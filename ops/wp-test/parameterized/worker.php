<?php
declare(strict_types=1);
// Reuse the frozen WPF6 connector and helpers, never its create entrypoint.
require __DIR__.'/run.php';
function paramGet(array $ctx,string $path):array {
    $r=wpCall($ctx,$path);
    if($r['status']!==200||$r['network_error']||!is_array($r['json']))stop('PARAM_GET_FAILED');
    return $r['json'];
}
function paramOperate(array $q,?array $fixture=null):array {
    global $journal,$state;
    if(array_keys($q)!==['operation','expert_id','fields']||!is_int($q['expert_id'])||$q['expert_id']<1||$q['expert_id']===30436||!in_array($q['operation'],['read','update_acf'],true)||!is_array($q['fields']))stop('PARAM_REQUEST_INVALID');
    if($q['operation']==='read'&&$q['fields'])stop('PARAM_READ_HAS_FIELDS');
    $p=JOURNAL_DIR.'/journal.json';
    if($fixture===null){
        $dir=lstat(JOURNAL_DIR);$st=lstat($p);
        if(!$dir||($dir['mode']&0170000)!==0040000||$dir['uid']!==posix_geteuid()||($dir['mode']&0077)!==0||!$st||($st['mode']&0170000)!==0100000||$st['uid']!==posix_geteuid()||($st['mode']&0077)!==0||$st['size']>200000)stop('PARAM_JOURNAL_UNSAFE');
        $raw=file_get_contents($p);$journal=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    }else{$raw=json_encode($fixture['journal']);$journal=$fixture['journal'];}
    if(($journal['id']??0)!==$q['expert_id']||($journal['phase']??'')!=='VERIFIED'||($journal['create_http']??0)!==201||($journal['slug']??'')!==SLUG||($journal['marker']??'')!==MARKER||!preg_match('/^[a-f0-9]{64}$/D',$journal['fingerprint']??''))stop('PARAM_ORIGINAL_RECEIPT_INVALID');
    $id=$q['expert_id'];
    $def=$fixture['definition']??sourceDef();$people=$fixture['people']??profiles();
    // Text recommendation groups come from the verified WPF6 source schema.
    // Identity, media, dates, status and core metadata are never writable here.
    $writable=array_values(array_unique(array_merge($def['groups'][3],$def['groups'][4],$def['groups'][5],array_values(array_filter($def['static'],fn($k)=>str_contains($k,'推薦'))))));
    if($q['operation']==='update_acf'){
        if(!$q['fields']||array_diff(array_keys($q['fields']),$writable))stop('PARAM_FIELD_DENIED');
        foreach($q['fields'] as $value)if(!is_string($value)||strlen($value)<1||strlen($value)>500||preg_match('/[\x00-\x1f\x7f]/',$value))stop('PARAM_VALUE_DENIED');
    }
    if(count($people)!==6||array_keys($people)!==[2,3,4,5,6,7])stop('PARAM_REAL_SIX_REQUIRED');
    $ctx=['url'=>$def['url'],'user'=>$def['user'],'password'=>$def['password']];
    $GLOBALS['allowedMediaIds']=array_map('intval',array_column($people,'photo'));
    $GLOBALS['allowedAuthors']=array_values(array_unique(array_map('intval',array_column($people,'author_id'))));
    $me=paramGet($ctx,'/wp-json/wp/v2/users/me?context=edit');
    if(($me['id']??0)!==($journal['author']??0))stop('PARAM_CREATOR_CHANGED');
    $type=paramGet($ctx,'/wp-json/wp/v2/types/expert?context=edit');
    if(($type['slug']??'')!=='expert'||($type['rest_base']??'')!=='expert'||($type['rest_namespace']??'')!=='wp/v2'||!is_array($type['supports']??null)||empty($type['supports']['editor']))stop('PARAM_TYPE_CHANGED');
    $GLOBALS['verifiedExpertCapabilities']=['author_supported'=>!empty($type['supports']['author']),'editor_supported'=>true];$GLOBALS['verifiedWpCreator']=$me['id'];
    foreach($people as $person){$authorId=$person['author_id']??0;if($authorId<1||(paramGet($ctx,'/wp-json/wp/v2/users/'.$authorId.'?context=edit')['id']??0)!==$authorId)stop('PARAM_ANALYST_AUTHOR_CHANGED');}
    $found=lookup($ctx);if(count($found)!==1||ownDraft($found[0],$me['id'])!==$id)stop('PARAM_EXPERT_NOT_UNIQUE');
    $core=paramGet($ctx,'/wp-json/wp/v2/expert/'.$id.'?context=edit');if(ownDraft($core,$me['id'])!==$id)stop('PARAM_CORE_CHANGED');
    $fields=wpFields(['json'=>paramGet($ctx,'/wp-json/acf/v3/expert/'.$id)]);
    if(count($fields)!==43||array_diff($def['all_keys'],array_keys($fields))||array_diff(array_keys($fields),$def['all_keys']))stop('PARAM_43_KEYS_REQUIRED');
    $media=mediaSourceInfo($ctx,$people);if(count($media)!==6||count(array_filter($media,fn($m)=>($m['source_verified']??false)===true))!==6)stop('PARAM_SIX_MEDIA_REQUIRED');
    $identities=[];
    foreach(array_values($people) as $i=>$person){
        $name=$i<5?'分析師'.($i+1):'分析師Bonus';$photo=$i<5?$name.'照片':'分析師bonus照片';
        if(($fields[$name]??null)!==$person['name']||!(mediaValueProof($fields[$photo]??null,(int)$person['photo'],$media)['ok']??false))stop('PARAM_ANALYST_ACF_CHANGED');
        $identities[]=$person;
    }
    $reference=['core'=>paramGet($ctx,'/wp-json/wp/v2/expert/30436?context=edit'),'acf'=>paramGet($ctx,'/wp-json/acf/v3/expert/30436')];
    if($q['operation']==='update_acf'){
        // Only source-whitelisted text fields on the receipt-bound existing Expert. Never core/create.
        $journal['phase']='ACF_INTENT';$GLOBALS['expected']=$q['fields'];
        $r=wpCall($ctx,'/wp-json/acf/v3/expert/'.$id,'POST',['fields'=>$q['fields']]);
        if($fixture===null&&hash_file('sha256',$p)!==hash('sha256',$raw))stop('PARAM_JOURNAL_CHANGED');
        return ['http'=>$r['status'],'network_error'=>$r['network_error']];
    }
    if($fixture===null&&hash_file('sha256',$p)!==hash('sha256',$raw))stop('PARAM_JOURNAL_CHANGED');
    return ['expert_id'=>$id,'writable_fields'=>$writable,'readback_http'=>200,'create_http'=>201,'fields'=>$fields,'core'=>$core,'reference'=>$reference,'identities'=>$identities,'media'=>$media,
            'journal_sha256'=>hash('sha256',$raw),'fingerprint'=>$journal['fingerprint']];
}
if(realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){
    try{$q=json_decode(stream_get_contents(STDIN,10000),true,512,JSON_THROW_ON_ERROR);echo json_encode(['ok'=>true,'data'=>paramOperate($q)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
    catch(Throwable $e){$c=$e->getMessage();echo json_encode(['ok'=>false,'stop_code'=>preg_match('/^[A-Z0-9_]+$/D',$c)?$c:'PARAM_WORKER_FAILED']);exit(2);}
}
