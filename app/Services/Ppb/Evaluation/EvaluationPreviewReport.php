<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

/** Independent legacy GET-only preview; does not touch unmigrated V2 evaluation tables. */
class EvaluationPreviewReport
{
    public function __construct(private LegacyReader $reader,private LegacyMonthSelector $selector,
        private EvaluationReport $report,private LegacyRatingCalculator $rating) {}

    public function load(array $subject,string $month): array
    {
        if(($subject['type']??null)!=='legacy')throw new \InvalidArgumentException('Preview requires legacy subject.');
        $date=\DateTimeImmutable::createFromFormat('!Y-m',$month);
        if(!$date||$date->format('Y-m')!==$month)throw new \InvalidArgumentException('Invalid month.');
        $snapshot=$this->reader->snapshot((int)$subject['id'],$month);
        $prior=$date->modify('-1 month')->format('Y-m');
        if(($snapshot['previous_month']??null)!==$prior)throw new \RuntimeException('PREVIEW_MONTH_SCOPE');
        $now=$this->period($snapshot,$month,(int)$subject['id']);
        $previous=$this->period($snapshot,$prior,(int)$subject['id']);
        $level=$now['level']??(int)($snapshot['profile']['rating_level']??2);
        if(!in_array($level,[0,1,2,3],true))throw new \RuntimeException('PREVIEW_LEVEL_SCOPE');
        $now['next_rating']=$this->rating->rating($level,$previous['level'],
            (int)$now['rating']['total_score'],(int)$previous['rating']['total_score']);
        $now['performance']=(new EvaluationPerformance)->summarize($now['selected'],$snapshot['recent']);
        unset($now['selected'],$previous['selected']);
        return ['subject'=>$subject,'month'=>$month,'previous_month'=>$prior,'level'=>$level,
            'current'=>$now,'previous'=>$previous,'source_sha256'=>$snapshot['source_sha256']];
    }

    private function period(array $snapshot,string $month,int $id): array
    {
        $rows=$this->selector->legacyRows($snapshot);
        $schedules=[];
        foreach($snapshot['schedules'] as $s)$schedules['legacy:'.(int)$s['id']]=['market_type'=>$s['market_type']];
        $selected=$this->selector->select($rows,'legacy:'.$id,$month,$schedules);
        $manual=$this->report->defaults($month);
        foreach($snapshot['evaluations'] as $e)if($e['eval_month']===$month)
            $manual=array_replace($manual,array_intersect_key($e,$manual));
        $attendance=array_values(array_filter($snapshot['attendance'],
            fn($row)=>substr($row['recommended_time'],0,7)===$month));
        usort($attendance,fn($a,$b)=>[$a['recommended_time'],(int)$a['id']]<=>
            [$b['recommended_time'],(int)$b['id']]);
        $level=null;
        foreach($snapshot['ratings'] as $r)if($r['rating_month']===$month)$level=(int)$r['rating_level'];
        $result=$this->report->calculate($selected,$manual,$schedules,$attendance,
            (int)($snapshot['video_counts'][$month]??0),$level);
        $result['level']=$level;$result['selected']=$selected;
        return $result;
    }
}
