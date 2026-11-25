<?php
declare(strict_types=1);
namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Services\Ppb\Evaluation\EvaluationPreviewReport;
use App\Services\Ppb\Evaluation\EvaluationSubjects;
use App\Services\Ppb\Evaluation\LegacyReader;
use App\Models\PpbAnalyst;
use Illuminate\Http\Request;
use App\Services\Ppb\LegacyDisplayLabels;
use Illuminate\Support\Facades\Auth;

/** GET-only preview for active owners; no write/POST routes or live migration. */
class EvaluationPreviewController extends Controller
{
    public function __construct(private EvaluationSubjects $subjects,private EvaluationPreviewReport $reports) {}

    private function owner():void
    {
        $u=Auth::guard('ppb')->user();
        abort_unless($u&&$u->is_active&&$u->roles()->where('ppb_roles.code','owner')->exists(),403);
    }

    private function month(Request $r):string
    {
        $v=$r->validate(['month'=>['nullable','date_format:Y-m','after_or_equal:2000-01','before_or_equal:2099-12']]);
        return $v['month']??now('Asia/Taipei')->format('Y-m');
    }

    private function protect($response)
    {
        return $response->header('Cache-Control','private, no-store')
            ->header('X-Robots-Tag','noindex, nofollow')
            ->header('X-Content-Type-Options','nosniff');
    }

    public function index(Request $r)
    {
        $this->owner();$month=$this->month($r);
        $page=(int)($r->validate(['page'=>['nullable','integer','min:1','max:1000']])['page']??1);
        $previewError=null;
        try {
            $subjects=array_values(array_filter($this->subjects->all(),fn($s)=>$s['type']==='legacy'));
        }catch(\Throwable $e){
            // Keep the view accessible for a logged-in owner. Never pretend a failed
            // legacy read is an empty roster or success, and do not expose SQL/PII.
            $subjects=[];
            $previewError='PREVIEW_DATA_UNAVAILABLE';
        }
        $total=count($subjects);$subjects=array_slice($subjects,($page-1)*5,5);
        return $this->protect(response()->view('ppb.evaluations.preview.index',compact('subjects','month','page','total','previewError')));
    }


    /**
     * Owner-only runtime diagnostic. Only returns bounded stage codes, never
     * DB/SQL exception text, names, IDs, credentials, paths, or query results.
     * Each operation is read-only; this route does not use the V2 evaluation tables.
     */
    public function health(LegacyReader $reader)
    {
        $this->owner();
        $checks=[
            ['name'=>'bridge', 'run'=>fn()=> $reader->query(['operation'=>'db_permissions'])],
            ['name'=>'legacy_roster','run'=>fn()=> $reader->profiles()],
            ['name'=>'v2_mappings','run'=>fn()=> $this->subjects->mappings()],
            ['name'=>'v2_analysts','run'=>fn()=> PpbAnalyst::query()->select('id')->limit(1)->get()],
        ];
        $verified=[];
        foreach ($checks as $step) {
            try {
                ($step['run'])();
                $verified[]=$step['name'];
            } catch (\Throwable $e) {
                $safe='PREVIEW_STAGE_UNAVAILABLE';
                // Only pre-approved non-sensitive reader error codes may pass.
                $message=$e->getMessage();
                if (preg_match('/\A(?:LEGACY_READER_[A-Z_]+|LEGACY_PROFILE_COVERAGE_LIMIT|READONLY_[A-Z_]+|DB_[A-Z_]+|SELECT_[A-Z_]+)\z/D', $message)) {
                    $safe=$message;
                }
                return $this->protect(response()->json([
                    'result'=>'STOP',
                    'stage'=>$step['name'],
                    'reason'=>$safe,
                    'checks_passed'=>$verified,
                    'writes'=>0,
                ]));
            }
        }
        return $this->protect(response()->json([
            'result'=>'PASS',
            'checks_passed'=>$verified,
            'writes'=>0,
        ]));
    }

    public function show(Request $r,int $id)
    {
        $this->owner();$month=$this->month($r);
        $format=$r->query('format');abort_unless($format===null||$format==='summary',404);
        try{
            $subject=$this->subjects->get('legacy',$id);
            $report=$this->reports->load($subject,$month);
        }catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){throw $e;}
        catch(\Throwable $e){abort(503,'唯讀資料暫時不可用');}
        if($format==='summary'){
            $now=$report['current'];
            return $this->protect(response()->json([
                'score'=>$now['rating']['total_score'],
                'previous_score'=>$report['previous']['rating']['total_score'],
                'level'=>$report['level'],'next_level'=>$now['next_rating']['rating_level'],
                'level_label'=>LegacyDisplayLabels::rating($report['level'] ?? null),
                'next_level_label'=>LegacyDisplayLabels::rating($now['next_rating']['rating_level']),
                'salary'=>$now['salary'],'performance'=>$now['performance']
            ]));
        }
        return $this->protect(response()->view('ppb.evaluations.preview.show',compact('report')));
    }
}
