<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * کشِ کاملِ صفحه برای مهمان — قلمِ «سه دور دست‌نخورده»ی ممیزی که در دورِ ۳
 * ساخته شد و دورِ ۴ راستی‌آزمایی‌اش کرد (HIT در ۱۶۰–۱۹۵ms).
 *
 * ═══ قرارداد ═══
 *
 *   · فقط GET، فقط روت‌های config/pagecache.php، بی‌رشتهٔ کوئری، بی‌کوکیِ
 *     نشست، بی‌احراز هویت، فقط پاسخِ 200ِ HTML.
 *   · هدرِ `X-Cache: HIT|MISS|BYPASS|STALE` — همیشه، تا با یک curl سنجیدنی باشد.
 *   · توکنِ CSRF در لحظهٔ HIT با توکنِ نشستِ جاری تعویض می‌شود (دلیل کامل
 *     پایین، بالای handle).
 *
 * ═══ آنچه ممیزی ۴ اضافه کرد ═══
 *
 * ۱) **ابطال (purge)** — CTO: «کش بدونِ ابطال بدهی است، نه دارایی: بعد از
 *    تغییرِ قیمت، HIT تا پایانِ TTL قیمتِ قدیمی را نشان می‌دهد.» راه‌حل،
 *    شمارهٔ نسل است: کلیدِ هر صفحه شاملِ `gen` است و `PageCache::purge()`
 *    فقط nesl را جلو می‌برد — O(1)، بدونِ نیاز به فهرستِ کلیدها، روی هر
 *    storeی. مدل‌های قیمت/محتوا در AppServiceProvider با saved/deleted
 *    purge می‌زنند؛ کهنگیِ قیمت حالا «تا اولین ذخیره» است نه «تا پایان TTL».
 *
 * ۲) **stale-while-error** — همتای `proxy_cache_use_stale error` در نسخهٔ
 *    nginxِ پیشنهادیِ CTO: نسخهٔ منقضی تا `hard_ttl` نگه داشته می‌شود و اگر
 *    رندرِ تازه ۵xx شد، همان نسخهٔ سالمِ قبلی با `X-Cache: STALE` سرو می‌شود.
 *    مهمانِ ناشناس به‌جای صفحهٔ خطا، صفحهٔ چند‌دقیقه‌پیش را می‌بیند؛ خطای
 *    واقعی همچنان در ردیاب ثبت می‌شود چون رندر واقعاً اجرا شده.
 *    ⚠️ فقط ۵xx — ۴۰۴ عمداً stale نمی‌گیرد: پستِ حذف‌شده باید ۴۰۴ بماند.
 *
 * ═══ برای QA (ریسکِ «کش رگرسیون را پنهان می‌کند») ═══
 *
 * هر تستِ دستی/خودکارِ سایتِ زنده باید یا رشتهٔ کوئری داشته باشد (?qa=1 ⇒
 * BYPASS) یا کوکی بفرستد. تستِ بی‌کوکیِ بی‌کوئری ممکن است نسخهٔ سالمِ کهنه
 * را ببیند و بیلدِ خراب را پاس کند. (CLAUDE.md §۱۳)
 */
class PageCache
{
    /** کلیدِ شمارهٔ نسل — bump یعنی ابطالِ آنیِ همهٔ صفحه‌های کش‌شده. */
    private const GEN_KEY = 'page:gen';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->eligible($request)) {
            return $this->tag($next($request), 'BYPASS');
        }

        $store = Cache::store();

        $gen = (int) $store->get(self::GEN_KEY, 0);
        $key = 'page:g'.$gen.':'.sha1($request->getHost().'|'.$request->path());

        $hit = $store->get($key);
        $hasCopy = is_array($hit) && isset($hit['html'], $hit['token']);

        if ($hasCopy && (int) ($hit['fresh_until'] ?? 0) >= time()) {
            return $this->tag($this->serve($hit, $request), 'HIT');
        }

        // MISS یا کهنه — رندرِ تازه؛ اگر ۵xx شد و نسخهٔ سالمِ قبلی داریم، همان.
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            if ($hasCopy) {
                report($e);

                return $this->tag($this->serve($hit, $request), 'STALE');
            }

            throw $e;
        }

        if ($response->getStatusCode() >= 500 && $hasCopy) {
            return $this->tag($this->serve($hit, $request), 'STALE');
        }

        if ($this->storable($response)) {
            $store->put($key, [
                'html'        => $response->getContent(),
                'token'       => $request->hasSession() ? (string) $request->session()->token() : '',
                'type'        => (string) $response->headers->get('Content-Type', 'text/html; charset=UTF-8'),
                'fresh_until' => time() + (int) config('pagecache.ttl', 60),
            ], (int) config('pagecache.hard_ttl', 86400));
        }

        return $this->tag($response, 'MISS');
    }

    /**
     * ابطالِ کلِ کشِ صفحه — O(1) با جلوبردنِ نسل.
     *
     * ⚠️ پیش‌فرضِ get صفر است و increment روی کلیدِ نبود آن را ۱ می‌کند؛
     * اگر پیش‌فرض ۱ بود، اولین purge به همان نسلِ پیش‌فرض می‌رسید و هیچ
     * چیزی باطل نمی‌شد — ابطالی که فقط از بارِ دوم کار کند، در دقیقاً اولین
     * تغییرِ قیمتِ بعد از دیپلوی ساکت شکست می‌خورَد.
     */
    public static function purge(): void
    {
        try {
            Cache::store()->increment(self::GEN_KEY);
        } catch (\Throwable) {
            // ابطال هرگز نباید ذخیرهٔ مدل را بشکند؛ بدترین حالت TTL کوتاه است
        }
    }

    /** بازسازیِ پاسخ از نسخهٔ ذخیره‌شده + تعویضِ توکنِ CSRF با نشستِ جاری. */
    private function serve(array $hit, Request $request): Response
    {
        $html = $hit['html'];

        /*
        | تعویضِ توکن: هر جای HTML که توکنِ لحظهٔ ذخیره نشسته (متا و
        | input های _token)، توکنِ نشستِ جاری می‌نشیند. بدونِ این، اولین
        | POSTِ هر بازدیدکنندهٔ تازه (نظر، چت، فرم) ۴۱۹ می‌گرفت — بی‌لاگ،
        | فقط در کنسولِ مرورگرِ کاربر. جایگزینیِ رشته‌ایِ ساده قطعی است چون
        | توکن ۴۰نویسهٔ تصادفی است و جز خودش جایی ظاهر نمی‌شود.
        */
        if ($hit['token'] !== '' && $request->hasSession()) {
            $html = str_replace($hit['token'], $request->session()->token(), $html);
        }

        return response($html, 200, ['Content-Type' => $hit['type'] ?? 'text/html; charset=UTF-8']);
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
        |
        | ⚠️ ممیزی ۴ (زیرساخت) درست دید که «کوکیِ ساختگی = دورزدنِ کش و رسیدن
        | به origin». پذیرفته و عمدی است — همان رفتارِ نسخهٔ nginx — چون بدیلش
        | (اعتبارسنجیِ نشست پیش از BYPASS) یعنی نشست‌خوانی برای هر درخواست و
        | خطرِ سروِ flashِ دیگران. سپرِ حجمی، Cloudflare جلوی ماست.
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
