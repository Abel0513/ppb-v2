<?php
// Test-only CLI worker. No request-accessible API, production credentials or URLs.
$root=dirname(__DIR__,2);
$_ENV['APP_BASE_PATH']=$_SERVER['APP_BASE_PATH']=$root;putenv('APP_BASE_PATH='.$root);
$loader=require $root.'/vendor/autoload.php';
$loader->setPsr4('App\\',$root.'/app');$loader->setPsr4('Tests\\',$root.'/tests');
$map=[];foreach($loader->getClassMap() as $c=>$file)foreach(['App\\'=>'app/','Tests\\'=>'tests/'] as $prefix=>$dir)if(str_starts_with($c,$prefix))$map[$c]=$root.'/'.$dir.str_replace('\\','/',substr($c,strlen($prefix))).'.php';$loader->addClassMap($map);
function send(array $value):void{fwrite(STDOUT,json_encode($value,JSON_THROW_ON_ERROR)."\n");fflush(STDOUT);}
function command():array{$read=[STDIN];$write=$except=[];if(stream_select($read,$write,$except,15)!==1)throw new RuntimeException('TEST_BARRIER_TIMEOUT');$line=fgets(STDIN);if($line===false)throw new RuntimeException('TEST_PARENT_GONE');$value=json_decode($line,true,32,JSON_THROW_ON_ERROR);if(isset($value['command']))send(['type'=>'command_ack','stage'=>$value['command']]);return $value;}
$trace=[];$starts=[];$begins=0;$paused=false;$spec=[];
try{
    $spec=command();$socket=$spec['socket']??'';
    if(getenv('APP_ENV')!=='testing'||!preg_match('~^/tmp/ppb-concurrent-[a-f0-9]{12}/mysqld.sock$~D',$socket))throw new RuntimeException('TEST_ONLY_SOCKET_REQUIRED');
    $app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['app.url'=>'https://v2.csdatw.xyz','database.default'=>'mysql','database.connections.mysql'=>[
        'driver'=>'mysql','host'=>'127.0.0.1','port'=>1,'database'=>'mysql','username'=>'root','password'=>'','unix_socket'=>$socket,'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true],
        'session.driver'=>'array','cache.default'=>'array','logging.default'=>'null']);
    Illuminate\Support\Facades\DB::purge('mysql');$db=Illuminate\Support\Facades\DB::connection();$pdo=$db->getPdo();
    $server=$db->selectOne('SELECT VERSION() v,@@datadir d,@@tmpdir t,@@server_uuid u,CONNECTION_ID() id');
    $guard=$pdo->query('SELECT nonce FROM ppb_concurrency_fixture WHERE id=1')->fetchColumn();
    if($server->v!=='5.7.44'||$server->d!=='/var/lib/mysql/'||$server->t!=='/tmp'||!hash_equals($spec['nonce'],$guard)||$server->u!==$spec['server_uuid'])throw new RuntimeException('TEST_DISPOSABLE_IDENTITY_NOT_PROVEN');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout='.(int)($spec['lock_wait']??5));
    $actor=new class extends App\Models\PpbAccount{public function hasPpbPermission(string $permission):bool{return $this->is_active&&in_array($permission,['schedules.view','schedules.manage'],true);}};
    $actor->forceFill(['id'=>$spec['actor']??41,'name'=>'Fixture staff','username'=>'fixture','is_active'=>true]);Illuminate\Support\Facades\Auth::guard('ppb')->setUser($actor);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    $op=$spec['op'];$gid=(int)($spec['game']??1);$aid=(int)($spec['assignment']??1);
    $game=App\Models\PpbGame::findOrFail($gid);$assignment=App\Models\PpbAssignment::find($aid);
    $manager=new App\Services\Ppb\ScheduleManager;$manuscript=new App\Http\Controllers\Ppb\ManuscriptController;$assignments=new App\Http\Controllers\Ppb\AssignmentController;
    $version=App\Services\Ppb\ScheduleVersion::game($game);
    $assignmentVersion=$assignment?App\Services\Ppb\ScheduleVersion::assignment($assignment):null;
    $view=$manuscript->show($game);$tokens=$view->getData()['tokens'];
    $token=$tokens[$aid]??null;$submission=(int)($spec['submission']??1);
    $pause=static function(string $stage)use(&$paused,$spec,$server):void{
        if(!$paused&&($spec['pause']??'')===$stage){$paused=true;send(['type'=>'barrier','stage'=>$stage,'session'=>(int)$server->id]);if((command()['command']??'')!=='continue')throw new RuntimeException('TEST_BARRIER_COMMAND_INVALID');}
    };
    $label=static function(string $sql):?string{
        $s=strtolower($sql);
        if(str_contains($s,'for update'))foreach(['ppb_schedule_scopes'=>'scope_lock','ppb_games'=>'game_lock','ppb_assignments'=>'assignment_lock','ppb_submissions'=>'submission_lock','ppb_publications'=>'publication_lock'] as $table=>$name)if(str_contains($s,'`'.$table.'`'))return $name;
        if(preg_match('/^(insert|update|delete) /',$s))foreach(['ppb_submissions'=>'submission_write','ppb_assignments'=>'assignment_write','ppb_substitutions'=>'substitution_write','ppb_publications'=>'publication_write','ppb_auth_audits'=>'audit_write','ppb_schedule_events'=>'event_write','ppb_games'=>'game_write'] as $table=>$name)if(str_contains($s,'`'.$table.'`'))return $name;
        return null;
    };
    $db->beforeExecuting(function($sql)use(&$starts,$label){if($name=$label($sql)){$starts[$name]=($starts[$name]??0)+1;send(['type'=>'query_start','stage'=>$name]);}});
    $app['events']->listen(Illuminate\Database\Events\TransactionBeginning::class,function()use(&$begins){$begins++;});
    $db->listen(function($q)use(&$trace,$label,$pause,$spec){
        if($name=$label($q->sql)){$trace[]=$name;$pause($name);}
        if(($spec['pause']??'')==='first_submission_read'&&str_starts_with(strtolower($q->sql),'select')&&str_contains($q->sql,'`ppb_submissions`')&&!str_contains(strtolower($q->sql),'for update'))$pause('first_submission_read');
        $auditInsert=str_starts_with($q->sql,'insert into `ppb_auth_audits`')||str_starts_with($q->sql,'insert into `ppb_schedule_events`');
        $failure=$spec['fail_audit']??false;
        if($auditInsert&&($failure===true||($failure==='compaction'&&in_array('renumber.compact',$q->bindings,true))))throw new RuntimeException('INJECTED_CONCURRENT_AUDIT_FAILURE');
    });
    send(['type'=>'ready','session'=>(int)$server->id,'shared_guard'=>true,'shared_guard_sha256'=>hash('sha256',$guard),'shared_game_id'=>(int)$game->id]);
    if((command()['command']??'')!=='start')throw new RuntimeException('TEST_START_REQUIRED');
    if($spec['outer_snapshot']??false){$db->beginTransaction();$db->table('ppb_games')->where('id',$gid)->value('notes');$pause('snapshot');}
    $request=static fn(array $data)=>Illuminate\Http\Request::create('https://v2.csdatw.xyz/admin/test','POST',$data);
    $result=null;
    switch($op){
        case 'save':$result=$manuscript->save($request(['mode'=>'submit','revision_token'=>$token,'recommend_pick'=>'Fixture choice','analysis_text'=>$spec['body']??'Fixture body']),$assignment);break;
        case 'review':$result=$manuscript->review($request(['decision'=>$spec['decision']??'approved','review_note'=>'Fixture feedback','revision_token'=>$token]),$submission);break;
        case 'return_resubmit':
            $manuscript->review($request(['decision'=>'changes_requested','review_note'=>'Fixture revision','revision_token'=>$token]),$submission);
            $fresh=$manuscript->show(App\Models\PpbGame::findOrFail($gid))->getData()['tokens'][$aid];
            $result=$manuscript->save($request(['mode'=>'submit','revision_token'=>$fresh,'recommend_pick'=>'Fixture choice','analysis_text'=>'Revised fixture body']),App\Models\PpbAssignment::findOrFail($aid));break;
        case 'substitute':$result=$assignments->substitute($request(['to_analyst_id'=>$spec['target']??10,'reason'=>'Fixture substitute','revision_token'=>$assignmentVersion]),$assignment);break;
        case 'status':$result=$assignments->status($request(['status'=>$spec['status']??'completed','revision_token'=>$assignmentVersion]),$assignment);break;
        case 'cancel':$manager->cancel($game,'Fixture cancellation',$version);break;
        case 'update':
            $slots=['short'=>[]];foreach($game->assignments()->where('kind','pick')->get() as $a)$slots['short'][$a->slot_no]=(int)$a->current_analyst_id;
            $manager->update($game,['month_key'=>$game->month_key,'game_type'=>$game->game_type,'notes'=>$spec['notes']??'Fixture update'],$slots,$version);break;
        case 'append':$manager->create(['month_key'=>$game->month_key,'game_type'=>$game->game_type,'matchup_text'=>'Appended fixture'],['short'=>[]]);break;
        case 'insert_before':$manager->create(['month_key'=>$game->month_key,'game_type'=>$game->game_type,'matchup_text'=>'Inserted fixture'],['short'=>[]],'before',$gid);break;
        case 'wp_verified_fixture':(new App\Services\Ppb\VerifiedWpRecorder)->recordVerifiedPublication($game,'fixture.invalid','fixture-post');break;
        case 'wp_pending_fixture':
            $db->transaction(function()use($db,$game){
                $db->table('ppb_schedule_scopes')->where('month_key',$game->month_key)->where('game_type',$game->game_type)->lockForUpdate()->first();
                App\Models\PpbGame::whereKey($game->id)->lockForUpdate()->firstOrFail();
                $db->table('ppb_publications')->insert(['game_id'=>$game->id,'wp_site'=>'fixture.invalid','wp_post_id'=>'fixture-pending','status'=>'uploading']);
            });break;
        case 'reverse_probe':
            $db->beginTransaction();
            foreach($spec['order'] as $part){if($part==='game')App\Models\PpbGame::whereKey($gid)->lockForUpdate()->firstOrFail();else App\Models\PpbAssignment::whereKey($aid)->lockForUpdate()->firstOrFail();}
            $db->table('ppb_games')->where('id',$gid)->update(['notes'=>'Rollback probe']);$db->rollBack();break;
        default:throw new RuntimeException('TEST_OPERATION_NOT_ALLOWED');
    }
    if($spec['outer_snapshot']??false)$db->commit();
    send(['type'=>'done','status'=>$result instanceof Symfony\Component\HttpFoundation\Response?$result->getStatusCode():200,'driver_code'=>null,'transaction_level'=>$db->transactionLevel(),'pdo_transaction'=>$pdo->inTransaction(),'trace'=>$trace,'starts'=>$starts,'begins'=>$begins]);
}catch(Throwable $e){
    try{if(isset($db))while($db->transactionLevel()>0)$db->rollBack();}catch(Throwable $ignored){}
    $status=$e instanceof Symfony\Component\HttpKernel\Exception\HttpExceptionInterface?$e->getStatusCode():($e instanceof Illuminate\Validation\ValidationException?422:500);
    $driver=$e instanceof Illuminate\Database\QueryException?(int)($e->errorInfo[1]??0):($e instanceof PDOException?(int)($e->errorInfo[1]??0):null);
    send(['type'=>'done','status'=>$status,'driver_code'=>$driver,'error'=>preg_match('/^[A-Z][A-Z_0-9]{0,80}$/D',$e->getMessage())?$e->getMessage():'TEST_OPERATION_REJECTED','error_class'=>get_class($e),'line'=>$e->getLine(),'file'=>basename($e->getFile()),'transaction_level'=>isset($db)?$db->transactionLevel():0,'pdo_transaction'=>isset($pdo)?$pdo->inTransaction():false,'trace'=>$trace,'starts'=>$starts,'begins'=>$begins]);
}
