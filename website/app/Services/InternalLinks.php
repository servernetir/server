<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\Route;

/**
 * لینک‌سازیِ داخلی برای مقاله‌های تولیدشده.
 *
 * ═══ مسئله ═══
 *
 * مدل وقتی می‌خواهد لینکِ داخلی بگذارد، آدرس را **حدس** می‌زند. حدسش هم
 * منطقی است — «/راهنمای-خرید-هاست» یا «/hosting/wordpress» دقیقاً همان
 * چیزی است که یک سایتِ هاستینگ *باید* داشته باشد. ولی ما نداریم، و نتیجه
 * ۴۰۴ ای است که هیچ تستی نمی‌بیندش چون در دیتابیس است نه در کد. ممیزیِ
 * بیرونی دقیقاً همین را پیدا کرد (`/راهنمای-خرید-لپ-تاپ`).
 *
 * پس مدل حق ندارد آدرس بسازد. این کلاس دو کار می‌کند:
 *
 *   ۱) `inventory()` — فهرستِ **بسته**ای از آدرس‌های واقعی به مدل می‌دهد و
 *      پرامپت می‌گوید «فقط از این‌ها». حدس‌زدن را از اول ممکن نمی‌کند.
 *   ۲) `sanitize()` — بعد از تولید، هر لینکِ داخلی را واقعاً حل می‌کند؛ هرچه
 *      حل نشد **بازمی‌شود** (متن می‌ماند، لینک می‌رود). دفاعِ لایهٔ دوم، چون
 *      مدل گاهی هرچه بگویی باز هم آدرس می‌سازد.
 *
 * ═══ چرا فهرست دستی و نه «همهٔ روت‌ها» ═══
 *
 * روتر صدها مسیر دارد که هیچ‌کدام مقصدِ خوبی برای لینکِ درون‌متنی نیستند
 * (`/order/…`، `/report/{token}`، پنل). فهرستِ زیر انتخاب‌شده است: صفحه‌هایی
 * که هم به کاربر کمک می‌کنند و هم ارزشِ لینک را جایی می‌برند که بفروشد.
 */
class InternalLinks
{
    /**
     * هاب‌های تجاری و ابزاری، دسته‌بندی‌شده بر اساس موضوعِ مقاله.
     * هر آدرس پیش از افزودن با درخواستِ واقعی تأیید شده که ۲۰۰ می‌دهد.
     *
     * ⚠️ `/hosting` و `/vps` و `/services` عمداً این‌جا نیستند: هر سه ۳۰۱
     * می‌دهند. لینکِ درون‌متنی به ریدایرکت یعنی یک پرشِ اضافه برای کاربر و
     * رقیق‌شدنِ سیگنالِ لینک — مقصدِ نهایی مستقیم آورده شده.
     *
     * @var array<string,list<array{0:string,1:string}>>
     */
    private const HUBS = [
        'hosting' => [
            ['/hosting/linux', 'هاست لینوکس سرورنت'],
            ['/servers', 'سرور اختصاصی'],
            ['/webtools', 'ابزارهای رایگان وب‌مستر'],
            ['/lookup', 'بررسی DNS و شبکه'],
        ],
        'cloud' => [
            ['/cloud', 'سرور ابری سرورنت'],
            ['/cloud/iaas', 'زیرساخت ابری (IaaS)'],
            ['/cloud/kubernetes', 'کوبرنتیز مدیریت‌شده'],
            ['/cloud/object-storage', 'فضای ذخیره‌سازی آبجکت'],
            ['/cloud/cdn', 'شبکهٔ توزیع محتوا'],
        ],
        'security' => [
            ['/cloud/ddos-protection', 'محافظت در برابر DDoS'],
            ['/lookup', 'ابزارهای بررسی DNS و شبکه'],
            ['/sla', 'سطح خدمات و آپتایم سرورنت'],
            ['/status', 'وضعیت لحظه‌ای سرویس‌ها'],
        ],
        'seo' => [
            ['/webtools', 'ابزارهای سئو و وب‌مستر'],
            ['/lookup', 'بررسی فنی دامنه و DNS'],
            ['/webdesign', 'طراحی سایت سرورنت'],
        ],
        'tutorial' => [
            ['/docs', 'مستندات سرورنت'],
            ['/webtools', 'ابزارهای رایگان'],
            ['/hosting/linux', 'هاست لینوکس'],
        ],
        'tech' => [
            ['/cloud', 'سرور ابری'],
            ['/servers', 'سرور فیزیکی'],
            ['/lookup', 'ابزارهای شبکه'],
        ],
        'business' => [
            ['/solutions', 'راهکارهای سازمانی سرورنت'],
            ['/domains', 'ثبت دامنه'],
            ['/webdesign', 'طراحی سایت'],
        ],

        /* ——— بخش‌های مستندات (config/docs.php) ——— */
        'getting-started' => [['/docs', 'مستندات سرورنت'], ['/solutions', 'راهکارها']],
        'servers'         => [['/servers', 'سرور اختصاصی'], ['/cloud', 'سرور ابری']],
        'domains'         => [['/domains', 'جستجو و ثبت دامنه'], ['/lookup', 'بررسی رکوردهای DNS']],
        'email'           => [['/lookup', 'بررسی رکورد MX'], ['/docs', 'مستندات']],
        'tools'           => [['/webtools', 'ابزارهای وب‌مستر'], ['/lookup', 'ابزارهای DNS و شبکه']],
        'billing'         => [['/docs', 'مستندات'], ['/solutions', 'راهکارها']],
    ];

    /**
     * دامنه‌هایی که مالِ خودِ سرورنت‌اند — لینک به آنها هرگز `nofollow` نمی‌گیرد.
     *
     * ⚠️ `servernet.ir` دامنهٔ مرده نیست: زیرساختِ زنده است و محتوایش در حالِ
     * مهاجرت به `.cloud`. (CLAUDE.md §۱۰.۵)
     */
    private const OWN_DOMAINS = ['servernet.cloud', 'servernet.ir'];

    /** همیشه در دسترس، مستقل از دسته */
    private const ALWAYS = [
        ['/blog', 'بلاگ سرورنت'],
        ['/docs', 'مستندات و پایگاه دانش'],
    ];

    /** @var array<string,bool>|null کشِ مسیرهای ثابتِ روتر */
    private ?array $known = null;

    /** @var array<string,bool> کشِ نتیجهٔ حلِ مسیرهای پارامتردار */
    private array $resolved = [];

    /**
     * فهرستِ مجازِ لینک برای یک مقاله — آماده برای گذاشتن در پرامپت.
     *
     * @return list<array{url:string,anchor:string}>
     */
    public function inventory(string $category, string $type = 'blog', ?string $excludeSlug = null): array
    {
        $out = [];

        foreach (array_merge(self::HUBS[$category] ?? [], self::ALWAYS) as [$url, $anchor]) {
            $out[$url] = ['url' => $url, 'anchor' => $anchor];
        }

        foreach ($this->siblings($category, $type, $excludeSlug) as $row) {
            $out[$row['url']] = $row;
        }

        return array_values($out);
    }

    /** همان فهرست، به‌صورتِ متنی برای تزریق در پرامپت */
    public function promptBlock(string $category, string $type = 'blog', ?string $excludeSlug = null): string
    {
        $rows = $this->inventory($category, $type, $excludeSlug);

        if (! $rows) {
            return '';
        }

        return implode("\n", array_map(fn ($r) => '- '.$r['url'].'  → '.$r['anchor'], $rows));
    }

    /**
     * مقاله‌های منتشرشدهٔ هم‌موضوع — مقصدِ لینکِ «بیشتر بخوانید».
     *
     * ⚠️ فقط منتشرشده‌ها: لینک به پیش‌نویسی که هنوز `published_at` اش نرسیده
     * تا روزِ انتشارش ۴۰۴ می‌دهد، و دقیقاً همان بازه‌ای است که گوگل مقالهٔ
     * تازه را می‌خزد.
     *
     * @return list<array{url:string,anchor:string}>
     */
    private function siblings(string $category, string $type, ?string $excludeSlug): array
    {
        try {
            $posts = Post::query()
                ->where('type', $type)
                ->where('status', 'published')
                ->where('category', $category)
                ->when($excludeSlug, fn ($q) => $q->where('slug', '!=', $excludeSlug))
                ->with('translations')
                ->latest('published_at')
                ->limit(6)
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $base = $type === 'kb' ? '/docs/' : '/blog/';

        return $posts->map(fn (Post $p) => [
            'url'    => $base.$p->slug,
            'anchor' => optional($p->tr('fa'))->title ?? $p->slug,
        ])->values()->all();
    }

    /**
     * پاک‌سازیِ لینک‌های یک مقالهٔ تولیدشده.
     *
     *   • لینکِ داخلیِ حل‌نشدنی → باز می‌شود (متن می‌ماند، لینک می‌رود)
     *   • لینکِ بیرونی → rel="nofollow noopener" و target="_blank"
     *
     * ⚠️ عمداً با regex کار می‌کند و نه DOMDocument: ورودی قطعه‌ای از HTML
     * است نه سندِ کامل، و DOMDocument قطعهٔ فارسی را با افزودنِ
     * <html><body> و کدگذاریِ اشتباه برمی‌گرداند.
     */
    public function sanitize(string $html, bool $markExternal = true): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        $out = preg_replace_callback(
            '~<a\b([^>]*)>(.*?)</a>~is',
            function (array $m) use ($host, $markExternal): string {
                $attrs = $m[1];
                $text  = $m[2];

                if (! preg_match('~href\s*=\s*["\']([^"\']*)["\']~i', $attrs, $h)) {
                    return $text;                       // لینکِ بی‌مقصد
                }

                $href = trim(html_entity_decode($h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($href === '' || str_starts_with($href, '#')
                    || preg_match('~^(mailto:|tel:)~i', $href)) {
                    return $m[0];
                }

                if (preg_match('~^(javascript:|data:)~i', $href)) {
                    return $text;                       // هرگز اجرا نشود
                }

                /*
                 * 🔴 «میزبانِ متفاوت» با «سایتِ دیگری» یکی نیست.
                 *
                 * پست‌های ایمپورت‌شده پر از لینک به `servernet.ir` اند — دامنهٔ
                 * **خودمان**، که مهاجرتِ محتوایش به `.cloud` در جریان است.
                 * زدنِ `nofollow` روی آنها یعنی دور ریختنِ همان اعتبارِ لینکی که
                 * کلِ مهاجرت برای جمع‌کردنش انجام می‌شود.
                 *
                 * ⚠️ و مهم‌تر: هر آدرسِ مطلق به میزبانِ دیگر باید **همین‌جا**
                 * تصمیمش گرفته شود. اگر بیفتد پایین، `internalPath()` برای
                 * میزبانِ غیرخودی `null` می‌دهد و لینک **باز می‌شود** — یعنی
                 * لینکِ سالم نابود می‌شد. دو تست همین را گرفتند.
                 */
                $isHttp = (bool) preg_match('~^https?://~i', $href);
                $linkHost = $isHttp ? (string) parse_url($href, PHP_URL_HOST) : '';

                if ($isHttp && strtolower($linkHost) !== strtolower((string) $host)) {
                    // دامنهٔ دومِ خودمان، یا وقتی فراخوان نخواسته دست بخورد: دست‌نخورده
                    if ($this->isOwnHost($linkHost, (string) $host) || ! $markExternal) {
                        return $m[0];
                    }

                    $clean = preg_replace('~\s(rel|target)\s*=\s*["\'][^"\']*["\']~i', '', $attrs);

                    return '<a'.$clean.' rel="nofollow noopener" target="_blank">'.$text.'</a>';
                }

                $path = $this->internalPath($href);

                return ($path !== null && $this->resolves($path)) ? $m[0] : $text;
            },
            $html
        );

        return $out ?? $html;
    }

    /**
     * افزودنِ پیشوندِ زبان به لینک‌های داخلیِ نسخهٔ ترجمه‌شده.
     *
     * ⚠️ بی‌این، مقالهٔ انگلیسی به `/blog/foo` لینک می‌دهد و خواننده وسطِ متنِ
     * انگلیسی به صفحهٔ **فارسی** پرتاب می‌شود. برای گوگل بدتر است: لینکِ
     * درون‌متنیِ بین‌زبانی سیگنالِ hreflang را خنثی می‌کند و خزندهٔ نسخهٔ
     * انگلیسی مدام به شاخهٔ فارسی می‌رود.
     *
     * فقط مسیرهای **نسبی** دست می‌خورند و پیشوندِ تکراری اضافه نمی‌شود.
     */
    public function localize(string $html, string $locale): string
    {
        if ($locale === 'fa' || $locale === '') {
            return $html;                                   // فارسی پیشوند ندارد
        }

        /*
         * 🔴 آدرسِ **کامل** هم باید بازنویسی شود، نه فقط مسیرِ نسبی.
         *
         * نسخهٔ اول فقط `href="/blog/x"` را می‌گرفت. ولی مقالهٔ واقعیِ اول که
         * روی سایت نشست، آدرس‌ها را کامل نوشته بود
         * (`https://servernet.cloud/blog/x`) — چون قاعدهٔ لینکِ محصول آدرسِ
         * کاملِ ساخته‌شده با `lroute()` به پرامپت می‌دهد و مدل همان سبک را
         * برای بقیهٔ لینک‌ها هم تکرار کرد.
         *
         * نتیجه: خوانندهٔ انگلیسی وسطِ متنِ انگلیسی روی لینک می‌زد و به صفحهٔ
         * **فارسی** می‌افتاد — دقیقاً همان خرابی‌ای که این متد برای جلوگیری
         * از آن نوشته شده بود. کدِ صفحه ۲۰۰، هیچ خطایی، و فقط با باز کردنِ
         * نسخهٔ en دیده شد.
         *
         * درسِ عمومی‌تر: وقتی چند نویسنده (کدِ ما + مدل) یک HTML می‌سازند،
         * قالبِ آدرس یکدست نیست. هر بازنویسی باید **هر دو** شکل را بپذیرد.
         */
        $host = preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST), '~');

        $out = preg_replace_callback(
            '~href\s*=\s*(["\'])(https?://'.$host.')?(/[^"\']*)\1~i',
            function (array $m) use ($locale): string {
                $origin = $m[2];          // خالی برای مسیرِ نسبی
                $path = $m[3];

                if (preg_match('~^/(fa|en|tr)(/|$)~', $path)) {
                    return $m[0];                           // از قبل پیشوند دارد
                }

                return 'href='.$m[1].$origin.'/'.$locale.$path.$m[1];
            },
            $html
        );

        return $out ?? $html;
    }

    /** شمارشِ لینکِ داخلیِ سالم در یک متن — برای سنجشِ کیفیتِ تولید */
    public function countInternal(string $html): int
    {
        preg_match_all('~href\s*=\s*["\']([^"\']+)["\']~i', $html, $m);

        $n = 0;
        foreach (array_unique($m[1]) as $href) {
            $path = $this->internalPath($href);
            if ($path !== null && $this->resolves($path)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * میزبان مالِ خودمان است؟ (دامنهٔ اصلی، دامنهٔ دوم، و زیردامنه‌هایشان)
     *
     * `OWN_DOMAINS` عمداً سخت‌کد است و از config نمی‌آید: این فهرست یک
     * واقعیتِ سازمانی است، نه تنظیمی که هر نصب عوضش کند — و اگر روزی خالی
     * بماند، لینک‌های داخلیِ خودمان بی‌صدا `nofollow` می‌گیرند.
     */
    private function isOwnHost(string $candidate, string $appHost): bool
    {
        $candidate = strtolower(ltrim($candidate, '.'));

        if ($candidate === '' || $candidate === strtolower($appHost)) {
            return true;
        }

        foreach (self::OWN_DOMAINS as $own) {
            if ($candidate === $own || str_ends_with($candidate, '.'.$own)) {
                return true;
            }
        }

        return false;
    }

    /** آدرس → مسیرِ داخلی، یا null اگر داخلی نباشد */
    public function internalPath(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($href === '' || str_starts_with($href, '#')
            || preg_match('~^(mailto:|tel:|javascript:|data:)~i', $href)) {
            return null;
        }

        if (preg_match('~^https?://~i', $href)) {
            if (parse_url($href, PHP_URL_HOST) !== parse_url((string) config('app.url'), PHP_URL_HOST)) {
                return null;
            }
            $href = (string) parse_url($href, PHP_URL_PATH);
        }

        $href = (string) strtok($href, '#');
        $href = (string) strtok($href, '?');

        return str_starts_with($href, '/') ? (rtrim($href, '/') ?: '/') : null;
    }

    /** آیا این مسیرِ داخلی واقعاً چیزی برمی‌گرداند؟ */
    public function resolves(string $path): bool
    {
        $path = rtrim($path, '/') ?: '/';

        if (isset($this->resolved[$path])) {
            return $this->resolved[$path];
        }

        if ($this->known === null) {
            $this->known = [];
            foreach (Route::getRoutes() as $route) {
                if (in_array('GET', $route->methods(), true) && ! str_contains($route->uri(), '{')) {
                    $this->known['/'.trim($route->uri(), '/')] = true;
                }
            }
        }

        if (isset($this->known[$path])) {
            return $this->resolved[$path] = true;
        }

        // مسیرِ پارامتردار (/blog/{slug}، /cloud/{location}) — باید واقعاً زده شود
        try {
            $request = \Illuminate\Http\Request::create($path, 'GET');
            $status  = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request)->getStatusCode();

            return $this->resolved[$path] = $status < 400;
        } catch (\Throwable) {
            return $this->resolved[$path] = false;
        }
    }
}
