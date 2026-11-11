<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class PpbEnsureActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = Auth::guard('ppb')->user();
        if (!$account || !$account->is_active) {
            Auth::guard('ppb')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, '帳號尚未啟用或已停用。');
        }

        return $next($request);
    }
}
