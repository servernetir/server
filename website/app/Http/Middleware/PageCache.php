<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * کشِ کاملِ صفحه برای مهمان — قلمِ «سه دور دست‌نخورده»ی هر سه ممیزی.
 *
 * ═══ قرارداد (همان نسخهٔ حداقلیِ CTO در ممیزی ۳، در لایهٔ اپ) ═══
 *
 *   · فقط GET، فقط روت‌های فهرستِ config/pagecache.php، فقط بی‌رشتهٔ کوئری،
 *     فقط بازدیدکنندهٔ بدونِ احراز هویت، فقط پاسخِ 200ِ HTML.
 *   · هر پاسخِ این روت‌ها هدرِ `X-Cache: HIT|MISS|BYPASS` می‌گیرد — «always»،
 *     تا قابلِ راستی‌آزمایی با یک curl باشد؛ سه دور «ندارد» دقیقاً یعنی
 *     هیچ‌کس نمی‌توانست بسنجد.
 *   · TTL کوتاه (پیش‌فرض ۶۰s) — صفحهٔ حداکثر یک‌دقیقه کهنه، در ازای حذفِ
 *     کاملِ رندر برای پربازدیدترین صفحات.
 *
 * ═══ چرا داخلِ گروهِ web است، نه global ═══
 *
 * لایوتِ سایت روی هر صفحه `<meta name="csrf-token">` دارد و فرم‌ها `_token`.
 * اگر HTML بینِ بازدیدکننده‌ها عیناً بازپخش شود، توکنِ نشستِ بازدیدکنندهٔ
 * اول به بقیه می‌رسد و اولین POSTشان (نظر، چت، فرم استخدام) ۴۱۹ می‌گیرد —
 * خرابیِ بی‌صدایی که فقط در کنسولِ کاربر دیده می‌شود. پس این میدل‌ور بعد از
 * StartSession می‌نشیند و در لحظهٔ HIT، توکنِ ذخیره‌شده را با توکنِ نشستِ
 * همین بازدیدکننده تعویض می‌کند. هزینهٔ نشست ناچیز است؛ آنچه حذف می‌شود
 * رندرِ Blade و پرس‌وجوهای دیتابیس است.
 *
 * ⚠️ ذخیره فقط محتواست، نه هدرها: پاسخِ کش‌شده نباید Set-Cookieِ نفرِ قبلی
 * را حمل کند؛ کوکی‌های نشستِ جاری را میدل‌ورهای بیرونی خودشان می‌گذارند.
 */
class PageCache
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->eligible($request)) {
            return $this->tag($next($request), 'BYPASS');
        }

        $key = 'page:'.sha1($request->getHost().'|'.$request->path());
        $store = Cache::store();

        $hit = $store->get($key);

        if (is_array($hit) && isset($hit['html'], $hit['token'])) {
            $html = $hit['html'];

            /*
            | تعویضِ توکن: هر جای HTML که توکنِ لحظهٔ ذخیره نشسته (متا و
            | input های _token)، توکنِ نشستِ جاری می‌نشیند. جایگزینیِ رشته‌ایِ
            | ساده کافی و قطعی است چون توکن ۴۰نویسهٔ تصادفی است و جز خودش
            | جایی ظاهر نمی‌شود.
            */
            if ($hit['token'] !== '' && $request->hasSession()) {
                $html = str_replace($hit['token'], $request->session()->token(), $html);
            }

            return $this->tag(
                response($html, 200, ['Content-Type' => $hit['type'] ?? 'text/html; charset=UTF-8']),
                'HIT'
            );
        }

        $response = $next($request);

        if ($this->storable($response)) {
            $store->put($key, [
                'html'  => $response->getContent(),
                'token' => $request->hasSession() ? (string) $request->session()->token() : '',
                'type'  => (string) $response->headers->get('Content-Type', 'text/html; charset=UTF-8'),
            ], (int) config('pagecache.ttl', 60));
        }

        return $this->tag($response, 'MISS');
    }

    private function eligible(Request $request): bool
    {
        if (! config('pagecache.enabled', true) || ! $request->isMethod('GET')) {
            return false;
        }

        // رشتهٔ کوئری یعنی نسخهٔ شخصی‌شده (?cat=، ?page=، UTM…) — کش نمی‌شود.
        if ($request->getQueryString() !== null) {
            return false;
        }

        /*
        | نامِ روت بدونِ پیشوندِ زبان سنجیده می‌شود تا فهرست یک‌بار نوشته شود
        | و هر سه زبان را بگیرد — کلیدِ کش خودش per-URL است و قاطی نمی‌شوند.
        */
        $name = (string) ($request->route()?->getName() ?? '');
        $base = preg_replace('/^(en|tr)\./', '', $name);

        if (! in_array($base, (array) config('pagecache.routes', []), true)) {
            return false;
        }

        /*
        | 🔴 کوکیِ نشست = BYPASS — همان قاعدهٔ نسخهٔ nginxِ ممیزی
        | (`map $http_cookie … session … ⇒ bypass`) و دلیلش این‌جا مشخص‌تر است:
        | صفحه‌ای که برای کاربرِ نشست‌دار رندر می‌شود می‌تواند محتوای همان نشست
        | را حمل کند (پیامِ flashِ «نظرت ثبت شد»، سبدِ در جریان…). ذخیره‌اش یعنی
        | پیامِ خصوصیِ یک نفر برای بقیه؛ سروِ کش به او یعنی بلعیدنِ flash.
        | بازدیدکنندهٔ تازه و خزنده — یعنی همان ترافیکی که کش برایش ساخته
        | شده — کوکی ندارند و کامل پوشش داده می‌شوند.
        */
        if ($request->cookies->has((string) config('session.cookie'))) {
            return false;
        }

        // و احرازِ هویت با هر مکانیزمِ دیگری (کوکیِ remember، نامِ متفاوت…)
        if (Auth::guard('web')->check() || Auth::guard('customer')->check()) {
            return false;
        }

        return true;
    }

    private function storable(Response $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $type = (string) $response->headers->get('Content-Type', '');

        // فقط HTML؛ sitemap/llms/feedها سبک‌اند و هدرهای خودشان را دارند.
        return str_contains($type, 'text/html');
    }

    private function tag(Response $response, string $state): Response
    {
        $response->headers->set('X-Cache', $state);

        return $response;
    }
}
