<?php

namespace Tests\Feature;

use App\Models\PpbAccount;
use App\Models\PpbAnalyst;
use App\Models\PpbGame;
use App\Services\Ppb\Evaluation\EvaluationPreviewReport;
use App\Services\Ppb\Evaluation\EvaluationService;
use App\Services\Ppb\Evaluation\EvaluationSubjects;
use App\Services\Ppb\Evaluation\LegacyReader;
use App\Services\Ppb\Evaluation\LegacyScheduleCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyAdminUiTest extends TestCase
{
    private const HOST = 'https://v2.csdatw.xyz';

    private const ATTENDANCE = self::HOST.'/admin/schedule/attendance';

    private const PAGES = [
        '/admin/games/readonly' => '賽事排班（唯讀）',
        '/admin/schedule/overview' => '排班總覽',
        '/admin/schedule/articles' => '分析文排班',
        '/admin/schedule/videos' => '影音排班',
        '/admin/schedule/attendance' => '出缺勤紀錄表',
        '/admin/analyst-list' => '分析師列表',
        '/admin/performance' => '戰績排行榜',
        '/admin/csda/users' => 'CSDA 用戶（唯讀）',
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
            'database.connections.sqlite.url' => null,
            'cache.default' => 'array',
        ]);
        DB::purge();
        DB::setDefaultConnection('sqlite');
        // Midnight in Taipei, still the prior day and month in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 16:00:00', 'UTC'));
        $this->installSchema();
        $reader = Mockery::mock(LegacyReader::class);
        $reader->shouldReceive('profiles')->andReturn([]);
        $reader->shouldReceive('query')->with(Mockery::on(fn ($query) =>
            $query['operation'] === 'select' && in_array($query['table'], ['pay.pay_attendance_record', 'pay.pay_analyst_pick'], true)))
            ->andReturn(['rows' => []]);
        $this->app->instance(LegacyReader::class, $reader);
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
            $table->string('away_team')->nullable();
            $table->string('home_team')->nullable();
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
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->unique(['subject_type', 'subject_id', 'game_id']);
            $table->timestamps();
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
            $table->char('source_sha256', 64)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unique(['subject_type', 'subject_id', 'month']);
            $table->timestamps();
        });
        Schema::create('ppb_evaluation_ratings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('source_type', 12);
            $table->unsignedBigInteger('source_id');
            $table->string('subject_type', 12);
            $table->unsignedBigInteger('subject_id');
            $table->char('month', 7);
            foreach (LegacyScheduleCalculator::RATING_FIELDS as $field) $table->smallInteger($field);
            $table->tinyInteger('result_state')->nullable();
            $table->unsignedBigInteger('revision');
            $table->unsignedBigInteger('actor_id');
            $table->char('source_sha256', 64);
            $table->unique(['source_type', 'source_id']);
            $table->timestamps();
        });
        Schema::create('ppb_evaluation_events', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('evaluation_id');
            $table->string('operation');
            $table->string('request_id')->unique();
            $table->char('payload_sha256', 64);
            $table->unsignedBigInteger('actor_id');
            $table->text('before_json');
            $table->text('after_json');
            $table->timestamps();
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
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('ppb_auth_audits', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('event', 48);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at');
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
            ->assertSee('<h1 class="admin-page-title">賽事排班</h1>', false)
            ->assertSee('class="ppb-shift-pagination" role="navigation" aria-label="賽事分頁"', false)
            ->assertSee('共 '.$total.' 筆 / 第 1 頁')
            ->assertDontSee('目前查詢條件下沒有賽事');
        $first->assertViewHas('games', function ($pager) use ($future, $history, $total, $lastPage) {
            $expected = $future ? range(101, 100 + $future) : range($history - 9, $history);
            return $pager->total() === $total && $pager->lastPage() === $lastPage
                && $pager->getCollection()->pluck('id')->all() === $expected;
        });
        if ($history) {
            $first->assertSee('aria-disabled="true">上一頁</span>', false)->assertSee('rel="next">下一頁</a>', false);
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
        PpbAnalyst::unguarded(fn () => PpbAnalyst::query()->create([
            'id' => 4, 'name' => '哆啦', 'display_name' => '哆啦', 'user_type' => 0, 'status' => 'active',
            'rating_level' => 2, 'analyst_level' => 1,
        ]));

        $this->actor(['schedules.view']);
        foreach (self::PAGES as $path => $label) {
            $this->get(self::HOST.$path)->assertOk()
                ->assertSee('<h1 class="admin-page-title">'.$label.'</h1>', false)
                ->assertSee('active" href="'.self::HOST.$path.'"', false);
        }
        $this->get(self::HOST.'/admin/analyst-list')->assertOk()
            ->assertSee('本月勝率')->assertSee('資深分析師')->assertSee('Line名稱');
        $this->get(self::HOST.'/admin/performance')->assertOk()->assertSee('本月戰績')
            ->assertDontSee('>薪資與評級<', false)->assertDontSee('>系統設定<', false);
        $this->get(self::HOST.'/admin/rating/index')->assertForbidden();
        $this->get(self::HOST.'/admin/rating/summary/legacy/4')->assertForbidden();

        $this->actor(['schedules.view', 'schedules.manage', 'evaluations.view']);
        $this->get(self::HOST.'/admin/games/readonly')->assertOk()
            ->assertDontSee('新增賽事')->assertDontSee('編輯／補齊人員')
            ->assertDontSee('id="ppbCancelGameForm"', false);
        $this->get(self::HOST.'/admin/rating/index')->assertOk()->assertSee('更新當月評級')->assertSee('評鑑・薪酬・稿件');
        $this->get(self::HOST.'/admin/rating/salary')->assertOk()->assertSee('總計');
        $this->get(self::HOST.'/admin/rating/change')->assertOk()->assertSee('評級異動');
    }

    public function test_attendance_merges_taipei_months_and_caches_the_paged_legacy_source(): void
    {
        $this->actor(['schedules.view', 'evaluations.view', 'evaluations.manage']);
        DB::table('ppb_analysts')->insert([
            'id' => 9, 'name' => 'Local analyst', 'display_name' => 'Mapped analyst',
            'color' => '#142536', 'background' => '#ABCDEF',
        ]);
        DB::table('ppb_legacy_pick_contents')->insert([
            'game_id' => 750, 'source_pick_id' => 20, 'legacy_analyst_id' => 42, 'kind' => 'pick',
        ]);
        DB::table('ppb_submissions')->insert([
            'game_id' => 750, 'legacy_source_pick_id' => 20, 'submitted_analyst_id' => 9,
            'kind' => 'pick', 'status' => 'legacy_imported',
        ]);
        DB::table('ppb_games')->insert([
            ['id' => 750, 'game_type' => 1, 'game_number' => 15, 'recommended_at' => '2026-10-31 16:00:00',
                'matchup_text' => 'Imported matchup', 'notes' => '{"_ppb_legacy_game_v1":true,"legacy_source_schedule_id":800}'],
            ['id' => 851, 'game_type' => 2, 'game_number' => 8, 'recommended_at' => '2026-11-03 05:00:00',
                'matchup_text' => 'Unrelated local matchup', 'notes' => null],
            ['id' => 900, 'game_type' => 1, 'game_number' => 40, 'recommended_at' => '2026-08-31 16:00:00',
                'matchup_text' => 'Native matchup', 'notes' => null],
        ]);
        DB::table('ppb_evaluation_attendance')->insert([
            ['game_id' => 750, 'subject_type' => 'legacy', 'subject_id' => 42, 'attendance_status' => 1],
            ['game_id' => 900, 'subject_type' => 'legacy', 'subject_id' => 42, 'attendance_status' => 2],
        ]);

        $records = [];
        for ($id = 1; $id <= 50; $id++) {
            $records[] = ['id' => $id, 'gid' => 800, 'uid' => 42, 'attendance_status' => 2];
        }
        $records[] = ['id' => 51, 'gid' => 851, 'uid' => 77, 'attendance_status' => 2];
        $records[] = ['id' => 52, 'gid' => 740, 'uid' => 77, 'attendance_status' => 1];
        $records[] = ['id' => 53, 'gid' => 800, 'uid' => 78, 'attendance_status' => 2];

        $schedules = [
            ['id' => 740, 'game_id' => 30, 'game_type' => 1, 'recommended_time' => '2026-10-31 23:59:59'],
            ['id' => 800, 'game_id' => 15, 'game_type' => 1, 'recommended_time' => '2026-11-01 00:00:00'],
        ];
        for ($id = 801; $id < 851; $id++) {
            $schedules[] = ['id' => $id, 'game_id' => $id, 'game_type' => 1, 'recommended_time' => '2026-11-02 12:00:00'];
        }
        $schedules[] = ['id' => 851, 'game_id' => 8, 'game_type' => 2, 'recommended_time' => '2026-11-03 13:00:00'];
        $reader = Mockery::mock(LegacyReader::class);
        $reader->shouldReceive('query')->times(5)->andReturnUsing(function (array $query) use ($records, $schedules) {
            self::assertSame('select', $query['operation']);
            self::assertSame(50, $query['limit']);
            if ($query['table'] === 'pay.pay_attendance_record') {
                self::assertSame(['id', 'gid', 'uid', 'attendance_status'], $query['columns']);

                return ['rows' => array_slice($records, $query['offset'], $query['limit'])];
            }

            self::assertSame('pay.pay_schedule', $query['table']);
            self::assertSame(['id', 'game_id', 'game_type', 'recommended_time', 'reviewer', 'market_type'], $query['columns']);
            self::assertContains('recommended_time', array_column($query['filters'], 'column'));
            $rows = array_filter($schedules, function ($row) use ($query) {
                foreach ($query['filters'] as $filter) {
                    $value = $row[$filter['column']];
                    $matches = match ($filter['op']) {
                        '>=' => $value >= $filter['value'],
                        '<=' => $value <= $filter['value'],
                        '<' => $value < $filter['value'],
                    };
                    if (!$matches) {
                        return false;
                    }
                }

                return true;
            });

            return ['rows' => array_slice($rows, $query['offset'], $query['limit'])];
        });
        $this->app->instance(LegacyReader::class, $reader);

        $url = self::HOST.'/admin/schedule/attendance';
        $first = $this->get($url)->assertOk()
            ->assertSee('<div class="page-title"><span class="page-title-month">2026-11</span> 出缺勤紀錄</div>', false)->assertSee('共 3 個月 / 第 1 頁')
            ->assertSee('<span class="tag tag-g">G15</span>', false)->assertSee('<span class="tag tag-e">E8</span>', false)
            ->assertSee('<span class="analyst-tag" style="background:#ABCDEF;color:#142536">Mapped analyst</span>', false)->assertDontSee('background-color:#ABCDEF')
            ->assertSee('舊分析師 #77')->assertSee('Imported matchup')->assertSee('<div class="c-matchup">—</div>', false)
            ->assertSee('class="status-badge status-absent">曠職', false)->assertSee('class="status-badge status-late">遲到', false)
            ->assertDontSee('Unrelated local matchup')->assertDontSee('<span class="tag tag-g">G30</span>', false);
        self::assertSame(1, substr_count($first->getContent(), '#evaluation-attendance'));
        self::assertSame(1, substr_count($first->getContent(), 'Mapped analyst'));
        $this->get($url.'?p=2')->assertOk()->assertSee('<div class="page-title"><span class="page-title-month">2026-10</span> 出缺勤紀錄</div>', false)
            ->assertSee('共 3 個月 / 第 2 頁')->assertSee('<span class="tag tag-g">G30</span>', false)
            ->assertDontSee('<span class="tag tag-e">E8</span>', false)->assertDontSee('#evaluation-attendance');
        $this->get($url.'?month=2026-09')->assertOk()->assertSee('<div class="page-title"><span class="page-title-month">2026-09</span> 出缺勤紀錄</div>', false)
            ->assertSee('共 3 個月 / 第 3 頁')->assertSee('Native matchup');
    }

    public function test_attendance_reader_failure_preserves_v2_rows_and_is_retried(): void
    {
        $this->actor(['schedules.view']);
        DB::table('ppb_analysts')->insert(['id' => 9, 'name' => 'Native analyst']);
        DB::table('ppb_games')->insert([
            'id' => 7, 'game_type' => 1, 'game_number' => 66, 'recommended_at' => '2026-11-01 00:00:00',
            'matchup_text' => 'Fallback matchup',
        ]);
        DB::table('ppb_evaluation_attendance')->insert([
            'game_id' => 7, 'subject_type' => 'v2', 'subject_id' => 9, 'attendance_status' => 2,
        ]);
        $reader = Mockery::mock(LegacyReader::class);
        $reader->shouldReceive('query')->once()->with(Mockery::on(fn ($query) =>
            $query['operation'] === 'select' && $query['table'] === 'pay.pay_attendance_record'))
            ->andThrow(new \RuntimeException('LEGACY_READER_UNAVAILABLE'));
        $reader->shouldReceive('query')->once()->with(Mockery::on(fn ($query) =>
            $query['operation'] === 'select' && $query['table'] === 'pay.pay_attendance_record'))
            ->andReturn(['rows' => []]);
        $this->app->instance(LegacyReader::class, $reader);

        $url = self::HOST.'/admin/schedule/attendance';
        $this->get($url)->assertOk()->assertSee('舊系統出缺勤暫時無法讀取')
            ->assertSee('Native analyst')->assertSee('Fallback matchup')->assertSee('曠職')->assertDontSee('#evaluation-attendance');
        $this->get($url)->assertOk()->assertDontSee('舊系統出缺勤暫時無法讀取')->assertSee('Fallback matchup');
    }

    private function migrateAttendanceDeletions(): void
    {
        (require database_path('migrations/2026_11_27_000019_create_ppb_attendance_deletions.php'))->up();
    }

    private function mockLegacyAttendance(LegacyReader $reader): void
    {
        $reader->shouldReceive('query')->with(Mockery::on(fn ($query) =>
            $query['operation'] === 'select' && $query['table'] === 'pay.pay_attendance_record'))
            ->andReturnUsing(function ($query) {
                $id = $query['filters'][0]['value'] ?? 106;
                return ['rows' => $id === 106
                    ? [['id' => 106, 'gid' => 320, 'uid' => 4, 'attendance_status' => 2]]
                    : []];
            });
        $reader->shouldReceive('query')->with(Mockery::on(fn ($query) =>
            $query['operation'] === 'select' && $query['table'] === 'pay.pay_schedule'))
            ->andReturnUsing(fn ($query) => ['rows' => $query['filters'][0]['op'] === '>=' ? [] : [[
                'id' => 320, 'game_id' => 71, 'game_type' => 1, 'recommended_time' => '2026-10-06 08:00:00',
            ]]]);
    }

    public function test_attendance_delete_requires_both_permissions_and_keeps_web_csrf(): void
    {
        $reader = Mockery::mock(LegacyReader::class);
        $this->mockLegacyAttendance($reader);
        $this->app->instance(LegacyReader::class, $reader);
        $payload = ['source' => 'legacy', 'legacy_record_id' => 106, 'request_id' => (string) Str::uuid()];
        foreach ([['schedules.view'], ['schedules.view', 'evaluations.view'], ['schedules.view', 'evaluations.manage']] as $permissions) {
            $this->actor($permissions);
            $this->get(self::ATTENDANCE)->assertOk()->assertSee('哆啦')->assertDontSee('class="btn-delete"', false);
            $this->postJson(self::ATTENDANCE.'/delete', $payload)->assertForbidden();
        }

        $router = $this->app['router'];
        $route = $router->getRoutes()->getByName('ppb.schedule.attendance.delete');
        self::assertContains('web', $route->gatherMiddleware());
        $middleware = $router->gatherRouteMiddleware($route);
        self::assertNotEmpty(array_intersect($middleware, [
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]));
    }

    public function test_legacy_attendance_delete_is_read_only_at_source_and_recalculates_salary(): void
    {
        $this->actor(['schedules.view', 'evaluations.view', 'evaluations.manage']);
        $this->migrateAttendanceDeletions();
        $reader = $this->bindReportReader();
        $this->mockLegacyAttendance($reader);
        $reader->shouldReceive('snapshot')->with(4, '2026-09')->andReturn($this->reportSnapshot('2026-09'));
        $reader->shouldReceive('snapshot')->with(4, '2026-10')
            ->andReturn($this->reportSnapshot('2026-10') + ['previous_month' => '2026-09']);

        $salary = self::HOST.'/admin/rating/summary/legacy/4?month=2026-10';
        $this->getJson($salary)->assertOk()->assertJsonPath('salary.absent', -100)->assertJsonPath('salary.total', 850);
        $service = $this->app->make(EvaluationService::class);
        $subject = ['type' => 'legacy', 'id' => 4, 'native_id' => null];
        $before = $service->load($subject, '2026-10');
        $this->get(self::ATTENDANCE)->assertOk()->assertSee('G71')->assertSee('class="btn-delete"', false);

        $payload = ['source' => 'legacy', 'legacy_record_id' => 106, 'request_id' => (string) Str::uuid()];
        $receipt = ['request_id' => $payload['request_id']];
        $this->postJson(self::ATTENDANCE.'/delete', $payload)->assertOk()->assertExactJson($receipt);
        $this->postJson(self::ATTENDANCE.'/delete', $payload)->assertOk()->assertExactJson($receipt);
        $this->postJson(self::ATTENDANCE.'/delete', array_replace($payload, ['legacy_record_id' => 999]))->assertConflict();
        $this->postJson(self::ATTENDANCE.'/delete', array_replace($payload, [
            'request_id' => (string) Str::uuid(),
        ]))->assertConflict();

        $this->assertDatabaseCount('ppb_attendance_deletions', 1);
        $this->assertDatabaseHas('ppb_attendance_deletions', [
            'legacy_record_id' => 106, 'legacy_gid' => 320, 'subject_type' => 'legacy', 'subject_id' => 4,
            'attendance_status' => 2, 'recommended_time' => '2026-10-06 08:00:00', 'actor_id' => 71,
            'request_id' => $payload['request_id'], 'restored_at' => null,
        ]);
        $this->assertDatabaseCount('ppb_auth_audits', 1);
        $this->assertDatabaseHas('ppb_auth_audits', ['account_id' => 71, 'event' => 'attendance.deleted#legacy:106']);
        $this->get(self::ATTENDANCE)->assertOk()->assertDontSee('G71')->assertSee('本月無出缺勤紀錄');
        $this->getJson($salary)->assertOk()->assertJsonPath('salary.absent', 0)->assertJsonPath('salary.total', 950);
        $after = $service->load($subject, '2026-10');
        self::assertNotSame($before['source_sha256'], $after['source_sha256']);
        self::assertSame(0, $after['current']['salary']['absent']);
        $preview = $this->app->make(EvaluationPreviewReport::class)->load($subject, '2026-10');
        self::assertSame(0, $preview['current']['salary']['absent']);
    }

    public function test_attendance_delete_rejects_unknown_and_unmigrated_legacy_targets(): void
    {
        $this->actor(['schedules.view', 'evaluations.view', 'evaluations.manage']);
        $payload = ['source' => 'legacy', 'legacy_record_id' => 999, 'request_id' => (string) Str::uuid()];
        $this->postJson(self::ATTENDANCE.'/delete', $payload)->assertStatus(503);

        $this->migrateAttendanceDeletions();
        $reader = Mockery::mock(LegacyReader::class);
        $reader->shouldReceive('query')->once()->with(Mockery::on(fn ($query) =>
            $query['operation'] === 'select' && $query['table'] === 'pay.pay_attendance_record'
            && $query['filters'] === [['column' => 'id', 'op' => '=', 'value' => 999]]))->andReturn(['rows' => []]);
        $this->app->instance(LegacyReader::class, $reader);
        $this->postJson(self::ATTENDANCE.'/delete', $payload)->assertNotFound();
        $this->postJson(self::ATTENDANCE.'/delete', array_replace($payload, ['request_id' => 'bad']))->assertUnprocessable();
        $this->assertDatabaseCount('ppb_attendance_deletions', 0);
        $this->assertDatabaseCount('ppb_auth_audits', 0);
    }

    public static function nativeAttendanceSources(): array
    {
        return ['derived substitution' => [false], 'V2 correction' => [true]];
    }

    #[DataProvider('nativeAttendanceSources')]
    public function test_v2_attendance_delete_writes_one_waiver_and_clears_the_salary_deduction(bool $corrected): void
    {
        $this->actor(['schedules.view', 'evaluations.view', 'evaluations.manage']);
        DB::table('ppb_analysts')->insert([['id' => 9, 'name' => 'Original analyst'], ['id' => 10, 'name' => 'Substitute']]);
        DB::table('ppb_games')->insert([
            'id' => 7, 'game_type' => 1, 'game_number' => 66, 'recommended_at' => '2026-11-01 00:00:00',
        ]);
        DB::table('ppb_assignments')->insert(['id' => 8, 'game_id' => 7, 'kind' => 'pick', 'original_analyst_id' => 9, 'current_analyst_id' => 10]);
        foreach ([11, 12] as $pick) {
            DB::table('ppb_submissions')->insert([
                'id' => $pick, 'game_id' => 7, 'assignment_id' => 8, 'kind' => 'pick', 'submitted_analyst_id' => 10,
                'submitted_at' => '2026-11-01 00:00:00', 'status' => 'submitted',
            ]);
        }
        if ($corrected) {
            DB::table('ppb_evaluation_attendance')->insert([
                'game_id' => 7, 'subject_type' => 'v2', 'subject_id' => 9, 'attendance_status' => 2, 'revision' => 3,
            ]);
        }
        $service = $this->app->make(EvaluationService::class);
        $subject = ['type' => 'v2', 'id' => 9, 'native_id' => 9];
        self::assertSame(-100, $service->loadLocal($subject, '2026-11')['current']['salary']['absent']);
        $this->get(self::ATTENDANCE)->assertOk()->assertSeeInOrder(['class="btn-delete"', '#evaluation-attendance'], false);
        $payload = ['source' => 'v2', 'subject_type' => 'v2', 'subject_id' => 9, 'game_id' => 7, 'request_id' => (string) Str::uuid()];
        $receipt = ['request_id' => $payload['request_id']];
        $this->postJson(self::ATTENDANCE.'/delete', $payload)->assertOk()->assertExactJson($receipt);
        $this->postJson(self::ATTENDANCE.'/delete', $payload)->assertOk()->assertExactJson($receipt);
        $this->postJson(self::ATTENDANCE.'/delete', array_replace($payload, [
            'request_id' => (string) Str::uuid(),
        ]))->assertNotFound();
        $this->assertDatabaseCount('ppb_evaluation_attendance', 1);
        $this->assertDatabaseHas('ppb_evaluation_attendance', [
            'game_id' => 7, 'subject_type' => 'v2', 'subject_id' => 9, 'attendance_status' => null,
            'revision' => $corrected ? 4 : 1, 'actor_id' => 71, 'waived_submission_id' => 11,
        ]);
        $this->assertDatabaseCount('ppb_auth_audits', 1);
        $this->assertDatabaseHas('ppb_auth_audits', ['account_id' => 71, 'event' => 'attendance.deleted#v2:v2:9:7']);
        $this->get(self::ATTENDANCE)->assertOk()->assertDontSee('G66')->assertSee('本月無出缺勤紀錄');
        self::assertSame(0, $service->loadLocal($subject, '2026-11')['current']['salary']['absent']);
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

    public function test_reports_use_bridge_summaries_and_render_warm_rows_server_side(): void
    {
        $this->actor(['schedules.view', 'evaluations.view']);
        $reader = $this->bindReportReader();
        $reader->shouldReceive('snapshot')->once()->with(4, '2026-11')->andReturn($this->reportSnapshot('2026-11'));
        $reader->shouldReceive('snapshot')->twice()->with(4, '2026-10')->andReturn($this->reportSnapshot('2026-10'));
        $reader->shouldReceive('snapshot')->once()->with(4, '2026-09')->andReturn($this->reportSnapshot('2026-09'));

        $this->get(self::HOST.'/admin/analyst-list')->assertOk()
            ->assertSeeInOrder(['哆啦', '資深分析師', '載入中...'])
            ->assertSee('/admin/performance/summary/legacy/4?month=2026-11')
            ->assertSee('2026/11月')->assertSee('2026/6月')->assertDontSee('洛老師');

        $this->getJson(self::HOST.'/admin/performance/summary/legacy/4')
            ->assertOk()->assertJsonPath('rating', 'S')->assertJsonPath('score', 69)
            ->assertJsonPath('performance.month.rate', 50)
            ->assertJsonPath('performance.recent10.rate', 60)
            ->assertJsonPath('performance.bonus.rate', 100)
            ->assertJsonMissingPath('salary')->assertJsonMissingPath('line_name');
        $this->get(self::HOST.'/admin/analyst-list')->assertOk()
            ->assertSeeInOrder(['資深分析師', '50%', '>69<', '>S<'], false)->assertDontSee('載入中...');
        $this->get(self::HOST.'/admin/performance')->assertOk()
            ->assertSeeInOrder(['50%', '1 - 1', '60%', '6 - 4', '1 - 0', '100%'])->assertDontSee('載入中...');

        $this->getJson(self::HOST.'/admin/rating/summary/legacy/4')->assertOk()
            ->assertJsonPath('rating', 'A')->assertJsonPath('next_rating', 'S')->assertJsonPath('change', '升級')
            ->assertJsonPath('salary.short', 200)->assertJsonPath('salary.late', -50)
            ->assertJsonPath('salary.absent', -100)->assertJsonPath('salary.long', 400)
            ->assertJsonPath('salary.video', 400)->assertJsonPath('salary.total', 850)
            ->assertJsonPath('scores', [68, 67, 66]);
        $this->get(self::HOST.'/admin/rating/index')->assertOk()
            ->assertSeeInOrder(['>850<', '>S<', '>A<', '>升級<', '>68<', '>67<', '>66<', '總計'], false)
            ->assertSee('<option>D</option>', false)->assertDontSee('載入中...');
        $salary = $this->get(self::HOST.'/admin/rating/salary')->assertOk()->assertDontSee('載入中...');
        self::assertMatchesRegularExpression('~data-report-total="total"\s*>850</td>~', $salary->getContent());
        $this->get(self::HOST.'/admin/rating/change')->assertOk()
            ->assertSeeInOrder(['>S<', '>A<', '>升級<', '>68<', '>67<', '>66<'], false)->assertDontSee('載入中...');

        // The second summary and the warm page requests perform no new snapshots.
        $this->getJson(self::HOST.'/admin/rating/summary/legacy/4')->assertOk()->assertJsonPath('salary.total', 850);
        $this->actor(['schedules.view']);
        $this->getJson(self::HOST.'/admin/rating/summary/legacy/4')->assertForbidden();
        $this->getJson(self::HOST.'/admin/performance/summary/legacy/4?month=2026-10')
            ->assertOk()->assertJsonMissingPath('salary')->assertJsonMissingPath('cells.total');
    }

    public function test_current_analyst_list_rating_falls_back_to_legacy_profile(): void
    {
        $this->actor(['schedules.view']);
        $reader = $this->bindReportReader();
        foreach (['2026-11', '2026-10'] as $month) {
            $snapshot = $this->reportSnapshot($month);
            $snapshot['ratings'] = [];
            $reader->shouldReceive('snapshot')->once()->with(4, $month)->andReturn($snapshot);
        }

        $this->getJson(self::HOST.'/admin/performance/summary/legacy/4')
            ->assertOk()->assertJsonPath('rating', 'B')->assertJsonPath('performance.month.rate', 50);
        $this->get(self::HOST.'/admin/analyst-list')->assertOk()
            ->assertSeeInOrder(['50%', '>69<', '>B<'], false)->assertDontSee('載入中...');
    }

    public function test_expired_rows_render_last_known_values_and_refresh_in_background(): void
    {
        $this->actor(['schedules.view']);
        $reader = $this->bindReportReader();
        foreach (['2026-11', '2026-10'] as $month) {
            $reader->shouldReceive('snapshot')->with(4, $month)->andReturn($this->reportSnapshot($month));
        }

        $this->getJson(self::HOST.'/admin/performance/summary/legacy/4')->assertOk();
        $this->travel(10)->minutes();

        $page = $this->get(self::HOST.'/admin/analyst-list')->assertOk()
            ->assertSeeInOrder(['資深分析師', '50%', '>69<', '>S<'], false)->assertDontSee('載入中...');
        $html = $page->getContent();
        // The stale row is refreshed by the browser but is not shown as loading.
        self::assertStringContainsString('/admin/performance/summary/legacy/4?month=2026-11', $html);
        self::assertStringNotContainsString('aria-busy', $html);
        $this->get(self::HOST.'/admin/performance')->assertOk()->assertDontSee('載入中...');
    }

    public function test_pay_month_tabs_start_at_previous_taipei_month(): void
    {
        $this->actor(['evaluations.view']);
        $headers = ['11月評級', '10月評級', '評級異動', '10月評分', '9月評分', '8月評分'];
        foreach (['index', 'salary', 'change'] as $page) {
            $response = $this->get(self::HOST.'/admin/rating/'.$page)->assertOk()->assertViewHas('month', '2026-10')
                ->assertSee('class="ppb-segments ppb-list-tabs" aria-label="月份"', false);
            self::assertMatchesRegularExpression('/aria-current="page"\s*>2026年10月<\/a>/u', $response->getContent());
            preg_match_all('/2026年[0-9]+月/u', $response->getContent(), $tabs);
            self::assertSame(['2026年10月', '2026年9月', '2026年8月', '2026年7月', '2026年6月'], $tabs[0]);

            if ($page !== 'salary') {
                preg_match('/<thead>.*?<\/thead>/s', $response->getContent(), $tableHeader);
                preg_match_all('/(?:[0-9]+月評級|評級異動|[0-9]+月評分)/u', $tableHeader[0], $labels);
                self::assertSame($headers, $labels[0]);
            }
        }
    }

    public function test_summary_reader_failure_is_reported_without_caching_zero_values(): void
    {
        $this->actor(['evaluations.view']);
        $reader = $this->bindReportReader();
        $reader->shouldReceive('snapshot')->once()->with(4, '2026-10')->andThrow(new \RuntimeException('LEGACY_READER_UNAVAILABLE'));
        $salary = $this->get(self::HOST.'/admin/rating/salary')->assertOk()->assertSee('載入中...');
        self::assertMatchesRegularExpression('~data-report-total="total"\s*>—</td>~u', $salary->getContent());
        $this->getJson(self::HOST.'/admin/rating/summary/legacy/4')->assertStatus(503)
            ->assertExactJson(['message' => '讀取失敗']);
        $this->get(self::HOST.'/admin/rating/salary')->assertOk()->assertSee('載入中...');
    }

    private function bindReportReader(): LegacyReader
    {
        $reader = Mockery::mock(LegacyReader::class);
        $reader->shouldReceive('profiles')->andReturn([
            ['id' => 7, 'sort_id' => 1, 'user_type' => 2, 'rating_level' => 0, 'analyst_level' => 0],
            ['id' => 4, 'sort_id' => 4, 'user_type' => 0, 'rating_level' => 1, 'analyst_level' => 1],
        ]);
        $this->app->instance(LegacyReader::class, $reader);

        return $reader;
    }

    private function reportSnapshot(string $month): array
    {
        $prior = CarbonImmutable::createFromFormat('!Y-m', $month)->subMonth()->format('Y-m');
        $next = CarbonImmutable::createFromFormat('!Y-m', $month)->addMonth()->format('Y-m');
        $picks = $columns = $schedules = $attendance = $evaluations = $ratings = [];
        foreach ([$prior, $month] as $period) {
            $index = (int) substr($period, -2);
            foreach ([[1, 0, 0, 1], [2, 0, 0, 2], [3, 1, 0, 1], [4, 0, 1, 0]] as [$day, $bonus, $long, $result]) {
                $id = $index * 10 + $day;
                $pick = [
                    'id' => $id, 'gid' => $id, 'analyst_name' => 4, 'is_substitute' => 0,
                    'recommended_time' => $period.sprintf('-%02d 08:00:00', $day),
                    'is_bonus' => $bonus, 'is_long' => $long, 'is_win' => $result,
                    'score_delivery_time' => 60, 'score_word_count' => 60, 'score_format' => 60, 'score_typos' => 60,
                ] + array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS, 0);
                $columns = array_keys($pick);
                $picks[] = array_values($pick);
                $schedules[] = ['id' => $id, 'game_type' => 1, 'game_id' => $day, 'market_type' => 1];
            }
            $attendance[] = ['id' => $index * 10 + 5, 'recommended_time' => $period.'-05 08:00:00', 'attendance_status' => 1];
            $attendance[] = ['id' => $index * 10 + 6, 'recommended_time' => $period.'-06 08:00:00', 'attendance_status' => 2];
            $evaluations[] = ['eval_month' => $period, 'score_extra_adjustment' => $index + 2];
        }
        foreach ([$prior, $month, $next] as $period) {
            $ratings[] = ['rating_month' => $period, 'rating_level' => $period >= '2026-11' ? 3 : 2];
        }
        return [
            'picks' => $picks, 'pick_columns' => $columns, 'schedules' => $schedules,
            'attendance' => $attendance, 'evaluations' => $evaluations, 'ratings' => $ratings,
            'video_counts' => [$prior => 1, $month => 1],
            'recent' => array_map(fn ($i) => ['id' => $i, 'recommended_time' => $month.sprintf('-%02d 08:00:00', $i),
                'is_win' => $i > 4 ? 1 : 2], range(1, 10)),
            'source_sha256' => str_repeat('a', 64), 'profile' => ['id' => 4, 'rating_level' => 1],
        ];
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
            ->assertSee('編輯／補齊人員')->assertSee('#delete-game">刪除賽事</a>', false)
            ->assertSee('data-dialog-title="編輯賽事"', false)
            ->assertSee('<button class="admin-unsettled-button" id="ppbUnsettledButton" type="button"', false)
            ->assertSee('aria-haspopup="dialog" aria-expanded="false" aria-controls="ppbUnsettledDropdown"', false)
            ->assertSee('id="ppbUnsettledDropdown" role="dialog" aria-labelledby="ppbUnsettledTitle" tabindex="-1" hidden', false)
            ->assertSee('! 未結算場次')
            ->assertSee('<strong id="ppbUnsettledTitle">未結算場次</strong><span>待處理</span>', false)
            ->assertSee('<p class="admin-sidebar-label">MAIN</p>', false)->assertSee('安全登出')
            ->assertDontSee('首页')->assertDontSee('定時廣播')->assertDontSee('中華民國運動彩券發展協會')
            ->assertSee('data-open-rating', false)->assertSee('id="ppbScheduleRatingTitle">分析師評鑑</h2>', false);
        $shift->assertViewHas('games', fn ($pager) => $pager->getCollection()->pluck('id')->all() === [15]);

        $this->get(self::HOST.'/admin/games/create?modal=1')->assertOk()
            ->assertSee('盤口類型')->assertSee('手動填寫玩法')->assertSee('BONUS 分析師')
            ->assertDontSee('id="ppbUnsettledCount"', false)->assertDontSee('id="ppbUnsettledDropdown"', false);

        $this->get(self::HOST.'/admin/rating/index')->assertOk()
            ->assertSee('11月評級')->assertSee('10月評級')->assertDontSee('載入中...')
            ->assertDontSee('12月評級');
        $this->get(self::HOST.'/admin/performance')->assertOk()->assertDontSee('載入中...');
        $this->get(self::HOST.'/admin/schedule/videos')->assertOk()
            ->assertSee('影音排班')->assertSee('新增影音排班')->assertSee('週次');
        $this->get(self::HOST.'/admin/csda/users')->assertOk()->assertSee('CSDA 用戶資料目前未匯入 V2');
    }

    private function seedArchive(array $scores = []): int
    {
        DB::table('ppb_analysts')->insert(['id' => 11, 'name' => '運彩ABC']);
        DB::table('ppb_games')->insert([
            'id' => 15, 'month_key' => '2026-11', 'recommended_at' => '2026-11-05 08:00:00',
            'game_type' => 1, 'game_number' => 15, 'status' => 'scheduled', 'matchup_text' => 'Fixture',
            'notes' => json_encode(['_ppb_legacy_game_v1' => true, 'lottery_no' => '0123']),
        ]);
        DB::table('ppb_legacy_pick_contents')->insert([
            'game_id' => 15, 'source_pick_id' => 9001, 'legacy_analyst_id' => 4, 'kind' => 'pick', 'legacy_is_win' => 1,
        ]);
        DB::table('ppb_submissions')->insert([
            'game_id' => 15, 'legacy_source_pick_id' => 9001, 'submitted_analyst_id' => 11,
            'kind' => 'pick', 'status' => 'legacy_imported', 'content_body' => json_encode(['_ppb_legacy_pick_v1' => true] + $scores),
        ]);

        return DB::table('ppb_assignments')->insertGetId([
            'game_id' => 15, 'kind' => 'pick', 'slot_no' => 1, 'original_analyst_id' => 11, 'current_analyst_id' => 11,
        ]);
    }

    public function test_archive_rating_saves_v2_override_with_revision_and_receipt(): void
    {
        $this->actor(['schedules.view', 'evaluations.view', 'evaluations.manage']);
        // Emulate MySQL session lock calls on this test's one SQLite connection.
        $held = false;
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('CONNECTION_ID', fn () => 1, 0);
        $pdo->sqliteCreateFunction('IS_USED_LOCK', function () use (&$held) {
            return $held ? 1 : null;
        }, 1);
        $pdo->sqliteCreateFunction('GET_LOCK', function () use (&$held) {
            $held = true;

            return 1;
        }, 2);
        $pdo->sqliteCreateFunction('RELEASE_LOCK', function () use (&$held) {
            $held = false;

            return 1;
        }, 1);
        $scores = array_combine(LegacyScheduleCalculator::RATING_FIELDS, [60, 60, 60, 20, 20, 20, 20, 10, 0]);
        $assignment = $this->seedArchive($scores);
        $archive = DB::table('ppb_legacy_pick_contents')->get()->all();
        $submissions = DB::table('ppb_submissions')->get()->all();
        $url = self::HOST.'/admin/games/15/assignments/'.$assignment.'/rating';
        $rating = $this->getJson($url)->assertOk()->assertJsonPath('pick._source_type', 'legacy')
            ->assertJsonPath('pick._source_id', 9001)->assertJsonPath('sections.delivery', 60)
            ->assertJsonPath('sections.compliance', 60)->assertJsonPath('sections.professional', 90)
            ->assertJsonPath('average', 70)->assertJsonPath('revision', 0)->json();
        $request = array_replace($scores, [
            'score_word_count' => 40, 'schedule_scope' => 'rating', 'operation' => 'rate',
            'source_type' => 'legacy', 'source_id' => 9001, 'month' => '2026-11', 'revision' => 0,
            'source_sha256' => $rating['source_sha256'], 'request_id' => '99b1e56d-7db5-4524-b567-8f028610079a',
        ]);
        $save = self::HOST.$rating['save_url'];
        $this->postJson($save, $request)->assertOk()->assertJsonPath('revision', 1)
            ->assertJsonPath('replayed', false)->assertJsonPath('request_id', $request['request_id']);
        $this->postJson($save, $request)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($save, array_replace($request, ['request_id' => '99b1e56d-7db5-4524-b567-8f028610079b']))
            ->assertStatus(409);
        $this->assertDatabaseCount('ppb_evaluation_events', 1);
        $this->assertDatabaseHas('ppb_evaluation_events', ['operation' => 'rate', 'request_id' => $request['request_id']]);
        $this->assertDatabaseCount('ppb_evaluation_ratings', 1);
        $this->assertDatabaseHas('ppb_evaluation_ratings', [
            'source_type' => 'legacy', 'source_id' => 9001, 'subject_type' => 'legacy', 'subject_id' => 4,
            'score_word_count' => 40, 'result_state' => null,
        ]);
        self::assertEquals($archive, DB::table('ppb_legacy_pick_contents')->get()->all());
        self::assertEquals($submissions, DB::table('ppb_submissions')->get()->all());
        $this->getJson($url)->assertOk()->assertJsonPath('pick.score_word_count', 40)
            ->assertJsonPath('sections.compliance', 50)->assertJsonPath('revision', 1);

        $source = $this->reportSnapshot('2026-11');
        $source['picks'][] = array_values(array_replace(array_fill_keys($source['pick_columns'], null), $scores, [
            'id' => 9001, 'gid' => 15, 'analyst_name' => 4, 'is_substitute' => 0,
            'recommended_time' => '2026-11-05 16:00:00', 'is_bonus' => 0, 'is_long' => 0, 'is_win' => 1,
        ]));
        $reader = $this->bindReportReader();
        $reader->shouldReceive('snapshot')->once()->with(4, '2026-11')->andReturn($source);
        $reader->shouldReceive('snapshot')->once()->with(4, '2026-10')->andReturn($this->reportSnapshot('2026-10'));
        $report = app(EvaluationService::class)
            ->load(['type' => 'legacy', 'id' => 4, 'native_id' => null], '2026-11');
        $pick = collect($report['current']['selected']['records'])->firstWhere('_source_id', 9001);
        self::assertSame(40, $pick['score_word_count']);
        self::assertSame(1, $pick['is_win']);
    }

    public function test_csda_users_render_in_v2_tables_with_read_only_statuses(): void
    {
        (require database_path('migrations/2026_11_26_000016_import_csda_users_line_names_and_reassign_picks.php'))->createTables();
        foreach (range(1, 23) as $id) {
            DB::table('ppb_csda_users')->insert([
                'legacy_id' => $id, 'name' => 'Synthetic '.$id, 'type' => $id <= 20 ? 1 : 2, 'line_id' => 'Usyn'.$id,
                'remark' => $id === 1 ? 'memo' : null, 'is_listen' => $id % 2, 'is_active' => true,
                'is_paid' => $id === 2, 'is_dealer' => $id === 21,
            ]);
        }
        $url = self::HOST.'/admin/csda/users';

        $this->actor(['schedules.view']);
        $this->get($url)->assertOk()->assertSee('需要排班管理及帳號管理權限')->assertDontSee('Synthetic 1');

        $this->actor(['schedules.view', 'schedules.manage', 'accounts.manage']);
        $page = $this->get($url)->assertOk()
            ->assertSeeInOrder(['全部用戶', '23', '個人', '20', '群組', '3', '已開通', '23', '付費會員', '1'])
            ->assertSeeInOrder(['名稱', '類型', 'LINE ID', '備註', '監聽狀態', '開通', '付費', '經銷商'])
            ->assertSee('class="data-table ppb-users-table"', false)
            ->assertSee('Synthetic 1')->assertSee('Usyn1')->assertSee('memo')->assertSee('監聽中')
            ->assertSee('class="status enabled">付費</span>', false)
            ->assertSee('共 23 筆 / 第 1 頁')->assertSee('rel="next">下一頁</a>', false)
            ->assertDontSee('Synthetic 21');
        $html = $page->getContent();
        self::assertStringNotContainsString('toggleActive', $html);
        $block = substr($html, strpos($html, 'data-csda-readonly'));
        self::assertStringNotContainsString('method="post"', strtolower(substr($block, 0, strpos($block, '</main>') ?: strlen($block))));
        self::assertStringNotContainsString('type="checkbox"', $block);

        $this->get($url.'?page=2')->assertOk()->assertSee('Synthetic 21')->assertSee('經銷');
        $this->get($url.'?filter=group_dealer')->assertOk()->assertSee('Synthetic 21')->assertDontSee('Synthetic 22')
            ->assertSee('共 1 筆');
        $this->get($url.'?q=usyn22')->assertOk()->assertSee('Synthetic 22')->assertDontSee('Synthetic 2<', false);
    }

    public function test_unsettled_badge_loads_asynchronously_and_counts_distinct_legacy_games(): void
    {
        $this->actor(['schedules.view']);
        $this->seedArchive();
        DB::table('ppb_games')->where('id', 15)->update([
            'month_key' => '2026-10', 'recommended_at' => '2026-10-27 03:00:00',
        ]);
        DB::table('ppb_games')->insert([
            ['id' => 116, 'month_key' => '2026-10', 'game_type' => 2, 'game_number' => 24, 'recommended_at' => '2026-10-22 08:00:00',
                'matchup_text' => '奧蘭多魔術@鳳凰城太陽', 'status' => 'completed',
                'notes' => '{"_ppb_legacy_game_v1":true,"legacy_source_schedule_id":16}'],
            ['id' => 19, 'month_key' => '2026-10', 'game_type' => 1, 'game_number' => 19, 'recommended_at' => '2026-10-24 03:00:00',
                'matchup_text' => 'Unrelated V2 game', 'status' => 'completed', 'notes' => null],
        ]);
        $url = self::HOST.'/admin/games/unsettled-count';
        $reader = Mockery::mock(LegacyReader::class);
        $this->app->instance(LegacyReader::class, $reader);
        $this->get(self::HOST.'/admin/games')->assertOk()
            ->assertSee('data-url="/admin/games/unsettled-count">—</span>', false);
        Cache::put('ppb:legacy-unsettled:last:v1', 7);
        $this->get(self::HOST.'/admin/games')->assertOk()
            ->assertSee('class="admin-unsettled-count" id="ppbUnsettledCount" data-url="/admin/games/unsettled-count">7</span>', false);

        $query = [
            'operation' => 'select', 'table' => 'pay.pay_analyst_pick', 'columns' => ['id', 'gid', 'is_win', 'recommended_time'],
            'filters' => [
                ['column' => 'recommended_time', 'op' => '>=', 'value' => '2026-10-15 00:00:00'],
                ['column' => 'recommended_time', 'op' => '<', 'value' => '2026-11-01 00:00:00'],
                ['column' => 'is_long', 'op' => '=', 'value' => 0],
                ['column' => 'is_bonus', 'op' => '=', 'value' => 0],
            ],
            'limit' => 50, 'offset' => 0,
        ];
        $reader->shouldReceive('query')->once()->with($query)->andReturn(['rows' =>
            array_map(fn ($id) => ['id' => $id, 'gid' => 15, 'is_win' => null, 'recommended_time' => '2026-10-27 11:00:00'], range(1, 50)),
        ]);
        $reader->shouldReceive('query')->once()->with(array_replace($query, ['offset' => 50]))->andReturn(['rows' => [
            ['id' => 51, 'gid' => 15, 'is_win' => null, 'recommended_time' => '2026-10-27 11:00:00'],
            ['id' => 52, 'gid' => 16, 'is_win' => '0', 'recommended_time' => '2026-10-30 16:00:00'],
            ['id' => 53, 'gid' => 17, 'is_win' => 1, 'recommended_time' => '2026-10-24 11:00:00'],
            ['id' => 54, 'gid' => 18, 'is_win' => 2, 'recommended_time' => '2026-10-24 11:00:00'],
            ['id' => 55, 'gid' => 19, 'is_win' => 0, 'recommended_time' => '2026-10-29 11:00:00'],
            ['id' => 56, 'gid' => '19', 'is_win' => 0, 'recommended_time' => '2026-10-24 11:00:00'],
            ['id' => 57, 'gid' => null, 'is_win' => 0, 'recommended_time' => '2026-10-24 11:00:00'],
        ]]);
        $games = [
            ['code' => 'E24', 'at' => '10/22 16:00', 'matchup' => '奧蘭多魔術@鳳凰城太陽', 'url' => '/admin/games/116'],
            ['code' => '#19', 'at' => '10/24 11:00', 'matchup' => null, 'url' => null],
            ['code' => 'G15', 'at' => '10/27 11:00', 'matchup' => 'Fixture', 'url' => '/admin/games/15'],
        ];
        $expected = ['count' => count($games), 'games' => $games];
        $this->getJson($url)->assertOk()->assertExactJson($expected);
        $this->getJson($url)->assertOk()->assertExactJson($expected);
        // The last known count renders immediately; the browser then refreshes it.
        $this->get(self::HOST.'/admin/games')->assertOk()
            ->assertSee('class="admin-unsettled-count" id="ppbUnsettledCount" data-url="/admin/games/unsettled-count">3</span>', false);

        $this->travel(5)->minutes();
        $reader->shouldReceive('query')->twice()->with($query)->andThrow(new \RuntimeException('LEGACY_READER_UNAVAILABLE'));
        $this->getJson($url)->assertOk()->assertExactJson(['count' => 1, 'games' => []]);
        self::assertSame($expected, Cache::get('ppb:legacy-unsettled:last:v2'));
        Schema::drop('ppb_games');
        $this->getJson($url)->assertOk()->assertExactJson(['count' => null, 'games' => []]);
    }

    public function test_unsettled_count_returns_an_empty_list_when_no_legacy_games_qualify(): void
    {
        $this->actor(['schedules.view']);
        $this->getJson(self::HOST.'/admin/games/unsettled-count')->assertOk()
            ->assertExactJson(['count' => 0, 'games' => []]);
        $this->get(self::HOST.'/admin/games')->assertOk()
            ->assertSee('data-url="/admin/games/unsettled-count">0</span>', false);
    }

    public function test_unsettled_count_requires_an_authenticated_schedule_viewer(): void
    {
        $url = self::HOST.'/admin/games/unsettled-count';
        $this->getJson($url)->assertUnauthorized();
        $this->actor([]);
        $this->getJson($url)->assertForbidden();
    }

    public function test_game_modals_show_current_taipei_absences_and_lottery_field(): void
    {
        $this->actor(['schedules.view', 'schedules.manage']);
        $this->seedArchive();
        DB::table('ppb_analysts')->where('id', 11)->update(['user_type' => 1]);
        DB::table('ppb_analysts')->insert(['id' => 12, 'name' => '零場分析師']);
        DB::table('ppb_games')->insert([
            'id' => 16, 'month_key' => '2026-11', 'recommended_at' => '2026-11-06 08:00:00',
            'game_type' => 1, 'game_number' => 16, 'status' => 'scheduled', 'matchup_text' => 'Native fixture',
        ]);
        DB::table('ppb_evaluation_attendance')->insert([
            ['game_id' => 15, 'subject_type' => 'legacy', 'subject_id' => 4, 'attendance_status' => 2],
            ['game_id' => 16, 'subject_type' => 'legacy', 'subject_id' => 4, 'attendance_status' => 2],
        ]);
        $query = [
            'operation' => 'select', 'table' => 'pay.pay_analyst_pick', 'columns' => ['id', 'is_substitute'],
            'filters' => [
                ['column' => 'recommended_time', 'op' => '>=', 'value' => '2026-11-01 00:00:00'],
                ['column' => 'recommended_time', 'op' => '<=', 'value' => '2026-11-30 23:59:59'],
                ['column' => 'is_substitute', 'op' => '>', 'value' => 1],
            ],
            'limit' => 50, 'offset' => 0,
        ];
        $reader = Mockery::mock(LegacyReader::class);
        $reader->shouldReceive('query')->once()->with($query)->andReturn(['rows' => [
            ['id' => 1, 'is_substitute' => 4],
            ['id' => 2, 'is_substitute' => '4'],
            ['id' => 3, 'is_substitute' => 12],
        ]]);
        $this->app->instance(LegacyReader::class, $reader);
        $this->get(self::HOST.'/admin/games/15/edit?modal=1')->assertOk()
            ->assertSeeInOrder(['台彩賽事編號', 'placeholder="請輸入台彩賽事編號"', 'value="0123"'], false)
            ->assertSee('運彩ABC ( 當月曠職3場 )</option></select></label>', false)
            ->assertSee('零場分析師</option>', false);
        $this->get(self::HOST.'/admin/games/create?modal=1&match_date=2026-10-31')->assertOk()
            ->assertSee('運彩ABC ( 當月曠職3場 )');
        $options = self::HOST.'/admin/games/form-options?date=2026-12-01';
        $this->getJson($options)->assertOk()->assertJsonPath('absences.11', 3)->assertJsonMissingPath('absences.12');

        $this->travel(5)->minutes();
        $reader->shouldReceive('query')->once()->with($query)->andThrow(new \RuntimeException('LEGACY_READER_UNAVAILABLE'));
        $this->getJson($options)->assertOk()->assertJsonPath('absences.11', 1);
    }

    public function test_edit_submits_lottery_number_as_notes_metadata_without_changing_display_number(): void
    {
        $this->actor(['schedules.view', 'schedules.manage']);
        $this->seedArchive();
        $game = PpbGame::findOrFail(15);
        $revision = \App\Services\Ppb\ScheduleVersion::game($game);
        $manager = Mockery::mock(\App\Services\Ppb\ScheduleManager::class);
        $manager->shouldReceive('update')->once()->withArgs(function ($target, $data, $slots, $token) use ($revision) {
            self::assertSame(15, $target->id);
            self::assertSame($revision, $token);
            self::assertArrayNotHasKey('game_number', $data);
            self::assertSame([
                '_ppb_legacy_game_v1' => true, 'lottery_no' => '0042', 'notes' => '賽事備註',
            ], json_decode($data['notes'], true));

            return true;
        })->andReturn($game);
        $this->app->instance(\App\Services\Ppb\ScheduleManager::class, $manager);

        $this->put(self::HOST.'/admin/games/15?modal=1', [
            'revision_token' => $revision, 'lottery_no' => '0042', 'notes' => '賽事備註',
            'game_type' => 1, 'match_date' => '2026-11-05', 'match_time' => '16:00',
            'matchup_mode' => 'manual', 'manual_matchup' => 'Away@Home', 'play_type' => 2, 'market_type' => 1,
            'short' => [1 => 11],
        ])->assertRedirect(self::HOST.'/admin/games/15')->assertSessionHasNoErrors();
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

    public function test_overview_renders_old_style_rows_grouped_by_taipei_date(): void
    {
        $this->actor(['schedules.view']);
        PpbGame::unguarded(fn () => PpbGame::query()->create([
            'id' => 31, 'month_key' => '2026-11', 'recommended_at' => '2026-11-01 03:00:00',
            'game_type' => 1, 'game_number' => 31, 'status' => 'scheduled', 'matchup_text' => 'Away@Home',
            'play_type' => 1, 'play_value' => '+10.5', 'has_long_article' => true,
            'notes' => json_encode(['lottery_no' => '306']),
        ]));
        PpbGame::unguarded(fn () => PpbGame::query()->create([
            'id' => 32, 'month_key' => '2026-11', 'recommended_at' => '2026-11-01 08:00:00',
            'game_type' => 2, 'game_number' => 2, 'status' => 'scheduled', 'matchup_text' => 'East@West',
            'play_type' => 3, 'has_long_article' => false,
        ]));
        $response = $this->get(self::HOST.'/admin/schedule/overview')->assertOk()
            ->assertSee('<form class="search-card"', false)
            ->assertSee('<button type="submit" class="search-btn">搜尋</button>', false)
            ->assertSee('<span class="date-label">11/01（日）</span>', false)
            ->assertSee('<span class="tag tag-g">G31</span>', false)
            ->assertSee('<span class="time-tag time-11">11點</span>', false)
            ->assertSee('>推 讓分 +10.5</span>', false)
            ->assertSee('<span class="lottery" title="台彩賽事編號">306</span>', false)
            ->assertSee('Away@Home', false)
            ->assertSee('<span class="tag tag-e">E2</span>', false)
            ->assertSee('<span class="time-tag time-16">16點</span>', false)
            ->assertSee('>推 大小分</span>', false)
            ->assertSee('East@West', false);
        self::assertSame(1, substr_count($response->getContent(), '<span class="article-tag">分析文</span>'));
    }

    public function test_public_schedule_overview_is_read_only_for_guests(): void
    {
        PpbGame::unguarded(fn () => PpbGame::query()->create([
            'id' => 41, 'month_key' => '2026-11', 'recommended_at' => '2026-11-01 03:00:00',
            'game_type' => 1, 'game_number' => 41, 'status' => 'scheduled', 'matchup_text' => 'Guest@Host',
            'play_type' => 2, 'has_long_article' => true, 'notes' => json_encode(['lottery_no' => '777']),
        ]));
        $response = $this->get(self::HOST.'/schedule-overview')->assertOk()
            ->assertSee('<title>賽事排班總覽</title>', false)
            ->assertSee('<span class="tag tag-g">G41</span>', false)
            ->assertSee('>推 不讓分</span>', false)
            ->assertSee('<span class="lottery" title="台彩賽事編號">777</span>', false)
            ->assertSee('<span class="article-tag">分析文</span>', false)
            ->assertSee('<div class="ss-row">', false);
        $html = $response->getContent();
        self::assertStringNotContainsString('/admin', $html);
        self::assertStringNotContainsString('_token', $html);
        self::assertStringNotContainsString('admin-sidebar', $html);
        self::assertStringNotContainsString('admin-topbar', $html);
        self::assertStringNotContainsString('method="post"', strtolower($html));
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies());
        $this->get(self::HOST.'/schedule-overview?p=2')->assertOk();
        $this->get(self::HOST.'/schedule-overview?match_no=G41')->assertOk()->assertSee('G41');
    }

    public function test_admin_schedule_pages_still_require_login_after_public_overview(): void
    {
        foreach (['/admin/schedule/overview', '/admin/games', '/admin/games/41', '/admin/analyst-list', '/admin/games/unsettled-count'] as $path) {
            $status = $this->get(self::HOST.$path)->getStatusCode();
            self::assertContains($status, [302, 401], $path);
        }
    }

    private function seedArticleGames(): void
    {
        DB::table('ppb_analysts')->insert(['id' => 61, 'name' => 'Writer', 'color' => '#ffcfc9', 'background' => '#b10202', 'line_id' => 'Uprivate']);
        foreach ([[51, '2026-11-01 03:00:00', 1], [52, '2026-11-01 08:00:00', 2], [53, '2026-11-02 08:00:00', 3]] as [$id, $at, $number]) {
            PpbGame::unguarded(fn () => PpbGame::query()->create([
                'id' => $id, 'month_key' => '2026-11', 'recommended_at' => $at, 'game_type' => 1, 'game_number' => $number,
                'status' => 'scheduled', 'matchup_text' => 'Match '.$id, 'has_long_article' => true,
            ]));
            DB::table('ppb_assignments')->insert(['game_id' => $id, 'current_analyst_id' => 61, 'kind' => 'long', 'status' => 'active']);
        }
    }

    public function test_articles_page_uses_old_system_rows(): void
    {
        $this->actor(['schedules.view']);
        $this->seedArticleGames();
        $html = $this->get(self::HOST.'/admin/schedule/articles')->assertOk()
            ->assertSee('<div class="month-title">2026 年 11 月</div>', false)
            ->assertSee('<span class="date-label">11/01（日）</span>', false)
            ->assertSee('<span class="tag tag-g">G1</span>', false)
            ->assertSee('<span class="analyst-tag" style="background:#b10202;color:#ffcfc9">Writer</span>', false)
            ->assertSee('<div class="ss-row new-date">', false)
            ->assertSee('Match 53', false)
            ->getContent();
        self::assertSame(1, substr_count($html, '<span class="date-label"></span>'));
    }

    public function test_public_analysis_schedule_is_read_only_for_guests(): void
    {
        $this->seedArticleGames();
        $response = $this->get(self::HOST.'/analysis-schedule')->assertOk()
            ->assertSee('<title>分析文排班總覽</title>', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', false)
            ->assertSee('<span class="tag tag-g">G2</span>', false)
            ->assertSee('Writer', false);
        $html = $response->getContent();
        foreach (['/admin', '_token', 'admin-sidebar', 'admin-topbar', 'Uprivate'] as $needle) {
            self::assertStringNotContainsString($needle, $html);
        }
        self::assertStringNotContainsString('method="post"', strtolower($html));
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies());
        $this->get(self::HOST.'/analysis-schedule?p=2')->assertOk();
        self::assertContains($this->get(self::HOST.'/admin/schedule/articles')->getStatusCode(), [302, 401]);
    }

    public function test_videos_page_uses_old_system_cards(): void
    {
        $this->actor(['schedules.view', 'evaluations.view', 'evaluations.manage']);
        DB::table('ppb_analysts')->insert(['id' => 62, 'name' => '羅蘋哥', 'user_type' => 0, 'color' => '#f3f9fd', 'background' => '#0642b1']);
        DB::table('ppb_evaluation_videos')->insert([
            'scheduled_at' => '2026-11-08 04:00:00', 'guest_1' => 'v2:62', 'week_no' => 1, 'sport_label' => 'NBA',
            'host_name' => '湘瑩', 'editor_name' => 'BONBON', 'cancelled' => false,
        ]);
        $html = $this->get(self::HOST.'/admin/schedule/videos?month=2026-11')->assertOk()
            ->assertSee('<span class="sport-tag nba">NBA</span>', false)
            ->assertSee('<span class="card-date">11/8（第一周）</span>', false)
            ->assertSee('<div class="avatar" style="background:#7f1d1d;color:#fee2e2">湘瑩</div>', false)
            ->assertSee('<div class="avatar" style="background:#0642b1;color:#f3f9fd"><img src="', false)
            ->assertSee('assets/ppb/people/'.substr(sha1('羅蘋哥'), 0, 12).'.webp', false)
            ->assertSee('<span class="person-role">來賓 3</span>', false)
            ->assertSee('<span class="editor-name">BONBON</span>', false)
            ->assertSee('＋ 新增班表')
            ->assertDontSee('影音<span>班表</span>', false)
            ->assertDontSee('aria-label="較早的月份"', false)
            ->getContent();
        self::assertStringContainsString('ppb-videos-legacy.css', $html);
        self::assertFileExists(public_path('assets/ppb/people/'.substr(sha1('BONBON'), 0, 12).'.webp'));
    }
}
