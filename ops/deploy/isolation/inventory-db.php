<?php
declare(strict_types=1);
// Fixed metadata-only inspection. Never load application/config PHP or credentials files.
try {
    $e=json_decode(stream_get_contents(STDIN,200000),true,512,JSON_THROW_ON_ERROR);
    if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$e['DB_DATABASE']??''))throw new Exception();
    $pdo=new PDO('mysql:host='.$e['DB_HOST'].';port='.($e['DB_PORT']??'3306').';dbname='.$e['DB_DATABASE'].';charset=utf8mb4',$e['DB_USERNAME'],$e['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]);
    $grants=$pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
    $grants=array_map(fn($g)=>preg_replace('/\s+IDENTIFIED\b.*$/i',' [AUTHENTICATION REDACTED]',$g),$grants);
    $q=$pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME');$q->execute([$e['DB_DATABASE']]);
    $tables=$q->fetchAll(PDO::FETCH_ASSOC);if(count($tables)>300)throw new Exception();
    $objects=[];foreach(['VIEWS'=>'TABLE_SCHEMA','ROUTINES'=>'ROUTINE_SCHEMA','TRIGGERS'=>'TRIGGER_SCHEMA','EVENTS'=>'EVENT_SCHEMA'] as $kind=>$column){$q=$pdo->prepare("SELECT DEFINER, COUNT(*) AS objects FROM information_schema.$kind WHERE $column=? GROUP BY DEFINER");$q->execute([$e['DB_DATABASE']]);$objects[$kind]=$q->fetchAll(PDO::FETCH_ASSOC);}
    $q=$pdo->prepare('SELECT CONSTRAINT_SCHEMA,TABLE_NAME,UNIQUE_CONSTRAINT_SCHEMA,REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA<>UNIQUE_CONSTRAINT_SCHEMA AND (CONSTRAINT_SCHEMA=? OR UNIQUE_CONSTRAINT_SCHEMA=?)');$q->execute([$e['DB_DATABASE'],$e['DB_DATABASE']]);
    $out=['actor'=>$pdo->query('SELECT CURRENT_USER()')->fetchColumn(),'database'=>$pdo->query('SELECT DATABASE()')->fetchColumn(),'grants'=>$grants,'tables'=>$tables,'objects'=>$objects,'external_foreign_keys'=>$q->fetchAll(PDO::FETCH_ASSOC)];
    // Check credentialless local DBA availability, never reuse a legacy root password.
    $out['credentialless_local_dba']='UNAVAILABLE';
    try{$admin=new PDO('mysql:host=localhost;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>3]);$out['credentialless_local_dba']=$admin->query('SELECT CURRENT_USER()')->fetchColumn();}catch(Throwable $ignored){}
    echo json_encode($out,JSON_THROW_ON_ERROR);
}catch(Throwable $e){fwrite(STDERR,"METADATA_INVENTORY_FAILED\n");exit(2);}
