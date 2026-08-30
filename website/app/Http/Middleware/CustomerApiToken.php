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
 * داده می‌شود؛ دامنهٔ دسترسی (ability) بررسی می‌شود تا توکنِ فقط‌خواندنی
 * نتواند دامنه بخرد.
 *
 * ═══ 🔴 چرا محدودیتِ IP این‌جاست و نه در `EnforceCustomerIp` ═══
 *
 * آن میدل‌ور مشتری را از `Auth::guard('customer')` می‌گیرد، ولی این‌جا هرگز
 * واردِ guard نمی‌شویم — فقط `setUserResolver()` می‌زنیم. یعنی افزودنِ
 * `EnforceCustomerIp` به گروهِ `api/v1` یک **no-opِ کاملاً بی‌صدا** بود:
 * صفحهٔ امنیت می‌گفت «قواعدِ IP فعال است»، اپراتور خیال می‌کرد توکنش محدود
 * شده، و هیچ چیزی محدود نشده بود. همان الگویی که این پروژه ثبتش کرده —
 * محافظی که با عوض‌شدنِ لایهٔ انتقال بی‌صدا می‌میرد.
 *
 * استفاده در روت: `->middleware(CustomerApiToken::class.':domains:write')`
 */
class CustomerApiToken
{
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $bearer = $request->bearerToken();

        if (! $bearer) {
            return $this->deny('missing_token', 'هدرِ Authorization: Bearer ارسال نشده است.', 401);
        }

        $token = TokenModel::findByPlain($bearer);

        if (! $token) {
            return $this->deny('invalid_token', 'توکن شناخته نشد.', 401);
        }

        /*
        | 🔴 علتِ دقیق برگردانده می‌شود، نه یک «invalid_token»ِ همه‌کاره.
        |
        | تماس‌گیرنده یک برنامه است: «توکن منقضی شده» یعنی نماینده می‌رود و
        | توکنِ تازه می‌سازد؛ «invalid_token» یعنی ساعت‌ها دنبالِ یک اشتباهِ
        | تایپی می‌گردد که وجود ندارد. تفکیک هیچ اطلاعاتی به مهاجم نمی‌دهد —
        | او از قبل توکنِ درست را در دست دارد وگرنه به این شاخه نمی‌رسید.
        */
        $reason = $token->unusableReason();

        if ($reason !== null) {
            return $this->deny($reason, match ($reason) {
                'token_expired' => 'این توکن منقضی شده است. از پنل توکنِ تازه بسازید.',
                'token_revoked' => 'این توکن باطل شده است.',
                default         => 'توکن قابلِ استفاده نیست.',
            }, 401);
        }

        if (! $token->allowsIp($request->ip())) {
            return $this->deny('ip_not_allowed',
                'این توکن فقط از IPهای مجازِ خودش قابلِ استفاده است.', 403);
        }

        if (! $token->can($ability)) {
            return $this->deny('insufficient_scope',
                'این توکن دسترسیِ «'.$ability.'» ندارد.', 403);
        }

        $customer = $token->customer;

        if (! $customer || ! $customer->isActive()) {
            return $this->deny('account_inactive', 'حسابِ کاربری فعال نیست.', 403);
        }

        // آخرین استفاده — best-effort، نباید درخواست را بشکند
        try {
            $token->forceFill([
                'last_used_at' => now(),
                'last_used_ip' => $request->ip(),
                // ⚠️ افزایشِ خام و نه `$token->use_count + 1`: دو تماسِ همزمان
                //    با خواندن-سپس-نوشتن یکی از دو شمارش را گم می‌کنند.
            ])->save();

            $token->newQuery()->whereKey($token->id)->increment('use_count');
        } catch (\Throwable) {
        }

        $request->setUserResolver(fn () => $customer);
        $request->attributes->set('api_customer', $customer);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    private function deny(string $code, string $message, int $status): Response
    {
        return response()->json([
            'ok'      => false,
            'error'   => $code,
            'message' => $message,
        ], $status);
    }
}
