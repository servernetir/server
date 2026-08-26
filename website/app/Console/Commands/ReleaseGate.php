<?php

namespace App\Console\Commands;

use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * دروازهٔ انتشار — ممیزی ۶ (QA + مشاور): «هیچ صفحه‌ای منتشر نشود مگر: در
 * sitemap، بارِ دومِ ناشناس HIT، حداقل یک لینکِ ورودی، اسکیمای نوعِ صفحه.
 * چهار شرطِ دودویی، مسدودکننده نه گزارش‌دهنده.»
 *
 * ═══ چرا لازم شد ═══
 *
 * ۲۲۳ صفحه منتشر شد بدونِ اینکه کسی هدرِ کش یا عضویت در sitemap را چک کند.
 * معیارِ پذیرشِ دورِ پنجم نوشته شده بود ولی به هیچ گیتِ اجرایی وصل نبود —
 * «شکستِ فرایندِ انتشار است، نه شکستِ کد».
 *
 * ═══ چه چیزی را می‌سنجد (همان کدهای RG ممیزی) ═══
 *
 *   RG-SITEMAP-03  هر URLِ sitemap ← ۲۰۰ (در رندرِ درون‌پروسه‌ای)
 *   RG-SITEMAP-04  هر صفحهٔ عمومیِ ایندکس‌پذیرِ کشف‌شده از لینک‌ها در sitemap هست
 *   RG-CACHE-01    بارِ دومِ بی‌کوکی روی نمونهٔ هر بخش ← X-Cache: HIT
 *   RG-SCHEMA-05   صفحاتِ پرچم‌دارِ /order دارای Product + AggregateOffer
 *   RG-GONE-10     /panel-preview و /share/url ← ۴۱۰
 *   RG-SEC-09      شش هدرِ امنیتی روی صفحهٔ اصلی
 *   RG-LINK-06     لینکِ شکسته ← links:site (فرمانِ جدا، همان شب)
 *
 * ═══ افزوده‌های ممیزی ۷ («مجموعهٔ دیروز کور بود — هیچ‌کدام محتوا را باز نمی‌کرد») ═══
 *
 *   RG-DUP-PATH-11  هیچ URLِ sitemap با الگوی کدِ کشورِ دوبل /([a-z]{2})-\1-
 *   RG-META-UNIQ-13 عنوانِ تکراری در همان زبان ← صفر (مسدودکننده)
 *   RG-ALT-14       imgِ بدونِ صفتِ alt: صفحه >۵۰٪ یا سایت‌واید >۲۵٪ ← قرمز
 *   RG-H1-15        دقیقاً یک <h1> در هر صفحهٔ HTML
 *   RG-BUDGET       سقفِ صفحهٔ هر بخش از config/seo.php (page_budget)
 *
 * درون‌پروسه‌ای است (بدونِ HTTP، بدونِ نویزِ Cloudflare) — همان دلیلِ links:site.
 *
 *     php artisan site:gate            ← کامل
 *     php artisan site:gate --limit=40 ← سریع (CI)
 */
class ReleaseGate extends Command
{
    protected $signature = 'site:gate {--limit=0 : سقفِ صفحه‌های رندرشدهٔ sitemap، ۰ یعنی همه}';

    protected $description = 'دروازهٔ انتشار: sitemap، کش، اسکیما، ۴۱۰ها، هدرهای امنیتی — هر بند مسدودکننده';

    /** بخش‌هایی که نمونه‌شان باید HIT شود (یک مسیر از هر بخش) */
    private const CACHE_SAMPLES = [
        '/', '/hosting/wordpress', '/vps/iran', '/cloud', '/blog', '/sla', '/about',
        // ⚠️ /lookup و /tools/* عمداً نیستند — HTMLشان به IPِ بازدیدکننده وابسته است و در denylist‌اند
        '/parts', '/urmia', '/webtools', '/docs', '/speed', '/aup', '/official-channels',
    ];

    private const SECURITY_HEADERS = [
        'Strict-Transport-Security', 'Content-Security-Policy', 'X-Frame-Options',
        'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy',
    ];

    public function handle(): int
    {
        config(['pagecache.enabled' => true]);

        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $fails = [];

        $get = function (string $path) use ($kernel) {
            // بی‌کوکی، بی‌کوئری — همان «بازدیدکنندهٔ ناشناس»ِ ممیزی
            // URLِ مطلق با میزبانِ واقعی (شورا/QA): با مسیرِ نسبی میزبان «localhost» می‌شد و
            // HSTS/ریدایرکتِ www و canonical روی هر صفحه قرمزِ دروغین می‌دادند
            return $kernel->handle(Request::create(rtrim((string) config('app.url'), '/').$path, 'GET', [], [], [], ['HTTP_USER_AGENT' => 'SN-ReleaseGate']));
        };

        // ── sitemap → فهرستِ مسیرها ─────────────────────────────────────
        $sm = $get('/sitemap.xml');

        if ($sm->getStatusCode() !== 200) {
            $this->error('sitemap.xml رندر نشد');

            return self::FAILURE;
        }

        preg_match_all('~<loc>([^<]+)</loc>~', (string) $sm->getContent(), $m);

        $inSitemap = [];

        foreach ($m[1] as $loc) {
            $p = CheckContentLinks::internalPath(html_entity_decode($loc, ENT_XML1));

            if ($p !== null) {
                $inSitemap[$p] = true;
            }
        }

        $pages = array_keys($inSitemap);
        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $pages = array_slice($pages, 0, $limit);
        }

        $this->info('صفحاتِ sitemap برای رندر: '.count($pages));

        /*
        | RG-DUP-PATH-11 (ممیزی ۷): هیچ URLِ sitemap با الگوی «کدِ کشورِ دوبل»
        | — ۲۲ صفحهٔ /cloud/de-de-… شش دور نامرئی ماندند چون هیچ تستی
        | «آنچه اعلام می‌شود» را با الگو نمی‌سنجید. مسدودکننده، آستانه: صفر.
        */
        foreach (array_keys($inSitemap) as $p) {
            if (preg_match('~/([a-z]{2})-\1-~', $p) === 1) {
                $fails[] = "RG-DUP-PATH-11 {$p} — کدِ کشورِ دوبل در sitemap";
            }
        }

        /*
        | RG-BUDGET (ممیزی ۷ — «قاعدهٔ سقفِ صفحه»): شمارِ صفحاتِ فارسیِ هر بخش
        | از سقفِ config/seo.php بالا نرود. «بدونِ assert، قاعده فقط یک آرزوست.»
        | سقف را فقط با ویرایشِ آگاهانهٔ همان config می‌شود بالا برد.
        */
        foreach ((array) config('seo.page_budget', []) as $prefix => $cap) {
            $n = count(array_filter(
                array_keys($inSitemap),
                fn ($p) => $p === $prefix || str_starts_with($p, rtrim($prefix, '/').'/')
            ));

            if ($n > (int) $cap) {
                $fails[] = "RG-BUDGET      {$prefix} ← {$n} صفحه (سقف: {$cap}) — انتشارِ تازه بدونِ تصمیمِ آگاهانه";
            }
        }

        // ── RG-SITEMAP-03 + کشفِ لینک‌ها برای RG-SITEMAP-04 ────────────
        // + جمع‌آوریِ عنوان/H1/alt برای سنجه‌های ممیزی ۷ در همان یک رندر
        $discovered = [];
        $titles = [];          // locale-prefix ⇒ title ⇒ [paths]
        $imgTotal = 0;
        $imgNoAlt = 0;

        foreach ($pages as $path) {
            try {
                $r = $get($path);
            } catch (\Throwable $e) {
                $fails[] = "RG-SITEMAP-03  {$path} ← ".get_class($e);

                continue;
            }

            if ($r->getStatusCode() !== 200) {
                $fails[] = "RG-SITEMAP-03  {$path} ← ".$r->getStatusCode();

                continue;
            }

            $html = (string) $r->getContent();

            // سنجه‌های کیفیت فقط برای HTML — llms.txt و فیدها عنوان/H1 ندارند
            if (! str_contains((string) $r->headers->get('Content-Type', ''), 'text/html')) {
                continue;
            }

            /*
            | RG-META-UNIQ-13: عنوانِ تکراری در همان زبان = مسدودکننده. «۴۶
            | صفحه با عنوانِ تکراری یعنی گوگل نمی‌تواند تشخیص دهد کدام صفحه
            | برای کدام کوئری است — خودِ سایت هم نتوانسته تفاوت را بیان کند.»
            */
            if (preg_match('~<title>(.*?)</title>~su', $html, $tm) === 1) {
                $bucket = preg_match('~^/(en|tr)(/|$)~', $path, $lm) === 1 ? $lm[1] : 'fa';
                $titles[$bucket][trim($tm[1])][] = $path;
            }

            // RG-H1-15: دقیقاً یک H1 (۷ صفحهٔ چند-H1 در ممیزی ۷ — P2 ولی ارزان)
            $h1 = preg_match_all('~<h1[\s>]~i', $html);

            if ($h1 !== 1) {
                $fails[] = "RG-H1-15       {$path} ← {$h1} تا <h1> (باید ۱ باشد)";
            }

            // RG-ALT-14: شمارشِ imgِ بدونِ صفتِ alt — آستانه‌های خودِ ممیزی
            preg_match_all('~<img\b[^>]*>~i', $html, $im);
            $pageTotal = count($im[0]);
            $pageNoAlt = count(array_filter($im[0], fn ($tag) => stripos($tag, 'alt=') === false));
            $imgTotal += $pageTotal;
            $imgNoAlt += $pageNoAlt;

            if ($pageTotal > 0 && $pageNoAlt / $pageTotal > 0.5) {
                $fails[] = "RG-ALT-14      {$path} ← {$pageNoAlt} از {$pageTotal} تصویر بدونِ صفتِ alt (حدِ صفحه: ۵۰٪)";
            }

            preg_match_all('~href=["\']([^"\']+)["\']~i', $html, $hm);

            foreach (array_unique($hm[1]) as $href) {
                $p = CheckContentLinks::internalPath($href);

                if ($p !== null && ! isset($inSitemap[$p]) && ! isset($discovered[$p])) {
                    $discovered[$p] = $path;
                }
            }
        }

        foreach ($titles as $bucket => $byTitle) {
            foreach ($byTitle as $title => $paths) {
                if (count($paths) > 1) {
                    $fails[] = 'RG-META-UNIQ-13 ['.$bucket.'] «'.mb_substr($title, 0, 60).'» × '.count($paths).': '.implode(' · ', array_slice($paths, 0, 4));
                }
            }
        }

        if ($imgTotal > 0 && $imgNoAlt / $imgTotal > 0.25) {
            $fails[] = "RG-ALT-14      سایت‌واید: {$imgNoAlt} از {$imgTotal} تصویر بدونِ صفتِ alt (حدِ کل: ۲۵٪)";
        }

        /*
        | RG-SITEMAP-04: صفحهٔ عمومیِ ایندکس‌پذیر که از لینک‌ها کشف شده ولی در
        | sitemap نیست — همان «۶۴ صفحهٔ /order خارج از sitemap». مستثنا: مسیرهای
        | خصوصی/تعاملی، و صفحه‌هایی که خودشان noindex اعلام می‌کنند.
        */
        $skip = '~^/(en|tr)?/?(account|admin|api|login|register|password|logout|system|report|share|sharing|panel-preview|parts/compare|lookup|tools|webtools|docs/search|sb|payment|blog\?)~';

        foreach ($discovered as $p => $source) {
            if (preg_match($skip, $p)) {
                continue;
            }

            try {
                $r = $get($p);
            } catch (\Throwable) {
                continue;
            }

            if ($r->getStatusCode() !== 200 || ! str_contains((string) $r->headers->get('Content-Type'), 'text/html')) {
                continue;
            }

            if (stripos((string) $r->getContent(), 'name="robots" content="noindex') !== false) {
                continue;
            }

            $fails[] = "RG-SITEMAP-04  {$p} (از {$source}) در sitemap نیست";
        }

        // ── RG-CACHE-01: بارِ دوم HIT ───────────────────────────────────
        foreach (self::CACHE_SAMPLES as $path) {
            $first = $get($path);

            if ($first->getStatusCode() !== 200) {
                continue;   // بخشِ ناموجود روی این نصب، شکستِ کش نیست
            }

            $second = $get($path);
            $state = (string) $second->headers->get('X-Cache', 'none');

            if ($state !== 'HIT') {
                $fails[] = "RG-CACHE-01    {$path} ← بارِ دوم {$state}";
            }
        }

        // ── RG-SCHEMA-05: صفحاتِ پرچم‌دارِ سفارش ───────────────────────
        foreach (\App\Models\Product::flagshipSlugs() as $sku) {
            $r = $get('/order/'.$sku);
            $html = (string) $r->getContent();

            if ($r->getStatusCode() !== 200 || ! str_contains($html, '"AggregateOffer"') || ! str_contains($html, '"Product"')) {
                $fails[] = "RG-SCHEMA-05   /order/{$sku} بدونِ Product/AggregateOffer";
            }
        }

        // ── RG-GONE-10 ──────────────────────────────────────────────────
        foreach (['/panel-preview', '/share/url', '/sharing/share-offsite'] as $p) {
            $code = $get($p)->getStatusCode();

            if ($code !== 410) {
                $fails[] = "RG-GONE-10     {$p} ← {$code} (باید ۴۱۰ باشد)";
            }
        }

        // ── RG-SEC-09 ───────────────────────────────────────────────────
        $home = $get('/');

        foreach (self::SECURITY_HEADERS as $h) {
            if (! $home->headers->has($h)) {
                $fails[] = "RG-SEC-09      هدرِ {$h} نیست";
            }
        }

        if (str_contains((string) $home->headers->get('Referrer-Policy'), 'unsafe-url')) {
            $fails[] = 'RG-SEC-09      Referrer-Policy نباید unsafe-url باشد';
        }

        // ── نتیجه ───────────────────────────────────────────────────────
        if ($fails === []) {
            $this->info('✅ دروازهٔ انتشار: همهٔ بندها سبز');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('🔴 دروازهٔ انتشار بسته — '.count($fails).' بند:');

        foreach ($fails as $f) {
            $this->line('  '.$f);
        }

        ErrorTracker::noteOnce('site', 'دروازهٔ انتشار '.count($fails).' بندِ قرمز دارد — php artisan site:gate', 21600, [
            'first' => array_slice($fails, 0, 10),
        ]);

        return self::FAILURE;
    }
}
