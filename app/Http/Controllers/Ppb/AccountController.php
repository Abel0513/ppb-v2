<?php

namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Models\PpbAccount;
use App\Models\PpbRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** A2: ownership-gated account administration on the isolated PPB database. */
class AccountController extends Controller
{
    private function ownerOnly(): void
    {
        abort_unless(Auth::guard('ppb')->user()?->roles()->where('code', 'owner')->exists(), 403);
    }

    private function audit(Request $request, string $event): void
    {
        DB::table('ppb_auth_audits')->insert([
            'account_id' => Auth::guard('ppb')->id(),
            'event' => $event,
            'ip' => substr((string) $request->ip(), 0, 45),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }

    public function index(Request $request): View
    {
        $this->ownerOnly();
        $keyword = trim((string) $request->query('q', ''));
        $accounts = PpbAccount::query()->with('roles')
            ->when($keyword !== '', fn ($q) => $q->where(function ($s) use ($keyword) {
                $s->where('name', 'like', '%'.addcslashes($keyword, '%_\\').'%')
                    ->orWhere('username', 'like', '%'.addcslashes($keyword, '%_\\').'%');
            }))->orderBy('id')->paginate(20)->withQueryString();
        return view('ppb.accounts-index', compact('accounts', 'keyword'));
    }

    public function create(): View
    {
        $this->ownerOnly();
        $roles = PpbRole::whereIn('code', ['manager', 'analyst'])->orderBy('id')->get();
        return view('ppb.accounts-form', ['account' => null, 'roles' => $roles, 'selectedRoles' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ownerOnly();
        $request->merge(['username' => strtolower(trim((string) $request->input('username', '')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_.-]{3,64}\z/', 'unique:ppb_accounts,username'],
            'password' => ['required', 'string', 'min:10', 'max:128', 'confirmed'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'integer', 'distinct', 'exists:ppb_roles,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $roles = PpbRole::whereIn('id', $data['roles'])->get();
        abort_unless($roles->count() === count($data['roles']) && $roles->every(fn ($r) => in_array($r->code, ['manager', 'analyst'], true)), 422);

        DB::transaction(function () use ($request, $data, $roles) {
            $account = PpbAccount::create([
                'name' => $data['name'],
                'username' => strtolower($data['username']),
                'password' => Hash::make($data['password']),
                'is_active' => $request->boolean('is_active'),
            ]);
            $account->roles()->sync($roles->pluck('id')->all());
            $this->audit($request, 'account.created#'.$account->id);
        });
        return redirect()->route('ppb.accounts.index')->with('status', '新帳號已建立。');
    }

    public function edit(PpbAccount $account): View
    {
        $this->ownerOnly();
        abort_if($account->roles()->where('code', 'owner')->exists(), 403, '系統管理員帳號不透過此頁修改。');
        $roles = PpbRole::whereIn('code', ['manager', 'analyst'])->orderBy('id')->get();
        return view('ppb.accounts-form', [
            'account' => $account,
            'roles' => $roles,
            'selectedRoles' => $account->roles()->pluck('ppb_roles.id')->all(),
        ]);
    }

    public function update(Request $request, PpbAccount $account): RedirectResponse
    {
        $this->ownerOnly();
        abort_if($account->roles()->where('code', 'owner')->exists(), 403, '系統管理員帳號不透過此頁修改。');
        $request->merge(['username' => strtolower(trim((string) $request->input('username', '')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_.-]{3,64}\z/', Rule::unique('ppb_accounts', 'username')->ignore($account->id)],
            'password' => ['nullable', 'string', 'min:10', 'max:128', 'confirmed'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'integer', 'distinct', 'exists:ppb_roles,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $roles = PpbRole::whereIn('id', $data['roles'])->get();
        abort_unless($roles->count() === count($data['roles']) && $roles->every(fn ($r) => in_array($r->code, ['manager', 'analyst'], true)), 422);

        DB::transaction(function () use ($request, $data, $roles, $account) {
            $fields = [
                'name' => $data['name'],
                'username' => strtolower($data['username']),
                'is_active' => $request->boolean('is_active'),
            ];
            if (!empty($data['password'])) {
                $fields['password'] = Hash::make($data['password']);
            }
            $account->fill($fields)->save();
            $account->roles()->sync($roles->pluck('id')->all());
            $this->audit($request, (!empty($data['password']) ? 'account.password_changed#' : 'account.updated#').$account->id);
        });
        return redirect()->route('ppb.accounts.index')->with('status', '帳號設定已更新。');
    }

    public function preview(): View
    {
        // Static presentation; no live database lookups and no forms.
        return view('ppb.accounts-preview');
    }
}
