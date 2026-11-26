<?php

declare(strict_types=1);

namespace App\Services\Ppb\Evaluation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AttendanceDeletions
{
    public const TABLE = 'ppb_attendance_deletions';

    public function deletedLegacyIds(?int $subjectId = null): array
    {
        if (!Schema::hasTable(self::TABLE)) {
            return [];
        }

        return DB::table(self::TABLE)->whereNull('restored_at')
            ->when($subjectId !== null, fn ($query) => $query->where('subject_type', 'legacy')->where('subject_id', $subjectId))
            ->orderBy('legacy_record_id')->pluck('legacy_record_id')->map(fn ($id) => (string) $id)->all();
    }

    public function filterLegacyRows(array $rows, array $deletedIds, string $idField): array
    {
        $deleted = array_fill_keys($deletedIds, true);

        return array_values(array_filter($rows, fn ($row) => !isset($deleted[$row[$idField]])));
    }

    public function filterSnapshot(array $snapshot, int $subjectId): array
    {
        $ids = $this->deletedLegacyIds($subjectId);
        if ($ids) {
            $snapshot['attendance'] = $this->filterLegacyRows($snapshot['attendance'], $ids, 'id');
            $snapshot['source_sha256'] = hash('sha256', json_encode([$snapshot['source_sha256'], $ids], JSON_THROW_ON_ERROR));
        }

        return $snapshot;
    }

    public function reportCacheVersion(): string
    {
        // Capture the version before reading or computing a summary, so a
        // concurrent deletion cannot publish old values into the new namespace.
        $lastDelete = Schema::hasTable('ppb_auth_audits')
            ? DB::table('ppb_auth_audits')->where('event', 'like', 'attendance.deleted#%')->max('id')
            : null;

        return hash('sha256', json_encode([$lastDelete, $this->deletedLegacyIds()], JSON_THROW_ON_ERROR));
    }
}
