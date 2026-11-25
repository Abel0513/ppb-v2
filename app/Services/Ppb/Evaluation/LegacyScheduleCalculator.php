<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

/**
 * Pure arithmetic from the observed AdminScheduleController, NOT a data selector.
 * Source: readonly run43537392013, commands bf2427da/9e5e1849/0959ec7f,
 * lines2446-2565 and2682-2774. Never reads legacy/V2 DB or changes identity.
 * Callers must supply the exact legacy-selected rated picks and absence/settled
 * counts; persistence/selection parity remains gated on independent readonly DB.
 */
final class LegacyScheduleCalculator
{
    public const RATING_FIELDS = [
        'score_delivery_time', 'score_word_count', 'score_format', 'score_typos',
        'score_semantic_clarity', 'score_reference_accuracy', 'score_logic_consistency',
        'score_language_appropriateness', 'score_professional_bonus',
    ];
    private const PROFESSIONAL = [
        'score_typos', 'score_semantic_clarity', 'score_reference_accuracy',
        'score_logic_consistency', 'score_language_appropriateness', 'score_professional_bonus',
    ];
    private function number(mixed $value): float
    {
        // PDO numeric strings/null follow the original PHP arithmetic semantics.
        if ($value === null) return 0.0;
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) || !is_finite((float) $value)) {
            throw new \InvalidArgumentException('Evaluation value must be finite numeric data.');
        }
        return (float) $value;
    }
    private function score(array $pick, string $field): float
    {
        if (!array_key_exists($field, $pick)) throw new \InvalidArgumentException('Incomplete source rating: '.$field);
        return $this->number($pick[$field]);
    }
    private function professional(array $pick): float
    {
        return array_sum(array_map(fn ($field) => $this->score($pick, $field), self::PROFESSIONAL));
    }
    public function hasLegacyRating(array $pick): bool
    {
        foreach (self::RATING_FIELDS as $field) if ($this->score($pick, $field) != 0) return true;
        return false;
    }
    /** getGameRecords uses the sum; keep distinct from getGameRecordsByMonth. */
    public function legacyListTotal(array $pick): ?float
    {
        if (!isset($pick['score_delivery_time'])) return null;
        return array_sum(array_map(fn ($field) => $this->score($pick, $field), self::RATING_FIELDS));
    }
    public function monthlyRecordTotal(array $pick): ?float
    {
        $total = array_sum($this->recordSections($pick));
        return $total > 0 ? $total : null;
    }
    public function recordSections(array $pick): array
    {
        return [
            'delivery' => $this->score($pick, 'score_delivery_time'),
            'compliance' => ($this->score($pick, 'score_word_count') + $this->score($pick, 'score_format')) / 2,
            'professional' => $this->professional($pick),
        ];
    }

    public function winrateBonus(int $settled, int $wins, int $absences): int
    {
        if ($settled < 0 || $wins < 0 || $wins > $settled || $absences < 0) throw new \InvalidArgumentException('Invalid result counts.');
        if ($settled < 12) return 0;
        $rate = $wins / ($settled + $absences) * 100;
        return match (true) { $rate >= 71 => 4, $rate >= 70 => 3, $rate >= 65 => 2, $rate >= 60 => 1, default => 0 };
    }
    /** Exact analyststats defaults and weighting, not the separate rating controller profile. */
    public function monthly(array $ratedPicks, int $absences, int $settled, int $wins, ?float $interactionAverage = 40, float $initiative = 0, float $extraAdjustment = 0): array
    {
        $bonus = $this->winrateBonus($settled, $wins, $absences);
        foreach ([$interactionAverage, $initiative, $extraAdjustment] as $value) if ($value !== null) $this->number($value);
        $delivery = $absences * 20.0; $word = $format = $absences * 40.0;
        $wordOnTime = $formatOnTime = 0; $professional = 0.0;
        foreach ($ratedPicks as $pick) {
            $delivery += $this->score($pick, 'score_delivery_time');
            $word += $w = $this->score($pick, 'score_word_count');
            $format += $f = $this->score($pick, 'score_format');
            if ($w == 60) $wordOnTime++;
            if ($f == 60) $formatOnTime++;
            $professional += $this->professional($pick);
        }
        $count = count($ratedPicks) + $absences;
        $deliveryScore = $count ? round($delivery / $count + ($absences === 0 ? 10 : 0), 0, PHP_ROUND_HALF_UP) : null;
        $compliance = $count ? round(($word / $count + ($wordOnTime / $count >= 0.95 ? 5 : 0)
            + $format / $count + ($formatOnTime / $count >= 0.95 ? 5 : 0)) / 2, 0, PHP_ROUND_HALF_UP) : null;
        $professionalism = $count ? round($professional / $count, 0, PHP_ROUND_HALF_UP) : null;
        // The observed analyststats path defaults to60 if any required component is missing.
        $total = 60.0;
        if ($deliveryScore !== null && $compliance !== null && $professionalism !== null && $interactionAverage !== null) {
            $total = round($deliveryScore * 0.3 + $compliance * 0.2 + $professionalism * 0.3
                + round($interactionAverage, 0, PHP_ROUND_HALF_UP) * 0.1 + $initiative + $bonus + $extraAdjustment, 0, PHP_ROUND_HALF_UP);
        }
        return ['score_delivery_time' => $deliveryScore, 'score_compliance' => $compliance,
            'score_professionalism' => $professionalism, 'score_interaction_avg' => $interactionAverage,
            'score_initiative' => $initiative, 'score_winrate_bonus' => $bonus,
            'score_initiative_winrate_total' => $initiative + $bonus,
            'score_extra_adjustment' => $extraAdjustment, 'total_score' => $total];
    }
}
