<?php
declare(strict_types=1);
namespace Tests\Unit\Ppb;
use App\Services\Ppb\Evaluation\LegacyInteractionCalculator;
use PHPUnit\Framework\TestCase;

final class LegacyInteractionCalculatorTest extends TestCase
{
    private function weeks(array $values): array
    {
        return array_combine(array_map(fn ($i) => 'score_interaction_w'.$i, range(1, 5)), $values);
    }
    public function test_all_blanks_stay_null_even_with_feedback(): void
    {
        $out = (new LegacyInteractionCalculator)->manual($this->weeks(['',null,'',null,'']), 5);
        self::assertNull($out['score_interaction_avg']);self::assertSame(5,$out['score_interaction_feedback']);
    }
    public function test_zero_is_included_and_feedback_is_added_after_the_mean(): void
    {
        $out = (new LegacyInteractionCalculator)->manual($this->weeks([0,20,null,40,null]), 3);
        self::assertSame(23.0,$out['score_interaction_avg']);self::assertSame(0.0,$out['score_interaction_w1']);
        self::assertSame(0.0,(new LegacyInteractionCalculator)->manual($this->weeks([0,null,null,null,null]))['score_interaction_avg']);
    }
    public function test_one_decimal_rounding_and_integer_feedback_follow_source_order(): void
    {
        $c = new LegacyInteractionCalculator;
        self::assertSame(0.7,$c->manual($this->weeks([1,2,2,null,null]), -1)['score_interaction_avg']);
        self::assertSame(3.7,$c->manual($this->weeks(['1','2','2',null,null]), '2.9')['score_interaction_avg']);
    }
    public function test_fifth_week_is_not_silently_discarded(): void
    {
        self::assertSame(28.0,(new LegacyInteractionCalculator)->manual($this->weeks([10,10,10,10,100]))['score_interaction_avg']);
    }
    public function test_incomplete_form_is_rejected_instead_of_overwriting_unsupplied_weeks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LegacyInteractionCalculator)->manual(['score_interaction_w1'=>40]);
    }
}
