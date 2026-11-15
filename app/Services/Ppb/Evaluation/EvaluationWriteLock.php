<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

use Illuminate\Support\Facades\DB;

/** Serialize evaluation writes across subjects/months with shared dependencies.
 * Session advisory lock only: no schema, persistent row, or legacy privilege.
 */
final class EvaluationWriteLock
{
    public function run(callable $save): array
    {
        $connection=DB::connection();
        // A caller's older transaction snapshot cannot be used for a fresh save.
        abort_unless($connection->transactionLevel()===0,409,'請結束既有操作後重新讀取評鑑。');
        $pdo=$connection->getPdo();
        $name='ppb-v2-eval-write:'.substr(hash('sha256',$connection->getDatabaseName()),0,32);
        $owner=$pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');$owner->execute([$name]);
        abort_if((int)$owner->fetchColumn()===1,409,'評鑑保存不可巢狀執行。');
        $take=$pdo->prepare('SELECT GET_LOCK(?,0)');$take->execute([$name]);
        abort_unless((int)$take->fetchColumn()===1,409,'另一筆評鑑正在保存，請重新讀取後再試。');
        $assertHeld=static function()use($connection,$pdo,$name):void{
            if($connection->getPdo()!==$pdo)throw new \RuntimeException('EVALUATION_WRITE_LOCK_SESSION_CHANGED');
            $held=$pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');$held->execute([$name]);
            if((int)$held->fetchColumn()!==1)throw new \RuntimeException('EVALUATION_WRITE_LOCK_NOT_HELD');
        };
        try{return $save($assertHeld);}
        finally{
            // Use the captured session, never reconnect/release another session.
            $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$name]);
            if((int)$release->fetchColumn()!==1)throw new \RuntimeException('EVALUATION_WRITE_LOCK_RELEASE_NOT_PROVEN');
        }
    }
}
