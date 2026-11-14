<?php
namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Services\Ppb\Evaluation\{EvaluationSubjects,EvaluationService,EvaluationWriter,LegacyScheduleCalculator};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class EvaluationController extends Controller
{
    public function __construct(private EvaluationSubjects $subjects,private EvaluationService $service,private EvaluationWriter $writer) {}
    private function month(Request $request): string
    {
        $data=$request->validate(['month'=>['nullable','date_format:Y-m','after_or_equal:1000-01','before_or_equal:2999-12']]);
        return $data['month']??now('Asia/Taipei')->startOfMonth()->subMonth()->format('Y-m');
    }
    public function index(Request $request)
    {
        $month=$this->month($request);$subjects=$this->subjects->all();
        return view('ppb.evaluations.index',compact('month','subjects'));
    }
    public function show(Request $request,string $type,int $id)
    {
        $report=$this->service->load($this->subjects->get($type,$id),$this->month($request));
        if($request->query('format')==='csv'){
            $salary=$report['current']['salary'];$values=[$report['subject']['label'],$report['month'],...array_values($salary)];
            return response()->streamDownload(function()use($values){$f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,['分析師','月份','短稿','長稿','影片','遲到','缺勤','合計'],',','"','');
                fputcsv($f,array_map(fn($v)=>is_string($v)&&preg_match('/^[=+@\-\t\r]/u',$v)?"'".$v:$v,$values),',','"','');fclose($f);},'evaluation-'.$report['month'].'.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private,no-store']);
        }
        return response()->view('ppb.evaluations.show',['report'=>$report,'subjects'=>$this->subjects->all()])->header('Cache-Control','private,no-store');
    }
    public function save(Request $request,string $type,int $id)
    {
        $subject=$this->subjects->get($type,$id);$month=$this->month($request);
        $base=$request->validate(['request_id'=>['required','uuid'],'revision'=>['required','integer','min:0'],'source_sha256'=>['required','regex:/^[a-f0-9]{64}$/D'],'operation'=>['required',Rule::in(['manual','rate','rebuild','input'])]]);
        $rules=[];
        if($base['operation']==='manual'){
            foreach(range(1,5) as $w)$rules['score_interaction_w'.$w]=['present','nullable','numeric','decimal:0,2','between:-999999.99,999999.99'];
            $rules['score_interaction_feedback']=['required','integer','between:-32768,32767'];
            foreach(['score_initiative','score_extra_adjustment'] as $f)$rules[$f]=['required','integer','between:-2147483648,2147483647'];
        }elseif($base['operation']==='rate'){
            $rules=['source_type'=>['required',Rule::in(['legacy','v2'])],'source_id'=>['required','integer','min:1']];
            foreach(LegacyScheduleCalculator::RATING_FIELDS as $f)$rules[$f]=['required','integer','between:-32768,32767'];
            if($request->input('source_type')==='v2')$rules['result_state']=['present','nullable','integer',Rule::in([0,1,2])];
            elseif($request->has('result_state'))abort(422);
        }
        if($base['operation']==='input'){
            $kind=$request->validate(['input_kind'=>['required',Rule::in(['attendance','video'])]])['input_kind'];
            $rules=['input_kind'=>['required',Rule::in([$kind])]];
            if($kind==='attendance')$rules+=['game_id'=>['required','integer','min:1'],'attendance_status'=>['present','nullable','integer',Rule::in([1,2])]];
            else{
                $keys=array_map(fn($s)=>$s['type'].':'.$s['id'],$this->subjects->all());
                $rules+=['video_id'=>['nullable','integer','min:1'],'scheduled_at'=>['required','date_format:Y-m-d\\TH:i'],'guest_1'=>['required',Rule::in($keys)],'guest_2'=>['nullable',Rule::in($keys)],'guest_3'=>['nullable',Rule::in($keys)],'cancelled'=>['required','boolean']];
            }
        }
        $payload=$request->validate($rules);
        $receipt=$this->writer->save($subject,$month,$base['operation'],$payload,$base['request_id'],(int)$base['revision'],$base['source_sha256'],(int)Auth::guard('ppb')->id());
        return redirect()->route('ppb.evaluations.show',[$type,$id,'month'=>$month])->with('status',$receipt['replayed']?'已核對相同請求，未重複寫入。':'評鑑已儲存。')->with('evaluation_receipt',$receipt);
    }
}
