<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class PpbCan
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $account = Auth::guard('ppb')->user();
        abort_unless($account && $account->hasPpbPermission($permission), 403);

        return $next($request);
    }
}
