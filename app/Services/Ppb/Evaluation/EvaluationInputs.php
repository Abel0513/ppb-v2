<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** V2-owned attendance corrections and video engagements, never old DB writes. */
class EvaluationInputs
{
    public function attendanceRows(iterable $nativeRows, iterable $corrections): array
    {
        $attendance = [];
        foreach ($nativeRows as $pick) {
            if ($pick['_source_type'] !== 'v2' || !$pick['_absence_key']) continue;
            [$type, $id] = explode(':', $pick['_absence_key']);
            $game = $pick['_game_id'] ?? (int) substr($pick['_schedule_key'], 3);
            $attendance[$pick['_absence_key'].':'.$game] = [
                'gid' => $game, 'uid' => (int) $id, 'subject_type' => $type, 'subject_id' => (int) $id,
                'attendance_status' => 2, 'recommended_time' => $pick['recommended_time'], 'source' => 'derived_substitution',
            ];
        }
        foreach ($corrections as $row) {
            $key = $row->subject_type.':'.$row->subject_id.':'.$row->game_id;
            if ($row->attendance_status === null) {
                unset($attendance[$key]);
                continue;
            }
            $attendance[$key] = [
                'gid' => (int) $row->game_id, 'uid' => (int) $row->subject_id, 'subject_type' => $row->subject_type,
                'subject_id' => (int) $row->subject_id, 'attendance_status' => (int) $row->attendance_status,
                'recommended_time' => CarbonImmutable::parse($row->recommended_at, 'UTC')->tz('Asia/Taipei')->toDateTimeString(),
                'source' => 'v2_correction',
            ];
        }
        usort($attendance, fn ($a, $b) => [$a['recommended_time'], $a['gid']] <=> [$b['recommended_time'], $b['gid']]);
        return $attendance;
    }

    public function read(array $subject,string $month,array $nativeRows): array
    {
        $date=CarbonImmutable::createFromFormat('!Y-m',$month,'Asia/Taipei');$key=$subject['type'].':'.$subject['id'];
        $games=$this->games($subject,$date);
        $overrides=DB::table('ppb_evaluation_attendance')->where('subject_type',$subject['type'])->where('subject_id',$subject['id'])->whereIn('game_id',array_keys($games))->orderBy('game_id')->get();
        $overrides = $overrides->map(fn ($row) => (object) ((array) $row + [
            'recommended_at' => CarbonImmutable::parse($games[$row->game_id]['recommended_time'], 'Asia/Taipei')->utc()->toDateTimeString(),
        ]));
        $attendance = $this->attendanceRows(array_filter($nativeRows, fn ($pick) =>
            $pick['_absence_key'] === $key && substr($pick['recommended_time'], 0, 7) === $month), $overrides);
        $attendance = array_map(fn ($row) => array_diff_key($row, array_flip(['subject_type', 'subject_id'])), $attendance);
        $overrides = $overrides->map(function ($row) { unset($row->recommended_at); return $row; });
        $start=$date->startOfMonth()->utc()->format('Y-m-d H:i:s');$end=$date->endOfMonth()->utc()->format('Y-m-d H:i:s');
        $videos=DB::table('ppb_evaluation_videos')->where(fn($q)=>$q->where('guest_1',$key)->orWhere('guest_2',$key)->orWhere('guest_3',$key))->whereBetween('scheduled_at',[$start,$end])->orderBy('scheduled_at')->orderBy('id')->limit(1001)->get()->map(fn($r)=>(array)$r)->all();
        if(count($videos)>1000)throw new \RuntimeException('V2_VIDEO_COVERAGE_LIMIT');
        return ['attendance'=>$attendance,'games'=>$games,'overrides'=>$overrides->map(fn($r)=>(array)$r)->all(),'videos'=>$videos,'video_count'=>count(array_filter($videos,fn($v)=>!$v['cancelled']))];
    }

    private function games(array $subject,CarbonImmutable $date): array
    {
        if(($subject['native_id']??null)===null)return [];
        $id=(int)$subject['native_id'];
        $rows=DB::table('ppb_games as g')->join('ppb_assignments as a','a.game_id','=','g.id')
            ->where(fn($q)=>$q->where('a.original_analyst_id',$id)->orWhere('a.current_analyst_id',$id))
            ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('ppb_legacy_pick_contents as archive')->whereColumn('archive.game_id','g.id'))
            ->whereBetween('g.recommended_at',[$date->startOfMonth()->utc()->format('Y-m-d H:i:s'),$date->endOfMonth()->utc()->format('Y-m-d H:i:s')])
            ->select('g.id','g.recommended_at')->distinct()->orderBy('g.id')->limit(2001)->get();
        if($rows->count()>2000)throw new \RuntimeException('V2_ATTENDANCE_COVERAGE_LIMIT');
        $out=[];foreach($rows as $r)$out[(int)$r->id]=['id'=>(int)$r->id,'recommended_time'=>CarbonImmutable::parse($r->recommended_at,'UTC')->tz('Asia/Taipei')->format('Y-m-d H:i:s')];return $out;
    }

    public function write(array $subject,string $month,array $payload,array $inputs,int $actor,bool $local=false): array
    {
        abort_unless(in_array($payload['input_kind'],['attendance','video'],true),422);
        if($payload['input_kind']==='attendance'){
            $gid=(int)$payload['game_id'];abort_unless(isset($inputs['games'][$gid]),422,'僅能登錄此分析師當月的 V2 原生賽事；舊出勤紀錄唯讀。');
            $query=DB::table('ppb_evaluation_attendance')->where('subject_type',$subject['type'])->where('subject_id',$subject['id'])->where('game_id',$gid);
            $old=$query->lockForUpdate()->first();$values=['attendance_status'=>$payload['attendance_status'],'actor_id'=>$actor,'revision'=>$old?(int)$old->revision+1:1,'updated_at'=>now()];
            $values['waived_submission_id']=null;
            if($payload['attendance_status']===null){
                // Original attendanceRecordDelete resets only ONE linked pick.
                // Preserve that cardinality; retain the exact V2 lineage in audit.
                $values['waived_submission_id']=DB::table('ppb_submissions as p')->join('ppb_assignments as a','a.id','=','p.assignment_id')
                    ->where('p.game_id',$gid)->whereColumn('p.game_id','a.game_id')->where('a.original_analyst_id',(int)$subject['native_id'])
                    ->whereColumn('p.submitted_analyst_id','!=','a.original_analyst_id')->whereIn('p.status',['submitted','approved','changes_requested'])->whereNotNull('p.submitted_at')->whereNull('p.legacy_source_pick_id')->orderBy('p.id')->value('p.id');
            }
            if($old)$query->update($values);else DB::table('ppb_evaluation_attendance')->insert($values+['subject_type'=>$subject['type'],'subject_id'=>$subject['id'],'game_id'=>$gid,'created_at'=>now()]);
            return ['before'=>$old?(array)$old:null,'after'=>$values+['game_id'=>$gid]];
        }
        $key=$subject['type'].':'.$subject['id'];$guests=[$payload['guest_1'],$payload['guest_2']??null,$payload['guest_3']??null];
        abort_unless(($local && !empty($payload['video_id'])) || in_array($key,$guests,true),422,'此影片必須包含目前分析師。');
        $when=CarbonImmutable::createFromFormat('!Y-m-d\\TH:i',$payload['scheduled_at'],'Asia/Taipei');
        abort_unless($when&&$when->format('Y-m')===$month,422,'影片日期必須屬於目前月份。');
        $values=['scheduled_at'=>$when->utc()->format('Y-m-d H:i:s'),'guest_1'=>$guests[0],'guest_2'=>$guests[1],'guest_3'=>$guests[2],'cancelled'=>(bool)$payload['cancelled'],'actor_id'=>$actor,'updated_at'=>now()];
        foreach (['host_name', 'editor_name', 'week_no', 'sport_label'] as $field) {
            if (array_key_exists($field, $payload)) $values[$field] = $payload[$field];
        }
        $old=null;
        if(!empty($payload['video_id'])){
            $reference=null;foreach($inputs['videos'] as $v)if((int)$v['id']===(int)$payload['video_id'])$reference=$v;
            abort_unless($reference!==null,422,'影片不屬於目前月份。');
            $old=DB::table('ppb_evaluation_videos')->where('id',(int)$payload['video_id'])->lockForUpdate()->first();
            abort_unless($old&&in_array($key,[$old->guest_1,$old->guest_2,$old->guest_3],true),422);
            abort_unless((int)$old->revision===(int)$reference['revision'] && (!isset($payload['video_revision']) || (int) $old->revision === (int) $payload['video_revision']),409,'影片已由其他評鑑更新，請重新讀取。');
            $values['revision']=(int)$old->revision+1;DB::table('ppb_evaluation_videos')->where('id',$old->id)->update($values);$id=(int)$old->id;
        }else{$values['revision']=1;$id=DB::table('ppb_evaluation_videos')->insertGetId($values+['created_at'=>now()]);}
        return ['before'=>$old?(array)$old:null,'after'=>$values+['id'=>$id]];
    }
}
