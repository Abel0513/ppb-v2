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
        $analysts = PpbAnalyst::query()->with('account:id,name,email')
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
        return view('ppb.analysts-form', compact('analyst','accounts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $analyst = DB::transaction(function () use ($request, $data) {
            $analyst = PpbAnalyst::create($data);
            $this->audit($request, 'analyst.created#'.$analyst->id);
            return $analyst;
        });
        return redirect()->route('ppb.analysts.index')->with('status', '分析師資料已建立（ID '.$analyst->id.'）。');
    }

    public function update(Request $request, PpbAnalyst $analyst): RedirectResponse
    {
        $data = $this->validated($request, $analyst);
        DB::transaction(function () use ($request, $data, $analyst) {
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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'user_type'=>['required',Rule::in(['0','1','2','3',0,1,2,3])],
            'account_id' => ['nullable', 'integer', Rule::exists('ppb_accounts', 'id')],
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
        $data['account_id'] = $data['account_id'] ?? null;
        $data['display_name'] = $data['display_name'] ?? null;
        $data['internal_notes'] = $data['internal_notes'] ?? null;
        return $data;
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
