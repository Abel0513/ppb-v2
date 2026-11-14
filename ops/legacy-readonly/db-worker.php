<?php
// Fixed read-only query compiler. No SQL, DSN, password, URL or filesystem path from requests.
declare(strict_types=1);
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
  $cols=array_map(fn($s)=>trim($s," `"),explode(',',$m[1]));must(!array_diff($cols,$catalog['tables'][$key]['data_columns']),'UNAPPROVED_COLUMN_GRANT');$seen[$key]=$cols;
 }
 must(count($seen)>0&&$metadata,'NO_READONLY_DATA_OR_METADATA_GRANTS');
 return $seen;
}
function selectPlan(array $q,array $table,array $granted):array {
   $cols=$q['columns']??[];must(is_array($cols)&&count($cols)>0&&count($cols)<=12&&!array_diff($cols,$granted),'SELECT_COLUMNS_DENIED');
   $limit=$q['limit']??30;$offset=$q['offset']??0;must(is_int($limit)&&$limit>=1&&$limit<=50&&is_int($offset)&&$offset>=0&&$offset<=5000,'SELECT_ROW_LIMIT');
   $filters=$q['filters']??[];must(is_array($filters)&&count($filters)<=8,'FILTER_LIMIT');$where=[];$params=[];
   foreach($filters as $f){
    must(is_array($f)&&array_keys($f)===['column','op','value']&&in_array($f['column'],$granted,true)&&in_array($f['op'],['=','<','<=','>','>='],true),'FILTER_DENIED');
    must(is_scalar($f['value'])&&strlen((string)$f['value'])<=64,'FILTER_VALUE_DENIED');$where[]=ident($f['column']).' '.$f['op'].' ?';$params[]=$f['value'];
   }
   $sql='SELECT /*+ MAX_EXECUTION_TIME(1000) */ '.implode(',',array_map('ident',$cols)).' FROM '.ident($table['schema']).'.'.ident($table['name']).($where?' WHERE '.implode(' AND ',$where):'').' LIMIT '.$limit.' OFFSET '.$offset;
 return [$sql,$params];
}
if(realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){try{
 must(posix_geteuid()>0,'NONROOT_DB_REQUIRED');$q=json_decode(stream_get_contents(STDIN,12000),true,512,JSON_THROW_ON_ERROR);
 must(is_array($q)&&!array_diff(array_keys($q),['operation','table','columns','filters','limit','offset']),'DB_REQUEST_SCHEMA');
 $config=privateData('credentials.json');$catalog=privateData('catalog.json');
 must(array_keys($config)===['user','password','socket']&&$config['user']==='ppb_legacy_ro'&&is_string($config['password']),'DEDICATED_READONLY_ACCOUNT_REQUIRED');
 $pdo=new PDO('mysql:unix_socket=/run/ppb-legacy-ro/mysql.sock;charset=utf8mb4',$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $actor=$pdo->query('SELECT CURRENT_USER()')->fetchColumn();must($actor==='ppb_legacy_ro@localhost','WRONG_READONLY_DB_ACTOR');
 must(str_starts_with((string)$pdo->query('SELECT VERSION()')->fetchColumn(),'5.7.'),'MYSQL_VERSION_NOT_REVIEWED');
 $seen=checkedGrants($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN),$catalog);
 $pdo->exec('SET SESSION MAX_EXECUTION_TIME=1000');$pdo->exec('SET SESSION TRANSACTION READ ONLY');$pdo->beginTransaction();
 must((int)$pdo->query('SELECT @@session.tx_read_only')->fetchColumn()===1,'READONLY_TRANSACTION_NOT_ENFORCED');
 $op=$q['operation']??'';must(in_array($op,['db_permissions','schema','select'],true),'DB_OPERATION_DENIED');
 $out=['db_actor'=>$actor,'old_db_write_grants'=>false,'global_privileges'=>false,'transaction_readonly'=>true,'database_writes'=>0];
 if($op==='db_permissions'){$out['authorized_tables']=array_keys($seen);$out['schema_snapshot_sha256']=hash_file('sha256','/etc/ppb-legacy-ro/catalog.json');}
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
   [$sql,$params]=selectPlan($q,$table,$seen[$key]);
   $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
   // No text/PII grants are provisioned; enforce the reviewed type domain on each cell again.
   foreach($rows as $row)foreach($row as $k=>$v){if($v===null)continue;$type=$table['types'][$k];
    must(is_scalar($v)&&strlen((string)$v)<=80,'OUTPUT_CELL_LIMIT');
    if(in_array($type,['date','datetime','timestamp','time','year'],true))must(preg_match('/^[0-9: .+\-]+$/D',(string)$v),'DATE_OUTPUT_DENIED');
    else must(is_numeric($v),'NONNUMERIC_BUSINESS_OUTPUT_DENIED');
   }
   $out['table']=$key;$out['rows']=$rows;$out['limit']=$limit;$out['offset']=$offset;
  }
 }
 $pdo->rollBack();echo json_encode(['result'=>'PASS','data'=>$out],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){$code=$e->getMessage();echo json_encode(['result'=>'STOP','reason'=>preg_match('/^[A-Z0-9_]{1,100}$/D',$code)?$code:'READONLY_DB_NOT_AVAILABLE']);exit(2);}

}
