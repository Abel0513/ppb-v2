<?php

namespace Tests\Feature;

use App\Models\PpbAccount;
use App\Models\PpbAnalyst;
use App\Models\PpbGame;
use App\Services\Ppb\Evaluation\EvaluationService;
use App\Services\Ppb\Evaluation\EvaluationSubjects;
use App\Services\Ppb\Evaluation\LegacyReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyAdminUiTest extends TestCase
{
    private const HOST = 'https://v2.csdatw.xyz';

    private const PAGES = [
        '/admin/games/readonly' => '分析師輪值表',
        '/admin/schedule/overview' => '賽事排班總覽',
        '/admin/schedule/articles' => '分析文班表總覽',
        '/admin/schedule/videos' => '影音班表',
        '/admin/schedule/attendance' => '出缺勤紀錄表',
        '/admin/analyst-list' => '本月勝率',
        '/admin/performance' => '本月戰績',
        '/admin/csda/users' => 'CSDA 用戶管理',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // This CI job points the default connection at an unavailable MySQL
        // port. Force an isolated sqlite memory database for these HTTP tests.
        config([
            'app.url' => self::HOST,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::setDefaultConnection('sqlite');
        // Midnight in Taipei, still the prior day and month in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 16:00:00', 'UTC'));
        $this->installSchema();
    }

    private function actor(array $permissions): void
    {
        $actor = Mockery::mock(PpbAccount::class)->makePartial();
        $actor->forceFill(['id' => 71, 'name' => 'UI fixture', 'username' => 'fixture', 'is_active' => true]);
        $actor->shouldReceive('hasPpbPermission')->andReturnUsing(fn ($permission) => in_array($permission, $permissions, true));
        $roles = Mockery::mock(BelongsToMany::class);
        $roles->shouldReceive('where')->andReturnSelf();
        $roles->shouldReceive('exists')->andReturn(false);
        $actor->shouldReceive('roles')->andReturn($roles);
        $this->actingAs($actor, 'ppb');
    }

    private function installSchema(): void
    {
        if (Schema::hasTable('ppb_games')) {
            return;
        }
        Schema::create('ppb_games', function (Blueprint $table) {
            $table->increments('id');
            $table->char('month_key', 7)->nullable();
            $table->integer('game_type')->nullable();
            $table->integer('game_number')->nullable();
            $table->integer('active_number')->nullable();
            $table->string('status')->default('scheduled');
            $table->string('matchup_text')->nullable();
            $table->dateTime('recommended_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('reviewer_analyst_id')->nullable();
            $table->boolean('has_long_article')->default(false);
            $table->dateTime('wp_locked_at')->nullable();
            $table->integer('play_type')->nullable();
            $table->string('play_value')->nullable();
            $table->integer('market_type')->nullable();
            $table->timestamps();
        });
        Schema::create('ppb_assignments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('game_id');
            $table->unsignedBigInteger('current_analyst_id')->nullable();
            $table->unsignedBigInteger('original_analyst_id')->nullable();
            $table->string('kind')->nullable();
            $table->integer('slot_no')->nullable();
            $table->string('status')->default('active');
        });
        Schema::create('ppb_analysts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('display_name')->nullable();
            $table->integer('user_type')->default(0);
            $table->string('status')->default('active');
            $table->string('color', 7)->nullable();
            $table->string('background', 7)->nullable();
            $table->string('line_name')->nullable();
            $table->string('line_id')->nullable();
            $table->unsignedTinyInteger('rating_level')->nullable();
            $table->unsignedTinyInteger('analyst_level')->nullable();
            $table->timestamps();
        });
        Schema::create('ppb_submissions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('game_id')->nullable();
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->unsignedBigInteger('submitted_analyst_id')->nullable();
            $table->unsignedBigInteger('legacy_source_pick_id')->nullable();
            $table->string('kind')->nullable();
            $table->string('status')->nullable();
            $table->text('content_body')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->text('recommend_pick')->nullable();
            $table->text('analysis_text')->nullable();
        });
        Schema::create('ppb_publications', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('game_id')->nullable();
            $table->string('status')->nullable();
            $table->dateTime('verified_at')->nullable();
        });
        Schema::create('ppb_legacy_pick_contents', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('game_id')->nullable();
            $table->unsignedBigInteger('source_pick_id')->nullable();
            $table->unsignedBigInteger('legacy_analyst_id')->nullable();
            $table->string('kind')->nullable();
            $table->text('recommend_pick')->nullable();
            $table->text('short_analysis')->nullable();
            $table->tinyInteger('legacy_is_win')->nullable();
            $table->char('source_sha256', 64)->nullable();
            $table->integer('legacy_is_substitute')->nullable();
        });
        Schema::create('ppb_evaluation_attendance', function (Blueprint $table) {
            $table->increments('id');
            $table->string('subject_type', 12);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('game_id');
            $table->tinyInteger('attendance_status')->nullable();
            $table->unsignedBigInteger('waived_submission_id')->nullable();
        });
        Schema::create('ppb_evaluation_videos', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('scheduled_at');
            $table->string('guest_1', 32)->nullable();
            $table->string('guest_2', 32)->nullable();
            $table->string('guest_3', 32)->nullable();
            $table->boolean('cancelled')->default(false);
            $table->unsignedTinyInteger('week_no')->nullable();
            $table->string('sport_label')->nullable();
            $table->string('host_name')->nullable();
            $table->string('editor_name')->nullable();
        });
        Schema::create('ppb_evaluations', function (Blueprint $table) {
            $table->increments('id');
            $table->string('subject_type', 12);
            $table->unsignedBigInteger('subject_id');
            $table->char('month', 7);
            $table->unsignedTinyInteger('next_rating_level')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();
        });
        Schema::create('ppb_evaluation_ratings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('source_type', 12);
            $table->unsignedBigInteger('source_id');
            $table->string('subject_type', 12);
            $table->unsignedBigInteger('subject_id');
            $table->char('month', 7);
        });
        Schema::create('ppb_legacy_wp_articles', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('game_id')->nullable();
            $table->unsignedBigInteger('source_pick_id')->nullable();
            $table->text('wp_link')->nullable();
        });
        Schema::create('ppb_schedule_events', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('game_id')->nullable();
            $table->string('event')->nullable();
            $table->text('detail_json')->nullable();
        });
    }

    public static function pagerCases(): array
    {
        return [
            'history begins on page one' => [0, 25, 25, 3],
            'future uses its own page' => [3, 20, 30, 3],
            'all future fits on one page' => [15, 0, 15, 1],
        ];
    }

    #[DataProvider('pagerCases')]
    public function test_shift_pager(int $future, int $history, int $total, int $lastPage): void
    {
        $this->actor(['schedules.view']);
        foreach ([[1, $history, '2026-10-31 03:00:00'], [101, $future, '2026-10-31 16:00:00']] as [$first, $count, $time]) {
            for ($id = $first; $id < $first + $count; $id++) {
                PpbGame::unguarded(fn () => PpbGame::query()->create([
                    'id' => $id,
                    'month_key' => $id >= 101 ? '2026-11' : '2026-10',
                    'recommended_at' => $time,
                    'game_type' => 1,
                    'game_number' => $id,
                    'status' => 'scheduled',
                    'matchup_text' => 'Fixture game '.$id,
                ]));
            }
        }

        $first = $this->get(self::HOST.'/admin/games')->assertOk()
            ->assertSee('分析師輪值表')->assertSee('共 '.$total.' 筆 / 第 1 頁')
            ->assertDontSee('目前查詢條件下沒有賽事');
        $first->assertViewHas('games', function ($pager) use ($future, $history, $total, $lastPage) {
            $expected = $future ? range(101, 100 + $future) : range($history - 9, $history);
            return $pager->total() === $total && $pager->lastPage() === $lastPage
                && $pager->getCollection()->pluck('id')->all() === $expected;
        });
        if ($history) {
            $end = $future ? $history : $history - 10;
            $this->get(self::HOST.'/admin/games?p=2')->assertOk()->assertViewHas('games',
                fn ($pager) => $pager->getCollection()->pluck('id')->all() === range($end - 9, $end)
            );
        } else {
            $first->assertDontSee('最後一頁');
        }
    }

    public function test_new_pages_use_schedule_permission_and_keep_pay_restricted(): void
    {
        foreach (array_keys(self::PAGES) as $path) {
            $this->get(self::HOST.$path)->assertStatus(302);
        }
        $this->actor([]);
        foreach (array_keys(self::PAGES) as $path) {
            $this->get(self::HOST.$path)->assertForbidden();
        }

        $roster = Mockery::mock(EvaluationSubjects::class);
        $roster->shouldReceive('all')->andReturn([
            ['type' => 'legacy', 'id' => 4, 'label' => '哆啦', 'native_id' => null, 'rating_level' => 2, 'analyst_level' => 1],
        ]);
        $roster->shouldReceive('mappings')->andReturn([]);
        $this->app->instance(EvaluationSubjects::class, $roster);

        $this->actor(['schedules.view']);
        foreach (self::PAGES as $path => $label) {
            $this->get(self::HOST.$path)->assertOk()->assertSee($label);
        }
        $this->get(self::HOST.'/admin/analyst-list')->assertOk()->assertSee('資深分析師')->assertSee('Line名稱');
        $this->get(self::HOST.'/admin/rating/index')->assertForbidden();
        $this->get(self::HOST.'/admin/rating/summary/legacy/4')->assertForbidden();

        $this->actor(['schedules.view', 'schedules.manage', 'evaluations.view']);
        $this->get(self::HOST.'/admin/games/readonly')->assertOk()->assertDontSee('新增賽事')->assertDontSee('修改賽事');
        $this->get(self::HOST.'/admin/rating/index')->assertOk()->assertSee('更新當月評級')->assertSee('評鑑・薪酬・稿件');
        $this->get(self::HOST.'/admin/rating/salary')->assertOk()->assertSee('總計');
        $this->get(self::HOST.'/admin/rating/change')->assertOk()->assertSee('評級異動');
    }

    public function test_analyst_list_only_reads_line_name_with_both_master_permissions(): void
    {
        PpbAnalyst::unguarded(fn () => PpbAnalyst::query()->create([
            'id' => 7,
            'name' => 'Fixture name',
            'display_name' => 'Fixture name',
            'user_type' => 0,
            'status' => 'active',
            'line_name' => 'Fixture private name',
            'line_id' => 'synthetic-private-id',
            'rating_level' => 0,
            'analyst_level' => 0,
        ]));
        $roster = Mockery::mock(EvaluationSubjects::class);
        $roster->shouldReceive('all')->andReturn([
            ['type' => 'v2', 'id' => 7, 'label' => 'Fixture name', 'native_id' => 7, 'rating_level' => 0, 'analyst_level' => 0],
        ]);
        $roster->shouldReceive('mappings')->andReturn([]);
        $this->app->instance(EvaluationSubjects::class, $roster);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actor(['schedules.view', 'accounts.manage']);
        $this->get(self::HOST.'/admin/analyst-list')->assertOk()->assertSee('Line名稱')->assertDontSee('Fixture private name');
        self::assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'line_name')));
        self::assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'line_id')));

        $queries = [];
        $this->actor(['schedules.view', 'schedules.manage', 'accounts.manage']);
        $this->get(self::HOST.'/admin/analyst-list')->assertOk()->assertSee('Fixture private name');
        self::assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, 'line_name')));
        self::assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'line_id')));
    }

    public function test_schedule_summary_returns_performance_without_salary_or_line_data(): void
    {
        $this->actor(['schedules.view']);
        $subject = ['type' => 'legacy', 'id' => 4, 'rating_level' => 2];
        $data = Mockery::mock(\App\Services\Ppb\Evaluation\LocalEvaluationData::class);
        $data->shouldReceive('roster')->andReturn(['legacy:4' => $subject]);
        $data->shouldReceive('ready')->andReturn(true);
        $this->app->instance(\App\Services\Ppb\Evaluation\LocalEvaluationData::class, $data);
        $service = Mockery::mock(EvaluationService::class);
        $service->shouldReceive('loadLocal')->once()->with($subject, '2026-11')->andReturn([
            'current' => ['level' => 2, 'view' => ['total_score' => 65],
                'salary' => ['total' => 100], 'performance' => ['month' => ['wins' => 4, 'losses' => 8, 'rate' => 33]]],
        ]);
        $this->app->instance(EvaluationService::class, $service);

        $this->getJson(self::HOST.'/admin/performance/summary/legacy/4')
            ->assertOk()->assertJsonPath('rating', 'A')->assertJsonPath('performance.month.rate', 33)
            ->assertJsonMissingPath('salary')->assertJsonMissingPath('line_name');
    }

    public function test_verified_legacy_label_does_not_create_a_mapping(): void
    {
        $reader = Mockery::mock(LegacyReader::class);
        $reader->shouldReceive('profiles')->andReturn([
            ['id' => 4, 'sort_id' => 4, 'user_type' => 0, 'rating_level' => 2, 'analyst_level' => 1],
            ['id' => 7, 'sort_id' => 7, 'user_type' => 0, 'rating_level' => 0, 'analyst_level' => 0],
        ]);
        [$verified, $other] = (new EvaluationSubjects($reader))->all();

        self::assertSame('哆啦', $verified['label']);
        self::assertFalse($verified['mapped']);
        self::assertNull($verified['native_id']);
        self::assertSame('洛老師', $other['label']);
    }


    public function test_acceptance_fixes_on_shift_and_report_pages(): void
    {
        $this->actor(['schedules.view', 'schedules.manage', 'evaluations.view', 'evaluations.manage']);
        PpbAnalyst::unguarded(fn () => PpbAnalyst::query()->create([
            'id' => 11, 'name' => '阿圈', 'display_name' => '阿圈', 'user_type' => 0, 'status' => 'active',
            'rating_level' => 2, 'analyst_level' => 1,
        ]));
        $game = PpbGame::unguarded(fn () => PpbGame::query()->create([
            'id' => 15, 'month_key' => '2026-11', 'recommended_at' => '2026-11-05 08:00:00',
            'game_type' => 1, 'game_number' => 15, 'status' => 'scheduled', 'matchup_text' => '奧蘭多魔術@鳳凰城太陽',
            'notes' => json_encode(['_ppb_legacy_game_v1' => true, 'lottery_no' => '1234']),
            'wp_locked_at' => '2026-11-05 09:00:00', 'play_type' => 1, 'market_type' => 1,
        ]));
        $assignmentId = DB::table('ppb_assignments')->insertGetId([
            'game_id' => 15, 'current_analyst_id' => 11, 'kind' => 'pick', 'slot_no' => 1, 'status' => 'active',
        ]);
        DB::table('ppb_legacy_pick_contents')->insert([
            'game_id' => 15, 'source_pick_id' => 9001, 'legacy_analyst_id' => 6, 'kind' => 'pick',
            'recommend_pick' => '主隊讓分', 'short_analysis' => '看好前場籃板', 'legacy_is_win' => 1,
        ]);
        DB::table('ppb_submissions')->insert([
            'game_id' => 15, 'assignment_id' => $assignmentId, 'submitted_analyst_id' => 11,
            'legacy_source_pick_id' => 9001, 'kind' => 'pick', 'status' => 'legacy_imported',
        ]);
        DB::table('ppb_publications')->insert(['game_id' => 15, 'status' => 'published', 'verified_at' => '2026-11-05 10:00:00']);

        $shift = $this->get(self::HOST.'/admin/games?p=1')->assertOk()
            ->assertSee('主隊讓分')->assertSee('看好前場籃板')
            ->assertDontSee('尚未串接投稿')->assertDontSee('稿件內容尚未接入新版')
            ->assertSee('1234')->assertDontSee('彩券號 —')
            ->assertSee('修改賽事')->assertSee('刪除賽事')
            ->assertSee('! 未結算場次')
            ->assertSee('分析師評鑑');
        $shift->assertViewHas('games', fn ($pager) => $pager->getCollection()->pluck('id')->all() === [15]);

        $this->get(self::HOST.'/admin/games/create?modal=1')->assertOk()
            ->assertSee('盤口類型')->assertSee('手動填寫玩法')->assertSee('BOUNS-分析師');

        $this->get(self::HOST.'/admin/rating/index')->assertOk()
            ->assertSee('11月評級')->assertSee('10月評級')->assertDontSee('載入中...')
            ->assertDontSee('12月評級');
        $this->get(self::HOST.'/admin/performance')->assertOk()->assertDontSee('載入中...');
        $this->get(self::HOST.'/admin/schedule/videos')->assertOk()
            ->assertSee('影音班表')->assertSee('新增班表')->assertSee('週次');
        $this->get(self::HOST.'/admin/csda/users')->assertOk()->assertSee('CSDA 用戶資料目前未匯入 V2');
    }

    public function test_history_only_shift_starts_with_the_most_recent_past_games(): void
    {
        $this->actor(['schedules.view']);
        foreach (range(1, 15) as $id) {
            PpbGame::unguarded(fn () => PpbGame::query()->create([
                'id' => $id, 'month_key' => '2026-10', 'recommended_at' => sprintf('2026-10-%02d 03:00:00', $id),
                'game_type' => 1, 'game_number' => $id, 'status' => 'scheduled', 'matchup_text' => 'Past '.$id,
            ]));
        }
        $this->get(self::HOST.'/admin/games')->assertOk()->assertViewHas('games',
            fn ($pager) => $pager->getCollection()->pluck('id')->all() === range(6, 15)
        );
    }
}
