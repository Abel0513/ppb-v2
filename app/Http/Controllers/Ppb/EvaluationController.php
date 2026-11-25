<?php
namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Services\Ppb\Evaluation\{EvaluationSubjects,EvaluationService,EvaluationWriter,LegacyScheduleCalculator,LocalEvaluationData};
use Illuminate\Http\Request;
use App\Models\PpbAssignment;
use App\Models\PpbGame;
use Illuminate\Support\Facades\Schema;
use App\Services\Ppb\LegacyDisplayLabels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class EvaluationController extends Controller
{
    public function __construct(private EvaluationSubjects $subjects,private EvaluationService $service,private EvaluationWriter $writer) {}
    private function month(Request $request): string
    {
        $data=$request->validate(['month'=>['nullable','date_format:Y-m','after_or_equal:1000-01','before_or_equal:2999-12']]);
        return $data['month']??now('Asia/Taipei')->format('Y-m');
    }
    public function index(Request $request)
    {
        $month=$this->month($request);$options=$request->validate(['page'=>['nullable','integer','min:1','max:1000']]);
        $page=(int)($options['page']??1);$all=$this->subjects->all();$total=count($all);$subjects=array_slice($all,($page-1)*5,5);
        return view('ppb.evaluations.index',compact('month','subjects','page','total'));
    }
    public function show(Request $request,string $type,int $id)
    {
        $report=$this->service->load($this->subjects->get($type,$id),$this->month($request));
        if($request->query('format')==='summary'){
            return response()->json(['score'=>$report['current']['rating']['total_score'],'previous_score'=>$report['previous']['rating']['total_score'],
                'earlier_score'=>$report['history'][$report['earlier_month']]['rating']['total_score'],'level'=>$report['current']['level'],
                'next_level'=>$report['current']['next_rating']['rating_level'],
                'level_label'=>LegacyDisplayLabels::rating($report['current']['level']),
                'next_level_label'=>LegacyDisplayLabels::rating($report['current']['next_rating']['rating_level']),'salary'=>$report['current']['salary'],
                'performance'=>$report['current']['performance']],200,['Cache-Control'=>'private,no-store']);
        }
        if($request->query('format')==='csv'){
            $salary=$report['current']['salary'];$values=[$report['subject']['label'],$report['month'],...array_values($salary)];
            return response()->streamDownload(function()use($values){$f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,['分析師','月份','短稿','長稿','影片','遲到','缺勤','合計'],',','"','');
                fputcsv($f,array_map(fn($v)=>is_string($v)&&preg_match('/^[=+@\-\t\r]/u',$v)?"'".$v:$v,$values),',','"','');fclose($f);},'evaluation-'.$report['month'].'.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private,no-store']);
        }
        return response()->view('ppb.evaluations.show',['report'=>$report,'subjects'=>$this->subjects->all()])->header('Cache-Control','private,no-store');
    }
    public function scheduleRating(Request $request, PpbGame $game, PpbAssignment $assignment, LocalEvaluationData $data)
    {
        abort_unless((int) $assignment->game_id === (int) $game->id && $assignment->status === 'active', 404);
        $month = $game->recommended_at?->timezone('Asia/Taipei')->format('Y-m');
        if (!$month || !$data->ready()) return response()->json(['pick' => null]);
        $identity = $this->subjects->forNative((int) $assignment->current_analyst_id);
        $subject = $data->roster()[$identity['type'].':'.$identity['id']] ?? null;
        if (!$subject) return response()->json(['pick' => null]);
        $report = $this->service->loadLocal($subject, $month);
        $records = collect($report['current']['selected']['records'])
            ->filter(fn ($pick) => $pick['_game_id'] === (int) $game->id && $pick['_assignment_id'] === (int) $assignment->id);
        $pick = $records->where('_source_type', 'v2')->sortByDesc('_source_id')->first()
            ?? $records->where('_source_type', 'legacy')->first();
        $canSave = $game->status !== 'cancelled' && $request->user('ppb')->hasPpbPermission('evaluations.manage');
        return response()->json([
            'pick' => $pick ? array_intersect_key($pick, array_flip(['_source_type', '_source_id', ...LegacyScheduleCalculator::RATING_FIELDS])) : null,
            'save_url' => $canSave && $pick ? route('ppb.evaluations.save', [$subject['type'], $subject['id']], false) : null,
            'month' => $month, 'revision' => $report['revision'], 'source_sha256' => $report['source_sha256'],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function videoReceipt(Request $request, string $type, int $id, LocalEvaluationData $data)
    {
        abort_unless($data->ready() && Schema::hasColumn('ppb_evaluation_videos', 'week_no'), 503);
        $subject = $data->roster()[$type.':'.$id] ?? abort(404);
        $report = $this->service->loadLocal($subject, $this->month($request));
        return response()->json([
            'save_url' => route('ppb.evaluations.save', [$type, $id], false),
            'revision' => $report['revision'], 'source_sha256' => $report['source_sha256'],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function save(Request $request,string $type,int $id, LocalEvaluationData $data)
    {
        $scope = $request->validate(['schedule_scope' => ['nullable', Rule::in(['rating', 'video'])]])['schedule_scope'] ?? null;
        $subject = $scope ? ($data->roster()[$type.':'.$id] ?? abort(404)) : $this->subjects->get($type,$id);
        $month=$this->month($request);
        $base=$request->validate(['request_id'=>['required','uuid'],'revision'=>['required','integer','min:0'],'source_sha256'=>['required','regex:/^[a-f0-9]{64}$/D'],'operation'=>['required',Rule::in(['manual','rate','rebuild','input','salary_snapshot'])]]);
        if ($scope === 'rating') abort_unless($base['operation'] === 'rate' && $data->ready(), 422);
        if ($scope === 'video') abort_unless($base['operation'] === 'input' && $data->ready()
            && $request->user('ppb')->hasPpbPermission('schedules.view')
            && $request->user('ppb')->hasPpbPermission('evaluations.view')
            && Schema::hasColumn('ppb_evaluation_videos', 'week_no'), 422);
        $rules=[];
        if($base['operation']==='manual'){
            foreach(range(1,5) as $w)$rules['score_interaction_w'.$w]=['present','nullable','numeric','decimal:0,2','between:-999999.99,999999.99'];
            $rules['score_interaction_feedback']=['required','integer','between:-32768,32767'];
            foreach(['score_initiative','score_extra_adjustment'] as $f)$rules[$f]=['required','integer','between:-2147483648,2147483647'];
        }elseif($base['operation']==='rate'){
            $rules=['source_type'=>['required',Rule::in(['legacy','v2'])],'source_id'=>['required','integer','min:1']];
            foreach(LegacyScheduleCalculator::RATING_FIELDS as $f)$rules[$f]=['required','integer','between:-32768,32767'];
            if ($scope === 'rating') {
                $options = ['score_word_count' => [60, 40], 'score_format' => [60, 40],
                    'score_typos' => [20, 10, 0], 'score_semantic_clarity' => [20, 10, 0],
                    'score_reference_accuracy' => [20, 10, 0], 'score_logic_consistency' => [20, 10, 0],
                    'score_language_appropriateness' => [10, 5, 0]];
                foreach ($options as $field => $values) $rules[$field][] = Rule::in($values);
                $rules['score_professional_bonus'] = ['required', 'integer', 'between:0,10'];
            }
            if($request->input('source_type')==='v2' && $scope !== 'rating')$rules['result_state']=['present','nullable','integer',Rule::in([0,1,2])];
            elseif($request->has('result_state'))abort(422);
        }
        if($base['operation']==='input'){
            $kind=$request->validate(['input_kind'=>['required',Rule::in(['attendance','video'])]])['input_kind'];
            if ($scope === 'video') abort_unless($kind === 'video', 422);
            $rules=['input_kind'=>['required',Rule::in([$kind])]];
            if($kind==='attendance')$rules+=['game_id'=>['required','integer','min:1'],'attendance_status'=>['present','nullable','integer',Rule::in([1,2])]];
            else{
                $keys=$scope === 'video' ? array_keys($data->roster()) : array_map(fn($s)=>$s['type'].':'.$s['id'],$this->subjects->all());
                $rules+=['video_id'=>['nullable','integer','min:1'],'scheduled_at'=>['required','date_format:Y-m-d\\TH:i'],'guest_1'=>['required',Rule::in($keys)],'guest_2'=>['nullable',Rule::in($keys)],'guest_3'=>['nullable',Rule::in($keys)],'cancelled'=>['required','boolean']];
                if ($scope === 'video') {
                    $rules += [
                        'host_name' => ['nullable', 'string', 'max:100'], 'editor_name' => ['nullable', 'string', 'max:100'],
                        'week_no' => ['nullable', 'integer', 'between:1,5'], 'sport_label' => ['nullable', 'string', 'max:160'],
                        'video_revision' => ['required_with:video_id', 'nullable', 'integer', 'min:1'],
                    ];
                }
            }
        }
        $payload=$request->validate($rules);
        $args = [$subject, $month, $base['operation'], $payload, $base['request_id'], (int) $base['revision'],
            $base['source_sha256'], (int) Auth::guard('ppb')->id()];
        $receipt = $this->writer->save(...($scope ? [...$args, true] : $args));
        if ($scope) return response()->json($receipt, 200, ['Cache-Control' => 'private, no-store']);
        return redirect()->route('ppb.evaluations.show',[$type,$id,'month'=>$month])->with('status',$receipt['replayed']?'已核對相同請求，未重複寫入。':'評鑑已儲存。')->with('evaluation_receipt',$receipt);
    }
}
