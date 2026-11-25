<?php
declare(strict_types=1);
namespace Tests\Unit\Ppb;
use App\Services\Ppb\Evaluation\LegacyRatingCalculator;
use PHPUnit\Framework\TestCase;

final class LegacyRatingCalculatorTest extends TestCase
{
    public function test_single_month_demotion_and_termination_boundaries_take_priority_over_reset(): void
    {
        $c = new LegacyRatingCalculator;
        foreach ([0 => [2,false], 39 => [1,true], 40 => [1,false], 49 => [1,false], 50 => [2,false]] as $score => [$level,$terminate]) {
            self::assertSame(['rating_level'=>$level,'is_reset'=>true,'is_terminate'=>$terminate], $c->rating(2,1,$score,90));
        }
    }
    public function test_two_month_thresholds_and_changed_rating_reset(): void
    {
        $c = new LegacyRatingCalculator;
        self::assertSame(3,$c->rating(2,2,68,68)['rating_level']);
        self::assertSame(2,$c->rating(2,2,67,68)['rating_level']);
        self::assertSame(1,$c->rating(2,2,59,59)['rating_level']);
        self::assertSame(2,$c->rating(2,2,60,59)['rating_level']);
        self::assertSame(2,$c->rating(2,1,68,68)['rating_level']);
        self::assertSame(2,$c->rating(2,1,59,59)['rating_level']);
        self::assertSame(3,$c->rating(2,null,68,68)['rating_level']);
    }
    public function test_rating_caps_and_missing_scores_do_not_become_demotion(): void
    {
        $c = new LegacyRatingCalculator;
        self::assertSame(3,$c->rating(3,3,90,90)['rating_level']);
        self::assertSame(0,$c->rating(0,0,39,90)['rating_level']);
        self::assertTrue($c->rating(0,0,39,90)['is_terminate']);
        self::assertSame(2,$c->rating(2,2,0,59)['rating_level']);
    }
    public function test_short_pick_matrix_preserves_base_bonus_and_win_only_rules(): void
    {
        $c = new LegacyRatingCalculator;
        foreach ([3=>[60,60,70,50],2=>[50,50,70,30],1=>[25,25,30,20],0=>[20,15,25,10]] as $level=>$r) {
            foreach ([0,2] as $market) {
                [$base,$win] = $market===2 ? [$r[2],$r[3]] : [$r[0],$r[1]];
                self::assertSame($base+$win,$c->shortPickPay($level,$market,0,1));
                self::assertSame($base,$c->shortPickPay($level,$market,0,2));
                self::assertSame($win,$c->shortPickPay($level,$market,1,1));
                self::assertSame(0,$c->shortPickPay($level,$market,1,0));
            }
        }
        self::assertSame(100,$c->shortPickPay(null,0,0,1));
        self::assertSame(100,$c->shortPickPay(99,0,0,1));
    }
    public function test_lateness_does_not_advance_absence_penalty_and_unknown_status_follows_source(): void
    {
        self::assertSame(['lateTotal'=>-100,'absentTotal'=>-500,'deductions'=>[-50,-100,-50,-200,-200]],
            (new LegacyRatingCalculator)->attendanceDeductions([1,0,1,2,99]));
    }
    public function test_long_article_and_video_counts_remain_separate_selected_inputs(): void
    {
        $c = new LegacyRatingCalculator;
        self::assertSame(1200,$c->longPickPay(3));
        self::assertSame(800,$c->videoPay(2));
        self::assertSame(0,$c->videoPay(0));
        $this->expectException(\InvalidArgumentException::class);$c->longPickPay(-1);
    }
}
