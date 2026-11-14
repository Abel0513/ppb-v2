<?php
declare(strict_types=1);
namespace App\Services\Ppb\Evaluation;

use App\Models\PpbEvaluation;
use Illuminate\Support\Facades\DB;

/** Receipt-bound, revision-checked V2-only transactions; no legacy connection. */
class EvaluationWriter
{
    public function __construct(private EvaluationService $service,private LegacyInteractionCalculator $interaction) {}
    public function save(array $subject,string $month,string $operation,array $payload,string $requestId,int $revision,string $sourceHash,int $actor): array
    {
        abort_unless(in_array($operation,['manual','rate','rebuild','input'],true),422);
        abort_unless(preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD',$requestId),422);
        $fingerprint=hash('sha256',json_encode([$subject['type'],$subject['id'],$month,$operation,$payload,$revision,$sourceHash,$actor],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));
        $existing=DB::table('ppb_evaluation_events')->where('request_id',$requestId)->first();
        if($existing)return $this->receipt($existing,$fingerprint);
        $report=$this->service->load($subject,$month);
        abort_unless(hash_equals($report['source_sha256'],$sourceHash),409,'來源資料已變更，請重新讀取後再評鑑。');
        return DB::transaction(function()use($subject,$month,$operation,$payload,$requestId,$revision,$sourceHash,$actor,$fingerprint,$report){
            // Unique request and subject/month indexes close concurrent-create races.
            DB::table('ppb_evaluations')->insertOrIgnore(['subject_type'=>$subject['type'],'subject_id'=>$subject['id'],'month'=>$month,'revision'=>0,'source_sha256'=>$sourceHash,'created_at'=>now(),'updated_at'=>now()]);
            $evaluation=PpbEvaluation::where('subject_type',$subject['type'])->where('subject_id',$subject['id'])->where('month',$month)->lockForUpdate()->firstOrFail();
            $again=DB::table('ppb_evaluation_events')->where('request_id',$requestId)->first();
            if($again)return $this->receipt($again,$fingerprint);
            abort_unless($evaluation->revision===$revision,409,'評鑑已由其他操作更新，請重新讀取。');
            $before=$evaluation->getAttributes();$changes=$revision===0?$report['manual']:[];
            // A first record-rating must recompute against the source manual
            // baseline, not the newly inserted row's nullable SQL defaults.
            if($revision===0){$evaluation->fill($report['manual']);$evaluation->save();}
            if($operation==='manual'){
                $weeks=array_intersect_key($payload,array_fill_keys(array_map(fn($w)=>'score_interaction_w'.$w,range(1,5)),true));
                $changes=array_replace($changes,$this->interaction->manual($weeks,$payload['score_interaction_feedback']??0));
                abort_unless($changes['score_interaction_avg']===null||abs($changes['score_interaction_avg'])<=999999.99,422,'互動平均超出可儲存範圍。');
                $changes['score_initiative']=(int)$payload['score_initiative'];$changes['score_extra_adjustment']=(int)$payload['score_extra_adjustment'];
            }elseif($operation==='rate'){
                $pick=null;
                foreach($report['current']['selected']['records'] as $p)if($p['_source_type']===$payload['source_type']&&$p['_source_id']===(int)$payload['source_id'])$pick=$p;
                abort_unless($pick!==null,422,'稿件不屬於本月份與分析師。');
                $values=array_intersect_key($payload,array_fill_keys(LegacyScheduleCalculator::RATING_FIELDS,true));
                abort_unless(count($values)===9,422);
                if($payload['source_type']==='legacy')abort_if(array_key_exists('result_state',$payload),422,'舊稿勝負唯讀。');
                $values+=['subject_type'=>$subject['type'],'subject_id'=>$subject['id'],'month'=>$month,'source_sha256'=>$sourceHash,'actor_id'=>$actor,'updated_at'=>now()];
                if(array_key_exists('result_state',$payload))$values['result_state']=$payload['result_state'];
                $row=DB::table('ppb_evaluation_ratings')->where('source_type',$payload['source_type'])->where('source_id',$payload['source_id'])->first();
                $values['revision']=$row?(int)$row->revision+1:1;
                if($row)DB::table('ppb_evaluation_ratings')->where('id',$row->id)->update($values);
                else DB::table('ppb_evaluation_ratings')->insert($values+['source_type'=>$payload['source_type'],'source_id'=>$payload['source_id'],'created_at'=>now()]);
                $fresh=$this->service->load($subject,$month);
                foreach(['score_delivery_time','score_compliance','score_professionalism'] as $f)$changes[$f]=$fresh['current']['cached'][$f];
            }elseif($operation==='input'){
                $inputChange=(new EvaluationInputs)->write($subject,$month,$payload,$report['inputs'],$actor);
            }else $changes['next_rating_level']=$report['current']['next_rating']['rating_level'];
            $evaluation->fill($changes+['source_sha256'=>$sourceHash,'actor_id'=>$actor,'revision'=>$revision+1]);$evaluation->save();
            $after=['evaluation'=>$evaluation->getAttributes(),'operation_payload'=>$payload];
            if(isset($inputChange))$after['input_change']=$inputChange;
            DB::table('ppb_evaluation_events')->insert(['evaluation_id'=>$evaluation->id,'request_id'=>$requestId,'operation'=>$operation,'payload_sha256'=>$fingerprint,'actor_id'=>$actor,'before_json'=>json_encode($before,JSON_THROW_ON_ERROR),'after_json'=>json_encode($after,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return ['replayed'=>false,'request_id'=>$requestId,'revision'=>$evaluation->revision];
        },1);
    }
    private function receipt(object $event,string $fingerprint): array
    {
        abort_unless(hash_equals($event->payload_sha256,$fingerprint),409,'同一請求編號不可重用於不同內容。');
        $after=json_decode($event->after_json,true,512,JSON_THROW_ON_ERROR);
        return ['replayed'=>true,'request_id'=>$event->request_id,'revision'=>(int)$after['evaluation']['revision']];
    }
}
