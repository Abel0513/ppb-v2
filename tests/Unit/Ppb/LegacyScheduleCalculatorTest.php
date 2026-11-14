<?php
declare(strict_types=1);
namespace Tests\Unit\Ppb;
use App\Services\Ppb\Evaluation\LegacyScheduleCalculator;
use PHPUnit\Framework\TestCase;
final class LegacyScheduleCalculatorTest extends TestCase
{
    private function pick(float $delivery = 60, float $word = 60, float $format = 60): array
    {
        return array_replace(array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS, 0),
            ['score_delivery_time' => $delivery, 'score_word_count' => $word, 'score_format' => $format,
             'score_typos' => 10, 'score_semantic_clarity' => 10, 'score_reference_accuracy' => 10,
             'score_logic_consistency' => 10, 'score_language_appropriateness' => 10, 'score_professional_bonus' => 10]);
    }
    public function test_missing_month_retains_legacy_60_instead_of_becoming_zero(): void
    {
        $out = (new LegacyScheduleCalculator)->monthly([], 0, 0, 0);
        self::assertNull($out['score_delivery_time']);self::assertSame(60.0,$out['total_score']);
    }
    public function test_absence_is_included_in_denominator_and_removes_attendance_bonus(): void
    {
        $c = new LegacyScheduleCalculator;
        $present=$c->monthly([$this->pick()],0,0,0);
        self::assertSame(70.0,$present['score_delivery_time']);self::assertSame(65.0,$present['score_compliance']);
        $absent=$c->monthly([$this->pick()],1,0,0);
        self::assertSame(40.0,$absent['score_delivery_time']);self::assertSame(50.0,$absent['score_compliance']);self::assertSame(30.0,$absent['score_professionalism']);
        self::assertSame(35.0,$absent['total_score']);
    }
    public function test_95_percent_threshold_is_inclusive_and_each_compliance_axis_is_separate(): void
    {
        $c=new LegacyScheduleCalculator;$picks=array_fill(0,20,$this->pick());$picks[19]=$this->pick(60,40,60);
        $out=$c->monthly($picks,0,0,0);self::assertSame(65.0,$out['score_compliance']);
        $picks[18]=$this->pick(60,40,60);$out=$c->monthly($picks,0,0,0);self::assertSame(62.0,$out['score_compliance']);
    }
    public function test_bonus_uses_unrounded_winrate_and_requires_twelve_settled_results(): void
    {
        $c=new LegacyScheduleCalculator;
        foreach([71=>4,70=>3,65=>2,60=>1,59=>0] as $wins=>$expected)self::assertSame($expected,$c->winrateBonus(100,$wins,0));
        self::assertSame(0,$c->winrateBonus(11,11,0));self::assertSame(4,$c->winrateBonus(12,12,0));
        self::assertSame(3,$c->winrateBonus(100,71,1));
    }
    public function test_interaction_is_rounded_before_weighting_and_adjustment_can_be_negative(): void
    {
        $out=(new LegacyScheduleCalculator)->monthly([$this->pick()],0,100,71,45.5,2,-3);
        self::assertSame(60.0,$out['total_score']);
    }
    public function test_two_legacy_record_totals_are_not_silently_merged(): void
    {
        $c=new LegacyScheduleCalculator;$pick=$this->pick();
        self::assertSame(240.0,$c->legacyListTotal($pick));self::assertSame(180.0,$c->monthlyRecordTotal($pick));
        $zero=array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS,0);
        self::assertFalse($c->hasLegacyRating($zero));self::assertNull($c->monthlyRecordTotal($zero));self::assertSame(0.0,$c->legacyListTotal($zero));
    }
    public function test_reordered_rows_are_equivalent_and_incomplete_rows_fail_closed(): void
    {
        $c=new LegacyScheduleCalculator;$a=$this->pick(40,60,20);$b=$this->pick(60,20,60);
        self::assertSame($c->monthly([$a,$b],1,20,15),$c->monthly([$b,$a],1,20,15));
        $this->expectException(\InvalidArgumentException::class);$c->monthly([['score_delivery_time'=>60]],0,0,0);
    }
}
