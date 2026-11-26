<?php

namespace Tests\Feature;

use App\Services\Ppb\Evaluation\LegacyReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class LegacyCsdaImportTest extends TestCase
{
    private const MIGRATION = '2026_11_26_000016_import_csda_users_line_names_and_reassign_picks.php';
    private string $dir;
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        $this->app->instance(LegacyReader::class, Mockery::mock(LegacyReader::class));
        foreach ([
            '2026_11_03_000001_create_ppb_security_tables.php',
            '2026_11_03_000003_create_ppb_operations_tables.php',
            '2026_11_04_000004_expand_ppb_schedule.php',
            '2026_11_05_000006_create_ppb_legacy_pick_contents.php',
            '2026_11_05_000007_create_ppb_legacy_wp_articles.php',
            '2026_11_06_000008_add_ppb_publication_lineage.php',
            '2026_11_06_000009_add_ppb_submission_source.php',
            '2026_11_06_000010_add_ppb_submission_workflow.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        if (!Schema::hasColumn('ppb_analysts', 'line_name')) {
            Schema::table('ppb_analysts', fn (Blueprint $table) => $table->string('line_name', 255)->nullable());
        }
        (require database_path('migrations/'.self::MIGRATION))->createTables();

        DB::table('ppb_analysts')->insert([
            ['id' => 7, 'name' => 'Old Author', 'line_name' => null],
            ['id' => 8, 'name' => 'New Author', 'line_name' => null],
            ['id' => 9, 'name' => 'Kept Author', 'line_name' => 'V2 edited'],
        ]);
        DB::table('ppb_games')->insert([
            'id' => 644, 'month_key' => '2026-11', 'game_type' => 1, 'game_number' => 19, 'active_number' => 19,
            'recommended_at' => '2026-11-07 03:00:00', 'sport' => 'other', 'home_team' => 'H', 'away_team' => 'A',
            'notes' => '{"_ppb_legacy_game_v1":true,"legacy_source_schedule_id":644}',
        ]);
        // Mapping evidence: legacy 24 -> V2 7, legacy 15 -> V2 8, legacy 16 -> V2 9.
        $this->pick(3988, 24, 7, 4);
        $this->pick(5000, 15, 8, 1);
        $this->pick(5001, 16, 9, 2);

        $this->dir = sys_get_temp_dir().'/ppb-csda-import-'.Str::uuid();
        File::makeDirectory($this->dir);
        $this->fixture = [
            'csda_users.json' => [
                ['id' => 5, 'name' => 'Synthetic Person', 'type' => 1, 'line_id' => 'Usynthetic1', 'remark' => null,
                    'is_listen' => 1, 'is_active' => 1, 'is_paid' => 0, 'is_dealer' => 1,
                    'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
                ['id' => 6, 'name' => 'Synthetic Group', 'type' => 2, 'line_id' => 'Csynthetic2', 'remark' => 'note',
                    'is_listen' => 0, 'is_active' => 0, 'is_paid' => 1, 'is_dealer' => 0,
                    'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
            ],
            'analyst_line_names.json' => [
                ['legacy_analyst_id' => 15, 'line_name' => 'Line Fifteen'],
                ['legacy_analyst_id' => 16, 'line_name' => 'Line Sixteen'],
                ['legacy_analyst_id' => 99, 'line_name' => 'Line Unmapped'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    public function test_import_is_idempotent_and_reassigns_the_legacy_author(): void
    {
        $counts = $this->import();
        $this->assertSame(['inserted' => 2, 'updated' => 0, 'unchanged' => 0], $counts['csda_users']);
        $this->assertSame(['filled' => 1, 'kept_existing' => 1, 'unmapped' => 1], $counts['analyst_line_names']);
        $this->assertSame(1, $counts['picks']['reassigned']);
        $this->assertSame(1, $counts['picks']['missing']); // 4008 is not in this fixture.
        $this->assertSame(1, $counts['picks']['assignment_moved']);

        $this->assertDatabaseHas('ppb_csda_users', ['legacy_id' => 5, 'type' => 1, 'is_listen' => 1, 'is_dealer' => 1, 'is_paid' => 0]);
        $this->assertDatabaseHas('ppb_csda_users', ['legacy_id' => 6, 'type' => 2, 'remark' => 'note', 'is_paid' => 1]);
        $this->assertDatabaseHas('ppb_legacy_analyst_profiles', ['legacy_analyst_id' => 99, 'line_name' => 'Line Unmapped']);
        $this->assertSame('Line Fifteen', DB::table('ppb_analysts')->where('id', 8)->value('line_name'));
        $this->assertSame('V2 edited', DB::table('ppb_analysts')->where('id', 9)->value('line_name'));
        $this->assertNull(DB::table('ppb_analysts')->where('id', 7)->value('line_name'));

        $this->assertSame(15, (int) DB::table('ppb_legacy_pick_contents')->where('source_pick_id', 3988)->value('legacy_analyst_id'));
        $submission = DB::table('ppb_submissions')->where('legacy_source_pick_id', 3988)->first();
        $this->assertSame(8, (int) $submission->submitted_analyst_id);
        $assignment = DB::table('ppb_assignments')->where('id', $submission->assignment_id)->first();
        $this->assertSame(8, (int) $assignment->current_analyst_id);
        $this->assertSame(8, (int) $assignment->original_analyst_id);
        $this->assertSame(4, (int) $assignment->slot_no);
        $hash = DB::table('ppb_legacy_pick_contents')->where('source_pick_id', 3988)->value('source_sha256');

        $again = $this->import();
        $this->assertSame(['inserted' => 0, 'updated' => 0, 'unchanged' => 2], $again['csda_users']);
        $this->assertSame(['inserted' => 0, 'updated' => 0, 'unchanged' => 3], $again['legacy_line_names']);
        $this->assertSame(0, $again['picks']['reassigned']);
        $this->assertSame(1, $again['picks']['already']);
        $this->assertDatabaseCount('ppb_csda_users', 2);
        $this->assertSame($hash, DB::table('ppb_legacy_pick_contents')->where('source_pick_id', 3988)->value('source_sha256'));

        $this->fixture['csda_users.json'][0]['is_paid'] = 1;
        $this->assertSame(['inserted' => 0, 'updated' => 1, 'unchanged' => 1], $this->import()['csda_users']);
    }

    public function test_checksum_mismatch_prevents_writes(): void
    {
        $this->write();
        File::append($this->dir.'/csda_users.json', ' ');
        try {
            $this->run16();
            $this->fail('Expected a checksum failure.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('SHA-256 mismatch', $error->getMessage());
        }
        $this->assertDatabaseCount('ppb_csda_users', 0);
        $this->assertSame(24, (int) DB::table('ppb_legacy_pick_contents')->where('source_pick_id', 3988)->value('legacy_analyst_id'));
    }

    private function pick(int $id, int $legacy, int $native, int $slot): void
    {
        $assignment = DB::table('ppb_assignments')->insertGetId([
            'game_id' => 644, 'kind' => 'pick', 'slot_no' => $slot, 'original_analyst_id' => $native,
            'current_analyst_id' => $native, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ppb_legacy_pick_contents')->insert([
            'source_pick_id' => $id, 'game_id' => 644, 'legacy_analyst_id' => $legacy, 'kind' => 'pick',
            'legacy_game_code' => 'G19', 'recommend_pick' => 'r', 'short_analysis' => 'a', 'legacy_is_substitute' => 0,
            'legacy_is_win' => 2, 'legacy_recommended_time' => '2026-11-07 11:00:00', 'source_sha256' => str_repeat('0', 64),
        ]);
        DB::table('ppb_submissions')->insert([
            'game_id' => 644, 'kind' => 'pick', 'status' => 'legacy_imported', 'assignment_id' => $assignment,
            'submitted_analyst_id' => $native, 'legacy_source_pick_id' => $id, 'content_body' => '{"_ppb_legacy_pick_v1":true}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function import(): array
    {
        $this->write();
        return $this->run16();
    }

    private function write(): void
    {
        $manifest = [];
        foreach ($this->fixture as $file => $rows) {
            $bytes = json_encode($rows, JSON_THROW_ON_ERROR);
            File::put($this->dir.'/'.$file, $bytes);
            $manifest[$file] = ['rows' => count($rows), 'sha256' => hash('sha256', $bytes)];
        }
        File::put($this->dir.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private function run16(): array
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        ob_start();
        try {
            return $migration->importSnapshot($this->dir);
        } finally {
            ob_end_clean();
        }
    }
}
