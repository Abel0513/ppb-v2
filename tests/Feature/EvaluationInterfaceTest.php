<?php
namespace Tests\Feature;
use App\Models\PpbAccount;
use App\Services\Ppb\Evaluation\{EvaluationSubjects,EvaluationService,EvaluationWriter,EvaluationReport,LegacyScheduleCalculator,LegacyRatingCalculator,LegacyMonthSelector};
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Mockery;
use Tests\TestCase;

class EvaluationInterfaceTest extends TestCase
{
    private function actor(bool $manage=true,bool $view=true): void
    {
        config(['app.url'=>'https://v2.csdatw.xyz']);
        $actor=Mockery::mock(PpbAccount::class)->makePartial();$actor->forceFill(['id'=>71,'name'=>'UI fixture','username'=>'fixture','is_active'=>true]);
        $actor->shouldReceive('hasPpbPermission')->andReturnUsing(fn($p)=>$p==='evaluations.manage'?$manage:$view);
        $roles=Mockery::mock(BelongsToMany::class);$roles->shouldReceive('where')->andReturnSelf();$roles->shouldReceive('exists')->andReturn(false);$actor->shouldReceive('roles')->andReturn($roles);
        $this->actingAs($actor,'ppb');
    }
    private function report(): array
    {
        $reporter=new EvaluationReport(new LegacyScheduleCalculator,new LegacyRatingCalculator);$manual=$reporter->defaults('2026-05');
        $selected=(new LegacyMonthSelector)->select([],'legacy:2','2026-05',[]);$result=$reporter->calculate($selected,$manual,[],[],0,2);
        $result['performance']=(new \App\Services\Ppb\Evaluation\EvaluationPerformance)->summarize($selected,[]);
        $result+=['level'=>2,'selected'=>$selected,'next_rating'=>['rating_level'=>2,'is_terminate'=>false]];
        return ['subject'=>['type'=>'legacy','id'=>2,'label'=>'<script>not executable</script>'],'month'=>'2026-05','manual'=>$manual,'current'=>$result,'previous'=>$result,'revision'=>0,'source_sha256'=>str_repeat('a',64),'earlier_month'=>'2026-03','history'=>['2026-03'=>$result,'2026-04'=>$result,'2026-05'=>$result],'saved_next_rating'=>null,'native_attendance_video_supported'=>true,'inputs'=>['games'=>[91=>['id'=>91,'recommended_time'=>'2026-05-12 12:00:00']],'attendance'=>[],'videos'=>[['id'=>8,'scheduled_at'=>'2026-05-12 04:00:00','guest_1'=>'legacy:2','guest_2'=>null,'guest_3'=>null,'cancelled'=>false]]]];
    }
    public function test_month_defaults_to_current_taipei_month(): void
    {
        $this->actor();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-31 16:00:00', 'UTC'));
        $subjects = Mockery::mock(EvaluationSubjects::class);
        $subjects->shouldReceive('all')->once()->andReturn([]);
        $this->app->instance(EvaluationSubjects::class, $subjects);

        $this->get('https://v2.csdatw.xyz/admin/evaluations')
            ->assertOk()->assertViewHas('month', '2026-11');
    }

    public function test_guest_cannot_read_evaluation_and_http_or_foreign_host_is_denied(): void
    {
        config(['app.url'=>'https://v2.csdatw.xyz']);
        $this->get('https://v2.csdatw.xyz/admin/evaluations')->assertRedirect();
        $this->actor();
        $this->get('http://v2.csdatw.xyz/admin/evaluations')->assertForbidden();
        $this->get('https://foreign.example/admin/evaluations')->assertForbidden();
    }
    public function test_view_only_user_cannot_post_and_denial_never_calls_writer(): void
    {
        $this->actor(false,true);$writer=Mockery::mock(EvaluationWriter::class);$writer->shouldNotReceive('save');$this->app->instance(EvaluationWriter::class,$writer);
        $this->post('https://v2.csdatw.xyz/admin/evaluations/legacy/2',[])->assertForbidden();
    }
    public function test_actual_blade_renders_escaped_identity_and_revision_bound_forms_without_database(): void
    {
        $this->actor();$subjects=Mockery::mock(EvaluationSubjects::class);$subjects->shouldReceive('get')->with('legacy',2)->andReturn(['type'=>'legacy','id'=>2]);$subjects->shouldReceive('all')->andReturn([['type'=>'legacy','id'=>2,'label'=>'Fixture']]);$this->app->instance(EvaluationSubjects::class,$subjects);
        $service=Mockery::mock(EvaluationService::class);$service->shouldReceive('load')->once()->andReturn($this->report());$this->app->instance(EvaluationService::class,$service);
        $response=$this->get('https://v2.csdatw.xyz/admin/evaluations/legacy/2?month=2026-05');
        $response->assertOk()->assertSee('&lt;script&gt;not executable&lt;/script&gt;',false)->assertDontSee('<script>not executable</script>',false)->assertSee('name="request_id"',false)->assertSee('name="source_sha256"',false)->assertSee('儲存人工評分')->assertSee('確認並保存下月評級')->assertSee('保存出勤更正')->assertSee('保存影片參與')->assertSee('2026-05-12T12:00',false);
    }
    public function test_missing_monthly_grade_renders_no_data_in_detail_and_summary(): void
    {
        $this->actor(false, true);
        $subject = ['type' => 'legacy', 'id' => 2];
        $subjects = Mockery::mock(EvaluationSubjects::class);
        $subjects->shouldReceive('get')->with('legacy', 2)->andReturn($subject);
        $subjects->shouldReceive('all')->andReturn([]);
        $this->app->instance(EvaluationSubjects::class, $subjects);

        $report = $this->report();
        $report['current']['level'] = null;
        $service = Mockery::mock(EvaluationService::class);
        $service->shouldReceive('load')->with($subject, '2026-05')->twice()->andReturn($report);
        $this->app->instance(EvaluationService::class, $service);

        $url = 'https://v2.csdatw.xyz/admin/evaluations/legacy/2?month=2026-05';
        $this->get($url)->assertOk()->assertSee('本月等級：無資料');
        $this->getJson($url.'&format=summary')->assertOk()->assertJsonPath('level_label', '無資料');
    }

    public function test_invalid_month_is_rejected_before_source_or_database_queries(): void
    {
        $this->actor();$subjects=Mockery::mock(EvaluationSubjects::class);$subjects->shouldNotReceive('all');$this->app->instance(EvaluationSubjects::class,$subjects);
        $this->getJson('https://v2.csdatw.xyz/admin/evaluations?month=2026-13')->assertUnprocessable();
    }
    public function test_incomplete_manual_form_is_rejected_instead_of_clearing_other_weeks(): void
    {
        $this->actor();$subjects=Mockery::mock(EvaluationSubjects::class);$subjects->shouldReceive('get')->andReturn(['type'=>'legacy','id'=>2]);$this->app->instance(EvaluationSubjects::class,$subjects);
        $writer=Mockery::mock(EvaluationWriter::class);$writer->shouldNotReceive('save');$this->app->instance(EvaluationWriter::class,$writer);
        $this->postJson('https://v2.csdatw.xyz/admin/evaluations/legacy/2',['month'=>'2026-05','request_id'=>'99b1e56d-7db5-4524-b567-8f028610079a','revision'=>0,'source_sha256'=>str_repeat('a',64),'operation'=>'manual','score_interaction_w1'=>50])->assertUnprocessable()->assertJsonValidationErrors('score_interaction_w2');
    }
    public function test_video_input_uses_explicit_subject_keys_and_local_date_validation(): void
    {
        $this->actor();$subjects=Mockery::mock(EvaluationSubjects::class);$subjects->shouldReceive('get')->andReturn(['type'=>'legacy','id'=>2]);$subjects->shouldReceive('all')->andReturn([['type'=>'legacy','id'=>2]]);$this->app->instance(EvaluationSubjects::class,$subjects);
        $writer=Mockery::mock(EvaluationWriter::class);$writer->shouldReceive('save')->once()->withArgs(fn($subject,$month,$operation,$payload,$id,$revision,$hash,$actor)=>$month==='2026-05'&&$operation==='input'&&$payload['guest_1']==='legacy:2'&&$payload['scheduled_at']==='2026-05-12T12:00'&&$actor===71)->andReturn(['replayed'=>false,'request_id'=>'99b1e56d-7db5-4524-b567-8f028610079a','revision'=>1]);$this->app->instance(EvaluationWriter::class,$writer);
        $input=['month'=>'2026-05','request_id'=>'99b1e56d-7db5-4524-b567-8f028610079a','revision'=>0,'source_sha256'=>str_repeat('a',64),'operation'=>'input','input_kind'=>'video','scheduled_at'=>'2026-05-12T12:00','guest_1'=>'legacy:2','guest_2'=>'','guest_3'=>'','cancelled'=>'0'];
        $this->post('https://v2.csdatw.xyz/admin/evaluations/legacy/2',$input)->assertRedirect();
        $input['guest_1']='legacy:999';$this->postJson('https://v2.csdatw.xyz/admin/evaluations/legacy/2',$input)->assertUnprocessable()->assertJsonValidationErrors('guest_1');
    }

    public function test_summary_returns_only_aggregates_and_index_paginates_bounded_cohort(): void
    {
        $this->actor();$roster=[];foreach(range(1,11) as $id)$roster[]=['type'=>'legacy','id'=>$id,'label'=>'AnalystFixture'.$id,'mapped'=>false];
        $subjects=Mockery::mock(EvaluationSubjects::class);$subjects->shouldReceive('all')->andReturn($roster);$subjects->shouldReceive('get')->andReturn(['type'=>'legacy','id'=>2]);$this->app->instance(EvaluationSubjects::class,$subjects);
        $this->get('https://v2.csdatw.xyz/admin/evaluations?month=2026-05&page=2')->assertOk()->assertSee('AnalystFixture6')->assertSee('AnalystFixture10')->assertDontSee('AnalystFixture11')->assertSee('data-evaluation-summary',false);
        $service=Mockery::mock(EvaluationService::class);$service->shouldReceive('load')->once()->andReturn($this->report());$this->app->instance(EvaluationService::class,$service);
        $json=$this->getJson('https://v2.csdatw.xyz/admin/evaluations/legacy/2?month=2026-05&format=summary')->assertOk()->assertJsonPath('score',60)->assertJsonPath('earlier_score',60)->assertJsonPath('level_label','A')->assertJsonPath('next_level_label','A')->assertJsonMissingPath('subject')->assertJsonMissingPath('current.selected.records');
        self::assertStringContainsString('no-store',$json->headers->get('Cache-Control'));
    }

    public function test_salary_snapshot_ignores_client_amounts_and_uses_receipt_bound_writer(): void
    {
        $this->actor();
        $subjects=Mockery::mock(EvaluationSubjects::class);$subjects->shouldReceive('get')->with('legacy',2)->andReturn(['type'=>'legacy','id'=>2]);
        $this->app->instance(EvaluationSubjects::class,$subjects);
        $writer=Mockery::mock(EvaluationWriter::class);
        $writer->shouldReceive('save')->once()->withArgs(fn($subject,$month,$operation,$payload,$id,$revision,$hash,$actor)=>
            $operation==='salary_snapshot'&&$payload===[]&&$month==='2026-05'&&$revision===3&&$actor===71)
            ->andReturn(['replayed'=>false,'request_id'=>'99b1e56d-7db5-4524-b567-8f028610079a','revision'=>4]);
        $this->app->instance(EvaluationWriter::class,$writer);
        $this->post('https://v2.csdatw.xyz/admin/evaluations/legacy/2',[
            'month'=>'2026-05','operation'=>'salary_snapshot','request_id'=>'99b1e56d-7db5-4524-b567-8f028610079a',
            'revision'=>3,'source_sha256'=>str_repeat('a',64),'total'=>999999,'salary'=>['total'=>999999]])->assertRedirect();
    }

}
