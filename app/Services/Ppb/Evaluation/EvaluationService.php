<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

use App\Models\PpbEvaluation;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

/** All source reads use the nonroot fixed reader. All writes target V2 tables. */
class EvaluationService
{
    public function __construct(private LegacyReader $reader,private EvaluationSubjects $subjects,private LegacyMonthSelector $selector,private EvaluationReport $report,private LegacyRatingCalculator $rating) {}

    public function load(array $subject,string $month): array
    {
        $date=CarbonImmutable::createFromFormat('!Y-m',$month,'Asia/Taipei');
        if(!$date||$date->format('Y-m')!==$month)throw new \InvalidArgumentException('Invalid month.');
        $prior=$date->subMonth()->format('Y-m');$key=$subject['type'].':'.$subject['id'];
        $snapshot=$subject['type']==='legacy'?$this->reader->query(['operation'=>'evaluation_snapshot','analyst_id'=>$subject['id'],'month'=>$month])['snapshot']:
            ['picks'=>[],'pick_columns'=>[],'schedules'=>[],'attendance'=>[],'evaluations'=>[],'ratings'=>[],'video_counts'=>[],'recent'=>[],'source_sha256'=>hash('sha256',$key.':'.$month)];
        $rows=$this->selector->legacyRows($snapshot);$schedules=[];
        foreach($snapshot['schedules'] as $s)$schedules['legacy:'.$s['id']]=$s;
        [$native,$nativeSchedules]=$this->nativeRows($date);
        $rows=array_merge($rows,$native);$schedules+=$nativeSchedules;
        $overrides=DB::table('ppb_evaluation_ratings')->where('subject_type',$subject['type'])->where('subject_id',$subject['id'])->whereIn('month',[$prior,$month])->get();
        $bySource=[];foreach($overrides as $r)$bySource[$r->source_type.':'.$r->source_id]=(array)$r;
        foreach($rows as &$row){
            if($row['_author_key']!==$key)continue;
            $override=$bySource[$row['_source_type'].':'.$row['_source_id']]??null;
            if($override){foreach(LegacyScheduleCalculator::RATING_FIELDS as $f)$row[$f]=$override[$f];if($row['_source_type']==='v2'&&$override['result_state']!==null)$row['is_win']=(int)$override['result_state'];}
        }unset($row);
        $local=PpbEvaluation::where('subject_type',$subject['type'])->where('subject_id',$subject['id'])->whereIn('month',[$date->subMonths(2)->format('Y-m'),$prior,$month])->get()->keyBy('month');
        $sourceHash=hash('sha256',json_encode([$snapshot['source_sha256'],$native,$nativeSchedules],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $reports=[];$manuals=[];
        foreach([$prior,$month] as $period){
            $manual=$this->report->defaults($period);
            foreach($snapshot['evaluations'] as $e)if($e['eval_month']===$period)$manual=array_replace($manual,array_intersect_key($e,$manual));
            if($local->has($period))$manual=array_replace($manual,array_intersect_key($local[$period]->getAttributes(),$manual));
            $manuals[$period]=$manual;
            $selected=$this->selector->select($rows,$key,$period,$schedules);
            $attendance=array_values(array_filter($snapshot['attendance'],fn($a)=>substr($a['recommended_time'],0,7)===$period));
            $level=$this->level($snapshot,$local,$period);
            $reports[$period]=$this->report->calculate($selected,$manual,$schedules,$attendance,(int)($snapshot['video_counts'][$period]??0),$level);
            $reports[$period]['level']=$level;$reports[$period]['selected']=$selected;
        }
        $current=$reports[$month];$previous=$reports[$prior];
        $current['next_rating']=$this->rating->rating($current['level']??2,$previous['level'],(int)$current['rating']['total_score'],(int)$previous['rating']['total_score']);
        return ['subject'=>$subject,'month'=>$month,'previous_month'=>$prior,'source_sha256'=>$sourceHash,'legacy_sha256'=>$snapshot['source_sha256'],
            'manual'=>$manuals[$month],'current'=>$current,'previous'=>$previous,'revision'=>$local->has($month)?$local[$month]->revision:0,
            'saved_next_rating'=>$local->has($month)?$local[$month]->next_rating_level:null,'recent'=>$snapshot['recent'],'source_rows'=>count($rows),
            'native_attendance_video_supported'=>false];
    }

    private function level(array $snapshot,$local,string $period): ?int
    {
        $previous=CarbonImmutable::createFromFormat('!Y-m',$period)->subMonth()->format('Y-m');
        if($local->has($previous)&&$local[$previous]->next_rating_level!==null)return (int)$local[$previous]->next_rating_level;
        foreach($snapshot['ratings'] as $r)if($r['rating_month']===$period)return (int)$r['rating_level'];
        return null; // Source salary falls back to2; do not invent stored history.
    }

    private function nativeRows(CarbonImmutable $date): array
    {
        $records=DB::table('ppb_submissions as p')->join('ppb_games as g','g.id','=','p.game_id')->join('ppb_assignments as a','a.id','=','p.assignment_id')
            ->whereNull('p.legacy_source_pick_id')->whereNotNull('p.submitted_at')->whereNotNull('p.submitted_analyst_id')
            ->whereBetween('g.recommended_at',[$date->subMonth()->startOfMonth()->utc()->format('Y-m-d H:i:s'),$date->endOfMonth()->utc()->format('Y-m-d H:i:s')])
            ->orderBy('g.recommended_at')->orderBy('p.id')->limit(2001)
            ->get(['p.id','p.game_id','p.submitted_analyst_id','p.kind','a.original_analyst_id','g.recommended_at','g.market_type']);
        if($records->count()>2000)throw new \RuntimeException('V2_EVALUATION_COVERAGE_LIMIT');
        $maps=$this->subjects->mappings();$reverse=array_flip($maps);$identity=fn($id)=>isset($reverse[$id])?'legacy:'.$reverse[$id]:'v2:'.$id;
        $rows=[];$schedules=[];
        foreach($records as $r){
            $author=$identity((int)$r->submitted_analyst_id);$original=$identity((int)$r->original_analyst_id);$sub=$original!==$author;
            $p=array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS,0);
            $p+=['_source_type'=>'v2','_source_id'=>(int)$r->id,'_author_key'=>$author,'_absence_key'=>$sub?$original:null,'_is_substitution'=>$sub,'_schedule_key'=>'v2:'.$r->game_id,
                'recommended_time'=>CarbonImmutable::parse($r->recommended_at,'UTC')->tz('Asia/Taipei')->format('Y-m-d H:i:s'),'is_win'=>0,'is_bonus'=>$r->kind==='bonus'?1:0,'is_long'=>$r->kind==='long'?1:0];
            $rows[]=$p;$schedules[$p['_schedule_key']]=['id'=>(int)$r->game_id,'market_type'=>(int)$r->market_type];
        }
        return [$rows,$schedules];
    }
}
