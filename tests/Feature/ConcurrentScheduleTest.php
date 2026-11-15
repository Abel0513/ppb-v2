<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\{DisposableMysql57,ScheduleSharedFixture,ConcurrentSchedulePeer};

/** Real independent PHP/PDO sessions over shared InnoDB rows in disposable RAM. */
class ConcurrentScheduleTest extends TestCase
{
    private array $peers=[];
    private array $evidence=[];
    private array $lastOperation=[];
    private \PDO $observer;
    private array $identity;
    private function peer(array $spec):ConcurrentSchedulePeer
    {
        $p=new ConcurrentSchedulePeer($spec+$this->identity);$this->peers[]=$p;
        self::assertTrue($p->ready['shared_guard']);self::assertSame(hash('sha256',$this->identity['nonce']),$p->ready['shared_guard_sha256']);self::assertNotSame((int)$this->observer->query('SELECT CONNECTION_ID()')->fetchColumn(),$p->ready['session']);return $p;
    }
    private function state():string
    {
        $state=[];foreach(['ppb_games','ppb_assignments','ppb_submissions','ppb_substitutions','ppb_schedule_events','ppb_auth_audits','ppb_publications'] as $table)$state[$table]=$this->observer->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));
    }
    private function scalar(string $sql){return $this->observer->query($sql)->fetchColumn();}
    private function finish(ConcurrentSchedulePeer $peer,int $expected,string $case):array
    {
        $result=$peer->take('done');$this->lastOperation=['case'=>$case,'result'=>$result];self::assertSame($expected,$result['status'],$case.' '.json_encode($result));self::assertSame(0,$result['transaction_level']);self::assertFalse($result['pdo_transaction']);self::assertNull($result['driver_code']);
        if($expected===500)self::assertSame('INJECTED_CONCURRENT_AUDIT_FAILURE',$result['error']);
        $g=array_search('game_lock',$result['trace'],true);$a=array_search('assignment_lock',$result['trace'],true);$scope=array_search('scope_lock',$result['trace'],true);
        if($a!==false){if($g===false&&str_contains($case,'next_number'))$g=array_search('game_write',$result['trace'],true);self::assertNotFalse($g);self::assertLessThan($a,$g,'GAME must precede ASSIGNMENT');}
        if($scope!==false&&$g!==false)self::assertLessThan($g,$scope,'SCOPE must precede GAME');
        return $result;
    }
    private function waitFor(ConcurrentSchedulePeer $waiter,ConcurrentSchedulePeer $blocker):array
    {
        $sql='SELECT r.trx_mysql_thread_id waiting,b.trx_mysql_thread_id blocking,l.lock_table FROM information_schema.innodb_lock_waits w JOIN information_schema.innodb_trx r ON r.trx_id=w.requesting_trx_id JOIN information_schema.innodb_trx b ON b.trx_id=w.blocking_trx_id JOIN information_schema.innodb_locks l ON l.lock_id=w.requested_lock_id';
        $until=microtime(true)+5;
        do{
            foreach($this->observer->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row)if((int)$row['waiting']===$waiter->ready['session']&&(int)$row['blocking']===$blocker->ready['session'])return ['table'=>$row['lock_table']];
            if($done=$waiter->done())self::fail('EXPECTED_REAL_LOCK_WAIT_BUT_WORKER_COMPLETED '.json_encode($done));
            if(microtime(true)>$until)self::fail('INNODB_LOCK_WAIT_NOT_OBSERVED '.json_encode(['waiter'=>$waiter->ready,'blocker'=>$blocker->ready,'waits'=>$this->observer->query($sql)->fetchAll(\PDO::FETCH_ASSOC),'trx'=>$this->observer->query('SELECT trx_mysql_thread_id,trx_state FROM information_schema.innodb_trx')->fetchAll(\PDO::FETCH_ASSOC),'processes'=>$this->observer->query('SELECT ID,COMMAND,STATE FROM information_schema.PROCESSLIST')->fetchAll(\PDO::FETCH_ASSOC),'peer'=>$waiter->debug()]));usleep(150000);
        }while(true);
    }
    private function clear():void{foreach($this->peers as $p)$p->stop();$this->peers=[];ScheduleSharedFixture::reset($this->observer);}
    private function seedSubmission():void
    {
        $this->observer->exec("INSERT INTO ppb_submissions(id,game_id,assignment_id,kind,submitted_analyst_id,status,recommend_pick,analysis_text,submitted_at) VALUES(1,1,1,'pick',9,'submitted','Fixture choice','Fixture body','2026-11-16 00:00:00')");
    }
    private function pair(string $name,array $aSpec,array $bSpec,int $aStatus,int $bStatus,string $barrier='game_lock'):array
    {
        $a=$this->peer($aSpec+['actor'=>41,'pause'=>$barrier]);$b=$this->peer($bSpec+['actor'=>42]);self::assertNotSame($a->ready['session'],$b->ready['session']);
        $a->send('start');$a->take('barrier',$barrier);$b->send('start');$start=microtime(true);$wait=$this->waitFor($b,$a);$a->send('continue');
        $ar=$this->finish($a,$aStatus,$name.' A');$br=$this->finish($b,$bStatus,$name.' B');
        $ms=(int)round((microtime(true)-$start)*1000);self::assertLessThan(10000,$ms);
        $this->evidence[]=['case'=>$name,'sessions'=>[$a->ready['session'],$b->ready['session']],'shared_guard'=>true,'wait'=>$wait,'statuses'=>[$ar['status'],$br['status']],'wait_ms'=>$ms,'rollback_clean'=>true];return [$ar,$br];
    }
    private function snapshotPair(string $name,array $bSpec,array $aSpec,int $expectedB,string $barrier):void
    {
        $b=$this->peer($bSpec+['actor'=>42,'pause'=>$barrier]);$a=$this->peer($aSpec+['actor'=>41]);self::assertNotSame($a->ready['session'],$b->ready['session']);
        $b->send('start');$b->take('barrier',$barrier);$a->send('start');$ar=$this->finish($a,($aSpec['op']==='return_resubmit'||$aSpec['op']==='save'||$aSpec['op']==='substitute')?302:200,$name.' A');
        $b->send('continue');$br=$this->finish($b,$expectedB,$name.' B');
        $this->evidence[]=['case'=>$name,'sessions'=>[$a->ready['session'],$b->ready['session']],'order'=>'B snapshot → A commit → B current read','statuses'=>[$ar['status'],$br['status']],'rollback_clean'=>true];
    }
    public function test_real_two_session_shared_row_application_interleavings():void
    {
        $container=new DisposableMysql57;$passed=false;
        try{
            $this->observer=$container->pdo();$nonce=bin2hex(random_bytes(16));ScheduleSharedFixture::create($this->observer,$nonce);
            $this->identity=['socket'=>$container->socket(),'nonce'=>$nonce,'server_uuid'=>$this->scalar('SELECT @@server_uuid')];$container->arm(240);
            self::assertSame(0,(int)$this->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='mysql' AND TABLE_NAME LIKE 'ppb_%' AND ENGINE!='InnoDB'"));
            $this->clear();$this->snapshotPair('old_snapshot_next_number',['op'=>'append','outer_snapshot'=>true],['op'=>'append'],200,'snapshot');
            self::assertSame(4,(int)$this->scalar('SELECT MAX(active_number) FROM ppb_games WHERE game_type=1'));
            $this->clear();$this->seedSubmission();
            $this->snapshotPair('stale_review_after_resubmission',['op'=>'review'],['op'=>'return_resubmit'],409,'first_submission_read');
            self::assertSame('submitted',$this->scalar('SELECT status FROM ppb_submissions WHERE id=1'));
            $this->clear();
            $this->snapshotPair('old_snapshot_pending_cancel',['op'=>'cancel','outer_snapshot'=>true],['op'=>'wp_pending_fixture'],422,'snapshot');
            self::assertSame('scheduled',$this->scalar('SELECT status FROM ppb_games WHERE id=1'));
            $this->clear();
            $this->snapshotPair('old_snapshot_submitted_insert',['op'=>'insert_before','game'=>2,'assignment'=>3,'outer_snapshot'=>true],['op'=>'save','game'=>2,'assignment'=>3],422,'snapshot');
            self::assertSame(2,(int)$this->scalar('SELECT game_number FROM ppb_games WHERE id=2'));
            $this->clear();$this->snapshotPair('old_snapshot_duplicate_target',['op'=>'substitute','assignment'=>2,'target'=>10,'outer_snapshot'=>true],['op'=>'substitute','target'=>10],422,'snapshot');
            $this->clear();$this->pair('submit_then_substitute',['op'=>'save'],['op'=>'substitute','target'=>10],302,302);
            self::assertSame(9,(int)$this->scalar('SELECT submitted_analyst_id FROM ppb_submissions'));self::assertSame(10,(int)$this->scalar('SELECT current_analyst_id FROM ppb_assignments WHERE id=1'));
            $this->clear();$this->pair('substitute_then_submit',['op'=>'substitute','target'=>10],['op'=>'save'],302,409);
            self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM ppb_submissions'));
            $this->clear();$this->seedSubmission();$this->pair('review_then_complete',['op'=>'review'],['op'=>'status','status'=>'completed'],302,302);
            self::assertSame('approved',$this->scalar('SELECT status FROM ppb_submissions'));self::assertSame('completed',$this->scalar('SELECT status FROM ppb_assignments WHERE id=1'));
            $this->clear();$this->seedSubmission();$this->pair('complete_then_review',['op'=>'status','status'=>'completed'],['op'=>'review'],302,409);
            self::assertSame('submitted',$this->scalar('SELECT status FROM ppb_submissions'));
            foreach(['substitute','save'] as $operation){$this->clear();$this->pair('cancel_then_'.$operation,['op'=>'cancel'],['op'=>$operation],200,422);self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM ppb_substitutions'));self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM ppb_submissions'));}
            foreach(['wp_pending_fixture','wp_verified_fixture'] as $wp)foreach(['substitute','save'] as $operation){
                $this->clear();$this->pair($wp.'_then_'.$operation,['op'=>$wp],['op'=>$operation],200,422,$wp==='wp_pending_fixture'?'publication_write':'game_lock');
                self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM ppb_submissions'));self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM ppb_substitutions'));
            }
            $this->clear();$this->seedSubmission();$this->snapshotPair('review_snapshot_pending_wp',['op'=>'review'],['op'=>'wp_pending_fixture'],422,'first_submission_read');
            $this->clear();$this->pair('two_slots_same_target',['op'=>'substitute','target'=>10],['op'=>'substitute','assignment'=>2,'target'=>10],302,422);
            self::assertSame(1,(int)$this->scalar('SELECT COUNT(*) FROM ppb_assignments WHERE game_id=1 AND current_analyst_id=10'));
            $this->clear();$this->observer->exec("UPDATE ppb_assignments SET status='completed' WHERE id=1");
            $this->pair('substitute_then_conflicting_restore',['op'=>'substitute','assignment'=>2,'target'=>9],['op'=>'status','status'=>'active'],302,422);
            $this->clear();$this->pair('same_scope_different_game',['op'=>'update'],['op'=>'update','game'=>2,'assignment'=>3],200,200,'scope_lock');
            $this->clear();$this->pair('renumber_stales_waiting_edit',['op'=>'cancel'],['op'=>'update','game'=>2,'assignment'=>3],200,409,'scope_lock');
            $this->clear();
            $a=$this->peer(['op'=>'update','pause'=>'scope_lock']);$b=$this->peer(['op'=>'update','game'=>3,'actor'=>42]);$a->send('start');$a->take('barrier','scope_lock');$b->send('start');$this->finish($b,200,'different_scope');$a->send('continue');$this->finish($a,200,'different_scope');
            $this->evidence[]=['case'=>'different_scope_no_block','sessions'=>[$a->ready['session'],$b->ready['session']],'B_committed_while_A_held_scope'=>true];
            $this->clear();$this->pair('failed_substitution_then_other_writer',['op'=>'substitute','target'=>10,'fail_audit'=>true],['op'=>'substitute','target'=>12],500,302);
            self::assertSame(1,(int)$this->scalar('SELECT COUNT(*) FROM ppb_substitutions'));self::assertSame(12,(int)$this->scalar('SELECT current_analyst_id FROM ppb_assignments WHERE id=1'));
            $this->clear();$this->pair('failed_submission_then_substitute',['op'=>'save','fail_audit'=>true],['op'=>'substitute'],500,302);
            self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM ppb_submissions'));
            $this->clear();$before=$this->state();
            $this->pair('compaction_audit_failure_then_waiting_edit',['op'=>'cancel','fail_audit'=>'compaction'],['op'=>'update','game'=>2,'assignment'=>3],500,200,'scope_lock');
            self::assertSame('scheduled',$this->scalar('SELECT status FROM ppb_games WHERE id=1'));
            self::assertSame(1,(int)$this->scalar('SELECT game_number FROM ppb_games WHERE id=1'));
            self::assertSame(2,(int)$this->scalar('SELECT game_number FROM ppb_games WHERE id=2'));
            self::assertSame(0,(int)$this->scalar("SELECT COUNT(*) FROM ppb_schedule_events WHERE event IN ('game.cancelled','renumber.compact')"));
            $this->timeoutAndRefresh();$this->negativeDeadlockProbe();
            self::assertSame(4,(int)$this->scalar('SELECT COUNT(*) FROM information_schema.SCHEMATA'));
            $container->proof['shared_innodb_tables']=(int)$this->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='mysql' AND TABLE_NAME LIKE 'ppb_%' AND ENGINE='InnoDB'");
            $passed=true;
        }catch(\Throwable $e){$this->lastOperation['isolation_runtime']=$container->runtime();throw $e;}finally{
            foreach($this->peers as $p)$p->stop();$this->peers=[];
            if(isset($this->observer))unset($this->observer);$container->destroy();
            self::assertFalse($container->exists());self::assertDirectoryDoesNotExist($container->directory);
            // Metadata-only receipt: no fixture rows/bodies/credentials on host.
            $receipt=['format'=>'ppb-shared-innodb-concurrency-v1','result'=>$passed?'PASS':'FAIL','isolation'=>$container->proof,'cases'=>$this->evidence,'last_operation'=>$this->lastOperation,'wp_http_calls'=>0,'production_connections'=>0];
            file_put_contents((getenv('RUNNER_TEMP')?:sys_get_temp_dir()).'/ppb-concurrency-receipt.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
        }
    }
    public function test_disposable_cleanup_on_failure_signals_and_deadline():void
    {
        $results=[];
        try{foreach(['fail','term','kill','timeout'] as $mode){
            $input=new \Symfony\Component\Process\InputStream;
            $process=new \Symfony\Component\Process\Process([getenv('PPB_TEST_PHP')?:PHP_BINARY,dirname(__DIR__).'/Support/DisposableMysqlLifecycle.php'],dirname(__DIR__,2),['APP_ENV'=>'testing']);
            $process->setInput($input);$process->setTimeout(75);$process->start();$buffer='';$ready=null;
            try{
                $until=microtime(true)+65;
                while(!$ready){
                    $buffer.=$process->getIncrementalOutput();
                    while(($n=strpos($buffer,"\n"))!==false){$event=json_decode(substr($buffer,0,$n),true);$buffer=substr($buffer,$n+1);if(($event['type']??'')==='ready')$ready=$event;}
                    if(microtime(true)>$until||!$process->isRunning())self::fail('LIFECYCLE_START_FAILED');usleep(10000);
                }
                self::assertSame(1,$ready['sentinel_rows']);self::assertSame('none',$ready['isolation']['network']);self::assertTrue($ready['isolation']['datadir_tmpfs']);
                if($mode==='term'||$mode==='kill')$process->signal($mode==='term'?15:9);
                else{$input->write($mode."\n");$process->isRunning();}
                $until=microtime(true)+25;
                do{
                    $process->getIncrementalOutput();$present=DisposableMysql57::containerExists($ready['name']);
                    if(!$present&&!is_dir($ready['directory']))break;
                    if(microtime(true)>$until)self::fail('LIFECYCLE_CLEANUP_NOT_PROVEN_'.$mode);usleep(20000);
                }while(true);
                self::assertFalse($present);self::assertDirectoryDoesNotExist($ready['directory']);
                $results[]=['mode'=>$mode,'container_destroyed'=>true,'shared_table_data_destroyed'=>true,'ipc_removed'=>true];
            }finally{$input->close();if($process->isRunning())$process->stop(.2,9);}
        }}catch(\Throwable $error){
            $path=(getenv('RUNNER_TEMP')?:sys_get_temp_dir()).'/ppb-concurrency-receipt.json';
            $receipt=is_file($path)?json_decode(file_get_contents($path),true):[];
            $receipt['result']='FAIL';$receipt['cleanup_faults']=$results;$receipt['cleanup_failed_mode']=$mode??'startup';
            file_put_contents($path,json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");throw $error;
        }
        $path=(getenv('RUNNER_TEMP')?:sys_get_temp_dir()).'/ppb-concurrency-receipt.json';
        $receipt=is_file($path)?json_decode(file_get_contents($path),true):[];$receipt['cleanup_faults']=$results;
        file_put_contents($path,json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    }
    private function timeoutAndRefresh():void
    {
        $this->clear();$a=$this->peer(['op'=>'substitute','target'=>10,'pause'=>'game_lock']);$b=$this->peer(['op'=>'substitute','target'=>12,'lock_wait'=>1]);
        $a->send('start');$a->take('barrier','game_lock');$b->send('start');$this->waitFor($b,$a);$br=$b->take('done');
        self::assertSame(1205,$br['driver_code']);self::assertSame(0,$br['transaction_level']);self::assertFalse($br['pdo_transaction']);self::assertSame(0,(int)$this->scalar('SELECT COUNT(*) FROM ppb_substitutions'));
        $a->send('continue');$this->finish($a,302,'timeout blocker');$fresh=$this->peer(['op'=>'substitute','target'=>12]);$fresh->send('start');$this->finish($fresh,302,'timeout refreshed retry');self::assertSame(2,(int)$this->scalar('SELECT COUNT(*) FROM ppb_substitutions'));
        $this->evidence[]=['case'=>'timeout_1205_clean_rollback_then_fresh_retry','sessions'=>[$a->ready['session'],$b->ready['session']],'driver_code'=>1205,'fresh_status'=>302,'failed_attempt_writes'=>0];
    }
    private function negativeDeadlockProbe():void
    {
        $this->clear();$before=$this->state();$a=$this->peer(['op'=>'reverse_probe','order'=>['game','assignment'],'pause'=>'game_lock']);$b=$this->peer(['op'=>'reverse_probe','order'=>['assignment','game'],'pause'=>'assignment_lock']);
        $a->send('start');$a->take('barrier','game_lock');$b->send('start');$b->take('barrier','assignment_lock');$a->send('continue');$this->waitFor($a,$b);$b->send('continue');
        $ar=$a->take('done');$br=$b->take('done');$this->lastOperation=['case'=>'reverse_probe','A'=>$ar,'B'=>$br];self::assertTrue(in_array(1213,[$ar['driver_code'],$br['driver_code']],true),json_encode([$ar,$br]));self::assertSame(0,$ar['transaction_level']);self::assertSame(0,$br['transaction_level']);self::assertTrue($before===$this->state(),'DEADLOCK_PROBE_LEFT_DATA');
        $this->evidence[]=['case'=>'explicit_reverse_order_engine_negative_probe','sessions'=>[$a->ready['session'],$b->ready['session']],'deadlock_detected'=>true,'state_unchanged'=>true,'application_path_deadlock'=>false];
    }
}
