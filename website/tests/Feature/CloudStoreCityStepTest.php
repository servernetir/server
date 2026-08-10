<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * «شهر، مرحلهٔ خودش» — و مرگِ واقعیِ نام‌های تکراری.
 *
 * ═══ چرا این فایل وجود دارد ═══
 *
 * پاسِ قبلی `cityIdentity()` + `cityFold()` + `cityBuckets()` را ساخت، ۱۴ تست و
 * ۱۵۲ ادعا سبز کرد، و **باگ زنده ماند**. کارفرما دوباره گزارشش داد.
 *
 * علت ساده بود: آن تست‌ها لایهٔ اشتباهی را می‌سنجیدند. `cityIdentity()` کلیدِ
 * سطل را می‌سازد و کلیدها **درست** بودند؛ تکرار روی محورِ **برچسب** مینت
 * می‌شد. بدتر: دو تست عیناً ادعا می‌کردند سه کدِ آلمان باید «برلین» چاپ کنند و
 * کارتِ کشور باید «۳ شهر» بگوید — یعنی محافظِ باگ بودند، نه نگهبانش.
 *
 * پس هر ادعای این فایل روی **HTMLِ رندرشدهٔ روتِ واقعی** است، نه روی بازگشتیِ
 * یک تابع. اگر روزی دوباره «برلین × ۳» روی صفحه ظاهر شود، این فایل قرمز
 * می‌شود؛ چه علتش برچسب باشد، چه کلید، چه یک لایهٔ تازه.
 *
 * ⚠️ دیتابیسِ محلی این را بازتولید نمی‌کند (۷ مکانِ تمیز). فیکسچر عمداً
 * شکل‌های **پروداکشن** را می‌سازد: شهرِ خالی، «AMD»/«Shared»/«NVMe»/«vds» در
 * ستونِ شهر، کدِ بی‌ردیفِ `cloud_locations`، و لاتینِ ترجمه‌نشده.
 */
class CloudStoreCityStepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

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

    // ═══════════════════ فیکسچرِ بیمار ═══════════════════

    /** @var array<int, array{0:string,1:string,2:?string}> */
    private const SICK = [
        ['de-frankfurt',    'DE', 'Frankfurt'],   // شهرِ سالم
        ['de-falkenstein',  'DE', 'Falkenstein'], // شهرِ سالمِ دوم
        ['de-de-amd',       'DE', 'AMD'],         // ردهٔ محصول در ستونِ شهر
        ['de-de-shared',    'DE', null],          // بی‌شهر
        ['de-nvme',         'DE', 'NVMe'],        // ردهٔ محصولِ دیگر
        ['nl-amsterdam',    'NL', 'Amsterdam'],   // آمستردامِ واقعی…
        ['nl-nl-shared',    'NL', null],          // …کنارِ سه کدی که «آمستردام» چاپ می‌کردند
        ['nl-nl-dedicated', 'NL', 'Dedicated'],
        ['fr-fr-shared',    'FR', null],
        ['fr-fr-dedicated', 'FR', 'Dedicated'],
        ['ch-zurich',       'CH', 'Zurich'],      // یک شهر با دو املا…
        ['ch-z-rich',       'CH', 'Zürich'],      // …چون slug() بایت‌محور است
        ['tr-ist',          'TR', 'ist'],         // توکنِ خام — لاتین وسطِ فارسی
        ['tr-vds',          'TR', 'vds'],         // «آنکارا»ی ساختگی
        ['ir-tehran',       'IR', 'Tehran'],      // تهرانِ واقعی
        ['ir-ref',          'IR', null],          // 🔴 «تهران» چاپ می‌کرد و تهران نیست
    ];

    /** پایتخت‌هایی که تا دیروز به‌جای نامِ شهر چاپ می‌شدند */
    private const INVENTED = ['برلین', 'آمستردام', 'پاریس', 'آنکارا', 'لندن'];

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'city'.random_int(1, 999999).'@example.com',
            'phone' => '0913'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** @return array<int,string> کدهای ساخته‌شده */
    private function sickCatalog(): array
    {
        CloudImage::firstOrCreate(['provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04'], [
            'key' => 'ubuntu-24.04', 'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04',
            'label' => 'Ubuntu 24.04', 'arch' => 'x86', 'min_disk_gb' => 5, 'is_active' => true,
        ]);

        $i = 0;

        foreach (self::SICK as [$code, $country, $city]) {
            $i++;
            CloudLocation::create(['code' => $code, 'country' => $country, 'city' => $city, 'is_active' => true]);
            $this->pair($code, 570000 + $i * 10000);
        }

        // کدی که اصلاً ردیفِ `cloud_locations` ندارد (سینکِ نیم‌بند). کنترلر
        // ردیفِ ذخیره‌نشده از خودِ کد می‌سازد و تا دیروز «لندن» چاپ می‌شد.
        $this->pair('gb-amd', 545000);

        return array_merge(array_column(self::SICK, 0), ['gb-amd']);
    }

    private function pair(string $code, int $irt): void
    {
        foreach ([
            ['CV-2-4', 'cv-2c-4g-40d-'.$code, 2, 4096, 40, $irt],
            ['CV-4-8', 'cv-4c-8g-80d-'.$code, 4, 8192, 80, $irt * 2],
        ] as [$name, $slug, $v, $r, $d, $price]) {
            CloudPlan::create([
                'provider' => 'hetzner', 'provider_ref' => $slug, 'provider_location' => 'fsn1',
                'location_code' => $code, 'public_name' => $name, 'slug' => $slug,
                'vcpu' => $v, 'ram_mb' => $r, 'disk_gb' => $d, 'disk_type' => 'nvme',
                'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
                'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => $price,
                'is_active' => true, 'in_stock' => true,
            ]);
        }
    }

    private function page(string $qs = '', ?Customer $c = null, string $route = 'account.cloud.store'): string
    {
        return $this->actingAs($c ?? $this->customer(), 'customer')
            ->get(route($route, [], false).$qs)->assertOk()->getContent();
    }

    /**
     * متنِ دیدنیِ هر کنترلِ شهر در HTMLِ رندرشده.
     *
     * ⚠️ عمداً از `<b>`ِ درونِ لنگر خوانده می‌شود، نه از کلِ innerHTML: چیپِ
     * «موجود نیست» و لنگرِ قیمت هم داخلِ همان لنگرند و ادعای «متنِ یکسان»
     * باید دربارهٔ **نامِ مکان** باشد.
     *
     * @return array<string,string> code => label
     */
    private function cityControls(string $html): array
    {
        preg_match_all(
            '~<a class="cvb-city[^"]*"[^>]*data-city="([^"]+)"[^>]*>.*?<b>(.*?)</b>~su',
            $html, $m, PREG_SET_ORDER);

        $out = [];

        foreach ($m as $row) {
            $out[$row[1]] = trim(html_entity_decode(strip_tags($row[2])));
        }

        return $out;
    }

    // ═══════════════ ۱ — تکرار، از خروجیِ رندرشده ═══════════════

    /**
     * 🔴 ادعای اصلی. در یک کشور، هیچ دو کنترلِ شهری متنِ یکسان ندارند.
     *
     * پیش از این کار، همین ادعا روی همین فیکسچر می‌داد:
     *   آلمان → برلین ×۳ · هلند → آمستردام ×۳ · فرانسه → پاریس ×۲ · ایران → تهران ×۲
     */
    public function test_no_two_city_controls_in_one_country_carry_the_same_text(): void
    {
        $codes = $this->sickCatalog();
        $c = $this->customer();
        $seen = 0;

        foreach ($codes as $code) {
            $labels = array_values($this->cityControls($this->page('?location='.$code, $c)));

            $this->assertNotSame([], $labels, "مرحلهٔ شهر برای «{$code}» خالی رندر شد");
            $this->assertSame(
                array_values(array_unique($labels)), $labels,
                "نامِ شهرِ تکراری در صفحهٔ «{$code}»: ".implode(' | ', $labels));

            $seen++;
        }

        $this->assertSame(count($codes), $seen);
    }

    /** پایتخت هرگز به‌جای نامِ شهر چاپ نمی‌شود — نه «برلین»ی که برلین نیست. */
    public function test_a_capital_is_never_printed_as_the_city_of_a_cityless_code(): void
    {
        $this->sickCatalog();
        $c = $this->customer();

        foreach (['de-de-amd', 'de-de-shared', 'de-nvme', 'nl-nl-shared', 'nl-nl-dedicated',
            'fr-fr-shared', 'fr-fr-dedicated', 'tr-vds', 'tr-ist', 'ir-ref', 'gb-amd'] as $code) {
            $label = $this->cityControls($this->page('?location='.$code, $c))[$code] ?? '';

            $this->assertNotSame('', $label, "کنترلِ «{$code}» رندر نشد");

            foreach (self::INVENTED as $capital) {
                $this->assertStringNotContainsString($capital, $label,
                    "«{$code}» شهر ندارد ولی «{$capital}» چاپ شد");
            }
        }
    }

    /** لاتینِ ترجمه‌نشده وسطِ یک صفحهٔ راست‌به‌چپ، خودش یک باگِ دیداری است. */
    public function test_no_farsi_city_control_prints_latin_script(): void
    {
        $codes = $this->sickCatalog();
        $c = $this->customer();

        foreach ($codes as $code) {
            foreach ($this->cityControls($this->page('?location='.$code, $c)) as $k => $label) {
                $this->assertDoesNotMatchRegularExpression('~[A-Za-z]~', $label,
                    "کنترلِ «{$k}» در فارسی لاتین چاپ می‌کند: «{$label}»");
            }
        }
    }

    // ═══════════════ ۲ — هیچ‌چیزی نارس نشد ═══════════════

    /**
     * 🔴 نگهبانِ درآمد. هر `location_code`ِ فروختنی دقیقاً **یک** کنترلِ رسیدنی
     * دارد و صفحه‌اش هنوز همان اسلاگ‌ها را می‌فروشد.
     *
     * ⚠️ یکتاسازی این‌جا هیچ سطلی را **ادغام نمی‌کند** — فقط برچسب را عوض
     * می‌کند. پس این ادعا ساختاری برقرار است، نه وابسته به درستیِ یک هش. اگر
     * روزی کسی «ساده‌سازی» کند و دو کد را زیرِ یک لینک ببرد، همین‌جا قرمز می‌شود.
     */
    public function test_every_sellable_location_code_is_reachable_as_exactly_one_city_control(): void
    {
        $this->sickCatalog();
        $c = $this->customer();

        $codes = CloudPlan::query()->distinct()->pluck('location_code')->filter()->all();
        $this->assertGreaterThan(10, count($codes), 'فیکسچر لاغر شده — برش کهنه است');

        foreach ($codes as $code) {
            $own = $this->page('?location='.$code, $c);

            $this->assertSame(1, substr_count($own, 'data-city="'.$code.'"'),
                "«{$code}» باید دقیقاً یک کنترل داشته باشد");
            $this->assertMatchesRegularExpression(
                '~<a class="cvb-city[^"]*"[^>]*href="[^"]*location='.preg_quote($code, '~').'&~',
                $own, "لینکِ «{$code}» شکسته یا حذف شده");

            foreach (['cv-2c-4g-40d-'.$code, 'cv-4c-8g-80d-'.$code] as $slug) {
                $this->assertStringContainsString('data-slug="'.$slug.'"', $own,
                    "اسلاگِ «{$slug}» در مکانِ «{$code}» دیگر خریدنی نیست");
            }
        }
    }

    /** و هر کشور از صفحهٔ نخست یک ردیفِ کلیک‌شدنی دارد که به همان کشور می‌رود. */
    public function test_every_country_has_one_row_on_the_first_screen(): void
    {
        $this->sickCatalog();
        $html = $this->page();

        preg_match_all('~<a class="cvb-nat[^"]*"[^>]*data-nat="([^"]+)"[^>]*href="[^"]*location=([^"&]+)"~u',
            $html, $m, PREG_SET_ORDER);

        $isos = array_column($m, 1);

        // ترتیب از CloudStoreController::REGIONS می‌آید: اول «خاورمیانه و اطراف»
        // با ایران در صدر (مخاطبِ اولِ این صفحه فارسی‌زبان است)، بعد اروپا،
        // بعد بقیهٔ جهان. هیچ‌کدام از سه ناحیه با ردِ پای یک زیرساخت یک‌به‌یک
        // نمی‌خواند، پس نقشه لباسِ نشتِ نام نیست.
        $this->assertSame(['IR', 'TR', 'DE', 'NL', 'FR', 'GB', 'CH'], $isos,
            'ردیفِ کشور یا جا افتاده یا ترتیبِ ناحیه‌ای به‌هم خورده');
        $this->assertSame($isos, array_unique($isos));

        // مقصدِ هر ردیف واقعاً در همان کشور است
        foreach ($m as $row) {
            $this->assertSame($row[1], strtoupper(explode('-', $row[2])[0]),
                "ردیفِ «{$row[1]}» به کشورِ دیگری لینک می‌دهد");
        }
    }

    // ═══════════════ ۳ — شهر، مرحلهٔ خودش ═══════════════

    public function test_the_flow_is_five_steps_and_city_is_the_second(): void
    {
        $this->sickCatalog();
        $html = $this->page();

        $this->assertSame(5, substr_count($html, 'class="cvb-step-b"'),
            'مسیر باید پنج مرحله داشته باشد: کشور ← شهر ← مشخصات ← سیستم‌عامل ← نام');

        foreach ([1, 2, 3, 4, 5] as $n) {
            $this->assertStringContainsString('id="cvb-step-'.$n.'"', $html);
            $this->assertStringContainsString('id="cvb-b-'.$n.'"', $html);
        }

        // مرحلهٔ ۲ واقعاً پرسشِ شهر است، و شهرها **بیرونِ** مرحلهٔ ۱ رندر می‌شوند
        $s1 = substr($html, (int) strpos($html, 'id="cvb-step-1"'),
            (int) strpos($html, 'id="cvb-step-2"') - (int) strpos($html, 'id="cvb-step-1"'));

        $this->assertStringNotContainsString('class="cvb-city', $s1,
            'شهر نباید داخلِ کارتِ کشور باشد — همان چیزی که کارفرما خواست بیرون بیاید');
        $this->assertStringContainsString(__('ui.cvb_s_city'), $html);

        // و تیرکِ پنج‌گره‌ای هست
        $this->assertSame(5, substr_count($html, 'class="cvb-sp '), 'تیرکِ مسیر باید پنج گره داشته باشد');
    }

    /**
     * پیش از انتخابِ کشور، مرحلهٔ شهر **جمله می‌گوید**، کنترلِ خالی نمی‌سازد.
     *
     * 🔴 و این شاخه رسیدنی است، نه تزئینی: `?location=` (خالی و صریح) دقیقاً
     * همین وضع را می‌سازد و لینکِ «تغییر کشور» روی همان صفحه همین آدرس است.
     */
    public function test_the_city_step_says_what_it_needs_before_a_country_is_chosen(): void
    {
        $this->sickCatalog();
        $html = $this->page('?location=');

        $this->assertStringContainsString(__('ui.cvb_city_none_t'), $html);
        $this->assertStringContainsString(__('ui.cvb_city_none_p'), $html);
        $this->assertStringContainsString('class="cvb-void-go" data-go="1"', $html,
            'حالتِ خالی باید راهِ برگشت داشته باشد');

        // هیچ شهری رندر نشده، هیچ کشوری تیک نخورده، و دکمهٔ پرداخت بسته است
        $this->assertSame([], $this->cityControls($html));
        $this->assertDoesNotMatchRegularExpression('~class="cvb-nat[^"]*\bon\b~', $html);
        $this->assertMatchesRegularExpression('~id="cvb-submit"[^>]*disabled~', $html);

        // ولی ردیف‌های کشور سرِ جایشان‌اند تا بشود ادامه داد
        $this->assertMatchesRegularExpression('~<a class="cvb-nat~', $html);

        // و بدونِ پارامتر، رفتارِ دیروز عوض نشده: یک مکان از پیش انتخاب است
        $this->assertMatchesRegularExpression('~name="location" value="[a-z0-9-]+"~', $this->page());
    }

    /** لینکِ «تغییر کشور» همان آدرسی را می‌سازد که حالتِ خالی به آن بند است. */
    public function test_the_change_country_link_produces_the_empty_state_url(): void
    {
        $this->sickCatalog();
        $html = $this->page();

        $this->assertMatchesRegularExpression(
            '~<a class="cvb-textlink" href="[^"]*\?location="~', $html,
            'راهِ رسیدن به حالتِ خالی باید روی خودِ صفحه باشد، وگرنه شاخهٔ مرده است');
    }

    // ═══════════════ ۴ — قلّاب‌های پولی ═══════════════

    /** 🔴 این‌ها را تست‌های دیگر عیناً می‌سنجند؛ بازطراحی حق ندارد جابه‌جاشان کند. */
    public function test_the_load_bearing_hooks_survive_the_redesign(): void
    {
        $this->sickCatalog();
        $html = $this->page('?location=de-frankfurt&plan=cv-4c-8g-80d-de-frankfurt');

        $this->assertMatchesRegularExpression(
            '~class="cvb-plan\s+on\s*"\s+data-slug="cv-4c-8g-80d-de-frankfurt"~', $html,
            'بعد از «on» هیچ کلاسی نیاید و data-slug بلافاصله بعدش بیاید');
        $this->assertStringContainsString('<b id="cvb-s-plan">', $html);
        $this->assertStringContainsString('id="cvb-h-low"', $html);
        $this->assertStringContainsString('class="cvb-tear"', $html);
        $this->assertSame(1, substr_count($html, 'id="cvb-s-first"'));
        $this->assertSame(1, substr_count($html, 'id="cvb-d-first"'));
        $this->assertStringContainsString('<details class="cvb-adv"', $html);
        $this->assertStringContainsString('class="cvb-dock"', $html);
    }

    /** سطحِ تازه هم هیچ نامِ زیرساختی بیرون نمی‌دهد. */
    public function test_the_new_city_step_leaks_no_provider_identity(): void
    {
        $this->sickCatalog();
        $c = $this->customer();

        foreach (['', '?location=ch-zurich', '?location=de-de-amd', '?location='] as $qs) {
            $html = (string) preg_replace('~<a\b[^>]*href="[^"]*/dedicated/[^"]*"[^>]*>.*?</a>~is', '',
                $this->page($qs, $c));

            foreach (['hetzner', 'Hetzner', 'aeza', 'Aeza', 'fsn1', 'hel1', 'gra7', 'cx22', 'CX22', 'EPs-'] as $secret) {
                $this->assertStringNotContainsString($secret, $html, "«{$secret}» نباید در HTML باشد ({$qs})");
            }
        }
    }

    // ═══════════════ ۵ — سه‌زبانگی ═══════════════

    public function test_the_new_keys_exist_in_all_three_files_in_the_same_order(): void
    {
        $fa = (array) require lang_path('fa/ui.php');
        $en = (array) require lang_path('en/ui.php');
        $tr = (array) require lang_path('tr/ui.php');

        $this->assertSame(array_keys($fa), array_keys($en));
        $this->assertSame(array_keys($fa), array_keys($tr));

        foreach (['cvb_s_city', 'cvb_step_country', 'cvb_step_city', 'cvb_city_none_t',
            'cvb_city_none_p', 'cvb_city_none_go', 'cvb_city_other_h', 'cvb_city_other_n',
            'cvb_from', 'cvb_upto_cores', 'cvb_reg_me', 'cvb_reg_eu', 'cvb_reg_other',
            'cvb_spine', 'cvb_step_idx', 'cvb_change_country'] as $k) {
            $this->assertArrayHasKey($k, $fa);
            $this->assertNotSame($fa[$k], $en[$k], "«{$k}» انگلیسی ترجمه نشده");
            $this->assertNotSame($fa[$k], $tr[$k], "«{$k}» ترکی ترجمه نشده");
        }
    }

    /** و صفحه در هر سه زبان بی‌کلیدِ خام رندر می‌شود. */
    public function test_the_page_renders_in_all_three_locales_without_raw_keys(): void
    {
        $this->sickCatalog();
        $c = $this->customer();

        foreach (['account.cloud.store', 'en.account.cloud.store', 'tr.account.cloud.store'] as $name) {
            if (! Route::has($name)) {
                continue;
            }

            $html = $this->page('', $c, $name);

            $this->assertStringNotContainsString('ui.cvb', $html, "کلیدِ خام در «{$name}»");
            $this->assertStringNotContainsString('{{', $html);
        }
    }

    // ═══════════════ ۶ — چیزهایی که فقط CSS می‌داند ═══════════════

    /**
     * 🔴 قیمتی که «همیشه جلوی چشم» است باید واقعاً بچسبد.
     *
     * `overflow-x:hidden` روی <body> آن را ظرفِ اسکرول می‌کند در حالی که
     * اسکرولرِ واقعی <html> است، و آن‌وقت هر position:sticky در کلِ سایت مرده
     * است. اندازه‌گیریِ A/B/A در مرورگر: با hidden، بالای برگهٔ قیمت دقیقاً
     * -scrollY بود؛ با clip می‌ایستد.
     */
    public function test_sticky_is_not_killed_by_the_body_scroll_container(): void
    {
        $css = self::css('site.css');

        $this->assertMatchesRegularExpression('~\bbody\{[^}]*overflow-x:clip~', $css,
            'body باید overflow-x:clip باشد وگرنه هیچ ریلِ چسبانی در سایت نمی‌چسبد');
        $this->assertDoesNotMatchRegularExpression('~\bbody\{[^}]*overflow-x:hidden~', $css);
    }

    /** لنگرِ چسبندگی از متغیر می‌آید، نه از عددِ جادوییِ ۹۶ (هدر ۱۱۲px است). */
    public function test_the_sticky_anchor_is_derived_from_the_header_height(): void
    {
        $css = self::css('panel.css');

        $this->assertStringContainsString('--cvb-top:calc(var(--header-h) + var(--sp4))', $css);
        $this->assertStringContainsString('.cvb-slip{position:sticky;top:var(--cvb-top)}', $css);
        $this->assertStringNotContainsString('top:96px', $css,
            'عددِ ۹۶ بازنشسته شد — هدر ۱۱۲px است و زیرِ ۴۰۰px، site.css آن را ۱۳۲ می‌کند');
    }

    /**
     * وارونگیِ سلسله‌مراتب — مهم‌ترین تغییرِ این پاس.
     *
     * مبلغ بلندترین و سنگین‌ترین چیزِ صفحه است، و وزنِ ۶۰۰ ممنوع (IRANSans
     * نیمه‌ضخیم ندارد؛ ۶۰۰ پیکسل‌به‌پیکسل با ۷۰۰ یکی است، پس یک رکابِ ناموجود).
     */
    public function test_the_running_total_leads_the_type_ramp(): void
    {
        $css = self::css('panel.css');

        $this->assertStringContainsString('.cvb-tot{font-size:34px;font-weight:700', $css);
        $this->assertStringContainsString('.cvb-q{font-size:22px;font-weight:700', $css);

        // و رکابِ میانی واقعاً استفاده می‌شود (IRANSans Medium روی هر بارگذاری
        // دانلود می‌شد و در کلِ این فایل یک بار هم به کار نرفته بود)
        $tail = substr($css, (int) strpos($css, '--cvb-top:calc'));
        $this->assertGreaterThan(5, substr_count($tail, 'font-weight:500'));
        $this->assertStringNotContainsString('font-weight:600', $tail,
            'IRANSans نیمه‌ضخیم ندارد — ۶۰۰ یک رکابِ ناموجود است');
    }

    /** پوستهٔ روشن نوشته می‌شود، نه کشف. چهار توکنِ وضعیت فراموش شده بودند. */
    public function test_the_light_theme_defines_the_four_forgotten_status_lines(): void
    {
        $css = self::css('panel.css');
        $light = substr($css, (int) strrpos($css, 'html[data-theme="light"]{'));

        foreach (['--ok-line:', '--warn-line:', '--danger-line:', '--info-line:'] as $token) {
            $this->assertStringContainsString($token, $light,
                "توکنِ «{$token}» در پوستهٔ روشن جا افتاده — حاشیه از پالتِ تیره می‌آید و متن از روشن");
        }
    }

    /**
     * ⚠️ کامنت‌ها **پیش از** هر تطبیق حذف می‌شوند. تلهٔ ثبت‌شدهٔ همین پروژه که
     * سه بار خورده شده: ادعای تست به نثرِ خودمان می‌خورد و سبز/قرمزِ بی‌معنا
     * می‌سازد. این‌جا کامنتِ بالای همان قواعد، عیناً «overflow-x:hidden» و
     * «top:96px» را نقل می‌کند تا معلوم باشد چرا رفته‌اند.
     */
    private static function css(string $file): string
    {
        return (string) preg_replace('~/\*.*?\*/~s',
            '', (string) file_get_contents(public_path('assets/css/'.$file)));
    }
}
