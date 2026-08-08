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
 * «برگهٔ سرور» — بازطراحیِ صفحهٔ سفارشِ سرورِ مجازی.
 *
 * `CloudStoreTest` قراردادِ **پول و تحویل** را نگه می‌دارد؛ این فایل قراردادِ
 * **صفحه** را: چه چیزی واقعاً رندر می‌شود و چه چیزی هرگز نباید.
 *
 * ⚠️ «کدِ ۲۰۰ یعنی هیچ» — هیچ ادعایی این‌جا به وضعیتِ پاسخ تکیه نمی‌کند.
 * ⚠️ هیچ تماسِ واقعیِ زیرساخت: هر تستی که ممکن است به شبکه برسد `Http::fake()`
 *    دارد و در پایان `Http::assertNothingSent()` می‌دهد.
 */
class CloudStoreSlipTest extends TestCase
{
    use RefreshDatabase;

    private function u(): string
    {
        return route('account.cloud.store', [], false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // همان بازسازیِ ترتیبِ روت‌ها که CloudStoreTest دارد: در routes/web.php
        // این دو روت **پیش از** روتِ همه‌گیرِ /{loc}/{rest?} ثبت می‌شوند.
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

    // ═══════════════════ داده ═══════════════════

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'slip'.random_int(1, 999999).'@example.com',
            'phone' => '0913'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function plan(array $over = []): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-frankfurt', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-frankfurt',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ], $over));
    }

    private function image(array $over = []): CloudImage
    {
        return CloudImage::create(array_merge([
            'provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => 'x86', 'min_disk_gb' => 5, 'is_active' => true,
        ], $over));
    }

    /** یک مکانِ سالم با دو اندازه که هیچ‌کدام دیگری را حذف نمی‌کند */
    private function base(): void
    {
        CloudLocation::create(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);
        $this->plan();
        $this->plan([
            'provider_ref' => 'cx32', 'public_name' => 'CV-4-8',
            'slug' => 'cv-4c-8g-80d-de-frankfurt',
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80,
            'cost_eur_cents' => 700, 'price_eur_cents' => 1000, 'price_irt' => 1000000,
        ]);
        $this->image();
    }

    private function page(?Customer $c = null, string $qs = ''): string
    {
        return $this->actingAs($c ?? $this->customer(), 'customer')
            ->get($this->u().$qs)->assertOk()->getContent();
    }

    /** فقط متنِ دیداری: money()ِ جاوااسکریپت هر دو واحد را به‌عنوان literal دارد */
    private function visible(string $html): string
    {
        return (string) preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html);
    }

    // ═══════════════════ ناموجودیِ صادقانه ═══════════════════

    /**
     * 🔴 پلنِ ناموجود باید **دیده شود** و بگوید چرا.
     *
     * باگِ صفحاتِ کشور دقیقاً همین بود: یک فیلترِ نمایشی پلنِ فروختنی را پنهان
     * کرد و درآمد رفت. حالا قاعده برعکس است — چیزی که هست ولی الان نمی‌شود
     * خرید، خاکستری می‌ماند نه غیب.
     */
    public function test_an_out_of_stock_plan_is_shown_as_unavailable_instead_of_vanishing(): void
    {
        $this->base();
        // اندازهٔ سومی که فقط ظرفیتش تمام شده
        $this->plan([
            'provider_ref' => 'cx42', 'public_name' => 'CV-8-16',
            'slug' => 'cv-8c-16g-160d-de-frankfurt',
            'vcpu' => 8, 'ram_mb' => 16384, 'disk_gb' => 160,
            'cost_eur_cents' => 1400, 'price_eur_cents' => 2000, 'price_irt' => 2000000,
            'in_stock' => false,
        ]);

        $html = $this->page();

        // هست، یک بار، و **بدونِ** data-slug (تا شمارشِ گروه‌بندی نشکند)
        $this->assertSame(1, substr_count($html, 'data-uslug="cv-8c-16g-160d-de-frankfurt"'));
        $this->assertSame(0, substr_count($html, 'data-slug="cv-8c-16g-160d-de-frankfurt"'));

        // نامش و دلیلِ صادقش روی صفحه است
        $this->assertStringContainsString('CV-8-16', $html);
        $this->assertStringContainsString(__('ui.cvb_off_stock'), $html);
        $this->assertStringContainsString(__('ui.cvb_off_stock_sub'), $html);

        // و هیچ‌جا قیمتی برایش چاپ نشده
        $this->assertStringNotContainsString(fa_num(number_format(2000000)), $html,
            'پلنِ ناموجود نباید قیمت نشان دهد');

        // قابلِ ارسال هم نیست: نه رادیویی دارد نه مقداری
        $this->assertStringNotContainsString('value="cv-8c-16g-160d-de-frankfurt"', $html);
    }

    /**
     * 🔴 `price_irt = 0` عمدی است (نرخِ ارز نرسیده)، پس هرگز نباید به‌شکلِ پول
     * چاپ شود — نه «۰ تومان»، نه «رایگان». CLAUDE.md §۱۰.۵.
     */
    public function test_an_unpriced_plan_says_so_and_never_renders_a_zero_as_money(): void
    {
        $this->base();
        $this->plan([
            'provider_ref' => 'cx42', 'public_name' => 'CV-8-16',
            'slug' => 'cv-8c-16g-160d-de-frankfurt',
            'vcpu' => 8, 'ram_mb' => 16384, 'disk_gb' => 160,
            'cost_eur_cents' => 1400, 'price_eur_cents' => 2000, 'price_irt' => 0,
        ]);

        $html = $this->page();

        $this->assertStringContainsString('data-uslug="cv-8c-16g-160d-de-frankfurt"', $html);
        $this->assertStringContainsString(__('ui.cvb_off_price'), $html);

        // ⚠️ سنجش روی **خودِ کارت**: موجودیِ کیفِ پولِ مشتریِ تازه هم صفر است و
        // آن یک مبلغِ واقعی است، نه قیمتِ پلن. تستِ گشاد هر دو را قاطی می‌کرد.
        preg_match('~<div class="cvb-off cvb-plan" data-uslug="cv-8c-16g-160d-de-frankfurt".*?</div>~s', $html, $m);
        $this->assertNotEmpty($m, 'کارتِ پلنِ بی‌قیمت باید رندر شود');
        $this->assertStringNotContainsString('تومان', $m[0], 'قیمتِ صفر هرگز نباید به‌صورتِ پول رندر شود');
        $this->assertStringNotContainsString('€', $m[0], 'قیمتِ صفر در یورو هم نباید بیاید');
        $this->assertStringNotContainsString('رایگان', $m[0]);
    }

    /** مکانی که همهٔ پلن‌هایش تمام شده، از فهرستِ کشورها حذف نمی‌شود */
    public function test_a_sold_out_location_stays_visible_and_explains_itself(): void
    {
        $this->base();
        CloudLocation::create(['code' => 'fi-helsinki', 'country' => 'FI', 'city' => 'Helsinki', 'is_active' => true]);
        $this->plan([
            'location_code' => 'fi-helsinki', 'provider_location' => 'hel1',
            'slug' => 'cv-2c-4g-40d-fi-helsinki', 'in_stock' => false,
        ]);

        $html = $this->page(null, '?location=fi-helsinki');

        $this->assertStringContainsString('هلسینکی', $html, 'کشورِ تمام‌شده باید همچنان دیده شود');
        $this->assertStringContainsString(__('ui.cvb_loc_off'), $html);
        $this->assertStringContainsString(__('ui.cvb_off_stock'), $html);

        // و دکمهٔ پرداخت بسته است — فرمِ بی‌پلن نباید ارسال‌شدنی باشد
        $this->assertMatchesRegularExpression('~id="cvb-submit"[^>]*disabled~', $html);
    }

    // ═══════════════════ سفیدبرچسبی ═══════════════════

    /**
     * 🔴 مهم‌ترین قاعدهٔ این حوزه. این‌بار **با ردیفِ ناموجود هم**، چون آن ردیف
     * از یک پرس‌وجوی تازه می‌آید که `sellable()` نیست و ستونِ `provider` دارد.
     */
    public function test_no_provider_name_appears_anywhere_on_the_ordering_page(): void
    {
        $this->base();

        // یک ردیفِ ناموجود از زیرساختِ دوم و یک ردیفِ بی‌قیمت از زیرساختِ سوم
        $this->plan([
            'provider' => 'aeza', 'provider_ref' => 'EPs-1', 'provider_location' => 'ru-1',
            'public_name' => 'CV-8-16', 'slug' => 'cv-8c-16g-160d-de-frankfurt',
            'vcpu' => 8, 'ram_mb' => 16384, 'disk_gb' => 160,
            'cost_eur_cents' => 1400, 'price_eur_cents' => 2000, 'price_irt' => 2000000,
            'in_stock' => false,
        ]);
        $this->plan([
            'provider' => 'arvan', 'provider_ref' => 'ar-g2-2', 'provider_location' => 'ir-thr-c2',
            'public_name' => 'CV-16-32', 'slug' => 'cv-16c-32g-320d-de-frankfurt',
            'vcpu' => 16, 'ram_mb' => 32768, 'disk_gb' => 320,
            'cost_eur_cents' => 2800, 'price_eur_cents' => 4000, 'price_irt' => 0,
        ]);
        $this->plan([
            'provider' => 'ovh', 'provider_ref' => 'b2-7', 'provider_location' => 'gra7',
            'public_name' => 'CV-4-8', 'slug' => 'cv-4c-8g-80d-de-frankfurt',
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80,
            'cost_eur_cents' => 900, 'price_eur_cents' => 1200, 'price_irt' => 1200000,
        ]);

        // ⚠️ بدونِ نرخِ ثابت، خودِ رندر برای تبدیلِ یورو به alanchand.com می‌زند و
        // assertNothingSent را قرمز می‌کند — بی‌آنکه هیچ زیرساختی صدا شده باشد.
        \App\Models\Setting::put('pricing_rate_override', '120000');
        Http::fake();
        $html = $this->page();

        // منوی «سرورِ اختصاصیِ برندِ X» محصولِ دیگری است و از دعوی بیرون است
        $own = (string) preg_replace('~<a\b[^>]*href="[^"]*/dedicated/[^"]*"[^>]*>.*?</a>~is', '', $html);

        foreach (['hetzner', 'Hetzner', 'HETZNER', 'aeza', 'Aeza', 'AEZA', 'ovh', 'OVH',
            'arvan', 'Arvan', 'cx22', 'CX22', 'cx32', 'cx42', 'EPs-', 'eps-',
            'fsn1', 'hel1', 'gra7', 'ru-1', 'ir-thr-c2', 'b2-7', 'ar-g2-2'] as $secret) {
            $this->assertStringNotContainsString($secret, $own, "«{$secret}» نباید در HTML باشد");
        }

        Http::assertNothingSent();
    }

    // ═══════════════════ ساختارِ برگه ═══════════════════

    /** برگه واقعاً رندر می‌شود: سطرها، خطِ پارگی، یک مبلغ، یک دکمه */
    public function test_the_slip_renders_its_lines_and_exactly_one_total(): void
    {
        $this->base();

        $html = $this->page(null, '?plan=cv-4c-8g-80d-de-frankfurt');

        $this->assertStringContainsString(__('ui.cvb_slip_h'), $html);
        $this->assertStringContainsString('<b id="cvb-s-plan">CV-4-8</b>', $html);
        $this->assertStringContainsString('id="cvb-s-img"', $html);
        $this->assertStringContainsString('id="cvb-s-cyc"', $html);
        $this->assertStringContainsString('class="cvb-tear"', $html);

        // مبلغِ کل دقیقاً **یک بار** روی برگه است (داکِ موبایل نسخهٔ همان است)
        $this->assertSame(1, substr_count($html, 'id="cvb-s-first"'));
        $this->assertSame(1, substr_count($html, 'id="cvb-d-first"'));

        // و مبلغش همان چیزی است که سرور می‌گیرد
        $plan = CloudPlan::where('slug', 'cv-4c-8g-80d-de-frankfurt')->firstOrFail();
        $price = CloudStoreController::priceForCycle($plan, CloudStoreController::defaultCycle());
        $first = $price + (int) round($price * CloudStoreController::taxPercent() / 100);

        $this->assertStringContainsString(
            '<div class="cvb-tot pnl-num" id="cvb-s-first">'.cloud_price($first).'</div>',
            $html,
            'مبلغِ روی برگه باید دقیقاً همان priceForCycle + مالیات باشد'
        );
    }

    /**
     * بی‌جاوااسکریپت هیچ مرحله‌ای پنهان نمی‌شود — یعنی فرم با اسکریپتِ خاموش
     * هم کامل و ارسال‌شدنی است. (`is-shut` را فقط جاوااسکریپت می‌گذارد.)
     */
    public function test_with_javascript_off_every_step_is_open_and_the_form_still_works(): void
    {
        $this->base();
        $html = $this->page();

        $this->assertSame(4, substr_count($html, 'class="cvb-step-b"'), 'چهار مرحله باید بدنه داشته باشند');
        $this->assertStringNotContainsString('cvb-step is-shut', $html, 'سرور نباید مرحله‌ای را بسته بفرستد');

        // مکان لینکِ واقعی است، نه دکمهٔ جاوااسکریپتی
        $this->assertMatchesRegularExpression('~<a class="cvb-city[^"]*"[^>]*href="[^"]*location=de-frankfurt~', $html);

        // ساعتی چک‌باکسِ ساده مانده: تیک‌نخورده یعنی هیچ‌چیز ارسال نمی‌شود
        $this->assertMatchesRegularExpression(
            '~<input type="checkbox" name="billing_mode" value="hourly"~', $html,
            'ساعتی باید چک‌باکس بماند تا بی‌جاوااسکریپت هم درست ارسال شود'
        );

        // «تنظیمات پیشرفته» یک details بومی است
        $this->assertStringContainsString('<details class="cvb-adv"', $html);
    }

    /** چیپِ شهر انتخاب‌های فعلی را با خودش می‌برد */
    public function test_the_location_chip_carries_the_current_choices(): void
    {
        $this->base();
        CloudLocation::create(['code' => 'fi-helsinki', 'country' => 'FI', 'city' => 'Helsinki', 'is_active' => true]);
        $this->plan(['location_code' => 'fi-helsinki', 'provider_location' => 'hel1',
            'slug' => 'cv-2c-4g-40d-fi-helsinki']);

        $html = $this->page(null, '?location=de-frankfurt&plan=cv-4c-8g-80d-de-frankfurt&cycle=yearly');

        $this->assertMatchesRegularExpression(
            '~href="[^"]*location=fi-helsinki&amp;plan=cv-4c-8g-80d-de-frankfurt&amp;cycle=yearly&amp;image=ubuntu-24\.04"~',
            $html,
            'عوض‌کردنِ شهر نباید پلن و دوره و سیستم‌عامل را دور بریزد'
        );

        // و خودِ دوره از آدرس خوانده شده باشد
        $this->assertStringContainsString('value="yearly" checked', $html);
    }

    // ═══════════════════ فیلترِ پارتو و لینکِ ورودی ═══════════════════

    /**
     * پلنِ «نصفِ پردازنده، دو برابرِ قیمت» حذف می‌شود — همان فیلتری که صفحهٔ
     * بازاریابی از قبل دارد و صفحهٔ خرید نداشت.
     *
     * ⚠️ ولی اسلاگی که یک لینکِ ورودی به آن اشاره می‌کند، هرگز حذف نمی‌شود.
     * فیلترِ نمایشی که مقصدِ یک لینک را ناپدید کند، همان باگِ درآمدی است.
     */
    public function test_a_dominated_plan_is_pruned_but_an_inbound_link_still_finds_it(): void
    {
        $this->base();
        // یک هسته، همان رم، **گران‌تر** → برای هیچ‌کس انتخابِ درستی نیست
        $this->plan([
            'provider_ref' => 'cpx11', 'public_name' => 'CV-1-4',
            'slug' => 'cv-1c-4g-40d-de-frankfurt',
            'vcpu' => 1, 'ram_mb' => 4096, 'disk_gb' => 40,
            'cost_eur_cents' => 800, 'price_eur_cents' => 1100, 'price_irt' => 1100000,
        ]);

        $plain = $this->page();
        $this->assertStringNotContainsString('data-slug="cv-1c-4g-40d-de-frankfurt"', $plain,
            'پلنِ مغلوب نباید در فهرستِ عادی باشد');

        $linked = $this->page(null, '?plan=cv-1c-4g-40d-de-frankfurt');
        $this->assertStringContainsString('data-slug="cv-1c-4g-40d-de-frankfurt"', $linked,
            'لینکِ ورودی نباید به پلنی برسد که بی‌صدا غیب شده');
        $this->assertMatchesRegularExpression(
            '~class="cvb-plan\s+on\s*"\s+data-slug="cv-1c-4g-40d-de-frankfurt"~', $linked);
    }

    /** اسلاگی که اصلاً وجود ندارد، بی‌صدا جایگزین نمی‌شود — صفحه می‌گوید */
    public function test_a_missing_plan_in_the_link_is_announced_not_silently_swapped(): void
    {
        $this->base();

        $html = $this->page(null, '?plan=cv-99c-999g-9999d-de-frankfurt');

        $this->assertStringContainsString(__('ui.cvb_plan_moved'), $html);
    }

    // ═══════════════════ استایل و ترجمه ═══════════════════

    /**
     * ⚠️ استایل و نشانه‌گذاری نباید دوباره از هم جدا شوند.
     *
     * ۱۰۳ خط CSS داخلِ Blade یعنی CssVariablesDefinedTest نمی‌بیندش — و دقیقاً
     * برای همین دو رنگِ خامِ hex و یک `var()`ِ تعریف‌نشده سال‌ها آن‌جا ماندند.
     */
    public function test_the_view_carries_no_css_and_the_vocabulary_lives_in_panel_css(): void
    {
        $blade = file_get_contents(resource_path('views/account/cloud-store.blade.php'));

        $this->assertStringNotContainsString('<style', $blade, 'استایل باید در panel.css باشد');
        $this->assertStringNotContainsString('style="', $blade, 'استایلِ درون‌خطی ممنوع');

        $css = file_get_contents(public_path('assets/css/panel.css'));

        foreach (['.cvb-slip', '.cvb-tear', '.cvb-plan.on', '.cvb-step-b', '.cvb-dock'] as $sel) {
            $this->assertStringContainsString($sel, $css, "«{$sel}» باید در panel.css تعریف شده باشد");
        }

        // برگه زیرِ هدرِ ثابت (۱۱۲px) نمی‌رود — همان چیزی که `.pnl-side` دارد
        $this->assertStringContainsString('.cvb-slip{position:sticky;top:96px}', $css);
        $this->assertStringContainsString('body.imp-on .cvb-slip{top:calc(96px + var(--imp-h))}', $css);

        // صفحهٔ تازه هیچ padding-top خودش نمی‌گذارد (قاعدهٔ هدرِ ثابت)
        $this->assertStringNotContainsString('.cvb-wrap{padding-top', $css);
    }

    /**
     * 🔴 «کلاسِ CSSِ نبود، بی‌هیچ خطایی بی‌استایل رندر می‌شود» (CLAUDE.md §۸).
     *
     * پس هر کلاسی که فرمِ سفارش واقعاً رندر می‌کند باید در یکی از سه شیت
     * تعریف شده باشد. این تنها راهِ گرفتنِ یک غلطِ املایی در نامِ کلاس است —
     * نه کامپایل می‌شود، نه لینت دارد، نه در «صفحه ۲۰۰ می‌دهد» دیده می‌شود.
     */
    public function test_every_class_the_form_renders_is_actually_styled(): void
    {
        $this->base();
        $html = $this->page();

        $start = strpos($html, 'class="cvb-wrap"');
        $end = strpos($html, '</form>');
        $this->assertNotFalse($start, 'فرم باید رندر شود');
        $frag = substr($html, $start, $end - $start);

        preg_match_all('~class="([^"]*)"~', $frag, $m);

        $used = [];
        foreach ($m[1] as $attr) {
            foreach (preg_split('~\s+~', trim($attr)) as $t) {
                if ($t !== '') {
                    $used[$t] = true;
                }
            }
        }

        $css = '';
        foreach (['site.css', 'admin.css', 'panel.css'] as $f) {
            $css .= file_get_contents(public_path('assets/css/'.$f));
        }

        $missing = array_values(array_filter(array_keys($used),
            fn ($c) => ! str_contains($css, '.'.$c)));

        sort($missing);
        $this->assertSame([], $missing,
            "کلاسِ بی‌استایل در فرمِ سفارش:\n  ".implode("\n  ", $missing));

        $this->assertGreaterThan(30, count($used), 'قطعهٔ فرم باید واقعاً استخراج شده باشد');
    }

    /** هیچ کلیدِ خامِ زبان روی صفحه چاپ نشود — در هر سه زبان */
    public function test_every_string_is_translated_in_all_three_locales(): void
    {
        $this->base();
        \App\Models\Setting::put('pricing_rate_override', '120000');

        foreach (['account.cloud.store', 'en.account.cloud.store', 'tr.account.cloud.store'] as $name) {
            $html = $this->actingAs($this->customer(), 'customer')
                ->get(route($name, [], false))->assertOk()->getContent();

            $this->assertStringNotContainsString('ui.cvb', $html, "کلیدِ خام در «{$name}»");
            $this->assertStringNotContainsString('{{', $html, "آکولادِ کامپایل‌نشده در «{$name}»");
        }

        // و کلیدهای تازه در هر سه فایل هستند و هیچ‌کدام به فارسی نمانده‌اند
        foreach (['cvb_slip_h', 'cvb_pay', 'cvb_off_stock', 'cvb_e_plan', 'cvb_a_location'] as $k) {
            $fa = (array) require lang_path('fa/ui.php');
            $en = (array) require lang_path('en/ui.php');
            $tr = (array) require lang_path('tr/ui.php');

            $this->assertArrayHasKey($k, $fa);
            $this->assertArrayHasKey($k, $en);
            $this->assertArrayHasKey($k, $tr);
            $this->assertNotSame($fa[$k], $en[$k], "«{$k}» انگلیسی ترجمه نشده");
            $this->assertNotSame($fa[$k], $tr[$k], "«{$k}» ترکی ترجمه نشده");
        }
    }

    /** سه فایلِ زبان باید کلیدبه‌کلید برابر بمانند */
    public function test_the_three_language_files_stay_key_identical(): void
    {
        $fa = array_keys((array) require lang_path('fa/ui.php'));
        $en = array_keys((array) require lang_path('en/ui.php'));
        $tr = array_keys((array) require lang_path('tr/ui.php'));

        $this->assertSame($fa, $en, 'کلیدهای fa و en باید یکی باشند');
        $this->assertSame($fa, $tr, 'کلیدهای fa و tr باید یکی باشند');
    }

    // ═══════════════════ افزودنی و قیمتِ زنده ═══════════════════

    /**
     * 🔴 عددِ روی برگه با مبلغی که فاکتور می‌گیرد یکی باشد — **با** IP اضافه.
     *
     * قبلاً `priceMap` بی‌افزودنی ساخته می‌شد ولی `order()` با افزودنی شارژ
     * می‌کرد، و انتخابگرِ IP هیچ شنونده‌ای نداشت. یعنی دکمه یک عدد نشان می‌داد و
     * فاکتور عددِ دیگری می‌گرفت.
     */
    public function test_the_slip_total_matches_the_invoice_when_an_extra_ip_is_chosen(): void
    {
        $this->base();
        // بی‌نرخِ یورو، قیمتِ افزودنی صفر می‌شود و تست بی‌صدا هیچ‌چیز نمی‌سنجد
        \App\Models\Setting::put('pricing_rate_override', '120000');
        $customer = $this->customer();

        // یک تلاشِ ناموفق تا ورودی‌ها با old() به صفحه برگردند
        $this->actingAs($customer, 'customer')->post(route('account.cloud.store.place', [], false), [
            'location' => 'de-frankfurt',
            'plan' => 'cv-2c-4g-40d-de-frankfurt',
            'image' => 'not-a-real-image',
            'cycle' => 'monthly',
            'extra_ipv4' => 2,
        ])->assertSessionHasErrors('image');

        $html = $this->actingAs($customer, 'customer')->get($this->u())->assertOk()->getContent();

        $plan = CloudPlan::where('slug', 'cv-2c-4g-40d-de-frankfurt')->firstOrFail();
        $price = CloudStoreController::priceForCycle($plan, 'monthly', ['extra_ipv4' => 2]);
        $first = $price + (int) round($price * CloudStoreController::taxPercent() / 100);

        $this->assertGreaterThan(
            CloudStoreController::priceForCycle($plan, 'monthly'),
            $price,
            'IP اضافه باید واقعاً به مبلغ اضافه شود، وگرنه این تست چیزی نمی‌سنجد'
        );

        $this->assertStringContainsString(
            '<div class="cvb-tot pnl-num" id="cvb-s-first">'.cloud_price($first).'</div>',
            $html,
            'مبلغِ برگه باید شاملِ IP اضافه باشد'
        );
    }

    /**
     * ⚠️ ظرفیتِ افزودنی به‌ازای **هر اسلاگ** فرستاده می‌شود، نه یک بولینِ واحد.
     * قبلاً عوض‌کردنِ پلن انتخابگرِ IP را روی پلنی جا می‌گذاشت که سرِ ثبتِ سفارش
     * ردش می‌کرد.
     */
    public function test_the_page_ships_a_per_slug_addon_capability_map(): void
    {
        $this->base();
        $html = $this->page();

        $this->assertMatchesRegularExpression(
            '~"addon":\{"cv-2c-4g-40d-de-frankfurt":(true|false),"cv-4c-8g-80d-de-frankfurt":(true|false)\}~',
            $html,
            'نقشهٔ ظرفیتِ افزودنی باید به‌ازای هر اسلاگ باشد'
        );
    }
}
