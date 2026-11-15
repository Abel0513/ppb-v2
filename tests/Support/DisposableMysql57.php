<?php
namespace Tests\Support;
use Symfony\Component\Process\{Process,InputStream};

/** Test-only shared-table exception: all MySQL data and logs remain in tmpfs. */
final class DisposableMysql57
{
    public const IMAGE='mysql:5.7.44@sha256:4bc6bc963e6d8443453676cae56536f4b8156d78bae03c0145cbe47c2aad73bb';
    public readonly string $name;
    public readonly string $directory;
    private Process $guardian;
    private InputStream $input;
    private bool $verified=false;
    private string $owner;
    public array $proof=[];
    public function __construct()
    {
        $this->name='ppb-concurrent-'.bin2hex(random_bytes(6));$this->directory='/tmp/'.$this->name;
        $this->owner=bin2hex(random_bytes(16));
        if(!mkdir($this->directory,0700))throw new \RuntimeException('DISPOSABLE_IPC_CREATE_FAILED');
        chmod($this->directory,0700);
        register_shutdown_function(function(){try{$this->destroy();}catch(\Throwable $ignored){}});
        // Guardian owns creation AND cleanup: SIGKILL of the parent during
        // docker run cannot leave a late-created unguarded database.
        $script= <<<'SH'
set -eu
name="$1"; ipc="$2"; image="$3"; uid="$4"; gid="$5"; owner="$6"
[[ "$name" =~ ^ppb-concurrent-[a-f0-9]{12}$ && "$ipc" == "/tmp/$name" ]]
cleanup() {
 if [[ "$(docker inspect --format '{{index .Config.Labels "ppb.test.owner"}}' "$name" 2>/dev/null || true)" == "$owner" ]]; then
  timeout 15 docker rm -f -v "$name" >/dev/null 2>&1 || true
 fi
 rm -f -- "$ipc/mysqld.sock" "$ipc/mysqld.sock.lock" "$ipc/mysqld.pid"
 rmdir -- "$ipc" 2>/dev/null || true
}
trap cleanup EXIT HUP INT TERM PIPE
timeout 45 docker run -d --user "$uid:$gid" --name "$name" --label "ppb.test.owner=$owner" --network=none --read-only --log-driver=none --stop-timeout=1 \
 --tmpfs /var/lib/mysql:rw,nosuid,size=768m,uid=$uid,gid=$gid,mode=0700 --tmpfs /tmp:rw,nosuid,size=128m \
 --tmpfs /var/log/mysql:rw,nosuid,size=32m,uid=$uid,gid=$gid,mode=0700 --mount "type=bind,source=$ipc,target=/run/mysqld" \
 -e MYSQL_ALLOW_EMPTY_PASSWORD=yes "$image" --skip-networking --tmpdir=/tmp --pid-file=/run/mysqld/mysqld.pid \
 --log-error=/var/log/mysql/mysql.log --general-log=0 --slow-query-log=0 --skip-log-bin >/dev/null
printf 'STARTED\n'
while IFS= read -r -t 300 line; do
 [[ "$line" == DONE ]] && exit 0
 if [[ "$line" =~ ^ARM:([0-9]+)$ ]]; then
  seconds="${BASH_REMATCH[1]}"; [[ "$seconds" -ge 1 && "$seconds" -le 240 ]]
  printf 'ARMED\n'
  IFS= read -r -t "$seconds" line || exit 0
  [[ "$line" == DONE ]] && exit 0
 fi
 exit 1
done
SH;
        $this->input=new InputStream;
        $this->guardian=new Process(['timeout','--signal=TERM','--kill-after=20','360','bash','-c',$script,'guardian',$this->name,$this->directory,self::IMAGE,(string)posix_geteuid(),(string)posix_getegid(),$this->owner]);
        $this->guardian->setInput($this->input);$this->guardian->setTimeout(null);$this->guardian->start();
        $deadline=microtime(true)+50;
        while(!str_contains($this->guardian->getOutput(),'STARTED')){
            if(!$this->guardian->isRunning()||microtime(true)>$deadline)throw new \RuntimeException('DISPOSABLE_MYSQL_START_FAILED');usleep(10000);
        }
        $i=json_decode(self::command(['docker','inspect',$this->name]),true,512,JSON_THROW_ON_ERROR)[0];$h=$i['HostConfig'];
        $ok=$h['NetworkMode']==='none'&&$h['ReadonlyRootfs']===true&&$h['LogConfig']['Type']==='none'&&$i['Config']['Image']===self::IMAGE&&$i['Config']['User']===posix_geteuid().':'.posix_getegid()&&($i['Config']['Labels']['ppb.test.owner']??null)===$this->owner;
        foreach(['/var/lib/mysql','/tmp','/var/log/mysql'] as $path)$ok=$ok&&isset($h['Tmpfs'][$path]);
        $binds=0;foreach($i['Mounts'] as $m){if($m['Type']==='bind'){$binds++;$ok=$ok&&$m['Source']===$this->directory&&$m['Destination']==='/run/mysqld';}else $ok=$ok&&$m['Type']==='tmpfs';}
        if(!$ok||$binds!==1)throw new \RuntimeException('DISPOSABLE_ISOLATION_NOT_PROVEN');
        $mounts=self::command(['docker','exec',$this->name,'cat','/proc/mounts']);
        foreach(['/var/lib/mysql','/tmp','/var/log/mysql'] as $path)if(!preg_match('~^tmpfs '.preg_quote($path,'~').' tmpfs ~m',$mounts))throw new \RuntimeException('DISPOSABLE_TMPFS_NOT_PROVEN');
        $this->proof=['network'=>'none','readonly_rootfs'=>true,'nonroot_mysql'=>true,'datadir_tmpfs'=>true,'temp_and_logs_tmpfs'=>true,'docker_log_driver'=>'none','persistent_volumes'=>0,'production_mounts'=>0,'ipc_only_bind'=>true];$this->verified=true;
    }
    public static function command(array $args):string
    {
        $p=new Process($args);$p->setTimeout(50);$p->run();if(!$p->isSuccessful())throw new \RuntimeException('DISPOSABLE_DOCKER_COMMAND_FAILED');return $p->getOutput();
    }
    public function socket():string{return $this->directory.'/mysqld.sock';}
    public function pdo():\PDO
    {
        if(!$this->verified)throw new \RuntimeException('SQL_BEFORE_ISOLATION_PROOF');$deadline=microtime(true)+50;
        do{try{
            // The image starts a temporary initialization server first. Do not
            // connect/create fixtures until the final mysqld is PID1.
            if(trim(self::command(['docker','exec',$this->name,'cat','/proc/1/comm']))!=='mysqld'){
                if(microtime(true)>$deadline)throw new \RuntimeException('FINAL_MYSQLD_NOT_READY');usleep(10000);continue;
            }
            $pdo=new \PDO('mysql:unix_socket='.$this->socket().';dbname=mysql;charset=utf8mb4','root','',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false]);
            $v=$pdo->query('SELECT VERSION() v,@@datadir d,@@tmpdir t,@@global.log_bin b,@@session.tx_isolation iso')->fetch(\PDO::FETCH_ASSOC);
            if($v['v']!=='5.7.44'||$v['d']!=='/var/lib/mysql/'||$v['t']!=='/tmp'||(int)$v['b']!==0||$v['iso']!=='REPEATABLE-READ')throw new \RuntimeException('DISPOSABLE_SERVER_NOT_PROVEN');
            $schemas=(int)$pdo->query('SELECT COUNT(*) FROM information_schema.SCHEMATA')->fetchColumn();if($schemas!==4)throw new \RuntimeException('DISPOSABLE_SCHEMA_SET_CHANGED');
            $this->proof+=['version'=>$v['v'],'schema_count'=>$schemas,'final_mysqld_pid1'=>true,'isolation'=>$v['iso']];return $pdo;
        }catch(\PDOException $e){if(microtime(true)>$deadline)throw new \RuntimeException('DISPOSABLE_MYSQL_NOT_READY');usleep(10000);}}while(true);
    }
    public function arm(int $seconds):void
    {
        if($seconds<1||$seconds>240)throw new \RuntimeException('DISPOSABLE_DEADLINE_INVALID');$this->input->write('ARM:'.$seconds."\n");
        $until=microtime(true)+5;
        while(!str_contains($this->guardian->getOutput(),'ARMED')){
            if(!$this->guardian->isRunning()||microtime(true)>$until)throw new \RuntimeException('DISPOSABLE_DEADLINE_NOT_ARMED');usleep(1000);
        }
    }
    public function closeParentPipe():void{$this->input->close();$this->guardian->isRunning();}
    public function exists():bool
    {
        return self::containerExists($this->name);
    }
    public static function containerExists(string $name):bool
    {
        if(!preg_match('/^ppb-concurrent-[a-f0-9]{12}$/D',$name))throw new \RuntimeException('DISPOSABLE_NAME_INVALID');
        $p=new Process(['docker','inspect','--type','container',$name]);$p->setTimeout(20);$p->run();
        if($p->isSuccessful())return true;
        // A daemon/auth/timeout failure is NOT proof that data was destroyed.
        if(!preg_match('/No such (?:object|container): '.preg_quote($name,'/').'\b/',$p->getErrorOutput()))throw new \RuntimeException('DISPOSABLE_ABSENCE_NOT_PROVEN');
        return false;
    }
    public function awaitDestroyed(float $seconds=20):void
    {
        $until=microtime(true)+$seconds;while($this->exists()){if(isset($this->guardian))$this->guardian->isRunning();if(microtime(true)>$until)throw new \RuntimeException('DISPOSABLE_CLEANUP_NOT_PROVEN');usleep(10000);}
        $this->proof+=['container_destroyed'=>true,'tmpfs_data_destroyed'=>true];
    }
    public function runtime():array
    {
        if(!$this->exists())return ['container_present'=>false];
        $i=json_decode(self::command(['docker','inspect',$this->name]),true)[0];
        return ['container_present'=>true,'running'=>$i['State']['Running'],'oom_killed'=>$i['State']['OOMKilled'],'exit_code'=>$i['State']['ExitCode']];
    }
    public function destroy():void
    {
        if(isset($this->input))$this->input->close();
        if(isset($this->guardian)){$until=microtime(true)+20;while($this->guardian->isRunning()&&microtime(true)<$until)usleep(10000);if($this->guardian->isRunning())$this->guardian->stop(1);}
        if($this->exists()){
            $i=json_decode(self::command(['docker','inspect',$this->name]),true)[0];
            if(($i['Config']['Labels']['ppb.test.owner']??null)!==$this->owner)throw new \RuntimeException('DISPOSABLE_OWNER_MISMATCH');
            self::command(['docker','rm','-f','-v',$this->name]);
        }$this->awaitDestroyed();
        foreach(['mysqld.sock','mysqld.sock.lock','mysqld.pid'] as $file)if(file_exists($this->directory.'/'.$file)||is_link($this->directory.'/'.$file))@unlink($this->directory.'/'.$file);
        if(is_dir($this->directory))@rmdir($this->directory);if(is_dir($this->directory))throw new \RuntimeException('DISPOSABLE_IPC_CLEANUP_NOT_PROVEN');$this->proof['ipc_removed']=true;
    }
}
