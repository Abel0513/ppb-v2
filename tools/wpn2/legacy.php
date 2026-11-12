<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(2);}
umask(0077);
require_once __DIR__.'/parser.php';
const PPB_LEGACY='/www/wwwroot/3.231.229.26/Application/WordPress/Controller/WordPressApiController.class.php';
const PPB_LEGACY_SHA='5b328e4d112f79bf332542d3c7cb747e41b1199f4eb16c164cbb3e6aedd6b344';
const PPB_VERSION='WPN2';
const VERSION='WPN2';
const OUT_PREVIEW='unused';
function report(array $data,string $path):void {$GLOBALS['state']['extraction']=$data;}
const TITLE='Codex測試 2026-11-13';
const SLUG='ppb-codex-issue12-new-expert-v1';
const MARKER='PPB-CODEX-ISSUE12-NEW-EXPERT-V1';
function emit(string $s):void {echo $s."\n";}
function sourceDef():array {
    if(!is_file(PPB_LEGACY)||is_link(PPB_LEGACY)||hash_file('sha256',PPB_LEGACY)!==PPB_LEGACY_SHA)stop('LEGACY_SOURCE_SHA_MISMATCH');
    $src=file_get_contents(PPB_LEGACY);if(!is_string($src))stop('LEGACY_SOURCE_UNREADABLE');
    $m=methodPart($src,'publishExpert');$def=parseFields($m);
    if(!preg_match('/\$newPosition\s*=\s*([0-9]+)\s*;/', $m,$match))stop('SOURCE_POSITION_START_UNPROVEN');
    $start=(int)$match[1];
    if(!in_array($start,[0,1],true)||!str_contains($m,'$newPosition++'))stop('SOURCE_POSITION_UNSUPPORTED');
    $oldDyn=array_merge(...$def['dynamic_groups']);
    $static=array_values(array_diff($def['keys'],$oldDyn));
    $expectedStatic=['賽事編號','日期','聯盟','賽事主隊','賽事客隊','合盤推薦','分析師Bonus','分析師bonus照片','分析師bonus狀態','Bonus推薦投注','Bonus推薦短文','有長文','長文連結'];
    if(count($static)!==13||array_diff($static,$expectedStatic)||array_diff($expectedStatic,$static))stop('SOURCE_STATIC_ACF_KEY_MISMATCH');
    $dyn=array_values(array_filter($def['assignments'],fn($e)=>$e['dynamic']));
    if(count($dyn)!==6)stop('SOURCE_SIX_DYNAMIC_GROUPS_UNPROVEN');
    $groups=[];
    foreach($dyn as $entry){
        $tokens=token_get_all('<?php '.$entry['expr']);array_shift($tokens);$keys=[];
        for($i=1;$i<=5;$i++){
            $name=parseKeyExpr($tokens,$i);if($name===null)stop('SOURCE_UNSUPPORTED_KEY_EXPRESSION');
            $keys[]=$name;
        }
        $groups[]=$keys;
    }
    $names=array_values(array_unique(array_merge($static,...$groups)));
    if(count($names)!==43)stop('SOURCE_EXPECTED_43_DISTINCT_KEYS_ACTUALLY_'.count($names));
    $allRequired=[];
    for($i=1;$i<=5;$i++){
        foreach(['','照片','狀態','推薦','推薦投注','推薦短文'] as $suffix)$allRequired[]='分析師'.$i.$suffix;
    }
    if(array_diff($allRequired,$names)||array_diff($names,array_merge($expectedStatic,$allRequired)))stop('SOURCE_ONE_TO_FIVE_ACF_KEY_SET_DIFFERS');
    if($def['bonus_keys']!==['分析師Bonus','分析師bonus照片','分析師bonus狀態','Bonus推薦投注','Bonus推薦短文'])stop('SOURCE_BONUS_FIELDS_MISMATCH');
    // The old long WordPress Post flow is distinct from Expert ACF, and explicitly
    // uses the legacy author_id as post_author. Verify contract without posting.
    $long=methodPart($src,'publishPost');
    if(!preg_match('~[\'\"]post_author[\'\"]\s*=>\s*\$authorId\b~',$long))stop('LEGACY_LONG_POST_AUTHOR_CONTRACT_CHANGED');
    $c=constants($src);
    foreach(['WP_SITE','WP_USER','WP_APP_PWD'] as $key)if(empty($c[$key]))stop('LEGACY_WP_CONNECTION_NOT_AVAILABLE_'.$key);
    $url=rtrim($c['WP_SITE'],'/');$u=parse_url($url);
    if(!is_array($u)||($u['scheme']??'')!=='https'||!in_array(strtolower((string)($u['host']??'')),['csdatw.com','www.csdatw.com'],true)||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])||($u['path']??'')!=='')stop('WP_ENDPOINT_NOT_APPROVED');
    return ['groups'=>$groups,'static'=>$static,'all_keys'=>$names,'source_start'=>$start,'url'=>$url,'user'=>$c['WP_USER'],'password'=>$c['WP_APP_PWD']];
}
function legacyPDO(): PDO {
    $root='/www/wwwroot/3.231.229.26/Application/';
    $names=['Common/Conf/db.php','Common/Conf/config.php','Common/Conf/database.php','Conf/config.php','Admin/Conf/config.php'];$config=[];
    foreach($names as $name){
        $f=$root.$name;if(!is_file($f)||is_link($f)||filesize($f)>150000)continue;
        $src=@file_get_contents($f);if(!is_string($src))continue;
        foreach(['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PWD'] as $key){
            if(array_key_exists($key,$config))continue;
            if(preg_match('~[\'\"]'.preg_quote($key,'~').'[\'\"]\s*=>\s*([\'\"])((?:\\\\.|(?!\\1).)*)\\1~s',$src,$m))$config[$key]=stripcslashes($m[2]);
        }
    }
    foreach(['DB_HOST','DB_NAME','DB_USER','DB_PWD'] as $key)if(!array_key_exists($key,$config))stop('LEGACY_DB_SETTING_MISSING_'.$key);
    $host=(string)$config['DB_HOST'];$db=(string)$config['DB_NAME'];$port=(string)($config['DB_PORT']??'3306');
    if(!preg_match('/^[a-zA-Z0-9._:\-]+$/D',$host)||!preg_match('/^[a-zA-Z0-9_]+$/D',$db)||!ctype_digit($port))stop('LEGACY_DB_CONNECTION_UNSUPPORTED');
    if(!in_array('mysql',PDO::getAvailableDrivers(),true))stop('MYSQL_PDO_UNAVAILABLE');
    $pdo=new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",(string)$config['DB_USER'],(string)$config['DB_PWD'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>8,
    ]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');$pdo->beginTransaction();return $pdo;
}
function profiles():array {
    $pdo=legacyPDO();
    $p=$pdo->query('SHOW COLUMNS FROM `pay_analyst_profiles`')->fetchAll();$columns=array_column($p,'Field');
    foreach(['id','analyst_name','wp_photo','author_id'] as $n)if(!in_array($n,$columns,true))stop('LEGACY_PROFILE_COLUMN_MISSING_'.$n);
    $stmt=$pdo->query('SELECT `id`,`analyst_name`,`wp_photo`,`author_id` FROM `pay_analyst_profiles` WHERE `id` IN (2,3,4,5,6,7) ORDER BY `id`');
    $rows=$stmt->fetchAll();
    if(count($rows)!==6)stop('LEGACY_REAL_SIX_ANALYST_IDS_NOT_FOUND');
    $out=[];foreach($rows as $r){
        $id=(int)$r['id'];$name=trim((string)$r['analyst_name']);$photo=trim((string)$r['wp_photo']);
        $author=$r['author_id']===null||$r['author_id']===''?null:(int)$r['author_id'];
        if($id<2||$id>7||isset($out[$id])||$name===''||!preg_match('//u',$name))stop('LEGACY_REAL_ANALYST_ID_OR_NAME_INVALID');
        if($photo===''||!ctype_digit($photo)||(int)$photo<1)stop('LEGACY_WP_PHOTO_NOT_POSITIVE_ATTACHMENT_ID_'.$id);
        if(strlen($photo)>65535||preg_match('/[\x00-\x1F\x7F]/',$photo))stop('LEGACY_PHOTO_FORMAT_INVALID_ID_'.$id);
        if($r['author_id']!==null&&$r['author_id']!==''&&(!ctype_digit((string)$r['author_id'])||$author<0))stop('LEGACY_AUTHOR_ID_INVALID_ID_'.$id);
        $out[$id]=['id'=>$id,'name'=>$name,'photo'=>$photo,'author_id'=>$author];
    }
    $pdo->rollBack();return $out;
}

/** Restrict REST requests to original WPF5 private Expert, six real images and identity. Never create. */
function payload(array $plan,string $marker,string $stage):array {
    $def=$plan['definition'];$existing=$plan['existing'];$people=$plan['people'];
    $out=[];
    foreach($def['static'] as $key){
        $v=$existing[$key]??'';
        if($v===null)$v='';
        if(!is_scalar($v))stop('WP_STATIC_FIELD_NOT_SCALAR_'.$key);
        $out[$key]=$v;
    }
    if($out['賽事編號']==='')$out['賽事編號']='TEST-WPF5-NEW-'.$marker;
    // Fixed distinctive day >12 makes dd/mm/yyyy and mm/dd/yyyy unambiguous; test is a DRAFT only.
    $out['日期']=$stage==='INITIAL'?'2026-11-23 12:34:00':($stage==='WPF6_UPDATE'?'2026-11-25 15:16:00':'2026-11-24 13:45:00');
    if($out['聯盟']==='')$out['聯盟']='PPB測試聯盟';
    if($out['賽事主隊']==='')$out['賽事主隊']='PPB測試主隊';
    if($out['賽事客隊']==='')$out['賽事客隊']='PPB測試客隊';
    if($out['合盤推薦']==='')$out['合盤推薦']='PPB-WPF5-COMBINED-'.$marker;
    if($out['有長文']==='')$out['有長文']=0;
    if($out['長文連結']==='')$out['長文連結']='';
    $g=$def['groups'];
    for($i=0;$i<5;$i++){
        $person=$people[$i+2];
        $out[$g[0][$i]]=$person['name'];
        $out[$g[1][$i]]=(int)$person['photo'];
        $status=$existing[$g[2][$i]]??'正常';
        $out[$g[2][$i]]=is_scalar($status)?$status:'正常';
        $out[$g[3][$i]]='PPB-WPF5-'.$stage.'-REC-'.($i+1).'-'.$marker;
        $out[$g[4][$i]]='PPB-WPF5-'.$stage.'-BET-'.($i+1).'-'.$marker;
        $out[$g[5][$i]]='PPB-WPF5-'.$stage.'-ANALYSIS-'.($i+1).'-'.$marker;
    }
    $out['分析師Bonus']=$people[7]['name'];
    $out['分析師bonus照片']=(int)$people[7]['photo'];
    $st=$existing['分析師bonus狀態']??'正常';$out['分析師bonus狀態']=is_scalar($st)?$st:'正常';
    $out['Bonus推薦投注']='PPB-WPF5-'.$stage.'-BONUS-BET-'.$marker;
    $out['Bonus推薦短文']='PPB-WPF5-'.$stage.'-BONUS-ANALYSIS-'.$marker;
    if(count($out)!==43||array_diff($def['all_keys'],array_keys($out))||array_diff(array_keys($out),$def['all_keys']))stop('WPF5_PAYLOAD_NOT_EXACTLY_SOURCE_43_KEYS');
    foreach($out as $key=>$v)if(!is_scalar($v))stop('WPF5_PAYLOAD_NONSCALAR_'.$key);
    return $out;
}
/** Independently verify media IDs against WordPress REST; never treat an arbitrary URL as an attachment match. */
/** Normalize official media path keys without depending on WP exposing source_url. */
function mediaUrlKey($raw):?string {
    if(!is_string($raw)||strlen($raw)>6000)return null;
    $raw=trim(html_entity_decode($raw,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    if(str_starts_with($raw,'//'))$raw='https:'.$raw;
    if(!preg_match('~^https?://~i',$raw))return null;
    $p=parse_url($raw);
    if(!is_array($p)||!in_array(strtolower((string)($p['scheme']??'')),['http','https'],true)
       ||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment']))return null;
    $host=strtolower((string)$p['host']);
    if($host==='www.csdatw.com')$host='csdatw.com';
    $path=rawurldecode((string)($p['path']??'/'));
    if($path===''||preg_match('/[\\x00-\\x1F\\x7F]/',$path))return null;
    foreach(explode('/',$path) as $seg)if($seg==='.'||$seg==='..')return null;
    return $host.$path;
}
function mediaRelFile($raw):?string {
    if(!is_string($raw)||strlen($raw)>2000)return null;
    $f=trim(str_replace('\\','/',$raw));
    $f=preg_replace('~^/?(?:wp-content/)?uploads/~','',$f);
    $f=ltrim($f,'/');
    if($f===''||str_contains($f,'?')||str_contains($f,'#')||preg_match('/[\\x00-\\x1F\\x7F]/',$f))return null;
    foreach(explode('/',$f) as $seg)if($seg===''||$seg==='.'||$seg==='..')return null;
    return $f;
}
function mediaUploadPath(string $host,string $rel):string {
    return $host.'/wp-content/uploads/'.$rel;
}
/** From one authenticated WP Media record, derive paths from metadata only when exact. */
function mediaEvidence(array $b,int $id,string $defaultHost):array {
    $urls=[];$relative=[];$registeredHosts=[$defaultHost];
    $link=$b['link']??null;
    $linkKey=mediaUrlKey($link);
    if($linkKey!==null){$lp=parse_url((string)$link);if(is_array($lp)&&!empty($lp['host']))$registeredHosts[]=strtolower((string)$lp['host']);}
    foreach(['source_url','guid'] as $k){
        $v=$b[$k]??null;if($k==='guid'&&is_array($v))$v=$v['rendered']??null;
        if(($key=mediaUrlKey($v))!==null)$urls[$key]=true;
    }
    $details=$b['media_details']??null;
    if(is_array($details)){
        if(($r=mediaRelFile($details['file']??null))!==null)$relative[$r]=true;
        if(($r=mediaRelFile($details['original_image']??null))!==null){
            // original_image is usually only a basename. Anchor to the verified file directory.
            $base=mediaRelFile($details['file']??null);
            if($base!==null){$dir=dirname($base);$relative[($dir==='.'?'':$dir.'/').basename($r)]=true;}
        }
        $base=mediaRelFile($details['file']??null);
        $directory=$base!==null?dirname($base):null;
        $sizes=$details['sizes']??null;
        if(is_array($sizes))foreach($sizes as $row){
            if(!is_array($row))continue;
            if(($urlkey=mediaUrlKey($row['source_url']??null))!==null)$urls[$urlkey]=true;
            if($directory!==null&&($sz=mediaRelFile($row['file']??null))!==null){
                $relative[($directory==='.'?'':$directory.'/').basename($sz)]=true;
            }
        }
    }
    $hosts=[];
    foreach($registeredHosts as $host){$host=strtolower($host);if($host==='www.csdatw.com')$host='csdatw.com';if($host!==''&&!isset($hosts[$host]))$hosts[$host]=true;}
    // Do not invent media URLs; derived paths rely on WP's own media_details.file.
    foreach(array_keys($relative) as $rel)foreach(array_keys($hosts) as $host){
        $urls[mediaUploadPath($host,$rel)]=true;
    }
    return ['id'=>$id,'keys'=>array_keys($urls),'url_count'=>count($urls),'metadata_rel_count'=>count($relative),
        'media_type'=>(string)($b['media_type']??''),'source_verified'=>true,
        'has_media_file'=>isset($details['file'])&&is_string($details['file'])&&$details['file']!=='',
        'rest_source_url_is_nonempty'=>is_string($b['source_url']??null)&&$b['source_url']!=='',
        'rest_guid_is_nonempty'=>is_string($b['guid']['rendered']??null)&&$b['guid']['rendered']!=='',
        'media_details_present'=>is_array($details),
        'media_detail_keys'=>is_array($details)?array_keys($details):[],
        'media_url_proof_mode'=>$relative?'OFFICIAL_MEDIA_FILE_PATH':($urls?'OFFICIAL_MEDIA_REST_URL':'NO_MEDIA_PATH_AVAILABLE'),
    ];
}
function mediaSourceInfo(array $ctx,array $people):array {
    $out=[];$failed=[];
    $defaultHost=strtolower((string)parse_url($ctx['url'],PHP_URL_HOST));
    if($defaultHost==='www.csdatw.com')$defaultHost='csdatw.com';
    foreach($people as $person){
        $id=(int)$person['photo'];
        $x=wpCall($ctx,'/wp-json/wp/v2/media/'.$id);
        $b=$x['json']??null;
        $valid= !$x['network_error']&&$x['status']===200&&is_array($b)
            &&(int)($b['id']??0)===$id&&($b['media_type']??'')==='image';
        $info=$valid?mediaEvidence($b,$id,$defaultHost):null;
        $editCode=null;
        // Try the authenticated edit context when public GET omits actual image file paths.
        if($valid&&($info['url_count']??0)===0){
            $edit=wpCall($ctx,'/wp-json/wp/v2/media/'.$id.'?context=edit');$editCode=$edit['status'];
            $eb=$edit['json']??null;
            if(!$edit['network_error']&&$edit['status']===200&&is_array($eb)&&
               (int)($eb['id']??0)===$id&&($eb['media_type']??'')==='image'){
                $info=mediaEvidence($eb,$id,$defaultHost);
            }
        }
        if(!$valid){
            $failed[]=$id;
            $out[$id]=['id'=>$id,'keys'=>[],'url_count'=>0,'source_verified'=>false,
                'rest_http'=>$x['status'],'rest_network_error'=>$x['network_error'],
                'rest_id_exact'=>is_array($b)&&(int)($b['id']??0)===$id,
                'rest_media_type'=>is_array($b)?(string)($b['media_type']??''):null];
            continue;
        }
        $info['rest_http']=$x['status'];$info['edit_context_http']=$editCode;
        $out[$id]=$info;
    }
    $GLOBALS['state']['media_audit']=array_map(static fn($x)=>array_diff_key($x,['keys'=>true]),array_values($out));
    $GLOBALS['state']['media_verified_image_count']=count($people)-count($failed);
    $GLOBALS['state']['media_with_strict_path_proof_count']=count(array_filter($out,static fn($x)=>($x['url_count']??0)>0));
    return $out;
}
function imageIdOf($raw):?int {
    if(is_int($raw)||is_string($raw)&&ctype_digit($raw)){
        $n=(int)$raw; return $n>0?$n:null;
    }
    if(is_array($raw))foreach(['ID','id','attachment_id'] as $key){
        if(array_key_exists($key,$raw)&&($candidate=imageIdOf($raw[$key]))!==null)return $candidate;
    }
    return null;
}
function mediaValueProof($raw,int $expected,array $media):array {
    $id=imageIdOf($raw);
    if($id!==null)return ['ok'=>$id===$expected,'mode'=>'EXACT_MEDIA_ID','actual_type'=>classifyType($raw),
        'actual_id'=>$id,'expected_id'=>$expected];
    $url=is_array($raw)?($raw['url']??$raw['source_url']??null):$raw;
    $key=mediaUrlKey($url);
    $mediaKeyList=$media[$expected]['keys']??[];
    $ok=$key!==null&&in_array($key,$mediaKeyList,true);
    return ['ok'=>$ok,'mode'=>$key!==null?'MEDIA_REST_URL_PROOF':'NO_MEDIA_ID_OR_URL',
        'actual_type'=>classifyType($raw),'actual_is_empty'=>$raw===''||$raw===null,
        'media_url_looks_valid'=>$key!==null,'media_url_sha256'=>$key!==null?hash('sha256',$key):null,
        'expected_id'=>$expected,'verified_media_url_count'=>count($mediaKeyList)];
}
/** ACF date/date-time return formats may differ from incoming storage format.
 * Compare the exact civil date in a distinctive test sample (23, 24 Nov).
 * Report whether the time was returned too rather than pretending to verify it. */
function dateParts($raw):?array {
    if(!is_scalar($raw))return null;
    $v=trim((string)$raw);
    $y=null;$m=0;$d=0;$h=null;$minute=null;$source='';
    // WP ACF localized example: "11月24日 下午 1:45". The year is not present.
    if(preg_match('/^(\d{1,2})月(\d{1,2})日(?:[\s\x{3000}]*(上午|下午)[\s\x{3000}]*(\d{1,2}):(\d{2}))?$/Du',$v,$mat)){
        $m=(int)$mat[1];$d=(int)$mat[2];$source='ZH_NO_YEAR';
        if(isset($mat[3])&&$mat[3]!==''){
            $period=$mat[3];$clock=(int)$mat[4];$minute=(int)$mat[5];
            if($clock<1||$clock>12||$minute>59)return null;
            $h=$clock%12+($period==='下午'?12:0);
        }
    }elseif(preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?$/D',$v,$mat)){
        $y=(int)$mat[1];$m=(int)$mat[2];$d=(int)$mat[3];$source='YEAR_FIRST';
        if(isset($mat[4])&&$mat[4]!==''){$h=(int)$mat[4];$minute=(int)$mat[5];}
    }elseif(preg_match('/^(\d{4})(\d{2})(\d{2})$/D',$v,$mat)){
        $y=(int)$mat[1];$m=(int)$mat[2];$d=(int)$mat[3];$source='YYYYMMDD';
    }elseif(preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/D',$v,$mat)){
        $a=(int)$mat[1];$b=(int)$mat[2];$y=(int)$mat[3];$source='NUMERIC_REVERSED';
        if($a>12&&$b<=12){$d=$a;$m=$b;}
        elseif($b>12&&$a<=12){$m=$a;$d=$b;}
        else return null;
    }elseif(preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日(?:\s*(?:(上午|下午)\s*)?(\d{1,2}):(\d{2}))?$/Du',$v,$mat)){
        $y=(int)$mat[1];$m=(int)$mat[2];$d=(int)$mat[3];$source='ZH_WITH_YEAR';
        if(isset($mat[5])&&$mat[5]!==''){
            $clock=(int)$mat[5];$minute=(int)$mat[6];$period=$mat[4]??'';
            if($period!==''){
                if($clock<1||$clock>12)return null;
                $h=$clock%12+($period==='下午'?12:0);
            }else{$h=$clock;}
        }
    }else return null;
    if($y!==null&&!checkdate($m,$d,$y))return null;
    if($y===null&&!checkdate($m,$d,2024))return null; // leap-year check for unknown year
    if($h!==null&&($h>23||$minute===null||$minute>59))return null;
    return ['year'=>$y,'month'=>$m,'day'=>$d,'hour'=>$h,'minute'=>$minute,'source'=>$source];
}
function classifyType($value):string {
    if(is_array($value)){
        if(imageIdOf($value)!==null)return 'acf_image_object_with_id';
        if(isset($value['url']))return 'acf_image_object_url_only';
        return 'array_without_media_id';
    }
    if($value===null)return 'null';
    if(is_bool($value))return 'boolean';
    if(is_int($value))return 'integer';
    if(is_float($value))return 'number';
    if(is_string($value)&&ctype_digit($value))return 'numeric_string';
    return gettype($value);
}
function compareField($actual,$expected,string $key,array $media=[]):array {
    if(str_contains($key,'照片')){
        $e=imageIdOf($expected);
        if($e===null)return ['ok'=>false,'mode'=>'EXPECTED_MEDIA_ID_MISSING'];
        return mediaValueProof($actual,$e,$media);
    }
    if($key==='日期'){
        $a=dateParts($actual);$e=dateParts($expected);
        $mdMatched=$a!==null&&$e!==null&&$a['month']===$e['month']&&$a['day']===$e['day'];
        $yearVisible=$a!==null&&$a['year']!==null;
        $yearMatched=$mdMatched&&(!$yearVisible||$a['year']===$e['year']);
        $timeVisible=$a!==null&&$a['hour']!==null&&$a['minute']!==null;
        $timeMatched=$timeVisible&&$e!==null&&$e['hour']!==null&&$a['hour']===$e['hour']&&$a['minute']===$e['minute'];
        $ok=$mdMatched&&$yearMatched&&$timeMatched;
        return ['ok'=>$ok,'mode'=>$a['source']??'UNPARSEABLE',
                'actual_type'=>classifyType($actual),
                'expected_date'=>$e===null?null:sprintf('%04d-%02d-%02d',$e['year'],$e['month'],$e['day']),
                'actual_month_day'=>$a===null?null:sprintf('%02d-%02d',$a['month'],$a['day']),
                'actual_year'=>$a['year']??null,'year_not_independently_exposed'=>!$yearVisible,
                'time_visible'=>$timeVisible,'expected_24h_time'=>$e===null?null:sprintf('%02d:%02d',$e['hour']??0,$e['minute']??0),
                'actual_24h_time'=>$timeVisible?sprintf('%02d:%02d',$a['hour'],$a['minute']):null,
                'month_day_and_time_verified'=>$mdMatched&&$timeMatched];
    }
    if($actual===null)return ['ok'=>$expected===null||$expected==='','mode'=>'NULL','actual_type'=>'null'];
    if(is_array($actual))return ['ok'=>false,'mode'=>'UNEXPECTED_ARRAY','actual_type'=>classifyType($actual)];
    if(is_bool($actual))$ok=in_array((string)$expected,[$actual?'1':'0',$actual?'true':'false'],true)||is_bool($expected)&&$actual===$expected;
    elseif(is_int($actual)||is_float($actual))$ok=is_numeric($expected)&&(string)$actual===(string)$expected;
    else $ok=is_scalar($actual)&&(string)$actual===(string)$expected;
    return ['ok'=>$ok,'mode'=>'SCALAR_EXACT','actual_type'=>classifyType($actual)];
}
function diffFields(array $payload,array $readback,array $media=[]):array {
    $missing=[];$mismatched=[];$special=[];
    foreach($payload as $key=>$expected){
        if(!array_key_exists($key,$readback)){$missing[]=$key;continue;}
        $cmp=compareField($readback[$key],$expected,$key,$media);
        if(!$cmp['ok'])$mismatched[]=$key;
        if($key==='日期'||str_contains($key,'照片'))$special[$key]=$cmp;
    }
    return ['expected_keys'=>count($payload),'present_keys'=>count($payload)-count($missing),
            'missing_keys'=>$missing,'mismatched_keys'=>$mismatched,
            'special_field_evidence'=>$special,'all_43_values_match'=>!$missing&&!$mismatched];
}

/** Fingerprint only source parameters; no server secrets or original article content. */
function fingerprint(array $plan):string {
    $a=['sha'=>PPB_LEGACY_SHA,'keys'=>$plan['definition']['all_keys'],
       'people'=>array_map(fn($p)=>[$p['id'],$p['name'],$p['photo'],$p['author_id']],array_values($plan['people']))];
    return hash('sha256',json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
function selftest():void {
    foreach([
        ['11月23日 下午 12:34','2026-11-23 12:34:00'],
        ['11月24日 下午 1:45','2026-11-24 13:45:00'],
        ['11月25日 下午 3:16','2026-11-25 15:16:00'],
        ['11月25日 上午 12:05','2026-11-25 00:05:00'],
        ['11月25日 下午 12:05','2026-11-25 12:05:00'],
        ['2026-11-25T15:16:00+08:00','2026-11-25 15:16:00'],
        ['2026年11月25日 下午 3:16','2026-11-25 15:16:00'],
    ] as [$raw,$expected]){
        $r=compareField($raw,$expected,'日期');
        if(!$r['ok'])stop('SELFTEST_LOCALIZED_DATE_FAILED_'.hash('sha256',$raw));
    }
    foreach([
        ['11月24日 下午 1:45','2026-11-25 13:45:00'],
        ['11月24日 上午 1:45','2026-11-24 13:45:00'],
        ['11月24日 下午 1:46','2026-11-24 13:45:00'],
        ['11月24日 下午 13:45','2026-11-24 13:45:00'],
        ['11月99日 下午 1:45','2026-11-24 13:45:00'],
        ['2025-11-24 13:45:00','2026-11-24 13:45:00'],
        ['11月24日','2026-11-24 13:45:00'],
    ] as [$raw,$expected])if(compareField($raw,$expected,'日期')['ok'])stop('SELFTEST_WRONG_DATE_ACCEPTED');
    $l=compareField('11月24日 下午 1:45','2026-11-24 13:45:00','日期');
    if(($l['year_not_independently_exposed']??false)!==true||($l['actual_24h_time']??'')!=='13:45')stop('SELFTEST_YEAR_AND_12H_METADATA');
    $changed=diffFields(['日期'=>'2026-11-25 15:16:00','分析師5推薦短文'=>'NEW'],['日期'=>'11月24日 下午 1:45','分析師5推薦短文'=>'OLD']);
    if($changed['all_43_values_match']||count($changed['mismatched_keys'])!==2)stop('SELFTEST_CHANGE_DETECTION');
    // Test strict media comparison unchanged from WPF5.
    $img=mediaEvidence(['id'=>15708,'media_type'=>'image','media_details'=>['file'=>'2026/11/a.jpg']],15708,'example.org');
    if(!compareField('https://example.org/wp-content/uploads/2026/11/a.jpg',15708,'分析師1照片',[15708=>$img])['ok'])stop('SELFTEST_MEDIA_COMPATIBILITY');
    if(compareField('https://example.org/wp-content/uploads/2026/11/wrong.jpg',15708,'分析師1照片',[15708=>$img])['ok'])stop('SELFTEST_MEDIA_WRONG_ACCEPTED');
    emit('PPB_WPF6_OFFLINE_LOCALIZED_DATE_AND_MEDIA_TEST_PASS');
}