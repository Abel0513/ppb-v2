<?php
namespace Tests\Feature;

use App\Services\Ppb\Evaluation\{EvaluationService,EvaluationWriter,LegacyInteractionCalculator,EvaluationReport,LegacyScheduleCalculator,LegacyRatingCalculator,LegacyMonthSelector,LegacyReader,EvaluationSubjects};
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
            DB::statement('CREATE TEMPORARY TABLE ppb_evaluation_attendance (id BIGINT AUTO_INCREMENT PRIMARY KEY,subject_type VARCHAR(12),subject_id BIGINT,game_id BIGINT,attendance_status TINYINT NULL,waived_submission_id BIGINT NULL,revision BIGINT,actor_id BIGINT,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL,UNIQUE(subject_type,subject_id,game_id)) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_evaluation_videos (id BIGINT AUTO_INCREMENT PRIMARY KEY,scheduled_at DATETIME,guest_1 VARCHAR(32),guest_2 VARCHAR(32) NULL,guest_3 VARCHAR(32) NULL,cancelled TINYINT DEFAULT 0,revision BIGINT,actor_id BIGINT,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL) ENGINE=InnoDB');
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
            DB::statement('CREATE TEMPORARY TABLE ppb_legacy_pick_contents (source_pick_id BIGINT PRIMARY KEY,game_id BIGINT,legacy_analyst_id BIGINT,kind VARCHAR(16)) ENGINE=InnoDB');
            // Exercise the real service's UTC month selection and imported/draft
            // exclusions through the same live MySQL session, never a mock SQL DB.
            DB::statement('CREATE TEMPORARY TABLE ppb_games (id BIGINT PRIMARY KEY,recommended_at DATETIME,market_type INT,game_type INT DEFAULT 1,game_number INT DEFAULT 9) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_assignments (id BIGINT PRIMARY KEY,game_id BIGINT,kind VARCHAR(16),original_analyst_id BIGINT,current_analyst_id BIGINT) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_submissions (id BIGINT PRIMARY KEY,game_id BIGINT,assignment_id BIGINT,submitted_analyst_id BIGINT,kind VARCHAR(16),status VARCHAR(32) DEFAULT \'submitted\',submitted_at DATETIME NULL,legacy_source_pick_id BIGINT NULL) ENGINE=InnoDB');
            DB::table('ppb_games')->insert([['id'=>1,'recommended_at'=>'2026-04-30 16:00:00','market_type'=>1,'game_number'=>9],['id'=>2,'recommended_at'=>'2026-05-31 15:59:59','market_type'=>2,'game_number'=>10],['id'=>3,'recommended_at'=>'2026-05-31 16:00:00','market_type'=>1,'game_number'=>11]]);
            foreach([1,2,3] as $gid)DB::table('ppb_assignments')->insert(['id'=>$gid,'game_id'=>$gid,'kind'=>'pick','original_analyst_id'=>9,'current_analyst_id'=>9]);
            foreach([[1,1,null,'2026-05-01 00:00:00'],[2,2,null,'2026-05-01 00:00:00'],[3,3,null,'2026-05-01 00:00:00'],[4,1,333,'2026-05-01 00:00:00'],[5,1,null,null]] as [$pid,$gid,$legacy,$submitted])DB::table('ppb_submissions')->insert(['id'=>$pid,'game_id'=>$gid,'assignment_id'=>$gid,'submitted_analyst_id'=>9,'kind'=>'pick','submitted_at'=>$submitted,'legacy_source_pick_id'=>$legacy]);
            DB::table('ppb_submissions')->insert([['id'=>7,'game_id'=>1,'assignment_id'=>1,'submitted_analyst_id'=>9,'kind'=>'pick','status'=>'legacy_imported','submitted_at'=>'2026-05-01 00:00:00','legacy_source_pick_id'=>null],['id'=>8,'game_id'=>1,'assignment_id'=>1,'submitted_analyst_id'=>9,'kind'=>'pick','status'=>'draft','submitted_at'=>'2026-05-01 00:00:00','legacy_source_pick_id'=>null]]);
            $snapshot=['picks'=>[],'pick_columns'=>[],'schedules'=>[],'attendance'=>[],'evaluations'=>[],'ratings'=>[],'video_counts'=>[],'recent'=>[],'source_sha256'=>str_repeat('b',64),'profile'=>['id'=>4,'rating_level'=>1]];
            $reader=Mockery::mock(LegacyReader::class)->makePartial();$reader->shouldReceive('query')->andReturnUsing(fn($query)=>['transaction_isolation'=>'REPEATABLE-READ','snapshot'=>array_replace($snapshot,['format'=>'ppb-evaluation-source-v1','analyst_id'=>$query['analyst_id'],'month'=>$query['month'],'transactional_tables_verified'=>7])]);
            $subjects=Mockery::mock(EvaluationSubjects::class);$subjects->shouldReceive('mappings')->andReturn([4=>9]);
            $realService=new EvaluationService($reader,$subjects,new LegacyMonthSelector,$reporter,new LegacyRatingCalculator);
            $writes=0;$loaded=$realService->load(['type'=>'legacy','id'=>4,'native_id'=>9],'2026-05');
            self::assertSame(0,$writes,'Loading an evaluation must never create rows.');
            self::assertSame(2,$loaded['current']['counts']['records']);self::assertCount(3,$loaded['history']);self::assertSame(['G9','G10'],array_column($loaded['current']['selected']['records'],'_game_code'));
            self::assertSame(['2026-05-01 00:00:00','2026-05-31 23:59:59'],array_column($loaded['current']['selected']['records'],'recommended_time'));
            self::assertSame(['legacy:4','legacy:4'],array_column($loaded['current']['selected']['records'],'_author_key'));
            self::assertSame(1,$loaded['current']['next_rating']['rating_level'],'Missing monthly grade falls back to source profile, not salary default2.');
            // Real input writes use the same revision/UUID journal as rating writes.
            DB::table('ppb_submissions')->insert(['id'=>6,'game_id'=>1,'assignment_id'=>1,'submitted_analyst_id'=>10,'kind'=>'pick','submitted_at'=>'2026-05-01 00:00:00','legacy_source_pick_id'=>null]);
            $origin=['type'=>'legacy','id'=>4,'native_id'=>9];$realWriter=new EvaluationWriter($realService,new LegacyInteractionCalculator);
            $beforeInput=$realService->load($origin,'2026-05');self::assertSame(1,$beforeInput['current']['counts']['absences']);self::assertSame(-100,$beforeInput['current']['salary']['absent']);
            $waive=['input_kind'=>'attendance','game_id'=>1,'attendance_status'=>null];
            $waiveId='683f084d-b85d-437b-9d9c-b280ce473e91';$saved=$realWriter->save($origin,'2026-05','input',$waive,$waiveId,0,$beforeInput['source_sha256'],71);self::assertSame(1,$saved['revision']);
            $afterInput=$realService->load($origin,'2026-05');self::assertSame(0,$afterInput['current']['counts']['absences']);self::assertSame(0,$afterInput['current']['salary']['absent']);self::assertSame(6,(int)DB::table('ppb_evaluation_attendance')->value('waived_submission_id'));
            $writes=0;self::assertTrue($realWriter->save($origin,'2026-05','input',$waive,$waiveId,0,$beforeInput['source_sha256'],71)['replayed']);self::assertSame(0,$writes);
            $video=['input_kind'=>'video','scheduled_at'=>'2026-05-12T12:00','guest_1'=>'legacy:4','guest_2'=>'legacy:4','guest_3'=>'v2:10','cancelled'=>false];
            $realWriter->save($origin,'2026-05','input',$video,'683f084d-b85d-437b-9d9c-b280ce473e92',1,$afterInput['source_sha256'],71);
            $withVideo=$realService->load($origin,'2026-05');self::assertSame(1,$withVideo['inputs']['video_count']);self::assertSame(400,$withVideo['current']['salary']['video']);self::assertSame('2026-05-12 04:00:00',DB::table('ppb_evaluation_videos')->value('scheduled_at'));
            $video['video_id']=(int)DB::table('ppb_evaluation_videos')->value('id');$video['cancelled']=true;
            $realWriter->save($origin,'2026-05','input',$video,'683f084d-b85d-437b-9d9c-b280ce473e93',2,$withVideo['source_sha256'],71);
            self::assertSame(0,$realService->load($origin,'2026-05')['current']['salary']['video']);
            $substitute=['type'=>'v2','id'=>10,'native_id'=>10];$subReport=$realService->load($substitute,'2026-05');
            $subRating=array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS,50)+['source_type'=>'v2','source_id'=>6,'result_state'=>1];
            $realWriter->save($substitute,'2026-05','rate',$subRating,'683f084d-b85d-437b-9d9c-b280ce473e94',0,$subReport['source_sha256'],71);
            $subReport=$realService->load($substitute,'2026-05');self::assertSame(100,$subReport['current']['salary']['short']);self::assertSame(['outcome'=>1,'count'=>1],$subReport['current']['performance']['streak']);
            // The no-archive-map query branch must also be valid MySQL5.7 SQL.
            $emptySubjects=Mockery::mock(EvaluationSubjects::class);$emptySubjects->shouldReceive('mappings')->andReturn([]);
            $unmappedService=new EvaluationService($reader,$emptySubjects,new LegacyMonthSelector,$reporter,new LegacyRatingCalculator);
            self::assertSame(0,$unmappedService->load($substitute,'2026-05')['current']['salary']['short']);
            // Freeze a month calculation in the existing append-only audit event.
            $salaryId='683f084d-b85d-437b-9d9c-b280ce473e95';
            $savedPay=$realWriter->save($substitute,'2026-05','salary_snapshot',[],$salaryId,$subReport['revision'],$subReport['source_sha256'],71);
            self::assertSame(2,$savedPay['revision']);
            $salaryReport=$realService->load($substitute,'2026-05');
            $salaryReadback=$salaryReport['saved_salary'];
            self::assertSame($salaryReadback['snapshot']['source_sha256'],$salaryReport['source_sha256'],
                'Saving a snapshot must not immediately invalidate its own source token.');
            self::assertSame($salaryId,$salaryReadback['request_id']);
            // MySQL JSON canonicalizes object key order; retain strict value types.
            foreach($subReport['current']['salary'] as $field=>$amount)self::assertSame($amount,$salaryReadback['snapshot']['salary'][$field]);
            self::assertFalse($salaryReadback['snapshot']['payment_executed']);
            self::assertArrayNotHasKey('selected',$salaryReadback['snapshot']);
            $writes=0;
            self::assertTrue($realWriter->save($substitute,'2026-05','salary_snapshot',[],$salaryId,$subReport['revision'],$subReport['source_sha256'],71)['replayed']);
            self::assertSame(0,$writes,'Replaying salary snapshot only reads its receipt.');
            // A later calculation change cannot alter the previously frozen receipt.
            $subRating['result_state']=2;
            $changed=$realService->load($substitute,'2026-05');
            $realWriter->save($substitute,'2026-05','rate',$subRating,'683f084d-b85d-437b-9d9c-b280ce473e96',$changed['revision'],$changed['source_sha256'],71);
            $changed=$realService->load($substitute,'2026-05');
            self::assertSame(50,$changed['current']['salary']['short']);
            self::assertSame(100,$changed['saved_salary']['snapshot']['salary']['short']);
            self::assertSame($salaryReadback,$changed['saved_salary']);
            // Only bijective archived pick lineage proves a cross-system identity.

            foreach([[101,4,9,1,'pick','pick'],[102,5,10,1,'pick','pick'],[103,5,11,1,'pick','pick'],[104,6,12,1,'pick','pick'],[105,7,12,1,'pick','pick'],[106,8,13,999,'pick','pick'],[107,9,14,1,'long','pick']] as [$source,$legacy,$native,$game,$kind,$sourceKind]){
                DB::table('ppb_legacy_pick_contents')->insert(['source_pick_id'=>$source,'game_id'=>1,'legacy_analyst_id'=>$legacy,'kind'=>$sourceKind]);
                DB::table('ppb_submissions')->insert(['id'=>$source,'game_id'=>$game,'assignment_id'=>1,'submitted_analyst_id'=>$native,'kind'=>$kind,'status'=>'legacy_imported','submitted_at'=>'2026-05-01 00:00:00','legacy_source_pick_id'=>$source]);
            }
            $actualSubjects=new EvaluationSubjects($reader);self::assertSame([4=>9],$actualSubjects->mappings());
            DB::statement('CREATE TEMPORARY TABLE ppb_analysts (id BIGINT PRIMARY KEY,name VARCHAR(100),display_name VARCHAR(100) NULL,status VARCHAR(20),user_type INT,account_id BIGINT NULL) ENGINE=InnoDB');
            foreach([[9,'active',0],[10,'inactive',0],[11,'active',2],[12,'active',3]] as [$native,$status,$type])DB::table('ppb_analysts')->insert(['id'=>$native,'name'=>'NativeFixture'.$native,'display_name'=>null,'status'=>$status,'user_type'=>$type,'account_id'=>null]);
            $reader->shouldReceive('profiles')->andReturn([['id'=>4,'sort_id'=>1,'user_type'=>0,'rating_level'=>1]]);
            $subjectsList=$actualSubjects->all();self::assertCount(2,$subjectsList);self::assertSame('legacy',$subjectsList[0]['type']);self::assertSame(9,$subjectsList[0]['native_id']);self::assertSame('v2',$subjectsList[1]['type']);self::assertSame(10,$subjectsList[1]['id']);self::assertSame('inactive',$subjectsList[1]['status']);

            // A three-month report must retain the earliest month's V2 ratings.
            // Compare the SAME March period from April and May, including its
            // settled salary. Dates are UTC; March starts at Feb28 16:00 Taipei.
            DB::table('ppb_games')->insert(['id'=>201,'recommended_at'=>'2026-02-28 16:00:00','market_type'=>1,'game_number'=>201]);
            DB::table('ppb_assignments')->insert(['id'=>201,'game_id'=>201,'kind'=>'pick','original_analyst_id'=>9,'current_analyst_id'=>9]);
            DB::table('ppb_submissions')->insert(['id'=>201,'game_id'=>201,'assignment_id'=>201,'submitted_analyst_id'=>9,'kind'=>'pick','submitted_at'=>'2026-03-01 00:00:00']);
            DB::table('ppb_evaluation_ratings')->insert(array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS,60)+[
                'source_type'=>'v2','source_id'=>201,'subject_type'=>'legacy','subject_id'=>4,'month'=>'2026-03',
                'result_state'=>1,'revision'=>1,'source_sha256'=>str_repeat('c',64),'actor_id'=>71]);
            $writes=0;
            $aprilHistory=$realService->load($origin,'2026-04');
            $mayHistory=$realService->load($origin,'2026-05');
            self::assertSame(0,$writes,'Historical evaluation GETs must remain read-only.');
            self::assertSame(1,$mayHistory['history']['2026-03']['counts']['rated']);
            self::assertSame(60,$mayHistory['history']['2026-03']['selected']['records'][0]['score_delivery_time']);
            self::assertSame(100,$mayHistory['history']['2026-03']['salary']['short']);
            self::assertSame($aprilHistory['history']['2026-03'],$mayHistory['history']['2026-03'],
                'Changing the selected month must not lose the same historical rating or pay.');

            // A prior-month grade changes this month's salary without changing
            // its own revision. The displayed source token must detect that.
            $beforeGrade=$realService->load($substitute,'2026-05');
            DB::table('ppb_evaluations')->insert($reporter->defaults('2026-04')+[
                'subject_type'=>'v2','subject_id'=>10,'month'=>'2026-04','revision'=>1,
                'next_rating_level'=>3,'source_sha256'=>str_repeat('d',64),'actor_id'=>71]);
            $writes=0;
            $afterGrade=$realService->load($substitute,'2026-05');
            self::assertSame(0,$writes);
            self::assertSame($beforeGrade['revision'],$afterGrade['revision']);
            self::assertSame(3,$afterGrade['current']['level']);
            self::assertSame(60,$afterGrade['current']['salary']['short']);
            self::assertNotSame($beforeGrade['source_sha256'],$afterGrade['source_sha256'],
                'Prior-month rating dependencies must invalidate a stale salary source token.');
            self::assertSame($beforeGrade['saved_salary'],$afterGrade['saved_salary']);
            try{
                $realWriter->save($substitute,'2026-05','salary_snapshot',[],
                    '683f084d-b85d-437b-9d9c-b280ce473e97',$beforeGrade['revision'],$beforeGrade['source_sha256'],71);
                self::fail('A stale cross-month salary request must fail before writing.');
            }catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(409,$e->getStatusCode());}
            self::assertSame(0,$writes,'Rejecting a stale salary source cannot write even a temporary evaluation row.');

            self::assertSame(0,(int)DB::selectOne("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA='mysql' AND TABLE_NAME LIKE 'ppb_%'")->n);
            DB::disconnect('mysql');
        }finally{
            DB::disconnect('mysql');
            try{try{$this->command(['docker','exec',$name,'mysqladmin','--no-defaults','--user=root','--skip-password','shutdown'],15);}catch(\Throwable $ignored){} $this->command(['docker','rm','-f',$name],30);}finally{if(is_dir($socket))@rmdir($socket);}
        }
    }
}
