<?php
declare(strict_types=1);
define('JOURNAL_DIR',sys_get_temp_dir().'/wpn2-test-'.getmypid());
mkdir(JOURNAL_DIR,0700);
require __DIR__.'/run.php';
function check(bool $ok,string $why):void {if(!$ok)throw new RuntimeException('TEST_FAILED_'.$why);}
$keys=['賽事編號','日期','聯盟','賽事主隊','賽事客隊','合盤推薦','分析師Bonus','分析師bonus照片','分析師bonus狀態','Bonus推薦投注','Bonus推薦短文','有長文','長文連結'];
$groups=[];
foreach(['','照片','狀態','推薦','推薦投注','推薦短文'] as $suffix){$g=[];for($i=1;$i<=5;$i++){$g[]='分析師'.$i.$suffix;$keys[]='分析師'.$i.$suffix;}$groups[]=$g;}
$people=[];for($i=2;$i<=7;$i++)$people[$i]=['id'=>$i,'name'=>'Real source analyst '.$i,'photo'=>(string)(100+$i),'author_id'=>200+$i];
$fixture=['definition'=>['static'=>array_slice($keys,0,13),'groups'=>$groups,'all_keys'=>$keys,'url'=>'https://csdatw.com','user'=>'fixture','password'=>'fixture'],'people'=>$people];
$server=null;$stored=[];$calls=[];$creates=0;$writes=0;$lost=false;$bad=false;$badMedia=false;
$GLOBALS['transport']=function($ctx,$path,$method,$payload)use(&$server,&$stored,&$calls,&$creates,&$writes,&$lost,&$bad,&$badMedia){
    $calls[]=[$path,$method];$body=null;$status=200;$error=false;
    if($path==='/wp-json/wp/v2/expert/30436?context=edit'){$body=['id'=>30436,'status'=>'private'];}
    elseif($path==='/wp-json/acf/v3/expert/30436'){$body=['acf'=>[]];}
    elseif($path==='/wp-json/wp/v2/users/me?context=edit')$body=['id'=>99];
    elseif(preg_match('~/users/(\d+)~',$path,$m))$body=['id'=>(int)$m[1]];
    elseif(preg_match('~/media/(\d+)~',$path,$m)){$body=['id'=>(int)$m[1],'media_type'=>'image','source_url'=>'https://csdatw.com/wp-content/uploads/'.$m[1].'.jpg'];if($badMedia&&(int)$m[1]===107){$status=404;$body=['code'=>'rest_post_invalid_id'];}}
    elseif($path===lookupPath())$body=$server?[$server]:[];
    elseif($path==='/wp-json/wp/v2/expert'&&$method==='POST'){
        $creates++;$server=['id'=>40123,'type'=>'expert','status'=>'draft','slug'=>$payload['slug'],'title'=>['raw'=>$payload['title']],'content'=>['raw'=>$payload['content']],'author'=>$payload['author']];
        $body=$server;$status=201;if($lost){$status=0;$body=null;$error=true;}
    }elseif($path==='/wp-json/wp/v2/expert/40123?context=edit')$body=$server;
    elseif($path==='/wp-json/acf/v3/expert/40123'){
        if($method==='POST'){$writes++;$stored=$payload['fields'];}
        $body=['acf'=>$stored];if($bad&&$method==='GET'&&$stored)$body['acf']['分析師5推薦']='WRONG';
    }else throw new RuntimeException('TEST_UNEXPECTED_ROUTE');
    return ['status'=>$status,'json'=>$body,'network_error'=>$error];
};
function resetTest():void {
    global $journal,$state,$server,$stored,$calls,$creates,$writes,$lost,$bad;
    $journal=[];$state=['wp_test_success'=>false];$server=null;$stored=[];$calls=[];$creates=0;$writes=0;$lost=false;$bad=false;
    @unlink(JOURNAL_DIR.'/journal.json');
}
try{
    if(($argv[1]??'')==='--emit-report'){
        $badMedia=($argv[2]??'success')==='media_failure';
        try{run($fixture);}catch(RuntimeException $e){$state['result']='STOP';$state['stop_code']=$e->getMessage();}
        saveReport();
        echo file_get_contents(JOURNAL_DIR.'/report.json');
        return;
    }
    selftest();
    // Full orchestration: source-shaped 43 fields -> actual guarded POST -> GET verification.
    run($fixture);check($creates===1&&$writes===1&&$state['wp_test_success'],'FIRST_RUN');
    check(count($stored)===43,'43_FIELDS');
    foreach([1,2,3,4,5] as $i)check($stored['分析師'.$i.'推薦']==='Codex測試','SIX_REC');
    check($stored['Bonus推薦投注']==='Codex測試','BONUS_REC');
    run($fixture);check($creates===1&&$writes===1,'REPEAT_NO_CREATE_OR_ACF_WRITE');
    foreach($calls as [$path,$method])check(!str_contains($path,'30436')||$method==='GET','OLD_ID_NEVER_WRITTEN');
    check(!allowed('/wp-json/acf/v3/expert/30436','POST',['fields'=>$stored]),'OLD_ACF_GUARD');
    check(!allowed('/wp-json/wp/v2/expert/30436','PUT',[]),'OLD_CORE_GUARD');
    check(!allowed('/wp-json/wp/v2/posts','POST',[]),'POSTS_GUARD');
    resetTest();$lost=true;run($fixture);check($creates===1&&$state['wp_test_success'],'LOST_CREATE_RESPONSE_RECONCILED');
    // Process dies after server accepted create but before receipt: reload durable intent.
    $journal=json_decode(file_get_contents(JOURNAL_DIR.'/journal.json'),true);unset($journal['id']);$journal['phase']='CREATE_INTENT';journalSave();
    run($fixture);check($creates===1,'CRASH_RESTART_NO_SECOND_CREATE');
    resetTest();$journal=['fingerprint'=>'wrong','phase'=>'CREATE_INTENT'];
    try{run($fixture);check(false,'SOURCE_CHANGE_ACCEPTED');}catch(RuntimeException $e){check($e->getMessage()==='JOURNAL_SOURCE_CHANGED','SOURCE_CHANGED_STOP');}
    resetTest();run($fixture);$journal=[];
    try{run($fixture);check(false,'ORPHAN_ACCEPTED');}catch(RuntimeException $e){check($e->getMessage()==='EXISTING_MARKER_WITHOUT_JOURNAL_NO_CREATE','ORPHAN_STOP');}
    check($creates===1,'ORPHAN_NO_SECOND_CREATE');
    resetTest();$bad=true;
    try{run($fixture);check(false,'BAD_READBACK_ACCEPTED');}catch(RuntimeException $e){check($e->getMessage()==='FINAL_43_ACF_OR_CORE_NOT_VERIFIED','BAD_READBACK_STOP');}
    check(!$state['wp_test_success'],'BAD_READBACK_NO_SUCCESS');
    resetTest();$lost=true;
    // A durable create intent with no matching remote result must never create again.
    run($fixture);$server=null;
    try{run($fixture);check(false,'UNCERTAIN_ACCEPTED');}catch(RuntimeException $e){check($e->getMessage()==='CREATE_OUTCOME_UNCERTAIN_NO_SECOND_CREATE','UNCERTAIN_STOP');}
    check($creates===1,'UNCERTAIN_NO_SECOND_CREATE');
    // Direct ownership protection applies even to a fabricated successful HTTP response.
    try{ownDraft(['id'=>30436],99);check(false,'OLD_OWNERSHIP_ACCEPTED');}catch(RuntimeException $e){check($e->getMessage()==='NEW_EXPERT_OWNERSHIP_NOT_VERIFIED','OLD_OWNERSHIP');}
    emit('WPN2_OFFLINE_ORCHESTRATION_GUARDS_RESTART_AND_43_ACF_PASS (NOT A LIVE WP RESULT)');
}finally{foreach(glob(JOURNAL_DIR.'/*') as $p)unlink($p);rmdir(JOURNAL_DIR);}
