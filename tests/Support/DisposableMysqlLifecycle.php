<?php
$root=dirname(__DIR__,2);$loader=require $root.'/vendor/autoload.php';$loader->setPsr4('Tests\\',$root.'/tests');
$map=[];foreach($loader->getClassMap() as $c=>$f)if(str_starts_with($c,'Tests\\'))$map[$c]=$root.'/tests/'.str_replace('\\','/',substr($c,6)).'.php';$loader->addClassMap($map);
if(getenv('APP_ENV')!=='testing')exit(2);
$container=null;$pdo=null;$code=0;
try{
    $container=new Tests\Support\DisposableMysql57;$pdo=$container->pdo();
    $pdo->exec('CREATE TABLE ppb_cleanup_sentinel (id INT PRIMARY KEY) ENGINE=InnoDB');$pdo->exec('INSERT INTO ppb_cleanup_sentinel VALUES(1)');
    echo json_encode(['type'=>'ready','name'=>$container->name,'directory'=>$container->directory,'sentinel_rows'=>(int)$pdo->query('SELECT COUNT(*) FROM ppb_cleanup_sentinel')->fetchColumn(),'isolation'=>$container->proof])."\n";flush();
    $line=trim((string)fgets(STDIN));
    if($line==='fail')throw new RuntimeException('INJECTED_PARENT_FAILURE');
    if($line==='timeout'){$container->arm(2);echo "{\"type\":\"armed\"}\n";flush();fgets(STDIN);}
}catch(Throwable $e){$code=1;echo "{\"type\":\"failed\"}\n";flush();}
finally{$pdo=null;if($container)$container->destroy();echo "{\"type\":\"cleaned\"}\n";flush();}
exit($code);
