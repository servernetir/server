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

        /*
        | آیا این صفحه **موجودیِ زندهٔ** قابلِ فروش دارد؟
        |
        |   null  → این دسته اصلاً کاتالوگِ زنده ندارد (هاست، دامنه، سرویس)
        |   true  → پلن‌های روی صفحه همان‌هایی‌اند که همین حالا می‌شود خرید
        |   false → کشور هست، صفحه هست، ولی هیچ پلنِ **فروختنی** ندارد
        |
        | 🔴 حالتِ `false` تا امروز بی‌صدا بود: صفحه همان کارت‌های config را با
        | «استعلام از واحد فروش» نشان می‌داد و بازدیدکننده هیچ‌وقت نمی‌فهمید چرا
        | قیمت نیست و چه‌کار باید بکند. کارفرما هم همین را «نشون نمیده» دید.
        | حالا ویو یک توضیحِ صریحِ سه‌زبانه می‌گذارد — بی‌آنکه قیمتی از خودش
        | بسازد.
        |
        | ⚠️ این حالت **قابلِ انتظار** است، نه استثنا: `scopeSellable` هر پلنی را
        | که `price_irt = 0` باشد بیرون می‌گذارد، و `price_irt` وقتی صفر می‌شود
        | که نرخِ روزِ یورو در دسترس نباشد. یعنی یک قطعیِ چنددقیقه‌ایِ نرخ، کلِ
        | کاتالوگِ یک کشور را از صفحه برمی‌دارد.
        */
        $liveStock = null;

        if (in_array($category, ['vps', 'dedicated'], true)) {
            [$livePlans, $planHrefs] = $this->livePlansFor($category, $slug);

            $liveStock = $livePlans !== [];

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
            // نامِ پلن روی این صفحات همان پسوند است («.com»، «.ir ×۵»)
            $tldOf = fn ($p) => strtolower(ltrim(trim(explode(' ', (string) ($p['name'] ?? ''))[0]), '.'));

            $live = [];

            try {
                $live = app(\App\Services\Domain\TldPriceBook::class)
                    ->forTlds(array_map($tldOf, $product['plans']));
            } catch (\Throwable) {
                // بی‌قیمت بهتر از قیمتِ غلط است؛ دکمهٔ «بررسی و ثبت» می‌مانَد
            }

            $product['plans'] = array_map(function ($p) use ($tldOf, $live) {
                $price = $live[$tldOf($p)] ?? null;

                unset($p['eur'], $p['pid']);

                // دکمه همیشه به جستجو می‌رود: «‎.com» را نمی‌شود خرید، یک
                // **نام** را می‌شود. بی‌این، شاخهٔ پیش‌فرضِ ویو سراغِ
                // `buy_url($p['pid'])` می‌رفت که همین بالا حذفش کردیم.
                $p['search_btn'] = true;

                if ($price !== null) {
                    // قیمتِ **واقعیِ امروز** — نه عددِ سخت‌کدِ config
                    $p['irt'] = $price;
                    $p['contact'] = false;
                    $p['quote'] = false;
                    $p['live'] = true;

                    return $p;
                }

                // رجیسترار جواب نداد یا این پسوند را نمی‌فروشد
                unset($p['irt']);
                $p['contact'] = true;
                $p['quote'] = true;

                return $p;
            }, $product['plans']);
        }

        // ویو در چند جا روی `plans` حلقه می‌زند و `count()` می‌گیرد. محصولی که
        // این کلید را نداشته باشد ۵۰۰ می‌داد — و ۵۰۰ روی صفحهٔ محصول یعنی
        // صفحهٔ سفید. آرایهٔ خالی به‌جایش «حالتِ خالیِ» صریح را نشان می‌دهد.
        $product['plans'] = array_values((array) ($product['plans'] ?? []));

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
            // null = بی‌ربط · true = موجودیِ زنده · false = کشور بدونِ پلنِ فروختنی
            'liveStock' => $liveStock,
            /*
            | 🔴 کدام پکیجِ هاست **واقعاً** سفارش‌پذیر است.
            |
            | ویو تا امروز برای هر ردیف بی‌قیدوشرط لینکِ `account.order` می‌ساخت.
            | اگر آن پکیج در دیتابیس نبود یا غیرفعال بود، مشتری قیمت را می‌دید،
            | «انتخاب» را می‌زد، و بی‌هیچ پیامی در صفحهٔ اول سر درمی‌آورد.
            |
            | ⚠️ همان منبعی خوانده می‌شود که قیمت را هم می‌دهد، پس نمی‌تواند از
            | آن جدا بیفتد.
            */
            'orderable' => \Illuminate\Support\Facades\Schema::hasTable('products')
                ? \App\Models\Product::where('is_active', true)->pluck('slug')->flip()->all()
                : [],
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

        /*
        | یک پرس‌وجو برای هم کد و هم **برچسبِ نمایشیِ** شهر.
        |
        | قبلاً دو پرس‌وجو بود (یکی `pluck('code')` و یکی `whereIn` در انتها) و
        | دومی فقط شهرهایی را می‌گرفت که ردیف داشتند — یعنی نامِ شهرِ ناموجود
        | اصلاً در دسترس نبود و «شهرِ ناموجود را صادقانه نشان بده» ناممکن می‌شد.
        */
        $locations = \App\Models\CloudLocation::where('country', $iso)
            ->where('is_active', true)->orderBy('sort')->get();

        if ($locations->isEmpty()) {
            return [[], []];
        }

        /*
        | 🔴 چرا `shelf()` و نه `offers()`.
        |
        | `offers()` از `scopeSellable` می‌خوانَد، پس شهری که موجودی‌اش تمام شده
        | **اصلاً به صفحه نمی‌رسد** — نه به‌عنوان ناموجود، بلکه اصلاً نه. قاعدهٔ
        | این پروژه برعکسش است: موجودیِ پنهان باگ است، نه نظم.
        |
        | `shelf()` همان گروه‌بندیِ اسلاگ‌محورِ `offers()` را دارد ولی ردیف‌هایی را
        | که فقط **گذرا** فروختنی نیستند (ناموجود / بی‌قیمت) نگه می‌دارد. حالتِ
        | `off` (تصمیمِ مدیر یا زیرساختِ خاموش) همان‌جا در پرس‌وجو کنار می‌رود و
        | هرگز دیده نمی‌شود.
        |
        | ⚠️ `scopeSellable` دست‌نخورده می‌مانَد: مسیرِ **سفارش** از همان می‌خوانَد و
        | گشادکردنش یعنی فروشِ سروری که نمی‌توانیم تحویل دهیم.
        */
        $sellableByCity = [];      // code => Collection<slug, CloudPlan>  (قابلِ خرید)
        $blockedByCity = [];       // code => [specKey => CloudPlan]       (گذرا ناموجود)

        foreach ($locations as $loc) {
            $code = (string) $loc->code;
            $shelf = \App\Models\CloudPlan::shelf($code);

            $sellableByCity[$code] = $shelf->filter(fn ($p) => $p->blockedReason() === null);

            foreach ($shelf as $p) {
                if ($p->blockedReason() === null) {
                    continue;
                }

                $blockedByCity[$code][self::specKey($p)] ??= $p;
            }
        }

        /*
        | 🔴 گروه‌بندیِ **مشخصات‌محور** — نه ادغامِ کورِ بین‌شهری.
        |
        | آنچه یک بار (به‌درستی) برداشته شد: `unique()` روی همهٔ شهرها، که پاریس و
        | لیون و مارسیِ هم‌مشخصات را یکی می‌کرد و دو تا را **حذف** می‌کرد. مشتری
        | دقیقاً بین شهرها انتخاب می‌کند (تأخیرِ شبکه و مکانِ داده فرق دارد)، پس آن
        | حذف یعنی موجودیِ واقعی بی‌خطا و بی‌لاگ و با کدِ ۲۰۰ پنهان می‌مانْد؛ صفحهٔ
        | آلمان از ده‌ها پلن فقط ۷ تا نشان می‌داد.
        |
        | آنچه این‌جا می‌شود چیزِ دیگری است و هیچ‌چیز را حذف نمی‌کند: هم‌مشخصات‌ها
        | **یک ردیف** می‌شوند و شهر به یک **انتخاب داخلِ همان ردیف** تبدیل می‌شود.
        | هر شهر لینکِ خریدِ خودش، اسلاگِ خودش و قیمتِ خودش را نگه می‌دارد. صفحهٔ
        | ایران ۱۴۶ ردیف داشت چون هر پلن چهار بار (یک بار به ازای هر شهر) تکرار
        | می‌شد؛ حالا همان موجودی در یک‌چهارمِ ردیف‌ها، بی‌آنکه چیزی کم شود.
        |
        | ⚠️ کلیدِ گروه از فیلدهای **خام** ساخته می‌شود، نه از برچسب‌های نمایشی:
        | با `cloud_traffic_unlimited = 1` همهٔ ردیف‌ها «نامحدود» چاپ می‌کنند، پس
        | گروه‌بندی روی برچسب، ۱ ترابایت و ۲۰ ترابایت را یکی می‌کرد.
        |
        | ⚠️ ادغامی که **باید** بماند و همچنان هست: درونِ یک شهر، `shelf()`/`offers()`
        | ردیف‌های هم‌اسلاگ را یکی می‌کند و ارزان‌ترین را نگه می‌دارد. یعنی دو
        | زیرساخت با همان ۴ هسته و ۴ گیگ در فرانکفورت = یک ردیف، و سفیدبرچسبی
        | دست‌نخورده می‌مانَد.
        */
        $groups = [];
        $ord = 0;

        foreach ($sellableByCity as $code => $rows) {
            foreach ($rows as $p) {
                $k = self::specKey($p);

                if (! isset($groups[$k])) {
                    $groups[$k] = ['rep' => $p, 'sell' => [], 'off' => [], 'ord' => $ord++];
                }

                $cur = $groups[$k]['sell'][$code] ?? null;

                if ($cur === null || (int) $p->price_irt < (int) $cur->price_irt) {
                    $groups[$k]['sell'][$code] = $p;
                }

                if ((int) $p->price_irt < (int) $groups[$k]['rep']->price_irt) {
                    $groups[$k]['rep'] = $p;
                }
            }
        }

        if ($groups === []) {
            return [[], []];
        }

        // شهری که همین مشخصات را دارد ولی الان ناموجود است — **حذف نمی‌شود**،
        // در انتخابگر می‌آید و صریح می‌گوید در دسترس نیست.
        foreach ($groups as $k => $g) {
            foreach ($locations as $loc) {
                $code = (string) $loc->code;

                if (isset($g['sell'][$code]) || ! isset($blockedByCity[$code][$k])) {
                    continue;
                }

                $groups[$k]['off'][$code] = (string) $blockedByCity[$code][$k]->blockedReason();
            }
        }

        /*
        | 🔴 حذفِ پلن‌های مغلوب — قاعدهٔ «چیزی که هیچ‌کس نباید بخرد را نشان نده».
        |
        | نمونهٔ واقعی از صفحهٔ آلمان پیش از این تغییر:
        |     ۲ هسته · ۲ گیگ  →  ۱٬۳۷۰٬۰۰۰
        |     ۱ هسته · ۲ گیگ  →  ۲٬۷۴۰٬۰۰۰   ← نصفِ پردازنده، دو برابرِ قیمت
        |
        | ردیفِ دوم برای هیچ مشتری‌ای انتخابِ درستی نیست، ولی روی صفحه بود و به
        | هر بازدیدکننده می‌گفت «قیمت‌های این‌جا حساب‌وکتاب ندارد».
        |
        | ⚠️ **ترتیب عوض شد و این عمدی است:** قبلاً prune روی ردیف‌های تک‌شهری
        | می‌دوید، پس دو شهر با مشخصاتِ **یکسان** و قیمتِ متفاوت هم‌دیگر را حذف
        | می‌کردند و شهرِ گران‌تر پیش از رسیدن به ویو ناپدید می‌شد — یعنی همان
        | «موجودیِ پنهان». حالا اول گروه‌بندیِ مشخصات‌محور انجام می‌شود و prune روی
        | **نمایندهٔ** هر گروه (ارزان‌ترین شهرش) می‌دود. نتیجه:
        |   · مشخصاتِ یکسان هرگز هم‌دیگر را حذف نمی‌کنند (یک گروه‌اند، نه دو ردیف)
        |   · مشخصاتِ واقعاً مغلوب همچنان حذف می‌شوند، دقیقاً مثل قبل
        | خودِ `CloudDominance` دست‌نخورده است؛ فقط دامنهٔ فراخوانی‌اش عوض شد.
        |
        | جزئیاتِ قاعده و آنچه عمداً مقایسه نمی‌شود، در `CloudDominance`.
        */
        $reps = collect(array_map(fn ($g) => $g['rep'], $groups));

        $keptIds = [];

        foreach (\App\Services\Cloud\CloudDominance::prune($reps->values()) as $rep) {
            $keptIds[spl_object_id($rep)] = true;
        }

        $groups = array_filter($groups, fn ($g) => isset($keptIds[spl_object_id($g['rep'])]));

        if ($groups === []) {
            return [[], []];
        }

        // پیش‌فرضِ چیدمان: ارزان به گران (کلیدِ چهارم فقط برای پایداریِ ترتیب)
        uasort($groups, fn ($x, $y) => [
            (int) $x['rep']->price_irt, (int) $x['rep']->vcpu, (int) $x['rep']->ram_mb, $x['ord'],
        ] <=> [
            (int) $y['rep']->price_irt, (int) $y['rep']->vcpu, (int) $y['rep']->ram_mb, $y['ord'],
        ]);

        /*
        | 🔴 فیلترِ نوعِ پردازنده هم برداشته شده است.
        |
        | قبلاً `/vps/*` فقط اشتراکی می‌گرفت و `/dedicated/*` فقط اختصاصی، پس
        | صفحهٔ کشور **هرگز** نمی‌توانست هر دو را با هم نشان دهد. حالا هر دو
        | می‌آیند و ویو در دو جدولِ جدا نشانشان می‌دهد؛ مشتری یک صفحه باز
        | می‌کند و کلِ موجودیِ آن کشور را می‌بیند.
        */

        $cityLabels = [];

        foreach ($locations as $loc) {
            $cityLabels[(string) $loc->code] = $loc->cityLabel();
        }

        $plans = [];
        $hrefs = [];
        $base = lroute('account.cloud.store');
        $i = 0;

        foreach ($groups as $g) {
            $p = $g['rep'];

            /*
            | انتخابگرِ شهر. ترتیب از `sort`ِ خودِ مکان‌ها می‌آید تا در همهٔ
            | ردیف‌ها یکسان باشد.
            |
            | ⚠️ هر شهر **اسلاگِ خودش** را می‌برد، نه فقط کدِ مکان را:
            | `CloudNaming::planSlug` کدِ مکان را داخلِ اسلاگ می‌گذارد، پس عوض‌کردنِ
            | تنها `?location=` یک لینکِ مرده می‌سازد که تسویه با `ui.cvb_e_plan`
            | ردش می‌کند.
            */
            $picker = [];
            $prices = [];

            foreach ($locations as $loc) {
                $code = (string) $loc->code;
                $label = $cityLabels[$code] ?? $code;

                if (isset($g['sell'][$code])) {
                    $c = $g['sell'][$code];
                    $irt = (int) $c->price_irt;
                    $prices[$irt] = true;

                    $picker[] = [
                        'code'    => $code,
                        'label'   => $label,
                        'ok'      => true,
                        'reason'  => null,
                        'irt'     => $irt,
                        'price_f' => site_price(['irt' => $irt, 'eur' => round(((int) $c->price_eur_cents) / 100, 2)]),
                        'href'    => $base.'?location='.urlencode($code).'&plan='.urlencode((string) $c->slug),
                    ];

                    continue;
                }

                if (isset($g['off'][$code])) {
                    $picker[] = [
                        'code'    => $code,
                        'label'   => $label,
                        'ok'      => false,
                        'reason'  => $g['off'][$code],
                        'irt'     => 0,
                        'price_f' => '',
                        'href'    => null,
                    ];
                }
            }

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
                    // شهرِ **سرصفحه‌ای** ردیف = ارزان‌ترین. ستونِ `data-city` روی
                    // همین می‌نشیند و هنوز دقیقاً یک بار در هر ردیف می‌آید.
                    'city'      => $cityLabels[(string) $p->location_code] ?? (string) $p->location_code,
                    'loc_code'  => (string) $p->location_code,
                    'price_n'   => (int) $p->price_irt,
                    // انتخابِ شهر داخلِ همین ردیف
                    'picker'    => $picker,
                    // قیمت بین شهرها فرق دارد ⇒ عددِ نمایش‌داده‌شده «از» است.
                    // ایران امروز یکنواخت است، ولی این را فرض نمی‌گیریم.
                    'from'      => count($prices) > 1,
                ],
            ];

            $hrefs[$i] = $base.'?location='.urlencode((string) $p->location_code)
                .'&plan='.urlencode((string) $p->slug);

            $i++;
        }

        return [$plans, $hrefs];
    }

    /**
     * هویتِ **نمایشیِ** یک پلن — همان کلیدی که CLAUDE.md §۱۰.۵ توصیفش می‌کند:
     * `vcpu-ram-disk-disk_type-traffic-cpu_kind-arch`.
     *
     * ⚠️ مکان عمداً در آن نیست: شهر یک **انتخاب** است، نه یک محصولِ دیگر.
     *
     * ⚠️ فیلدها **خام**‌اند، نه برچسبِ نمایشی. `trafficLabel()` با تنظیمِ
     * `cloud_traffic_unlimited` برای همهٔ ردیف‌ها «نامحدود» برمی‌گرداند و
     * `diskLabel()` اندازه را با نوعِ دیسک قاطی می‌کند — گروه‌بندی روی آنها
     * دو محصولِ واقعاً متفاوت را یکی می‌کرد.
     *
     * ⚠️ اسلاگ جای این کلید را نمی‌گیرد: `CloudNaming::planSlug` فقط
     * هسته/رم/دیسک/مکان را دارد، نه نوعِ دیسک، نه ترافیک، نه معماری.
     */
    private static function specKey(\App\Models\CloudPlan $p): string
    {
        return implode('|', [
            (int) $p->vcpu,
            (int) $p->ram_mb,
            (int) $p->disk_gb,
            strtolower((string) $p->disk_type),
            (int) $p->traffic_gb,
            (string) $p->cpu_kind,
            (string) $p->arch,
        ]);
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
