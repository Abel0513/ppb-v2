<?php
namespace Tests\Feature;

use App\Models\PpbAccount;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Actual W2 routes/controller/Blade + session TEMPORARY tables, never a test DB. */
class ManuscriptAuthorizationTest extends TestCase
{
    private function actor(int $id,bool $manage=false,bool $active=true):void
    {
        config(['app.url'=>'https://v2.csdatw.xyz']);
        $user=Mockery::mock(PpbAccount::class)->makePartial();
        $user->forceFill(['id'=>$id,'name'=>'Staff fixture','username'=>'fixture','is_active'=>$active]);
        $user->shouldReceive('hasPpbPermission')->andReturnUsing(fn($p)=>$p==='schedules.manage'?$manage:$p==='schedules.view');
        $roles=Mockery::mock(BelongsToMany::class);$roles->shouldReceive('where')->andReturnSelf();$roles->shouldReceive('exists')->andReturn(false);
        $user->shouldReceive('roles')->andReturn($roles);$this->actingAs($user,'ppb');
    }
    private function command(array $args):string
    {
        $p=new Process($args);$p->setTimeout(120);$p->run();
        if(!$p->isSuccessful())throw new \RuntimeException('ISOLATED_MYSQL57_COMMAND_FAILED');
        return $p->getOutput();
    }
    private function token(int $game=1):string
    {
        $response=$this->get('https://v2.csdatw.xyz/admin/games/'.$game.'/manuscripts')->assertOk();
        self::assertSame(1,preg_match('/name="revision_token" value="([a-f0-9]{64}|new)"/',$response->getContent(),$m),'A writable or reviewable form must carry a revision token.');
        return $m[1];
    }
    private function postSave(string $token,array $more=[])
    {
        return $this->postJson('https://v2.csdatw.xyz/admin/manuscripts/assignments/1/save',array_replace([
            'mode'=>'submit','revision_token'=>$token,'recommend_pick'=>'Fixture choice','analysis_text'=>'Fixture body'],$more));
    }
    private function postReview(int $id,string $token,string $decision='approved')
    {
        return $this->postJson('https://v2.csdatw.xyz/admin/manuscripts/submissions/'.$id.'/review',[
            'decision'=>$decision,'review_note'=>$decision==='changes_requested'?'Please revise fixture':null,'revision_token'=>$token]);
    }
    public function test_guest_untrusted_host_and_inactive_staff_cannot_enter_w2():void
    {
        config(['app.url'=>'https://v2.csdatw.xyz']);
        $this->get('https://v2.csdatw.xyz/admin/manuscripts')->assertRedirect();
        $this->postJson('https://v2.csdatw.xyz/admin/manuscripts/submissions/1/review',[])->assertUnauthorized();
        $this->actor(71,true);
        $this->get('http://v2.csdatw.xyz/admin/manuscripts')->assertForbidden();
        $this->get('https://foreign.example/admin/manuscripts')->assertForbidden();
        $this->actor(71,true,false);
        $this->get('https://v2.csdatw.xyz/admin/manuscripts')->assertForbidden();
    }
    public function test_real_w2_authorization_versions_history_and_staff_workflow():void
    {
        $name='ppb-w2-'.bin2hex(random_bytes(6));$socket=sys_get_temp_dir().'/'.$name;mkdir($socket,0777);chmod($socket,0777);
        try{
            $this->command(['docker','run','-d','--name',$name,'--network=none','--tmpfs','/var/lib/mysql:rw,nosuid,size=768m','--mount','type=bind,source='.$socket.',target=/run/mysqld','-e','MYSQL_ALLOW_EMPTY_PASSWORD=yes','mysql:5.7.44@sha256:4bc6bc963e6d8443453676cae56536f4b8156d78bae03c0145cbe47c2aad73bb','--skip-networking']);
            $ready=false;for($i=0;$i<60;$i++){
                if(file_exists($socket.'/mysqld.sock')){try{if(trim($this->command(['docker','exec',$name,'cat','/proc/1/comm']))==='mysqld'){$ready=true;break;}}catch(\Throwable $ignored){}}
                usleep(500000);
            }
            self::assertTrue($ready);
            config(['database.connections.mysql'=>['driver'=>'mysql','database'=>'mysql','username'=>'root','password'=>'','unix_socket'=>$socket.'/mysqld.sock','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true]]);
            DB::purge('mysql');DB::setDefaultConnection('mysql');
            self::assertSame('5.7.44',DB::selectOne('SELECT VERSION() v')->v);self::assertCount(4,DB::select('SHOW DATABASES'));
            DB::statement('CREATE TEMPORARY TABLE ppb_analysts (id BIGINT PRIMARY KEY,account_id BIGINT NULL,name VARCHAR(80),display_name VARCHAR(80),status VARCHAR(24),user_type INT) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_games (id BIGINT PRIMARY KEY,status VARCHAR(24),reviewer_analyst_id BIGINT NULL,game_type INT DEFAULT 1,game_number INT DEFAULT 1,matchup_text VARCHAR(100),home_team VARCHAR(40),away_team VARCHAR(40),recommended_at DATETIME,wp_locked_at DATETIME NULL,has_long_article TINYINT DEFAULT 0) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_assignments (id BIGINT PRIMARY KEY,game_id BIGINT,original_analyst_id BIGINT,current_analyst_id BIGINT,kind VARCHAR(20),slot_no INT,status VARCHAR(24),created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_submissions (id BIGINT AUTO_INCREMENT PRIMARY KEY,game_id BIGINT,assignment_id BIGINT,kind VARCHAR(20),submitted_analyst_id BIGINT NULL,content_body LONGTEXT NULL,status VARCHAR(24),submitted_at DATETIME NULL,recommend_pick TEXT NULL,analysis_text LONGTEXT NULL,last_edited_by_account_id BIGINT NULL,reviewed_by_account_id BIGINT NULL,reviewed_at DATETIME NULL,review_note TEXT NULL,legacy_source_pick_id BIGINT NULL,created_at DATETIME NULL,updated_at DATETIME NULL) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_publications (id BIGINT AUTO_INCREMENT PRIMARY KEY,game_id BIGINT,status VARCHAR(24),verified_at DATETIME NULL) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_schedule_events (id BIGINT AUTO_INCREMENT PRIMARY KEY,game_id BIGINT,actor_id BIGINT,event VARCHAR(40),detail_json JSON,created_at DATETIME) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_substitutions (id BIGINT AUTO_INCREMENT PRIMARY KEY,assignment_id BIGINT,from_analyst_id BIGINT,to_analyst_id BIGINT,performed_by_account_id BIGINT,reason VARCHAR(255),changed_at DATETIME) ENGINE=InnoDB');
            DB::statement('CREATE TEMPORARY TABLE ppb_auth_audits (id BIGINT AUTO_INCREMENT PRIMARY KEY,account_id BIGINT,event VARCHAR(120),ip VARCHAR(45),user_agent VARCHAR(255),created_at DATETIME) ENGINE=InnoDB');
            DB::table('ppb_analysts')->insert([['id'=>9,'account_id'=>70,'name'=>'Analyst fixture','display_name'=>'<script>fixture</script>','status'=>'active','user_type'=>1],['id'=>10,'account_id'=>72,'name'=>'Substitute fixture','display_name'=>'Substitute','status'=>'active','user_type'=>0]]);
            foreach([1,2] as $id)DB::table('ppb_games')->insert(['id'=>$id,'status'=>'scheduled','reviewer_analyst_id'=>9,'matchup_text'=>'Fixture match','home_team'=>'Home','away_team'=>'Away','recommended_at'=>'2026-11-15 04:00:00']);
            DB::table('ppb_assignments')->insert(['id'=>1,'game_id'=>1,'original_analyst_id'=>9,'current_analyst_id'=>9,'kind'=>'pick','slot_no'=>1,'status'=>'active']);
            $writes=0;DB::listen(function($q)use(&$writes){if(preg_match('/^(insert|update|delete) /i',$q->sql))$writes++;});

            $this->actor(70); // Actual account_id link to assigned author AND reviewer.
            $this->get('https://v2.csdatw.xyz/admin/manuscripts')->assertForbidden();
            $this->get('https://v2.csdatw.xyz/admin/games/1/manuscripts?analyst_id=9&line_id=untrusted')->assertForbidden();
            $this->postSave('new',['analyst_id'=>9,'line_id'=>'untrusted'])->assertForbidden();
            $this->postReview(1,str_repeat('a',64))->assertForbidden();
            $this->postJson('https://v2.csdatw.xyz/admin/assignments/1/substitute',['to_analyst_id'=>10,'reason'=>'Untrusted'])->assertForbidden();
            self::assertSame(0,$writes);self::assertSame(0,DB::table('ppb_submissions')->count());

            $this->actor(71,true); // Explicit staff permission, no analyst account link.
            $this->get('https://v2.csdatw.xyz/admin/manuscripts')->assertOk()->assertSee('Fixture match');
            $this->get('https://v2.csdatw.xyz/admin/games/1/manuscripts')->assertOk()->assertSee('&lt;script&gt;fixture&lt;/script&gt;',false)->assertDontSee('<script>fixture</script>',false)->assertSee('分析師身分委派尚未接入');
            $newToken=$this->token();$this->postSave($newToken,['submitted_analyst_id'=>10,'last_edited_by_account_id'=>70,'line_id'=>'untrusted'])->assertRedirect();
            $id=(int)DB::table('ppb_submissions')->value('id');
            self::assertSame(9,(int)DB::table('ppb_submissions')->value('submitted_analyst_id'));
            self::assertSame(71,(int)DB::table('ppb_submissions')->value('last_edited_by_account_id'));
            $reviewToken=$this->token();
            $this->postReview($id,$reviewToken,'changes_requested')->assertRedirect();
            $editToken=$this->token();$this->postSave($editToken,['analysis_text'=>'Revised fixture body'])->assertRedirect();
            $writes=0;$this->postReview($id,$reviewToken)->assertUnprocessable();
            self::assertSame(0,$writes);self::assertSame('submitted',DB::table('ppb_submissions')->value('status'));
            $this->postReview($id,$this->token())->assertRedirect();
            self::assertSame('approved',DB::table('ppb_submissions')->value('status'));
            self::assertSame(71,(int)DB::table('ppb_submissions')->value('reviewed_by_account_id'));
            $event=DB::table('ppb_schedule_events')->orderBy('id')->first();
            self::assertSame('backend_management',json_decode($event->detail_json,true)['actor_boundary']);
            self::assertStringNotContainsString('Fixture body',$event->detail_json);
            self::assertSame(0,DB::table('ppb_publications')->count());

            // A pending/verified WP game cannot be changed, even by staff.
            foreach([['status'=>'uploading','verified_at'=>null],['status'=>'published','verified_at'=>'2026-11-15 00:00:00']] as $publication){
                DB::table('ppb_submissions')->where('id',$id)->update(['status'=>'draft']);$token=$this->token();
                DB::table('ppb_publications')->insert($publication+['game_id'=>1]);
                $before=(array)DB::table('ppb_submissions')->first();$writes=0;
                $this->postSave($token)->assertUnprocessable();self::assertSame(0,$writes);self::assertSame($before,(array)DB::table('ppb_submissions')->first());
                DB::table('ppb_submissions')->where('id',$id)->update(['status'=>'submitted']);
                $read=$this->get('https://v2.csdatw.xyz/admin/games/1/manuscripts')->assertOk();$reviewToken=$read->viewData('tokens')[1];$writes=0;
                $this->postReview($id,$reviewToken)->assertUnprocessable();self::assertSame(0,$writes);
                DB::table('ppb_publications')->delete();
            }
            // Marker alone is sufficient protection, regardless of mutable status.
            foreach([0,123] as $source){
                DB::table('ppb_submissions')->where('id',$id)->update(['status'=>'draft','legacy_source_pick_id'=>null]);$token=$this->token();
                DB::table('ppb_submissions')->where('id',$id)->update(['legacy_source_pick_id'=>$source]);
                $read=$this->get('https://v2.csdatw.xyz/admin/games/1/manuscripts')->assertOk()->assertDontSee('name="mode"',false);
                // A version token is not authority to modify historical content.
                $currentToken=$read->viewData('tokens')[1];self::assertNotSame($token,$currentToken);$writes=0;
                $this->postSave($currentToken)->assertUnprocessable();self::assertSame(0,$writes);
            }
            DB::table('ppb_submissions')->where('id',$id)->update(['legacy_source_pick_id'=>null]);
            foreach([['submitted_analyst_id'=>10],['kind'=>'bonus']] as $mismatch){
                DB::table('ppb_submissions')->where('id',$id)->update(['kind'=>'pick','submitted_analyst_id'=>9,'status'=>'draft']);
                DB::table('ppb_submissions')->where('id',$id)->update($mismatch);
                $read=$this->get('https://v2.csdatw.xyz/admin/games/1/manuscripts')->assertOk()->assertDontSee('name="mode"',false);
                $writes=0;$this->postSave($read->viewData('tokens')[1])->assertUnprocessable();self::assertSame(0,$writes);
            }
            // Assignment change after form display must invalidate even a new draft.
            DB::table('ppb_submissions')->delete();$token=$this->token();
            $this->postJson('https://v2.csdatw.xyz/admin/assignments/1/substitute',['to_analyst_id'=>10,'reason'=>'Staff reassignment fixture'])->assertRedirect();
            self::assertSame(9,(int)DB::table('ppb_assignments')->value('original_analyst_id'));
            self::assertSame(10,(int)DB::table('ppb_assignments')->value('current_analyst_id'));
            self::assertSame(71,(int)DB::table('ppb_substitutions')->value('performed_by_account_id'));$writes=0;
            $this->postSave($token)->assertUnprocessable();self::assertSame(0,$writes);
            // A correctly refreshed staff request retains lawful management access.
            $this->postSave($this->token())->assertRedirect();$id=(int)DB::table('ppb_submissions')->value('id');
            foreach([70,72] as $linked){
                $this->actor($linked);$writes=0;
                $this->postSave(str_repeat('a',64))->assertForbidden();$this->postReview($id,str_repeat('a',64))->assertForbidden();self::assertSame(0,$writes);
            }
            $this->actor(71,true);
            // A stale submitted analyst cannot be borrowed after another substitution.
            $submittedToken=$this->token();
            DB::table('ppb_assignments')->where('id',1)->update(['current_analyst_id'=>9]);$writes=0;
            $this->postReview($id,$submittedToken)->assertUnprocessable();self::assertSame(0,$writes);
            DB::table('ppb_assignments')->where('id',1)->update(['current_analyst_id'=>10]);
            // Ambiguous duplicate submissions cannot be reviewed through a crafted POST.
            $token=$this->token();$duplicate=(array)DB::table('ppb_submissions')->first();unset($duplicate['id']);DB::table('ppb_submissions')->insert($duplicate);$writes=0;
            $this->postReview($id,$token)->assertUnprocessable();self::assertSame(0,$writes);
            DB::table('ppb_submissions')->delete();
            $this->postSave($this->token(),['recommend_pick'=>'0','analysis_text'=>'0'])->assertRedirect();
            self::assertSame('0',DB::table('ppb_submissions')->value('recommend_pick'));
            self::assertSame('0',DB::table('ppb_submissions')->value('analysis_text'));
            $id=(int)DB::table('ppb_submissions')->value('id');
            $this->postJson('https://v2.csdatw.xyz/admin/manuscripts/submissions/'.$id.'/review',[
                'decision'=>'changes_requested','revision_token'=>$this->token(),'review_note'=>'0'])->assertRedirect();
            self::assertSame('0',DB::table('ppb_submissions')->value('review_note'));
            $zeroNotePage=$this->get('https://v2.csdatw.xyz/admin/games/1/manuscripts')->assertOk();
            self::assertTrue(str_contains($zeroNotePage->getContent(),'核稿意見：0'),'Zero review note must remain visible.');
            $token=$this->token();$before=(array)DB::table('ppb_submissions')->first();
            $eventCount=DB::table('ppb_schedule_events')->count();$failAudit=true;
            DB::listen(function($q)use(&$failAudit){
                if($failAudit&&str_starts_with($q->sql,'insert into `ppb_schedule_events`'))throw new \RuntimeException('INJECTED_MANUSCRIPT_AUDIT_FAILURE');
            });
            $this->withoutExceptionHandling();
            try{$this->postSave($token,['mode'=>'save','analysis_text'=>'Rollback fixture']);self::fail('Audit failure must stop.');}
            catch(\RuntimeException $e){self::assertSame('INJECTED_MANUSCRIPT_AUDIT_FAILURE',$e->getMessage());}
            finally{$failAudit=false;$this->withExceptionHandling();}
            self::assertSame($before,(array)DB::table('ppb_submissions')->first());
            self::assertSame($eventCount,DB::table('ppb_schedule_events')->count());
            self::assertSame(0,DB::transactionLevel());
            self::assertSame(0,(int)DB::selectOne("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA='mysql' AND TABLE_NAME LIKE 'ppb_%'")->n);
        }finally{
            DB::disconnect('mysql');
            try{$this->command(['docker','rm','-f',$name]);}finally{if(is_dir($socket))@rmdir($socket);}
        }
    }
}
