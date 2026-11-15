<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

/** Immutable calculation receipt, not a payment instruction or a ledger transfer. */
class EvaluationSalarySnapshot
{
    public function capture(array $report, array $subject, string $month, string $sourceHash): array
    {
        if (($report['native_attendance_video_supported'] ?? false) !== true
            || ($report['month'] ?? null) !== $month
            || ($report['subject']['type'] ?? null) !== $subject['type']
            || ($report['subject']['id'] ?? null) !== $subject['id']
            || ($report['source_sha256'] ?? null) !== $sourceHash) {
            throw new \RuntimeException('SALARY_SNAPSHOT_SCOPE_NOT_PROVEN');
        }
        $salary = [];
        foreach (['short', 'long', 'video', 'late', 'absent', 'total'] as $field) {
            $amount = $report['current']['salary'][$field] ?? null;
            if (!is_int($amount)) throw new \RuntimeException('SALARY_SNAPSHOT_AMOUNT_NOT_PROVEN');
            $salary[$field] = $amount;
        }
        // Do not duplicate business formulas: preserve the existing service result.
        return [
            'format' => 'ppb-evaluation-salary-v1',
            'subject_type' => $subject['type'], 'subject_id' => $subject['id'],
            'month' => $month, 'source_sha256' => $sourceHash,
            'salary' => $salary,
            'counts' => array_intersect_key($report['current']['counts'], array_flip(['salary_short', 'salary_long', 'absences'])),
            'rating_level' => $report['current']['level'],
            'rating_score' => $report['current']['rating']['total_score'],
            'next_rating' => $report['current']['next_rating'],
            'payment_executed' => false,
        ];
    }
}
