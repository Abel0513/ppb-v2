<?php

declare(strict_types=1);

namespace App\Services\Ppb;

use App\Models\PpbGame;
use App\Services\Ppb\Evaluation\LegacyPickRows;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class LegacyUnsettledCount
{
    // The badge uses the legacy migration boundary as its lower time limit.
    private const WINDOW_START = '2026-10-15 00:00:00';
    private const LAST_KEY = 'ppb:legacy-unsettled:last:v2';
    private const PREVIOUS_LAST_KEY = 'ppb:legacy-unsettled:last:v1';

    public function __construct(private LegacyPickRows $picks) {}

    /** Last successfully computed count, rendered immediately while the badge refreshes. */
    public static function lastKnown(): ?int
    {
        try {
            $value = Cache::get(self::LAST_KEY)['count'] ?? Cache::get(self::PREVIOUS_LAST_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_int($value) ? $value : null;
    }

    public function snapshot(): array
    {
        $today = CarbonImmutable::today('Asia/Taipei')->toDateTimeString();

        $snapshot = Cache::remember('ppb:legacy-unsettled:v3:'.$today, 300, function () use ($today) {
            $times = [];
            foreach ($this->picks->select(['gid', 'is_win', 'recommended_time'], [
                ['column' => 'recommended_time', 'op' => '>=', 'value' => self::WINDOW_START],
                ['column' => 'recommended_time', 'op' => '<', 'value' => $today],
                ['column' => 'is_long', 'op' => '=', 'value' => 0],
                ['column' => 'is_bonus', 'op' => '=', 'value' => 0],
            ]) as $row) {
                if ($row['gid'] !== null && in_array($row['is_win'], [null, 0, '0'], true)) {
                    $gid = (int) $row['gid'];
                    $time = $row['recommended_time'];
                    $times[$gid] = min($times[$gid] ?? $time, $time);
                }
            }

            $games = $this->gameList($times);

            return ['count' => count($games), 'games' => $games];
        });
        Cache::put(self::LAST_KEY, $snapshot, now()->addDay());

        return $snapshot;
    }

    private function gameList(array $times): array
    {
        if ($times === []) {
            return [];
        }

        $imported = PpbGame::where(fn ($query) => $query->whereIntegerInRaw('id', array_keys($times))
            ->orWhere('notes', 'like', '%"legacy_source_schedule_id"%'))
            ->get(['id', 'notes', 'game_type', 'game_number', 'recommended_at', 'matchup_text', 'away_team', 'home_team']);

        $linked = $byId = [];
        foreach ($imported as $game) {
            $notes = json_decode((string) $game->notes, true);
            if (!($notes['_ppb_legacy_game_v1'] ?? false)) {
                continue;
            }

            $sourceId = $notes['legacy_source_schedule_id'] ?? null;
            if ($sourceId === null) {
                // The earlier import reused the legacy ID when available. Corroborate it with the time.
                $at = $game->recommended_at?->timezone('Asia/Taipei')->toDateTimeString();
                if ($at !== null && $at === ($times[$game->id] ?? null)) {
                    $byId[$game->id] = $game;
                }
            } elseif (is_int($sourceId) || (is_string($sourceId) && ctype_digit($sourceId))) {
                $linked[(int) $sourceId][] = $game;
            }
        }

        $games = [];
        foreach ($times as $gid => $time) {
            $matches = $linked[$gid] ?? [];
            $game = match (count($matches)) {
                0 => $byId[$gid] ?? null,
                1 => $matches[0],
                default => null,
            };
            $games[] = [
                'code' => $game?->display_code ?? '#'.$gid,
                'at' => $game?->recommended_at?->timezone('Asia/Taipei') ?? CarbonImmutable::parse($time, 'Asia/Taipei'),
                'matchup' => $game ? ($game->matchup_text ?: $game->away_team.' @ '.$game->home_team) : null,
                'url' => $game ? route('ppb.games.show', ['game' => $game], false) : null,
            ];
        }

        usort($games, fn ($left, $right) => $left['at'] <=> $right['at']);
        foreach ($games as &$game) {
            $game['at'] = $game['at']->format('m/d H:i');
        }

        return $games;
    }
}
