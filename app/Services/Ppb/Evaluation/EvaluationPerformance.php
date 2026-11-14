<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

/** AnalystPerformanceController49–149 and AdminSchedule2616–2645. */
final class EvaluationPerformance
{
    public function summarize(array $selected,array $recent): array
    {
        $result=static function(array $rows):array{
            $wins=count(array_filter($rows,fn($r)=>(int)$r['is_win']===1));$losses=count(array_filter($rows,fn($r)=>(int)$r['is_win']===2));$count=$wins+$losses;
            return ['wins'=>$count?$wins:null,'losses'=>$count?$losses:null,'rate'=>$count?(int)round($wins/$count*100,0,PHP_ROUND_HALF_UP):null];
        };
        $top=array_slice($recent,0,10);$recent10=count($top)===10?$result($top):['wins'=>null,'losses'=>null,'rate'=>null];
        $streak=0;$outcome=null;
        foreach(array_slice($recent,0,20) as $row){$win=(int)$row['is_win'];if(!in_array($win,[1,2],true))throw new \InvalidArgumentException('Unsettled recent result.');if($outcome!==null&&$win!==$outcome)break;$outcome=$win;$streak++;}
        return ['month'=>$result($selected['regular']),'bonus'=>$result($selected['bonus']),'recent10'=>$recent10,'streak'=>['outcome'=>$outcome,'count'=>$streak]];
    }
}
