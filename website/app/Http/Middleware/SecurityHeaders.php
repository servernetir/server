<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * هدرهای امنیتی سطح سازمانی روی همه‌ی پاسخ‌های HTML.
 * CSP با دقت طوری تنظیم شده که هیچ‌چیز سایت نشکند:
 *  - فونت گوگل (googleapis + gstatic)
 *  - استایل و اسکریپت inline موجود در قالب‌ها
 *  - آی‌فریم نقشه‌ی OpenStreetMap (ابزار IP) و پیش‌نمایش srcdoc سایت‌ساز
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // فقط روی پاسخ‌های HTML (نه فایل‌های دانلود/JSON) هدرهای محتوایی را می‌گذاریم
        $isHtml = str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html');

        // همیشه‌فعال‌ها
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=(), usb=(), interest-cohort=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // HSTS فقط روی HTTPS (۲ سال + preload)
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains; preload');
        }

        if ($isHtml) {
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline'",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com data:",
                // blob: لازم است چون ابزارهای تصویرِ /webtools فایل کاربر را با
                // URL.createObjectURL() در canvas می‌خوانند. یک blob فقط به داده‌ای
                // اشاره می‌کند که خود همین صفحه ساخته و از جای دیگری قابل بارگذاری
                // نیست، پس اجازه‌دادنش سطح حمله را باز نمی‌کند.
                "img-src 'self' data: blob: https:",
                // ⚠️ صفحهٔ کنسولِ سرورِ ابری تنها جایی است که به یک WebSocketِ
                // بیرونی وصل می‌شود (خودِ ماشینِ مجازیِ مشتری). بی‌این استثنا،
                // مرورگر اتصال را **بی‌صدا** بلاک می‌کند و صفحه فقط «در حالِ
                // اتصال…» می‌مانَد — دقیقاً همان تلهٔ CSP که در این پروژه سابقه
                // دارد.
                //
                // عمداً `wss:` کلی است و نامِ میزبانِ زیرساخت در هدر نمی‌آید؛
                // وگرنه هدرِ پاسخِ همان صفحه، تأمین‌کننده را لو می‌داد. دامنه هم
                // فقط روی همین مسیر باز می‌شود، نه سراسرِ سایت.
                $this->isCloudConsole($request) ? "connect-src 'self' wss:" : "connect-src 'self'",
                "frame-src 'self' https://www.openstreetmap.org",
                "frame-ancestors 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                // زرین‌پال: بعد از submitِ فرمِ پرداخت، سرور به درگاه زرین‌پال
                // ۳۰۲ می‌دهد. مرورگرها form-action را روی ریدایرکتِ بعد از submit
                // هم اعمال می‌کنند، پس بدون این دامنه‌ها، هدایت به درگاه بی‌صدا
                // بلاک می‌شد و «هیچ اتفاقی نمی‌افتاد».
                "form-action 'self' https://*.zarinpal.com https://zarinpal.com",
                'upgrade-insecure-requests',
            ]);
            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }

    /**
     * آیا این درخواست صفحهٔ کنسولِ سرورِ ابری است؟
     *
     * دقیقاً یک مسیر، با الگوی بسته — نه پیشوندِ باز. اگر روزی مسیرِ تازه‌ای
     * اضافه شد، آگاهانه این‌جا هم اضافه شود؛ باز کردنِ `wss:` روی کلِ `/account`
     * یعنی هر صفحهٔ پنل بتواند به هر جایی سوکت بزند.
     */
    private function isCloudConsole(Request $request): bool
    {
        return $request->is('account/cloud/*/console/view')
            || $request->is('*/account/cloud/*/console/view');
    }
}
