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
        config(['app.url' => self::HOST]);
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
            $table->timestamps();
        });
        Schema::create('ppb_assignments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('game_id');
            $table->unsignedBigInteger('current_analyst_id')->nullable();
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
        $subjects = Mockery::mock(EvaluationSubjects::class);
        $subjects->shouldReceive('get')->once()->with('legacy', 4)->andReturn($subject);
        $this->app->instance(EvaluationSubjects::class, $subjects);
        $service = Mockery::mock(EvaluationService::class);
        $service->shouldReceive('load')->once()->with($subject, '2026-11')->andReturn([
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
        self::assertSame('舊分析師 #7', $other['label']);
    }
}
