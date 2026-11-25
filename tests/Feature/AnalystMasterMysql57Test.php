<?php
namespace Tests\Feature;
use App\Models\{PpbAccount,PpbAnalyst,PpbGame};
use App\Services\Ppb\{ScheduleManager,ScheduleVersion};
use App\Services\Ppb\Evaluation\{EvaluationService,EvaluationSubjects,LegacyReader};
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\{DB,Http};
use Mockery;
use Tests\Support\{DisposableMysql57,ScheduleSharedFixture,EvaluationIntegrationFixture};
use Tests\TestCase;
class AnalystMasterMysql57Test extends TestCase
{
    private const HOST='https://v2.csdatw.xyz';
    private function actor(bool $private=false,bool $manage=true):void
    {
        $user=Mockery::mock(PpbAccount::class)->makePartial();$user->forceFill(['id'=>41,'name'=>'Synthetic manager','is_active'=>true]);
        $user->shouldReceive('hasPpbPermission')->andReturnUsing(fn($p)=>in_array($p,['schedules.view','evaluations.view'],true)||($manage&&in_array($p,['schedules.manage','evaluations.manage'],true))||($private&&$p==='accounts.manage'));
        $roles=Mockery::mock(BelongsToMany::class);$roles->shouldReceive('where')->andReturnSelf();$roles->shouldReceive('exists')->andReturn(false);$user->shouldReceive('roles')->andReturn($roles);$this->actingAs($user,'ppb');
    }
    private function update(int $id,array $data=[])
    {
        $this->travel(61)->seconds(); // Keep real throttling, independent management actions.
        return $this->putJson(self::HOST.'/admin/analysts/'.$id,$data+['name'=>'Synthetic profile','status'=>'active','user_type'=>1]);
    }
    public function test_real_mysql_candidate_migration_http_fields_privacy_and_unchanged_history():void
    {
        $container=new DisposableMysql57;$pass=false;
        try{
            $pdo=$container->pdo();ScheduleSharedFixture::create($pdo,bin2hex(random_bytes(16)));ScheduleSharedFixture::reset($pdo);
            config(['app.url'=>self::HOST,'database.connections.mysql'=>['driver'=>'mysql','database'=>'mysql','username'=>'root','password'=>'','unix_socket'=>$container->socket(),'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true]]);
            DB::purge('mysql');DB::setDefaultConnection('mysql');DB::connection()->setPdo($pdo);
            self::assertSame($container->socket(),DB::connection()->getConfig('unix_socket'));
            $before=DB::table('ppb_analysts')->get()->toJson();
            // Only the verified disposable socket may execute candidate DDL.
            $migration=require base_path('database/migrations/2026_11_16_000012_add_confirmed_ppb_analyst_fields.php');$migration->up();
            $levelMigration=require base_path('database/migrations/2026_11_25_000013_add_ppb_analyst_background_and_level.php');$levelMigration->up();DB::statement('ALTER TABLE ppb_analysts ADD UNIQUE(account_id)');
            self::assertSame($before,DB::table('ppb_analysts')->get(['id','account_id','name','display_name','status','user_type','internal_notes','created_at','updated_at'])->toJson());
            try{$migration->up();self::fail('Existing columns must not be overwritten.');}catch(\RuntimeException $e){self::assertSame('ANALYST_MASTER_COLUMNS_ALREADY_EXIST',$e->getMessage());}
            try{$levelMigration->up();self::fail('Existing columns must not be overwritten.');}catch(\RuntimeException $e){self::assertSame('ANALYST_MASTER_COLUMNS_ALREADY_EXIST',$e->getMessage());}
            DB::statement('ALTER TABLE ppb_accounts ADD email VARCHAR(255) NULL');
            DB::statement('CREATE TABLE ppb_roles (id BIGINT PRIMARY KEY,code VARCHAR(40)) ENGINE=InnoDB');
            DB::statement('CREATE TABLE ppb_account_roles (account_id BIGINT,role_id BIGINT) ENGINE=InnoDB');
            DB::table('ppb_accounts')->insert(['id'=>51,'name'=>'Synthetic account','username'=>'synthetic-optional','email'=>'synthetic@example.invalid','is_active'=>1]);
            DB::table('ppb_roles')->insert(['id'=>1,'code'=>'analyst']);DB::table('ppb_account_roles')->insert(['account_id'=>51,'role_id'=>1]);
            DB::statement('ALTER TABLE ppb_legacy_pick_contents ADD legacy_analyst_id BIGINT NULL, ADD recommend_pick TEXT NULL');DB::statement('ALTER TABLE ppb_legacy_wp_articles ADD wp_link TEXT NULL');EvaluationIntegrationFixture::create();
            $reader=Mockery::mock(LegacyReader::class);$reader->shouldReceive('profiles')->andReturn([]);$this->app->instance(LegacyReader::class,$reader);Http::preventStrayRequests();
            $this->postJson(self::HOST.'/admin/analysts',[])->assertUnauthorized();$this->actor(false,false);$this->postJson(self::HOST.'/admin/analysts',[])->assertForbidden();
            $this->actor(true);$input=['name'=>'Synthetic profile','display_name'=>'Public label','status'=>'active','user_type'=>1,'account_id'=>null,'internal_notes'=>'Synthetic note','color'=>'#12Ab34','background'=>'#FFE5A0','wp_photo'=>'123','author_id'=>'0','rating_level'=>3,'analyst_level'=>'1','line_id'=>'synthetic-private-id','line_name'=>'Synthetic private display'];
            $this->postJson(self::HOST.'/admin/analysts',$input)->assertRedirect();$id=(int)DB::table('ppb_analysts')->max('id');$model=PpbAnalyst::findOrFail($id);
            foreach(['name','display_name','color','background','line_id','line_name'] as $key)self::assertSame($input[$key],$model->$key);
            self::assertSame(123,$model->wp_photo);self::assertSame(0,$model->author_id);self::assertSame(3,$model->rating_level);self::assertSame(1,$model->analyst_level);self::assertNull($model->account_id);
            self::assertArrayNotHasKey('line_id',$model->toArray());self::assertArrayNotHasKey('line_name',$model->toArray());
            $this->get(self::HOST.'/admin/analysts/'.$id.'/edit')->assertOk()->assertSee('synthetic-private-id')->assertSee('Synthetic private display');
            $this->actor();$form=$this->get(self::HOST.'/admin/analysts/'.$id.'/edit')->assertOk()->assertDontSee('synthetic-private-id')->assertDontSee('Synthetic private display');
            self::assertNull($form->viewData('analyst')->getRawOriginal('line_id'));
            $this->get(self::HOST.'/admin/analysts')->assertOk()->assertDontSee('synthetic-private-id')->assertDontSee('Synthetic private display');
            $this->update($id,['line_id'=>null])->assertForbidden();self::assertSame('synthetic-private-id',PpbAnalyst::findOrFail($id)->line_id);
            // Account NULL still participates in actual scheduling and manuscript HTTP.
            $game=(new ScheduleManager)->create(['month_key'=>'2026-11','game_type'=>1,'recommended_at'=>'2026-11-20 03:00:00','market_type'=>1,'reviewer_analyst_id'=>$id,'matchup_text'=>'Synthetic profile match'],['short'=>[1=>$id]]);
            $aid=(int)$game->assignments()->first()->id;
            $token=$this->get(self::HOST.'/admin/games/'.$game->id.'/manuscripts')->assertOk()->viewData('tokens')[$aid];
            $this->postJson(self::HOST.'/admin/manuscripts/assignments/'.$aid.'/save',['mode'=>'submit','revision_token'=>$token,'recommend_pick'=>'Synthetic pick','analysis_text'=>'Synthetic manuscript'])->assertRedirect();
            $w2Before=$this->get(self::HOST.'/admin/games/'.$game->id.'/manuscripts')->assertOk()->viewData('tokens')[$aid];
            $history=DB::table('ppb_submissions')->get()->toJson();$assignments=DB::table('ppb_assignments')->get()->toJson();$version=ScheduleVersion::game(PpbGame::findOrFail($game->id));
            $subjects=$this->app->make(EvaluationSubjects::class);$subject=$subjects->get('v2',$id);self::assertSame(3,$subject['rating_level']);
            $service=$this->app->make(EvaluationService::class);$report=$service->load($subject,'2026-11');
            $this->app->make(\App\Services\Ppb\Evaluation\EvaluationWriter::class)->save($subject,'2026-11','salary_snapshot',[],(string)\Illuminate\Support\Str::uuid(),$report['revision'],$report['source_sha256'],41);
            $report=$service->load($subject,'2026-11');self::assertNotNull($report['saved_salary']);
            $this->update($id,['name'=>'Updated public label','color'=>'#ABCDEF','rating_level'=>1])->assertRedirect();
            $current=PpbAnalyst::findOrFail($id);self::assertSame(123,$current->wp_photo);self::assertSame(0,$current->author_id);self::assertSame('synthetic-private-id',$current->line_id);self::assertSame('#FFE5A0',$current->background);self::assertSame(1,$current->analyst_level);
            self::assertSame($history,DB::table('ppb_submissions')->get()->toJson());self::assertSame($assignments,DB::table('ppb_assignments')->get()->toJson());self::assertSame($version,ScheduleVersion::game(PpbGame::findOrFail($game->id)));
            self::assertSame($w2Before,$this->get(self::HOST.'/admin/games/'.$game->id.'/manuscripts')->assertOk()->viewData('tokens')[$aid]);
            $this->get(self::HOST.'/admin/evaluations/v2/'.$id.'?month=2026-11')->assertOk()->assertDontSee('synthetic-private-id')->assertDontSee('Synthetic private display');
            $fresh=$service->load($subjects->get('v2',$id),'2026-11');self::assertSame($report['source_sha256'],$fresh['source_sha256']);self::assertSame($report['current']['salary'],$fresh['current']['salary']);self::assertSame($report['saved_salary'],$fresh['saved_salary']);
            self::assertSame('#ABCDEF',$current->scheduleColors()['color']);self::assertSame('#FFE5A0',$current->scheduleColors()['background']);
            $page=$this->get(self::HOST.'/admin/games?month=2026-11')->assertOk()->assertSee('color:#ABCDEF',false)->assertSee('background-color:#FFE5A0',false)->assertDontSee('synthetic-private-id');
            self::assertGreaterThanOrEqual(2,substr_count($page->getContent(),'color:#ABCDEF'),'Current analyst and reviewer must use their own master color.');
            // Explicit null clears only the requested associations; omission preserves.
            $this->actor(true);$this->update($id,['account_id'=>51,'wp_photo'=>456,'author_id'=>789,'line_id'=>'synthetic-rebound','line_name'=>'Synthetic rebound','rating_level'=>0])->assertRedirect();
            $this->actor();$this->update($id,['display_name'=>'Independent label'])->assertRedirect();$m=PpbAnalyst::findOrFail($id);self::assertSame(51,(int)$m->account_id);self::assertSame(456,$m->wp_photo);self::assertSame(789,$m->author_id);self::assertSame(0,$m->rating_level);self::assertSame('#FFE5A0',$m->background);self::assertSame(1,$m->analyst_level);
            $this->get(self::HOST.'/admin/analysts')->assertOk()->assertSee('synthetic-optional')->assertDontSee('synthetic-rebound');
            foreach([['wp_photo'=>true],['author_id'=>false],['rating_level'=>true],['wp_photo'=>1.2],['wp_photo'=>0],['wp_photo'=>'https://example.invalid/photo'],['author_id'=>-1],['author_id'=>'9223372036854775808'],['rating_level'=>4],['color'=>'red;display:none'],['color'=>'#123'],['id'=>999],['background'=>'Biography'],['background'=>'#123'],['analyst_level'=>2],['analyst_level'=>true],['analyst_level'=>1.5],['name'=>str_repeat('n',101)]] as $invalid){
                $old=DB::table('ppb_analysts')->where('id',$id)->first();$this->update($id,$invalid)->assertUnprocessable();self::assertEquals($old,DB::table('ppb_analysts')->where('id',$id)->first());
            }
            $this->actor(true);foreach([['line_id'=>str_repeat('i',256)],['line_name'=>"bad\nname"]] as $bad)$this->update($id,$bad)->assertUnprocessable();
            // HTML validation failure must not flash private input into session.
            $this->travel(61)->seconds();$this->put(self::HOST.'/admin/analysts/'.$id,['name'=>'','status'=>'active','user_type'=>1,'line_id'=>'synthetic-never-flash','line_name'=>'Synthetic never flash'])->assertRedirect();
            self::assertNull(session('_old_input.line_id'));self::assertNull(session('_old_input.line_name'));
            $failSql=true;
            DB::connection()->beforeExecuting(function($sql,$bindings)use(&$failSql){if($failSql&&str_starts_with($sql,'update `ppb_analysts`'))throw new \Illuminate\Database\QueryException('mysql',$sql,$bindings,new \PDOException('SYNTHETIC_DATABASE_FAILURE'));});
            $this->withoutExceptionHandling();
            try{$this->update($id,['line_id'=>'synthetic-private-sql-fault']);self::fail('Database failure required.');}
            catch(\RuntimeException $e){self::assertSame('ANALYST_MASTER_WRITE_FAILED_DB_0',$e->getMessage());self::assertNull($e->getPrevious(),'Never chain private SQL/bindings into ordinary error reports.');}
            finally{$failSql=false;$this->withExceptionHandling();}
            $beforeFailed=DB::table('ppb_analysts')->where('id',$id)->first();$auditCount=DB::table('ppb_auth_audits')->count();$failAudit=true;
            DB::listen(function($q)use(&$failAudit){if($failAudit&&str_starts_with($q->sql,'insert into `ppb_auth_audits`'))throw new \RuntimeException('ANALYST_AUDIT_FAILURE');});
            $this->update($id,['color'=>'#123456','line_id'=>'synthetic-private-failed'])->assertStatus(500)->assertDontSee('synthetic-private-failed');$failAudit=false;
            self::assertEquals($beforeFailed,DB::table('ppb_analysts')->where('id',$id)->first());self::assertSame($auditCount,DB::table('ppb_auth_audits')->count());self::assertSame(0,DB::connection()->transactionLevel());
            $this->update($id,['line_id'=>null,'line_name'=>'','color'=>null,'background'=>null,'wp_photo'=>null,'author_id'=>null,'rating_level'=>null,'analyst_level'=>null,'account_id'=>null])->assertRedirect();$cleared=PpbAnalyst::findOrFail($id);
            foreach(['line_id','line_name','color','background','wp_photo','author_id','rating_level','analyst_level','account_id'] as $field)self::assertNull($cleared->$field);
            $audits=DB::table('ppb_auth_audits')->get()->toJson();foreach(['synthetic-private-id','Synthetic private display','synthetic-rebound','synthetic-never-flash'] as $secret)self::assertStringNotContainsString($secret,$audits);
            try{$migration->down();self::fail('Cannot silently destroy source associations.');}catch(\LogicException $e){self::assertSame('ANALYST_MASTER_ROLLBACK_REQUIRES_REVIEW',$e->getMessage());}
            self::assertSame(4,(int)DB::selectOne('SELECT COUNT(*) n FROM information_schema.SCHEMATA')->n);$pass=true;
        }finally{
            $this->travelBack();DB::disconnect('mysql');unset($pdo);$container->destroy();self::assertFalse($container->exists());self::assertDirectoryDoesNotExist($container->directory);
            file_put_contents((getenv('RUNNER_TEMP')?:sys_get_temp_dir()).'/ppb-analyst-master-receipt.json',json_encode(['result'=>$pass?'PASS':'FAIL','isolation'=>$container->proof,'checks'=>['confirmed_fields'=>['color','background','wp_photo','author_id','line_id','line_name','rating_level','analyst_level'],'http_crud'=>true,'private_permissions'=>true,'null_account_scheduling'=>true,'history_and_salary_unchanged'=>true,'audit_rollback'=>true,'candidate_migration_only'=>true],'unverified_fields'=>['analyst_level_labels'],'private_values_exported'=>false,'production_connections'=>0,'external_http_calls'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
        }
    }
}
