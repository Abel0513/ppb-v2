<?php

declare(strict_types=1);

namespace App\Services\Ppb;

use App\Services\Ppb\Evaluation\LegacyPickRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class LegacyUnsettledCount
{
    // The badge uses the legacy migration boundary as its lower time limit.
    private const WINDOW_START = '2026-10-15 00:00:00';

    public function __construct(private LegacyPickRows $picks) {}

    public function count(): int
    {
        $today = CarbonImmutable::today('Asia/Taipei')->toDateTimeString();

        return Cache::remember('ppb:legacy-unsettled:v1:'.$today, 300, function () use ($today) {
            $games = [];
            foreach ($this->picks->select(['gid', 'is_win'], [
                ['column' => 'recommended_time', 'op' => '>=', 'value' => self::WINDOW_START],
                ['column' => 'recommended_time', 'op' => '<', 'value' => $today],
                ['column' => 'is_long', 'op' => '=', 'value' => 0],
                ['column' => 'is_bonus', 'op' => '=', 'value' => 0],
            ]) as $row) {
                if ($row['gid'] !== null && in_array($row['is_win'], [null, 0, '0'], true)) {
                    $games[(int) $row['gid']] = true;
                }
            }

            return count($games);
        });
    }
}
