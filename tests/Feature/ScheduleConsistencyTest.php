<?php
namespace Tests\Feature;

use App\Models\{PpbAccount,PpbAssignment,PpbGame};
use App\Services\Ppb\{ScheduleManager,ScheduleVersion};
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ScheduleConsistencyTest extends TestCase
{
    private function command(array $argv):string
    {
        $p=new Process($argv);$p->setTimeout(120);$p->run();
        if(!$p->isSuccessful())throw new \RuntimeException('ISOLATED_MYSQL57_COMMAND_FAILED');return $p->getOutput();
    }
    private function actor():void
    {
        config(['app.url'=>'https://v2.csdatw.xyz']);$actor=Mockery::mock(PpbAccount::class)->makePartial();
        $actor->forceFill(['id'=>41,'name'=>'Staff fixture','username'=>'fixture','is_active'=>true]);
        $actor->shouldReceive('hasPpbPermission')->andReturnUsing(fn($p)=>in_array($p,['schedules.view','schedules.manage'],true));
        $roles=Mockery::mock(BelongsToMany::class);$roles->shouldReceive('where')->andReturnSelf();$roles->shouldReceive('exists')->andReturn(false);$actor->shouldReceive('roles')->andReturn($roles);$this->actingAs($actor,'ppb');
    }
    private function substitute(int $analyst,?string $version=null)
    {
        return $this->postJson('https://v2.csdatw.xyz/admin/assignments/1/substitute',[
            'to_analyst_id'=>$analyst,'reason'=>'Fixture substitution','revision_token'=>$version??ScheduleVersion::assignment(PpbAssignment::findOrFail(1))]);
    }
    public function test_sequential_stale_state_wp_history_and_audit_rollback_in_session_temporary_tables():void
    {
        $name='ppb-schedule-'.bin2hex(random_bytes(6));$socket=sys_get_temp_dir().'/'.$name;mkdir($socket,0777);chmod($socket,0777);
        try{
            $this->command(['docker','run','-d','--name',$name,'--network=none','--tmpfs','/var/lib/mysql:rw,nosuid,size=768m','--mount','type=bind,source='.$socket.',target=/run/mysqld','-e','MYSQL_ALLOW_EMPTY_PASSWORD=yes','mysql:5.7.44@sha256:4bc6bc963e6d8443453676cae56536f4b8156d78bae03c0145cbe47c2aad73bb','--skip-networking']);
            $ready=false;for($i=0;$i<60;$i++){if(file_exists($socket.'/mysqld.sock')){try{if(trim($this->command(['docker','exec',$name,'cat','/proc/1/comm']))==='mysqld'){$ready=true;break;}}catch(\Throwable $ignored){}}usleep(500000);}self::assertTrue($ready);
            config(['database.connections.mysql'=>['driver'=>'mysql','database'=>'mysql','username'=>'root','password'=>'','unix_socket'=>$socket.'/mysqld.sock','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true]]);DB::purge('mysql');DB::setDefaultConnection('mysql');$this->actor();
            self::assertSame('5.7.44',DB::selectOne('SELECT VERSION() v')->v);self::assertCount(4,DB::select('SHOW DATABASES'));
            DB::statement('CREATE TEMPORARY TABLE ppb_schedule_scopes (month_key CHAR(7),game_type INT,PRIMARY KEY(month_key,game_type)) ENGINE=InnoDB');
            DB::statement("CREATE TEMPORARY TABLE ppb_games (id BIGINT AUTO_INCREMENT PRIMARY KEY,month_key CHAR(7) DEFAULT '2026-11',game_type INT DEFAULT 1,game_number INT,active_number INT,status VARCHAR(24) DEFAULT 'scheduled',sport VARCHAR(30),league VARCHAR(50),home_team VARCHAR(50),away_team VARCHAR(50),matchup_mode VARCHAR(24),matchup_text VARCHAR(255),fixture_id BIGINT NULL,play_type INT,play_value VARCHAR(160),market_type INT,reviewer_analyst_id BIGINT NULL,has_long_article TINYINT DEFAULT 0,recommended_at DATETIME,starts_at DATETIME NULL,notes TEXT NULL,wp_locked_at DATETIME NULL,cancelled_at DATETIME NULL,cancel_reason VARCHAR(255) NULL,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE(month_key,game_type,active_number)) ENGINE=InnoDB");
            DB::statement('CREATE TEMPORARY TABLE ppb_assignments (id BIGINT AUTO_INCREMENT PRIMARY KEY,game_id BIGINT,original_analyst_id BIGINT,current_analyst_id BIGINT,kind VARCHAR(20),slot_no INT,status VARCHAR(24),notes TEXT NULL,created_at DATETIME NULL,updated_at DATETIME NULL,UNIQUE(game_id,kind,slot_no),UNIQUE(game_id,original_analyst_id,kind)) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_analysts (id BIGINT PRIMARY KEY,account_id BIGINT NULL,name VARCHAR(80),display_name VARCHAR(80),status VARCHAR(24),user_type INT) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_substitutions (id BIGINT AUTO_INCREMENT PRIMARY KEY,assignment_id BIGINT,from_analyst_id BIGINT,to_analyst_id BIGINT,performed_by_account_id BIGINT,reason VARCHAR(255),changed_at DATETIME) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_submissions (id BIGINT AUTO_INCREMENT PRIMARY KEY,game_id BIGINT,assignment_id BIGINT,kind VARCHAR(20),submitted_analyst_id BIGINT NULL,status VARCHAR(24),content_body LONGTEXT NULL,recommend_pick TEXT NULL,analysis_text LONGTEXT NULL,submitted_at DATETIME NULL,last_edited_by_account_id BIGINT NULL,reviewed_by_account_id BIGINT NULL,reviewed_at DATETIME NULL,review_note TEXT NULL,legacy_source_pick_id BIGINT NULL,created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_publications (id BIGINT AUTO_INCREMENT PRIMARY KEY,game_id BIGINT,status VARCHAR(24),verified_at DATETIME NULL) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_schedule_events (id BIGINT AUTO_INCREMENT PRIMARY KEY,game_id BIGINT,actor_id BIGINT,event VARCHAR(40),detail_json JSON,created_at DATETIME) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_auth_audits (id BIGINT AUTO_INCREMENT PRIMARY KEY,account_id BIGINT,event VARCHAR(120),ip VARCHAR(45),user_agent VARCHAR(255),created_at DATETIME) ENGINE=InnoDB');
            foreach([9,10,11,12] as $id)DB::table('ppb_analysts')->insert(['id'=>$id,'name'=>'Fixture'.$id,'display_name'=>'Fixture'.$id,'status'=>'active','user_type'=>1]);
            foreach([1,2] as $id)DB::table('ppb_games')->insert(['id'=>$id,'game_number'=>$id,'active_number'=>$id,'reviewer_analyst_id'=>9,'recommended_at'=>'2026-11-20 03:00:00','matchup_text'=>'Fixture match']);
            foreach([[1,9],[2,11]] as [$slot,$analyst])DB::table('ppb_assignments')->insert(['id'=>$slot,'game_id'=>1,'kind'=>'pick','slot_no'=>$slot,'original_analyst_id'=>$analyst,'current_analyst_id'=>$analyst,'status'=>'active']);
            DB::statement('CREATE TEMPORARY TABLE ppb_sport_fixtures (id BIGINT PRIMARY KEY,sport VARCHAR(30),starts_at DATETIME NULL) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_legacy_pick_contents (source_pick_id BIGINT PRIMARY KEY,game_id BIGINT,kind VARCHAR(20)) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_legacy_wp_articles (source_pick_id BIGINT PRIMARY KEY,game_id BIGINT) ENGINE=InnoDB');
            $writes=0;$locks=[];DB::listen(function($q)use(&$writes,&$locks){if(preg_match('/^(insert|update|delete) /i',$q->sql))$writes++;if(str_contains(strtolower($q->sql),'for update'))$locks[]=$q->sql;});
            // Original endpoint accepts substitution despite unresolved publication.
            DB::table('ppb_publications')->insert(['game_id'=>1,'status'=>'uploading']);$writes=0;
            $this->substitute(10)->assertUnprocessable();self::assertSame(0,$writes);self::assertSame(0,DB::table('ppb_substitutions')->count());
            DB::table('ppb_publications')->delete();
            foreach(['published','latched'] as $protected){
                if($protected==='published')DB::table('ppb_publications')->insert(['game_id'=>1,'status'=>'published','verified_at'=>'2026-11-15 00:00:00']);
                else DB::table('ppb_games')->where('id',1)->update(['wp_locked_at'=>'2026-11-15 00:00:00']);
                $writes=0;$this->substitute(10)->assertUnprocessable();self::assertSame(0,$writes);
                $this->postJson('https://v2.csdatw.xyz/admin/assignments/1/status',['status'=>'completed','revision_token'=>ScheduleVersion::assignment(PpbAssignment::findOrFail(1))])->assertUnprocessable();self::assertSame(0,$writes);
                DB::table('ppb_publications')->delete();DB::table('ppb_games')->where('id',1)->update(['wp_locked_at'=>null]);
            }
            $before=ScheduleVersion::assignment(PpbAssignment::findOrFail(1));$locks=[];
            $this->substitute(10,$before)->assertRedirect();
            $gameLock=array_search(true,array_map(fn($sql)=>str_contains($sql,'`ppb_games`'),$locks),true);
            $assignmentLock=array_search(true,array_map(fn($sql)=>str_contains($sql,'`ppb_assignments`'),$locks),true);
            self::assertNotFalse($gameLock);self::assertNotFalse($assignmentLock);self::assertLessThan($assignmentLock,$gameLock);
            self::assertSame(9,(int)DB::table('ppb_assignments')->where('id',1)->value('original_analyst_id'));
            $writes=0;$this->substitute(12,$before)->assertConflict();self::assertSame(0,$writes);
            $this->substitute(11)->assertUnprocessable();self::assertSame(1,DB::table('ppb_substitutions')->count());
            $manager=new ScheduleManager;$game=PpbGame::findOrFail(1);$version=ScheduleVersion::game($game);$slots=['short'=>[1=>10,2=>11]];
            $manager->update($game,['month_key'=>'2026-11','game_type'=>1,'notes'=>'First edit'],$slots,$version);
            try{$manager->update($game,['month_key'=>'2026-11','game_type'=>1,'notes'=>'Stale edit'],$slots,$version);self::fail('Stale schedule edit must fail.');}
            catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){self::assertSame(409,$e->getStatusCode());}
            self::assertSame('First edit',PpbGame::findOrFail(1)->notes);
            // Real form/API contract: stale browser fields cannot be re-armed.
            $edit=$this->get('https://v2.csdatw.xyz/admin/games/1/edit')->assertOk();
            self::assertSame(1,preg_match('/name="revision_token" value="([a-f0-9]{64})"/',$edit->getContent(),$match));
            $oldFormVersion=$match[1];$latest=PpbGame::findOrFail(1);
            $manager->update($latest,['month_key'=>'2026-11','game_type'=>1,'notes'=>'Other admin'],$slots,ScheduleVersion::game($latest));
            $payload=['game_type'=>1,'match_date'=>'2026-11-20','match_time'=>'11:00','matchup_mode'=>'manual',
                'manual_league'=>'Fixture league','manual_matchup'=>'Away @ Home','play_type'=>1,'play_value'=>'-1.5',
                'market_type'=>1,'reviewer_analyst_id'=>9,'short'=>[1=>10,2=>11],'revision_token'=>$oldFormVersion];
            $this->putJson('https://v2.csdatw.xyz/admin/games/1',$payload)->assertConflict();
            self::assertSame('Other admin',PpbGame::findOrFail(1)->notes);
            $edit=$this->get('https://v2.csdatw.xyz/admin/games/1/edit')->assertOk();
            preg_match('/name="revision_token" value="([a-f0-9]{64})"/',$edit->getContent(),$match);$payload['revision_token']=$match[1];
            $this->putJson('https://v2.csdatw.xyz/admin/games/1',$payload)->assertRedirect();
            $detail=$this->get('https://v2.csdatw.xyz/admin/games/1')->assertOk();
            self::assertTrue(str_contains($detail->getContent(),'data-assignment-revision='));
            $oldAssignment=ScheduleVersion::assignment(PpbAssignment::findOrFail(1));
            $statusUrl='https://v2.csdatw.xyz/admin/assignments/1/status';
            $this->postJson($statusUrl,['status'=>'completed','revision_token'=>$oldAssignment])->assertRedirect();
            $writes=0;$this->postJson($statusUrl,['status'=>'cancelled','revision_token'=>$oldAssignment])->assertConflict();self::assertSame(0,$writes);
            $this->postJson($statusUrl,['status'=>'active','revision_token'=>ScheduleVersion::assignment(PpbAssignment::findOrFail(1))])->assertRedirect();
            // Different games do not invalidate each other's state tokens.
            $unrelated=ScheduleVersion::game(PpbGame::findOrFail(2));
            // Existing posted authorship remains frozen across explicit substitution.
            DB::table('ppb_submissions')->insert(['game_id'=>1,'assignment_id'=>1,'kind'=>'pick','submitted_analyst_id'=>10,'status'=>'submitted','analysis_text'=>'Frozen body']);
            $row=(array)DB::table('ppb_submissions')->first();$this->substitute(12)->assertRedirect();self::assertSame($row,(array)DB::table('ppb_submissions')->first());
            $this->travel(61)->seconds();
            $snapshot=(array)DB::table('ppb_assignments')->where('id',1)->first();$count=DB::table('ppb_substitutions')->count();$auditCount=DB::table('ppb_auth_audits')->count();$fail=true;
            DB::listen(function($q)use(&$fail){if($fail&&str_starts_with($q->sql,'insert into `ppb_auth_audits`'))throw new \RuntimeException('INJECTED_ASSIGNMENT_AUDIT_FAILURE');});
            $this->withoutExceptionHandling();try{$this->substitute(9);self::fail('Audit failure must roll back.');}catch(\RuntimeException $e){self::assertSame('INJECTED_ASSIGNMENT_AUDIT_FAILURE',$e->getMessage());}finally{$fail=false;$this->withExceptionHandling();}
            self::assertSame($snapshot,(array)DB::table('ppb_assignments')->where('id',1)->first());self::assertSame($count,DB::table('ppb_substitutions')->count());
            self::assertSame($auditCount,DB::table('ppb_auth_audits')->count());
            $fail=true;$this->withoutExceptionHandling();
            try{
                $this->postJson($statusUrl,['status'=>'completed','revision_token'=>ScheduleVersion::assignment(PpbAssignment::findOrFail(1))]);self::fail('Status audit failure must roll back.');
            }catch(\RuntimeException $e){self::assertSame('INJECTED_ASSIGNMENT_AUDIT_FAILURE',$e->getMessage());}finally{$fail=false;$this->withExceptionHandling();}
            self::assertSame($snapshot,(array)DB::table('ppb_assignments')->where('id',1)->first());self::assertSame($auditCount,DB::table('ppb_auth_audits')->count());
            $oldGame=(array)DB::table('ppb_games')->where('id',1)->first();$currentGame=PpbGame::findOrFail(1);
            try{$manager->update($currentGame,['month_key'=>'2026-11','game_type'=>1,'notes'=>'Invalid reassignment'],['short'=>[1=>9,2=>11]],ScheduleVersion::game($currentGame));self::fail('Submitted/substituted assignment must not be replaced.');}
            catch(\Illuminate\Validation\ValidationException $e){self::assertArrayHasKey('schedule',$e->errors());}
            self::assertSame($oldGame,(array)DB::table('ppb_games')->where('id',1)->first());self::assertSame($row,(array)DB::table('ppb_submissions')->first());

            self::assertSame($unrelated,ScheduleVersion::game(PpbGame::findOrFail(2)));
            // A verified published cancellation keeps the irrevocable G/E slot.
            DB::table('ppb_games')->insert(['id'=>3,'game_number'=>3,'active_number'=>3,'reviewer_analyst_id'=>9]);
            DB::table('ppb_publications')->insert(['game_id'=>3,'status'=>'published','verified_at'=>'2026-11-15 00:00:00']);
            $published=PpbGame::findOrFail(3);$manager->cancel($published,'Published fixture',ScheduleVersion::game($published));
            self::assertSame(3,PpbGame::findOrFail(3)->active_number);self::assertSame(3,PpbGame::findOrFail(3)->game_number);
            // Inject failure after BOTH cancellation and a following safe renumber.
            $gamesBefore=DB::table('ppb_games')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();$eventsBefore=DB::table('ppb_schedule_events')->count();$failSchedule=true;
            DB::listen(function($q)use(&$failSchedule){
                if($failSchedule&&str_starts_with($q->sql,'insert into `ppb_schedule_events`')&&in_array('renumber.compact',$q->bindings,true))throw new \RuntimeException('INJECTED_COMPACTION_AUDIT_FAILURE');
            });
            $cancel=PpbGame::findOrFail(1);
            try{$manager->cancel($cancel,'Rollback fixture',ScheduleVersion::game($cancel));self::fail('Compaction audit must roll back cancellation too.');}
            catch(\RuntimeException $e){self::assertSame('INJECTED_COMPACTION_AUDIT_FAILURE',$e->getMessage());}finally{$failSchedule=false;}
            self::assertSame($gamesBefore,DB::table('ppb_games')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all());
            self::assertSame($eventsBefore,DB::table('ppb_schedule_events')->count());self::assertSame(0,DB::transactionLevel());
            $cancel=PpbGame::findOrFail(1);$manager->cancel($cancel,'Fixture cancel',ScheduleVersion::game($cancel));
            self::assertNull(PpbGame::findOrFail(1)->active_number);self::assertSame(1,PpbGame::findOrFail(2)->active_number);self::assertSame(3,PpbGame::findOrFail(3)->active_number);

            self::assertSame($row,(array)DB::table('ppb_submissions')->first());$writes=0;
            $this->substitute(9)->assertUnprocessable();self::assertSame(0,$writes);
            $current=PpbAssignment::findOrFail(1);
            $this->postJson('https://v2.csdatw.xyz/admin/assignments/1/status',['status'=>'active','revision_token'=>ScheduleVersion::assignment($current)])->assertUnprocessable();
            // Independent delete scenario: keep production throttling enabled.
            $this->travel(61)->seconds();
            $deletable=PpbGame::findOrFail(2);
            $this->deleteJson('https://v2.csdatw.xyz/admin/games/2',[])->assertUnprocessable();
            $this->deleteJson('https://v2.csdatw.xyz/admin/games/2',['revision_token'=>$unrelated])->assertConflict();
            $this->deleteJson('https://v2.csdatw.xyz/admin/games/2',['revision_token'=>ScheduleVersion::game($deletable)])->assertRedirect();
            self::assertNull(PpbGame::find(2));self::assertSame(3,PpbGame::findOrFail(3)->active_number);
            $this->deleteJson('https://v2.csdatw.xyz/admin/games/3',['revision_token'=>ScheduleVersion::game(PpbGame::findOrFail(3))])->assertUnprocessable();
            self::assertSame(3,PpbGame::findOrFail(3)->active_number);
            // Existing G/E behavior: append skips reserved cancelled numbers;
            // inserting before a submitted game must stop rather than renumber it.
            $data=['month_key'=>'2026-11','game_type'=>1,'matchup_text'=>'Append fixture'];
            $appended=$manager->create($data,['short'=>[]]);self::assertSame(4,$appended->game_number);
            DB::table('ppb_submissions')->insert(['game_id'=>$appended->id,'assignment_id'=>null,'kind'=>'pick','submitted_analyst_id'=>9,'status'=>'submitted']);
            $countBefore=DB::table('ppb_games')->count();
            try{$manager->create($data,['short'=>[]],'before',$appended->id);self::fail('Submitted history must prevent insertion renumber.');}
            catch(\Illuminate\Validation\ValidationException $e){self::assertArrayHasKey('schedule',$e->errors());}
            self::assertSame($countBefore,DB::table('ppb_games')->count());self::assertSame(4,PpbGame::findOrFail($appended->id)->game_number);self::assertSame(3,PpbGame::findOrFail(3)->active_number);
            // Literal two-session visibility proof, NOT a shared-row race test.
            // MySQL5.7 session TEMPORARY tables cannot serve that latter purpose.
            $peer=new \PDO('mysql:unix_socket='.$socket.'/mysqld.sock;dbname=mysql','root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
            try{$peer->query('SELECT id FROM ppb_games LIMIT 1');self::fail('Peer session must not see another session temporary table.');}
            catch(\PDOException $e){self::assertSame('42S02',$e->getCode());self::assertSame(1146,(int)$e->errorInfo[1]);}
            $peer=null;
            self::assertSame(0,(int)DB::selectOne("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA='mysql' AND TABLE_NAME LIKE 'ppb_%'")->n);
        }finally{$this->travelBack();DB::disconnect('mysql');try{$this->command(['docker','rm','-f',$name]);}finally{if(is_dir($socket))@rmdir($socket);}}
    }
}
