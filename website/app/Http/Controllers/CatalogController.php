<?php

namespace App\Http\Controllers;

use App\Services\Whmcs;
use Illuminate\View\View;

/**
 * صفحات محصول: هاست (config/hosting.php) و بقیه دسته‌ها (config/catalog.php).
 * features/faqs هر محصول می‌توانند کلید pool مشترک یا آرایه اختصاصی باشند.
 */
class CatalogController extends Controller
{
    /** بیشترین پلنی که در صفحهٔ بازاریابیِ یک کشور نشان داده می‌شود. */
    // 🔴 این‌جا قبلاً `MAX_MARKETING_PLANS = 6` بود و هر Nاُمین پلن را برمی‌داشت،
    // پس `/vps/germany` از ده‌ها پلنِ فروختنیِ آلمان فقط ۶ تا نشان می‌داد و بقیه
    // هیچ‌جای سایت دیده نمی‌شدند. حالا سرورِ مجازی/اختصاصی **همهٔ** پلن‌ها را در
    // یک جدول نشان می‌دهد (`$planTable` در ویو).

    public function hosting(string $slug): View
    {
        return $this->render('hosting', $slug);
    }

    public function show(string $category, string $slug): View
    {
        return $this->render($category, $slug);
    }

    private function render(string $category, string $slug): View
    {
        $products = $category === 'hosting'
            ? config('hosting.products')
            : config("catalog.$category");

        abort_unless(is_array($products) && isset($products[$slug]), 404);

        $product = $products[$slug];

        // قیمت‌ها از جدولِ products سوار می‌شوند تا سایت و تسویه یک عدد نشان
        // دهند. بدونِ این، تغییرِ قیمت در پنلِ مدیریت روی صفحهٔ محصول اثر نداشت.
        if ($category === 'hosting' && ! empty($product['plans'])) {
            $product['plans'] = app(\App\Services\CatalogPricing::class)
                ->applyToPlans($slug, $product['plans']);
        }

        // 🔴 سرورِ مجازی/اختصاصی: پلن‌ها از **کاتالوگِ زندهٔ ابری** می‌آیند، نه از
        // config. پیش از این، صفحهٔ کشور قیمتِ سخت‌کدِ config را نشان می‌داد و
        // صفحهٔ پرداخت قیمتِ واقعیِ سینک‌شده را — مشتری «۶۷۰٬۰۰۰» می‌دید و سرِ
        // پرداخت «۲٬۰۰۰٬۰۰۰». حالا هر دو از یک منبع‌اند، پس همیشه یکی‌اند.
        $planHrefs = [];

        if (in_array($category, ['vps', 'dedicated'], true)) {
            [$livePlans, $planHrefs] = $this->livePlansFor($category, $slug);

            if ($livePlans !== []) {
                $product['plans'] = $livePlans;
            } elseif (! empty($product['plans'])) {
                // 🔴 موجودیِ زنده‌ای برای این کشور نداریم. قیمتِ سخت‌کدِ config را
                // **نشان نده** — قیمتی که نمی‌شود خرید، بدتر از نبودِ قیمت است و
                // دقیقاً همان چیزی است که مشتری را سرِ پرداخت شوکه می‌کرد.
                // مشخصات و نامِ پلن برای سئو می‌مانند، ولی عدد جای خود را به
                // «تماس برای قیمت» می‌دهد.
                $product['plans'] = array_map(function ($p) {
                    $p['contact'] = true;
                    unset($p['irt'], $p['eur'], $p['pid']);

                    return $p;
                }, $product['plans']);
            }
        }

        /*
        | 🔴 صفحاتِ دامنه: قیمتِ سخت‌کدِ config را نشان نده.
        |
        | همان درسِ صفحاتِ سرور، این‌بار روی دامنه. منو `.com` را ۱٬۲۹۰٬۰۰۰
        | نشان می‌داد در حالی که استعلامِ زنده ۲٬۰۱۶٬۰۰۰ بود — مشتری قیمتی
        | می‌دید که نمی‌توانست بخرد، و لحظهٔ پرداخت شوکه می‌شد.
        |
        | قیمتِ دامنه ذاتاً نمی‌تواند در config بنشیند: به نرخِ روزِ ارز و
        | قیمتِ لحظه‌ایِ رجیسترار وابسته است و برای هر نام هم فرق می‌کند
        | (پرمیوم). پس عدد جای خود را به دکمهٔ «استعلامِ لحظه‌ای» می‌دهد که به
        | جستجوی واقعی می‌رود؛ مشخصات و متنِ سئو دست‌نخورده می‌مانند.
        */
        if ($category === 'domain' && ! empty($product['plans'])) {
            $product['plans'] = array_map(function ($p) {
                $p['contact'] = true;
                $p['quote'] = true;              // «استعلام» نه «تماس بگیرید»
                unset($p['irt'], $p['eur'], $p['pid']);

                return $p;
            }, $product['plans']);
        }

        $featurePool = config('hosting.feature_pool');
        $faqPool = config('hosting.faq_pool');

        $features = array_values(array_filter(array_map(
            fn ($f) => is_array($f) ? $f : ($featurePool[$f] ?? null),
            $product['features']
        )));

        $faqs = array_values(array_filter(array_map(
            fn ($f) => is_array($f) ? $f : ($faqPool[$f] ?? null),
            $product['faqs']
        )));

        // چیپ‌های TLD فقط برای امضای «جستجوی دامنه» لازم‌اند
        $tlds = ($product['signature']['type'] ?? null) === 'domainsearch' ? $this->tlds() : [];

        return view('pages.hosting', [
            'category' => $category,
            'slug'     => $slug,
            'product'  => $product,
            'features' => $features,
            'faqs'     => $faqs,
            'related'  => array_diff_key($products, [$slug => null]),
            'tlds'     => $tlds,
            // خریدِ سرورِ مجازی/اختصاصی → **کنسولِ خودمان**، نه WHMCSِ بیرونی.
            // مکانِ همین کشور از قبل انتخاب می‌شود تا کاربر دوباره کشور نبیند.
            'cloudStoreHref' => $this->cloudStoreHref($category, $slug),
            // لینکِ اختصاصیِ هر پلن (با پلنِ ازپیش‌انتخاب‌شده) — اگر پلن‌ها زنده باشند
            'planHrefs' => $planHrefs,
        ]);
    }

    /**
     * پلن‌های **واقعیِ** یک کشور از کاتالوگِ ابری، به شکلی که کارت‌های صفحهٔ
     * محصول می‌فهمند + لینکِ خریدِ هر پلن.
     *
     * 🔴 چرا این‌جا: صفحهٔ `/vps/england` قیمتِ سخت‌کدِ config را نشان می‌داد
     * (۶۵۰٬۰۰۰) ولی صفحهٔ پرداخت قیمتِ سینک‌شدهٔ واقعی را (۲٬۰۰۰٬۰۰۰+). دو منبعِ
     * حقیقت یعنی مشتری قیمتی می‌بیند که نمی‌تواند بخرد. حالا هر دو از
     * `CloudPlan::offers()` می‌آیند — همان منبعی که فروشگاهِ کنسول از آن می‌خواند.
     *
     * اگر کشور هنوز پلنِ زنده‌ای ندارد، آرایهٔ خالی برمی‌گردد و صفحه به همان
     * متن/پلنِ config برمی‌گردد (بهتر از صفحهٔ خالی).
     *
     * @return array{0:array<int,array<string,mixed>>,1:array<int,string>}
     */
    private function livePlansFor(string $category, string $slug): array
    {
        $iso = \App\Services\Cloud\CloudCountry::isoForSlug($slug);

        if ($iso === null || ! \Illuminate\Support\Facades\Schema::hasTable('cloud_plans')) {
            return [[], []];
        }

        $codes = \App\Models\CloudLocation::where('country', $iso)
            ->where('is_active', true)->orderBy('sort')->pluck('code')->all();

        if ($codes === []) {
            return [[], []];
        }

        // عرضه‌های **همهٔ** مکان‌های این کشور، بی‌هیچ ادغامِ بین‌شهری.
        $offers = collect();

        foreach ($codes as $code) {
            $offers = $offers->merge(\App\Models\CloudPlan::offers($code));
        }

        /*
        | 🔴 ادغامِ بین‌شهری برداشته شد — و این همان چیزی بود که صفحات کشور را
        | «ناقص» می‌کرد.
        |
        | قبلاً پلن‌های همهٔ شهرهای یک کشور روی هم `unique()` می‌شدند. یعنی
        | پاریس و لیون و مارسی با مشخصاتِ یکسان یکی می‌شدند و دو ردیف حذف. ولی
        | اینها محصولِ تکراری نیستند: مشتری دقیقاً بین شهرها انتخاب می‌کند، چون
        | تأخیرِ شبکه و مکانِ داده فرق دارد. حذفشان یعنی موجودیِ واقعی از چشمِ
        | مشتری پنهان می‌مانْد — بی‌خطا، بی‌لاگ، با کدِ ۲۰۰. صفحهٔ آلمان از
        | ده‌ها پلن فقط ۷ تا نشان می‌داد.
        |
        | ادغامی که **باید** بماند و همچنان هست: درونِ یک شهر، `CloudPlan::offers()`
        | ردیف‌های هم‌مشخصات را با اسلاگ یکی می‌کند و ارزان‌ترین را نگه می‌دارد.
        | یعنی اگر دو زیرساخت در فرانکفورت همان ۴ هسته و ۴ گیگ را بدهند، فقط
        | ارزان‌تر دیده می‌شود — دقیقاً قاعده‌ای که کارفرما خواست، و سفیدبرچسبی
        | هم دست‌نخورده می‌مانَد.
        */
        /*
        | 🔴 حذفِ پلن‌های مغلوب — قاعدهٔ «چیزی که هیچ‌کس نباید بخرد را نشان نده».
        |
        | نمونهٔ واقعی از صفحهٔ آلمان پیش از این تغییر:
        |     ۲ هسته · ۲ گیگ  →  ۱٬۳۷۰٬۰۰۰
        |     ۱ هسته · ۲ گیگ  →  ۲٬۷۴۰٬۰۰۰   ← نصفِ پردازنده، دو برابرِ قیمت
        |
        | ردیفِ دوم برای هیچ مشتری‌ای انتخابِ درستی نیست، ولی روی صفحه بود و به
        | هر بازدیدکننده می‌گفت «قیمت‌های این‌جا حساب‌وکتاب ندارد». یک ردیفِ
        | بی‌فروش به اعتبارِ کلِ کاتالوگ آسیب می‌زد.
        |
        | جزئیاتِ قاعده و آنچه عمداً مقایسه نمی‌شود، در `CloudDominance`.
        */
        $offers = \App\Services\Cloud\CloudDominance::prune($offers)
            ->sortBy([['price_irt', 'asc'], ['vcpu', 'asc'], ['ram_mb', 'asc']])
            ->values();

        /*
        | 🔴 فیلترِ نوعِ پردازنده هم برداشته شد.
        |
        | قبلاً `/vps/*` فقط اشتراکی می‌گرفت و `/dedicated/*` فقط اختصاصی، پس
        | صفحهٔ کشور **هرگز** نمی‌توانست هر دو را با هم نشان دهد. حالا هر دو
        | می‌آیند و ویو در دو جدولِ جدا نشانشان می‌دهد؛ مشتری یک صفحه باز
        | می‌کند و کلِ موجودیِ آن کشور را می‌بیند.
        */

        if ($offers->isEmpty()) {
            return [[], []];
        }

        $plans = [];
        $hrefs = [];
        $base = lroute('account.cloud.store');

        // نامِ شهر برای ستونِ «مکان» — بی‌این، مشتری نمی‌داند این ردیف کجاست و
        // برای انتخابِ تأخیرِ شبکه همین مهم‌ترین ستون است.
        $cities = \App\Models\CloudLocation::whereIn('code', $offers->pluck('location_code')->unique())
            ->get()->keyBy('code');

        foreach ($offers as $i => $p) {
            $loc = $cities[$p->location_code] ?? null;

            $plans[] = [
                'name'    => (string) $p->public_name,
                'irt'     => (int) $p->price_irt,
                'eur'     => round(((int) $p->price_eur_cents) / 100, 2),
                'popular' => $i === 1,          // دومی معمولاً نقطهٔ شیرینِ قیمت است
                'specs'   => [
                    fa_num((int) $p->vcpu).' '.__('ui.cvb_cores'),
                    $p->ramLabel().' '.__('ui.cvb_ram'),
                    $p->diskLabel(),
                    $p->trafficLabel(),
                ],
                // ستون‌های جدولِ کاملِ پلن‌ها. کارت‌ها اینها را نادیده می‌گیرند،
                // پس افزودنشان چیزی را در صفحاتِ هاست خراب نمی‌کند.
                //
                // ⚠️ مقدارهای **خام** (vcpu, ram_mb, price_n) کنارِ برچسب‌های
                // خوانا می‌آیند تا فیلترِ سمتِ کاربر مجبور نباشد متنِ فارسی را
                // پارس کند — «۴ گیگ» را نمی‌شود با عدد مقایسه کرد.
                'row' => [
                    'vcpu'      => (int) $p->vcpu,
                    'ram'       => $p->ramLabel(),
                    'ram_mb'    => (int) $p->ram_mb,
                    'disk'      => $p->diskLabel(),
                    'disk_gb'   => (int) $p->disk_gb,
                    'traffic'   => $p->trafficLabel(),
                    'cpu'       => $p->cpuKindLabel(),
                    'dedicated' => $p->cpu_kind === 'dedicated',
                    'city'      => $loc?->cityLabel() ?? (string) $p->location_code,
                    'loc_code'  => (string) $p->location_code,
                    'price_n'   => (int) $p->price_irt,
                ],
            ];

            $hrefs[$i] = $base.'?location='.urlencode((string) $p->location_code)
                .'&plan='.urlencode((string) $p->slug);
        }

        return [$plans, $hrefs];
    }

    /**
     * لینکِ خریدِ سرورِ مجازی/اختصاصی → فروشگاهِ **کنسولِ خودمان**.
     *
     * چرا: صفحاتِ بازاریابیِ `/vps/*` و `/dedicated/*` تا امروز به WHMCSِ بیرونی
     * (my.servernet) می‌رفتند، ولی فروش و تحویل حالا کاملاً در کنسولِ خودمان است.
     * پس دکمهٔ خرید باید به `‎/account/cloud-store‎` برود — که خودش به
     * console.servernet.cloud ریدایرکت می‌شود.
     *
     * مکان از روی همان کشورِ صفحه انتخاب می‌شود (`/vps/germany` → آلمان)، تا
     * کاربر که یک‌بار کشور را انتخاب کرده، دوباره فهرستِ کشورها را نبیند.
     * اگر کشور مکانِ فعالی نداشت، فروشگاه بی‌پیش‌انتخاب باز می‌شود.
     */
    private function cloudStoreHref(string $category, string $slug): ?string
    {
        if (! in_array($category, ['vps', 'dedicated'], true)) {
            return null;
        }

        $iso = \App\Services\Cloud\CloudCountry::isoForSlug($slug);

        $code = null;

        if ($iso !== null && \Illuminate\Support\Facades\Schema::hasTable('cloud_locations')) {
            $code = \App\Models\CloudLocation::where('country', $iso)
                ->where('is_active', true)->orderBy('sort')->value('code');
        }

        return lroute('account.cloud.store').($code ? '?location='.urlencode((string) $code) : '');
    }

    /** قیمت زنده TLD از WHMCS با fallback به config — همان منطق صفحه اصلی */
    private function tlds(): array
    {
        $pricing = Whmcs::forLocale()->tldPricing();

        if ($pricing === null) {
            return config('servernet.tlds');
        }

        $out = [];
        foreach (config('servernet.featured_tlds') as $tld) {
            if (isset($pricing['prices'][$tld])) {
                $out[] = ['tld' => $tld, 'display' => whmcs_price($pricing['prices'][$tld], $pricing['currency'])];
            }
        }

        if ($out === []) {
            foreach (array_slice($pricing['prices'], 0, 10, true) as $tld => $price) {
                $out[] = ['tld' => $tld, 'display' => whmcs_price($price, $pricing['currency'])];
            }
        }

        return $out;
    }
}
