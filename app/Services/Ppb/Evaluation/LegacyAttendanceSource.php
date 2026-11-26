<?php

declare(strict_types=1);

namespace App\Services\Ppb\Evaluation;

use App\Models\PpbGame;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class LegacyAttendanceSource
{
    private const PAGE_SIZE = 50;

    public function __construct(private LegacyReader $reader) {}

    public function rows(): array
    {
        $rows = Cache::remember('ppb:legacy-attendance:v2', 600, fn () => $this->load());
        $deletions = new AttendanceDeletions;

        return $deletions->filterLegacyRows($rows, $deletions->deletedLegacyIds(), 'legacy_record_id');
    }

    public function matchesImportedGame(array $row, ?PpbGame $game): bool
    {
        $notes = json_decode((string) $game?->notes, true);

        return $game && ($notes['_ppb_legacy_game_v1'] ?? false)
            && (int) ($notes['legacy_source_schedule_id'] ?? $game->id) === (int) $row['gid']
            && $game->display_code === $row['code'] && $game->recommended_at
            && CarbonImmutable::parse($game->getRawOriginal('recommended_at'), 'UTC')
                ->tz('Asia/Taipei')->toDateTimeString() === $row['recommended_time'];
    }

    private function load(): array
    {
        $attendance = [];
        $filters = [];
        for ($offset = 0; ; $offset += self::PAGE_SIZE) {
            if ($offset > 5000) {
                $filters = [self::filter('id', '>', (int) $page[array_key_last($page)]['id'])];
                $offset = 0;
            }
            $page = $this->select('pay.pay_attendance_record', ['id', 'gid', 'uid', 'attendance_status'], $filters, $offset);
            $attendance = array_merge($attendance, $page);
            if (count($page) < self::PAGE_SIZE) {
                break;
            }
        }
        $pending = array_fill_keys(array_column($attendance, 'gid'), true);
        ksort($pending, SORT_NUMERIC);
        $schedules = [];
        $month = now('Asia/Taipei')->startOfMonth();
        $start = $month->toDateTimeString();
        $end = $month->copy()->addMonth()->toDateTimeString();

        // Start with the newest likely page, then cover both sides without assuming IDs follow dates.
        foreach ([
            [self::filter('recommended_time', '>=', $start), self::filter('recommended_time', '<', $end)],
            [self::filter('recommended_time', '<', $start)],
            [self::filter('recommended_time', '>=', $end)],
        ] as $window) {
            $after = 0;
            while ($pending) {
                $first = null;
                foreach ($pending as $id => $_) {
                    if ($id > $after) {
                        $first = $id;
                        break;
                    }
                }
                if ($first === null) {
                    break;
                }
                $page = $this->select('pay.pay_schedule', ['id', 'game_id', 'game_type', 'recommended_time', 'reviewer', 'market_type'], [
                    ...$window, self::filter('id', '>=', $first), self::filter('id', '<=', array_key_last($pending)),
                ]);
                foreach ($page as $schedule) {
                    $id = (int) $schedule['id'];
                    if (isset($pending[$id])) {
                        $schedules[$id] = $schedule;
                        unset($pending[$id]);
                    }
                }
                if (count($page) < self::PAGE_SIZE) {
                    break;
                }
                $lastId = (int) $page[array_key_last($page)]['id'];
                if ($lastId < $first) {
                    throw new RuntimeException('LEGACY_SCHEDULE_PAGE_NOT_ADVANCING');
                }
                $after = $lastId;
            }
        }

        $rows = [];
        foreach ($attendance as $record) {
            $row = $this->row($record, $schedules[$record['gid']] ?? null);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function find(int $id): ?array
    {
        // Verify the target against the reader, bypassing the list cache.
        $record = $this->select('pay.pay_attendance_record', ['id', 'gid', 'uid', 'attendance_status'],
            [self::filter('id', '=', $id)])[0] ?? null;
        if ($record === null || (int) $record['id'] !== $id) {
            return null;
        }

        $schedule = $this->select('pay.pay_schedule', ['id', 'game_id', 'game_type', 'recommended_time', 'reviewer', 'market_type'],
            [self::filter('id', '=', (int) $record['gid'])])[0] ?? null;

        return $this->row($record, $schedule);
    }

    private function row(array $record, ?array $schedule): ?array
    {
        $status = (int) $record['attendance_status'];
        if (!$schedule || (int) $schedule['id'] !== (int) $record['gid']
            || empty($schedule['recommended_time']) || !in_array($status, [1, 2], true)) {
            return null;
        }

        return [
            'legacy_record_id' => (int) $record['id'],
            'gid' => (int) $record['gid'],
            'subject_type' => 'legacy',
            'subject_id' => (int) $record['uid'],
            'attendance_status' => $status,
            'recommended_time' => $schedule['recommended_time'],
            'code' => ((int) $schedule['game_type'] === 1 ? 'G' : 'E').$schedule['game_id'],
            'source' => 'legacy',
        ];
    }

    private static function filter(string $column, string $op, int|string $value): array
    {
        return compact('column', 'op', 'value');
    }

    private function select(string $table, array $columns, array $filters, int $offset = 0): array
    {
        $data = $this->reader->query([
            'operation' => 'select', 'table' => $table, 'columns' => $columns,
            'filters' => $filters, 'limit' => self::PAGE_SIZE, 'offset' => $offset,
        ]);

        if (!is_array($data['rows'] ?? null)) {
            throw new RuntimeException('LEGACY_ATTENDANCE_ROWS_MISSING');
        }

        return $data['rows'];
    }
}
