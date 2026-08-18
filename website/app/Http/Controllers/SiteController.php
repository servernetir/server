<?php

namespace App\Http\Controllers;

use App\Services\Domain\DomainSearch;
use App\Services\Domain\TldPriceBook;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function home(): View
    {
        return view('pages.home', [
            'products'   => config('servernet.products'),
            'plans'      => config('servernet.plans'),
            'enterprise' => config('servernet.enterprise'),
            'why'        => config('servernet.why'),
            'locations'  => config('servernet.locations'),
            'faqs'       => config('servernet.faqs'),
            'brands'     => config('servernet.brands'),
            'tlds'       => $this->tlds(),
        ]);
    }

    public function contact(): View
    {
        return view('pages.contact', [
            'faqs' => config('servernet.faqs'),
        ]);
    }

    /**
     * پایگاه دانش.
     *
     * ⚠️ بخشِ «تازه‌های وبلاگ» قبلاً از config/knowledge.php خوانده می‌شد — یک
     * فایلِ ثابت — پس هرچه در پنل مدیریت منتشر می‌شد این‌جا دیده نمی‌شد و
     * لینک‌های کارت‌ها هم `href="#"` مرده بودند. حالا از همان منبعِ بلاگ
     * (دیتابیس) می‌آید و به نوشتهٔ واقعی لینک می‌شود.
     */
    /**
     * 🔴 شمارشِ مستندات از **خودِ مستندات** می‌آید، نه از یک عددِ سخت‌کد.
     *
     * `config/knowledge.php` شش دستهٔ ساختگی با شمارشِ اختراعی داشت که جمعشان
     * ۱۶۴ می‌شد، در حالی که کلِ پایگاه دانش **۳۵** سند دارد — تقریباً پنج
     * برابر. عدد روی یک صفحهٔ عمومی نشسته بود و هیچ‌چیز در کد به واقعیت وصلش
     * نمی‌کرد، پس با انتشارِ هر سندِ تازه فقط **غلط‌تر** می‌شد.
     *
     * `DocsRepository::tree()` همان منبعی است که خودِ `/docs` از آن ساخته
     * می‌شود و بخشِ خالی را اصلاً برنمی‌گرداند. یعنی از این‌جا به بعد این عدد
     * نمی‌تواند دروغ بگوید — نه امروز، نه بعد از صد سندِ دیگر.
     */
    public function knowledge(): View
    {
        $tree = app(\App\Services\DocsRepository::class)->tree();

        return view('pages.knowledge', [
            'kb'    => config('knowledge'),
            'posts' => array_slice(app(\App\Services\BlogRepository::class)->index(), 0, 6),
            'docSections' => collect($tree)->map(fn ($s, $key) => [
                'key'   => $key,
                'icon'  => $s['meta']['icon'] ?? 'book',
                'meta'  => $s['meta'],
                'count' => count($s['items']),
                // به **اولین سندِ همان بخش** لینک می‌دهد، نه فهرستِ کلی: کارتی
                // که همه‌شان به یک آدرس بروند، برای کاربر و برای خزنده یکی است.
                'first' => $s['items'][0]['slug'] ?? null,
            ])->values()->all(),
        ]);
    }

    public function page(string $slug): View
    {
        $pages = config('pages');
        abort_unless(isset($pages[$slug]), 404);

        return view('pages.content', ['slug' => $slug, 'page' => $pages[$slug]]);
    }

    /**
     * صفحهٔ وضعیت.
     *
     * ⚠️ عمداً هیچ عددِ آپتایمی نمی‌سازد. تا وقتی پایشِ مستقل نداریم، هر عددی
     * که این‌جا چاپ شود ساختگی است — و ادعای ساختگی روی صفحه‌ای که مشتری
     * دقیقاً برای راستی‌آزمایی بازش می‌کند، از نبودِ صفحه بدتر است.
     */
    public function status(): \Illuminate\View\View
    {
        return view('pages.status', [
            'open'    => \App\Models\StatusIncident::openNow(),
            'history' => \App\Models\StatusIncident::history(90),
        ]);
    }
    /**
     * `llms.txt` — کارتِ شناساییِ سرورنت برای مدل‌های زبانی.
     *
     * بخشی از خریدارها دیگر از گوگل نمی‌پرسند؛ از ChatGPT و Perplexity
     * می‌پرسند. آن‌ها این فایل را می‌خوانند تا بفهمند این سایت چیست و کدام
     * صفحه‌ها معتبرند. بدونش، «سرورنت» به یک موجودیتِ واحد قفل نمی‌شود.
     *
     * ⚠️ فهرست‌ها از config و دیتابیس ساخته می‌شوند نه دستی: فایلِ دست‌نویس
     * همان هفتهٔ اول از کاتالوگ عقب می‌افتد و بعد مدل، محصولی را معرفی می‌کند
     * که دیگر نمی‌فروشیم.
     */
    public function llms(): \Illuminate\Http\Response
    {
        $base = rtrim(config('app.url'), '/');

        $lines = [
            '# ServerNet — servernet.cloud',
            '',
            '> ServerNet is an Iranian hosting company selling shared hosting, VPS,',
            '> dedicated and cloud servers across Iranian and European data centres,',
            '> with a Persian-first trilingual site (fa / en / tr).',
            '',
            '## Products',
        ];

        /*
        | 🔴 کاتالوگ باید **کامل** باشد، وگرنه مدل شرکت را کوچک‌تر از آنچه هست
        | معرفی می‌کند.
        |
        | تا شهریور ۱۴۰۵ فقط سه دسته این‌جا بودند و نتیجه‌اش این بود که
        | **سرورِ ابری** — که بیشترین صفحه و تازه‌ترین خطِ محصول است — و
        | **دامنه** و **خدمات** و **راهکارها** اصلاً نامشان برده نمی‌شد. یعنی
        | وقتی کسی از یک مدلِ زبانی می‌پرسید «سرورنت سرورِ ابری دارد؟»، تنها
        | سندی که خودمان برایش گذاشته‌ایم می‌گفت نه.
        |
        | ⚠️ همه از همان configهایی می‌آیند که خودِ صفحات از آن ساخته می‌شوند،
        | پس افزودنِ محصولِ تازه خودبه‌خود این‌جا هم می‌آید. فهرستِ دست‌نویس
        | همان هفتهٔ اول عقب می‌افتد.
        */
        foreach (['hosting', 'vps', 'dedicated', 'cloud', 'domain', 'services'] as $cat) {
            $items = $cat === 'hosting' ? config('hosting.products', []) : config("catalog.$cat", []);

            foreach ((array) $items as $slug => $p) {
                $title = $p['fa']['t'] ?? ($p['en']['t'] ?? $slug);
                $path = $cat === 'hosting' ? "/hosting/$slug" : "/$cat/$slug";
                $lines[] = "- [{$title}]({$base}{$path})";
            }
        }

        // ⚠️ راهکارِ ادغام‌شده ۳۰۱ می‌خورد؛ از **همان** ثابتی خوانده می‌شود که
        //    خودِ `/solutions` برای کنارگذاشتنشان استفاده می‌کند.
        $merged = \App\Http\Controllers\SolutionController::MERGED;

        foreach ((array) config('solutions', []) as $slug => $s) {
            if (isset($merged[$slug])) {
                continue;
            }
            $title = $s['fa']['t'] ?? ($s['en']['t'] ?? $slug);
            $lines[] = "- [{$title}]({$base}/solutions/{$slug})";
        }

        $lines[] = '';
        $lines[] = '## Key pages';

        foreach ([
            '/about'         => 'About ServerNet',
            '/contact'       => 'Contact and support',
            '/domains'       => 'Domain search and registration',
            '/servers'       => 'Refurbished physical servers for sale',
            '/blog'          => 'Blog',
            '/knowledge'     => 'Knowledge base',
            '/docs'          => 'Documentation',
            '/webtools'      => 'Free webmaster tools (100% client-side)',
            /*
             * ⚠️ این دو، هابِ **واقعیِ** ابزارهای شبکه‌اند و از صفحهٔ اصلی هم
             * لینک می‌شوند. پیش از این `/lookup` این‌جا بود — تنها آدرسِ این
             * فایل که در نقشهٔ سایت **نبود**، چون همان `/lookup/a` را رندر
             * می‌کند و به آن canonical می‌شود. فرستادنِ مدل به یک آدرسِ
             * غیرِcanonical یعنی همان صفحه را زیرِ دو نام معرفی کردن.
             */
            '/dns-lookup'    => 'DNS record lookup tools',
            '/network-scan'  => 'SSL, port and ping network tools',
            '/status'        => 'Service status',
            '/sla'           => 'Service level agreement',
            '/terms'         => 'Terms of service',
            '/privacy'       => 'Privacy policy',
        ] as $path => $label) {
            $lines[] = "- [{$label}]({$base}{$path})";
        }

        $lines[] = '';
        $lines[] = '## Notes for assistants';
        $lines[] = '- Prices on product pages are marked up with schema.org Offer and carry';
        $lines[] = '  a priceValidUntil date. Do not quote a price past that date; fetch the';
        // 🔴 این جمله قبلاً می‌گفت «قیمتِ ایرانی به تومان است» — و چون schema
        //    واقعاً ریال می‌دهد، مدل عدد را ده برابر گران نقل می‌کرد. دقیقاً در
        //    لحظه‌ای که خریدار دارد ما را با رقبا مقایسه می‌کند، و بی‌آنکه در
        //    هیچ آنالیتیکسی دیده شود.
        $lines[] = '  page again instead. Iranian prices in schema are IRR (rial):';
        $lines[] = '  divide by 10 to get toman, the unit shown on the page.';
        $lines[] = '- The English and Turkish versions live under /en and /tr.';
        $lines[] = '- Customer panel and admin pages are not public and are excluded from search.';

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type'  => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function sitemap(): \Illuminate\Http\Response
    {
        $locales = \App\Providers\AppServiceProvider::LOCALES;
        $urls = [];

        // $lastmod اختیاری است: برای نوشته‌های بلاگ تاریخِ واقعی داریم و دادنش
        // به گوگل کمک می‌کند صفحاتِ تازه را زودتر بازدید کند. برای صفحاتِ ثابت
        // تاریخِ ساختگی نمی‌دهیم — lastmod دروغ، اعتمادِ خزنده را کم می‌کند.
        $add = function (string $name, string|array $params = [], ?string $lastmod = null) use (&$urls, $locales) {
            foreach ($locales as $prefix) {
                $urls[] = ['loc' => route($prefix.$name, $params), 'lastmod' => $lastmod];
            }
        };

        $add('home');
        // /domains صفحهٔ فرودِ «ثبت دامنه» با کلیدواژهٔ ارزشمند است و جا افتاده بود
        $add('domain.search');
        // status و sla عمداً در نقشهٔ سایت‌اند: هر دو صفحهٔ «اثبات»اند و
        // خریدارِ سازمانی مستقیم دنبالشان می‌گردد.
        // ⚠️ webdesign عمداً در **منو** نیست ولی در نقشهٔ سایت **هست** — این دو
        //    یکی نیستند. صفحه‌ای که از هیچ‌جای سایت لینک نمی‌شود، بدونِ نقشه ممکن
        //    است هرگز ایندکس نشود، و کلِ هدفش ورودیِ ارگانیکِ محلی است.
        foreach (['contact', 'knowledge', 'about', 'privacy', 'terms', 'careers', 'status', 'sla', 'webdesign'] as $n) {
            $add($n);
        }
        // فروشگاهِ سرورِ فیزیکی — فهرست + صفحهٔ هر مدل. منبع همان کاتالوگِ زنده
        // است (DB اگر پر باشد، وگرنه config)، تا مدل‌های افزوده‌شده از پنل هم
        // در نقشهٔ سایت بیایند.
        $add('servers.index');
        $serverSlugs = \Illuminate\Support\Facades\Schema::hasTable('physical_servers')
            && \App\Models\PhysicalServer::active()->exists()
                ? \App\Models\PhysicalServer::active()->ordered()->pluck('slug')->all()
                : array_keys((array) config('servers.models'));
        foreach ($serverSlugs as $slug) {
            $add('servers.show', $slug);
        }
        foreach (['seo', 'whois', 'ip', 'meet', 'app-builder', 'domain-ideas', 'speedtest'] as $slug) {
            $add('tools', $slug);
        }
        $add('hub.dns');
        $add('hub.network');

        /*
        | ⚠️ سرورِ ابری کاملاً از نقشهٔ سایت جا افتاده بود — صفحاتی که بیشترین
        | متنِ سئوی یکتا برایشان نوشته شده (چرا این مکان، تأخیر تقریبی، مناسبِ
        | چه کاری) و خودِ صفحهٔ اصلیِ فروشِ سرورِ مجازی.
        |
        | ⚠️ پشتِ `hasTable` است تا روی سرورِ مهاجرت‌نخورده نقشه ۵۰۰ ندهد.
        */
        $add('cloud.index');

        if (\Illuminate\Support\Facades\Schema::hasTable('cloud_locations')) {
            foreach (\App\Models\CloudLocation::where('is_active', true)->pluck('code') as $code) {
                $add('cloud.location', $code);
            }
        }
        $add('blog.index');
        foreach (app(\App\Services\BlogRepository::class)->index() as $post) {
            $add('blog', $post['slug'], $post['date'] ?? null);
        }
        $add('webtools.index');
        foreach (\App\Http\Controllers\WebToolsController::slugs() as $wt) {
            $add('webtools', $wt);
        }
        $add('docs.index');
        foreach (app(\App\Services\DocsRepository::class)->tree() as $sec) {
            foreach ($sec['items'] as $item) {
                $add('docs', $item['slug']);
            }
        }
        foreach (array_keys(config('lookup.types')) as $type) {
            $add('lookup', $type);
        }
        $add('solutions.index');                 // هابِ راهکارها — والدِ این دسته
        foreach (array_keys(config('solutions')) as $slug) {
            if ($slug === 'email') {
                continue; // با /hosting/email یکی شده
            }
            $add('solution', $slug);
        }
        foreach (array_keys(config('hosting.products')) as $slug) {
            $add('hosting', $slug);
        }
        foreach (config('catalog') as $category => $products) {
            foreach (array_keys($products) as $slug) {
                $add('catalog', ['category' => $category, 'slug' => $slug]);
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.htmlspecialchars($u['loc'], ENT_XML1).'</loc>';

            if (! empty($u['lastmod'])) {
                $xml .= '<lastmod>'.htmlspecialchars($u['lastmod'], ENT_XML1).'</lastmod>';
            }

            $xml .= '</url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * چیپ‌های قیمتِ زیرِ جعبهٔ جست‌وجوی صفحهٔ اول.
     *
     * 🔴 پیش از این از **WHMCS** می‌خواند. WHMCS سامانهٔ قدیمی است که داریم از
     * آن مهاجرت می‌کنیم؛ فروشِ واقعی از OpenProvider می‌آید. نتیجه این بود که
     * صفحهٔ اول قیمتی نشان می‌داد که دیگر قیمتِ فروشِ ما نبود — و چون WHMCS
     * پاسخ می‌داد، هیچ‌جا خطایی هم دیده نمی‌شد.
     *
     * ⚠️ فهرست عمداً `DomainCheckController::SUGGEST` است، نه `featured_tlds`.
     *
     * کلیدِ کشِ `TldPriceBook` از **فهرستِ پسوندها** ساخته می‌شود و کرونِ
     * `domains:price-book` همان `SUGGEST` را گرم می‌کند. با فهرستِ دیگر، کلید
     * فرق می‌کرد، کش همیشه خالی می‌مانْد، و **هر بازدیدکننده به یک تماسِ
     * زندهٔ OpenProvider تبدیل می‌شد**. حسابِ ما یک بار به‌خاطرِ تماسِ زیاد از
     * آی‌پیِ ایران علامت خورده.
     *
     * ⚠️ و `cachedForTlds()` نه `forTlds()`: دومی روی کشِ سرد استعلامِ زنده
     * می‌زند، و این تابع در رندرِ صفحهٔ اول است — جایی که نه انتظارِ شبکه
     * قابل‌قبول است نه تماسِ ناخواسته.
     *
     * 🔴 پسوندی که **نمی‌فروشیم** اصلاً تبلیغ نمی‌شود.
     *
     * صافی `DomainSearch::sells()` است، نه فهرستی جدا. `.ir` امروز در
     * `UNSOLD_TLDS` است (به‌خواستِ کارفرما فعلاً فروخته نمی‌شود) و همان یک جا
     * تصمیم می‌گیرد: هم جلوی ساختِ پیش‌فاکتور را می‌گیرد، هم چیپِ صفحهٔ اول
     * را برمی‌دارد.
     *
     * ⚠️ فهرستِ موازی نساختم و `featured_tlds` را هم دست نزدم. اگر روزی `.ir`
     * فروخته شود، برداشتنش از `UNSOLD_TLDS` کافی است تا همه‌جا با هم برگردد —
     * وگرنه روزی یکی از دو فهرست کهنه می‌شود و پسوندی تبلیغ می‌شود که سبدِ
     * خرید قبولش نمی‌کند. همان نقصی که یک بار رخ داد.
     *
     * ⚠️ پسوندِ بی‌قیمت **اصلاً نشان داده نمی‌شود** — نه صفر، نه «—». همان
     * قاعدهٔ `site_price()`: صفر یعنی «نمی‌دانم»، نه «رایگان».
     *
     * ⚠️ `irt` برمی‌گردد نه `display`: `site_price()` خودش برای en/tr با نرخِ
     * زنده به یورو تبدیل می‌کند. رشتهٔ آمادهٔ تومانی آن دو زبان را می‌شکست.
     */
    private function tlds(): array
    {
        $prices = app(TldPriceBook::class)->cachedForTlds(DomainCheckController::SUGGEST);
        $manual = collect(config('servernet.tlds', []))->keyBy('tld');

        $out = [];

        foreach (config('servernet.featured_tlds', []) as $tld) {
            if (! DomainSearch::sells($tld)) {
                continue;
            }

            $key = ltrim($tld, '.');

            if ((int) ($prices[$key] ?? 0) > 0) {
                $out[] = ['tld' => $tld, 'irt' => (int) $prices[$key]];

                continue;
            }

            // دفترچه هنوز این پسوند را نگرفته — قیمتِ دستی، اگر واقعی باشد
            $row = $manual->get($tld);

            if ($row && (int) ($row['irt'] ?? 0) > 0) {
                $out[] = ['tld' => $tld, 'irt' => (int) $row['irt']];
            }
        }

        /*
        | کشِ کاملاً سرد (اولین بازدید پس از دیپلوی، پیش از کرون) ⇒ فهرستِ دستی.
        |
        | ⚠️ این‌جا هم صافیِ «می‌فروشیم؟» اعمال می‌شود. بی‌آن، همان مسیرِ
        | اضطراری پسوندِ نافروشی را از درِ پشتی برمی‌گرداند — و چون فقط روی
        | کشِ سرد رخ می‌دهد، ماه‌ها دیده نمی‌شود.
        */
        return $out !== [] ? $out : array_values(array_filter(
            config('servernet.tlds', []),
            fn ($r) => DomainSearch::sells((string) ($r['tld'] ?? ''))
        ));
    }
}
