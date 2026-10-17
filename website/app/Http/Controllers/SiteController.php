<?php

namespace App\Http\Controllers;

use App\Models\CloudPlan;
use App\Services\CatalogPricing;
use App\Services\Domain\DomainSearch;
use App\Services\Domain\TldPriceBook;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function home(): View
    {
        $tlds = $this->tlds();
        $offers = CloudPlan::offers()->values();
        $products = (array) config('servernet.products', []);
        $catalog = app(CatalogPricing::class);
        $quotes = [
            $catalog->minimumQuote('linux'),
            $catalog->minimumQuote('wordpress'),
            $this->cloudQuote($offers->first(fn (CloudPlan $plan) => ! $plan->isMetal())),
            $this->cloudQuote($offers->first(fn (CloudPlan $plan) => $plan->isMetal())),
            $this->cloudQuote($offers->first()),
            $tlds[0] ?? ['contact' => true, 'quote' => true],
        ];
        foreach ($products as $index => $product) {
            unset($product['irt'], $product['eur'], $product['base_eur'], $product['yearly_irt']);
            $products[$index] = $product + ($quotes[$index] ?? ['contact' => true, 'quote' => true]);
        }

        return view('pages.home', [
            'products'   => $products,
            'plans'      => $this->homeCloudPlans($offers),
            'enterprise' => config('servernet.enterprise'),
            'why'        => config('servernet.why'),
            'locations'  => config('servernet.locations'),
            'faqs'       => config('servernet.faqs'),
            'brands'     => config('servernet.brands'),
            'tlds'       => $tlds,
        ]);
    }

    private function cloudQuote(?CloudPlan $plan): array
    {
        if (! $plan || (int) $plan->price_irt <= 0) {
            return ['contact' => true, 'quote' => true];
        }
        $factor = 1 - ((int) config('billing.cycles.yearly.discount_pct', 0) / 100);
        $yearly = (int) (ceil(((int) $plan->price_irt * $factor) / 10_000) * 10_000);
        return ['irt' => (int) $plan->price_irt, 'yearly_irt' => $yearly, 'final_irt' => true];
    }

    private function homeCloudPlans(Collection $offers): array
    {
        return $offers->take(4)->values()->map(function (CloudPlan $plan, int $index) {
            $name = trim((string) $plan->public_name) ?: 'Cloud '.($index + 1);
            return $this->cloudQuote($plan) + [
                'pid' => $plan->id,
                'cpu' => $plan->vcpu.' vCPU',
                'ram' => $plan->ramLabel(),
                'ssd' => $plan->diskLabel(),
                'tb' => $plan->trafficLabel(),
                'en' => $name, 'fa' => $name, 'tr' => $name,
                'popular' => $index === 1,
            ];
        })->all();
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
            '/vps/hourly'    => 'Hourly-billed cloud VPS (pay per hour from wallet, Iran and abroad)',
            '/gpu'           => 'Hourly GPU servers for AI inference, rendering and training (interruptible, SSH access)',
            '/servers'       => 'Refurbished physical servers for sale',
            /*
             * ⚠️ فروشگاهِ قطعات صفحهٔ جدایی است، نه زیرمجموعهٔ `/servers`.
             * مدلِ زبانی که «قطعهٔ سرور» را جستجو می‌کند، اگر فقط `/servers`
             * را بشناسد کاربر را به فهرستِ سرورِ کامل می‌فرستد — یعنی همان
             * پاسخِ «تقریباً درست» که بدتر از نبودن است.
             */
            '/parts'         => 'HPE ProLiant Gen8-Gen12 server parts sold individually (CPU, ECC memory, drives, RAID, NICs, PSUs)',
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
            '/aup'           => 'Acceptable use policy (no VPN/proxy resale on Iran infrastructure)',
            '/abuse'         => 'Report abuse of ServerNet infrastructure (reviewed within 2 business days)',
            '/speed'         => 'Public speed report with reproducible methodology (measured TTFB, all numbers)',
            '/official-channels' => 'The only official ServerNet channels and contact points (beware of look-alike Telegram channels)',
            '/privacy'       => 'Privacy policy',
        ] as $path => $label) {
            $lines[] = "- [{$label}]({$base}{$path})";
        }

        /*
        | ممیزی ۶ (سئو/AEO): «/order/* و /urmia/* در llms.txt نیستند — یعنی
        | صفحاتِ قیمت و کلِ استراتژیِ ارومیه برای موتورهای پاسخِ AI اعلام
        | نشده‌اند.» چیزی که باعثِ نقل‌شدن می‌شود قیمتِ عددیِ تاریخ‌دار است، و
        | آن دقیقاً روی /order/{sku} نشسته. فقط پرچم‌دارها (همان‌هایی که در
        | sitemap هستند) — نه ۶۴ صفحهٔ هم‌قالب.
        */
        $flagship = \App\Models\Product::flagshipSlugs();

        if ($flagship !== []) {
            $lines[] = '';
            $lines[] = '## Order pages (public pricing, all billing cycles, tax-inclusive totals, no login)';

            // برچسب = نامِ محصول + کمترین قیمتِ ماهانه (شورا/سئو): «group-3» برای مدل بی‌معنی است
            $products = \App\Models\Product::whereIn('slug', $flagship)->get()->keyBy('slug');

            foreach ($flagship as $sku) {
                $p = $products[$sku] ?? null;
                $label = $p
                    ? $p->name.' — from '.cloud_price($p->monthlyEquivalent('yearly')).'/mo'
                    : $sku;
                $lines[] = "- [{$label}]({$base}/order/{$sku})";
            }
        }

        // ارومیه — فقط فارسی؛ عنوانِ واقعیِ هر صفحه از config تا مدل همان را نقل کند
        if (is_array(config('urmia.pages'))) {
            $lines[] = '';
            $lines[] = '## Urmia (West Azerbaijan) local services — Persian only';
            $lines[] = "- [Web design & software in Urmia]({$base}/urmia)";

            foreach ((array) config('urmia.pages') as $slug => $pg) {
                $lines[] = '- ['.((string) ($pg['title'] ?? $slug))."]({$base}/urmia/{$slug})";
            }

            foreach (array_keys((array) config('urmia.cities', [])) as $slug) {
                $lines[] = "- [{$slug}]({$base}/urmia/cities/{$slug})";
            }
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
        // اولین اجرای site:gate روی سرور (۳ شهریور) این را به‌عنوان RG-SITEMAP-04
        // گرفت: صفحهٔ عمومیِ ایندکس‌پذیر، لینک‌شده از /domains، غایب از نقشه.
        $add('domain.transfer.page');
        // status و sla عمداً در نقشهٔ سایت‌اند: هر دو صفحهٔ «اثبات»اند و
        // خریدارِ سازمانی مستقیم دنبالشان می‌گردد.
        // ⚠️ webdesign عمداً در **منو** نیست ولی در نقشهٔ سایت **هست** — این دو
        //    یکی نیستند. صفحه‌ای که از هیچ‌جای سایت لینک نمی‌شود، بدونِ نقشه ممکن
        //    است هرگز ایندکس نشود، و کلِ هدفش ورودیِ ارگانیکِ محلی است.
        foreach (['contact', 'knowledge', 'about', 'privacy', 'terms', 'aup', 'speed', 'abuse', 'developers', 'careers', 'status', 'sla', 'webdesign', 'badge'] as $n) {
            $add($n);
        }

        /*
        | صفحاتی که فعلاً فقط روی **سرور** روت دارند (کدِ سرور از مخزن جلوتر است —
        | مثل /developers/tunnel که site:gate کشفش کرد). اعلامِ کور با route()
        | روی نصبی که روت را ندارد کلِ sitemap را ۵۰۰ می‌کند؛ پس هر مسیر اول با
        | روترِ همان نصب match می‌شود و فقط اگر واقعاً پاسخ‌گوست اعلام می‌شود.
        | 🔴 راه‌حلِ ریشه‌ای: آن کد به مخزن برگردد (check-live-state.sh دقیقاً
        | برای دیدنِ همین دریفت است)؛ این بلوک فقط پل است، نه جای دائمی.
        */
        foreach (['/developers/tunnel'] as $serverOnly) {
            foreach (['', '/en', '/tr'] as $lp) {
                try {
                    app('router')->getRoutes()->match(\Illuminate\Http\Request::create($lp.$serverOnly, 'GET'));
                    $urls[] = ['loc' => rtrim(config('app.url'), '/').$lp.$serverOnly, 'lastmod' => null];
                } catch (\Throwable) {
                    // این نصب چنین روتی ندارد — چیزی اعلام نمی‌شود
                }
            }
        }

        /*
        | بخشِ محلی ارومیه — از مرداد ۱۴۰۵ سه‌زبانه است (روت‌های en./tr. ثبت
        | می‌شوند و UrmiaController ترجمهٔ واقعی می‌دهد)، پس مثل بقیه از $add.
        | مقصدِ ۳۰۱های مهاجرتِ servernet.ir؛ مثل webdesign: در منو نیست ولی
        | در نقشهٔ سایت هست.
        */
        /*
        | 🔴 ممیزی نهم (قلم ۲): ارومیه فقط **فارسی** در نقشهٔ سایت.
        |
        | `$add()` روی هر سه زبان حلقه می‌زند و همین بود که ۵۸ نشانیِ
        | `/en/urmia/*` و `/tr/urmia/*` را وارد نقشه کرد. آن مسیرها حالا ۴۱۰
        | می‌دهند — و نشانیِ ۴۱۰ در sitemap یعنی گوگل هر بار می‌رود، خطا
        | می‌گیرد و بودجهٔ خزش را همان‌جا می‌سوزاند. حذفِ صفحه و ماندنِ نشانی
        | در نقشه، از نگه‌داشتنِ خودِ صفحه بدتر است.
        |
        | ⚠️ `$addFa` عمداً جدا نوشته شده و از `$add` استفاده نمی‌کند: هر
        | تغییری در `$add` نباید ناخواسته ارومیه را دوباره سه‌زبانه کند.
        */
        $addFa = function (string $name, string|array $params = []) use (&$urls) {
            $urls[] = ['loc' => route($name, $params), 'lastmod' => null];
        };

        $addFa('urmia.hub');
        foreach (array_keys((array) config('urmia.pages')) as $slug) {
            $addFa('urmia.page', $slug);
        }
        foreach (array_keys((array) config('urmia.cities')) as $slug) {
            $addFa('urmia.city', $slug);
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

        /*
        | فروشگاهِ قطعات — هاب، هر دسته، هر صفحهٔ نسل، و هر قطعهٔ فعال.
        |
        | 🔴 صفحاتِ دسته و نسل عمداً این‌جا هستند و نه فقط صفحهٔ هاب: هرکدام
        | متنِ یکتا و کلیدواژهٔ خودشان را دارند («رم سرور ECC»، «قطعات HP نسل
        | ۹») و بدونِ نقشه، تنها راهِ کشفشان مگامنو است — که خزنده لزوماً
        | دنبال نمی‌کند.
        |
        | ⚠️ `parts.compare` عمداً **نیست**: خروجی‌اش ترکیبِ انتخابیِ کاربر است
        | و بی‌نهایت آدرسِ تقریباً یکسان می‌سازد. صفحه‌اش هم `noindex` است.
        |
        | ⚠️ پشتِ `hasTable` است تا نقشه روی سرورِ مهاجرت‌نخورده ۵۰۰ ندهد.
        */
        $add('parts.index');
        foreach (array_keys(\App\Models\ServerPart::CATEGORIES) as $cat) {
            $add('parts.category', $cat);
        }
        foreach (array_keys((array) config('hp_generations', [])) as $gen) {
            $add('servers.generation', $gen);
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('server_parts')) {
            foreach (\App\Models\ServerPart::active()->orderBy('sort')->get(['category', 'slug', 'updated_at']) as $part) {
                $add('parts.show', [$part->category, $part->slug], $part->updated_at?->toDateString());
            }
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
        /*
        | Storage Box is backed by the live object-storage product table, not
        | config/catalog/cloud.php.  It is nevertheless a public, canonical
        | landing page linked from Block Storage.  The generic catalogue loop
        | below can therefore never discover it; declare the three localized
        | URLs explicitly so site:gate does not find a public page missing from
        | the sitemap after every deployment.
        */
        $add('cloud.location', 'storage-box');
        // صفحهٔ فرودِ «سرور مجازی ساعتی» — روتِ صریح، خارج از کاتالوگِ config
        $add('vps.hourly');
        // سرورِ گرافیکی — خطِ محصولِ مستقل، روتِ صریح
        $add('gpu');

        if (\Illuminate\Support\Facades\Schema::hasTable('cloud_locations')) {
            /*
            | فقط مکان‌هایی که واقعاً پلنِ قابل‌فروش دارند. ردیف‌های میراثیِ
            | syncِ قدیمی (ru-intel، de-shared، ws-dedicated…) فعال مانده‌اند
            | ولی «۰ پلن، از —» رندر می‌کنند؛ Search Console همین‌ها را
            | «Duplicate, Google chose different canonical» گزارش کرد (۲۱
            | صفحه، ممیزی ۲۴ اوت ۲۰۲۶). صفحهٔ خالی در نقشهٔ سایت = دعوتِ
            | خزنده به محتوای هیچ.
            */
            $sellableLocs = \App\Models\CloudPlan::query()->sellable()
                ->distinct()->pluck('location_code')->all();
            foreach (\App\Models\CloudLocation::where('is_active', true)
                ->whereIn('code', $sellableLocs)->pluck('code') as $code) {
                /*
                | ممیزی ۷، یافتهٔ ۱: نسلِ دومِ همان باگ — کدِ کشورِ دوبل
                | (de-de-dedicated…) که **پلنِ فروختنی دارد** و برای همین از
                | فیلترِ بالا رد می‌شود. صفحه‌اش ۳۰۱ به صفحهٔ کشور است؛
                | sitemap فقط URLِ ۲۰۰ اعلام می‌کند.
                */
                if (\App\Models\CloudLocation::isLegacyCode((string) $code)
                    || \App\Models\CloudLocation::isGpuCode((string) $code)) {
                    continue;
                }
                $add('cloud.location', $code);
            }
        }
        $add('blog.index');

        /*
        | فهرستِ هر **دستهٔ** بلاگ (`/blog?cat=seo`).
        |
        | تا شهریور ۱۴۰۵ این‌ها canonicalِ خودشان را نداشتند (لایوت از
        | `url()->current()` می‌سازد و آن رشتهٔ پرس‌وجو را دور می‌ریزد) پس
        | همه خودشان را `/blog` اعلام می‌کردند و Search Console زیرِ
        | «Alternate page with proper canonical tag» می‌گذاشتشان. حالا
        | `BlogController::listingSeo()` هرکدام را خودcanonical می‌کند و
        | صفحهٔ فرودِ واقعیِ یک موضوع‌اند («هاست»، «سئو»، «امنیت») — پس در
        | نقشهٔ سایت هم جا دارند.
        |
        | ⚠️ صفحه‌های **صفحه‌بندی** (`?page=2`) عمداً این‌جا نیستند: نقشهٔ
        | سایت جای مقصدهای محتوایی است، و خودِ آن صفحه‌ها از فهرست لینک
        | دارند. اعلامشان فقط بودجهٔ خزش را بین ۱۵ آدرسِ واسطه پخش می‌کرد.
        |
        | ⚠️ دستهٔ خالی اعلام نمی‌شود — صفحهٔ بی‌نتیجه در نقشهٔ سایت یعنی
        | دعوتِ خزنده به محتوای هیچ (همان قاعدهٔ مکان‌های ابری بالاتر).
        */
        try {
            $blogRepo = app(\App\Services\BlogRepository::class);
            foreach (array_keys((array) config('blog.categories', [])) as $catSlug) {
                if ($blogRepo->byCategory((string) $catSlug) === []) {
                    continue;
                }
                foreach ($locales as $prefix) {
                    $urls[] = ['loc' => route($prefix.'blog.index').'?cat='.rawurlencode((string) $catSlug), 'lastmod' => null];
                }
            }
        } catch (\Throwable) {
            // جدولِ مهاجرت‌نشده — مثل خودِ بلاگ، نقشه ۵۰۰ نمی‌شود
        }

        /*
        | پست‌ها به‌ازای هر زبان فقط اگر ترجمهٔ همان زبان موجود باشد. بی‌این،
        | نقشهٔ سایت /en/blog/x را تبلیغ می‌کرد در حالی که find() آن را با
        | fallbackِ فارسی رندر می‌کند — یعنی صفحهٔ «انگلیسیِ» تمام‌فارسی به
        | خزنده معرفی می‌شد (بررسی سراسری زبان، مرداد ۱۴۰۵). fa همیشه هست؛
        | با رسیدنِ ترجمه (کرون یا stepِ translate) نسخهٔ آن زبان خودکار
        | برمی‌گردد. $add این‌جا به‌کار نمی‌آید چون همهٔ زبان‌ها را یک‌جا می‌زند.
        */
        try {
            $blogPosts = \App\Models\Post::query()
                ->where('type', 'blog')->where('status', 'published')
                ->with('translations')
                ->orderByDesc('published_at')->orderByDesc('id')->get();
            foreach ($blogPosts as $p) {
                $date = optional($p->published_at ?? $p->created_at)->toDateString();
                foreach (\App\Providers\AppServiceProvider::LOCALES as $code => $prefix) {
                    if ($code === 'fa' || $p->translations->contains('locale', $code)) {
                        $urls[] = ['loc' => route($prefix.'blog', $p->slug), 'lastmod' => $date];
                    }
                }
            }
        } catch (\Throwable) {
            // جدولِ مهاجرت‌نشده — بلاگ از نقشه می‌افتد، صفحه ۵۰۰ نمی‌شود (قاعدهٔ BlogRepository)
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

        /*
        | `/llms.txt` — سه ممیزیِ پیاپی غیبتش در نقشهٔ سایت را علامت زدند.
        | تک‌زبانه است (متنش انگلیسی و برای مدلِ زبانی)، پس مستقیم و بدونِ
        | پیشوندِ زبان اضافه می‌شود، نه از مسیرِ `$add()`.
        */
        $urls[] = ['loc' => rtrim(config('app.url'), '/').'/llms.txt', 'lastmod' => null];

        /*
        | صفحاتِ سفارش — ممیزی ۷ قلم ۲ («۶۴ از ۶۴ در sitemap») تصمیمِ
        | «فقط پرچم‌دار»ِ ممیزی ۶ را برگرداند: هر SKUی فعال صفحهٔ سفارشِ
        | ایندکس‌پذیر با عنوانِ تراکنشیِ یکتا و اسکیمای Product/AggregateOffer
        | دارد — تنها قلمِ رودمپ که مستقیم روی درآمد اثر دارد.
        */
        foreach (\App\Models\Product::orderableSlugs() as $sku) {
            $add('order.summary', $sku);
        }

        $add('ai.home');
        $add('identity.home');
        $add('identity.docs');
        $add('identity.terms');
        $add('ai.models');
        $add('ai.docs');
        foreach (\App\Models\AiModel::where('status', '!=', 'deprecated')->get() as $aiModel) {
            // Informational pages can be indexed while a route is preparing. Retired models cannot.
            if (\App\Services\Ai\AiModelPage::indexable($aiModel)) {
                $add('ai.model', $aiModel->slug);
            }
        }

        $add('official');

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

    /** Cached real registration totals for each TLD's minimum term. */
    private function tlds(): array
    {
        $featured = (array) config('servernet.featured_tlds', []);
        $prices = app(TldPriceBook::class)->cachedFullForTlds($featured);
        $out = [];
        foreach ($featured as $tld) {
            if (! DomainSearch::sells($tld)) {
                continue;
            }
            $row = $prices[strtolower(ltrim($tld, '.'))] ?? [];
            if ((int) ($row['register'] ?? 0) <= 0 || (int) ($row['min_years'] ?? 0) < 1) {
                continue;
            }
            $out[] = ['tld' => $tld, 'irt' => (int) $row['register'],
                'final_irt' => true, 'min_years' => (int) $row['min_years']];
        }
        return $out;
    }
}
