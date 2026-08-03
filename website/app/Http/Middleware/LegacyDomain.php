<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * انتقالِ سئوی `servernet.ir` به `servernet.cloud` با ۳۰۱.
 *
 * دامنه بازنشسته نمی‌شود؛ فقط صفحاتش منتقل می‌شوند تا اعتبارِ لینک‌هایشان هم
 * منتقل شود. قواعد در `config/legacy.php` است تا نقشهٔ مسیرها بدونِ دپلوی
 * به‌روز شود.
 *
 * ═══ محافظ‌ها — هرکدام یک خرابیِ واقعی را می‌بندد ═══
 *
 * ۱. **فقط apex و www.** زیردامنه‌های .ir زنده‌اند — `my.servernet.ir` هنوز
 *    WHMCSِ فارسی است. تطبیق با فهرستِ صریح، نه `str_ends_with`.
 *
 * ۲. **نامِ میزبان نرمال می‌شود.** `servernet.ir.` (با نقطهٔ پایانی) یک میزبانِ
 *    کاملاً معتبر است و `getHost()` نقطه را نگه می‌دارد. بی‌نرمال‌سازی، آن شکل
 *    از **هر دو** محافظ رد می‌شد: هم ریدایرکت نمی‌گرفت (کلِ سایت روی .ir سرو
 *    می‌شد) و هم `SecurityHeaders` هدرِ HSTS با `includeSubDomains` می‌فرستاد —
 *    یعنی پین‌شدنِ دوسالهٔ `my.servernet.ir` که **از سمتِ ما برگشت‌پذیر نیست**.
 *
 * ۳. **فقط GET/HEAD.** ۳۰۱ روی POST متد را عوض می‌کند و بدنه را دور می‌ریزد.
 *
 * ۴. **مسیرهای ماشین‌به‌ماشین ریدایرکت نمی‌شوند.** `api/*`، `system/*`،
 *    `bale/webhook/*`، `payment/*` و رلهٔ پیامک. یک ۳۰۱ِ بین‌دامنه‌ای باعث
 *    می‌شود curl و بیشترِ کلاینت‌های HTTP هدرِ `Authorization` را **دور
 *    بریزند**، پس تماسِ توکن‌دار بی‌صدا ۴۰۱ می‌گیرد. وب‌هوکِ درگاه هم بدنه‌اش
 *    را از دست می‌دهد.
 */
class LegacyDomain
{
    /**
     * مسیرهایی که هرگز ریدایرکت نمی‌شوند — فراتر از فهرستِ config.
     *
     * این‌ها ماشین‌به‌ماشین‌اند و همان چیزی هستند که `ConsoleHost::CONSOLE_EXTRA`
     * روی کنسول مستثنا می‌کند. اگر این‌جا تکرار نشوند، چون این میدل‌ور **زودتر**
     * اجرا می‌شود، آن استثناها را بی‌اثر می‌کند.
     */
    private const NEVER_PATTERNS = [
        'api/*', 'system/*', 'bale/webhook/*', 'payment/*', 'up',
        'assets/*', 'favicon.ico', 'favicon.svg', 'robots.txt',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isLegacyHost($request)) {
            return $next($request);
        }

        // OPTIONS را هم کنار می‌گذاریم: preflightِ CORS نباید ۳۰۱ بگیرد
        if (! $request->isMethodSafe() || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        return redirect()->away($this->target($request), 301);
    }

    /**
     * آیا این میزبان، دامنهٔ قدیمی است؟
     *
     * نقطهٔ پایانی و بزرگی/کوچکیِ حروف نرمال می‌شوند — هر دو شکلِ معتبرِ همان
     * میزبان‌اند و بی‌این، مهاجم یا حتی یک خزندهٔ ساده از محافظ رد می‌شد.
     */
    public static function isLegacyHost(Request $request): bool
    {
        $host = rtrim(strtolower($request->getHost()), '.');

        return in_array($host, array_map(
            fn ($h) => rtrim(strtolower((string) $h), '.'),
            (array) config('legacy.hosts', [])
        ), true);
    }

    /** مسیرهایی که باید همان‌جا سرو شوند، نه ریدایرکت */
    private function isExempt(Request $request): bool
    {
        if ($request->is(self::NEVER_PATTERNS)) {
            return true;
        }

        // ⚠️ مسیر نرمال می‌شود پیش از تطبیق: `getPathInfo()` خام است، پس
        // `/SMS-relay.php`، `/./sms-relay.php` و `%2E` بدونِ این از فهرست رد
        // می‌شدند — و رلهٔ پیامک تنها راهِ فرستادنِ کدِ ورود از سرورِ آلمان است.
        $path = $this->normalizedPath($request);

        foreach ((array) config('legacy.never', []) as $skip) {
            $skip = strtolower((string) $skip);

            if ($skip !== '' && str_starts_with($path, $skip)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedPath(Request $request): string
    {
        $path = rawurldecode($request->getPathInfo());
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('~/\.(?=/|$)~', '/', $path) ?? $path;   // /./  →  /
        $path = preg_replace('~/{2,}~', '/', $path) ?? $path;        // //   →  /

        return strtolower('/'.ltrim($path, '/'));
    }

    /** آدرسِ مقصد روی دامنهٔ تازه */
    private function target(Request $request): string
    {
        $base = rtrim((string) config('legacy.target', 'https://servernet.cloud'), '/');

        // ⚠️ `getQueryString()` پارامترها را الفبایی مرتب می‌کند. برای ۳۰۱ باید
        // عیناً همان چیزی برود که آمده، وگرنه لینکِ کمپین با اصلش یکی نیست.
        $query = (string) $request->server->get('QUERY_STRING', '');
        $tail = $query === '' ? '' : '?'.$query;

        // برای نگاشت، مسیرِ **خام** ملاک است (نه نرمال‌شده) تا اسلاگِ فارسی و
        // حروفِ بزرگ دست‌نخورده به مقصد برسند.
        $path = '/'.ltrim($request->getPathInfo(), '/');

        $exact = (array) config('legacy.exact', []);

        if (isset($exact[$path])) {
            return $base.$exact[$path].$tail;
        }

        foreach ((array) config('legacy.prefix', []) as $from => $to) {
            if (str_starts_with($path, $from)) {
                $rest = ltrim(substr($path, strlen($from)), '/');

                // 🔴 دُم فقط وقتی چسبانده می‌شود که مقصد با «/» تمام شود.
                // بی‌این شرط، نگاشتی مثلِ `'/category/' => '/blog'` که عمداً
                // می‌خواست همه را روی صفحهٔ فهرست جمع کند، `/blog/{term}`
                // می‌ساخت — و چون مسیرِ دسته‌بندی روی سایتِ تازه اصلاً وجود
                // ندارد، هر آرشیوِ قدیمی مستقیم به ۴۰۴ می‌رفت.
                return str_ends_with($to, '/')
                    ? $base.rtrim($to, '/').'/'.$rest.$tail
                    : $base.$to.$tail;
            }
        }

        if ($path === '/') {
            return $base.'/'.$tail;
        }

        return config('legacy.unknown') === 'home'
            ? $base.'/'
            : $base.$path.$tail;
    }
}
