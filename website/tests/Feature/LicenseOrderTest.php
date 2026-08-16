<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خریدِ لایسنس — پکیجی که **دامنه ندارد** و به‌جایش **IP سرور** می‌خواهد.
 *
 * ═══ چرا IP هنگام سفارش گرفته می‌شود ═══
 *
 * صفحهٔ محصول وعدهٔ «تحویل آنی پس از پرداخت» می‌دهد. لایسنس بی‌IP اصلاً قابلِ
 * فعال‌سازی نیست، پس اگر IP را بعد از پرداخت بپرسیم، تحویل به پاسخِ **مشتری**
 * گره می‌خورد نه به کارِ ما — و آن وعده از همان روزِ اول دروغ می‌شود.
 * گرفتنش در فرمِ سفارش تنها چیزی است که وعده را قابلِ انجام می‌کند.
 */
class LicenseOrderTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'l'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function license(array $over = []): Product
    {
        return Product::create(array_merge([
            'name' => 'لایسنس cPanel/WHM — سرور مجازی', 'category' => 'other', 'group' => 'licenses',
            'price' => 390000, 'setup_fee' => 0, 'cycle' => 'monthly', 'tax_percent' => 10,
            'requires_domain' => false, 'requires_server_ip' => true, 'is_active' => true,
        ], $over));
    }

    /** خریدِ سالم: بی‌دامنه، با IP. */
    public function test_a_license_can_be_ordered_without_a_domain(): void
    {
        $product = $this->license();
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'server_ip' => '203.0.113.10'])
            ->assertRedirect();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('203.0.113.10', $service->server_ip);
        $this->assertNull($service->domain);
    }

    /**
     * 🔴 بی‌IP نباید سفارش ثبت شود.
     *
     * وگرنه پول گرفته می‌شود و اپراتور سفارشی می‌بیند که نمی‌داند روی چه سروری
     * فعالش کند — و «تحویل آنی» به یک رفت‌وبرگشتِ تیکتی تبدیل می‌شود.
     */
    public function test_a_license_order_without_a_server_ip_is_rejected(): void
    {
        $product = $this->license();
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}", ['cycle' => 'monthly'])
            ->assertSessionHasErrors('server_ip');

        $this->assertSame(0, Service::where('customer_id', $customer->id)->count());
    }

    /**
     * 🔴 IPِ **خصوصی** معتبر است ولی بی‌فایده.
     *
     * `127.0.0.1` و `192.168.1.5` هر دو از قاعدهٔ `ip` لاراول رد می‌شوند و روی
     * هیچ لایسنسی فعال نمی‌شوند. مشتری هیچ خطایی نمی‌بیند — فقط چند روز بعد
     * می‌فهمد پنلش کار نمی‌کند و آن‌وقت تقصیرِ ماست.
     */
    public function test_a_private_or_loopback_ip_is_rejected(): void
    {
        $product = $this->license();
        $customer = $this->customer();

        foreach (['127.0.0.1', '192.168.1.5', '10.0.0.7'] as $ip) {
            $this->actingAs($customer, 'customer')
                ->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'server_ip' => $ip])
                ->assertSessionHasErrors('server_ip');
        }

        $this->assertSame(0, Service::where('customer_id', $customer->id)->count());
    }

    public function test_a_malformed_ip_is_rejected(): void
    {
        $product = $this->license();

        $this->actingAs($this->customer(), 'customer')
            ->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'server_ip' => 'my-server.com'])
            ->assertSessionHasErrors('server_ip');
    }

    /** IPv6 عمومی هم باید بپذیرد — سرورهای اروپایی اغلب همین را می‌دهند. */
    public function test_a_public_ipv6_is_accepted(): void
    {
        $product = $this->license();
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'server_ip' => '2a01:4f8:c17:b8f::1'])
            ->assertRedirect();

        $this->assertSame('2a01:4f8:c17:b8f::1',
            Service::where('customer_id', $customer->id)->firstOrFail()->server_ip);
    }

    /**
     * ⚠️ نیمهٔ دیگر: باز کردنِ اجبارِ دامنه نباید هاست را هم شل کند.
     * پکیجِ هاست هنوز بی‌دامنه رد می‌شود.
     */
    public function test_loosening_the_domain_rule_did_not_loosen_it_for_hosting(): void
    {
        $product = Product::create([
            'name' => 'هاست لینوکس LX-5', 'category' => 'shared', 'price' => 249000,
            'setup_fee' => 0, 'cycle' => 'monthly', 'tax_percent' => 10,
            'requires_domain' => true, 'is_active' => true,
        ]);

        $this->actingAs($this->customer(), 'customer')
            ->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'domain_mode' => 'have'])
            ->assertSessionHasErrors('domain');
    }

    /**
     * 🔴 IP روی ستونِ خودش می‌نشیند، نه روی `domain`.
     *
     * ستونِ `domain` در تحویلِ WHM/DirectAdmin مستقیم به `createacct` می‌رود؛
     * نشاندنِ یک IP در آن یعنی روزی دامنه‌ای به شکلِ `203.0.113.10` به
     * کنترل‌پنل فرستاده شود.
     */
    public function test_the_ip_never_lands_in_the_domain_column(): void
    {
        $product = $this->license();
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'server_ip' => '203.0.113.10']);

        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertNotSame('203.0.113.10', $service->domain);
    }

    /**
     * لایسنس هیچ سرورِ تحویلی ندارد، پس کرونِ تحویل نباید برش دارد.
     * (وگرنه درایورِ WHM رویش می‌دود و «سرور تعیین نشده» شکست می‌دهد.)
     */
    public function test_a_license_service_is_not_picked_up_by_the_provisioning_queue(): void
    {
        $product = $this->license();
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'server_ip' => '203.0.113.10']);

        $service = Service::where('customer_id', $customer->id)->firstOrFail();

        $this->assertNull($service->server_id);
        $this->assertFalse($service->needsProvisioning());
    }

    /** سیم‌کشیِ فرمانِ seed — پکیجِ لایسنس واقعاً قابلِ خرید می‌شود. */
    public function test_the_seeder_creates_orderable_license_products(): void
    {
        $this->artisan('products:seed-licenses')->assertExitCode(0);

        $products = Product::where('group', 'licenses')->get();
        $this->assertGreaterThan(0, $products->count(), 'هیچ پکیجِ لایسنسی ساخته نشد');

        foreach ($products as $p) {
            $this->assertTrue((bool) $p->requires_server_ip, "«{$p->name}» IP نمی‌پرسد");
            $this->assertFalse((bool) $p->requires_domain, "«{$p->name}» بی‌دلیل دامنه می‌خواهد");
            $this->assertNull($p->server_id);
            $this->assertGreaterThan(0, $p->price);
        }
    }

    /**
     * ⚠️ **کد ۲۰۰ یعنی هیچ.** فرمِ سفارشِ لایسنس باید واقعاً فیلدِ IP را داشته
     * باشد و بلوکِ دامنه را نداشته باشد — یک `@if`ِ اشتباه در Blade صفحه را
     * سالم رندر می‌کند و فقط فیلد را می‌اندازد، و آن‌وقت هیچ سفارشی از
     * اعتبارسنجی رد نمی‌شود بی‌آنکه کسی بفهمد چرا.
     */
    public function test_the_checkout_form_asks_for_the_ip_and_not_for_a_domain(): void
    {
        $product = $this->license();

        $html = $this->actingAs($this->customer(), 'customer')
            ->get("/account/order/{$product->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="server_ip"', $html, 'فرمِ لایسنس فیلدِ IP ندارد');
        $this->assertStringNotContainsString('name="domain_mode"', $html, 'فرمِ لایسنس بی‌دلیل دامنه می‌پرسد');
    }

    /** و نیمهٔ دیگر: فرمِ هاست هنوز دامنه می‌پرسد و IP نمی‌پرسد. */
    public function test_the_hosting_checkout_form_is_unchanged(): void
    {
        $product = Product::create([
            'name' => 'هاست لینوکس LX-5', 'category' => 'shared', 'price' => 249000,
            'setup_fee' => 0, 'cycle' => 'monthly', 'tax_percent' => 10,
            'requires_domain' => true, 'is_active' => true,
        ]);

        $html = $this->actingAs($this->customer(), 'customer')
            ->get("/account/order/{$product->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="domain_mode"', $html);
        $this->assertStringNotContainsString('name="server_ip"', $html);
    }

    // ═══════════════ بعد از پرداخت ═══════════════

    /**
     * 🔴 چهارمین رخِ همان تله: سرویسِ لایسنس نه سرور دارد نه دامنه، پس شرطِ
     * `$needsDelivery` در `applyPaid` مستقیم `active`ش می‌کرد — نه صفی، نه
     * کاری برای اپراتور، نه هیچ ردی. مشتری پول می‌داد، پنلش می‌گفت «فعال»، و
     * هیچ‌کس نمی‌فهمید باید لایسنسی ثبت شود.
     */
    public function test_a_paid_license_lands_in_the_manual_queue_not_straight_to_active(): void
    {
        $service = Service::create([
            'customer_id' => $this->customer()->id,
            'name' => 'لایسنس cPanel/WHM — سرور مجازی',
            'server_ip' => '203.0.113.10', 'server_id' => null, 'domain' => null,
            'cycle' => 'monthly', 'price' => 390000, 'status' => 'pending',
        ]);

        // همان شرطی که PaymentService::applyPaid می‌سنجد
        $autoDelivered = $service->server_id !== null || $service->isCloud();
        $needsDelivery = $service->provision_status !== 'done'
            && ($autoDelivered || filled($service->domain) || filled($service->server_ip));

        $this->assertTrue($needsDelivery,
            'سرویسِ لایسنس مستقیم فعال می‌شود و هیچ‌کس خبردار نمی‌شود که باید لایسنسی ثبت شود');
        $this->assertFalse($autoDelivered, 'لایسنس نباید در صفِ تحویلِ خودکار بیفتد — درایوری ندارد');
    }

    /** تیکت واقعاً ساخته می‌شود، با IP و نامِ پکیج داخلش. */
    public function test_paying_for_a_license_opens_a_ticket_carrying_the_ip(): void
    {
        $customer = $this->customer();
        $service = Service::create([
            'customer_id' => $customer->id, 'name' => 'لایسنس cPanel/WHM — سرور مجازی',
            'server_ip' => '203.0.113.10', 'cycle' => 'monthly', 'price' => 390000, 'status' => 'pending',
        ]);

        $ticket = \App\Services\Ticket\LicenseOrderTicket::openFor($service);

        $this->assertNotNull($ticket, 'تیکتِ سفارشِ لایسنس ساخته نشد');
        $this->assertSame('open', $ticket->status,
            'تیکت از صفِ «نیاز به اقدام» بیرون افتاد — کسی لایسنس را ثبت نمی‌کند');
        $this->assertSame($customer->id, $ticket->customer_id);

        $body = \App\Models\TicketMessage::where('ticket_id', $ticket->id)->value('body');
        $this->assertStringContainsString('203.0.113.10', $body, 'IP در متنِ تیکت نیست');
        $this->assertStringContainsString('لایسنس cPanel/WHM', $body);
    }

    /**
     * ⚠️ پرداختِ دوباره / رفرشِ درگاه / رویدادِ تکراریِ وب‌هوک نباید تیکتِ دوم
     * بسازد — وگرنه اپراتور یک لایسنس را دو بار ثبت می‌کند.
     */
    public function test_opening_the_ticket_twice_does_not_create_a_second_one(): void
    {
        $service = Service::create([
            'customer_id' => $this->customer()->id, 'name' => 'لایسنس Plesk',
            'server_ip' => '203.0.113.11', 'cycle' => 'monthly', 'price' => 450000, 'status' => 'pending',
        ]);

        $a = \App\Services\Ticket\LicenseOrderTicket::openFor($service);
        $b = \App\Services\Ticket\LicenseOrderTicket::openFor($service);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, \App\Models\Ticket::where('subject_ref_id', $service->id)->count());
    }

    /**
     * ⚠️ **تستِ سیم‌کشی.** سه تستِ بالا فقط ثابت می‌کنند `openFor()` درست کار
     * می‌کند — نه اینکه کسی صدایش می‌زند. کلاسی که هیچ فراخوانی ندارد دقیقاً
     * همان‌قدر بی‌فایده است که وجود نداشته باشد، و هر سه تست سبز می‌مانند.
     * (همان درسِ `bale_relay`: `.env` درست، `env()` درست، `config()` خالی.)
     *
     * پس این‌جا خودِ فایلِ `PaymentService` خوانده می‌شود.
     */
    public function test_the_payment_flow_actually_calls_the_ticket_opener(): void
    {
        $src = file_get_contents(app_path('Services/Payment/PaymentService.php'));

        $this->assertStringContainsString('LicenseOrderTicket::openFor', $src,
            'PaymentService تیکتِ لایسنس را صدا نمی‌زند — وعدهٔ «اعلام در تیکت» هرگز اجرا نمی‌شود');

        // و صدازدنش باید **بیرونِ** تراکنش باشد: یک ساختِ تیکت نباید بتواند
        // تسویه‌ای که انجام شده را برگرداند.
        $ledgerPos = strpos($src, 'BusinessLedger::class');
        $ticketPos = strpos($src, 'LicenseOrderTicket::openFor');
        $this->assertNotFalse($ledgerPos);
        $this->assertGreaterThan($ledgerPos, $ticketPos,
            'تیکت پیش از پایانِ تراکنش صدا زده می‌شود — خطایش می‌تواند پرداخت را برگرداند');
    }

    /** و برای سرویسِ غیرِلایسنس هیچ تیکتی ساخته نمی‌شود. */
    public function test_no_ticket_is_opened_for_an_ordinary_hosting_service(): void
    {
        $server = \App\Models\Server::create(['name' => 'WHM-1', 'type' => 'whm', 'status' => 'active']);
        $service = Service::create([
            'customer_id' => $this->customer()->id, 'name' => 'هاست لینوکس',
            'server_id' => $server->id, 'domain' => 'shop.ir',
            'cycle' => 'monthly', 'price' => 249000, 'status' => 'pending',
        ]);

        $this->assertNull(\App\Services\Ticket\LicenseOrderTicket::openFor($service));
        $this->assertSame(0, \App\Models\Ticket::count());
    }

    /** اجرای دوباره نباید پکیجِ دوتایی بسازد. */
    public function test_seeding_twice_does_not_duplicate(): void
    {
        $this->artisan('products:seed-licenses');
        $first = Product::where('group', 'licenses')->count();

        $this->artisan('products:seed-licenses');
        $this->assertSame($first, Product::where('group', 'licenses')->count());
    }
}
