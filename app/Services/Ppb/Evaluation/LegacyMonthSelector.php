<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

/** Exact source selectors, kept separate from the already verified arithmetic. */
final class LegacyMonthSelector
{
    public function select(array $rows, string $subjectKey, string $month, array $schedules): array
    {
        if (!preg_match('/^[12][0-9]{3}-(0[1-9]|1[0-2])$/D',$month)) throw new \InvalidArgumentException('Invalid month.');
        $out=array_fill_keys(['rated','cache_update','absences','settled_view','settled_rating','regular','bonus','salary_short','salary_long','records'],[]);
        foreach($rows as $p){
            if(substr($p['recommended_time'],0,7)!==$month)continue;
            $own=$p['_author_key']===$subjectKey;$absence=($p['_absence_key']??null)===$subjectKey;
            $sub=(bool)$p['_is_substitution'];$long=(int)$p['is_long']===1;$bonus=(int)$p['is_bonus']===1;
            $resolved=in_array((int)$p['is_win'],[1,2],true);
            if($absence&&!$bonus)$out['absences'][]=$p;
            if(!$own)continue;
            $out['records'][]=$p;
            if((float)$p['score_delivery_time']>0){
                $out['cache_update'][]=$p;
                if($resolved&&!$long&&!$sub)$out['rated'][]=$p;
            }
            if($resolved&&!$long&&!$bonus){
                $out['settled_view'][]=$p;
                if(!$sub){$out['settled_rating'][]=$p;$out['regular'][]=$p;}
            }
            if($resolved&&$bonus&&!$sub)$out['bonus'][]=$p;
            if(isset($schedules[$p['_schedule_key']])){
                if($long)$out['salary_long'][]=$p;
                elseif(!$sub&&$resolved)$out['salary_short'][]=$p;
            }
        }
        return $out;
    }

    public function legacyRows(array $snapshot): array
    {
        $rows=[];
        foreach($snapshot['picks'] as $values){
            if(count($values)!==count($snapshot['pick_columns']))throw new \RuntimeException('SOURCE_MATRIX_INCOMPLETE');
            $p=array_combine($snapshot['pick_columns'],$values);
            $p['_source_type']='legacy';$p['_source_id']=(int)$p['id'];
            $p['_author_key']='legacy:'.(int)$p['analyst_name'];
            $p['_is_substitution']=(int)$p['is_substitute']!==0;
            $p['_absence_key']=(int)$p['is_substitute']>1?'legacy:'.(int)$p['is_substitute']:null;
            $p['_schedule_key']='legacy:'.(int)$p['gid'];$rows[]=$p;
        }
        return $rows;
    }
}
