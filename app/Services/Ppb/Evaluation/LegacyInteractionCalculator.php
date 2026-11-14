<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

/** AdminScheduleController:2927–2963, readonly run43587125308.
 * Normalized complete form only; caller retains month, identity and write policy.
 * Zero is a real score; only blank/null is omitted. No invented score clamps.
 */
final class LegacyInteractionCalculator
{
    public function manual(array $weeks, mixed $feedback = 0): array
    {
        $fields = array_map(fn ($week) => 'score_interaction_w'.$week, range(1, 5));
        if (array_diff($fields, array_keys($weeks)) || array_diff(array_keys($weeks), $fields)) {
            throw new \InvalidArgumentException('Complete five-week input required.');
        }
        $number = static function (mixed $value): float {
            if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value) || !is_finite((float) $value)) {
                throw new \InvalidArgumentException('Finite numeric interaction value required.');
            }
            return (float) $value;
        };
        $out = []; $scores = [];
        foreach ($fields as $field) {
            $value = $weeks[$field];
            $out[$field] = ($value === '' || $value === null) ? null : $number($value);
            if ($out[$field] !== null) $scores[] = $out[$field];
        }
        // The original form uses intval for feedback and adds it AFTER the mean.
        $feedback = ($feedback === '' || $feedback === null) ? 0 : (int) $number($feedback);
        $out['score_interaction_feedback'] = $feedback;
        $out['score_interaction_avg'] = $scores ? round(array_sum($scores) / count($scores) + $feedback, 1, PHP_ROUND_HALF_UP) : null;
        return $out;
    }
}
