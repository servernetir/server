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

        // عرضه‌های همهٔ مکان‌های این کشور (تکراری‌ها با اسلاگ یکی می‌شوند)
        $offers = collect();

        foreach ($codes as $code) {
            $offers = $offers->merge(\App\Models\CloudPlan::offers($code));
        }

        // ⚠️ اسلاگِ پلن نامِ مکان را در خود دارد (`cv-2c-4g-40d-ir-tehran`)، پس یک
        // مشخصاتِ یکسان در پنج شهر = پنج اسلاگِ متفاوت. با **مشخصات** یکتا
        // می‌کنیم تا صفحهٔ ایران همان پلن را پنج بار تکرار نکند، و ارزان‌ترینِ هر
        // مشخصات را نگه می‌داریم.
        //
        // 🔴 کلیدِ یکتاسازی عمداً از `slug` مفصل‌تر است. `CloudNaming::planSlug`
        // فقط هسته/رم/دیسک/مکان را می‌گیرد، پس دو محصولِ **واقعاً متفاوت** زیرِ
        // یک اسلاگ می‌افتادند و یکی‌شان از صفحه غیب می‌شد: پردازندهٔ ARM و x86 با
        // مشخصاتِ یکسان (تلهٔ مستندشده در CLAUDE.md)، دیسکِ NVMe و SSD، و ترافیکِ
        // ۲۰ ترابایت و ۱ ترابایت. مشتری این تفاوت‌ها را می‌بیند و بابتشان پول
        // می‌دهد، پس باید ردیفِ جدا داشته باشند.
        $offers = $offers
            ->sortBy('price_irt')
            ->unique(fn ($p) => implode('-', [
                $p->vcpu, $p->ram_mb, $p->disk_gb,
                $p->disk_type, $p->traffic_gb, $p->cpu_kind, $p->arch,
            ]))
            ->sortBy([['vcpu', 'asc'], ['ram_mb', 'asc'], ['disk_gb', 'asc'], ['price_irt', 'asc']])
            ->values();

        // «پردازندهٔ اختصاصی» فقط پلن‌های dedicated؛ سرورِ مجازی فقط اشتراکی.
        $offers = $offers->filter(fn ($p) => $category === 'dedicated'
            ? $p->cpu_kind === 'dedicated'
            : $p->cpu_kind !== 'dedicated')->values();

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
                'row' => [
                    'vcpu'    => (int) $p->vcpu,
                    'ram'     => $p->ramLabel(),
                    'disk'    => $p->diskLabel(),
                    'traffic' => $p->trafficLabel(),
                    'cpu'     => $p->cpuKindLabel(),
                    'city'    => $loc?->cityLabel() ?? (string) $p->location_code,
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
