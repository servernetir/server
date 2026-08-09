<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/vps/iran` — یک ردیف به ازای هر **مشخصات**، و شهر یک انتخاب داخلِ همان ردیف.
 *
 * ═══ چه چیزی خراب بود ═══
 *
 * صفحهٔ زندهٔ ایران ۱۴۶ ردیف داشت. هر پلن **چهار بار** می‌آمد — ارومیه، اصفهان،
 * شیراز، تهران — با مشخصاتِ یکسان و قیمتِ یکسان. جدول ۹۱۶۳ پیکسل بلند بود و
 * کارفرما گزارش داد صفحه غیرقابلِ استفاده است. آلمان ۱۱ ردیف داشت و سالم بود.
 *
 * ═══ چرا رفعِ ساده‌اش خطرناک بود ═══
 *
 * راهِ آسان — `unique()` روی همهٔ شهرها — یک بار امتحان شده و **پس گرفته شده**:
 * پاریس و لیون و مارسی را یکی می‌کرد و دو شهر را حذف. ولی شهر یک محصولِ تکراری
 * نیست؛ تأخیرِ شبکه و مکانِ داده فرق دارد و مشتری دقیقاً بینشان انتخاب می‌کند.
 * نتیجه‌اش این بود که صفحهٔ آلمان از ده‌ها پلن فقط ۷ تا نشان می‌داد — بی‌خطا،
 * بی‌لاگ، با کدِ ۲۰۰. کامنتِ `CatalogController::livePlansFor()` همین را ثبت کرده.
 *
 * ═══ ادعای این فایل ═══
 *
 * 🔴 **هیچ‌چیز نامرئی نشد.** هر (مشخصات × شهر) که پیش از این خریدنی بود، هنوز
 * خریدنی است — با لینکِ تسویهٔ **خودش** (`?location=` و `?plan=`، جفتِ هماهنگ).
 * تعدادِ ردیف کم شد؛ تعدادِ چیزهای قابلِ خرید نه. آخرین تستِ این فایل دقیقاً
 * همین را می‌سنجد: قبل و بعد، روی همان داده.
 */
class CountryPlanCityPickerTest extends TestCase
{
    use RefreshDatabase;

    /** چهار شهرِ واقعیِ صفحهٔ ایران (به ترتیبِ `sort` که ردیف‌ها از آن می‌آیند) */
    private const CITIES = [
        'ir-tehran' => 'تهران',
        'ir-shiraz' => 'شیراز',
        'ir-isfahan' => 'اصفهان',
    ];

    private function cities(array $only = []): void
    {
        $sort = 0;

        foreach (self::CITIES as $code => $city) {
            if ($only !== [] && ! in_array($code, $only, true)) {
                continue;
            }

            CloudLocation::create([
                'code' => $code, 'country' => 'IR', 'city' => $city,
                'is_active' => true, 'sort' => $sort++,
            ]);
        }
    }

    /**
     * پلنِ ایرانی. `$n` هم مشخصات را می‌سازد هم قیمت را، هم‌جهت — وگرنه
     * `CloudDominance` یکی را مغلوب می‌شمارد و تست چیزی را می‌سنجد که روی صفحه
     * نیست.
     *
     * ⚠️ اسلاگ **کدِ مکان را در خود دارد** (همان کاری که `CloudNaming::planSlug`
     * می‌کند). این تصادفی نیست: تسویه پلن را **داخلِ** مکان پیدا می‌کند، پس یک
     * انتخابگر که فقط `?location=` را عوض کند لینکِ مرده می‌سازد.
     */
    private function plan(string $loc, int $n, array $over = []): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'provider'      => 'hetzner',
            'provider_ref'  => $loc.'-'.$n,
            'provider_location' => 'fsn1',
            'location_code' => $loc,
            'slug'          => 'cv-'.$n.'c-'.$n.'g-'.(20 * $n).'d-'.$loc,
            'public_name'   => 'CV-'.$n.'-'.$n,
            'vcpu'          => $n,
            'ram_mb'        => $n * 1024,
            'disk_gb'       => 20 * $n,
            'disk_type'     => 'ssd',
            'traffic_gb'    => 1000 * $n,
            'cpu_kind'      => 'shared',
            'arch'          => 'x86',
            'cost_eur_cents'  => 100 * $n,
            'price_eur_cents' => 200 * $n,
            'price_irt'       => 850000 * $n,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $over));
    }

    private function html(): string
    {
        return $this->get('/vps/iran')->assertOk()->getContent();
    }

    /** شمارشِ ردیف — دقیقاً یک `data-city=` روی هر `<tr>` */
    private function rows(string $html): int
    {
        return substr_count($html, 'data-city=');
    }

    // ═══════════════ ۱) تکرار تمام شد ═══════════════

    /**
     * 🔴 قلبِ گزارش: ۳ شهر × ۴ مشخصات = ۱۲ ردیف پیش از این، ۴ ردیف بعد از این.
     */
    public function test_the_page_renders_one_row_per_spec_not_one_per_city(): void
    {
        $this->cities();

        foreach (array_keys(self::CITIES) as $loc) {
            foreach ([1, 2, 3, 4] as $n) {
                $this->plan($loc, $n);
            }
        }

        $html = $this->html();

        $this->assertSame(4, $this->rows($html),
            'صفحه هنوز یک ردیف به ازای هر (مشخصات × شهر) می‌سازد — همان جدولِ ۹۱۶۳ پیکسلی');

        // و چهار مشخصات هم واقعاً روی صفحه‌اند، نه اینکه هشت‌تا حذف شده باشد
        foreach ([1, 2, 3, 4] as $n) {
            $this->assertStringContainsString('CV-'.$n.'-'.$n.'<', $html,
                "مشخصاتِ CV-$n از صفحه غایب است — گروه‌بندی چیزی را حذف کرده");
        }
    }

    /**
     * 🔴🔴 **مدرکِ اصلی: هیچ‌چیز غیرقابلِ دسترس نشد.**
     *
     * کامنتی که این تغییر دورش می‌چرخد، به این دلیل نوشته شده که یک «مرتب‌سازی»
     * قبلی ده‌ها پلنِ فروختنی را بی‌خطا و بی‌لاگ پنهان کرد. پس ادعای «تمیزتر
     * شد» بی‌این تست بی‌ارزش است.
     *
     * روش: مجموعهٔ «هر چیزی که سرور می‌تواند بفروشد» را مستقل از ویو می‌سازیم
     * (`CloudPlan::offers()` به ازای هر شهر — همان منبعی که مسیرِ **سفارش** از آن
     * می‌خوانَد) و می‌سنجیم که تک‌تکشان لینکِ خریدِ خودشان را روی HTML دارند.
     */
    public function test_every_sellable_spec_in_every_city_is_still_buyable_from_the_page(): void
    {
        $this->cities();

        foreach (array_keys(self::CITIES) as $loc) {
            foreach ([1, 2, 3, 4] as $n) {
                $this->plan($loc, $n);
            }
        }

        $expected = [];

        foreach (array_keys(self::CITIES) as $loc) {
            foreach (CloudPlan::offers($loc) as $offer) {
                $expected[] = 'location='.urlencode($loc).'&amp;plan='.urlencode((string) $offer->slug);
            }
        }

        $this->assertCount(12, $expected, 'فیکسچر ۱۲ عرضهٔ فروختنی می‌سازد — پیش‌شرطِ خودِ سنجش');

        $html = $this->html();

        $missing = [];

        foreach ($expected as $link) {
            if (! str_contains($html, $link)) {
                $missing[] = $link;
            }
        }

        $this->assertSame([], $missing,
            "این عرضه‌ها فروختنی‌اند ولی از صفحه قابلِ خرید نیستند — همان موجودیِ پنهان:\n"
            .implode("\n", $missing));

        // و ۴ ردیف کافی بوده‌اند تا هر ۱۲ تا در دسترس بمانند
        $this->assertSame(4, $this->rows($html));
    }

    /** هر سه شهر باید با نام دیده شوند، نه فقط ارزان‌ترین */
    public function test_every_city_is_visible_on_the_page(): void
    {
        $this->cities();

        foreach (array_keys(self::CITIES) as $loc) {
            $this->plan($loc, 2);
        }

        $html = $this->html();

        foreach (self::CITIES as $city) {
            $this->assertStringContainsString('>'.$city.'</a>', $html,
                "شهرِ {$city} در انتخابگرِ ردیف نیست — یعنی از صفحه انتخاب‌شدنی نیست");
        }
    }

    // ═══════════════ ۲) قیمتِ متفاوت بین شهرها ═══════════════

    /**
     * ایران امروز قیمتِ یکنواخت دارد، ولی این یک **تصادف** است نه یک قاعده:
     * هر ردیفِ `cloud_plans` قیمتِ خودش را از بهای تمام‌شدهٔ همان منطقه می‌گیرد.
     *
     * ⚠️ این حالت پیش از این تغییر اصلاً به ویو نمی‌رسید: `CloudDominance` روی
     * مجموعهٔ چندشهری می‌دوید و چون مکان بُعدِ مقایسه نیست، شهرِ گران‌تر با
     * مشخصاتِ یکسان **پاک** می‌شد.
     */
    public function test_a_spec_priced_differently_per_city_shows_the_cheapest_with_a_from_marker(): void
    {
        $this->cities();

        $this->plan('ir-tehran', 2, ['price_irt' => 1_700_000]);
        $this->plan('ir-shiraz', 2, ['price_irt' => 2_400_000]);
        $this->plan('ir-isfahan', 2, ['price_irt' => 3_100_000]);

        $html = $this->html();

        $this->assertSame(1, $this->rows($html), 'یک مشخصات = یک ردیف');

        // ارزان‌ترین سرصفحه است
        $this->assertStringContainsString('data-city="تهران"', $html);
        $this->assertStringContainsString('data-price="1700000"', $html);
        $this->assertStringContainsString('<span class="pt-from">'.__('ui.from').'</span>', $html,
            'قیمت بین شهرها فرق دارد و عدد بدونِ «شروع از» دروغ است');

        // و قیمتِ **هر** شهر روی صفحه هست تا انتخابگر بتواند نشانش دهد
        foreach ([1_700_000, 2_400_000, 3_100_000] as $irt) {
            $this->assertStringContainsString(fa_num(number_format($irt)), $html,
                'قیمتِ یکی از شهرها روی صفحه نیست — با عوض‌شدنِ شهر عددی برای نشان‌دادن نمی‌مانَد');
        }

        // هر سه شهر همچنان لینکِ خریدِ خودشان را دارند
        foreach (array_keys(self::CITIES) as $loc) {
            $this->assertStringContainsString('location='.$loc.'&amp;plan=cv-2c-2g-40d-'.$loc, $html);
        }
    }

    // ═══════════════ ۳) شهرِ ناموجود: دیده شود، نه حذف ═══════════════

    /**
     * 🔴 قاعدهٔ پروژه: موجودیِ پنهان باگ است، نه نظم. شهری که ظرفیتش تمام شده
     * باید **صریح** بگوید ناموجود است؛ ناپدیدشدنش به مشتری می‌گوید «این‌جا این
     * اندازه را اصلاً ندارند» که دروغ است.
     *
     * ⚠️ پیش از این تغییر، `offers()` از `scopeSellable` می‌خوانْد و شهرِ ناموجود
     * اصلاً به صفحه نمی‌رسید. حالا `shelf()` خوانده می‌شود — همان پرس‌وجویی که
     * فروشگاهِ کنسول برای همین کار دارد. `scopeSellable` دست‌نخورده مانده چون
     * مسیرِ **سفارش** از آن می‌خوانَد.
     */
    public function test_a_city_that_is_out_of_stock_is_shown_as_unavailable_not_omitted(): void
    {
        $this->cities();

        $this->plan('ir-tehran', 2);
        $this->plan('ir-shiraz', 2, ['in_stock' => false]);

        $html = $this->html();

        $this->assertSame(1, $this->rows($html));

        // شهر دیده می‌شود …
        $this->assertStringContainsString('شیراز', $html,
            'شهرِ ناموجود از صفحه غیب شده — مشتری فکر می‌کند این اندازه را آن‌جا نداریم');

        // … ولی صریح ناموجود است و لینکِ خرید ندارد
        $this->assertStringContainsString(__('ui.pt_city_out'), $html);
        $this->assertStringContainsString('pt-c is-off', $html,
            'شهرِ ناموجود مثلِ شهرِ خریدنی رندر شده — کلیکِ بی‌نتیجه بدترین حالت است');
        $this->assertStringNotContainsString('location=ir-shiraz', $html,
            'شهرِ ناموجود لینکِ خرید دارد — مشتری به تسویه‌ای می‌رود که ردش می‌کند');

        // و شهرِ سالم دست‌نخورده
        $this->assertStringContainsString('location=ir-tehran&amp;plan=cv-2c-2g-40d-ir-tehran', $html);
    }

    /** شهرِ ناموجود نباید در فهرستِ فیلترِ شهر بیاید — فیلترِ بی‌نتیجه */
    public function test_an_out_of_stock_city_is_not_offered_as_a_filter_option(): void
    {
        $this->cities();

        $this->plan('ir-tehran', 2);
        $this->plan('ir-shiraz', 2, ['in_stock' => false]);

        $html = $this->html();

        $this->assertStringContainsString('data-f="city" data-v="تهران"', $html);
        $this->assertStringNotContainsString('data-f="city" data-v="شیراز"', $html);
    }

    /**
     * مشخصاتی که در **هیچ** شهری فروختنی نیست، نباید ردیف بسازد.
     *
     * ⚠️ این نگهبانِ خطرِ تازه‌ای است که `shelf()` وارد کرد: قفسه ردیف‌های
     * گذرا-ناموجود را هم برمی‌دارد، و اگر بی‌احتیاط باشیم نامِ پلنی که هیچ‌کجا
     * قابلِ خرید نیست روی صفحه می‌آید.
     */
    public function test_a_spec_that_is_nowhere_sellable_makes_no_row_at_all(): void
    {
        $this->cities();

        $this->plan('ir-tehran', 2);
        $this->plan('ir-tehran', 5, ['public_name' => 'GHOST-SPEC', 'in_stock' => false]);
        $this->plan('ir-shiraz', 5, ['public_name' => 'GHOST-SPEC', 'in_stock' => false]);

        $html = $this->html();

        $this->assertSame(1, $this->rows($html));
        $this->assertStringNotContainsString('GHOST-SPEC', $html,
            'پلنی که هیچ‌جا فروختنی نیست نباید نامش روی صفحه بیاید');
    }

    // ═══════════════ ۴) کلیدِ گروه‌بندی ═══════════════

    /**
     * کلیدِ یکتاسازی `vcpu-ram-disk-disk_type-traffic-cpu_kind-arch` است و مکان
     * در آن **نیست**. هر بُعدِ دیگری که مشتری می‌بیند باید ردیف را جدا کند.
     *
     * ⚠️ چرا هر مورد اسلاگِ جدا دارد: `CloudNaming::planSlug` فقط
     * هسته/رم/دیسک/مکان را می‌گیرد، پس دو ردیفِ هم‌اسلاگ در یک شهر را
     * `offers()` با هم ادغام می‌کند و تست پیش از رسیدن به گروه‌بندی می‌مُرد.
     * این‌جا اسلاگ‌ها جدا داده می‌شوند تا واقعاً **گروه‌بندی** سنجیده شود.
     */
    public function test_specs_that_differ_only_in_disk_type_traffic_or_arch_stay_separate_rows(): void
    {
        $this->cities(['ir-tehran']);

        $this->plan('ir-tehran', 2, ['public_name' => 'BASE', 'slug' => 'a-ir-tehran', 'provider_ref' => 'r-a']);
        $this->plan('ir-tehran', 2, ['public_name' => 'NVME', 'slug' => 'b-ir-tehran', 'provider_ref' => 'r-b', 'disk_type' => 'nvme', 'price_irt' => 1_900_000]);
        $this->plan('ir-tehran', 2, ['public_name' => 'BIGT', 'slug' => 'c-ir-tehran', 'provider_ref' => 'r-c', 'traffic_gb' => 40000, 'price_irt' => 2_100_000]);
        $this->plan('ir-tehran', 2, ['public_name' => 'ARMY', 'slug' => 'd-ir-tehran', 'provider_ref' => 'r-d', 'arch' => 'arm', 'price_irt' => 1_300_000]);

        $html = $this->html();

        foreach (['BASE', 'NVME', 'BIGT', 'ARMY'] as $name) {
            $this->assertStringContainsString($name.'<', $html,
                "{$name} با ردیفِ دیگری ادغام شده — کلیدِ گروه‌بندی درشت‌تر از چیزی است که مشتری می‌بیند");
        }

        $this->assertSame(4, $this->rows($html));
    }

    /** پردازندهٔ اختصاصی هرگز با اشتراکی در یک ردیف نمی‌نشیند */
    public function test_the_two_cpu_kind_tables_both_survive_grouping(): void
    {
        $this->cities(['ir-tehran', 'ir-shiraz']);

        foreach (['ir-tehran', 'ir-shiraz'] as $loc) {
            $this->plan($loc, 2);
            $this->plan($loc, 4, ['cpu_kind' => 'dedicated', 'slug' => 'cvd-4c-4g-80d-'.$loc]);
        }

        $html = $this->html();

        $this->assertStringContainsString('data-group="std"', $html);
        $this->assertStringContainsString('data-group="ded"', $html);
        $this->assertSame(2, $this->rows($html), 'دو مشخصات × دو شهر باید دو ردیف بدهد');
    }

    // ═══════════════ ۵) سفیدبرچسبی و چیدمان ═══════════════

    /** نامِ زیرساخت و کدِ پلنِ زیرساخت هیچ‌جای صفحه نباید باشد */
    public function test_no_provider_name_reaches_the_grouped_page(): void
    {
        $this->cities();

        foreach (array_keys(self::CITIES) as $loc) {
            $this->plan($loc, 2, ['provider' => 'aeza', 'provider_ref' => 'EPs-1', 'provider_location' => 'hel1']);
            $this->plan($loc, 3, ['provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1']);
        }

        $html = strtolower($this->html());

        foreach (['aeza', 'cx22', 'eps-1', 'fsn1', 'hel1', 'cost_eur_cents', 'provider_ref'] as $leak) {
            $this->assertStringNotContainsString($leak, $html,
                "«{$leak}» در خروجی نشسته — انتخابگرِ شهر یک سطحِ نشتِ تازه است");
        }
    }

    /** چیدمانِ پیش‌فرض هنوز ارزان به گران است (ارزان‌ترین شهرِ هر گروه) */
    public function test_grouped_rows_are_still_ordered_cheapest_first(): void
    {
        $this->cities();

        foreach (array_keys(self::CITIES) as $loc) {
            foreach ([4, 1, 3, 2] as $n) {
                $this->plan($loc, $n);
            }
        }

        preg_match_all('~data-price="(\d+)"~', $this->html(), $m);
        $prices = array_map('intval', $m[1]);

        $sorted = $prices;
        sort($sorted);

        $this->assertSame($sorted, $prices, 'جدول باید از ارزان به گران باشد');
        $this->assertCount(4, $prices);
    }

    /**
     * ⚠️ ردیفِ چندشهری نباید فیلترِ شهر را بشکند.
     *
     * فیلترِ سمتِ مرورگر روی `data-city` تطبیقِ **دقیق** می‌کرد. با یک ردیف که
     * سه شهر می‌فروشد، فیلترِ «شیراز» همهٔ ردیف‌ها را پنهان می‌کرد و مشتری
     * می‌دید «شیراز موجودی ندارد». پس ردیف فهرستِ شهرهایش را هم می‌برد.
     */
    public function test_a_multi_city_row_carries_every_buyable_city_for_the_filter(): void
    {
        $this->cities();

        foreach (array_keys(self::CITIES) as $loc) {
            $this->plan($loc, 2);
        }

        $html = $this->html();

        $this->assertStringContainsString('data-cities="|تهران|شیراز|اصفهان|"', $html,
            'ردیف فهرستِ شهرهایش را ندارد — فیلترِ شهر ردیفِ درست را پنهان می‌کند');

        foreach (self::CITIES as $city) {
            $this->assertStringContainsString('data-f="city" data-v="'.$city.'"', $html);
        }
    }

    /**
     * 🔴 موبایل: **بدنهٔ صفحه** هرگز اسکرولِ افقی نمی‌گیرد؛ جدولِ پهن داخلِ ظرفِ
     * خودش اسکرول می‌شود.
     *
     * ⚠️ این تا امروز هیچ تستی نداشت، در حالی که یک‌بار خرابی‌اش گزارش شده:
     * دکمهٔ «انتخاب پلن» در عرض‌های ~۹۰۰ تا ~۱۰۹۰ بریده می‌شد و مشتری **اصلاً
     * نمی‌توانست بخرد**. انتخابگرِ شهر ردیف را پهن‌تر می‌کند، پس این شکل باید
     * قفل باشد.
     *
     * ⚠️ و به همین دلیل انتخابگر «چیپِ درون‌خطی» است نه پاپ‌آور:
     * `overflow-x:auto` طبقِ استاندارد محورِ عمودی را هم کلیپ می‌کند، پس هر
     * منوی مطلقِ داخلِ ردیف در همان ظرف بریده می‌شود.
     */
    public function test_the_wide_table_scrolls_inside_its_own_container_not_the_page(): void
    {
        $css = file_get_contents(public_path('assets/css/site.css'));

        $this->assertMatchesRegularExpression('~\.plan-table-wrap\{[^}]*overflow-x:\s*auto~', $css,
            'ظرفِ جدول اسکرولِ خودش را ندارد — جدولِ پهن صفحه را افقی می‌کشد');

        $this->assertMatchesRegularExpression('~\.plan-table\{[^}]*min-width:\s*\d+px~', $css,
            'جدول حداقلِ عرض ندارد — ستون‌ها روی موبایل له می‌شوند به‌جای اسکرول');

        $this->assertMatchesRegularExpression('~[\s}]body\{[^}]*overflow-x:\s*hidden~', $css,
            'بدنهٔ صفحه می‌تواند افقی اسکرول شود — همان چیزی که روی گوشی صفحه را می‌لرزانَد');

        $this->assertDoesNotMatchRegularExpression('~\.pt-c(ities)?[\s,{][^}]*position:\s*(absolute|fixed)~', $css,
            'انتخابگرِ شهر پاپ‌آورِ مطلق شده — داخلِ ظرفِ اسکرول‌دارِ جدول بریده می‌شود');
    }

    /**
     * ⚠️ گزینه‌های انتخابگر نباید `data-city=` یا `data-price=` بگیرند.
     *
     * دو تستِ دیگرِ همین صفحه ردیف‌ها را با `substr_count('data-city=')` می‌شمارند
     * و یکی ترتیبِ قیمت را با `preg_match_all('~data-price="(\d+)"~')` می‌سنجد.
     * اگر انتخابگر همان نام‌ها را به کار ببرد، هر دو **بی‌صدا** چیزِ دیگری
     * می‌سنجند و سبز می‌مانند.
     */
    public function test_the_picker_does_not_pollute_the_row_counting_attributes(): void
    {
        $this->cities();

        foreach (array_keys(self::CITIES) as $loc) {
            $this->plan($loc, 2, ['price_irt' => 1_000_000 + strlen($loc) * 1000]);
        }

        $html = $this->html();

        $this->assertSame(1, substr_count($html, 'data-city='));
        $this->assertSame(1, substr_count($html, 'data-price='));
    }
}
