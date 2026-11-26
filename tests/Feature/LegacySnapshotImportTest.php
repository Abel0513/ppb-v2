<?php

namespace Tests\Feature;

use App\Services\Ppb\Evaluation\LegacyReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LegacySnapshotImportTest extends TestCase
{
    private const MIGRATION = '2026_11_26_000015_import_legacy_snapshot_20261126.php';
    private string $fixtureDirectory;
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

        // Exercise the actual V2 foreign keys and unique constraints. The
        // MySQL-only username migration is unrelated to these tables' writes.
        foreach ([
            '2026_11_03_000001_create_ppb_security_tables.php',
            '2026_11_03_000003_create_ppb_operations_tables.php',
            '2026_11_04_000004_expand_ppb_schedule.php',
            '2026_11_05_000006_create_ppb_legacy_pick_contents.php',
            '2026_11_05_000007_create_ppb_legacy_wp_articles.php',
            '2026_11_06_000008_add_ppb_publication_lineage.php',
            '2026_11_06_000009_add_ppb_submission_source.php',
            '2026_11_06_000010_add_ppb_submission_workflow.php',
            '2026_11_14_000011_create_ppb_evaluation_tables.php',
            '2026_11_26_000014_add_ppb_evaluation_video_staff_fields.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        $this->fixtureDirectory = sys_get_temp_dir().'/ppb-legacy-import-test-'.Str::uuid();
        File::makeDirectory($this->fixtureDirectory);
        $this->fixture = [
            'games' => [[
                'id' => 8100, 'game_type' => 2, 'game_number' => 17,
                'recommended_time' => '2026-11-08 11:00:00', 'lottery_no' => '0008',
                'matchup' => 'TestLeague Away@Home', 'league' => 'TestLeague', 'away' => 'Away', 'home' => 'Home',
                'prediction' => '推 讓分 -1.5', 'play_type' => 1, 'play_value' => '-1.5', 'market_type' => 1,
                'reviewer_id' => 51, 'cancelled' => false, 'has_long' => true,
            ]],
            'picks' => [
                [
                    'id' => 11001, 'game_id' => 8100, 'kind' => 'short', 'slot' => 1,
                    'analyst_id' => 51, 'is_substitute' => 55, 'is_win' => 1,
                    'recommend' => 'First pick', 'analysis' => 'First analysis', 'long_url' => null,
                ],
                [
                    'id' => 11002, 'game_id' => 8100, 'kind' => 'long', 'slot' => 1,
                    'analyst_id' => 55, 'is_substitute' => 0, 'is_win' => null,
                    'recommend' => null, 'analysis' => null, 'long_url' => 'https://csdatw.com/synthetic-test/',
                ],
            ],
            'analysts' => [['id' => 51, 'name' => 'Sample Analyst'], ['id' => 55, 'name' => 'Unmapped Analyst']],
            'videos' => [[
                'id' => 700, 'month' => '2026-11', 'date' => '2026-11-08', 'week_label' => '第一周',
                'sport_tag' => 'TestSport', 'host' => 'Test Host', 'guest1' => 'Sample Analyst',
                'guest2' => 'Unmapped Analyst', 'guest3' => null, 'editor' => 'Test Editor',
            ]],
            'fixtures' => [[
                'id' => 2301, 'game_time' => '2026-11-08 19:00:00', 'sport' => 'TestSport',
                'league' => 'TestLeague', 'away' => 'Away', 'home' => 'Home',
            ]],
        ];
        DB::table('ppb_analysts')->insert(['id' => 7, 'name' => 'Internal Label', 'display_name' => 'Sample Analyst']);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtureDirectory)) {
            File::deleteDirectory($this->fixtureDirectory);
        }
        parent::tearDown();
    }

    public function test_import_uses_legacy_lineage_and_second_run_keeps_existing_rows(): void
    {
        $this->import();
        $game = DB::table('ppb_games')->first();
        $this->assertSame(8100, (int) $game->id);
        $this->assertSame('2026-11-08 03:00:00', $game->recommended_at);
        $this->assertSame('0008', json_decode($game->notes, true)['lottery_no']);
        $this->assertDatabaseHas('ppb_assignments', ['game_id' => $game->id, 'slot_no' => 1, 'current_analyst_id' => 7]);
        $this->assertDatabaseHas('ppb_submissions', [
            'game_id' => $game->id, 'legacy_source_pick_id' => 11001, 'kind' => 'pick',
            'status' => 'legacy_imported', 'submitted_analyst_id' => 7,
        ]);
        $this->assertDatabaseHas('ppb_submissions', [
            'legacy_source_pick_id' => 11002, 'assignment_id' => null, 'submitted_analyst_id' => null,
        ]);
        $this->assertDatabaseHas('ppb_legacy_pick_contents', [
            'source_pick_id' => 11001, 'legacy_game_code' => 'E17', 'legacy_analyst_id' => 51,
            'legacy_is_substitute' => 55, 'short_analysis' => 'First analysis',
        ]);
        $this->assertDatabaseHas('ppb_legacy_pick_contents', [
            'source_pick_id' => 11002, 'recommend_pick' => 'https://csdatw.com/synthetic-test/',
        ]);
        $this->assertNull($game->wp_locked_at);
        $this->assertDatabaseCount('ppb_legacy_wp_articles', 0);
        $this->assertDatabaseCount('ppb_publications', 0);
        $hash = DB::table('ppb_legacy_pick_contents')->where('source_pick_id', 11001)->value('source_sha256');

        $this->import();
        $this->assertDatabaseCount('ppb_games', 1);
        $this->assertDatabaseCount('ppb_legacy_pick_contents', 2);
        $this->assertDatabaseCount('ppb_submissions', 2);
        $this->assertDatabaseCount('ppb_assignments', 1);
        $this->assertDatabaseCount('ppb_evaluation_videos', 1);
        $this->assertDatabaseCount('ppb_sport_fixtures', 1);
        $this->assertSame($hash, DB::table('ppb_legacy_pick_contents')->where('source_pick_id', 11001)->value('source_sha256'));

        $this->fixture['picks'][0]['analysis'] = 'Corrected analysis';
        $this->fixture['picks'][0]['is_win'] = 2;
        $this->fixture['picks'][0]['is_substitute'] = 0;
        $this->import();
        $pick = DB::table('ppb_legacy_pick_contents')->where('source_pick_id', 11001)->first();
        $this->assertSame('Corrected analysis', $pick->short_analysis);
        $this->assertSame(2, (int) $pick->legacy_is_win);
        $this->assertSame(0, (int) $pick->legacy_is_substitute);
        $this->assertNotSame($hash, $pick->source_sha256);
    }

    public function test_matching_import_fills_lottery_and_preserves_v2_edits(): void
    {
        $this->seedGame(['_ppb_legacy_game_v1' => true, 'notes' => 'V2 note']);
        $this->import();
        $game = DB::table('ppb_games')->first();
        $this->assertDatabaseCount('ppb_games', 1);
        $this->assertJsonStringEqualsJsonString(
            '{"_ppb_legacy_game_v1":true,"notes":"V2 note","legacy_source_schedule_id":8100,"lottery_no":"0008"}',
            $game->notes
        );
        $this->assertSame('V2 edited play', $game->play_value);
        $this->assertSame('V2 edited matchup', $game->matchup_text);
    }

    public function test_a_nonmatching_occupied_number_rolls_back_all_writes(): void
    {
        $this->seedGame([]);
        try {
            $this->import();
            $this->fail('Expected a slot conflict.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('occupied V2 slot 2026-11 E17', $error->getMessage());
        }
        $this->assertDatabaseCount('ppb_games', 1);
        $this->assertDatabaseCount('ppb_schedule_scopes', 0);
        $this->assertDatabaseCount('ppb_sport_fixtures', 0);
        $this->assertDatabaseCount('ppb_legacy_pick_contents', 0);
    }

    public function test_video_and_fixture_upserts_keep_the_same_v2_ids(): void
    {
        $this->import();
        $video = DB::table('ppb_evaluation_videos')->first();
        $fixture = DB::table('ppb_sport_fixtures')->first();
        $this->assertDatabaseHas('ppb_evaluation_videos', [
            'id' => $video->id, 'scheduled_at' => '2026-11-07 16:00:00', 'week_no' => 1,
            'host_name' => 'Test Host', 'editor_name' => 'Test Editor', 'sport_label' => 'TestSport',
            'guest_1' => 'legacy:51', 'guest_2' => 'legacy:55',
        ]);
        $this->assertDatabaseHas('ppb_sport_fixtures', [
            'id' => $fixture->id, 'source' => 'pay_sport_schedule', 'source_id' => '2301',
            'starts_at' => '2026-11-08 11:00:00', 'matchup_text' => 'Away@Home',
        ]);
        $this->fixture['videos'][0]['editor'] = 'New Editor';
        $this->fixture['fixtures'][0]['game_time'] = '2026-11-08 20:00:00';
        $this->import();
        $this->assertDatabaseCount('ppb_evaluation_videos', 1);
        $this->assertDatabaseCount('ppb_sport_fixtures', 1);
        $this->assertDatabaseHas('ppb_evaluation_videos', ['id' => $video->id, 'editor_name' => 'New Editor', 'revision' => 2]);
        $this->assertDatabaseHas('ppb_sport_fixtures', ['id' => $fixture->id, 'starts_at' => '2026-11-08 12:00:00']);
    }

    public function test_checksum_mismatch_prevents_writes(): void
    {
        $this->writeFixture();
        File::append($this->fixtureDirectory.'/games.json', ' ');
        $this->expectExceptionMessage('SHA-256 mismatch for games.json');
        $this->runImport();
    }

    private function seedGame(array $notes): void
    {
        DB::table('ppb_games')->insert([
            'month_key' => '2026-11', 'game_type' => 2, 'game_number' => 17, 'active_number' => 17,
            'recommended_at' => '2026-11-08 03:00:00', 'sport' => 'other', 'home_team' => 'Home', 'away_team' => 'Away',
            'matchup_text' => 'V2 edited matchup', 'notes' => json_encode($notes, JSON_THROW_ON_ERROR),
            'play_type' => 5, 'play_value' => 'V2 edited play',
        ]);
    }

    private function import(): void
    {
        $this->writeFixture();
        $this->runImport();
    }

    private function writeFixture(): void
    {
        $manifest = [];
        foreach ($this->fixture as $name => $rows) {
            $file = $name.'.json';
            $bytes = json_encode($rows, JSON_THROW_ON_ERROR);
            File::put($this->fixtureDirectory.'/'.$file, $bytes);
            $manifest[$file] = ['rows' => count($rows), 'sha256' => hash('sha256', $bytes)];
        }
        File::put($this->fixtureDirectory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private function runImport(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        ob_start();
        try {
            $migration->importSnapshot($this->fixtureDirectory);
        } finally {
            ob_end_clean();
        }
    }
}
