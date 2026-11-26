<?php
namespace Tests\Feature;

use App\Models\PpbAccount;
use App\Services\Ppb\Evaluation\EvaluationSubjects;
use App\Services\Ppb\Evaluation\EvaluationPreviewReport;
use App\Services\Ppb\Evaluation\LegacyReader;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Mockery;
use Tests\TestCase;

class EvaluationReadonlyPreviewTest extends TestCase
{
    private function actor(bool $owner=true):void
    {
        config(['app.url'=>'https://v2.csdatw.xyz']);
        $user=Mockery::mock(PpbAccount::class)->makePartial();
        $user->forceFill(['id'=>71,'name'=>'UI fixture','username'=>'fixture','is_active'=>true]);
        $user->shouldReceive('hasPpbPermission')->andReturnUsing(fn($code)=>$code==='accounts.manage');
        $roles=Mockery::mock(BelongsToMany::class);$roles->shouldReceive('where')->andReturnSelf();
        $roles->shouldReceive('exists')->andReturn($owner);$user->shouldReceive('roles')->andReturn($roles);
        $this->actingAs($user,'ppb');
    }

    public function test_month_defaults_to_current_taipei_month(): void
    {
        $this->actor();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-31 16:00:00', 'UTC'));
        $subjects = Mockery::mock(EvaluationSubjects::class);
        $subjects->shouldReceive('all')->once()->andReturn([]);
        $this->app->instance(EvaluationSubjects::class, $subjects);

        $this->get('https://v2.csdatw.xyz/admin/evaluations-preview')
            ->assertOk()->assertViewHas('month', '2026-11');
    }

    public function test_guest_and_untrusted_hosts_are_not_allowed():void
    {
        config(['app.url'=>'https://v2.csdatw.xyz']);
        $this->get('https://v2.csdatw.xyz/admin/evaluations-preview')->assertRedirect();
        $this->get('https://v2.csdatw.xyz/admin/evaluations-preview/health')->assertRedirect();
        $this->actor();
        $this->get('http://v2.csdatw.xyz/admin/evaluations-preview')->assertForbidden();
        $this->get('https://foreign.example/admin/evaluations-preview')->assertForbidden();
    }

    public function test_owner_only_readonly_list_escapes_names_and_has_no_save_form():void
    {
        $this->actor();
        $subjects=Mockery::mock(EvaluationSubjects::class);
        $subjects->shouldReceive('all')->once()->andReturn([
            ['type'=>'legacy','id'=>2,'label'=>'<script>alert(1)</script>','status'=>null,'mapped'=>false],
            ['type'=>'v2','id'=>3,'label'=>'Native fixture','status'=>null,'mapped'=>true]]);
        $this->app->instance(EvaluationSubjects::class,$subjects);
        $this->get('https://v2.csdatw.xyz/admin/evaluations-preview?month=2026-05')
            ->assertOk()->assertHeader('Cache-Control','no-store, private')
            ->assertSee('月評鑑與薪酬｜唯讀預覽')
            ->assertSee('月評鑑預覽（唯讀）')
            ->assertSee('class="admin-menu-item active" href="https://v2.csdatw.xyz/admin/evaluations-preview"', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;',false)
            ->assertDontSee('<script>alert(1)</script>',false)->assertDontSee('Native fixture')
            ->assertDontSee('name="operation"',false);
        $this->post('https://v2.csdatw.xyz/admin/evaluations-preview',[])->assertStatus(405);
    }

    public function test_non_owner_cannot_view():void
    {
        $this->actor(false);
        $this->get('https://v2.csdatw.xyz/admin/evaluations-preview')->assertForbidden();
    }

    public function test_preview_owner_permission_does_not_grant_draft_writer_permission():void
    {
        $this->actor(); // accounts.manage only; evaluations.manage remains denied.
        $writer=Mockery::mock(\App\Services\Ppb\Evaluation\EvaluationWriter::class);
        $writer->shouldNotReceive('save');$this->app->instance(\App\Services\Ppb\Evaluation\EvaluationWriter::class,$writer);
        $this->post('https://v2.csdatw.xyz/admin/evaluations/legacy/2',['operation'=>'salary_snapshot'])->assertForbidden();
        $this->post('https://v2.csdatw.xyz/admin/evaluations-preview/health')->assertStatus(405);
    }

    public function test_detail_and_summary_use_mocked_readonly_values_without_v2_tables():void
    {
        $this->actor();
        $subject=['type'=>'legacy','id'=>2,'label'=>'Preview #2'];
        $subjects=Mockery::mock(EvaluationSubjects::class);
        $subjects->shouldReceive('get')->with('legacy',2)->andReturn($subject);
        $this->app->instance(EvaluationSubjects::class,$subjects);
        $value=['score_delivery_time'=>50,'score_compliance'=>50,'score_professionalism'=>50,
            'score_interaction_avg'=>40,'score_initiative'=>0,'score_winrate_bonus'=>0,
            'score_extra_adjustment'=>0,'total_score'=>60];
        $current=['view'=>$value,'rating'=>$value,'level'=>2,
            'salary'=>['short'=>100,'long'=>400,'video'=>0,'late'=>0,'absent'=>0,'total'=>500],
            'counts'=>['salary_short'=>1,'salary_long'=>1],
            'next_rating'=>['rating_level'=>2,'is_terminate'=>false],
            'performance'=>['month'=>['wins'=>1,'losses'=>0,'rate'=>100],
                'bonus'=>['wins'=>null,'losses'=>null,'rate'=>null],
                'recent10'=>['wins'=>null,'losses'=>null,'rate'=>null]]];
        $report=['subject'=>$subject,'month'=>'2026-05','current'=>$current,
            'previous'=>['rating'=>['total_score'=>58]],'level'=>2,
            'source_sha256'=>str_repeat('a',64)];
        $service=Mockery::mock(EvaluationPreviewReport::class);
        $service->shouldReceive('load')->with($subject,'2026-05')->andReturn($report);
        $this->app->instance(EvaluationPreviewReport::class,$service);
        $this->get('https://v2.csdatw.xyz/admin/evaluations-preview/legacy/2?month=2026-05')
            ->assertOk()->assertSee('來源薪酬試算')->assertDontSee('name="operation"',false);
        $this->getJson('https://v2.csdatw.xyz/admin/evaluations-preview/legacy/2?month=2026-05&format=summary')
            ->assertOk()->assertJsonPath('score',60)->assertJsonPath('salary.total',500)->assertJsonPath('level_label','A')->assertJsonPath('next_level_label','A')
            ->assertJsonMissingPath('source_sha256');
        $this->post('https://v2.csdatw.xyz/admin/evaluations-preview/legacy/2',[])->assertStatus(405);
    }

    public function test_legacy_roster_error_is_visible_not_a_false_empty_or_raw_503():void
    {
        $this->actor();
        $subjects=Mockery::mock(EvaluationSubjects::class);
        $subjects->shouldReceive('all')->once()->andThrow(new \RuntimeException('LEGACY_READER_UNAVAILABLE'));
        $this->app->instance(EvaluationSubjects::class,$subjects);
        $this->get('https://v2.csdatw.xyz/admin/evaluations-preview?month=2026-05')
            ->assertOk()
            ->assertSee('唯讀資料暫時無法取得')
            ->assertSee('PREVIEW_DATA_UNAVAILABLE')
            ->assertSee('查看受保護的唯讀診斷結果')
            ->assertDontSee('無符合資格的舊系統分析師');
    }

    public function test_health_returns_bounded_reason_without_sql_or_sensitive_values():void
    {
        $this->actor();
        $reader=Mockery::mock(LegacyReader::class);
        $reader->shouldReceive('query')->once()
            ->with(['operation'=>'db_permissions'])
            ->andThrow(new \RuntimeException('LEGACY_READER_UNAVAILABLE'));
        $this->app->instance(LegacyReader::class,$reader);
        $this->getJson('https://v2.csdatw.xyz/admin/evaluations-preview/health')
            ->assertOk()
            ->assertJsonPath('result','STOP')
            ->assertJsonPath('stage','bridge')
            ->assertJsonPath('reason','LEGACY_READER_UNAVAILABLE')
            ->assertJsonPath('writes',0)
            ->assertJsonPath('checks_passed',[])
            ->assertJsonMissingPath('query');
        $this->get('http://v2.csdatw.xyz/admin/evaluations-preview/health')
            ->assertForbidden();
    }


}
