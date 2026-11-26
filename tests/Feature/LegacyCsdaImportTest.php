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
        foreach ([644 => 19, 645 => 20] as $gameId => $number) {
            DB::table('ppb_games')->insert([
                'id' => $gameId, 'month_key' => '2026-11', 'game_type' => 1, 'game_number' => $number, 'active_number' => $number,
                'recommended_at' => '2026-11-07 03:00:00', 'sport' => 'other', 'home_team' => 'H', 'away_team' => 'A',
                'notes' => '{"_ppb_legacy_game_v1":true,"legacy_source_schedule_id":'.$gameId.'}',
            ]);
        }
        // Mapping evidence: legacy 24 -> V2 7, legacy 15 -> V2 8, legacy 16 -> V2 9.
        $this->pick(3988, 24, 7, 4);
        $this->pick(5000, 15, 8, 1, 645);
        $this->pick(5001, 16, 9, 2, 645);

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

    public function test_follow_up_moves_unlinked_slot_to_the_reassigned_author_and_fills_line_names_by_name(): void
    {
        // Earlier-import shape: the pick submission is not linked to the slot assignment.
        DB::table('ppb_submissions')->where('legacy_source_pick_id', 3988)->update(['assignment_id' => null]);
        $this->pick(5002, 24, 7, 3, 645); // Keeps the previous author's legacy mapping.
        DB::table('ppb_analysts')->insert(['id' => 10, 'name' => 'Legacy Only', 'line_name' => null]);
        $this->fixture['analyst_line_names.json'][] = ['legacy_analyst_id' => 6, 'line_name' => 'Line Six'];
        $this->import();
        $slot = DB::table('ppb_assignments')->where('game_id', 644)->where('slot_no', 4)->first();
        $this->assertSame(7, (int) $slot->current_analyst_id); // 000016 alone leaves the slot behind.

        $roster = $this->dir.'/analysts.json';
        File::put($roster, json_encode([
            ['id' => 6, 'name' => 'Legacy Only'], ['id' => 15, 'name' => 'New Author'], ['id' => 24, 'name' => 'Old Author'],
        ], JSON_THROW_ON_ERROR));
        $counts = $this->run17($roster);
        $this->assertSame(1, $counts['slots']['moved']);
        $this->assertSame(1, $counts['slots']['missing']); // 4008 is not in this fixture.
        $this->assertSame(1, $counts['line_names_by_name']['filled']);

        $slot = DB::table('ppb_assignments')->where('id', $slot->id)->first();
        $this->assertSame(8, (int) $slot->current_analyst_id);
        $this->assertSame(8, (int) $slot->original_analyst_id);
        $this->assertSame('Line Six', DB::table('ppb_analysts')->where('id', 10)->value('line_name'));
        $this->assertNull(DB::table('ppb_analysts')->where('id', 7)->value('line_name'));

        // The schedule card for slot 4 now resolves the reassigned pick text and result.
        $game = \App\Models\PpbGame::with('assignments')->findOrFail(644);
        $preview = app(\App\Services\Ppb\SchedulePickDisplay::class)->load(collect([$game]))['assigned'][$slot->id];
        $this->assertSame('r', $preview['recommend_pick']);
        $this->assertSame('a', $preview['short_analysis']);
        $this->assertSame(2, (int) $preview['is_win']);

        $again = $this->run17($roster);
        $this->assertSame(0, $again['slots']['moved']);
        $this->assertSame(1, $again['slots']['already']);
        $this->assertSame(0, $again['line_names_by_name']['filled']);
        $this->assertSame(1, $again['line_names_by_name']['kept_existing']);
    }

    public function test_link_merge_links_legacy_only_analysts_by_exact_name_and_moves_their_evaluation_rows(): void
    {
        (require database_path('migrations/2026_11_14_000011_create_ppb_evaluation_tables.php'))->up();
        $migration = require database_path('migrations/2026_11_26_000018_link_legacy_only_analysts_by_name.php');
        $migration->createTable();
        DB::table('ppb_analysts')->insert([
            ['id' => 20, 'name' => '元', 'display_name' => null, 'user_type' => 1, 'line_name' => null],
            ['id' => 21, 'name' => 'Ye', 'display_name' => '葉秋', 'user_type' => 0, 'line_name' => null],
            ['id' => 22, 'name' => '潔西', 'display_name' => null, 'user_type' => 0, 'line_name' => null],
            ['id' => 23, 'name' => '潔西', 'display_name' => null, 'user_type' => 0, 'line_name' => null],
        ]);
        $sha = str_repeat('0', 64);
        DB::table('ppb_evaluations')->insert([
            ['subject_type' => 'v2', 'subject_id' => 20, 'month' => '2026-11', 'source_sha256' => $sha],
            ['subject_type' => 'v2', 'subject_id' => 20, 'month' => '2026-10', 'source_sha256' => $sha],
            ['subject_type' => 'legacy', 'subject_id' => 49, 'month' => '2026-10', 'source_sha256' => $sha],
        ]);
        DB::table('ppb_evaluation_ratings')->insert(['source_type' => 'v2', 'source_id' => 1, 'subject_type' => 'v2', 'subject_id' => 20,
            'month' => '2026-11', 'source_sha256' => $sha]);
        DB::table('ppb_evaluation_attendance')->insert(['subject_type' => 'v2', 'subject_id' => 21, 'game_id' => 644, 'attendance_status' => 1]);
        DB::table('ppb_evaluation_videos')->insert(['scheduled_at' => '2026-11-07 04:00:00', 'guest_1' => 'legacy:15', 'guest_2' => 'v2:20']);

        $roster = $this->dir.'/analysts.json';
        File::put($roster, json_encode([
            ['id' => 6, 'name' => '葉秋'], ['id' => 7, 'name' => '洛老師'], ['id' => 18, 'name' => '潔西'],
            ['id' => 22, 'name' => 'BONBON'], ['id' => 49, 'name' => '元'],
        ], JSON_THROW_ON_ERROR));
        $counts = $this->run18($migration, $roster);
        $this->assertSame(['linked' => 2, 'already' => 0, 'mapped_by_evidence' => 0, 'roster_mismatch' => 0, 'no_match' => 2, 'ambiguous' => 1], $counts['links']);
        $this->assertSame(['evaluations' => 1, 'ratings' => 1, 'attendance' => 1, 'video_guests' => 1], $counts['remapped']);
        $this->assertSame(['evaluations' => 1, 'attendance' => 0], $counts['remap_conflicts']);

        $subjects = app(\App\Services\Ppb\Evaluation\EvaluationSubjects::class);
        $mappings = $subjects->mappings();
        $this->assertSame(20, $mappings[49]);
        $this->assertSame(21, $mappings[6]);
        $this->assertSame(7, $mappings[24]); // Derived evidence is kept.
        $this->assertDatabaseHas('ppb_evaluations', ['subject_type' => 'legacy', 'subject_id' => 49, 'month' => '2026-11']);
        $this->assertDatabaseHas('ppb_evaluation_ratings', ['subject_type' => 'legacy', 'subject_id' => 49]);
        $this->assertDatabaseHas('ppb_evaluation_attendance', ['subject_type' => 'legacy', 'subject_id' => 6, 'game_id' => 644]);
        $this->assertDatabaseHas('ppb_evaluation_videos', ['guest_2' => 'legacy:49']);
        $this->assertSame(5, DB::table('ppb_analysts')->whereIn('id', [7, 20, 21, 22, 23])->count()); // V2 records are kept.

        $reader = Mockery::mock(LegacyReader::class);
        $reader->shouldReceive('profiles')->andReturn([
            ['id' => 6, 'user_type' => 0, 'sort_id' => 3], ['id' => 49, 'user_type' => 1, 'sort_id' => 4],
        ]);
        $this->app->instance(LegacyReader::class, $reader);
        $this->app->forgetInstance(\App\Services\Ppb\Evaluation\EvaluationSubjects::class);
        $rows = collect(app(\App\Services\Ppb\Evaluation\EvaluationSubjects::class)->all());
        $this->assertSame('元', $rows->firstWhere('id', 49)['label']);
        $this->assertSame('葉秋', $rows->firstWhere('id', 6)['label']);
        $this->assertSame([22, 23], $rows->where('type', 'v2')->pluck('id')->values()->all());

        $again = $this->run18($migration, $roster);
        $this->assertSame(2, $again['links']['already']);
        $this->assertSame(0, $again['links']['linked']);
        $this->assertSame(['evaluations' => 0, 'ratings' => 0, 'attendance' => 0, 'video_guests' => 0], $again['remapped']);
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

    private function pick(int $id, int $legacy, int $native, int $slot, int $game = 644): void
    {
        $assignment = DB::table('ppb_assignments')->insertGetId([
            'game_id' => $game, 'kind' => 'pick', 'slot_no' => $slot, 'original_analyst_id' => $native,
            'current_analyst_id' => $native, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ppb_legacy_pick_contents')->insert([
            'source_pick_id' => $id, 'game_id' => $game, 'legacy_analyst_id' => $legacy, 'kind' => 'pick',
            'legacy_game_code' => 'G19', 'recommend_pick' => 'r', 'short_analysis' => 'a', 'legacy_is_substitute' => 0,
            'legacy_is_win' => 2, 'legacy_recommended_time' => '2026-11-07 11:00:00', 'source_sha256' => str_repeat('0', 64),
        ]);
        DB::table('ppb_submissions')->insert([
            'game_id' => $game, 'kind' => 'pick', 'status' => 'legacy_imported', 'assignment_id' => $assignment,
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

    private function run17(string $roster): array
    {
        $migration = require database_path('migrations/2026_11_26_000017_move_reassigned_pick_slots_and_fill_line_names_by_name.php');
        ob_start();
        try {
            return $migration->apply($roster);
        } finally {
            ob_end_clean();
        }
    }

    private function run18(object $migration, string $roster): array
    {
        ob_start();
        try {
            return $migration->apply($roster);
        } finally {
            ob_end_clean();
        }
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
