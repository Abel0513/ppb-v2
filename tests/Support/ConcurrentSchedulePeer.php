<?php
namespace Tests\Support;
use Symfony\Component\Process\{Process,InputStream};
final class ConcurrentSchedulePeer
{
    private Process $process;
    private InputStream $input;
    private string $buffer='';
    private array $events=[];
    public readonly array $ready;
    public function __construct(array $spec)
    {
        $this->input=new InputStream;
        $this->process=new Process([getenv('PPB_TEST_PHP')?:PHP_BINARY,__DIR__.'/ScheduleConcurrentWorker.php'],dirname(__DIR__,2),[
            'APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'1','DB_DATABASE'=>'unavailable','DB_USERNAME'=>'no_database_access','DB_PASSWORD'=>'no_database_access','DB_URL'=>'',
            'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','LOG_CHANNEL'=>'null',
        ]);
        $this->process->setInput($this->input);$this->process->setTimeout(30);$this->process->start();$this->input->write(json_encode($spec)."\n");
        $this->ready=$this->take('ready');
    }
    public function send(string $command):void{$this->input->write(json_encode(['command'=>$command])."\n");$this->take('command_ack',$command);}
    public function pump():void
    {
        $this->buffer.=$this->process->getIncrementalOutput();
        while(($pos=strpos($this->buffer,"\n"))!==false){$line=substr($this->buffer,0,$pos);$this->buffer=substr($this->buffer,$pos+1);if($line!=='')$this->events[]=json_decode($line,true,64,JSON_THROW_ON_ERROR);}
    }
    public function done():?array{$this->pump();foreach($this->events as $event)if($event['type']==='done')return $event;return null;}
    public function take(string $type,?string $stage=null):array
    {
        $deadline=microtime(true)+20;
        do{
            $this->pump();foreach($this->events as $i=>$e){if($e['type']===$type&&($stage===null||($e['stage']??null)===$stage)){array_splice($this->events,$i,1);return $e;}
                if($e['type']==='done'&&$type!=='done')throw new \RuntimeException('WORKER_EARLY_RESULT_'.json_encode($e));}
            if(!$this->process->isRunning()||microtime(true)>$deadline)throw new \RuntimeException('WORKER_EVENT_NOT_PROVEN_'.$type);
            usleep(1000); // IO readiness polling; transaction order is barrier-driven.
        }while(true);
    }
    public function debug():array{$this->pump();return ['running'=>$this->process->isRunning(),'events'=>$this->events];}
    public function stop():void{$this->input->close();if($this->process->isRunning())$this->process->stop(.2,9);}
}
