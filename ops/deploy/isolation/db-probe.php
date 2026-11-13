<?php
declare(strict_types=1);
// Installed fixed tool: never bootstrap Laravel, include app PHP or emit passwords.
try {
    $e=json_decode(stream_get_contents(STDIN,200000),true,512,JSON_THROW_ON_ERROR);
    if(!in_array($e['DB_CONNECTION']??'', ['mysql','mariadb'],true)||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$e['DB_DATABASE']??''))throw new Exception();
    $pdo=new PDO('mysql:host='.$e['DB_HOST'].';port='.($e['DB_PORT']??'3306').';dbname='.$e['DB_DATABASE'].';charset=utf8mb4',$e['DB_USERNAME'],$e['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>8]);
    $row=$pdo->query('SELECT DATABASE() AS db, CURRENT_USER() AS actor')->fetch(PDO::FETCH_ASSOC);
    // MySQL 8 exposes effective active roles; MariaDB/older servers fail closed.
    $version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
    if(!str_contains(strtolower($version),'mariadb')&&preg_match('/^([0-9]+)\./',$version,$v)&&(int)$v[1]<8)$roles='NONE';
    else $roles=$pdo->query('SELECT CURRENT_ROLE()')->fetchColumn();
    $grants=$pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
    $q=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE=?');
    $q->execute([$row['db'],'BASE TABLE']);
    $engines=$q->fetchAll(PDO::FETCH_COLUMN);$definers=[];
    foreach(['SELECT DEFINER FROM information_schema.VIEWS WHERE TABLE_SCHEMA=?','SELECT DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=?','SELECT DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=?','SELECT DEFINER FROM information_schema.EVENTS WHERE EVENT_SCHEMA=?'] as $sql){$q=$pdo->prepare($sql);$q->execute([$row['db']]);$definers=array_merge($definers,$q->fetchAll(PDO::FETCH_COLUMN));}
    $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA <> UNIQUE_CONSTRAINT_SCHEMA AND (CONSTRAINT_SCHEMA=? OR UNIQUE_CONSTRAINT_SCHEMA=?)');$q->execute([$row['db'],$row['db']]);$cross=(int)$q->fetchColumn();
    echo json_encode(['current_user'=>$row['actor'],'definers'=>$definers,'cross_schema_foreign_keys'=>$cross,'database'=>$row['db'],'user'=>explode('@',$row['actor'])[0],'roles'=>$roles,'grants'=>$grants,'table_engines'=>$engines],JSON_THROW_ON_ERROR);
} catch(Throwable $e) {fwrite(STDERR,"DB_SCOPE_PROBE_FAILED\n");exit(2);}
