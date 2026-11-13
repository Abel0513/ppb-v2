<?php
declare(strict_types=1);
require __DIR__.'/legacy.php';
if(!defined('JOURNAL_DIR'))define('JOURNAL_DIR','/var/tmp/ppb-codex-issue12-new-expert-v1');
$state=['version'=>'WPN2','result'=>'UNVERIFIED','wp_test_success'=>false,
    'execution'=>['commit'=>getenv('PPB_WP_GITHUB_COMMIT')?:null,
                  'run_id'=>getenv('PPB_WP_GITHUB_RUN_ID')?:null,
                  'run_attempt'=>getenv('PPB_WP_GITHUB_RUN_ATTEMPT')?:null]];
$journal=[];
function atomicJson(string $path,array $data):void {
    if(is_link($path))stop('STATE_SYMLINK');
    $tmp=$path.'.tmp.'.getmypid();
    $h=fopen($tmp,'x');if(!$h)stop('STATE_OPEN_FAILED');
    $raw=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    if(fwrite($h,$raw)!==strlen($raw)||!fflush($h)||!fsync($h))stop('STATE_SYNC_FAILED');
    fclose($h);if(!rename($tmp,$path))stop('STATE_RENAME_FAILED');
}
function journalSave():void {$GLOBALS['state']['phase']=$GLOBALS['journal']['phase']??'UNKNOWN';atomicJson(JOURNAL_DIR.'/journal.json',$GLOBALS['journal']);}
function allowed(string $path,string $method,?array $payload):bool {
    $id=(int)($GLOBALS['journal']['id']??0);
    if($method==='GET') {
        if(in_array($path,['/wp-json/wp/v2/expert/30436?context=edit','/wp-json/acf/v3/expert/30436'],true))return true;
        if($path==='/wp-json/wp/v2/users/me?context=edit'||$path===lookupPath())return true;
        if(preg_match('~^/wp-json/wp/v2/media/([1-9][0-9]*)(?:\?context=edit)?$~D',$path,$m))return in_array((int)$m[1],$GLOBALS['allowedMediaIds']??[],true);
        if(preg_match('~^/wp-json/wp/v2/users/([1-9][0-9]*)\?context=edit$~D',$path,$m))return in_array((int)$m[1],$GLOBALS['allowedAuthors']??[],true);
        return $id>0&&$id!==30436&&in_array($path,['/wp-json/wp/v2/expert/'.$id.'?context=edit','/wp-json/acf/v3/expert/'.$id],true);
    }
    if($method!=='POST')return false;
    if($path==='/wp-json/wp/v2/expert')return ($GLOBALS['journal']['phase']??'')==='CREATE_INTENT'&&($payload['slug']??'')===SLUG&&($payload['status']??'')==='draft'&&($payload['title']??'')===TITLE&&($payload['content']??'')==='<!-- '.MARKER.' -->';
    return $id>0&&$id!==30436&&($GLOBALS['journal']['phase']??'')==='ACF_INTENT'&&$path==='/wp-json/acf/v3/expert/'.$id&&array_keys($payload??[])===['fields']&&($payload['fields']??null)===($GLOBALS['expected']??null);
}
function wpCall(array $ctx,string $path,string $method='GET',?array $payload=null):array {
    if(!allowed($path,$method,$payload))stop('WRITE_OR_ROUTE_GUARD');
    if(isset($GLOBALS['transport']))return ($GLOBALS['transport'])($ctx,$path,$method,$payload);
    $ch=curl_init($ctx['url'].$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','Authorization: Basic '.base64_encode($ctx['user'].':'.$ctx['password'])]]);
    if($method==='POST')curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_errno($ch);curl_close($ch);
    $body=is_string($raw)?json_decode($raw,true):null;
    $result=['status'=>$status,'json'=>is_array($body)?$body:null,'network_error'=>$err!==0||$raw===false];
    $GLOBALS['state']['api'][]=['path'=>$path,'method'=>$method,'http'=>$status,'curl_errno'=>$err,'code'=>is_array($body)&&preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',(string)($body['code']??''))?$body['code']:null];
    return $result;
}
function lookupPath():string{return '/wp-json/wp/v2/expert?context=edit&status=any&slug='.SLUG.'&per_page=100';}
function lookup(array $ctx):array {
    $r=wpCall($ctx,lookupPath());
    if($r['status']!==200||$r['network_error']||!is_array($r['json'])||!array_is_list($r['json']))stop('DUPLICATE_LOOKUP_UNVERIFIED');
    if(count($r['json'])>1)stop('MULTIPLE_MARKED_EXPERTS');return $r['json'];
}
function ownDraft(array $body,int $author):int {
    $id=(int)($body['id']??0);
    if($id<1||$id===30436||($body['type']??'')!=='expert'||($body['status']??'')!=='draft'||($body['slug']??'')!==SLUG||($body['title']['raw']??'')!==TITLE||($body['content']['raw']??'')!=='<!-- '.MARKER.' -->'||(int)($body['author']??0)!==$author)stop('NEW_EXPERT_OWNERSHIP_NOT_VERIFIED');
    return $id;
}
function run(?array $fixture=null):void {
    global $state,$journal;
    foreach(['/www/ppb-v2-deploy/wpn1/issue-12-state.json','/www/ppb-v2-deploy/wp-test/issue-12-state.json'] as $prior){
        if(is_file($prior))stop('PRIOR_RUN_JOURNAL_EXISTS_NO_NEW_CREATE');
    }
    $def=$fixture['definition']??sourceDef();$people=$fixture['people']??profiles();$ctx=['url'=>$def['url'],'user'=>$def['user'],'password'=>$def['password']];
    $GLOBALS['allowedMediaIds']=array_map('intval',array_column($people,'photo'));
    $GLOBALS['allowedAuthors']=array_values(array_unique(array_map('intval',array_column($people,'author_id'))));
    $me=wpCall($ctx,'/wp-json/wp/v2/users/me?context=edit');
    if($me['status']!==200||$me['network_error']||(int)($me['json']['id']??0)<1)stop('WP_AUTH_FAILED');
    foreach($people as $p){
        if(($p['author_id']??0)<1)stop('REAL_ANALYST_AUTHOR_MISSING');
        $u=wpCall($ctx,'/wp-json/wp/v2/users/'.$p['author_id'].'?context=edit');
        if($u['status']!==200||$u['network_error']||(int)($u['json']['id']??0)!==$p['author_id'])stop('REAL_ANALYST_AUTHOR_NOT_VERIFIED');
    }
    // Expert is a six-person aggregate. Its author is the authenticated publishing
    // account; individual legacy author_id mappings are independently checked above.
    $author=(int)$me['json']['id'];
    $plan=['definition'=>$def,'people'=>$people,'ctx'=>$ctx,'existing'=>[]];
    $reference=wpCall($ctx,'/wp-json/wp/v2/expert/30436?context=edit');
    $referenceAcf=wpCall($ctx,'/wp-json/acf/v3/expert/30436');
    $state['reference_30436']=['core_http'=>$reference['status'],'acf_http'=>$referenceAcf['status'],'status'=>$reference['json']['status']??null,'used_as_draft_gate'=>false];
    // These are the actual historical WPF6 test values, reconstructed from its
    // original source, not a made-up live match or a legacy schedule insertion.
    $fields=payload($plan,'WPF5-20261110172836-87a96466','WPF6_UPDATE');
    $state['static_source']='ORIGINAL_WPF6_TEST_PAYLOAD';
    if($referenceAcf['status']===200&&!$referenceAcf['network_error']){
        $rf=wpFields($referenceAcf);$date=dateParts($rf['日期']??null);
        // Only copy a current reference's game fields as one set when its full
        // civil date is exposed. Never invent an absent year.
        if($date!==null&&$date['year']!==null&&$date['hour']!==null&&!array_diff($def['static'],array_keys($rf))){
            foreach($def['static'] as $key){if(!is_scalar($rf[$key])&&$rf[$key]!==null)stop('REFERENCE_STATIC_TYPE');$fields[$key]=$rf[$key]??'';}
            $fields['日期']=sprintf('%04d-%02d-%02d %02d:%02d:00',$date['year'],$date['month'],$date['day'],$date['hour'],$date['minute']);
            $state['static_source']='CURRENT_REFERENCE_ACF_WITH_EXPLICIT_YEAR';
        }
    }
    foreach($fields as $key=>&$v)if(str_contains($key,'推薦'))$v='Codex測試';unset($v);
    $GLOBALS['expected']=$fields;
    $media=mediaSourceInfo($ctx,$people);
    if(count(array_filter($media,fn($m)=>($m['source_verified']??false)))!==6)stop('SIX_MEDIA_NOT_VERIFIED');
    $fp=hash('sha256',fingerprint($plan).json_encode($fields).':'.$author);
    $state['source_fingerprint']=$fp;$state['analysts']=array_values($people);$state['expert_author']=$author;
    if($journal&&($journal['phase']??'')!=='PREFLIGHT'&&($journal['fingerprint']??'')!==$fp)stop('JOURNAL_SOURCE_CHANGED');
    $found=lookup($ctx);
    if(!$journal||($journal['phase']??'')==='PREFLIGHT'){
        if($found)stop('EXISTING_MARKER_WITHOUT_JOURNAL_NO_CREATE');
        $journal=['fingerprint'=>$fp,'phase'=>'CREATE_INTENT','marker'=>MARKER,'slug'=>SLUG,'author'=>$author,'created_at'=>gmdate('c')];
        journalSave(); // Durable intent BEFORE the only create attempt, even on timeout.
        $r=wpCall($ctx,'/wp-json/wp/v2/expert','POST',['title'=>TITLE,'status'=>'draft','slug'=>SLUG,'content'=>'<!-- '.MARKER.' -->','author'=>$author]);
        $journal['create_http']=$r['status'];journalSave();
        if(!$r['network_error']&&$r['status']===201){$journal['id']=ownDraft($r['json']??[],$author);$journal['phase']='CREATED';journalSave();}
        $found=lookup($ctx);
    }
    // A lost response is reconciled by the stable unique slug. Never POST create again.
    if(count($found)!==1)stop('CREATE_OUTCOME_UNCERTAIN_NO_SECOND_CREATE');
    $id=ownDraft($found[0],$author);
    if(isset($journal['id'])&&$journal['id']!==$id)stop('JOURNAL_ID_MISMATCH');
    $journal['id']=$id;journalSave();
    $core=wpCall($ctx,'/wp-json/wp/v2/expert/'.$id.'?context=edit');
    if($core['status']!==200||$core['network_error']||ownDraft($core['json']??[],$author)!==$id)stop('NEW_DRAFT_GET_FAILED');
    $read=wpCall($ctx,'/wp-json/acf/v3/expert/'.$id);
    if($read['status']!==200||$read['network_error'])stop('INITIAL_ACF_GET_FAILED');
    $diff=diffFields($fields,wpFields($read),$media);
    if(!($diff['all_43_values_match']??false)){
        $journal['phase']='ACF_INTENT';journalSave();
        $r=wpCall($ctx,'/wp-json/acf/v3/expert/'.$id,'POST',['fields'=>$fields]);
        $journal['acf_http']=$r['status'];journalSave();
        // Always GET after a write, including ambiguous/failed HTTP responses.
    }
    $read=wpCall($ctx,'/wp-json/acf/v3/expert/'.$id);
    if($read['status']!==200||$read['network_error'])stop('FINAL_ACF_GET_FAILED');
    $diff=diffFields($fields,wpFields($read),$media);$state['final_readback']=$diff;
    $core=wpCall($ctx,'/wp-json/wp/v2/expert/'.$id.'?context=edit');
    if($core['status']!==200||$core['network_error']||ownDraft($core['json']??[],$author)!==$id||!$diff['all_43_values_match'])stop('FINAL_43_ACF_OR_CORE_NOT_VERIFIED');
    $found=lookup($ctx);if(count($found)!==1||ownDraft($found[0],$author)!==$id)stop('FINAL_EXACTLY_ONE_NOT_VERIFIED');
    // Read-only before/after evidence is optional; 30436's status is never a gate.
    $refAfter=wpCall($ctx,'/wp-json/wp/v2/expert/30436?context=edit');
    $acfAfter=wpCall($ctx,'/wp-json/acf/v3/expert/30436');
    $state['reference_30436']['readback_unchanged']=($reference['status']===200&&$referenceAcf['status']===200&&$refAfter['status']===200&&$acfAfter['status']===200)?($reference['json']===$refAfter['json']&&$referenceAcf['json']===$acfAfter['json']):null;
    if($state['reference_30436']['readback_unchanged']===false)stop('REFERENCE_CHANGED_DURING_TEST');
    $journal['phase']='VERIFIED';journalSave();
    $state['result']='PASS';$state['wp_test_success']=true;$state['new_expert_id']=$id;
    $state['CREATE_HTTP']=$journal['create_http']??null;$state['READBACK_HTTP']=200;$state['ACF_MATCHED']=43;$state['RECOMMENDATIONS']=6;
    $state['recommendations']=[];foreach(['分析師1推薦','分析師2推薦','分析師3推薦','分析師4推薦','分析師5推薦','Bonus推薦投注'] as $key)$state['recommendations'][$key]=wpFields($read)[$key]??null;
    $state['edit_url']=$ctx['url'].'/wp-admin/post.php?post='.$id.'&action=edit';
    if($fixture===null)emit('NEW_WP_EXPERT_ID='.$id.' POST_TYPE=expert WP_STATUS=draft CREATE_HTTP='.($state['CREATE_HTTP']??'UNKNOWN').' READBACK_HTTP=200 ACF_MATCHED=43 RECOMMENDATIONS=6 WP_EDIT_URL='.$state['edit_url']);
}
function wpFields(array $r):array {
    $b=$r['json'];foreach(['fields','acf','data'] as $k)if(isset($b[$k])&&is_array($b[$k]))return $b[$k];return $b;
}
if(realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){
    if(($argv[1]??'')==='--selftest'){selftest();exit;}
    try{
        if(!is_dir(JOURNAL_DIR)&&!mkdir(JOURNAL_DIR,0700))stop('JOURNAL_DIRECTORY_FAILED');
        if(is_link(JOURNAL_DIR)||(fileperms(JOURNAL_DIR)&0077)!==0||fileowner(JOURNAL_DIR)!==posix_geteuid())stop('JOURNAL_DIRECTORY_UNSAFE');
        $lock=fopen(JOURNAL_DIR.'/lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))stop('ALREADY_RUNNING');
        $held=true;
        $p=JOURNAL_DIR.'/journal.json';
        if(file_exists($p)){
            if(is_link($p))stop('JOURNAL_SYMLINK');
            $loaded=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
            if(!is_array($loaded)||!in_array($loaded['phase']??'', ['PREFLIGHT','CREATE_INTENT','CREATED','ACF_INTENT','VERIFIED'],true))stop('JOURNAL_INVALID');
            $journal=$loaded;$journalLoaded=true;
        }
        if(!$journal){$journal=['phase'=>'PREFLIGHT','marker'=>MARKER,'slug'=>SLUG];journalSave();$journalLoaded=true;}
        run();
    }catch(Throwable $e){$state['result']='STOP';$state['stop_code']=preg_match('/^[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'UNEXPECTED_'.get_class($e);fwrite(STDERR,'STOP: '.$state['stop_code']."\n");}
    if(($held??false)&&($journalLoaded??false)&&$state['result']==='STOP'){$journal['last_stop_code']=$state['stop_code'];journalSave();}
    $state['journal_exists']=is_file(JOURNAL_DIR.'/journal.json');
    if($held??false)atomicJson(JOURNAL_DIR.'/report.json',$state);
    exit($state['wp_test_success']?0:2);
}
