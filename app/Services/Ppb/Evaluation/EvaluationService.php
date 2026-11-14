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
        $prior=$date->subMonth()->format('Y-m');$earlier=$date->subMonths(2)->format('Y-m');$key=$subject['type'].':'.$subject['id'];
        $snapshot=$subject['type']==='legacy'?$this->reader->snapshot($subject['id'],$month):
            ['picks'=>[],'pick_columns'=>[],'schedules'=>[],'attendance'=>[],'evaluations'=>[],'ratings'=>[],'video_counts'=>[],'recent'=>[],'source_sha256'=>hash('sha256',$key.':'.$month)];
        if($subject['type']==='legacy'){
            $older=$this->reader->snapshot($subject['id'],$prior);
            $snapshot=$this->history($snapshot,$older,$prior);
        }
        $rows=$this->selector->legacyRows($snapshot);$schedules=[];
        foreach($snapshot['schedules'] as $s){$s['_display_code']=((int)$s['game_type']===1?'G':'E').($s['game_id']??'?');$schedules['legacy:'.$s['id']]=$s;}
        [$native,$nativeSchedules]=$this->nativeRows($date,$subject);
        $rows=array_merge($rows,$native);$schedules+=$nativeSchedules;
        foreach($rows as &$p)$p['_game_code']=$schedules[$p['_schedule_key']]['_display_code']??'來源排程缺失';unset($p);
        $overrides=DB::table('ppb_evaluation_ratings')->where('subject_type',$subject['type'])->where('subject_id',$subject['id'])->whereIn('month',[$prior,$month])->get();
        $bySource=[];foreach($overrides as $r)$bySource[$r->source_type.':'.$r->source_id]=(array)$r;
        foreach($rows as &$row){
            if($row['_author_key']!==$key)continue;
            $override=$bySource[$row['_source_type'].':'.$row['_source_id']]??null;
            if($override){foreach(LegacyScheduleCalculator::RATING_FIELDS as $f)$row[$f]=$override[$f];if($row['_source_type']==='v2'&&$override['result_state']!==null)$row['is_win']=(int)$override['result_state'];}
        }unset($row);
        $local=PpbEvaluation::where('subject_type',$subject['type'])->where('subject_id',$subject['id'])->whereIn('month',[$date->subMonths(3)->format('Y-m'),$earlier,$prior,$month])->get()->keyBy('month');
        $sourceHash=hash('sha256',json_encode([$snapshot['source_sha256'],$native,$nativeSchedules],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $reports=[];$manuals=[];$inputs=[];
        foreach([$earlier,$prior,$month] as $period){
            $manual=$this->report->defaults($period);
            foreach($snapshot['evaluations'] as $e)if($e['eval_month']===$period)$manual=array_replace($manual,array_intersect_key($e,$manual));
            if($local->has($period))$manual=array_replace($manual,array_intersect_key($local[$period]->getAttributes(),$manual));
            $manuals[$period]=$manual;
            $selected=$this->selector->select($rows,$key,$period,$schedules);
            $inputs[$period]=(new EvaluationInputs)->read($subject,$period,$native);
            $attendance=array_values(array_filter($snapshot['attendance'],fn($a)=>substr($a['recommended_time'],0,7)===$period));
            $attendance=array_merge($attendance,$inputs[$period]['attendance']);
            usort($attendance,fn($a,$b)=>[$a['recommended_time'],(int)($a['id']??$a['gid'])]<=>[$b['recommended_time'],(int)($b['id']??$b['gid'])]);
            $level=$this->level($snapshot,$local,$period);
            $reports[$period]=$this->report->calculate($selected,$manual,$schedules,$attendance,(int)($snapshot['video_counts'][$period]??0)+$inputs[$period]['video_count'],$level);
            $reports[$period]['level']=$level;$reports[$period]['selected']=$selected;
        }
        $sourceHash=hash('sha256',json_encode([$sourceHash,$inputs],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $current=$reports[$month];$previous=$reports[$prior];
        $base=$current['level']??(int)($snapshot['profile']['rating_level']??2);
        if(!in_array($base,[0,1,2,3],true))throw new \RuntimeException('UNVERIFIED_PROFILE_RATING_LEVEL');
        $current['next_rating']=$this->rating->rating($base,$previous['level'],(int)$current['rating']['total_score'],(int)$previous['rating']['total_score']);
        $recent=[];
        foreach($snapshot['recent'] as $row)$recent[]=$row+['_source_type'=>'legacy'];
        $recent=array_merge($recent,$this->nativeRecent($subject));
        usort($recent,fn($a,$b)=>[$b['recommended_time'],$b['_source_type'],(int)$b['id']]<=>[$a['recommended_time'],$a['_source_type'],(int)$a['id']]);
        $recent=array_slice($recent,0,20);
        $current['performance']=(new EvaluationPerformance)->summarize($current['selected'],$recent);
        $salaryEvent=DB::table('ppb_evaluation_events as ev')->join('ppb_evaluations as e','e.id','=','ev.evaluation_id')
            ->where('e.subject_type',$subject['type'])->where('e.subject_id',$subject['id'])->where('e.month',$month)
            ->where('ev.operation','salary_snapshot')->orderByDesc('ev.id')->first(['ev.request_id','ev.after_json','ev.created_at']);
        $savedSalary=null;
        if($salaryEvent){
            $saved=json_decode($salaryEvent->after_json,true,512,JSON_THROW_ON_ERROR);
            $savedSalary=['request_id'=>$salaryEvent->request_id,'created_at'=>$salaryEvent->created_at,
                'revision'=>(int)$saved['evaluation']['revision'],'snapshot'=>$saved['salary_snapshot']];
        }
        return ['subject'=>$subject,'month'=>$month,'previous_month'=>$prior,'source_sha256'=>$sourceHash,'legacy_sha256'=>$snapshot['source_sha256'],
            'manual'=>$manuals[$month],'current'=>$current,'previous'=>$previous,'revision'=>$local->has($month)?$local[$month]->revision:0,
            'earlier_month'=>$earlier,'history'=>$reports,'saved_next_rating'=>$local->has($month)?$local[$month]->next_rating_level:null,'recent'=>$recent,'source_rows'=>count($rows),
            'native_attendance_video_supported'=>true,'inputs'=>$inputs[$month],'saved_salary'=>$savedSalary];
    }

    /** Each month is one fixed snapshot; shared-month drift aborts the read. */
    private function history(array $current,array $older,string $overlap): array
    {
        $periodRows=static function(array $snapshot,string $key,string $dateField)use($overlap):array{
            if($key==='picks'){
                $index=array_search('recommended_time',$snapshot['pick_columns'],true);
                return array_values(array_filter($snapshot[$key],fn($r)=>substr($r[$index],0,7)===$overlap));
            }
            return array_values(array_filter($snapshot[$key],fn($r)=>substr($r[$dateField],0,7)===$overlap));
        };
        $same=static function($a,$b):void{if($a!==$b)throw new \RuntimeException('LEGACY_HISTORY_CHANGED_DURING_READ');};
        $same($current['profile']??null,$older['profile']??null);$same($current['pick_columns'],$older['pick_columns']);
        foreach(['picks'=>'recommended_time','attendance'=>'recommended_time','evaluations'=>'eval_month'] as $key=>$field)$same($periodRows($current,$key,$field),$periodRows($older,$key,$field));
        $same($current['video_counts'][$overlap]??0,$older['video_counts'][$overlap]??0);
        foreach(['schedules'=>'id','evaluations'=>'eval_month','ratings'=>'rating_month','attendance'=>'id','picks'=>null] as $key=>$idField){
            $merged=[];
            foreach(array_merge($older[$key],$current[$key]) as $row){$id=$idField===null?$row[array_search('id',$current['pick_columns'],true)]:$row[$idField];if(isset($merged[$id]))$same($merged[$id],$row);$merged[$id]=$row;}
            $current[$key]=array_values($merged);
        }
        $current['video_counts']=$current['video_counts']+$older['video_counts'];
        $current['source_sha256']=hash('sha256',$current['source_sha256'].':'.$older['source_sha256']);
        return $current;
    }

    private function level(array $snapshot,$local,string $period): ?int
    {
        $previous=CarbonImmutable::createFromFormat('!Y-m',$period)->subMonth()->format('Y-m');
        if($local->has($previous)&&$local[$previous]->next_rating_level!==null)return (int)$local[$previous]->next_rating_level;
        foreach($snapshot['ratings'] as $r)if($r['rating_month']===$period)return (int)$r['rating_level'];
        return null; // Source salary falls back to2; do not invent stored history.
    }

    private function nativeRecent(array $subject): array
    {
        if(($subject['native_id']??null)===null)return [];
        $rows=DB::table('ppb_submissions as p')->join('ppb_games as g','g.id','=','p.game_id')->join('ppb_assignments as a','a.id','=','p.assignment_id')
            ->leftJoin('ppb_evaluation_attendance as c',function($q){
                $q->on('c.game_id','=','g.id')->on('c.waived_submission_id','=','p.id')->whereNull('c.attendance_status');
                $maps=$this->subjects->mappings();
                if(!$maps){$q->where('c.subject_type','v2')->on('c.subject_id','=','a.original_analyst_id');return;}
                $type='CASE a.original_analyst_id';$id='CASE a.original_analyst_id';$typeBindings=[];$idBindings=[];
                foreach($maps as $legacy=>$native){$type.=' WHEN ? THEN ?';$id.=' WHEN ? THEN ?';array_push($typeBindings,$native,'legacy');array_push($idBindings,$native,$legacy);}
                $q->whereRaw("BINARY c.subject_type = BINARY (".$type." ELSE 'v2' END)",$typeBindings)->whereRaw('c.subject_id = '.$id.' ELSE a.original_analyst_id END',$idBindings);
            })
            ->join('ppb_evaluation_ratings as r',fn($q)=>$q->on('r.source_id','=','p.id')->where('r.source_type','v2'))
            ->whereColumn('a.game_id','p.game_id')->whereColumn('a.kind','p.kind')
            ->where('p.submitted_analyst_id',(int)$subject['native_id'])->where(fn($q)=>$q->whereColumn('p.submitted_analyst_id','a.original_analyst_id')->orWhereNotNull('c.id'))
            ->whereNull('p.legacy_source_pick_id')->whereIn('p.status',['submitted','approved','changes_requested'])->whereNotNull('p.submitted_at')->whereNotNull('g.recommended_at')->where('p.kind','pick')->whereIn('r.result_state',[1,2])
            ->orderByDesc('g.recommended_at')->orderByDesc('p.id')->limit(20)->get(['p.id','g.recommended_at','r.result_state']);
        return $rows->map(fn($r)=>['id'=>(int)$r->id,'recommended_time'=>CarbonImmutable::parse($r->recommended_at,'UTC')->tz('Asia/Taipei')->format('Y-m-d H:i:s'),'is_win'=>(int)$r->result_state,'_source_type'=>'v2'])->all();
    }

    private function nativeRows(CarbonImmutable $date,array $subject): array
    {
        if(($subject['native_id']??null)===null)return [[],[]];
        $nativeId=(int)$subject['native_id'];
        $records=DB::table('ppb_submissions as p')->join('ppb_games as g','g.id','=','p.game_id')->join('ppb_assignments as a','a.id','=','p.assignment_id')
            ->where(fn($q)=>$q->where('p.submitted_analyst_id',$nativeId)->orWhere('a.original_analyst_id',$nativeId))
            ->whereColumn('a.game_id','p.game_id')->whereColumn('a.kind','p.kind')->whereIn('p.kind',['pick','bonus','long'])
            ->whereNull('p.legacy_source_pick_id')->whereIn('p.status',['submitted','approved','changes_requested'])->whereNotNull('p.submitted_at')->whereNotNull('p.submitted_analyst_id')
            ->whereBetween('g.recommended_at',[$date->subMonths(2)->startOfMonth()->utc()->format('Y-m-d H:i:s'),$date->endOfMonth()->utc()->format('Y-m-d H:i:s')])
            ->orderBy('g.recommended_at')->orderBy('p.id')->limit(2001)
            ->get(['p.id','p.game_id','p.submitted_analyst_id','p.kind','a.original_analyst_id','g.recommended_at','g.market_type','g.game_type','g.game_number']);
        if($records->count()>2000)throw new \RuntimeException('V2_EVALUATION_COVERAGE_LIMIT');
        $maps=$this->subjects->mappings();$reverse=array_flip($maps);$identity=fn($id)=>isset($reverse[$id])?'legacy:'.$reverse[$id]:'v2:'.$id;
        $rows=[];$schedules=[];
        foreach($records as $r){
            $author=$identity((int)$r->submitted_analyst_id);$original=$identity((int)$r->original_analyst_id);$sub=$original!==$author;
            $p=array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS,0);
            $p+=['_source_type'=>'v2','_source_id'=>(int)$r->id,'_author_key'=>$author,'_absence_key'=>$sub?$original:null,'_is_substitution'=>$sub,'_schedule_key'=>'v2:'.$r->game_id,
                'recommended_time'=>CarbonImmutable::parse($r->recommended_at,'UTC')->tz('Asia/Taipei')->format('Y-m-d H:i:s'),'is_win'=>0,'is_bonus'=>$r->kind==='bonus'?1:0,'is_long'=>$r->kind==='long'?1:0];
            $rows[]=$p;$schedules[$p['_schedule_key']]=['id'=>(int)$r->game_id,'market_type'=>(int)$r->market_type,'_display_code'=>((int)$r->game_type===1?'G':'E').($r->game_number??'?')];
        }
        if($rows){
            $waivers=DB::table('ppb_evaluation_attendance')->whereIn('game_id',array_map(fn($r)=>(int)substr($r['_schedule_key'],3),$rows))->whereNull('attendance_status')->get(['game_id','subject_type','subject_id','waived_submission_id']);
            $waived=[];foreach($waivers as $w)$waived[(int)$w->waived_submission_id][$w->subject_type.':'.$w->subject_id]=true;
            foreach($rows as &$row)if(isset($waived[$row['_source_id']][$row['_absence_key']??''])){$row['_absence_key']=null;$row['_is_substitution']=false;}unset($row);
        }
        return [$rows,$schedules];
    }
}
