<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Prevent credentials on HTTP or third-party sslip.io preview hosts. */
class PpbRequireSecureHost
{
    public static function eligible(Request $request): bool
    {
        $ownedHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $request->isSecure()
            && is_string($ownedHost)
            && $ownedHost !== ''
            && strcasecmp($request->getHost(), $ownedHost) === 0;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!self::eligible($request)) {
            abort(403, '登入功能僅允許在自有 HTTPS 網域使用。');
        }

        return $next($request);
    }
}
