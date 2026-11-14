<?php
declare(strict_types=1);
namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Services\Ppb\Evaluation\EvaluationPreviewReport;
use App\Services\Ppb\Evaluation\EvaluationSubjects;
use Illuminate\Http\Request;
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
        return $v['month']??now('Asia/Taipei')->subMonthNoOverflow()->format('Y-m');
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
        try {
            $subjects=array_values(array_filter($this->subjects->all(),fn($s)=>$s['type']==='legacy'));
        }catch(\Throwable $e){abort(503,'唯讀資料暫時不可用');}
        $total=count($subjects);$subjects=array_slice($subjects,($page-1)*5,5);
        return $this->protect(response()->view('ppb.evaluations.preview.index',compact('subjects','month','page','total')));
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
                'salary'=>$now['salary'],'performance'=>$now['performance']
            ]));
        }
        return $this->protect(response()->view('ppb.evaluations.preview.show',compact('report')));
    }
}
