<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Services\Cloud\CloudNaming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * «نقشه‌اول» — کارتِ کشور، یکتاسازیِ شهر، و پایداریِ پنل‌ها.
 *
 * ═══ باگی که این فایل برای تکرارنشدنش نوشته شد ═══
 *
 * این سومین بارِ همان **ردهٔ** باگ است: «ردیف‌ها را پنهان کن تا مرتب دیده شود».
 * بارِ اول `MAX_MARKETING_PLANS` درآمد سوزاند، بارِ دوم اصلاحش ۱۴۸ ردیفِ تکراری
 * ساخت. پس این‌جا هر ادعا دو نیمه دارد: «تکراری‌ها یک کارت شدند» **و** «هیچ
 * چیزی که دیروز خریدنی بود امروز نارس نیست».
 *
 * علتِ ریشه‌ایِ شهرهای تکراری در `CloudLocation::cityIdentity()` مستند است:
 * ردیف تکراری نیست (`code` یکتاست و پرس‌وجو `unique()` می‌خورد)؛ تکرار را
 * `cityLabel()` سرِ **رندر** می‌سازد، چون هر کدِ بی‌شهر نامِ پایتخت را چاپ
 * می‌کند. برای همین کلیدِ گروه از **شناسه** می‌آید نه از برچسب.
 *
 * ⚠️ دیتابیسِ محلی این باگ را بازتولید نمی‌کند (۷ مکانِ تمیز). پس ردیف‌های
 * بیمار این‌جا **صریحاً ساخته می‌شوند**؛ تستی که از فیکسچرِ محلی بسازد سبز
 * می‌شود و هیچ‌چیز ثابت نمی‌کند.
 * ⚠️ هیچ تماسِ واقعیِ زیرساخت: `Http::fake()` در setUp. (نرخِ ارزِ زندهٔ صفحه
 *    خودش یک درخواستِ جعلی می‌زند، پس `assertNothingSent()` این‌جا بی‌معناست و
 *    عمداً نیست — یک ادعای همیشه‌قرمز چیزی را امن‌تر نمی‌کند.)
 */
class CloudStoreLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        // همان بازسازیِ ترتیبِ روت‌ها که بقیهٔ تست‌های فروشگاه دارند
        if (! Route::has('account.cloud.store')) {
            Route::middleware(['web', 'auth:customer'])->prefix('account')->name('account.')->group(function () {
                Route::get('/cloud-store', [CloudStoreController::class, 'index'])->name('cloud.store');
                Route::post('/cloud-store', [CloudStoreController::class, 'order'])->name('cloud.store.place');
            });

            $mine = ['account.cloud.store', 'account.cloud.store.place'];
            $ordered = new RouteCollection;

            foreach (collect(Route::getRoutes()->getRoutes())
                ->sortBy(fn ($r) => in_array($r->getName(), $mine, true) ? 0 : 1)->all() as $route) {
                $ordered->add($route);
            }

            Route::setRoutes($ordered);
        }
    }

    // ═══════════════════ فیکسچرها ═══════════════════

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'loc'.random_int(1, 999999).'@example.com',
            'phone' => '0913'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function image(): void
    {
        CloudImage::firstOrCreate(['provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04'], [
            'key' => 'ubuntu-24.04', 'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04',
            'label' => 'Ubuntu 24.04', 'arch' => 'x86', 'min_disk_gb' => 5, 'is_active' => true,
        ]);
    }

    /** دو اندازه که هیچ‌کدام دیگری را حذف نمی‌کند (فیلترِ پارتو دست نمی‌زند) */
    private function pair(string $code, bool $inStock = true): void
    {
        foreach ([
            ['CV-2-4', 'cv-2c-4g-40d-'.$code, 2, 4096, 40, 379, 570, 570000],
            ['CV-4-8', 'cv-4c-8g-80d-'.$code, 4, 8192, 80, 700, 1000, 1000000],
        ] as [$name, $slug, $v, $r, $d, $cost, $eur, $irt]) {
            CloudPlan::create([
                'provider' => 'hetzner', 'provider_ref' => $slug, 'provider_location' => 'fsn1',
                'location_code' => $code, 'public_name' => $name, 'slug' => $slug,
                'vcpu' => $v, 'ram_mb' => $r, 'disk_gb' => $d, 'disk_type' => 'nvme',
                'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
                'cost_eur_cents' => $cost, 'price_eur_cents' => $eur, 'price_irt' => $irt,
                'is_active' => true, 'in_stock' => $inStock,
            ]);
        }
    }

    /**
     * کاتالوگِ بیمار — دقیقاً همان چیزی که روی پروداکشن نشسته و محلی نیست.
     *
     * @return array<int,string> کدهای فروختنی
     */
    private function sickCatalog(): array
    {
        $this->image();

        $rows = [
            ['de-frankfurt', 'DE', 'Frankfurt'],   // شهرِ سالم
            ['de-ref',       'DE', null],          // بی‌شهر → «برلین» چاپ می‌کند
            ['de-amd',       'DE', 'AMD'],         // ردهٔ محصول در ستونِ شهر → «برلین»
            ['ch-zurich',    'CH', 'Zurich'],      // یک شهرِ واقعی…
            ['ch-z-rich',    'CH', 'Zürich'],      // …با دو کد، چون slug() بایت‌محور است
            ['ir-tehran',    'IR', 'Tehran'],      // تهرانِ واقعی
            ['ir-ref',       'IR', null],          // 🔴 «تهران» چاپ می‌کند ولی تهران نیست
        ];

        foreach ($rows as [$code, $country, $city]) {
            CloudLocation::create(['code' => $code, 'country' => $country, 'city' => $city, 'is_active' => true]);
            $this->pair($code);
        }

        // کشوری که همه‌چیزش تمام شده — باید خاکستری بماند، نه غیب شود
        CloudLocation::create(['code' => 'fi-helsinki', 'country' => 'FI', 'city' => 'Helsinki', 'is_active' => true]);
        $this->pair('fi-helsinki', false);

        return array_column($rows, 0);
    }

    private function page(string $qs = ''): string
    {
        return $this->actingAs($this->customer(), 'customer')
            ->get(route('account.cloud.store', [], false).$qs)->assertOk()->getContent();
    }

    // ═══════════════════ ۱ — علتِ ریشه‌ای ═══════════════════

    /**
     * 🔴 تکرار در **دیتابیس** نیست؛ سرِ رندر ساخته می‌شود.
     *
     * سه کدِ کاملاً متفاوتِ آلمان یک متن چاپ می‌کنند، چون `cityLabel()` هر جا
     * شهر خالی یا یک واژهٔ ردهٔ محصول باشد نامِ پایتخت را برمی‌گرداند. اگر این
     * تست روزی قرمز شد یعنی علتِ ریشه‌ای عوض شده و راه‌حلِ نمایشی باید بازبینی
     * شود — نه اینکه ادعا را پاک کنیم.
     */
    public function test_the_duplicate_city_names_are_minted_at_render_time_not_by_duplicate_rows(): void
    {
        $mk = fn (string $code, ?string $city) => new CloudLocation(
            ['code' => $code, 'country' => 'DE', 'city' => $city, 'is_active' => true]
        );

        /*
        | ⚠️ این ادعا **وارونه شد**، و دلیلش گران‌ترین درسِ این پرونده است.
        |
        | نسخهٔ قبلی می‌گفت «هر سه باید برلین چاپ کنند» و آن را رفتارِ درست
        | می‌نامید. یعنی تستی که برای گرفتنِ باگ نوشته شده بود، خودش **محافظِ**
        | باگ شد: ۱۴ تست و ۱۵۲ ادعا سبز بودند در حالی که کارفرما سه چیپِ
        | «برلین» را کنارِ هم می‌دید.
        |
        | `CloudLocation::cityLabel()` هنوز پایتخت را برمی‌گرداند و عمداً
        | دست‌نخورده است (فاکتور و صفحات کشور همان را می‌خوانند). چیزی که عوض
        | شد، **فروشگاه** است: `CloudStoreController::trustedCityName()` شهری را
        | که نمی‌شناسیم نام نمی‌دهد. ادعای رندرشده در CloudStoreCityStepTest است.
        */
        $this->assertSame('برلین', $mk('de-ref', null)->cityLabel('fa'),
            'رفتارِ مدلی عمداً دست‌نخورده — فروشگاه دیگر از این متد برای نامِ شهر نمی‌پرسد');

        $store = new \ReflectionMethod(CloudStoreController::class, 'trustedCityName');
        $store->setAccessible(true);

        foreach ([['de-ref', null], ['de-amd', 'AMD'], ['de-nvme', 'NVMe']] as [$code, $city]) {
            $this->assertNull($store->invoke(null, $mk($code, $city)),
                "«{$code}» شهر ندارد؛ فروشگاه نباید نامی برایش بسازد");
        }

        // …و سه شناسهٔ متفاوت‌اند، پس سه سطلِ متفاوت می‌مانند (هیچ ادغامی)
        $keys = array_map(fn ($l) => $l->cityIdentity(),
            [$mk('de-ref', null), $mk('de-amd', 'AMD'), $mk('de-nvme', 'NVMe')]);

        $this->assertSame($keys, array_unique($keys), 'کدِ بی‌شهر نباید در سطلِ دیگری حل شود');
    }

    /**
     * 🔴 خطرناک‌ترین ادغام: `ir-ref` هم «تهران» چاپ می‌کند و **تهران نیست**.
     *
     * گروه‌بندی روی برچسب یعنی فروختنِ دیتاسنتری در جای دیگر به مشتری‌ای که بر
     * پایهٔ تأخیر انتخاب می‌کند — و پاک‌شدنِ شواهدِ خرابیِ پارس. کلید از شناسه
     * می‌آید، پس دو سطل می‌مانند.
     */
    public function test_a_cityless_code_is_never_merged_into_the_real_capital(): void
    {
        $tehran = new CloudLocation(['code' => 'ir-tehran', 'country' => 'IR', 'city' => 'Tehran', 'is_active' => true]);
        $junk = new CloudLocation(['code' => 'ir-ref', 'country' => 'IR', 'city' => null, 'is_active' => true]);

        // دو کارتِ جدا می‌مانند — هیچ ادغامی، پس هیچ کدی نارس نمی‌شود
        $buckets = CloudStoreController::cityBuckets([$tehran, $junk], ['ir-tehran', 'ir-ref']);

        $this->assertCount(2, $buckets, 'ir-ref نباید داخلِ کارتِ تهران حل شود');
        $this->assertSame(['ir-tehran', 'ir-ref'],
            array_map(fn ($b) => (string) $b['primary']->code, $buckets));

        /*
        | 🔴 و ادعایی که تا امروز نبود و نبودنش کلِ باگ را زنده نگه داشت:
        | **متنِ دو کارت هم باید فرق کند.** تستِ قبلی این‌جا `assertSame('تهران',
        | $junk->cityLabel('fa'))` داشت و آن را درست می‌نامید؛ یعنی دقیقاً همان
        | چیزی که کارفرما روی صفحه می‌دید، این‌جا به‌عنوان رفتارِ مطلوب قفل شده
        | بود. یکتاییِ **کلید** هرگز یکتاییِ **برچسب** را ثابت نمی‌کند.
        */
        $labels = array_map(fn ($b) => (string) $b['label'], $buckets);

        $this->assertSame($labels, array_unique($labels), 'دو سطل نباید یک متن چاپ کنند');
        $this->assertSame('تهران', $labels[0]);
        $this->assertStringNotContainsString('تهران', $labels[1],
            'ir-ref تهران نیست — نامِ تهران نباید رویش بنشیند');
    }

    /** یک شهرِ واقعی با دو املا = یک کارت، ولی هر دو کد سرِ جایشان */
    public function test_two_spellings_of_one_city_collapse_into_one_card_without_losing_a_code(): void
    {
        $a = new CloudLocation(['code' => 'ch-zurich', 'country' => 'CH', 'city' => 'Zurich', 'is_active' => true]);
        $b = new CloudLocation(['code' => 'ch-z-rich', 'country' => 'CH', 'city' => 'Zürich', 'is_active' => true]);

        $this->assertSame('zurich', CloudNaming::cityFold('Zurich'));
        $this->assertSame('zurich', CloudNaming::cityFold('Zürich'));
        // İ ترکی: slug() حذفش می‌کند («stanbul»)، تاشدگیِ نمایشی نه
        $this->assertSame('istanbul', CloudNaming::cityFold('İstanbul'));
        $this->assertSame('istanbul', CloudNaming::cityFold('Istanbul'));
        // و `slug()` — که کدِ ذخیره‌شده و planSlug را می‌سازد — دست‌نخورده است
        $this->assertSame('z-rich', CloudNaming::slug('Zürich'));

        $buckets = CloudStoreController::cityBuckets([$a, $b], ['ch-zurich', 'ch-z-rich']);

        $this->assertCount(1, $buckets, 'یک شهر باید یک کارت باشد');
        $this->assertSame(2, $buckets[0]['n']);
        $this->assertSame(['ch-zurich', 'ch-z-rich'],
            array_map(fn ($m) => (string) $m->code, $buckets[0]['members']),
            'هیچ کدی حذف نمی‌شود — یکتاسازی فقط نمایشی است');
    }

    /** نمایندهٔ سطل باید عضوِ **باز** باشد، وگرنه تمام‌شده جلوی فروختنی را می‌گیرد */
    public function test_a_shut_member_never_hides_an_open_sibling(): void
    {
        $shut = new CloudLocation(['code' => 'ch-zurich', 'country' => 'CH', 'city' => 'Zurich', 'is_active' => true]);
        $open = new CloudLocation(['code' => 'ch-z-rich', 'country' => 'CH', 'city' => 'Zürich', 'is_active' => true]);

        $buckets = CloudStoreController::cityBuckets([$shut, $open], ['ch-z-rich']);

        $this->assertCount(1, $buckets);
        $this->assertTrue($buckets[0]['open'], 'یک عضوِ باز کافی است تا شهر خریدنی بماند');
        $this->assertSame('ch-z-rich', (string) $buckets[0]['primary']->code);
    }

    // ═══════════════════ ۲ — هیچ‌چیزی نارس نشد ═══════════════════

    /**
     * 🔴 ادعایِ اصلیِ این کار: هر (مشخصات × مکان) که دیروز خریدنی بود، امروز هم
     * هست — هم از فهرستِ کشورها **قابلِ رسیدن** است و هم صفحه‌اش همان اسلاگ‌ها
     * را می‌فروشد.
     *
     * یکتاسازی فقط یک گروه‌بندیِ نمایشی است: `location` همان کدِ اصلی را پست
     * می‌کند، `?location=` همان را حمل می‌کند و `CloudPlan::shelf($code)` هیچ
     * تغییری ندیده.
     */
    public function test_every_spec_times_location_that_was_buyable_before_is_still_buyable(): void
    {
        $codes = $this->sickCatalog();
        $html = $this->page();

        foreach ($codes as $code) {
            // ۱) کشورش از صفحهٔ نخست یک ردیفِ کلیک‌شدنی دارد.
            //    ⚠️ خودِ چیپِ شهر دیگر روی صفحهٔ نخست نیست: شهر مرحلهٔ خودش شد و
            //    فقط شهرهای کشورِ انتخاب‌شده رندر می‌شوند. دسترس‌پذیری حالا
            //    «کشور ← شهر» است، و هر دو نیمه این‌جا سنجیده می‌شود.
            $iso = strtoupper(explode('-', $code)[0]);
            $this->assertStringContainsString('data-nat="'.$iso.'"', $html,
                "کشورِ «{$iso}» از فهرست ناپدید شده");

            // ۲) و صفحهٔ خودش هم لینکِ شهرش را دارد هم همان دو اندازه را می‌فروشد
            $own = $this->page('?location='.$code);

            $this->assertStringContainsString('data-city="'.$code.'"', $own,
                "مکانِ «{$code}» از مرحلهٔ شهر ناپدید شده");
            $this->assertMatchesRegularExpression(
                '~<a class="cvb-city[^"]*"[^>]*href="[^"]*location='.preg_quote($code, '~').'&~',
                $own, "لینکِ مکانِ «{$code}» شکسته یا حذف شده");

            foreach (['cv-2c-4g-40d-'.$code, 'cv-4c-8g-80d-'.$code] as $slug) {
                $this->assertStringContainsString('data-slug="'.$slug.'"', $own,
                    "اسلاگِ «{$slug}» در مکانِ «{$code}» دیگر خریدنی نیست");
            }

            // و دکمهٔ پرداخت باز است (قیمت دارد)
            $this->assertDoesNotMatchRegularExpression('~id="cvb-submit"[^>]*disabled~', $own,
                "دکمهٔ خرید در مکانِ «{$code}» بسته است");
        }
    }

    /** کشورِ تمام‌شده خاکستری می‌شود، غیب نمی‌شود (درسِ MAX_MARKETING_PLANS) */
    public function test_a_sold_out_country_is_greyed_out_and_still_reachable(): void
    {
        $this->sickCatalog();
        $html = $this->page();

        $this->assertStringContainsString('data-nat="FI"', $html, 'کشورِ تمام‌شده باید دیده شود');
        $this->assertStringContainsString(__('ui.cvb_c_soldout'), $html);
        $this->assertMatchesRegularExpression('~<a class="cvb-nat\s+is-shut~', $html,
            'کشورِ بی‌موجودی باید علامتِ دیداریِ خودش را داشته باشد');

        // …و شهرش هم صادقانه ناموجود علامت خورده باشد، در مرحلهٔ خودش
        $fi = $this->page('?location=fi-helsinki');
        $this->assertMatchesRegularExpression('~class="cvb-city\s+is-shut[^"]*"[^>]*data-city="fi-helsinki"~', $fi);
    }

    /**
     * شمارِ روی کارتِ کشور = شهرهای **باز**، نه تعدادِ کدها.
     *
     * ⚠️ `count($g['locations'])` همان تکرارها را دوباره می‌شمرد و
     * `CloudCountry::served()` شهرهای خام را حساس‌به‌حروف می‌شمارد و خالی‌ها را
     * می‌اندازد. هیچ‌کدام مبنای درستی نیست.
     */
    public function test_the_country_row_count_matches_the_rows_the_city_step_renders(): void
    {
        $this->sickCatalog();
        $html = $this->page();

        /*
        | 🔴 ادعای قبلی «آلمان باید ۳ شهر بگوید» بود، و همان عدد سه چیپِ
        | «برلین» را می‌شمرد. عددی که با صفحه نمی‌خوانَد بدتر از نبودنِ عدد است.
        | ادعای درست: عددِ روی ردیفِ کشور **دقیقاً** برابرِ تعدادِ ردیف‌هایی است
        | که مرحلهٔ ۲ برای همان کشور رندر می‌کند.
        */
        preg_match('~data-nat="DE".*?<small>([^<]*)</small>~su', $html, $m);
        $this->assertNotEmpty($m, 'ردیفِ آلمان شمارِ شهر ندارد');

        $de = $this->page('?location=de-frankfurt');
        preg_match_all('~<a class="cvb-city[^"]*"[^>]*data-city="~', $de, $rows);
        $shown = count($rows[0]);

        $this->assertSame(3, $shown, 'آلمان سه کد دارد: de-frankfurt + de-ref + de-amd');
        $this->assertSame(trans_choice('ui.cvb_c_cities', $shown, ['n' => fa_num($shown)]), trim($m[1]),
            'عددِ روی کارتِ کشور باید همان چیزی باشد که روی صفحه دیده می‌شود');

        // و شهرِ دو-دیتاسنتری، اعضایش را با شمارهٔ ترتیبی نشان می‌دهد
        $ch = $this->page('?location=ch-zurich');
        $this->assertStringContainsString(__('ui.cvb_dc_multi', ['count' => fa_num(2)]), $ch);
        $this->assertStringContainsString(__('ui.cvb_dc_n', ['n' => fa_num(1)]), $ch);
    }

    // ═══════════════════ ۳ — پنلِ پایدار، بی‌جاوااسکریپت ═══════════════════

    /**
     * انتخابگرِ مکان از `<details>`ِ بومی ساخته شده — همان الگویی که «تنظیمات
     * پیشرفته» از قبل داشت. یعنی بی‌جاوااسکریپت هم باز/بسته می‌شود، با
     * صفحه‌کلید کامل است، و «پنلی که زیرِ دستِ کاربر بسته شد» ساختاراً ممکن
     * نیست چون trigger و پنل یک عنصرند.
     */
    public function test_the_location_picker_is_plain_links_and_survives_javascript_off(): void
    {
        $this->sickCatalog();
        $html = $this->page();

        /*
        | ⚠️ سه سطحِ تودرتوی `<details>` رفت. علتش سلیقه نبود: کارفرما خواست شهر
        | یک **مرحلهٔ انتخابی بعد از کشور** باشد، و آن با «افشاگرِ تودرتو داخلِ
        | کارتِ کشور» ناسازگار است. حالا هر دو سطح لینکِ ساده‌اند — که از
        | `<details>` هم بی‌جاوااسکریپت‌تر است، نه کمتر.
        */
        $this->assertStringNotContainsString('<details class="cvb-cnat', $html);
        $this->assertMatchesRegularExpression('~<a class="cvb-nat[^"]*"[^>]*data-nat="~', $html);

        // پنج بدنه — شهر مرحلهٔ خودش شد
        $this->assertSame(5, substr_count($html, 'class="cvb-step-b"'));
        $this->assertStringNotContainsString('cvb-step is-shut', $html);

        // کشورِ انتخابی سرور-رندر علامت خورده است
        $this->assertMatchesRegularExpression('~class="cvb-nat[^"]*\bon\b~', $html);

        // و مرحلهٔ ۱ روی نخستین بارگذاری **باز** است، نه بسته‌وپاسخ‌داده:
        // پیش از این صفحه با «🇩🇪 آلمان — برلین» باز می‌شد، و «برلین» خودش
        // پایتختِ جای‌گزینِ ردیفی بود که ستونِ شهرش «AMD» است.
        $this->assertMatchesRegularExpression('~&quot;openStep&quot;:1|"openStep":1~', $html,
            'نخستین بارگذاری باید روی مرحلهٔ ۱ بایستد، نه روی مرحله‌ای با پاسخِ ساختگی');
    }

    /** صفحه هنوز هیچ نامِ زیرساختی بیرون نمی‌دهد — سطحِ تازه هم همین‌طور */
    public function test_the_new_datacenter_level_leaks_no_provider_identity(): void
    {
        $this->sickCatalog();
        $own = preg_replace('~<a\b[^>]*href="[^"]*/dedicated/[^"]*"[^>]*>.*?</a>~is', '', $this->page());

        foreach (['hetzner', 'Hetzner', 'aeza', 'Aeza', 'fsn1', 'hel1', 'gra7', 'cx22', 'CX22', 'EPs-'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $own, "«{$secret}» نباید در HTML باشد");
        }
    }

    // ═══════════════════ ۴ — کم‌رنگی، برگه و داک ═══════════════════

    /**
     * 🔴 نگهبانِ بلوکِ دربرگیرنده.
     *
     * `.cvb-dock` با `position:fixed` **داخلِ** فرم است. هر
     * filter/backdrop-filter/transform/perspective/will-change/contain روی یکی
     * از نیاکانش، همان گره را بلوکِ دربرگیرندهٔ داک می‌کند و داک از قابِ دید
     * کنده می‌شود و با صفحه پایین می‌رود. خرابی‌اش ۲۰۰ می‌دهد، هیچ خطایی در
     * کنسول نمی‌سازد و فقط زیرِ ۱۰۰۰px دیده می‌شود — یعنی دقیقاً همان چیزی که
     * یک تست باید بگیرد.
     */
    public function test_no_ancestor_of_the_fixed_dock_creates_a_containing_block(): void
    {
        $css = '';
        foreach (['site.css', 'panel.css'] as $f) {
            $css .= file_get_contents(public_path('assets/css/'.$f));
        }

        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);

        $ancestors = ['.cvb-wrap', '.pnl-main', '.pnl-layout', '.pnl-wrap', '.container', '#main'];
        $forbidden = ['filter:', 'backdrop-filter:', 'transform:', 'perspective:', 'will-change:', 'contain:'];

        $seen = 0;
        $bad = [];

        foreach ($rules as $r) {
            foreach (preg_split('~\s*,\s*~', trim($r[1])) as $sel) {
                $sel = trim($sel);

                // فقط سلکتورهایی که **خودِ** نیا را هدف می‌گیرند (نه فرزندانش)
                if (! in_array($sel, $ancestors, true) && ! in_array($sel, ['body', 'html'], true)) {
                    continue;
                }

                $seen++;

                foreach ($forbidden as $p) {
                    if (str_contains($r[2], $p)) {
                        $bad[] = $sel.' → '.$p;
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $seen, 'هیچ قاعدهٔ نیایی پیدا نشد — برش کهنه شده');
        $this->assertSame([], $bad,
            "این اعلان‌ها داکِ ثابتِ موبایل را از قابِ دید می‌کَنند:\n  ".implode("\n  ", $bad));
    }

    /**
     * کم‌رنگی فقط روی `.cvb-step`/`.cvb-step-i` می‌نشیند — نه یک پله بالاتر.
     * و برگهٔ چسبان و داک هنوز همان اعلان‌های قفل‌شده را دارند.
     */
    public function test_the_dimming_is_scoped_below_the_slip_and_the_dock(): void
    {
        $raw = (string) file_get_contents(public_path('assets/css/panel.css'));

        /*
        | 🔴 کامنت‌ها **اول** حذف می‌شوند — تلهٔ ثبت‌شدهٔ همین پروژه که دو بار
        | خورده شده: ادعای تست به کامنتِ خودِ ما می‌خورد. این‌جا کامنتِ توضیحیِ
        | بالای همین بخش، عینِ قاعدهٔ حذف‌شده (`.cvb-step-i{filter:blur(.6px)}`)
        | را نقل می‌کند تا معلوم باشد چرا رفته — و همان نقلِ قول، ادعای «برنگشته
        | باشد» را قرمز می‌کرد. تست باید CSSِ **اجراشدنی** را بسنجد، نه نثر را.
        */
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $raw);

        $this->assertStringContainsString('.cvb-main.is-focus .cvb-step:not(.is-now)', $css);
        // ⚠️ ادعای قبلی این‌جا **وجودِ** `…:not(.is-now) .cvb-step-i{filter:blur(.6px)}`
        // را قفل کرده بود؛ یعنی یک قاعدهٔ صفر-اثر، نگهبانِ خودش شده بود.
        // چرایش در CloudStoreConfiguratorAuditTest است؛ این‌جا فقط برنگشتن.
        /*
        | ⚠️ ادعای «رشتهٔ filter:blur هیچ‌جا نباشد» زیادی گشاد بود و تصادفی به
        | `backdrop-filter:blur(2px)`ِ پس‌زمینهٔ کشویِ موبایل (panel.css:218)
        | می‌خورد — قاعده‌ای که سال‌هاست هست و به این کار ربطی ندارد.
        | ادعای درست، نبودنِ `filter` روی خودِ زنجیرهٔ کم‌رنگی است؛ چون همان
        | است که containing block می‌سازد و برگهٔ چسبان و داکِ ثابت را می‌شکند.
        */
        $this->assertStringNotContainsString('.cvb-step-i{filter:blur', $css,
            'قاعدهٔ صفر-اثرِ blur نباید برگردد — روی محتوای جمع‌شده اعمال می‌شد و هیچ‌وقت دیده نمی‌شد');
        $this->assertStringNotContainsString('.cvb-main.is-focus{filter', $css,
            'filter روی .cvb-main یعنی containing block — برگهٔ چسبان و داکِ ثابت می‌شکنند');
        $this->assertStringContainsString('.cvb-main.is-focus .cvb-step:not(.is-now){opacity:', $css,
            'کم‌رنگی باید واقعاً با opacity انجام شود، نه با قاعده‌ای که اثر ندارد');
        $this->assertStringNotContainsString('.cvb-wrap.is-focus', $css);

        // حرکتِ کم‌شده محترم است
        $this->assertMatchesRegularExpression(
            '~@media \(prefers-reduced-motion: reduce\)\{[^}]*\.cvb-main\.is-focus~s', $css);

        // و لنگرِ چسبندگی سرِ جایش است — حالا از --header-h مشتق می‌شود، نه ۹۶
        $this->assertStringContainsString('.cvb-slip{position:sticky;top:var(--cvb-top)}', $css);
        $this->assertStringContainsString('body.imp-on .cvb-slip{top:calc(var(--cvb-top) + var(--imp-h))}', $css);
        $this->assertStringNotContainsString('.cvb-wrap{padding-top', $css);
    }

    /** خلاصه باید **همهٔ** انتخاب‌ها را بگوید، نه فقط دیسک */
    public function test_the_slip_summarises_cpu_ram_disk_and_bandwidth(): void
    {
        $this->sickCatalog();
        $html = $this->page('?location=de-frankfurt&plan=cv-4c-8g-80d-de-frankfurt');

        $this->assertMatchesRegularExpression(
            '~<small class="cvb-sspec" id="cvb-s-spec">[^<]*vCPU[^<]*·[^<]*·[^<]*·[^<]*</small>~u',
            $html, 'خلاصه باید پردازنده و رم و دیسک و ترافیک را با هم بگوید');

        // و قلّاب‌های برگه سرِ جایشان مانده‌اند
        $this->assertStringContainsString('<b id="cvb-s-plan">', $html);
        $this->assertStringContainsString('id="cvb-h-low"', $html);
        $this->assertSame(1, substr_count($html, 'id="cvb-s-first"'));
        $this->assertSame(1, substr_count($html, 'id="cvb-d-first"'));
    }

    // ═══════════════════ ۵ — سه‌زبانگی ═══════════════════

    /** کلیدهای تازه در هر سه فایل، با مقدارِ واقعاً ترجمه‌شده */
    public function test_the_new_location_strings_exist_and_differ_in_all_three_languages(): void
    {
        $fa = (array) require lang_path('fa/ui.php');
        $en = (array) require lang_path('en/ui.php');
        $tr = (array) require lang_path('tr/ui.php');

        $this->assertSame(array_keys($fa), array_keys($en), 'کلیدهای fa و en باید هم‌ترتیب باشند');
        $this->assertSame(array_keys($fa), array_keys($tr), 'کلیدهای fa و tr باید هم‌ترتیب باشند');

        /*
        | ⚠️ `cvb_pick_country` عمداً از این فهرست حذف شد و خودِ کلید هم پاک شد.
        | آن جمله («ابتدا کشور را انتخاب کنید») بی‌قید چاپ می‌شد، در حالی که
        | کنترلر همیشه یک کشور را از پیش انتخاب می‌کند — یعنی متن روی هر
        | بارگذاری با وضعیتِ صفحه تناقض داشت. جمله‌ای که همیشه دروغ است باید
        | برداشته شود، نه اینکه با شرط پنهان شود.
        */
        foreach (['cvb_h1', 'cvb_c_pick', 'cvb_c_cities',
            'cvb_c_soldout', 'cvb_dc_multi', 'cvb_dc_n', 'cvb_os_group', 'sec_add_server'] as $k) {
            $this->assertArrayHasKey($k, $fa);
            $this->assertNotSame($fa[$k], $en[$k], "«{$k}» انگلیسی ترجمه نشده");
            $this->assertNotSame($fa[$k], $tr[$k], "«{$k}» ترکی ترجمه نشده");
        }
    }

    /** عنوانِ صفحه یک جا تعریف شده و در هر سه زبان رندر می‌شود */
    public function test_the_page_title_is_defined_once_and_rendered_in_all_three_locales(): void
    {
        $this->sickCatalog();
        $c = $this->customer();

        foreach ([
            'account.cloud.store' => 'ساخت سرور مجازی',
            'en.account.cloud.store' => 'Create Virtual Server',
            'tr.account.cloud.store' => 'Sanal Sunucu Oluştur',
        ] as $name => $needle) {
            if (! Route::has($name)) {
                continue;
            }

            $html = $this->actingAs($c, 'customer')->get(route($name, [], false))->assertOk()->getContent();

            // سرصفحه **و** مسیرِ راهنما، هر دو از همان یک کلید
            $this->assertGreaterThanOrEqual(2, substr_count($html, $needle), "عنوان در «{$name}» نیست");
            $this->assertStringNotContainsString('ui.cvb', $html);
            $this->assertStringNotContainsString('{{', $html);
        }

        // و رشتهٔ خامِ Blade جایی عنوان را سخت‌کد نکرده
        $blade = (string) file_get_contents(resource_path('views/account/cloud-store.blade.php'));
        $this->assertStringNotContainsString('ساخت سرور مجازی', $blade, 'عنوان نباید در Blade سخت‌کد شود');
    }
}
