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
            /*
            |------------------------------------------------------------------
            | 🔴 پسوند از **نامِ بازاریابی** استخراج می‌شد، و پسوندهای الکی می‌ساخت
            |------------------------------------------------------------------
            |
            | شکلِ قبلی اولین واژهٔ نامِ پلن را پسوند می‌گرفت. روی `.com` و `.ir`
            | درست بود، ولی روی صفحاتِ دیگر آشغال می‌ساخت و همان آشغال به
            | رجیسترار فرستاده می‌شد:
            |
            |     /domain/persian    →  IDN .com  →  «idn»       (پسوندِ ناموجود)
            |     /domain/reseller   →  Starter/Business/Enterprise
            |     /domain/backorder  →  Single/Pack/Monitor
            |
            | و `sn7price9check4base.idn` دقیقاً همان استعلامِ تک‌دامنه‌ای است که
            | در پروداکشن `code 199 · An unknown error has occurred! · domains=1`
            | گرفت. یعنی آن ۱۹۹ نه یک هیچکاپِ رجیسترار بود، نه ربطی به سقفِ ۷۰۱
            | داشت: **ما پسوندی می‌پرسیدیم که وجود ندارد** — روی حسابی که از قبل
            | به‌خاطرِ تماسِ زیاد علامت خورده.
            |
            | قاعدهٔ تازه: نامِ پلن فقط وقتی پسوند است که با «.» شروع شود. هر
            | چیزِ دیگری اسمِ بستهٔ فروش است و اصلاً استعلام نمی‌شود. پلنی که
            | نامش با پسوندش نمی‌خوانَد، کلیدِ صریحِ `tld` می‌گیرد.
            */
            $tldOf = function ($p): string {
                if (filled($p['tld'] ?? null)) {
                    return strtolower(ltrim(trim((string) $p['tld']), '.'));
                }

                $first = trim(explode(' ', (string) ($p['name'] ?? ''))[0]);

                return str_starts_with($first, '.')
                    ? strtolower(ltrim($first, '.'))
                    : '';
            };

            $live = [];

            try {
                $ask = array_values(array_filter(array_map($tldOf, $product['plans'])));

                $live = $ask === []
                    ? []
                    : app(\App\Services\Domain\TldPriceBook::class)->forTlds($ask);
            } catch (\Throwable) {
                // بی‌قیمت بهتر از قیمتِ غلط است؛ دکمهٔ «بررسی و ثبت» می‌مانَد
            }

            $product['plans'] = array_map(function ($p) use ($tldOf, $live) {
                $tld = $tldOf($p);
                $price = $tld === '' ? null : ($live[$tld] ?? null);

                unset($p['tld']);

                /*
                |--------------------------------------------------------------
                | 🔴 فقط پلنی که واقعاً یک **پسوند** است دستکاری می‌شود
                |--------------------------------------------------------------
                |
                | `$tldOf` از قبل تفکیک را بلد بود و کامنتش هم می‌گفت «چیزِ
                | دیگری اسمِ بستهٔ فروش است و اصلاً استعلام نمی‌شود» — ولی سه خطِ
                | بعد، همان بستهٔ فروش هم `pid`ش حذف و دکمه‌اش به جستجوی دامنه
                | فرستاده می‌شد.
                |
                | نتیجه‌اش روی `/domain/reseller` این بود: هر سه کارتِ «شارژ
                | اعتبار» یک دکمهٔ «بررسی و ثبت» می‌گرفتند که به `/domains`
                | می‌رفت. یعنی صفحهٔ فروشِ پنلِ نمایندگی **هیچ راهی به خودِ
                | پنلِ نمایندگی نداشت** — کاربر روی «انتخاب» می‌زد و در جستجوی
                | دامنه می‌افتاد. کدِ ۲۰۰، بی‌خطا، و قیفِ فروش کاملاً قطع.
                |
                | ⚠️ برای پسوندها رفتار **مو نمی‌خورد**: «.com» را نمی‌شود خرید،
                | یک **نام** را می‌شود؛ پس آن‌ها همچنان به جستجو می‌روند.
                |
                | ⚠️ و شرط عمداً `route`/`contact` را هم می‌خواهد، نه فقط
                | «پسوند نیست». نسخهٔ اول فقط `$tld === ''` را می‌سنجید و
                | `/domain/backorder` را **بدتر** کرد: پلن‌هایش هم پسوند
                | نیستند، پس از این شاخه رد شدند و به `url`ِ پیش‌فرضِ `$mkTld`
                | افتادند — یعنی `cart.php` روی WHMCSِ بیرونی، همان بن‌بستی که
                | این تغییر قرار بود جلویش را بگیرد. دکمهٔ جستجو برای آن صفحه
                | مقصدِ ایده‌آلی نیست ولی دستِ‌کم **زنده** است.
                |
                | پس قاعده این است: پلنی از این دستکاری معاف می‌شود که خودش
                | صریح گفته باشد کجا می‌رود.
                */
                if ($tld === '' && (isset($p['route']) || ! empty($p['contact']))) {
                    return $p;
                }

                unset($p['eur'], $p['pid']);

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

        foreach ($locations as $loc) {
            $code = (string) $loc->code;

            $sellableByCity[$code] = \App\Models\CloudPlan::shelf($code)
                ->filter(fn ($p) => $p->blockedReason() === null);
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

        /*
        | شهرِ ناموجود دیگر در صفحه نمی‌آید (نه به‌عنوان تراشهٔ «ناموجود»، نه
        | هیچ شکلِ دیگر). پیش از این می‌آمد، با این استدلال که «موجودیِ پنهان باگ
        | است» — استدلالِ درستی برای **مشخصات**، ولی نه برای **شهر**:
        |
        |   · مشخصاتی که هیچ‌جا فروختنی نیست، همچنان هیچ ردیفی نمی‌سازد و
        |     همچنان چیزی پنهان نمی‌شود (گروه‌ها فقط از ردیف‌های فروختنی ساخته
        |     می‌شوند).
        |   · ولی «برلین ناموجود» کنارِ «برلین موجود» هیچ اطلاعاتی به مشتری
        |     نمی‌داد و فقط تناقض نشان می‌داد.
        |
        | ⚠️ `shelf()` عمداً به `offers()` تغییر نکرد: تفاوتشان همین ردیف‌های گذرا
        | ناموجود است و اگر روزی خواستیم دوباره نشانشان دهیم، فقط همین بخش
        | برمی‌گردد، نه کلِ پرس‌وجو.
        */

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
            | 🔴 هر ردیف دقیقاً **یک** شهر دارد: ارزان‌ترین. (تصمیمِ کارفرما)
            |
            | ═══ باگی که این را لازم کرد ═══
            |
            | پیش از این هر شهرِ هم‌مشخصات یک تراشه می‌شد و شهرِ ناموجود هم با
            | برچسبِ «ناموجود» می‌ماند. روی `/vps/germany` نتیجه این بود:
            |
            |     برلین ناموجود  ·  برلین
            |
            | یعنی صفحه یک شهر را هم‌زمان موجود و ناموجود اعلام می‌کرد. علتش دو
            | **کدِ مکانِ متفاوت** بود که هر دو «برلین» نمایش داده می‌شدند:
            | `de-de-hi-cpu` شهر نداشت و `CloudLocation::cityLabel()` پایتختِ
            | کشور را جایش گذاشت — یعنی دو مکانِ متفاوت یک **هویتِ نمایشیِ
            | یکسان** گرفتند. (خودِ آن کدِ بدشکل جدا باید اصلاح شود.)
            |
            | ═══ قاعده ═══
            |
            |   · یک شهر در هر ردیف — همان که قیمت و لینکِ خریدِ ردیف از اوست
            |   · دو زیرساخت، یک شهر، مشخصاتِ یکسان  ⇒ ارزان‌تر
            |   · دو شهر با قیمتِ برابر                ⇒ یکی (ترتیبِ `sort`ِ مکان)
            |   · دو شهر با قیمتِ متفاوت               ⇒ ارزان‌تر
            |
            | ⚠️ تراشه از **خودِ نماینده** ساخته می‌شود، نه از پیمایشِ فهرستِ
            | مکان‌ها. پس تکرارِ برچسب ساختاراً ناممکن است — حتی اگر فردا دو کدِ
            | دیگر هم به یک نام برسند، این ردیف باز هم یک شهر نشان می‌دهد.
            | رفعِ داده‌ایِ تنها این تضمین را نمی‌داد.
            |
            | ⚠️ بهایش صریح: انتخابِ شهر از صفحهٔ **بازاریابی** برداشته شد.
            | موجودی پنهان نمی‌شود — فروشگاهِ کنسول همچنان همهٔ مکان‌ها را دارد و
            | هر مشخصاتِ متمایز هنوز ردیفِ خودش را دارد؛ فقط این صفحه به‌جای
            | فهرستِ شهرها، ارزان‌ترین را تبلیغ می‌کند.
            |
            | ⚠️ `$p` (نمایندهٔ گروه) در ساختِ گروه‌ها ارزان‌ترینِ همهٔ شهرها
            | انتخاب شده، پس این تراشه همیشه با `price_n` و `loc_code`ِ همین
            | ردیف می‌خواند. اگر روزی انتخابِ نماینده عوض شد، این هم باید عوض شود.
            */
            $code = (string) $p->location_code;

            $picker = [[
                'code'    => $code,
                'label'   => $cityLabels[$code] ?? $code,
                'ok'      => true,
                'reason'  => null,
                'irt'     => (int) $p->price_irt,
                'price_f' => site_price([
                    'irt' => (int) $p->price_irt,
                    'eur' => round(((int) $p->price_eur_cents) / 100, 2),
                ]),
                'href'    => $base.'?location='.urlencode($code).'&plan='.urlencode((string) $p->slug),
            ]];

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
                    // همیشه یک عضو: شهرِ ارزان‌ترین. (بالای همین حلقه چرا)
                    'picker'    => $picker,
                    /*
                    | ⚠️ همیشه false — و این یک ثابتِ بی‌مصرف نیست.
                    |
                    | «از» یعنی «قیمت بین شهرها فرق دارد». حالا که هر ردیف یک
                    | شهر دارد، عددِ نشان‌داده‌شده **قیمتِ قطعیِ همان شهر** است و
                    | «از» به مشتری دروغ می‌گفت. کلید عمداً می‌مانَد تا اگر روزی
                    | انتخابِ چندشهری برگشت، ویو و تست‌ها دست‌نخورده کار کنند.
                    */
                    'from'      => false,
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
