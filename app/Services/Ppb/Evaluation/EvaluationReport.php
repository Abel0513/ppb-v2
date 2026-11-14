<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

final class EvaluationReport
{
    public function __construct(private LegacyScheduleCalculator $scores,private LegacyRatingCalculator $pay) {}

    public function calculate(array $selected,array $manual,array $schedules,array $attendance,int $videos,?int $level): array
    {
        $wins=static fn($rows)=>count(array_filter($rows,fn($p)=>(int)$p['is_win']===1));
        $args=[$manual['score_interaction_avg']===null?null:(float)$manual['score_interaction_avg'],(int)$manual['score_initiative'],(int)$manual['score_extra_adjustment']];
        $view=$this->scores->monthly($selected['rated'],count($selected['absences']),count($selected['settled_view']),$wins($selected['settled_view']),...$args);
        $rating=$this->scores->monthly($selected['rated'],count($selected['absences']),count($selected['settled_rating']),$wins($selected['settled_rating']),...$args);
        $cached=$this->scores->monthly($selected['cache_update'],count($selected['absences']),0,0,...$args);
        // updateMonthlyAverages returns before updating if no own rated rows exist.
        if(!$selected['cache_update'])foreach(['score_delivery_time','score_compliance','score_professionalism'] as $f)$cached[$f]=$manual[$f]??null;
        $short=0;$salaryRows=[];
        foreach($selected['salary_short'] as $p){
            $amount=$this->pay->shortPickPay($level,(int)$schedules[$p['_schedule_key']]['market_type'],(int)$p['is_bonus'],(int)$p['is_win']);
            $short+=$amount;$salaryRows[]=['source_type'=>$p['_source_type'],'source_id'=>$p['_source_id'],'amount'=>$amount];
        }
        $deductions=$this->pay->attendanceDeductions(array_map(fn($a)=>(int)$a['attendance_status'],$attendance));
        $salary=['short'=>$short,'long'=>$this->pay->longPickPay(count($selected['salary_long'])),'video'=>$this->pay->videoPay($videos),'late'=>$deductions['lateTotal'],'absent'=>$deductions['absentTotal']];
        return ['view'=>$view,'rating'=>$rating,'cached'=>$cached,'salary'=>$salary+['total'=>array_sum($salary)],'salary_rows'=>$salaryRows,'deductions'=>$deductions,
            'counts'=>array_map('count',$selected),'wins'=>['regular'=>$wins($selected['regular']),'bonus'=>$wins($selected['bonus'])]];
    }

    public function defaults(string $month): array
    {
        $date=new \DateTimeImmutable($month.'-01');$out=[];
        foreach(range(1,5) as $w)$out['score_interaction_w'.$w]=$w===5&&(int)$date->format('t')<29?null:40;
        return $out+['score_interaction_avg'=>40,'score_interaction_feedback'=>0,'score_initiative'=>0,'score_extra_adjustment'=>0,'score_winrate_bonus'=>0,'score_delivery_time'=>null,'score_compliance'=>null,'score_professionalism'=>null];
    }
}
