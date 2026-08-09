<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * سرورساز در پنلِ مشتری — «کاربر خودش سرور بسازد».
 *
 * تمرکز روی چیزهایی که خرابی‌شان گران است:
 *  • **سفیدبرچسبی**: نامِ زیرساخت نباید از هیچ درزی به HTML برسد.
 *  • **پول**: مبلغ از دیتابیس بیاید نه از ورودیِ کاربر؛ دوره‌ها از config.
 *  • **تحویل‌شدنی بودن**: گزینه‌ای که ساخته نمی‌شود نباید فروخته شود.
 *  • **سفارشِ پرداخت‌نشده هرگز سرورِ واقعی نخرد.**
 *
 * ⚠️ «کدِ ۲۰۰ یعنی هیچ» — پس صفحه واقعاً رندر و محتوایش سنجیده می‌شود.
 */
class CloudStoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * مسیرِ صفحه از خودِ روت خوانده می‌شود، نه سخت‌کد — اگر هماهنگ‌کننده مسیر را
     * جای دیگری ثبت کند، تست همان را می‌آزماید نه یک آدرسِ خیالی.
     */
    private function u(): string
    {
        return route('account.cloud.store', [], false);
    }

    private function up(): string
    {
        return route('account.cloud.store.place', [], false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // روت‌ها هنوز ممکن است در routes/web.php نباشند (هماهنگ‌کننده اعمالشان
        // می‌کند). تست باید هر دو حالت را بپوشاند، پس اگر نبودند ثبتشان می‌کنیم.
        if (! Route::has('account.cloud.store')) {
            Route::middleware(['web', 'auth:customer'])->prefix('account')->name('account.')->group(function () {
                Route::get('/cloud-store', [CloudStoreController::class, 'index'])->name('cloud.store');
                Route::post('/cloud-store', [CloudStoreController::class, 'order'])->name('cloud.store.place');
            });

            /*
             * ⚠️ ترتیب مهم است. در routes/web.php این روت‌ها داخل closure ‎$site‎ و
             * **پیش از** روتِ همه‌گیرِ ‎/{loc}/{rest?}‎ (هدایتِ پیشوندِ بزرگ) ثبت
             * می‌شوند. این‌جا دیرتر ثبت می‌شوند، پس همان روتِ همه‌گیر
             * «‎/account/cloud-store‎» را می‌قاپد و ۴۰۴ می‌دهد — نه چون کد خراب
             * است، فقط چون ترتیب فرق دارد. پس ترتیبِ تولید را بازمی‌سازیم.
             */
            $mine = ['account.cloud.store', 'account.cloud.store.place'];
            $ordered = new RouteCollection;

            foreach (collect(Route::getRoutes()->getRoutes())
                ->sortBy(fn ($r) => in_array($r->getName(), $mine, true) ? 0 : 1)->all() as $route) {
                $ordered->add($route);
            }

            Route::setRoutes($ordered);
        }
    }

    // ═══════════════════ داده‌های آزمایشی ═══════════════════

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'vps'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function loc(string $code, string $country, string $city): CloudLocation
    {
        return CloudLocation::create([
            'code' => $code, 'country' => $country, 'city' => $city, 'is_active' => true,
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

    /** کاتالوگِ کاملِ آزمایشی: دو کشور، سه پلن، سه ایمیجِ ممکن + یکی نشدنی */
    private function catalog(): void
    {
        $this->loc('de-frankfurt', 'DE', 'Frankfurt');
        $this->loc('fi-helsinki', 'FI', 'Helsinki');

        $this->plan();
        $this->plan([
            'provider_ref' => 'cx32', 'public_name' => 'CV-4-8',
            'slug' => 'cv-4c-8g-80d-de-frankfurt',
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80,
            'cost_eur_cents' => 700, 'price_eur_cents' => 1000, 'price_irt' => 1000000,
        ]);
        $this->plan([
            'location_code' => 'fi-helsinki', 'provider_location' => 'hel1',
            'slug' => 'cv-2c-4g-40d-fi-helsinki',
        ]);

        $this->image();
        $this->image(['provider_ref' => 'debian-12', 'key' => 'debian-12',
            'family' => 'debian', 'version' => '12', 'label' => 'Debian 12']);
        $this->image(['provider_ref' => 'docker-ce', 'key' => 'app-docker', 'kind' => 'app',
            'family' => 'docker', 'version' => null, 'label' => 'Docker CE']);

        // ⚠️ ۱۰۰ گیگ دیسک می‌خواهد و بزرگ‌ترین پلنِ ما ۸۰ گیگ است → نشدنی
        $this->image(['provider_ref' => 'windows-2022', 'key' => 'windows-2022',
            'family' => 'windows', 'version' => '2022', 'label' => 'Windows Server 2022',
            'min_disk_gb' => 100]);
    }

    private function order(Customer $customer, array $over = [])
    {
        return $this->actingAs($customer, 'customer')->post($this->up(), array_merge([
            'location' => 'de-frankfurt',
            'plan' => 'cv-2c-4g-40d-de-frankfurt',
            'image' => 'ubuntu-24.04',
            'cycle' => 'monthly',
        ], $over));
    }

    // ═══════════════════ رندرِ واقعیِ صفحه ═══════════════════

    public function test_page_renders_the_whole_wizard_with_real_content(): void
    {
        $this->catalog();

        $res = $this->actingAs($this->customer(), 'customer')->get($this->u());
        $res->assertOk();
        $html = $res->getContent();

        // گام‌ها و عنوان
        $this->assertStringContainsString('سرور مجازی بساز', $html);

        // کشور و شهر، سه‌زبانه از CloudLocation
        foreach (['آلمان', 'فرانکفورت', 'فینلاند', 'هلسینکی', '🇩🇪', '🇫🇮'] as $needle) {
            $this->assertStringContainsString($needle, $html, "«{$needle}» باید در فهرستِ مکان‌ها باشد");
        }

        // پلن‌های همین مکان با نامِ عمومی و مشخصات
        $this->assertStringContainsString('CV-2-4', $html);
        $this->assertStringContainsString('CV-4-8', $html);
        $this->assertStringContainsString('۴۰ GB NVME', $html, 'دیسک باید با ارقامِ فارسی دیده شود');

        // سیستم‌عامل و نرم‌افزارِ آماده
        $this->assertStringContainsString('Ubuntu 24.04', $html);
        $this->assertStringContainsString('Debian 12', $html);
        $this->assertStringContainsString('Docker CE', $html);

        // دوره‌ها **از config** — هیچ برچسبی سخت‌کد نشده
        foreach (array_keys((array) config('billing.cycles')) as $cycle) {
            $this->assertStringContainsString(Service::labelFor($cycle), $html, "دورهٔ «{$cycle}» باید در فرم باشد");
        }

        // قیمتِ واقعیِ دیتابیس، با ارقامِ فارسی
        $this->assertStringContainsString(fa_num(number_format(570000)), $html);

        // Blade سالم کامپایل شده باشد
        $this->assertStringNotContainsString('{{', $html, 'آکولادِ کامپایل‌نشده نباید بماند');
        $this->assertStringNotContainsString('ui.', $html, 'کلیدِ زبانِ خام نباید چاپ شود');
    }

    /** پلنی که برای این پلن قابلِ تحویل نیست، حتی پنهان هم در HTML نباشد */
    public function test_an_image_that_cannot_fit_any_plan_never_reaches_the_html(): void
    {
        $this->catalog();

        $html = $this->actingAs($this->customer(), 'customer')->get($this->u())->getContent();

        $this->assertStringNotContainsString('Windows Server 2022', $html);
        $this->assertStringNotContainsString('windows-2022', $html);
    }

    /** مهم‌ترین قاعده: نامِ زیرساخت نباید به مشتری برسد */
    public function test_no_provider_name_leaks_into_the_page(): void
    {
        $this->catalog();

        $html = $this->actingAs($this->customer(), 'customer')->get($this->u())->getContent();

        // لینک‌های منوی «سرورِ اختصاصی» محصولِ دیگری‌اند و از دعوی بیرون‌اند
        $own = preg_replace('~<a\b[^>]*href="[^"]*/dedicated/[^"]*"[^>]*>.*?</a>~is', '', $html);

        foreach (['hetzner', 'Hetzner', 'HETZNER', 'aeza', 'Aeza', 'cx22', 'CX22', 'cx32', 'fsn1', 'hel1'] as $secret) {
            $this->assertStringNotContainsString($secret, $own, "«{$secret}» نباید در HTML باشد");
        }
    }

    /** یک مشخصات از دو زیرساخت = یک کارت، با قیمتِ ارزان‌ترین */
    public function test_two_providers_with_the_same_specs_show_one_card_at_the_cheapest_price(): void
    {
        $this->loc('de-frankfurt', 'DE', 'Frankfurt');
        $this->plan();                                            // ۵۷۰٬۰۰۰
        $this->plan([
            'provider' => 'aeza', 'provider_ref' => '77', 'provider_location' => '3',
            'cost_eur_cents' => 300, 'price_eur_cents' => 450, 'price_irt' => 450000,
        ]);
        $this->image();
        $this->image(['provider' => 'aeza', 'provider_ref' => '1042']);

        $html = $this->actingAs($this->customer(), 'customer')->get($this->u())->getContent();

        $this->assertSame(1, substr_count($html, 'data-slug="cv-2c-4g-40d-de-frankfurt"'),
            'مشتری باید یک کارت ببیند، نه دو تا');
        $this->assertStringContainsString(fa_num(number_format(450000)), $html, 'قیمتِ ارزان‌ترین');
        $this->assertStringNotContainsString(fa_num(number_format(570000)), $html);
    }

    /** انتخابِ مکان، فهرستِ پلن‌ها را عوض می‌کند */
    public function test_location_query_switches_the_plan_list(): void
    {
        $this->catalog();
        $customer = $this->customer();

        $de = $this->actingAs($customer, 'customer')->get($this->u().'?location=de-frankfurt')->getContent();
        $this->assertStringContainsString('data-slug="cv-4c-8g-80d-de-frankfurt"', $de);
        $this->assertStringNotContainsString('data-slug="cv-2c-4g-40d-fi-helsinki"', $de);

        $fi = $this->actingAs($customer, 'customer')->get($this->u().'?location=fi-helsinki')->getContent();
        $this->assertStringContainsString('data-slug="cv-2c-4g-40d-fi-helsinki"', $fi);
        $this->assertStringNotContainsString('data-slug="cv-4c-8g-80d-de-frankfurt"', $fi);
    }

    /**
     * دستِ‌دادن با صفحات عمومیِ سایت: دکمهٔ «خرید» آن‌جا به این‌جا با
     * `?location=…&plan=…` لینک می‌دهد و باید همان مکان و همان پلن از قبل
     * انتخاب‌شده بیاید — وگرنه مشتری دوباره از صفر انتخاب می‌کند.
     */
    public function test_location_and_plan_in_the_query_arrive_preselected(): void
    {
        $this->catalog();

        $html = $this->actingAs($this->customer(), 'customer')
            ->get($this->u().'?location=de-frankfurt&plan=cv-4c-8g-80d-de-frankfurt')
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~class="cvb-plan\s+on\s*"\s+data-slug="cv-4c-8g-80d-de-frankfurt"~',
            $html,
            'پلنِ آمده در لینک باید انتخاب‌شده باشد'
        );
        $this->assertStringContainsString('value="cv-4c-8g-80d-de-frankfurt" checked', $html);
        $this->assertStringContainsString('<b id="cvb-s-plan">CV-4-8</b>', $html);
    }

    /** ورودیِ آرایه‌ای در query نباید صفحه را ۵۰۰ کند */
    public function test_malformed_query_input_does_not_break_the_page(): void
    {
        $this->catalog();

        $this->actingAs($this->customer(), 'customer')
            ->get($this->u().'?location[]=x&plan[]=y')
            ->assertOk();
    }

    /** کاتالوگِ خالی: پیامِ روشن، نه صفحهٔ سفید */
    public function test_empty_catalog_explains_itself(): void
    {
        $html = $this->actingAs($this->customer(), 'customer')->get($this->u())
            ->assertOk()->getContent();

        $this->assertStringContainsString('در دسترس نیست', $html);
    }

    // ═══════════════════ ثبتِ سفارش ═══════════════════

    public function test_customer_can_build_a_server_and_gets_a_proforma(): void
    {
        $this->catalog();
        $customer = $this->customer();

        $res = $this->order($customer, ['label' => 'My App!'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame('pending', $service->status);
        $this->assertTrue($service->isCloud());
        $this->assertSame(
            CloudPlan::where('slug', 'cv-2c-4g-40d-de-frankfurt')->value('id'),
            (int) $service->cloud_plan_id
        );
        $this->assertSame('ubuntu-24.04', $service->cloud_image_key);
        $this->assertSame('monthly', $service->cycle);
        $this->assertSame(570000, (int) $service->price);
        $this->assertSame('IRT', $service->currency_code);
        $this->assertStringContainsString('my-app', $service->name, 'نامِ کاربر باید پاک‌سازی و استفاده شود');
        $this->assertStringContainsString('فرانکفورت', (string) $service->description);
        $this->assertStringContainsString('Ubuntu 24.04', (string) $service->description);

        // پیش‌فاکتورِ همان جریانِ موجود
        $invoice = Invoice::where('service_id', $service->id)->firstOrFail();
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame('service', $invoice->kind);
        $this->assertSame(570000, (int) $invoice->subtotal);
        $this->assertSame(627000, (int) $invoice->total, '۵۷۰٬۰۰۰ + ۱۰٪ مالیات');
        $this->assertSame(1, $invoice->items()->count());

        // همان جریانِ پرداختِ موجود، نه یک مسیرِ تازه
        $res->assertRedirect(route('account.invoice', $invoice));
    }

    /**
     * ⚠️ گران‌ترین باگِ ممکن در این مسیر: `provision:run` هر ردیفِ
     * `provision_status=pending` را می‌سازد و **پرداخت را نمی‌سنجد**. پس سفارشِ
     * تازه نباید در صفِ تحویل بنشیند، وگرنه برای فاکتورِ پرداخت‌نشده سرورِ
     * واقعی خریده می‌شود و پولِ ما می‌سوزد.
     */
    public function test_an_unpaid_order_never_buys_a_real_server(): void
    {
        $this->catalog();
        $customer = $this->customer();

        $this->order($customer)->assertRedirect();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertNull($service->provision_status, 'سفارشِ پرداخت‌نشده نباید در صفِ تحویل باشد');

        Http::fake();
        $this->artisan('provision:run')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, CloudInstance::count());
        $this->assertSame('pending', $service->fresh()->status);
    }

    /** مبلغ از دیتابیس می‌آید، نه از ورودیِ کاربر */
    public function test_price_comes_from_the_database_not_from_the_request(): void
    {
        $this->catalog();
        $customer = $this->customer();

        $this->order($customer, [
            'price' => 1000, 'total' => 1, 'price_irt' => 10, 'amount' => 5,
        ])->assertRedirect();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame(570000, (int) $service->price);
        $this->assertSame(627000, (int) Invoice::where('service_id', $service->id)->value('total'));
    }

    /**
     * دوره‌ها و تخفیف‌ها فقط از `config/billing.php`.
     *
     * انتظارِ تست از خودِ config ساخته می‌شود، نه از عددِ سخت‌کد — وگرنه تست
     * همان باگی را که می‌خواهد بگیرد تکرار می‌کند.
     */
    public function test_long_cycles_use_the_configured_discount_and_stay_round(): void
    {
        $this->catalog();

        foreach (['quarterly', 'semiannual', 'yearly'] as $cycle) {
            $customer = $this->customer();
            $this->order($customer, ['cycle' => $cycle])->assertRedirect();

            $months = Service::monthsIn($cycle);
            $pct = (int) config('billing.cycles.'.$cycle.'.discount_pct');
            $expected = Product::roundUpToman(570000 * $months * (100 - $pct) / 100);

            $service = Service::where('customer_id', $customer->id)->firstOrFail();

            $this->assertSame($cycle, $service->cycle);
            $this->assertSame($expected, (int) $service->price, "مبلغِ دورهٔ «{$cycle}»");
            $this->assertSame(0, (int) $service->price % 10000, 'مبلغِ تومانی باید مضربِ ۱۰٬۰۰۰ باشد');
            $this->assertLessThan(570000 * $months, (int) $service->price, 'دورهٔ بلندتر باید ارزان‌تر باشد');
        }
    }

    /** شش‌ماهه واقعاً هست و «یک‌بار» برای اشتراکِ سرور پیشنهاد نمی‌شود */
    public function test_cycle_list_follows_config_and_drops_the_one_off_cycle(): void
    {
        $cycles = CloudStoreController::cycles();

        $this->assertSame(array_keys((array) config('billing.cycles')), $cycles);
        $this->assertContains('semiannual', $cycles, 'دورهٔ شش‌ماهه قبلاً جا افتاده بود');
        $this->assertNotContains('once', $cycles, 'سرورِ اشتراکی دورهٔ «یک‌بار» ندارد');
    }

    // ═══════════════════ اعتبارسنجیِ سرور-محور ═══════════════════

    public function test_unknown_plan_is_rejected(): void
    {
        $this->catalog();

        $this->order($this->customer(), ['plan' => 'cv-99c-999g-9999d-de-frankfurt'])
            ->assertSessionHasErrors('plan');

        $this->assertSame(0, Service::count());
    }

    /** پلنِ مکانِ دیگر را نمی‌شود با دستکاریِ فرم خرید */
    public function test_a_plan_from_another_location_is_rejected(): void
    {
        $this->catalog();

        $this->order($this->customer(), ['plan' => 'cv-2c-4g-40d-fi-helsinki'])
            ->assertSessionHasErrors('plan');

        $this->assertSame(0, Service::count());
    }

    public function test_unknown_location_is_rejected(): void
    {
        $this->catalog();

        $this->order($this->customer(), ['location' => 'ir-tehran'])
            ->assertSessionHasErrors('location');

        $this->assertSame(0, Service::count());
    }

    public function test_unknown_cycle_is_rejected(): void
    {
        $this->catalog();

        $this->order($this->customer(), ['cycle' => 'weekly'])->assertSessionHasErrors('cycle');
        $this->order($this->customer(), ['cycle' => 'once'])->assertSessionHasErrors('cycle');

        $this->assertSame(0, Service::count());
    }

    public function test_arbitrary_image_input_is_rejected(): void
    {
        $this->catalog();

        $this->order($this->customer(), ['image' => '../../etc/passwd'])->assertSessionHasErrors('image');
        $this->order($this->customer(), ['image' => 'windows-2022'])->assertSessionHasErrors('image');

        $this->assertSame(0, Service::count());
    }

    /**
     * ایمیجی که فقط روی زیرساختی هست که این پلن را نمی‌فروشد، قابلِ تحویل
     * نیست — نه در فهرست می‌آید و نه با دستکاریِ فرم پذیرفته می‌شود.
     */
    public function test_image_that_no_provider_of_this_plan_has_is_rejected(): void
    {
        $this->loc('de-frankfurt', 'DE', 'Frankfurt');
        $this->plan();                                             // فقط زیرساختِ ۱
        $this->image();
        // این ایمیج فقط روی زیرساختِ ۲ است و زیرساختِ ۲ این اسلاگ را نمی‌فروشد
        $this->image(['provider' => 'aeza', 'provider_ref' => '2001', 'key' => 'rocky-9',
            'family' => 'rocky', 'version' => '9', 'label' => 'Rocky Linux 9']);

        $html = $this->actingAs($this->customer(), 'customer')->get($this->u())->getContent();
        $this->assertStringNotContainsString('Rocky Linux 9', $html);

        $this->order($this->customer(), ['image' => 'rocky-9'])->assertSessionHasErrors('image');
        $this->assertSame(0, Service::count());
    }

    /**
     * سه حالتی که یک پلن «قابلِ فروش» نیست: ناموجود، بی‌قیمت، غیرفعال.
     *
     * پلنِ دومِ همان مکان دست‌نخورده می‌مانَد تا مکان همچنان موجودی داشته باشد و
     * خطا دقیقاً روی «پلن» بنشیند، نه روی «مکان».
     */
    public function test_out_of_stock_unpriced_or_inactive_plans_cannot_be_ordered(): void
    {
        $this->catalog();
        $plan = CloudPlan::where('slug', 'cv-2c-4g-40d-de-frankfurt')
            ->where('location_code', 'de-frankfurt')->firstOrFail();

        $plan->update(['in_stock' => false]);
        $this->order($this->customer())->assertSessionHasErrors('plan');

        $plan->update(['in_stock' => true, 'price_irt' => 0]);
        $this->order($this->customer())->assertSessionHasErrors('plan');

        $plan->update(['price_irt' => 570000, 'is_active' => false]);
        $this->order($this->customer())->assertSessionHasErrors('plan');

        $this->assertSame(0, Service::count());
    }

    public function test_guest_can_neither_see_nor_order(): void
    {
        $this->catalog();

        $this->get($this->u())->assertRedirect();
        $this->post($this->up(), [
            'location' => 'de-frankfurt', 'plan' => 'cv-2c-4g-40d-de-frankfurt',
            'image' => 'ubuntu-24.04', 'cycle' => 'monthly',
        ])->assertRedirect();

        $this->assertSame(0, Service::count());
        $this->assertSame(0, Invoice::count());
    }

    /** سیلِ سفارش بسته شود — هر ردیفِ pending یک پیش‌فاکتورِ واقعی می‌سازد */
    public function test_order_is_rate_limited(): void
    {
        $this->catalog();
        $customer = $this->customer();

        for ($i = 0; $i < 6; $i++) {
            $this->order($customer)->assertSessionHasNoErrors();
        }

        $this->order($customer)->assertSessionHasErrors();

        $this->assertSame(6, Service::where('customer_id', $customer->id)->count());
    }

    // ═══════════════════ نامِ سرور ═══════════════════

    public function test_server_label_is_sanitised_or_generated(): void
    {
        $this->assertSame('my-app', CloudStoreController::serverLabel('My App!'));
        $this->assertSame('weird-name', CloudStoreController::serverLabel('  --Weird__Name!!  '));
        $this->assertSame('vps-123', CloudStoreController::serverLabel('123'), 'نامِ میزبان باید با حرف شروع شود');
        $this->assertSame('a-b', CloudStoreController::serverLabel('a...b'));

        // خالی → خودکار
        $this->assertMatchesRegularExpression('/^vps-[a-z0-9]{6}$/', CloudStoreController::serverLabel(null));
        $this->assertMatchesRegularExpression('/^vps-[a-z0-9]{6}$/', CloudStoreController::serverLabel('!!!'));

        // فارسی هم به نامِ خودکار می‌رسد، نه نامِ نامعتبر
        $this->assertMatchesRegularExpression('/^vps-[a-z0-9]{6}$/', CloudStoreController::serverLabel('سرور من'));

        $long = CloudStoreController::serverLabel(str_repeat('abcde-', 20));
        $this->assertLessThanOrEqual(CloudStoreController::LABEL_MAX, strlen($long));
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*[a-z0-9]$/', $long);
    }

    public function test_label_is_generated_when_the_customer_leaves_it_empty(): void
    {
        $this->catalog();
        $customer = $this->customer();

        $this->order($customer, ['label' => ''])->assertRedirect();

        $name = (string) Service::where('customer_id', $customer->id)->value('name');
        $this->assertMatchesRegularExpression('/^سرور مجازی vps-[a-z0-9]{6}$/u', $name);
    }

    // ═══════════════════ زبان و ارز (باگی که کارفرما در ترکی دید) ═══════════════════

    /**
     * 🔴 کارفرما: «در ترکی، مبلغ به تومان بود و صفحه جاهایی فارسی». کنسول با
     * پیشوندِ زبان کار می‌کند (‎/en/account‎، ‎/tr/account‎)، پس زبان و ارز باید
     * از همان پیروی کنند: فارسی تومان، انگلیسی/ترکی یورو، و **هیچ نشتِ فارسی**
     * در نسخهٔ خارجی.
     */
    public function test_builder_currency_and_labels_follow_the_console_locale(): void
    {
        $this->catalog();

        // ⚠️ بدونِ این، تست به **اینترنت** وابسته است: `cloud_price()` برای
        // en/tr نرخِ یورو می‌خواهد و `ExchangeRate` آن را زنده از alanchand.com
        // می‌گیرد (timeout 12 × retry 2 ≈ ۴۴ ثانیه). اگر سایت جواب ندهد نرخ صفر
        // می‌شود و `cloud_price()` عمداً «€» نمی‌گذارد — یعنی تستِ ارز، بی‌آنکه
        // چیزی در کد عوض شده باشد، قرمز می‌شد. نرخِ ثابت هم تست را قطعی می‌کند
        // هم ۴۴ ثانیه از هر اجرا کم می‌کند.
        Setting::put('pricing_rate_override', '120000');

        // ⚠️ money() جاوااسکریپت هر دو واحدِ «€» و « تومان» را به‌عنوانِ literal در
        // سورس دارد (در ران‌تایم شاخه می‌زند)، پس سنجشِ ارز روی HTMLِ خام گمراه
        // است. اسکریپت‌ها را کنار می‌گذاریم و روی متنِ **دیداریِ** رندرشده
        // (خروجیِ cloud_price در PHP) قضاوت می‌کنیم.
        $vis = fn (string $h) => preg_replace('~<script\b[^>]*>.*?</script>~is', '', $h);

        // فارسی: تومان، بی‌یورو
        $fa = $vis($this->actingAs($this->customer(), 'customer')
            ->get(route('account.cloud.store', [], false))->assertOk()->getContent());
        $this->assertStringContainsString('تومان', $fa);
        $this->assertStringContainsString('سرور مجازی بساز', $fa);
        $this->assertStringNotContainsString('€', $fa, 'فارسی نباید یورو نشان دهد');

        // انگلیسی: یورو، بی‌تومان، بی‌نشتِ فارسی
        $en = $vis($this->actingAs($this->customer(), 'customer')
            ->get(route('en.account.cloud.store', [], false))->assertOk()->getContent());
        $this->assertStringContainsString('€', $en);
        $this->assertStringNotContainsString('تومان', $en, 'نسخهٔ انگلیسی نباید تومان داشته باشد');
        $this->assertStringContainsString('Build your VPS', $en);
        $this->assertStringContainsString('Order summary', $en);
        $this->assertStringNotContainsString('سرور مجازی بساز', $en, 'نشتِ فارسی در انگلیسی');
        $this->assertStringNotContainsString('ui.cvb', $en, 'کلیدِ خام نباید چاپ شود');

        // ترکی: یورو، بی‌تومان، بی‌نشتِ فارسی
        $tr = $vis($this->actingAs($this->customer(), 'customer')
            ->get(route('tr.account.cloud.store', [], false))->assertOk()->getContent());
        $this->assertStringContainsString('€', $tr);
        $this->assertStringNotContainsString('تومان', $tr, 'نسخهٔ ترکی نباید تومان داشته باشد');
        $this->assertStringContainsString('Sipariş özeti', $tr);
        $this->assertStringNotContainsString('سرور مجازی بساز', $tr, 'نشتِ فارسی در ترکی');
    }

    // ═══════════════════ فروشِ ساعتی ═══════════════════

    private function topup(Customer $c, int $irt): void
    {
        \App\Models\CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => $irt,
            'balance_after' => $irt, 'reason' => 'topup', 'source_type' => Customer::class,
            'source_id' => $c->id, 'note' => 'test',
        ]);
    }

    /** ساعتی بدونِ کفِ اعتبار → رد */
    public function test_hourly_order_requires_the_credit_floor(): void
    {
        $this->catalog();
        $customer = $this->customer();
        $this->topup($customer, 5000);   // نرخِ ساعتی ۸۰۰ → حداقلِ شروع ۹۶۰۰

        $this->order($customer, ['billing_mode' => 'hourly'])->assertSessionHasErrors('billing_mode');

        $this->assertDatabaseMissing('services', ['customer_id' => $customer->id, 'billing_mode' => 'hourly']);
        $this->assertSame(5000, $customer->creditBalance('IRT'), 'نباید کسر شود');
    }

    /**
     * 🔴 **جفتِ مرزی** — بی‌این، سوئیتِ سبز دربارهٔ عددِ ۱۲ هیچ ادعایی ندارد.
     *
     * فیکسچرِ ۵۰۰۰ تومانیِ تستِ بالا هم زیرِ کفِ قدیمِ ۱۹٬۲۰۰ رد می‌شد هم زیرِ کفِ
     * تازهٔ ۹٬۶۰۰ — یعنی عوض‌شدنِ ضریب هیچ سیگنالی نمی‌ساخت. این تست دقیقاً روی
     * مرز می‌نشیند: یک تومان کم‌تر رد، دقیقاً برابر قبول.
     *
     * ⚠️ و عمداً حسابِ خودش را نمی‌کند: عدد از همان ثابتی می‌آید که کد می‌خوانَد،
     * وگرنه تست یک نسخهٔ دومِ قاعده می‌شد که می‌تواند با کد اختلاف پیدا کند.
     */
    public function test_the_credit_floor_is_exact_on_both_sides(): void
    {
        $this->catalog();

        $plan = \App\Models\CloudPlan::where('slug', 'cv-2c-4g-40d-de-frankfurt')->firstOrFail();
        $rate = $plan->hourlyIrt();
        $floor = $plan->hourlyStartMinIrt();

        $this->assertSame(800, $rate);
        $this->assertSame(800 * \App\Models\CloudPlan::HOURLY_START_MIN_HOURS, $floor);

        /*
        | 🔴 کف و مهلتِ تعلیق باید برابر بمانند — تصمیمِ صریحِ کارفرما.
        |
        | اگر کف کمتر از مهلت شود، مشتری کمتر از آنچه رایگان نگهش می‌داریم
        | پرداخت کرده و تفاوت از جیبِ ما می‌رود؛ اگر بیشتر شود، پولی گرفته‌ایم
        | که بابتش سرویسی نداده‌ایم. این ادعا همان چیزی است که «نه ما ضرر کنیم
        | نه مشتری» را قفل می‌کند، و عمداً عددِ خام نمی‌نویسد تا با تغییرِ
        | آگاهانهٔ هر دو، همچنان درست بماند.
        */
        $grace = new \ReflectionClassConstant(\App\Console\Commands\CloudMeterHourly::class, 'SUSPEND_GRACE_HOURS');
        $this->assertSame(
            $grace->getValue(),
            \App\Models\CloudPlan::HOURLY_START_MIN_HOURS,
            'کفِ اعتبارِ خرید و مهلتِ تعلیق باید برابر باشند — یکی را بدونِ دیگری عوض نکن'
        );

        // یک تومان کم‌تر → رد، و هیچ کسری
        $poor = $this->customer();
        $this->topup($poor, $floor - 1);
        $this->order($poor, ['billing_mode' => 'hourly'])->assertSessionHasErrors('billing_mode');
        $this->assertDatabaseMissing('services', ['customer_id' => $poor->id, 'billing_mode' => 'hourly']);
        $this->assertSame($floor - 1, $poor->creditBalance('IRT'));

        // دقیقاً برابر → قبول، و فقط ساعتِ اول کسر می‌شود
        $ok = $this->customer();
        $this->topup($ok, $floor);
        $this->order($ok, ['billing_mode' => 'hourly'])->assertRedirect();
        $this->assertDatabaseHas('services', ['customer_id' => $ok->id, 'billing_mode' => 'hourly']);
        $this->assertSame($floor - $rate, $ok->creditBalance('IRT'), 'خرید با ۱۲× نرخ، ۱۱× نرخ باقی می‌گذارد');
    }

    /** پیامِ ردِ خرید باید همان عددی را بگوید که گیت واقعاً می‌سنجد */
    public function test_the_refusal_message_states_the_real_requirement(): void
    {
        $this->catalog();

        $plan = \App\Models\CloudPlan::where('slug', 'cv-2c-4g-40d-de-frankfurt')->firstOrFail();
        $customer = $this->customer();
        $this->topup($customer, 1000);

        $errors = $this->order($customer, ['billing_mode' => 'hourly'])
            ->assertSessionHasErrors('billing_mode')
            ->getSession()->get('errors');

        $msg = (string) $errors->first('billing_mode');

        $this->assertStringContainsString(cloud_price($plan->hourlyStartMinIrt()), $msg,
            'مبلغِ پیام باید از hourlyStartMinIrt() بیاید، نه از یک حسابِ دومِ داخلِ کنترلر');
        $this->assertStringContainsString(fa_num(\App\Models\CloudPlan::HOURLY_START_MIN_HOURS), $msg);

        /*
        | ⚠️ این ادعا قبلاً «۲۴ ساعت نباشد» بود، چون آن روز ۲۴ عددِ کهنه بود.
        | حالا ۲۴ عددِ درست است، پس ادعای درست این است که هیچ عددِ **دیگری**
        | جز ثابت در متن نیامده باشد — وگرنه یعنی جایی حسابِ دومی وجود دارد.
        */
        foreach ([6, 12, 48, 72] as $stale) {
            $this->assertStringNotContainsString(
                fa_num($stale).' ساعت', $msg,
                "متن نباید عددِ {$stale} را بگوید — تنها منبع باید HOURLY_START_MIN_HOURS باشد"
            );
        }
    }

    /**
     * 🔴 عددِ کف در **هیچ‌کدام** از سه فایلِ زبان سخت‌کد نباشد.
     *
     * فایلِ واقعی خوانده می‌شود و هیچ چیزی ست نمی‌شود: تستی که خودش مقدار را
     * می‌سازد، هرگز سیم‌کشی را نمی‌سنجد (درسِ ثبت‌شدهٔ همین پروژه).
     *
     * دو کلید، دو راه‌حلِ متفاوت و عمدی:
     *  · `cvb_e_hourly_credit` را **کنترلر** رندر می‌کند، پس جای‌نگهدارِ `:hours`
     *    می‌گیرد و از خودِ ثابت پر می‌شود.
     *  · `cvb_hourly_min_suf` را **Blade** رندر می‌کند (فایلی که این تغییر
     *    مالکش نیست)، و `__()` جای‌نگهدارِ پرنشده را عیناً چاپ می‌کند. پس این یکی
     *    عمداً **بی‌عدد** است: مبلغِ واقعی همان کنار چاپ می‌شود، و هیچ عددی دو
     *    نسخه ندارد. جمله بی‌آن هم کامل است.
     */
    public function test_the_credit_floor_strings_never_hard_code_the_number(): void
    {
        foreach (['fa', 'en', 'tr'] as $locale) {
            $ui = require base_path("lang/{$locale}/ui.php");

            foreach (['cvb_hourly_min_suf', 'cvb_e_hourly_credit', 'cvb_hourly_low'] as $key) {
                $this->assertArrayHasKey($key, $ui, "کلیدِ {$key} در {$locale} نیست");

                $s = (string) $ui[$key];
                $this->assertStringNotContainsString('24', $s, "{$locale}.{$key} هنوز عددِ کهنه دارد");
                $this->assertStringNotContainsString('۲۴', $s, "{$locale}.{$key} هنوز عددِ کهنه دارد");
                $this->assertStringNotContainsString('12', $s, "{$locale}.{$key} نباید عدد را سخت‌کد کند");
                $this->assertStringNotContainsString('۱۲', $s, "{$locale}.{$key} نباید عدد را سخت‌کد کند");
            }

            $this->assertStringContainsString(':hours', (string) $ui['cvb_e_hourly_credit'],
                "{$locale}.cvb_e_hourly_credit باید جای‌نگهدار داشته باشد");
            $this->assertStringNotContainsString(':hours', (string) $ui['cvb_hourly_min_suf'],
                'این کلید را Blade رندر می‌کند و جای‌نگهدارِ پرنشده عیناً چاپ می‌شود');
        }
    }

    /** جای‌نگهدار روی صفحهٔ واقعی نباید خام چاپ شود */
    public function test_no_raw_placeholder_leaks_onto_the_store_page(): void
    {
        $this->catalog();
        $customer = $this->customer();
        $this->topup($customer, 50_000);

        foreach ([$this->u(), route('en.account.cloud.store', [], false)] as $url) {
            $html = $this->actingAs($customer, 'customer')->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(':hours', $html, 'جای‌نگهدارِ پرنشده روی صفحه');
            $this->assertStringNotContainsString(':min', $html);
        }
    }

    /** برابریِ کلیدها بینِ سه زبان — کلیدِ جاافتاده یعنی متنِ خام روی صفحه */
    public function test_the_three_language_files_have_identical_keys(): void
    {
        $fa = array_keys(require base_path('lang/fa/ui.php'));
        $en = array_keys(require base_path('lang/en/ui.php'));
        $tr = array_keys(require base_path('lang/tr/ui.php'));

        sort($fa);
        sort($en);
        sort($tr);

        $this->assertSame($fa, $en, 'کلیدهای fa و en باید دقیقاً برابر باشند');
        $this->assertSame($fa, $tr, 'کلیدهای fa و tr باید دقیقاً برابر باشند');
    }

    /**
     * 🔴 ساعتِ اولِ پیش‌پرداخت باید روی **سرویس** ثبت شود، نه روی مشتری.
     *
     * مسیرِ لغوِ سفارشِ تحویل‌نشده مبلغِ بازگشتی را فقط از ردیف‌هایی جمع می‌زند که
     * `source_type = Service` باشند؛ تا مرداد ۱۴۰۵ این یک ردیف با کلیدِ
     * `Customer` نوشته می‌شد و **تنها چیزی بود که مشتری روی سرورِ
     * هرگز-تحویل‌نشده پس نمی‌گرفت**.
     */
    public function test_cancelling_an_undelivered_hourly_order_returns_the_prepaid_hour(): void
    {
        $this->catalog();

        $plan = \App\Models\CloudPlan::where('slug', 'cv-2c-4g-40d-de-frankfurt')->firstOrFail();
        $floor = $plan->hourlyStartMinIrt();

        $customer = $this->customer();
        $this->topup($customer, $floor);

        $this->order($customer, ['billing_mode' => 'hourly'])->assertRedirect();

        $service = \App\Models\Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame($floor - 800, $customer->creditBalance('IRT'));

        // ساعتِ اول باید به سرویس گره خورده باشد
        $this->assertDatabaseHas('credit_ledger', [
            'source_type' => \App\Models\Service::class, 'source_id' => $service->id,
            'amount' => -800, 'reason' => 'cloud_hourly',
        ]);

        $this->actingAs($customer, 'customer')->post("/account/services/{$service->id}/cancel");

        $this->assertSame('cancelled', $service->fresh()->status);
        $this->assertSame($floor, $customer->creditBalance('IRT'), 'ساعتِ پیش‌پرداخت باید کامل برگردد');
    }

    /** بلوکِ «پرداختِ ساعتی» با نرخ و گزینه‌ها روی صفحه رندر شود (نه کلیدِ خام) */
    public function test_hourly_toggle_renders_on_store_page(): void
    {
        $this->catalog();
        $customer = $this->customer();
        $this->topup($customer, 50_000);

        $html = $this->actingAs($customer, 'customer')->get($this->u())->assertOk()->getContent();

        $this->assertStringContainsString('name="billing_mode"', $html, 'چک‌باکسِ ساعتی باید باشد');
        $this->assertStringContainsString('name="on_credit_out"', $html, 'انتخابِ رفتارِ پایانِ اعتبار باید باشد');
        $this->assertStringContainsString('پرداختِ ساعتی', $html);
        $this->assertStringContainsString('۸۰۰ تومان', $html, 'نرخِ ساعتی باید نمایش داده شود');
        $this->assertStringNotContainsString('ui.cvb_hourly', $html, 'کلیدِ خامِ ترجمه نباید چاپ شود');
    }

    /** هشدارِ «اعتبار کافی نیست» وقتی موجودی زیرِ کف است */
    public function test_low_credit_warning_shows_when_balance_under_the_floor(): void
    {
        $this->catalog();
        $customer = $this->customer();   // بی‌شارژ

        $html = $this->actingAs($customer, 'customer')->get($this->u())->assertOk()->getContent();

        $this->assertStringContainsString('cvb-h-low', $html);
        // بی‌اعتبار: هشدار نباید hidden باشد
        $this->assertMatchesRegularExpression('/id="cvb-h-low"(?![^>]*hidden)/', $html, 'هشدار باید دیده شود');
    }

    /** ساعتی با اعتبارِ کافی → ساعتِ اول کسر، سرویسِ ساعتیِ در صفِ تحویل ساخته شود */
    public function test_hourly_order_deducts_first_hour_and_queues_provision(): void
    {
        $this->catalog();
        $customer = $this->customer();
        $this->topup($customer, 50_000);

        $this->order($customer, ['billing_mode' => 'hourly'])->assertRedirect();

        $service = \App\Models\Service::where('customer_id', $customer->id)->first();
        $this->assertNotNull($service);
        $this->assertSame('hourly', $service->billing_mode);
        $this->assertSame(800, (int) $service->hourly_rate_irt);          // ۵۷۰۰۰۰÷۷۲۰ گردِ بالا به ۱۰۰
        $this->assertSame('awaiting_provision', $service->status);         // پرداخت‌شده از اعتبار
        $this->assertSame('pending', $service->provision_status);          // صفِ تحویل می‌سازدش
        $this->assertNotNull($service->last_metered_at);
        $this->assertSame(49_200, $customer->creditBalance('IRT'));        // ۵۰۰۰۰ − ۸۰۰
    }
}
