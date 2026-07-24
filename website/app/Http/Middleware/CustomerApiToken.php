<?php

namespace App\Http\Middleware;

use App\Models\CustomerApiToken as TokenModel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * احراز هویتِ APIِ مشتری با توکنِ Bearer.
 *
 * بی‌نشست و بی‌CSRF — تماس‌گیرنده یک برنامه است نه مرورگر. توکن با هش تطبیق
 * داده می‌شود؛ دامنهٔ دسترسی (ability) بررسی می‌شود تا وقتی «نوشتن» اضافه شد،
 * توکنِ فقط‌خواندنی نتواند سرویس بسازد.
 *
 * استفاده در روت: ->middleware(CustomerApiToken::class.':read')
 */
class CustomerApiToken
{
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return response()->json(['ok' => false, 'error' => 'missing_token'], 401);
        }

        $token = TokenModel::findByPlain($bearer);
        if (! $token) {
            return response()->json(['ok' => false, 'error' => 'invalid_token'], 401);
        }

        if (! $token->can($ability)) {
            return response()->json(['ok' => false, 'error' => 'insufficient_scope'], 403);
        }

        $customer = $token->customer;
        if (! $customer || ! $customer->isActive()) {
            return response()->json(['ok' => false, 'error' => 'account_inactive'], 403);
        }

        // آخرین استفاده — best-effort، نباید درخواست را بشکند
        try {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->save();
        } catch (\Throwable) {
        }

        $request->setUserResolver(fn () => $customer);
        $request->attributes->set('api_customer', $customer);

        return $next($request);
    }
}
