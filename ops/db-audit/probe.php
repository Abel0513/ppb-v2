<?php
declare(strict_types=1);
// Fixed metadata-only program: no caller SQL, no business table SELECT, no app bootstrap.
function connection(array $e): PDO {
    if(($e['DB_CONNECTION']??'')!=='mysql'||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$e['DB_DATABASE']??'')||!preg_match('/^[A-Za-z0-9._:-]+$/D',$e['DB_HOST']??'')||!preg_match('/^[0-9]{1,5}$/D',(string)($e['DB_PORT']??'3306'))||!preg_match('/^[A-Za-z0-9_]{1,32}$/D',$e['DB_USERNAME']??''))throw new Exception();
    return new PDO('mysql:host='.$e['DB_HOST'].';port='.($e['DB_PORT']??'3306').';dbname='.$e['DB_DATABASE'].';charset=utf8mb4',$e['DB_USERNAME'],$e['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>8,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function referenceOnly(array $grants,string $actor,mixed $roles): bool {
    if($actor!=='ppb_v2_metadata@localhost'||$roles!=='NONE')return false;
    $reference=false;
    foreach($grants as $g){
        if(preg_match("/^GRANT REFERENCES ON \\*\\.\\* TO 'ppb_v2_metadata'@'localhost'$/D",$g))$reference=true;
        elseif(!preg_match("/^GRANT USAGE ON \\*\\.\\* TO 'ppb_v2_metadata'@'localhost'$/D",$g))return false;
    }
    return $reference;
}
function supportedServer(string $version): bool {
    return !str_contains(strtolower($version),'mariadb')&&preg_match('/^8\\.(?:0|4)\\./',$version)===1;
}
if(defined('PPB_METADATA_POLICY_TEST'))return;
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
    $ownScoped=$identity['actor']==='ppbaos@localhost'&&$identity['db']==='ppbaos'&&$roles==='NONE';$ownAll=false;
    foreach($raw as $grant){
        if(preg_match("/^GRANT ALL PRIVILEGES ON `ppbaos`\\.\\* TO 'ppbaos'@'localhost'$/D",$grant))$ownAll=true;
        elseif(!preg_match("/^GRANT USAGE ON \\*\\.\\* TO 'ppbaos'@'localhost'$/D",$grant))$ownScoped=false;
    }
    $out=['result'=>'STOP','identity'=>$identity,'roles'=>$roles,'effective_grants'=>$grants,'v2_privileges_scoped'=>$ownScoped&&$ownAll,'v2_object_metadata_coverage_proven'=>$ownScoped&&$ownAll,'global_fk_visibility_proven'=>false,'fk_scope'=>'CURRENT_ACCOUNT_VISIBLE_SCHEMAS_ONLY','visible_cross_schema_fks'=>$fks,'v2_objects'=>$objects,'business_table_queries'=>0,'ddl_dml_statements'=>0,'credential_output'=>false];
    $out['metadata_connection_file_present']=isset($in['metadata']);
    $out['reason']='AUTHORIZED_METADATA_IDENTITY_NOT_PROVISIONED';
    if(isset($in['metadata'])) {
        $m=$in['metadata'];
        foreach(['DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE'] as $key)if(($m[$key]??null)!==($in['v2'][$key]??null))throw new Exception();
        if(($m['DB_USERNAME']??'')!=='ppb_v2_metadata')throw new Exception();
        $a=connection($m);$actor=$a->query('SELECT CURRENT_USER()')->fetchColumn();
        $metaRoles=$a->query('SELECT CURRENT_ROLE()')->fetchColumn();
        $mg=$a->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
        $readerScoped=referenceOnly($mg,$actor,$metaRoles);
        // Certified backend: MySQL 8 DD CAN_ACCESS_TABLE uses TABLE_OP_ACLS.
        // Global REFERENCES covers every table while granting no row SELECT or DDL/DML.
        $supported=supportedServer($identity['version']);
        $out['metadata_actor']=$actor;$out['metadata_roles']=$metaRoles;
        $out['metadata_grants_verified_reference_only']=$readerScoped;
        $out['global_fk_visibility_proven']=$readerScoped&&$supported;
        if($out['global_fk_visibility_proven']){
            $out['fk_scope']='ALL_SCHEMAS_GLOBAL_REFERENCES_NO_PARTIAL_REVOKES_OR_ROLES';
            $q=$a->prepare('SELECT CONSTRAINT_SCHEMA,CONSTRAINT_NAME,TABLE_NAME,UNIQUE_CONSTRAINT_SCHEMA,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA<>UNIQUE_CONSTRAINT_SCHEMA AND (CONSTRAINT_SCHEMA=? OR UNIQUE_CONSTRAINT_SCHEMA=?) ORDER BY CONSTRAINT_SCHEMA,CONSTRAINT_NAME');$q->execute(['ppbaos','ppbaos']);
            $out['visible_cross_schema_fks']=$q->fetchAll(PDO::FETCH_ASSOC);
            $out['schema_table_metadata_coverage']=$a->query('SELECT TABLE_SCHEMA,COUNT(*) AS visible_tables FROM information_schema.TABLES GROUP BY TABLE_SCHEMA ORDER BY TABLE_SCHEMA')->fetchAll(PDO::FETCH_ASSOC);
            $emptyObjects=array_sum(array_map('count',$objects))===0;
            if(!$ownScoped||!$ownAll)$out['reason']='V2_EFFECTIVE_GRANTS_NOT_SCOPED';
            elseif($out['visible_cross_schema_fks'])$out['reason']='CROSS_SCHEMA_FOREIGN_KEY_FOUND';
            elseif(!$emptyObjects)$out['reason']='V2_SQL_OBJECT_REQUIRES_DEFINITION_REVIEW';
            else{$out['result']='PASS';$out['reason']='GLOBAL_FK_AND_V2_OBJECT_SCOPE_VERIFIED';}
        }else $out['reason']='METADATA_GRANTS_OR_SERVER_VISIBILITY_UNPROVEN';
    }
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){echo json_encode(['result'=>'STOP','reason'=>'FIXED_METADATA_QUERY_FAILED','credential_output'=>false]);exit(2);}
