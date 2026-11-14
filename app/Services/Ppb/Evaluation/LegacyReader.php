<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

/** Same fixed reader used by SSM. No old DB credentials, shell or URL in V2. */
class LegacyReader
{
    public function query(array $query): array
    {
        $path = '/run/ppb-v2-legacy-reader.sock';
        $st = @lstat($path);
        if (!$st || ($st['mode'] & 0170000) !== 0140000 || $st['uid'] !== 0 || ($st['mode'] & 0007) !== 0) {
            throw new \RuntimeException('LEGACY_READER_SOCKET_NOT_VERIFIED');
        }
        $request = json_encode($query, JSON_THROW_ON_ERROR)."\n";
        if (strlen($request) > 12000) throw new \RuntimeException('LEGACY_READER_REQUEST_LIMIT');
        $stream = @stream_socket_client('unix://'.$path, $errno, $error, 5, STREAM_CLIENT_CONNECT);
        if (!$stream) throw new \RuntimeException('LEGACY_READER_UNAVAILABLE');
        try {
            stream_set_timeout($stream, 30);
            $offset = 0;
            while ($offset < strlen($request)) {
                $written = @fwrite($stream, substr($request, $offset));
                if (!$written) throw new \RuntimeException('LEGACY_READER_REQUEST_INCOMPLETE');
                $offset += $written;
            }
            $raw = '';
            while (!str_contains($raw, "\n")) {
                $part = @fread($stream, 65536);
                if ($part === false || $part === '' || strlen($raw) + strlen($part) > 1048576) {
                    throw new \RuntimeException('LEGACY_READER_RESPONSE_INCOMPLETE');
                }
                $raw .= $part;
            }
            $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            $data = $value['data'] ?? [];
            if (($value['result'] ?? '') !== 'PASS' || ($data['db_actor'] ?? '') !== 'ppb_legacy_ro@localhost'
                || ($data['transaction_readonly'] ?? false) !== true || ($data['old_db_write_grants'] ?? true) !== false
                || ($data['global_privileges'] ?? true) !== false || ($data['database_writes'] ?? 1) !== 0) {
                throw new \RuntimeException('LEGACY_READER_BOUNDARY_NOT_PROVEN');
            }
            return $data;
        } finally {
            fclose($stream);
        }
    }

    public function snapshot(int $id,string $month): array
    {
        $data=$this->query(['operation'=>'evaluation_snapshot','analyst_id'=>$id,'month'=>$month]);$s=$data['snapshot']??[];
        if(($data['transaction_isolation']??null)!=='REPEATABLE-READ'||($s['format']??null)!=='ppb-evaluation-source-v1'
            ||($s['analyst_id']??null)!==$id||($s['month']??null)!==$month||(int)($s['profile']['id']??0)!==$id
            ||($s['transactional_tables_verified']??null)!==7||!preg_match('/^[a-f0-9]{64}$/D',$s['source_sha256']??'')){
            throw new \RuntimeException('LEGACY_SNAPSHOT_SCOPE_NOT_PROVEN');
        }
        return $s;
    }

    public function profiles(): array
    {
        $rows = [];
        for ($offset = 0; $offset <= 5000; $offset += 50) {
            $data = $this->query(['operation'=>'select','table'=>'pay.pay_analyst_profiles',
                'columns'=>['id','sort_id','user_type','rating_level','analyst_level'],'limit'=>50,'offset'=>$offset]);
            $page = $data['rows']; $rows = array_merge($rows, $page);
            if (count($page) < 50) return $rows;
        }
        throw new \RuntimeException('LEGACY_PROFILE_COVERAGE_LIMIT');
    }
}
