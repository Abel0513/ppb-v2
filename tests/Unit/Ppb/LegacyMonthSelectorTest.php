<?php
namespace Tests\Unit\Ppb;
use App\Services\Ppb\Evaluation\{LegacyMonthSelector,LegacyScheduleCalculator,LegacyRatingCalculator,EvaluationReport};
use PHPUnit\Framework\TestCase;
final class LegacyMonthSelectorTest extends TestCase
{
    private function pick(array $changes=[]): array
    {
        return array_replace(array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS,0),['_author_key'=>'legacy:2','_absence_key'=>null,'_is_substitution'=>false,'_schedule_key'=>'legacy:9','_source_type'=>'legacy','_source_id'=>1,'recommended_time'=>'2026-05-01 00:00:00','is_win'=>1,'is_bonus'=>0,'is_long'=>0,'score_delivery_time'=>60],$changes);
    }
    public function test_month_edges_and_same_numeric_id_in_other_identity_domain_do_not_leak(): void
    {
        $c=new LegacyMonthSelector;$rows=[$this->pick(),$this->pick(['recommended_time'=>'2026-05-31 23:59:59']),$this->pick(['recommended_time'=>'2026-04-30 23:59:59']),$this->pick(['recommended_time'=>'2026-06-01 00:00:00']),$this->pick(['_author_key'=>'v2:2'])];
        $s=$c->select($rows,'legacy:2','2026-05',['legacy:9'=>['market_type'=>1]]);
        self::assertCount(2,$s['rated']);self::assertCount(2,$s['salary_short']);self::assertCount(2,$s['records']);
    }
    public function test_resolved_short_salary_does_not_pay_unresolved_or_substitution_and_long_uses_distinct_filters(): void
    {
        $c=new LegacyMonthSelector;$rows=[$this->pick(['is_win'=>0]),$this->pick(['_is_substitution'=>true]),$this->pick(['is_long'=>1,'is_win'=>0,'_is_substitution'=>true]),$this->pick(['is_bonus'=>1]),$this->pick(['_schedule_key'=>'legacy:999'])];
        $s=$c->select($rows,'legacy:2','2026-05',['legacy:9'=>['market_type'=>1]]);
        self::assertCount(1,$s['salary_short']);self::assertSame(1,(int)$s['salary_short'][0]['is_bonus']);self::assertCount(1,$s['salary_long']);
        self::assertCount(5,$s['cache_update']);self::assertCount(2,$s['rated']);
    }
    public function test_view_and_rating_denominators_and_bonus_long_filters_remain_distinct(): void
    {
        $c=new LegacyMonthSelector;$rows=[$this->pick(),$this->pick(['_is_substitution'=>true]),$this->pick(['is_long'=>1,'is_bonus'=>1])];
        $s=$c->select($rows,'legacy:2','2026-05',[]);
        self::assertCount(2,$s['settled_view']);self::assertCount(1,$s['settled_rating']);self::assertCount(1,$s['bonus']);self::assertCount(1,$s['regular']);
    }
    public function test_absence_includes_long_unresolved_but_excludes_bonus_and_is_not_an_own_submission(): void
    {
        $c=new LegacyMonthSelector;$rows=[$this->pick(['_author_key'=>'legacy:3','_absence_key'=>'legacy:2','is_win'=>0,'is_long'=>1]),$this->pick(['_author_key'=>'legacy:3','_absence_key'=>'legacy:2','is_bonus'=>1])];
        $s=$c->select($rows,'legacy:2','2026-05',[]);
        self::assertCount(1,$s['absences']);self::assertCount(0,$s['cache_update']);self::assertCount(0,$s['records']);
    }
    public function test_native_analyst_one_can_be_original_of_a_substitution_without_using_legacy_reserved_id_rules(): void
    {
        $s=(new LegacyMonthSelector)->select([$this->pick(['_author_key'=>'v2:2','_absence_key'=>'v2:1','_is_substitution'=>true])],'v2:1','2026-05',[]);
        self::assertCount(1,$s['absences']);
    }
    public function test_no_rated_rows_preserves_legacy_cached_auto_fields_and_week5_calendar_rule(): void
    {
        $r=new EvaluationReport(new LegacyScheduleCalculator,new LegacyRatingCalculator);
        self::assertNull($r->defaults('2026-02')['score_interaction_w5']);self::assertSame(40,$r->defaults('2024-02')['score_interaction_w5']);
        $manual=array_replace($r->defaults('2026-05'),['score_delivery_time'=>'55.00']);
        $s=(new LegacyMonthSelector)->select([],'legacy:2','2026-05',[]);$report=$r->calculate($s,$manual,[],[['attendance_status'=>0],['attendance_status'=>1],['attendance_status'=>2]],2,null);
        self::assertSame('55.00',$report['cached']['score_delivery_time']);self::assertSame(450,$report['salary']['total']);self::assertSame([-100,-50,-200],$report['deductions']['deductions']);
    }
    public function test_sql_flag_equals_zero_does_not_treat_null_or_unknown_flags_as_zero(): void
    {
        $c=new LegacyMonthSelector;$rows=[$this->pick(['is_long'=>2]),$this->pick(['is_long'=>null]),$this->pick(['is_bonus'=>2]),$this->pick(['is_bonus'=>null]),$this->pick(['_is_substitution'=>null])];
        $s=$c->select($rows,'legacy:2','2026-05',['legacy:9'=>['market_type'=>1]]);
        self::assertCount(0,$s['regular']);self::assertCount(0,$s['salary_long']);self::assertCount(2,$s['rated']);self::assertCount(2,$s['salary_short']);
    }

    public function test_missing_schedule_key_is_distinct_from_real_zero_schedule_id(): void
    {
        $p=array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS,0)+['id'=>77,'gid'=>null,'analyst_name'=>'2','recommended_time'=>'2026-05-01 00:00:00','is_win'=>1,'is_bonus'=>0,'is_long'=>0,'is_substitute'=>0];
        $c=new LegacyMonthSelector;$snapshot=['pick_columns'=>array_keys($p),'picks'=>[array_values($p)]];$rows=$c->legacyRows($snapshot);
        self::assertSame('legacy:missing',$rows[0]['_schedule_key']);self::assertCount(0,$c->select($rows,'legacy:2','2026-05',['legacy:0'=>['market_type'=>2]])['salary_short']);
        $p['gid']=0;$snapshot['picks']=[array_values($p)];self::assertCount(1,$c->select($c->legacyRows($snapshot),'legacy:2','2026-05',['legacy:0'=>['market_type'=>2]])['salary_short']);
    }

}
