<?php

namespace App\Http\Controllers\Ppb;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PpbRequireSecureHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function preview(): View
    {
        // Static sample only. Never query real accounts or other business data.
        return view('ppb.preview');
    }

    public function loginForm(Request $request): View|RedirectResponse
    {
        $canLogin = PpbRequireSecureHost::eligible($request);
        if ($canLogin && Auth::guard('ppb')->check()) {
            // PPB_UI3_20261104 | Go straight to the live schedule.
            return redirect()->route('ppb.games.index');
        }

        return view('ppb.login', compact('canLogin'));
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'regex:/\A[a-zA-Z0-9_.-]{3,64}\z/'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        if (!Auth::guard('ppb')->attempt([
            'username' => strtolower($credentials['username']),
            'password' => $credentials['password'],
            'is_active' => 1,
        ], $request->boolean('remember'))) {
            return back()->withErrors(['username' => '帳號或密碼不正確，或帳號尚未啟用。'])->onlyInput('username');
        }

        $request->session()->regenerate();
        $account = Auth::guard('ppb')->user();
        $account->forceFill(['last_login_at' => now()])->save();
        DB::table('ppb_auth_audits')->insert([
            'account_id' => $account->id,
            'event' => 'login',
            'ip' => substr((string) $request->ip(), 0, 45),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'created_at' => now(),
        ]);

        // PPB_UI3_20261104 | Preserve protected deep links, default to the live schedule.
        return redirect()->intended(route('ppb.games.index'));
    }

    public function dashboard(): View
    {
        $account = Auth::guard('ppb')->user();

        return view('ppb.dashboard', [
            'account' => $account,
            'roleNames' => $account->roles()->pluck('name')->all(),
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $id = Auth::guard('ppb')->id();
        if ($id) {
            DB::table('ppb_auth_audits')->insert([
                'account_id' => $id,
                'event' => 'logout',
                'ip' => substr((string) $request->ip(), 0, 45),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        }
        Auth::guard('ppb')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
