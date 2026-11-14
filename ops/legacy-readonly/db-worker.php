<?php
// Fixed read-only query compiler. No SQL, DSN, password, URL or filesystem path from requests.
declare(strict_types=1);
const TEXT_DOMAINS=['pay_analyst_pick'=>['analyst_name'=>'numeric_id'],'pay_analyst_monthly_evaluation'=>['eval_month'=>'year_month'],'pay_analyst_monthly_rating'=>['rating_month'=>'year_month'],'pay_schedule'=>['market_type'=>'market_code']];
const PRIVATE_JOINS=['pay_analyst_profiles'=>['analyst_name'],'pay_video_schedule'=>['guest_1_name','guest_2_name','guest_3_name']];
function domainValue($value,$domain):bool {
 if(!is_string($value)&&!is_int($value))return false;
 return match($domain){'numeric_id'=>(bool)preg_match('/^[0-9]{1,18}$/D',(string)$value),'year_month'=>(bool)preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D',(string)$value),'market_code'=>(bool)preg_match('/^[0-9]{0,3}$/D',(string)$value),default=>false};
}
function must($ok,$code){if(!$ok)throw new RuntimeException($code);}
function privateData($name){
 $p='/etc/ppb-legacy-ro/'.$name;$s=lstat($p);
 must($s&&($s['mode']&0170000)===0100000&&$s['uid']===0&&$s['gid']===posix_getegid()&&($s['mode']&0027)===0&&$s['nlink']===1&&$s['size']<1000000,'DB_CONFIG_UNSAFE');
 return json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
}
function ident($s){must(is_string($s)&&preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D',$s),'SQL_IDENTIFIER_DENIED');return '`'.$s.'`';}
function checkedGrants(array $grants,array $catalog):array {
 $seen=[];$metadata=false;
 foreach($grants as $grant){
  must(!preg_match('/\bWITH GRANT OPTION\b/i',$grant),'DB_WRITE_OR_GLOBAL_AUTHORITY_DENIED');
  if(preg_match('/^GRANT USAGE ON \*\.\* TO /',$grant))continue;
  if(preg_match('/^GRANT ([A-Z ,]+) ON `([^`]+)`\.\* TO /',$grant,$m)){
    $privs=array_map('trim',explode(',',$m[1]));must(!array_diff($privs,['REFERENCES','SHOW VIEW'])&&$m[2]===$catalog['schema'],'METADATA_GRANT_SCOPE_DENIED');$metadata=true;continue;
  }
  must(preg_match('/^GRANT SELECT \(([^)]+)\) ON `([^`]+)`\.`([^`]+)` TO /',$grant,$m),'ONLY_COLUMN_SELECT_GRANTS_ALLOWED');
  $key=$m[2].'.'.$m[3];must(isset($catalog['tables'][$key]),'UNAPPROVED_DB_SCOPE');
  $table=$catalog['tables'][$key];$private=$table['join_columns']??[];
  must(!array_diff($private,PRIVATE_JOINS[$table['name']??'']??[]),'PRIVATE_JOIN_SCOPE_DENIED');
  $cols=array_map(fn($s)=>trim($s," `"),explode(',',$m[1]));must(!array_diff($cols,array_merge($table['data_columns'],$private)),'UNAPPROVED_COLUMN_GRANT');$seen[$key]=$cols;
 }
 must(count($seen)>0&&$metadata,'NO_READONLY_DATA_OR_METADATA_GRANTS');
 return $seen;
}
function selectPlan(array $q,array $table,array $granted):array {
   // Private name grants exist ONLY for fixed server-side aggregate joins.
   $granted=array_values(array_intersect($granted,$table['data_columns']));
   $cols=$q['columns']??[];must(is_array($cols)&&count($cols)>0&&count($cols)<=12&&!array_diff($cols,$granted),'SELECT_COLUMNS_DENIED');
   $limit=$q['limit']??30;$offset=$q['offset']??0;must(is_int($limit)&&$limit>=1&&$limit<=50&&is_int($offset)&&$offset>=0&&$offset<=5000,'SELECT_ROW_LIMIT');
   $filters=$q['filters']??[];must(is_array($filters)&&count($filters)<=8,'FILTER_LIMIT');$where=[];$params=[];
   foreach($filters as $f){
    must(is_array($f)&&array_keys($f)===['column','op','value']&&in_array($f['column'],$granted,true)&&in_array($f['op'],['=','<','<=','>','>='],true),'FILTER_DENIED');
    must(is_scalar($f['value'])&&strlen((string)$f['value'])<=64,'FILTER_VALUE_DENIED');$where[]=ident($f['column']).' '.$f['op'].' ?';$params[]=$f['value'];
    if(isset($table['text_domains'][$f['column']]))must((TEXT_DOMAINS[$table['name']][$f['column']]??null)===$table['text_domains'][$f['column']]&&domainValue($f['value'],$table['text_domains'][$f['column']]),'BUSINESS_KEY_FILTER_DENIED');
   }
   $primary=array_values(array_filter($table['indexes']??[],fn($i)=>$i['name']==='PRIMARY'));usort($primary,fn($a,$b)=>$a['sequence']<=>$b['sequence']);$order=array_column($primary,'column');
   must($offset===0||($order&&!array_diff($order,$granted)),'PAGINATION_REQUIRES_APPROVED_PRIMARY_KEY');
   $ordering=$order&&!array_diff($order,$granted)?' ORDER BY '.implode(',',array_map('ident',$order)):'';
   $sql='SELECT /*+ MAX_EXECUTION_TIME(1000) */ '.implode(',',array_map('ident',$cols)).' FROM '.ident($table['schema']).'.'.ident($table['name']).($where?' WHERE '.implode(' AND ',$where):'').$ordering.' LIMIT '.$limit.' OFFSET '.$offset;
 return [$sql,$params];
}
function videoPlan(array $q,array $catalog,array $seen):array {
  $id=$q['analyst_id']??null;$start=$q['start']??'';$end=$q['end']??'';
  must(is_int($id)&&$id>0&&$id<=2147483647,'ANALYST_ID_DENIED');
  $a=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$start);$b=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$end);
  must($a&&$b&&$a->format('Y-m-d H:i:s')===$start&&$b->format('Y-m-d H:i:s')===$end&&$b>=$a&&$b->getTimestamp()-$a->getTimestamp()<=32*86400,'VIDEO_PERIOD_DENIED');
  $schema=$catalog['schema'];$profiles=$schema.'.pay_analyst_profiles';$video=$schema.'.pay_video_schedule';
  must(!array_diff(['id','analyst_name'],$seen[$profiles]??[])&&!array_diff(['schedule_date','guest_1_name','guest_2_name','guest_3_name'],$seen[$video]??[]),'PRIVATE_AGGREGATE_GRANTS_MISSING');
  $query='SELECT /*+ MAX_EXECUTION_TIME(1000) */ COUNT(*) FROM '.ident($schema).'.`pay_video_schedule` v JOIN '.ident($schema).'.`pay_analyst_profiles` a ON a.id=? WHERE v.schedule_date BETWEEN ? AND ? AND TRIM(a.analyst_name)<>\'\' AND (v.guest_1_name=TRIM(a.analyst_name) OR v.guest_2_name=TRIM(a.analyst_name) OR v.guest_3_name=TRIM(a.analyst_name))';
  return [$query,[$id,$start,$end]];
}
require_once __DIR__.'/evaluation-snapshot.php';
if(realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){try{
 must(posix_geteuid()>0,'NONROOT_DB_REQUIRED');$q=json_decode(stream_get_contents(STDIN,12000),true,512,JSON_THROW_ON_ERROR);
 must(is_array($q)&&!array_diff(array_keys($q),['operation','table','columns','filters','limit','offset','analyst_id','start','end','month','summary_only']),'DB_REQUEST_SCHEMA');
 $config=privateData('credentials.json');$catalog=privateData('catalog.json');
 must(array_keys($config)===['user','password','socket']&&$config['user']==='ppb_legacy_ro'&&is_string($config['password']),'DEDICATED_READONLY_ACCOUNT_REQUIRED');
 $pdo=new PDO('mysql:unix_socket=/run/ppb-legacy-ro/mysql.sock;charset=utf8mb4',$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $actor=$pdo->query('SELECT CURRENT_USER()')->fetchColumn();must($actor==='ppb_legacy_ro@localhost','WRONG_READONLY_DB_ACTOR');
 must(str_starts_with((string)$pdo->query('SELECT VERSION()')->fetchColumn(),'5.7.'),'MYSQL_VERSION_NOT_REVIEWED');
 $seen=checkedGrants($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN),$catalog);
 $pdo->exec('SET SESSION MAX_EXECUTION_TIME=1000');$pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('SET SESSION TRANSACTION READ ONLY');$pdo->beginTransaction();
 must((int)$pdo->query('SELECT @@session.tx_read_only')->fetchColumn()===1,'READONLY_TRANSACTION_NOT_ENFORCED');
 must($pdo->query('SELECT @@session.tx_isolation')->fetchColumn()==='REPEATABLE-READ','CONSISTENT_READ_NOT_ENFORCED');
 $op=$q['operation']??'';must(in_array($op,['db_permissions','schema','select','video_count','evaluation_snapshot'],true),'DB_OPERATION_DENIED');
 $out=['db_actor'=>$actor,'old_db_write_grants'=>false,'global_privileges'=>false,'transaction_readonly'=>true,'transaction_isolation'=>'REPEATABLE-READ','database_writes'=>0];
 if($op==='db_permissions'){
  $out['authorized_tables']=array_keys($seen);$out['schema_snapshot_sha256']=hash_file('sha256','/etc/ppb-legacy-ro/catalog.json');$out['grant_option']=false;$out['evaluation_domains_enabled']=!empty($catalog['evaluation_read_v1']);
  $out['typed_key_grants']=[];$out['private_join_grants']=[];
  foreach(TEXT_DOMAINS as $table=>$columns)foreach($columns as $column=>$domain)$out['typed_key_grants'][$table.'.'.$column]=in_array($column,$seen[$catalog['schema'].'.'.$table]??[],true);
  foreach(PRIVATE_JOINS as $table=>$columns)foreach($columns as $column)$out['private_join_grants'][$table.'.'.$column]=in_array($column,$seen[$catalog['schema'].'.'.$table]??[],true);
  if($out['evaluation_domains_enabled']){$runtime=privateData('runtime.json');must(preg_match('/^[a-f0-9]{64}$/D',$runtime['original_catalog_sha256']??''),'ORIGINAL_CATALOG_ATTESTATION_REQUIRED');$out['original_catalog_sha256']=$runtime['original_catalog_sha256'];}
 }
 elseif($op==='evaluation_snapshot'){$out['snapshot']=evaluationSnapshot($pdo,$q,$catalog,$seen);}
 elseif($op==='video_count'){
  must(!empty($catalog['evaluation_read_v1']),'EVALUATION_DOMAIN_EXTENSION_REQUIRED');
  [$query,$params]=videoPlan($q,$catalog,$seen);
  $stmt=$pdo->prepare($query);$stmt->execute($params);$out['analyst_id']=$q['analyst_id'];$out['video_count']=(int)$stmt->fetchColumn();$out['private_names_returned']=0;
 }
 else{
  $key=$q['table']??'';must(isset($catalog['tables'][$key]),'TABLE_NOT_AUTHORIZED');$table=$catalog['tables'][$key];
  if($op==='schema'){
   // Full DBA metadata snapshot is labelled; live metadata restricted to granted visibility.
   $limit=$q['limit']??30;$offset=$q['offset']??0;must(is_int($limit)&&$limit>=1&&$limit<=50&&is_int($offset)&&$offset>=0&&$offset<=5000,'SCHEMA_PAGE_LIMIT');
   $out['table']=$key;$out['reviewed_schema_snapshot']=array_slice($table['columns'],$offset,$limit);$out['reviewed_indexes']=array_slice($table['indexes'],0,50);$out['snapshot_time']=$catalog['created_at'];$out['column_offset']=$offset;
   $stmt=$pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE,COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION LIMIT '.$limit.' OFFSET '.$offset);$stmt->execute([$table['schema'],$table['name']]);$out['live_visible_columns']=$stmt->fetchAll();
   $stmt=$pdo->prepare('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX LIMIT 50');$stmt->execute([$table['schema'],$table['name']]);$out['live_indexes']=$stmt->fetchAll();
   $stmt=$pdo->prepare('SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 50');$stmt->execute([$table['schema'],$table['name']]);$out['live_keys']=$stmt->fetchAll();
  }else{
   must(isset($seen[$key]),'TABLE_DATA_NOT_AUTHORIZED');
   $limit=$q['limit']??30;$offset=$q['offset']??0;
   [$sql,$params]=selectPlan($q,$table,$seen[$key]);
   $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
   // No text/PII grants are provisioned; enforce the reviewed type domain on each cell again.
   foreach($rows as $row)foreach($row as $k=>$v){if($v===null)continue;$type=$table['types'][$k];
    must(is_scalar($v)&&strlen((string)$v)<=80,'OUTPUT_CELL_LIMIT');
    if(isset($table['text_domains'][$k]))must(is_string($v)&&(TEXT_DOMAINS[$table['name']][$k]??null)===$table['text_domains'][$k]&&domainValue($v,$table['text_domains'][$k]),'BUSINESS_KEY_OUTPUT_DENIED');
    elseif(in_array($type,['date','datetime','timestamp','time','year'],true))must(preg_match('/^[0-9: .+\-]+$/D',(string)$v),'DATE_OUTPUT_DENIED');
    else must(is_numeric($v),'NONNUMERIC_BUSINESS_OUTPUT_DENIED');
   }
   $out['table']=$key;$out['rows']=$rows;$out['limit']=$limit;$out['offset']=$offset;
  }
 }
 $pdo->rollBack();echo json_encode(['result'=>'PASS','data'=>$out],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){$code=$e->getMessage();echo json_encode(['result'=>'STOP','reason'=>preg_match('/^[A-Z0-9_]{1,100}$/D',$code)?$code:'READONLY_DB_NOT_AVAILABLE']);exit(2);}

}
