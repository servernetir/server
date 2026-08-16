<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Services\Payment\PaymentService;
use Database\Seeders\LicenseProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * فروش لایسنس نرم‌افزار — سرتاسری: کاتالوگ → تسویه با IP → پرداخت → صف دستی.
 *
 * ═══ دو تصمیم طراحی که این تست‌ها قفل می‌کنند ═══
 *
 * ۱) IP لایسنس در `services.domain` می‌نشیند تا applyPaid موجود («دامنه‌ی
 *    پرشده + بی‌سرور = تحویل دستی») بدون هیچ تغییری سرویس پرداخت‌شده را به
 *    صف ادمین ببرد. اگر روزی کسی این را «تمیزکاری» کند و ستون جدا بسازد،
 *    test_paid_license_enters_the_manual_delivery_queue قرمز می‌شود — همان
 *    خرابی بی‌صدای سه‌بار-تکرارشده‌ی DeliveryRoutingTest از در چهارم.
 *
 * ۲) قیمت صفحه‌ی عمومی /services/licenses از DB می‌آید نه config — و seeder
 *    باید عدد config را عیناً کپی کند چون آن عدد قبلاً تبلیغ شده است.
 */
class LicenseSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'lic'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function seed4(): void
    {
        (new LicenseProductSeeder)->run();
    }

    /* ═══════════════ seeder ═══════════════ */

    public function test_seeder_is_insert_missing_and_idempotent(): void
    {
        $this->seed4();
        $this->assertSame(4, Product::where('category', 'license')->count());

        // ویرایش مدیر نباید با اجرای دوباره پاک شود
        Product::where('slug', 'license-cpanel')->update(['price' => 1234567]);
        $this->seed4();

        $this->assertSame(4, Product::where('category', 'license')->count());
        $this->assertSame(1234567, (int) Product::where('slug', 'license-cpanel')->value('price'));
    }

    /**
     * 🔴 قیمت seeder = قیمت تبلیغ‌شده‌ی config، عدد به عدد. جداافتادن این دو
     * یعنی مشتری قیمتی می‌بیند که در تسویه عوض می‌شود.
     */
    public function test_seeder_prices_match_the_advertised_catalog_config(): void
    {
        $plans = (array) config('catalog.services.licenses.plans');
        $catalog = LicenseProductSeeder::catalog();

        $linked = 0;
        foreach ($plans as $plan) {
            $slug = $plan['product'] ?? null;
            if ($slug === null) {
                continue;
            }
            $linked++;

            $this->assertArrayHasKey($slug, $catalog, "پلن config به پکیج ناموجود «{$slug}» اشاره می‌کند");
            $this->assertSame((int) $plan['irt'], (int) $catalog[$slug]['price'],
                "قیمت seeder برای {$slug} با عدد تبلیغ‌شده‌ی config نمی‌خواند");
        }

        $this->assertSame(4, $linked, 'هر چهار پلن لایسنس باید به پکیج وصل باشند');
    }

    /* ═══════════════ صفحه‌ی عمومی کاتالوگ ═══════════════ */

    public function test_licenses_page_links_every_plan_to_our_own_store(): void
    {
        $this->seed4();

        $html = $this->get('/services/licenses')->assertOk()->getContent();

        foreach (array_keys(LicenseProductSeeder::catalog()) as $slug) {
            $this->assertStringContainsString('order/'.$slug, $html, "دکمه‌ی خرید {$slug} به فروشگاه خودمان نمی‌رود");
        }

        // هیچ ردی از سبد WHMCS بیرونی — همان شکاف قدیمی «pid placeholder»
        $this->assertStringNotContainsString('cart.php', $html);
        $this->assertStringNotContainsString('pid=229', $html);
    }

    /** بی‌محصول (مهاجرت/سیدر هنوز نرفته) قیمت قول داده نمی‌شود — «تماس بگیرید» */
    public function test_without_products_the_page_offers_contact_not_dead_buttons(): void
    {
        $html = $this->get('/services/licenses')->assertOk()->getContent();

        $this->assertStringNotContainsString('order/license-', $html);
        $this->assertStringNotContainsString('cart.php', $html);
    }

    /**
     * قیمت نمایشی صفحه همان قیمت DB است (ویرایش مدیر بلافاصله اثر می‌کند) و
     * دقیقاً از همان مسیر site_price که بقیه‌ی سایت — نه دو بار ضریب، نه صفر.
     */
    public function test_page_price_comes_from_the_database_not_config(): void
    {
        $this->seed4();
        Product::where('slug', 'license-cpanel')->update(['price' => 1111110]);

        $html = $this->get('/services/licenses')->assertOk()->getContent();

        app()->setLocale('fa');
        $this->assertStringContainsString(site_price(['irt' => 1111110]), $html,
            'قیمت ویرایش‌شده‌ی DB باید با همان فرمت site_price روی صفحه بیاید');
        // و عدد قدیمی config دیگر روی صفحه نیست
        $this->assertStringNotContainsString(site_price(['irt' => 990000]), $html);
    }

    /* ═══════════════ تسویه ═══════════════ */

    public function test_license_checkout_asks_for_ip_not_domain_or_location(): void
    {
        $this->seed4();

        $html = $this->actingAs($this->customer(), 'customer')
            ->get('/account/order/license-cpanel')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.chk_q_ip'), $html);
        $this->assertStringContainsString('license_ip', $html);
        // نه دامنه، نه محل سرور، نه هشدار «سرور نداریم»، نه دکمه‌ی قفل‌شده
        $this->assertStringNotContainsString(__('ui.chk_q_domain'), $html);
        $this->assertStringNotContainsString(__('ui.chk_q_country'), $html);
        $this->assertStringNotContainsString(__('ui.chk_no_server_warn'), $html);
        $this->assertStringNotContainsString('disabled', substr($html, strpos($html, 'co-form')));
    }

    public function test_ordering_a_license_creates_an_ip_keyed_service_and_invoice(): void
    {
        $this->seed4();
        $customer = $this->customer();
        $product = Product::where('slug', 'license-directadmin')->firstOrFail();

        $this->actingAs($customer, 'customer')
            ->post('/account/order/license-directadmin', [
                'cycle' => 'monthly', 'license_ip' => '203.0.113.10',
            ])->assertRedirect();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame('203.0.113.10', $service->domain, 'شناسه‌ی سرویس لایسنس باید IP باشد');
        $this->assertNull($service->server_id, 'لایسنس هیچ سروری از ما نمی‌گیرد');
        $this->assertSame('pending', $service->status);
        $this->assertSame($product->priceForCycle('monthly'), (int) $service->price,
            'مبلغ باید از همان priceForCycle فروشگاه بیاید — قیمت هرگز جای دیگری ساخته نمی‌شود');
        $this->assertStringContainsString('203.0.113.10', (string) $service->description);

        $this->assertNotNull(Invoice::where('service_id', $service->id)->first());
    }

    /** IP خصوصی/رزرو/بی‌شکل پذیرفته نمی‌شود — لایسنس روی آن‌ها فعال‌شدنی نیست */
    public function test_private_and_malformed_ips_are_rejected(): void
    {
        $this->seed4();
        $customer = $this->customer();

        foreach (['10.0.0.5', '192.168.1.2', '127.0.0.1', '169.254.169.254', 'not-an-ip', '2001:db8::1', ''] as $bad) {
            $this->actingAs($customer, 'customer')
                ->from('/account/order/license-cpanel')
                ->post('/account/order/license-cpanel', ['cycle' => 'monthly', 'license_ip' => $bad])
                ->assertSessionHasErrors('license_ip');
        }

        $this->assertSame(0, Service::count(), 'هیچ سفارشی نباید از IP نامعتبر ساخته شود');
    }

    /* ═══════════════ پرداخت → صف تحویل دستی ═══════════════ */

    /**
     * 🔴 قلب ماجرا: لایسنسِ پرداخت‌شده نه باید بی‌صدا active شود (هیچ‌کس
     * لایسنسی فعال نکرده!) و نه در صف خودکار گم شود — باید در صف **دستی**
     * ادمین بنشیند، همان‌جایی که SystemHealth هم می‌شمارد.
     */
    public function test_paid_license_enters_the_manual_delivery_queue(): void
    {
        $this->seed4();
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post('/account/order/license-litespeed', [
                'cycle' => 'monthly', 'license_ip' => '198.51.100.7',
            ])->assertRedirect();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $invoice = Invoice::where('service_id', $service->id)->firstOrFail();

        $payment = Payment::create([
            'invoice_id'    => $invoice->id,
            'customer_id'   => $customer->id,
            'gateway'       => 'bale',
            'currency_code' => 'IRT',
            'amount'        => $invoice->total,
            'status'        => 'redirected',
            'external_ref'  => 'LIC-'.$invoice->id,
        ]);
        app(PaymentService::class)->settleConfirmed($payment, 'REF-'.random_int(1000, 9999));

        $service->refresh();

        $this->assertSame('awaiting_provision', $service->status,
            'لایسنس نباید مستقیم active شود — هنوز نزد تأمین‌کننده فعال نشده');
        $this->assertSame('manual', $service->provision_status,
            'باید در صف دستی ادمین بنشیند؛ صف خودکار سروری برای ساختن ندارد');
    }

    /* ═══════════════ ادمین ═══════════════ */

    /** ساخت پکیج لایسنس در ادمین نباید سراغ WHM برود — packageِ بی‌مصرف نساز */
    public function test_admin_creating_a_license_product_skips_whm_packages(): void
    {
        \App\Models\Server::create([
            'name' => 'node1', 'type' => 'whm', 'hostname' => 'node1.example.com',
            'status' => 'active',
        ]);

        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post('/admin/products', [
                'name' => 'لایسنس تستی', 'category' => 'license',
                'price' => 500000, 'cycle' => 'monthly',
            ])->assertRedirect();

        $this->assertDatabaseHas('products', ['name' => 'لایسنس تستی', 'category' => 'license']);
        // پیام موفقیت باید صریح بگوید package لازم نبوده (یعنی به createWhmPackage نرسیده)
        $this->assertStringContainsString('لایسنس', (string) session('ok'));
    }
}
