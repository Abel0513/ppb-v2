<?php
declare(strict_types=1);
// Fixed metadata-only program: no caller SQL, no business table SELECT, no app bootstrap.
function connection(array $e): PDO {
    if(($e['DB_CONNECTION']??'')!=='mysql'||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$e['DB_DATABASE']??'')||!preg_match('/^[A-Za-z0-9._:-]+$/D',$e['DB_HOST']??''))throw new Exception();
    return new PDO('mysql:host='.$e['DB_HOST'].';port='.($e['DB_PORT']??'3306').';dbname='.$e['DB_DATABASE'].';charset=utf8mb4',$e['DB_USERNAME'],$e['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>8,PDO::ATTR_EMULATE_PREPARES=>false]);
}
try {
    $in=json_decode(stream_get_contents(STDIN,200000),true,512,JSON_THROW_ON_ERROR);$p=connection($in['v2']);
    $identity=$p->query('SELECT VERSION() AS version, @@version_comment AS version_comment, DATABASE() AS db, CURRENT_USER() AS actor, USER() AS login')->fetch(PDO::FETCH_ASSOC);
    $raw=$p->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
    $grants=array_map(fn($g)=>preg_replace('/\s+IDENTIFIED\b.*$/i',' [AUTHENTICATION REDACTED]',$g),$raw);
    $roles='UNVERIFIED';try{$roles=$p->query('SELECT CURRENT_ROLE()')->fetchColumn();}catch(Throwable $ignored){}
    $q=$p->prepare('SELECT CONSTRAINT_SCHEMA,CONSTRAINT_NAME,TABLE_NAME,UNIQUE_CONSTRAINT_SCHEMA,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA<>UNIQUE_CONSTRAINT_SCHEMA AND (CONSTRAINT_SCHEMA=? OR UNIQUE_CONSTRAINT_SCHEMA=?) ORDER BY CONSTRAINT_SCHEMA,CONSTRAINT_NAME');$q->execute(['ppbaos','ppbaos']);$fks=$q->fetchAll(PDO::FETCH_ASSOC);
    $objects=[];
    foreach([
        'views'=>['SELECT TABLE_NAME AS name,DEFINER,SECURITY_TYPE,VIEW_DEFINITION AS definition FROM information_schema.VIEWS WHERE TABLE_SCHEMA=?'],
        'triggers'=>['SELECT TRIGGER_NAME AS name,DEFINER,ACTION_STATEMENT AS definition FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=?'],
        'routines'=>['SELECT ROUTINE_NAME AS name,DEFINER,SECURITY_TYPE,ROUTINE_DEFINITION AS definition FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=?'],
        'events'=>['SELECT EVENT_NAME AS name,DEFINER,EVENT_DEFINITION AS definition FROM information_schema.EVENTS WHERE EVENT_SCHEMA=?']
    ] as $kind=>[$sql]) {
        $q=$p->prepare($sql);$q->execute(['ppbaos']);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){$definition=$row['definition'];unset($row['definition']);$row['definition_visible']=$definition!==null;$row['definition_sha256']=$definition===null?null:hash('sha256',$definition);$row['review_required']=true;}unset($row);
        $objects[$kind]=$rows;
    }
    $globalVisible=false;
    foreach($raw as $grant){if(preg_match('/^GRANT (.+) ON \*\.\* TO /i',$grant,$m)&&preg_match('/(?:^|, )(?:SELECT|ALL PRIVILEGES)(?:,|$)/i',$m[1]))$globalVisible=true;}
    $out=['result'=>'STOP','identity'=>$identity,'roles'=>$roles,'effective_grants'=>$grants,'global_fk_visibility_proven'=>$globalVisible,'fk_scope'=>$globalVisible?'ALL_SCHEMAS':'CURRENT_ACCOUNT_VISIBLE_SCHEMAS_ONLY','visible_cross_schema_fks'=>$fks,'v2_objects'=>$objects,'business_table_queries'=>0,'ddl_dml_statements'=>0,'credential_output'=>false];
    $out['metadata_connection_file_present']=isset($in['metadata']);
    if(isset($in['metadata'])) {
        // Do not assume credentials or an arbitrary DBA means certified visibility.
        $out['reason']='DEDICATED_METADATA_INTERFACE_REQUIRES_VERSION_SPECIFIC_CERTIFICATION';
    }else $out['reason']='AUTHORIZED_METADATA_IDENTITY_NOT_PROVISIONED';
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){echo json_encode(['result'=>'STOP','reason'=>'FIXED_METADATA_QUERY_FAILED','credential_output'=>false]);exit(2);}
