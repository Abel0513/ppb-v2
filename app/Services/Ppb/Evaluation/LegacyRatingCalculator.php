<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

/** Pure rules observed in AnalystRatingController:592–824,1167–1439.
 * Readonly run43540046639. No queries, analyst mapping or persistence.
 * Inputs must already have passed the original selection/month/identity checks.
 */
final class LegacyRatingCalculator
{
    public function rating(int $base, ?int $previousBase, int $currentScore, int $previousScore): array
    {
        if ($base < 0 || $base > 3 || ($previousBase !== null && ($previousBase < 0 || $previousBase > 3))) {
            throw new \InvalidArgumentException('Unverified rating level.');
        }
        $reset = $previousBase !== null && $previousBase !== $base;
        $level = $base;
        $terminate = false;
        if ($currentScore > 0 && $currentScore < 40) {
            $level = max(0, $base - 1);
            $terminate = true;
        } elseif ($currentScore > 0 && $currentScore < 50) {
            $level = max(0, $base - 1);
        } elseif (!$reset && $previousScore >= 68 && $currentScore >= 68) {
            $level = min(3, $base + 1);
        } elseif (!$reset && $previousScore > 0 && $previousScore < 60 && $currentScore > 0 && $currentScore < 60) {
            $level = max(0, $base - 1);
        }
        return ['rating_level' => $level, 'is_reset' => $reset, 'is_terminate' => $terminate];
    }

    public function shortPickPay(?int $ratingLevel, int $marketType, int $isBonus, int $isWin): int
    {
        $rules = [3 => [60, 60, 70, 50], 2 => [50, 50, 70, 30], 1 => [25, 25, 30, 20], 0 => [20, 15, 25, 10]];
        // The source explicitly falls back to level2 for missing/unknown levels.
        $rule = $rules[$ratingLevel ?? 2] ?? $rules[2];
        [$base, $win] = $marketType === 2 ? [$rule[2], $rule[3]] : [$rule[0], $rule[1]];
        return ($isBonus === 1 ? 0 : $base) + ($isWin === 1 ? $win : 0);
    }

    /** Caller supplies chronological attendance statuses. Source treats ALL non1 as absent. */
    public function attendanceDeductions(array $chronologicalStatuses): array
    {
        $late = $absent = $absences = 0;
        $rows = [];
        foreach ($chronologicalStatuses as $status) {
            if (!is_int($status)) throw new \InvalidArgumentException('Attendance status must be an integer.');
            if ($status === 1) {
                $deduction = -50;
                $late += $deduction;
            } else {
                $deduction = $absences++ === 0 ? -100 : -200;
                $absent += $deduction;
            }
            $rows[] = $deduction;
        }
        return ['lateTotal' => $late, 'absentTotal' => $absent, 'deductions' => $rows];
    }

    public function longPickPay(int $selectedArticleCount): int
    {
        return $this->fixedPay($selectedArticleCount);
    }

    public function videoPay(int $selectedScheduleCount): int
    {
        return $this->fixedPay($selectedScheduleCount);
    }

    private function fixedPay(int $count): int
    {
        if ($count < 0 || $count > intdiv(PHP_INT_MAX, 400)) throw new \InvalidArgumentException('Invalid selected count.');
        return $count * 400;
    }
}
