<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * آدرسِ مرده → نزدیک‌ترین صفحهٔ زنده (۳۰۱)، یا «برای همیشه رفته» (۴۱۰).
 *
 * ═══ چرا این‌جا و نه یک روتِ catch-all ═══
 *
 * فقط از داخلِ `TrackNotFound` و فقط وقتی پاسخ **از قبل ۴۰۴** است صدا زده
 * می‌شود. روتِ catch-all باید با `/{category}/{slug}`ِ کاتالوگ و ریدایرکتِ
 * پیشوندِ زبانِ بزرگ رقابت می‌کرد و یک الگوی گشاد می‌توانست صفحهٔ زنده را
 * بپوشاند. این‌جا چنین چیزی ممکن نیست: صفحه‌ای که وجود دارد هرگز به این کلاس
 * نمی‌رسد.
 *
 * ═══ ترتیب ═══
 *
 *   ۱ `/null` — باگِ ویجتِ چت که `href = null` را «null»ِ نسبی می‌کرد
 *   ۲ `gone`  — فایلِ سیستمی و داراییِ قالبِ وردپرس ⇒ ۴۱۰
 *   ۳ پسوندِ وردپرسی (`/feed`، `/page/2`، `/amp`، …) کنده می‌شود
 *
 *     ⚠️ `/comment` عمداً در فهرست نیست: فرمِ نظرِ همین سایت به
 *     `/blog/{slug}/comment` POST می‌کند، پس GETِ ۴۰۴ روی آن یک لینکِ
 *     خرابِ واقعیِ خودمان است و باید در ردیاب بماند (TrackNotFoundTest).
 *   ۴ نقشهٔ دقیق (`exact`)
 *   ۵ هرزنامهٔ تزریق‌شده روی وردپرسِ قدیمی ⇒ ۴۱۰
 *   ۶ الگوهای ساختاری (دسته، برچسب، آرشیوِ تاریخ، /seo، نقشهٔ سایت، …)
 *   ۷ قاعده‌های موضوعی — **فقط** برای مسیرِ دارای حرفِ فارسی
 *
 * 🔴 نامکِ لاتینِ ناشناخته عمداً ۴۰۴ می‌مانَد. سایتِ امروز فقط نامکِ لاتین
 *    دارد، پس یک نامکِ لاتینِ ۴۰۴ ممکن است **لینکِ خرابِ خودمان** باشد؛
 *    ریدایرکتِ آن به یک صفحهٔ عمومی، خرابی را از ردیابِ ۴۰۴ پنهان می‌کرد.
 *    نامکِ فارسی اما فقط از وردپرسِ قدیمی می‌آید.
 */
final class LegacyUrlResolver
{
    /** مسیرهایی که هرگز ریدایرکت نمی‌شوند — ماشین‌به‌ماشین یا خصوصی */
    private const SKIP = ['api', 'system', 'admin', 'account', 'assets', 'bale', 'payment', 'v1',
        '.well-known', 'storage', 'up', 'healthz', 'go', 'sb'];

    /** @var array<string,string>|null */
    private static ?array $exact = null;

    /**
     * @return array{0:int,1:?string}|null  [وضعیت، مقصد] — null یعنی همان ۴۰۴ بماند
     */
    public static function resolve(Request $request): ?array
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return null;   // ۳۰۱ روی POST بدنه را دور می‌ریزد
        }

        $path = rawurldecode($request->getPathInfo());
        $path = '/'.trim((string) preg_replace('~/+~', '/', $path), '/');

        $loc = '';
        $core = $path;

        if (preg_match('~^/(en|tr)(/.*)?$~i', $path, $m)) {
            $loc = strtolower($m[1]);
            $core = ($m[2] ?? '') === '' ? '/' : $m[2];
        }

        $first = explode('/', ltrim($core, '/'))[0];

        if (in_array(strtolower($first), self::SKIP, true)) {
            return null;
        }

        // ── ۱) /null ──
        if ($core === '/null' || str_ends_with($core, '/null')) {
            return [301, self::localized($loc, substr($core, 0, -5) ?: '/')];
        }

        // ── ۲) فایلِ سیستمی/قالبِ وردپرس ──
        foreach ((array) config('legacy_urls.gone', []) as $re) {
            if (preg_match($re, $core)) {
                return [410, null];
            }
        }

        // ── ۳) پسوندهای وردپرسی ──
        $stripped = $core;

        while (preg_match('~^(.*)/(feed|amp|embed|trackback|rss|atom|page/\d+)$~i', $stripped, $m)) {
            $stripped = $m[1] === '' ? '/' : $m[1];
        }

        $wasStripped = $stripped !== $core;
        $core = $stripped;

        // ── ۴) نقشهٔ دقیق ──
        $key = self::normalize($core);
        $exact = self::exactMap();

        if ($key !== '' && isset($exact[$key])) {
            return [301, self::target($loc, $exact[$key])];
        }

        // ── ۵) هرزنامه ──
        $spam = (string) config('legacy_urls.spam', '');

        if ($spam !== '' && $key !== '' && preg_match($spam, $key)) {
            return [410, null];
        }

        // ── ۶) الگوهای ساختاری ──
        if (preg_match('~^/category/(.+)$~u', $core, $m)) {
            return [301, self::localized($loc, self::categoryTarget($m[1]))];
        }

        if (preg_match('~^/(tag|author)(/|$)~i', $core) || preg_match('~^/(19|20)\d{2}(/\d{1,2}){0,2}$~', $core)) {
            return [301, self::localized($loc, '/blog')];
        }

        if (preg_match('~^/seo/contact(/|$)~i', $core)) {
            return [301, self::localized($loc, '/contact')];
        }

        if (preg_match('~^/seo(/|$)~i', $core)) {
            return [301, self::localized($loc, '/tools/seo')];
        }

        // نمونه‌کارهای تکیِ وردپرس (/portfolio/x، /portfolio-type-2، /portfolios)
        if (preg_match('~^/portfolio(s|-type-[\w-]+)?/~i', $core)) {
            return [301, self::target($loc, '/urmia/portfolio')];
        }

        // شناسه‌های عددیِ WPML (/18318-2) — نوشته‌ای بی‌نامک که مقصدِ دقیقی ندارد
        if (preg_match('~^/\d+(-\d+)?$~', $core)) {
            return [301, self::localized($loc, '/blog')];
        }

        if (preg_match('~^/(feeds?|atom|rss|index)(\.xml)?$~i', $core)) {
            return [301, self::localized($loc, '/blog')];
        }

        if (preg_match('~^/blog/portfolio(/|$)~i', $core)) {
            return [301, self::target($loc, '/urmia/portfolio')];
        }

        // پکیج‌های بکاپ/دانلودی که از فروش برداشته شدند
        if (preg_match('~^/order/(backup|download)-\d+$~i', $core, $m)) {
            return [301, self::localized($loc, '/hosting/'.strtolower($m[1]))];
        }

        if (preg_match('~^/product/~i', $core)) {
            return [301, self::localized($loc, '/parts')];
        }

        // نقشهٔ سایتِ افزونه‌های وردپرس (Yoast/RankMath/…) — نقشهٔ ما یکی است
        if ($core !== '/sitemap.xml' && preg_match('~^/[a-z0-9_-]*sitemap[a-z0-9_-]*\.(xml|rss|txt)$~i', $core)) {
            return [301, '/sitemap.xml'];
        }

        // ── ۷) قاعده‌های موضوعی — فقط نامکِ فارسی ──
        if (preg_match('~\p{Arabic}~u', $core)) {
            $text = str_replace(['-', '/', '_'], ' ', $key);

            foreach ((array) config('legacy_urls.rules', []) as $rule) {
                [$re, $to] = $rule;

                if (preg_match($re, $text)) {
                    return [301, self::target($loc, $to)];
                }
            }

            return [301, self::localized($loc, '/blog')];
        }

        // پسوند کنده شد ولی بقیه‌اش نقشه‌ای نداشت: دستِ‌کم پسوندِ مرده برود
        if ($wasStripped) {
            return [301, self::localized($loc, $core)];
        }

        return null;
    }

    /**
     * نرمال‌سازیِ کلیدِ نقشه. 🔴 همتای `gen.py` که `config/legacy_urls.php` را
     * ساخت — تغییرِ یکی بی‌دیگری یعنی هیچ کلیدی دیگر تطبیق نمی‌خورد.
     */
    public static function normalize(string $path): string
    {
        $s = mb_strtolower($path, 'UTF-8');
        $s = strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه',
            "\u{200C}" => '', "\u{200D}" => '', "\u{0640}" => '', "\u{FE0F}" => '',
        ]);
        $s = (string) preg_replace('~\s+~u', '-', $s);
        $s = (string) preg_replace('~/+~', '/', $s);

        return trim($s, '/');
    }

    /** @return array<string,string> */
    private static function exactMap(): array
    {
        return self::$exact ??= (array) config('legacy_urls.exact', []);
    }

    /** برای تست: نقشهٔ کش‌شده را دوباره از config بخوان */
    public static function flush(): void
    {
        self::$exact = null;
    }

    private static function categoryTarget(string $rest): string
    {
        $map = (array) config('legacy_urls.categories', []);
        $segments = array_reverse(array_filter(explode('/', self::normalize($rest))));

        foreach ($segments as $seg) {
            if (isset($map[$seg])) {
                return '/blog?cat='.$map[$seg];
            }
        }

        return '/blog';
    }

    /**
     * مقصدِ فارسیِ نقشه را برای زبانِ درخواست آماده می‌کند.
     *
     * ⚠️ صفحه‌های `/urmia` فقط فارسی‌اند (en/tr ۴۱۰ می‌دهند)؛ درخواستِ en/tr به
     *    معادلِ زبانیِ «طراحی سایت» می‌رود، وگرنه ۳۰۱ به یک ۴۱۰ می‌رسید.
     */
    private static function target(string $loc, string $to): string
    {
        if (preg_match('~^/(en|tr)(/|$)~', $to)) {
            return $to;
        }

        if ($loc !== '' && str_starts_with($to, '/urmia')) {
            return self::localized($loc, '/solutions/web-design');
        }

        return self::localized($loc, $to);
    }

    private static function localized(string $loc, string $path): string
    {
        if ($loc === '') {
            return $path;
        }

        return $path === '/' ? '/'.$loc : '/'.$loc.$path;
    }
}
