<?php
namespace Tests\Feature;

use App\Services\Ppb\Evaluation\{EvaluationService,EvaluationWriter,LegacyInteractionCalculator,EvaluationReport,LegacyScheduleCalculator,LegacyRatingCalculator,LegacyMonthSelector};
use Illuminate\Support\Facades\DB;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real isolated 5.7 transactions; session TEMPORARY tables only, no test DB. */
class EvaluationMysql57Test extends TestCase
{
    private function command(array $argv,int $timeout=120): string
    {
        $process=new Process($argv);$process->setTimeout($timeout);$process->run();
        if(!$process->isSuccessful())throw new \RuntimeException('ISOLATED_MYSQL57_COMMAND_FAILED');
        return $process->getOutput();
    }
    public function test_real_mysql57_receipts_replay_conflicts_and_failure_rollback_without_persistent_tables(): void
    {
        $name='ppb-evaluation-'.bin2hex(random_bytes(6));$socket=sys_get_temp_dir().'/'.$name;mkdir($socket,0777);chmod($socket,0777);
        $image='mysql:5.7.44@sha256:4bc6bc963e6d8443453676cae56536f4b8156d78bae03c0145cbe47c2aad73bb';
        try{
            $this->command(['docker','run','-d','--name',$name,'--network=none','--tmpfs','/var/lib/mysql:rw,nosuid,size=768m','--mount','type=bind,source='.$socket.',target=/run/mysqld','-e','MYSQL_ALLOW_EMPTY_PASSWORD=yes',$image,'--skip-networking']);
            $ready=false;
            for($i=0;$i<60;$i++){
                try{if(trim($this->command(['docker','exec',$name,'cat','/proc/1/comm'],5))==='mysqld'&&file_exists($socket.'/mysqld.sock')){$ready=true;break;}}catch(\Throwable $ignored){}
                usleep(500000);
            }
            self::assertTrue($ready,'Final isolated MySQL server required; no skipped regression.');
            config(['database.connections.mysql'=>['driver'=>'mysql','host'=>'127.0.0.1','port'=>1,'database'=>'mysql','username'=>'root','password'=>'','unix_socket'=>$socket.'/mysqld.sock','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,'engine'=>null]]);DB::purge('mysql');DB::setDefaultConnection('mysql');
            self::assertSame('5.7.44',DB::selectOne('SELECT VERSION() version')->version);
            $schemas=DB::select('SHOW DATABASES');self::assertCount(4,$schemas);
            self::assertSame(0,(int)DB::selectOne("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA='mysql' AND TABLE_NAME LIKE 'ppb_evaluation%'")->n);
            $fields=implode(',',array_map(fn($f)=>$f.' SMALLINT DEFAULT 0',LegacyScheduleCalculator::RATING_FIELDS));
            DB::statement('CREATE TEMPORARY TABLE ppb_evaluations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,subject_type VARCHAR(12),subject_id BIGINT UNSIGNED,month CHAR(7),'.implode(',',array_map(fn($w)=>'score_interaction_w'.$w.' DECIMAL(8,2) NULL',range(1,5))).',score_interaction_avg DECIMAL(8,2) NULL,score_interaction_feedback SMALLINT DEFAULT 0,score_initiative INT DEFAULT 0,score_extra_adjustment INT DEFAULT 0,score_winrate_bonus INT DEFAULT 0,score_delivery_time DECIMAL(8,2) NULL,score_compliance DECIMAL(8,2) NULL,score_professionalism DECIMAL(8,2) NULL,next_rating_level TINYINT NULL,revision BIGINT UNSIGNED DEFAULT 1,source_sha256 CHAR(64),actor_id BIGINT UNSIGNED NULL,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL,UNIQUE(subject_type,subject_id,month)) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_evaluation_ratings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_type VARCHAR(12),source_id BIGINT UNSIGNED,subject_type VARCHAR(12),subject_id BIGINT UNSIGNED,month CHAR(7),'.$fields.',result_state TINYINT NULL,revision BIGINT UNSIGNED,source_sha256 CHAR(64),actor_id BIGINT UNSIGNED,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL,UNIQUE(source_type,source_id)) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_evaluation_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,evaluation_id BIGINT UNSIGNED,request_id CHAR(36) UNIQUE,operation VARCHAR(32),payload_sha256 CHAR(64),actor_id BIGINT UNSIGNED,before_json JSON,after_json JSON,created_at TIMESTAMP NULL) ENGINE=InnoDB');
            $reporter=new EvaluationReport(new LegacyScheduleCalculator,new LegacyRatingCalculator);$manual=$reporter->defaults('2026-05');$selected=(new LegacyMonthSelector)->select([],'legacy:2','2026-05',[]);
            $current=$reporter->calculate($selected,$manual,[],[],0,2);$current['next_rating']=['rating_level'=>3];
            $current['selected']['records']=[['_source_type'=>'legacy','_source_id'=>77]];
            $report=['source_sha256'=>str_repeat('a',64),'manual'=>$manual,'current'=>$current];
            $service=Mockery::mock(EvaluationService::class);$service->shouldReceive('load')->andReturnUsing(fn()=>$report);
            $writer=new EvaluationWriter($service,new LegacyInteractionCalculator);$subject=['type'=>'legacy','id'=>2];
            $payload=array_intersect_key($manual,array_flip(['score_interaction_w1','score_interaction_w2','score_interaction_w3','score_interaction_w4','score_interaction_w5','score_interaction_feedback','score_initiative','score_extra_adjustment']));$payload['score_interaction_w1']=0;
            $id='683f084d-b85d-437b-9d9c-b280ce473e81';$hash=str_repeat('a',64);
            $first=$writer->save($subject,'2026-05','manual',$payload,$id,0,$hash,71);self::assertFalse($first['replayed']);self::assertSame(1,$first['revision']);
            self::assertSame('32.00',DB::table('ppb_evaluations')->value('score_interaction_avg'));
            $writes=0;DB::listen(function($query)use(&$writes){if(preg_match('/^(insert|update|delete) /i',$query->sql))$writes++;});
            $replay=$writer->save($subject,'2026-05','manual',$payload,$id,0,$hash,71);self::assertTrue($replay['replayed']);self::assertSame(0,$writes,'Replaying a receipt may only read.');self::assertSame(1,DB::table('ppb_evaluation_events')->count());
            try{$writer->save($subject,'2026-05','manual',$payload+['extra'=>1],$id,0,$hash,71);self::fail('Conflicting request must fail.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(409,$e->getStatusCode());}
            try{$writer->save($subject,'2026-05','manual',$payload,'683f084d-b85d-437b-9d9c-b280ce473e82',0,$hash,71);self::fail('Stale revision must fail.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(409,$e->getStatusCode());}
            self::assertSame(0,DB::transactionLevel());self::assertSame(1,DB::table('ppb_evaluation_events')->count());
            $rate=array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS,50)+['source_type'=>'legacy','source_id'=>77];
            $rated=$writer->save($subject,'2026-05','rate',$rate,'683f084d-b85d-437b-9d9c-b280ce473e83',1,$hash,71);self::assertSame(2,$rated['revision']);self::assertSame(1,DB::table('ppb_evaluation_ratings')->count());
            $next=$writer->save($subject,'2026-05','rebuild',[],'683f084d-b85d-437b-9d9c-b280ce473e84',2,$hash,71);self::assertSame(3,$next['revision']);self::assertSame(3,(int)DB::table('ppb_evaluations')->value('next_rating_level'));
            $throw=true;DB::listen(function($query)use(&$throw){if($throw&&str_starts_with($query->sql,'insert into `ppb_evaluation_events`'))throw new \RuntimeException('INJECTED_EVENT_WRITE_FAILURE');});
            try{$writer->save($subject,'2026-05','manual',$payload,'683f084d-b85d-437b-9d9c-b280ce473e85',3,$hash,71);self::fail('Injected event failure must roll back.');}catch(\RuntimeException $e){self::assertSame('INJECTED_EVENT_WRITE_FAILURE',$e->getMessage());}finally{$throw=false;}
            self::assertSame(3,(int)DB::table('ppb_evaluations')->value('revision'));self::assertSame(3,DB::table('ppb_evaluation_events')->count());self::assertSame(0,DB::transactionLevel());
            self::assertSame(0,(int)DB::selectOne("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA='mysql' AND TABLE_NAME LIKE 'ppb_evaluation%'")->n);
            DB::disconnect('mysql');
        }finally{
            DB::disconnect('mysql');
            try{try{$this->command(['docker','exec',$name,'mysqladmin','--no-defaults','--user=root','--skip-password','shutdown'],15);}catch(\Throwable $ignored){} $this->command(['docker','rm','-f',$name],30);}finally{if(is_dir($socket))@rmdir($socket);}
        }
    }
}
