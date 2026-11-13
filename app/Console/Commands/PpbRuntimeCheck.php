<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** V2 lifecycle's non-root entrypoint; no fixtures, tables, seeds or external calls. */
class PpbRuntimeCheck extends Command
{
    protected $signature = 'ppb:runtime-check';
    protected $description = 'Verify actual Laravel database identity and transaction rollback without data writes';
    public function handle(): int
    {
        $commit = (string) ($_SERVER['PPB_V2_RELEASE_COMMIT'] ?? $_ENV['PPB_V2_RELEASE_COMMIT'] ?? (function_exists('getenv') ? getenv('PPB_V2_RELEASE_COMMIT') : ''));
        if (!preg_match('/^[a-f0-9]{40}$/D', $commit)) {
            $this->line('V2_FEATURE_V1='.json_encode(['result'=>'STOP','reason'=>'RELEASE_CONTEXT_REQUIRED']));
            return self::FAILURE;
        }
        $started = false;
        try {
            if (DB::transactionLevel() !== 0) throw new \RuntimeException();
            DB::beginTransaction(); $started = true;
            $row = DB::selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS actor');
            DB::rollBack(); $started = false;
            if (DB::transactionLevel() !== 0 || !$row || !is_string($row->db) || !is_string($row->actor)) throw new \RuntimeException();
            $this->line('V2_FEATURE_V1='.json_encode([
                'result'=>'PASS','commit'=>$commit,'database'=>$row->db,
                'user'=>explode('@',$row->actor)[0],'transaction_rolled_back'=>true,
                'database_writes'=>0,'database_writes_committed'=>0,'checks'=>2,
            ], JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ($started) { try { DB::rollBack(); } catch (\Throwable $ignored) {} }
            $this->line('V2_FEATURE_V1='.json_encode(['result'=>'STOP','commit'=>$commit,'reason'=>'V2_DATABASE_RUNTIME_NOT_VERIFIED']));
            return self::FAILURE;
        }
    }
}
