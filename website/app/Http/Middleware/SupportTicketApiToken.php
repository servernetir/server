<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** احراز API سرور-به‌سرور ساخت تیکت؛ مستقل از نشست و CSRF. */
class SupportTicketApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = trim((string) config('services.support_ticket_api.token'));

        if ($expected === '') {
            return response()->json([
                'ok' => false,
                'code' => 'api_not_configured',
                'message' => 'API ساخت تیکت روی این نصب فعال نشده است.',
            ], 503);
        }

        $given = trim((string) $request->bearerToken());

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json([
                'ok' => false,
                'code' => 'unauthorized',
                'message' => 'توکن دسترسی معتبر نیست.',
            ], 401);
        }

        return $next($request);
    }
}
