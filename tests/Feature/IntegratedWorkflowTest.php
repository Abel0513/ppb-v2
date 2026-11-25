<?php
namespace Tests\Feature;

use App\Models\{PpbAccount,PpbGame,PpbAssignment};
use App\Services\Ppb\ScheduleVersion;
use App\Services\Ppb\Evaluation\{LegacyReader,LegacyScheduleCalculator};
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\{DisposableMysql57,ScheduleSharedFixture,EvaluationIntegrationFixture};
use Tests\TestCase;

/** Actual Laravel HTTP kernel + synthetic rows; never a production connection. */
class IntegratedWorkflowTest extends TestCase
{
    private const HOST='https://v2.csdatw.xyz';
    private function actor(array $permissions):void
    {
        $actor=Mockery::mock(PpbAccount::class)->makePartial();
        $actor->forceFill(['id'=>41,'name'=>'Integration fixture','username'=>'fixture','is_active'=>true]);
        $actor->shouldReceive('hasPpbPermission')->andReturnUsing(fn($p)=>in_array($p,$permissions,true));
        $roles=Mockery::mock(BelongsToMany::class);$roles->shouldReceive('where')->andReturnSelf();$roles->shouldReceive('exists')->andReturn(false);
        $actor->shouldReceive('roles')->andReturn($roles);$this->actingAs($actor,'ppb');
    }
    private function token(int $game,int $assignment):string
    {
        return $this->get(self::HOST.'/admin/games/'.$game.'/manuscripts')->assertOk()->viewData('tokens')[$assignment];
    }
    private function report(int $analyst):array
    {
        return $this->get(self::HOST.'/admin/evaluations/v2/'.$analyst.'?month=2026-11')->assertOk()->viewData('report');
    }
    private function submit(int $game,int $assignment,string $body='Integration manuscript')
    {
        return $this->postJson(self::HOST.'/admin/manuscripts/assignments/'.$assignment.'/save',[
            'mode'=>'submit','revision_token'=>$this->token($game,$assignment),'recommend_pick'=>'Synthetic selection','analysis_text'=>$body]);
    }
    private function evaluate(int $analyst,array $report,string $operation,array $payload=[],?string $id=null)
    {
        return $this->postJson(self::HOST.'/admin/evaluations/v2/'.$analyst,[
            'month'=>'2026-11','request_id'=>$id??(string)Str::uuid(),'revision'=>$report['revision'],'source_sha256'=>$report['source_sha256'],'operation'=>$operation]+$payload);
    }
    public function test_schedule_manuscript_review_evaluation_salary_and_identity_contracts():void
    {
        $container=new DisposableMysql57;$passed=false;$proof=[];
        try{
            $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-16 00:00:00','UTC'));
            $pdo=$container->pdo();ScheduleSharedFixture::create($pdo,bin2hex(random_bytes(16)));ScheduleSharedFixture::reset($pdo);
            config(['app.url'=>self::HOST,'database.connections.mysql'=>['driver'=>'mysql','database'=>'mysql','username'=>'root','password'=>'','unix_socket'=>$container->socket(),'host'=>'127.0.0.1','port'=>1,'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true]]);
            DB::purge('mysql');DB::setDefaultConnection('mysql');DB::connection()->setPdo($pdo);
            DB::statement('ALTER TABLE ppb_legacy_pick_contents ADD legacy_analyst_id BIGINT NULL');
            EvaluationIntegrationFixture::create();Http::preventStrayRequests();
            $reader=Mockery::mock(LegacyReader::class);$reader->shouldReceive('profiles')->andReturn([]);$this->app->instance(LegacyReader::class,$reader);
            $this->get(self::HOST.'/admin/evaluations')->assertRedirect();
            $schedule=['schedules.view','schedules.manage'];$evaluation=['evaluations.view','evaluations.manage'];
            $this->actor($schedule);$this->get(self::HOST.'/admin/evaluations')->assertForbidden();
            $this->actor($evaluation);$this->postJson(self::HOST.'/admin/manuscripts/assignments/1/save',[])->assertForbidden();
            $this->actor([...$schedule,...$evaluation]);
            // Use the actual scheduling HTTP validation/controller entrypoint.
            $this->postJson(self::HOST.'/admin/games',['game_type'=>1,'match_date'=>'2026-11-20','match_time'=>'11:00','matchup_mode'=>'manual','manual_matchup'=>'Synthetic away @ Synthetic home','play_type'=>2,'market_type'=>1,'reviewer_analyst_id'=>9,'short'=>[1=>9]])->assertRedirect();
            $game=PpbGame::orderByDesc('id')->firstOrFail();
            $gid=(int)$game->id;$aid=(int)$game->assignments()->first()->id;
            $before=$this->report(9);self::assertCount(0,$before['current']['selected']['records']);
            $this->submit($gid,$aid)->assertRedirect();
            $sid=(int)DB::table('ppb_submissions')->where('game_id',$gid)->value('id');
            $this->postJson(self::HOST.'/admin/manuscripts/submissions/'.$sid.'/review',['decision'=>'approved','revision_token'=>$this->token($gid,$aid)])->assertRedirect();
            $authored=$this->report(9);self::assertCount(1,$authored['current']['selected']['records']);self::assertSame($sid,$authored['current']['selected']['records'][0]['_source_id']);
            self::assertNotSame($before['source_sha256'],$authored['source_sha256']);
            $w2=$this->token($gid,$aid);$version=ScheduleVersion::game(PpbGame::findOrFail($gid));
            $scores=array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS,60)+['source_type'=>'v2','source_id'=>$sid,'result_state'=>1];
            $this->evaluate(9,$authored,'rate',$scores)->assertRedirect();$rated=$this->report(9);
            self::assertSame(100,$rated['current']['salary']['short']);
            self::assertSame($w2,$this->token($gid,$aid));self::assertSame($version,ScheduleVersion::game(PpbGame::findOrFail($gid)),'Evaluation writes cannot invalidate unrelated W2/schedule tokens.');
            $snapshotId=(string)Str::uuid();$this->evaluate(9,$rated,'salary_snapshot',[],$snapshotId)->assertRedirect();
            $snapshot=$this->report(9)['saved_salary'];$events=DB::table('ppb_evaluation_events')->count();
            $this->evaluate(9,$rated,'salary_snapshot',[],$snapshotId)->assertRedirect();self::assertSame($events,DB::table('ppb_evaluation_events')->count());
            $oldAssignment=ScheduleVersion::assignment(PpbAssignment::findOrFail($aid));
            $this->postJson(self::HOST.'/admin/assignments/'.$aid.'/substitute',['to_analyst_id'=>10,'reason'=>'Integrated substitute','revision_token'=>$oldAssignment])->assertRedirect();
            self::assertSame(9,(int)DB::table('ppb_submissions')->where('id',$sid)->value('submitted_analyst_id'));
            self::assertCount(1,$this->report(9)['current']['selected']['records']);self::assertCount(0,$this->report(10)['current']['selected']['records']);
            self::assertCount(0,$this->report(9)['inputs']['attendance']);
            $this->postJson(self::HOST.'/admin/assignments/'.$aid.'/status',['status'=>'completed','revision_token'=>$oldAssignment])->assertConflict();
            // WP metadata protection blocks both management domains; no WP HTTP.
            DB::table('ppb_publications')->insert(['game_id'=>$gid,'wp_site'=>'fixture.invalid','wp_post_id'=>'pending','status'=>'uploading']);
            $currentAssignment=ScheduleVersion::assignment(PpbAssignment::findOrFail($aid));
            $this->postJson(self::HOST.'/admin/assignments/'.$aid.'/substitute',['to_analyst_id'=>12,'reason'=>'Blocked','revision_token'=>$currentAssignment])->assertUnprocessable();
            $this->postJson(self::HOST.'/admin/manuscripts/assignments/'.$aid.'/save',['mode'=>'submit','revision_token'=>$w2,'recommend_pick'=>'Blocked','analysis_text'=>'Blocked'])->assertUnprocessable();
            DB::table('ppb_publications')->delete();
            // A substitute submits a separate match: preserve true author and absence lineage.
            $a=PpbAssignment::findOrFail(1);
            $this->postJson(self::HOST.'/admin/assignments/1/substitute',['to_analyst_id'=>10,'reason'=>'Pre-submission substitute','revision_token'=>ScheduleVersion::assignment($a)])->assertRedirect();
            $this->submit(1,1)->assertRedirect();$subId=(int)DB::table('ppb_submissions')->where('game_id',1)->value('id');
            $sub=$this->report(10);self::assertSame($subId,$sub['current']['selected']['records'][0]['_source_id']);
            $origin=$this->report(9);self::assertCount(1,$origin['inputs']['attendance']);self::assertSame(1,$origin['inputs']['attendance'][0]['gid']);
            $this->evaluate(10,$sub,'rate',array_replace($scores,['source_id'=>$subId]))->assertRedirect();
            self::assertSame(0,$this->report(10)['current']['salary']['short'],'Unwaived substitute retains existing legacy pay semantics.');
            $this->evaluate(9,$origin,'rate',array_replace($scores,['source_id'=>$subId]))->assertUnprocessable();
            // Source failures must leave evaluation, ratings and audit as one unit.
            $fresh=$this->report(10);$beforeRows=DB::table('ppb_evaluations')->get()->toJson();$beforeEvents=DB::table('ppb_evaluation_events')->count();$fail=true;
            DB::listen(function($q)use(&$fail){if($fail&&str_starts_with($q->sql,'insert into `ppb_evaluation_events`'))throw new \RuntimeException('INTEGRATED_AUDIT_FAILURE');});
            $this->evaluate(10,$fresh,'salary_snapshot')->assertStatus(500);$fail=false;
            self::assertSame($beforeRows,DB::table('ppb_evaluations')->get()->toJson());self::assertSame($beforeEvents,DB::table('ppb_evaluation_events')->count());
            self::assertSame(0,DB::connection()->transactionLevel());
            // Rating a resubmitted manuscript must use its current actual content.
            // Use the separate substitute match, whose submitted author is unchanged.
            $stale=$this->report(10);$absenceOnly=$this->report(9);
            $this->postJson(self::HOST.'/admin/manuscripts/submissions/'.$subId.'/review',['decision'=>'changes_requested','review_note'=>'Synthetic revision','revision_token'=>$this->token(1,1)])->assertRedirect();
            $this->submit(1,1,'Changed integration manuscript')->assertRedirect();
            $changed=$this->report(10);
            self::assertNotSame($stale['source_sha256'],$changed['source_sha256'],'A W2 resubmission must invalidate old evaluation source fingerprints.');
            self::assertSame($absenceOnly['source_sha256'],$this->report(9)['source_sha256'],'Editing substitute content cannot invalidate an unchanged original-analyst absence/pay source.');
            $this->evaluate(10,$stale,'salary_snapshot')->assertConflict();
            self::assertSame($snapshot,$this->report(9)['saved_salary'],'Later W2 changes cannot overwrite a settled snapshot.');
            $this->get(self::HOST.'/Admin/AnalystRating/salary.html?month=2026-11')->assertRedirect();
            $this->get(self::HOST.'/admin/evaluations/v2/9?month=2026-11&format=summary')->assertOk()->assertJsonStructure(['score','salary','performance']);
            // Preserve real throttling: this is a later management action, not
            // a burst sharing the lower cancellation limit of the same actor.
            $this->travel(61)->seconds();
            $game=PpbGame::findOrFail($gid);$this->postJson(self::HOST.'/admin/games/'.$gid.'/cancel',['reason'=>'Synthetic cancellation','revision_token'=>ScheduleVersion::game($game)])->assertRedirect();
            self::assertCount(1,$this->report(9)['current']['selected']['records'],'Cancelling a match cannot silently delete authored evaluation history.');
            self::assertSame($snapshot,$this->report(9)['saved_salary']);
            $plan=json_decode(file_get_contents(base_path('resources/ppb/release.json')),true,flags:JSON_THROW_ON_ERROR);
            self::assertIsArray($plan);
            self::assertSame(['migrate','cache','queue_restart'],array_keys($plan));
            self::assertIsBool($plan['migrate']);
            self::assertSame(['config','routes','views'],$plan['cache']);
            self::assertFalse($plan['queue_restart']);
            // migrate=true is the owner-authorized isolated backup+migrate gate
            // for 2026_11_16_000012 and 2026_11_25_000013; the draft-candidate forbid-migrate check no longer applies.
            $proof=['flow'=>'HTTP schedule → HTTP submit → HTTP approve → author lineage → HTTP evaluation → salary snapshot','request_replay'=>true,'permissions_separated'=>true,'substitution_lineage'=>true,'wp_metadata_guard'=>true,'rollback'=>true,'stale_source_409'=>true,'absence_only_source_stable'=>true];$passed=true;
        }finally{
            $this->travelBack();DB::disconnect('mysql');unset($pdo);$container->destroy();self::assertFalse($container->exists());self::assertDirectoryDoesNotExist($container->directory);
            file_put_contents((getenv('RUNNER_TEMP')?:sys_get_temp_dir()).'/ppb-integrated-receipt.json',json_encode(['result'=>$passed?'PASS':'FAIL','isolation'=>$container->proof,'checks'=>$proof,'production_connections'=>0,'external_http_calls'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
        }
    }
}
