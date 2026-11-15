<?php
namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Models\PpbAccount;
use App\Models\PpbAnalyst;
use App\Models\PpbAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** PPB AN1 | 2026-11-04 | Preserve legacy analyst user_type=3 without granting schedule permissions. */
/** PPB A3 | 2026-11-03 | Analyst roster, separate from A2 login accounts. */
class AnalystController extends Controller
{
    public function index(Request $request): View
    {
        $keyword = trim((string) $request->query('q', ''));
        $analysts = PpbAnalyst::query()->select(['id','account_id','name','display_name','status','user_type','color','rating_level'])->with('account:id,username')
            ->when($keyword !== '', fn ($q) => $q->where(function ($s) use ($keyword) {
                $like = '%'.addcslashes($keyword, '%_\\').'%';
                $s->where('name', 'like', $like)->orWhere('display_name', 'like', $like);
            }))->orderByDesc('id')->paginate(20)->withQueryString();
        return view('ppb.analysts-index', compact('analysts','keyword'));
    }

    public function create(): View { return $this->form(null); }
    public function edit(PpbAnalyst $analyst): View { return $this->form($analyst); }

    private function form(?PpbAnalyst $analyst): View
    {
        $accounts = PpbAccount::query()->whereHas('roles', fn ($r) => $r->where('code', 'analyst'))
            ->where(function ($q) use ($analyst) {
                $q->whereDoesntHave('roles', fn ($r) => $r->where('code','owner'));
            })->where(function ($q) use ($analyst) {
                $q->whereDoesntHave('roles', fn ($r) => $r->where('code','manager'));
            })->whereNotIn('id', function ($q) use ($analyst) {
                $q->select('account_id')->from('ppb_analysts')->whereNotNull('account_id');
                if ($analyst) $q->where('id', '<>', $analyst->id);
            })->orderBy('name')->get(['id', 'name', 'email']);
        $canPrivate=Auth::guard('ppb')->user()->hasPpbPermission('accounts.manage');
        if($analyst&&!$canPrivate){$analyst=clone $analyst;$analyst->offsetUnset('line_id');$analyst->offsetUnset('line_name');$analyst->syncOriginal();}
        return view('ppb.analysts-form', compact('analyst','accounts','canPrivate'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $analyst = $this->write(function () use ($request, $data) {
            $analyst = PpbAnalyst::create($data);
            $this->audit($request, 'analyst.created#'.$analyst->id);
            return $analyst;
        });
        return redirect()->route('ppb.analysts.index')->with('status', '分析師資料已建立（ID '.$analyst->id.'）。');
    }

    public function update(Request $request, PpbAnalyst $analyst): RedirectResponse
    {
        $data = $this->validated($request, $analyst);
        $this->write(function () use ($request, $data, $analyst) {
            if ($data['status']==='inactive' && PpbAssignment::where('current_analyst_id',$analyst->id)
                ->where('status','active')->exists()) {
                abort(422,'這位分析師仍有進行中的排班；請先完成或重新指派後再停用。');
            }
            $analyst->update($data);
            $this->audit($request, 'analyst.updated#'.$analyst->id);
        });
        return redirect()->route('ppb.analysts.index')->with('status', '分析師資料已更新。');
    }

    private function validated(Request $request, ?PpbAnalyst $analyst): array
    {
        $canPrivate=Auth::guard('ppb')->user()->hasPpbPermission('accounts.manage');
        abort_if($request->hasAny(['line_id','line_name'])&&!$canPrivate,403);
        $strictInteger=static function($attribute,$value,$fail):void{
            if(!is_int($value)&&!(is_string($value)&&preg_match('/^[0-9]+$/D',$value)))$fail($attribute.' 必須是整數，不接受布林或小數。');
        };
        $data = $request->validate([
            'id'=>['missing'],'background'=>['missing'],'analyst_level'=>['missing'],
            'color'=>['sometimes','nullable','string','regex:/^#[0-9a-fA-F]{6}$/D'],
            'wp_photo'=>['sometimes','nullable',$strictInteger,'integer','min:1','max:'.PHP_INT_MAX],
            'author_id'=>['sometimes','nullable',$strictInteger,'integer','min:0','max:'.PHP_INT_MAX],
            'rating_level'=>['sometimes','nullable',$strictInteger,'integer',Rule::in([0,1,2,3])],
            'line_id'=>['sometimes','nullable','string','max:255','not_regex:/[\x00-\x1F\x7F]/'],
            'line_name'=>['sometimes','nullable','string','max:255','not_regex:/[\x00-\x1F\x7F]/'],
            'name' => ['required', 'string', 'max:100'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'user_type'=>['required',$strictInteger,Rule::in(['0','1','2','3',0,1,2,3])],
            'account_id' => ['nullable', $strictInteger, 'integer', Rule::exists('ppb_accounts', 'id')],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (!empty($data['account_id'])) {
            $account = PpbAccount::findOrFail($data['account_id']);
            abort_unless($account->roles()->where('code','analyst')->exists(), 422, '只能綁定分析師角色的帳號。');
            abort_if($account->roles()->whereIn('code',['owner','manager'])->exists(), 422, '管理員帳號不能綁定分析師主檔。');
            abort_if(PpbAnalyst::where('account_id', $account->id)
                ->when($analyst, fn ($q) => $q->whereKeyNot($analyst->id))->exists(), 422, '該帳號已綁定其他分析師。');
        }
        $data['user_type'] = (int)$data['user_type'];
        foreach(['account_id','display_name','internal_notes'] as $field){
            if(!$analyst||array_key_exists($field,$data))$data[$field]=$data[$field]??null;
        }
        return $data;
    }

    private function write(callable $save): mixed
    {
        try{return DB::transaction($save);}
        catch(\Illuminate\Database\QueryException $error){
            // QueryException includes SQL bindings. Never chain private LINE
            // input into ordinary exception reports, even after a rollback.
            throw new \RuntimeException('ANALYST_MASTER_WRITE_FAILED_DB_'.(int)($error->errorInfo[1]??0));
        }
    }

    private function audit(Request $request, string $event): void
    {
        DB::table('ppb_auth_audits')->insert([
            'account_id' => Auth::guard('ppb')->id(), 'event' => $event,
            'ip' => substr((string)$request->ip(),0,45),
            'user_agent' => substr((string)$request->userAgent(),0,255),
            'created_at' => now(),
        ]);
    }
}
