<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Ticket\LicenseOrderTicket;
use Database\Seeders\LicenseProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * افزوده‌های روی فروشِ لایسنس — رده‌بندیِ مجازی/اختصاصی و تیکتِ خودکار.
 *
 * پایهٔ فروشِ لایسنس (کاتالوگ، seeder، مسیرِ سفارش، ردِ IPِ خصوصی) را
 * `LicenseSalesTest` قفل می‌کند و این‌جا تکرار نمی‌شود. این فایل فقط چیزی را
 * می‌سنجد که روی آن پایه اضافه شده.
 */
class LicenseTicketAndTiersTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'lt'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    // ═══════════════ رده‌بندی ═══════════════

    /**
     * هر پنلی که دو رده دارد، باید ردهٔ اختصاصی‌اش **گران‌تر** از مجازی باشد.
     *
     * ⚠️ ادعا روی نسبت است نه عددِ ثابت: قیمت‌ها با بازار عوض می‌شوند و تستی
     * که عدد را قفل کند، هر تغییرِ قیمت را به یک شکستِ الکی تبدیل می‌کند.
     * چیزی که **نباید** عوض شود این است که مشتریِ VPS قیمتِ سرورِ اختصاصی
     * نبیند — همان چیزی که ما را دو برابرِ بازار گران کرده بود.
     */
    public function test_the_dedicated_tier_always_costs_more_than_the_vps_tier(): void
    {
        $catalog = LicenseProductSeeder::catalog();

        foreach (['license-directadmin', 'license-cpanel', 'license-plesk'] as $vps) {
            $ded = $vps.'-ded';

            $this->assertArrayHasKey($ded, $catalog, "ردهٔ اختصاصیِ «{$vps}» وجود ندارد");
            $this->assertGreaterThan(
                (int) $catalog[$vps]['price'],
                (int) $catalog[$ded]['price'],
                "«{$ded}» باید از ردهٔ مجازی گران‌تر باشد — وگرنه رده‌بندی بی‌معنی است"
            );
        }
    }

    /** CloudLinux برای نماینده لازم است و باید قابلِ خرید باشد. */
    public function test_cloudlinux_is_sellable(): void
    {
        (new LicenseProductSeeder)->run();

        $p = Product::where('slug', 'license-cloudlinux')->first();
        $this->assertNotNull($p, 'CloudLinux در کاتالوگ نیست');
        $this->assertTrue((bool) $p->is_active);
        $this->assertTrue($p->isLicense());
    }

    /**
     * 🔴 قیمتِ تازهٔ config باید روی ردیفِ **دست‌نخوردهٔ** موجود بنشیند.
     *
     * صفحه قیمت را از دیتابیس می‌خواند؛ اگر seeder ردیفِ موجود را هرگز
     * به‌روز نکند، هر اصلاحِ قیمت بعد از دیپلوی **بی‌اثر** می‌مانَد و هیچ
     * خطایی هم نمی‌دهد — دقیقاً اتفاقی که روی prod افتاد.
     */
    public function test_a_price_change_reaches_a_row_the_admin_never_touched(): void
    {
        // ردیفی با قیمتِ نسخهٔ قبلیِ seeder — یعنی دست‌نخورده
        Product::create([
            'name' => 'لایسنس cPanel/WHM', 'slug' => 'license-cpanel',
            'category' => 'license', 'group' => 'license', 'currency_code' => 'IRT',
            'price' => 990000, 'price_eur' => 990, 'cycle' => 'monthly',
            'tax_percent' => 10, 'is_active' => true,
        ]);

        (new LicenseProductSeeder)->run();

        $this->assertSame(390000, (int) Product::where('slug', 'license-cpanel')->value('price'),
            'قیمتِ تازه به ردیفِ دست‌نخورده نرسید — روی سایتِ زنده قیمتِ قدیمی می‌مانَد');
    }

    /**
     * ⚠️ و نیمهٔ دیگر، که تستِ خودِ seeder گرفتش: ویرایشِ **آگاهانهٔ** مدیر
     * هرگز پاک نمی‌شود.
     */
    public function test_an_admin_edited_price_is_never_overwritten(): void
    {
        (new LicenseProductSeeder)->run();

        Product::where('slug', 'license-cpanel')->update(['price' => 1234567]);
        (new LicenseProductSeeder)->run();

        $this->assertSame(1234567, (int) Product::where('slug', 'license-cpanel')->value('price'));
    }

    // ═══════════════ تیکتِ خودکار ═══════════════

    private function licenseService(string $ip = '203.0.113.10'): Service
    {
        return Service::create([
            'customer_id' => $this->customer()->id,
            'name' => 'لایسنس cPanel/WHM — سرور مجازی',
            'domain' => $ip, 'server_id' => null,
            'cycle' => 'monthly', 'price' => 390000, 'status' => 'pending',
        ]);
    }

    public function test_paying_for_a_license_opens_a_ticket_carrying_the_ip(): void
    {
        $service = $this->licenseService();

        $ticket = LicenseOrderTicket::openFor($service);

        $this->assertNotNull($ticket, 'تیکتِ سفارشِ لایسنس ساخته نشد');
        $this->assertSame('open', $ticket->status,
            'تیکت از صفِ «نیاز به اقدام» بیرون افتاد — کسی لایسنس را ثبت نمی‌کند');

        $body = TicketMessage::where('ticket_id', $ticket->id)->value('body');
        $this->assertStringContainsString('203.0.113.10', $body, 'IP در متنِ تیکت نیست');
    }

    /** پرداختِ دوباره یا رویدادِ تکراریِ وب‌هوک نباید تیکتِ دوم بسازد. */
    public function test_the_ticket_is_idempotent(): void
    {
        $service = $this->licenseService('203.0.113.11');

        $a = LicenseOrderTicket::openFor($service);
        $b = LicenseOrderTicket::openFor($service);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Ticket::where('subject_ref_id', $service->id)->count());
    }

    /**
     * 🔴 نشانهٔ لایسنس «دامنه دارد و سرور ندارد» **نیست**.
     *
     * سفارشِ هاستی که هنوز سرورش انتخاب نشده هم همان شکل را دارد؛ با شرطِ شل،
     * برایش تیکتِ «فعال‌سازی لایسنس» باز می‌شد — پیامی بی‌ربط به مشتری و کارِ
     * الکی در صفِ اپراتور. نشانهٔ درست این است که «دامنه» یک IP باشد.
     */
    public function test_a_hosting_order_without_a_server_never_gets_a_license_ticket(): void
    {
        $service = Service::create([
            'customer_id' => $this->customer()->id,
            'name' => 'هاست لینوکس LX-5',
            'domain' => 'shop.ir', 'server_id' => null,
            'cycle' => 'monthly', 'price' => 249000, 'status' => 'pending',
        ]);

        $this->assertNull(LicenseOrderTicket::openFor($service));
        $this->assertSame(0, Ticket::count());
    }

    /**
     * ⚠️ تستِ سیم‌کشی: کلاسی که هیچ فراخوانی ندارد همان‌قدر بی‌فایده است که
     * وجود نداشته باشد — و همهٔ تست‌های بالا باز هم سبز می‌مانند.
     */
    public function test_the_payment_flow_actually_calls_the_ticket_opener(): void
    {
        $src = file_get_contents(app_path('Services/Payment/PaymentService.php'));

        $this->assertStringContainsString('LicenseOrderTicket::openFor', $src,
            'PaymentService تیکتِ لایسنس را صدا نمی‌زند — مشتری بعد از پرداخت در سکوت می‌مانَد');

        $ledger = strpos($src, 'BusinessLedger::class');
        $ticket = strpos($src, 'LicenseOrderTicket::openFor');
        $this->assertNotFalse($ledger);
        $this->assertGreaterThan($ledger, $ticket,
            'تیکت پیش از پایانِ تراکنش صدا زده می‌شود — خطایش می‌تواند پرداخت را برگرداند');
    }
}
