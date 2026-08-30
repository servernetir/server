<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * انقضای ۷۲ ساعتهٔ پیش‌فاکتورِ سفارشِ مشتری.
 *
 * کارفرما: «تا ۷۲ ساعت اگر کاربر پرداخت نکرد فاکتور برود، بعداً دوباره سفارش
 * بدهد — چون قیمت‌ها نوسان دارد.» بهای تمام‌شدهٔ ما یورویی است و پیش‌فاکتورِ
 * هفتهٔ پیش می‌تواند زیرِ قیمتِ خرید باشد.
 *
 * ⚠️ ادعای مرکزیِ این فایل «چه چیزی لغو می‌شود» نیست؛ **«چه چیزی هرگز لغو
 * نمی‌شود»** است. یک لغوِ اشتباه یعنی قطعِ سرویسی که مشتری پولش را داده، یا
 * پولی که به فاکتوری می‌رسد که دیگر قابلِ پرداخت نیست.
 */
class InvoiceOrderExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'e'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** سفارشِ تازهٔ مشتری: سرویسِ pending + فاکتورِ قدیمی‌تر از مهلت */
    private function order(array $svc = [], array $inv = []): array
    {
        $c = $this->customer();

        $service = Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'هاستِ لینوکسی', 'currency_code' => 'IRT',
            'price' => 500000, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'pending',
        ], $svc));

        $invoice = Invoice::create(array_merge([
            'customer_id' => $c->id, 'service_id' => $service->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => 500000, 'tax' => 0, 'total' => 500000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ], $inv));

        // ۹۶ ساعت پیش ساخته شده ⇒ از مهلتِ ۷۲ ساعته گذشته
        $invoice->forceFill(['created_at' => now()->subHours(96)])->save();

        return [$c, $service, $invoice];
    }

    private function expire(): void
    {
        $this->artisan('invoices:expire-orders')->assertSuccessful();
    }

    // ═══════════════ آنچه باید لغو شود ═══════════════

    public function test_an_unpaid_order_older_than_the_window_is_cancelled_with_its_service(): void
    {
        [, $service, $invoice] = $this->order();

        $this->expire();

        $this->assertSame('canceled', $invoice->fresh()->status);
        $this->assertSame('cancelled', $service->fresh()->status,
            'سرویسِ منتظر زنده ماند — مشتری چیزی می‌بیند که هرگز پرداختی برایش ممکن نیست');
    }

    public function test_an_order_inside_the_window_is_left_alone(): void
    {
        [, $service, $invoice] = $this->order();
        $invoice->forceFill(['created_at' => now()->subHours(10)])->save();

        $this->expire();

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('pending', $service->fresh()->status);
    }

    /** اجرای دوباره نباید چیزی بیش از اجرای اول عوض کند */
    public function test_running_twice_changes_nothing_more(): void
    {
        [, , $invoice] = $this->order();

        $this->expire();
        $before = $invoice->fresh()->updated_at;

        $this->travel(1)->minutes();
        $this->expire();

        $this->assertSame('canceled', $invoice->fresh()->status);
        $this->assertEquals($before, $invoice->fresh()->updated_at, 'ردیفِ لغوشده دوباره نوشته شد');
    }

    // ═══════════════ 🔴 آنچه هرگز نباید لمس شود ═══════════════

    /**
     * 🔴 مهم‌ترین تستِ این فایل.
     *
     * فاکتورِ تمدید روی سرویسِ **فعال** صادر می‌شود. لغوش یعنی قطعِ سرویسی که
     * مشتری بابتش پول داده — دقیقاً چیزی که کارفرما گفت نباید بشود.
     */
    public function test_a_renewal_invoice_is_never_touched(): void
    {
        [, $service, $invoice] = $this->order(
            ['status' => 'active', 'activated_at' => now()->subMonths(3)],
        );

        $this->expire();

        $this->assertSame('unpaid', $invoice->fresh()->status, 'فاکتورِ تمدید لغو شد');
        $this->assertSame('active', $service->fresh()->status, 'سرویسِ فعالِ مشتری قطع شد');
    }

    /** پرداختِ نیمه هم `unpaid` است — سنجه ستونِ `paid` است نه وضعیت */
    public function test_a_partially_paid_invoice_is_never_touched(): void
    {
        [, , $invoice] = $this->order([], ['paid' => 100000]);

        $this->expire();

        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    /**
     * ⚠️ «از سمت کاربر» یعنی سفارشِ خودِ مشتری. پیش‌فاکتورِ صادرشدهٔ مدیر ممکن
     * است تخفیفِ مذاکره‌شده داشته باشد که بازسازی‌شدنی نیست، و مدیر می‌تواند
     * تاریخِ صدور را عقب بزند — پس بعضی‌شان از لحظهٔ تولد از مهلت قدیمی‌ترند.
     */
    public function test_an_admin_issued_proforma_is_never_touched(): void
    {
        $admin = \App\Models\User::create([
            'name' => 'کارفرما', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        [, $service, $invoice] = $this->order(['created_by' => $admin->id]);

        $this->expire();

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('pending', $service->fresh()->status);
    }

    /**
     * 🔴 مشتری می‌تواند ساعتِ ۷۱:۵۹ روی «پرداخت» بزند و درگاه چند دقیقه بعد
     * برگردد. اگر وسطش لغو کنیم، پول به فاکتوری می‌رسد که دیگر وجود ندارد.
     */
    public function test_an_invoice_with_an_open_gateway_payment_is_not_expired(): void
    {
        [$c, , $invoice] = $this->order();

        Payment::create([
            'customer_id' => $c->id, 'invoice_id' => $invoice->id,
            'gateway' => 'zarinpal', 'amount' => 500000, 'currency_code' => 'IRT',
            'status' => 'redirected', 'external_ref' => 'A'.random_int(1000, 9999),
        ]);

        $this->expire();

        $this->assertSame('unpaid', $invoice->fresh()->status,
            'فاکتوری که مشتری همین حالا جلوی درگاهش ایستاده لغو شد');
    }

    /**
     * 🔴 پایا در ایران ۱ تا ۳ روزِ کاری طول می‌کشد و سفارشِ چهارشنبه‌شب
     * تعطیلیِ آخرِ هفته را هم رد می‌کند. رسیدِ در انتظارِ بررسی یعنی آدمی ادعا
     * کرده پولِ واقعی فرستاده.
     */
    public function test_a_pending_bank_receipt_blocks_expiry(): void
    {
        [$c, , $invoice] = $this->order();

        \App\Models\BankTransferReceipt::create([
            'customer_id' => $c->id, 'invoice_id' => $invoice->id,
            'reference' => '123456789', 'amount' => 500000, 'status' => 'pending',
        ]);

        $this->expire();

        $this->assertSame('unpaid', $invoice->fresh()->status,
            'مشتری پول فرستاده و فاکتورش رفت — پول به هیچ‌جا نمی‌رسد');
    }

    /**
     * ⚠️ ولی آن معافیت **سقف دارد**: شمارهٔ پیگیریِ رسید متنِ آزاد است و
     * راستی‌آزمایی نمی‌شود، پس بی‌سقف هر کسی با یک عددِ ساختگی قیمتِ امروز را
     * برای همیشه قفل می‌کرد — همان چیزی که این قابلیت برای رفعش هست.
     */
    public function test_a_stale_bank_receipt_stops_blocking_after_the_grace_window(): void
    {
        [$c, , $invoice] = $this->order();

        $r = \App\Models\BankTransferReceipt::create([
            'customer_id' => $c->id, 'invoice_id' => $invoice->id,
            'reference' => '999', 'amount' => 500000, 'status' => 'pending',
        ]);
        $r->forceFill(['created_at' => now()->subDays(60)])->save();

        $this->expire();

        $this->assertSame('canceled', $invoice->fresh()->status);
    }

    // ═══════════════ نبودِ ردیفِ زامبی ═══════════════

    /**
     * 🔴 خواهرهای پرداخت‌نشدهٔ همان سرویس هم باید بروند.
     *
     * وگرنه سرویس مرده است ولی فاکتورِ دومی هنوز در پنلِ مشتری دکمهٔ پرداخت
     * دارد: پول می‌آید و چون سرویس مرده است هیچ اتفاقی نمی‌افتد.
     */
    public function test_sibling_unpaid_invoices_of_the_same_order_are_cancelled_too(): void
    {
        [$c, $service, $invoice] = $this->order();

        $second = Invoice::create([
            'customer_id' => $c->id, 'service_id' => $service->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => 500000, 'tax' => 0, 'total' => 500000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $this->expire();

        $this->assertSame('canceled', $invoice->fresh()->status);
        $this->assertSame('canceled', $second->fresh()->status,
            'فاکتورِ خواهر زنده ماند — روی سرویسی که همین حالا کشتیم قابلِ پرداخت است');
    }

    /**
     * 🔴 دامنه هم باید آزاد شود، وگرنه آن نام برای همیشه قفل می‌مانَد:
     * `domains` قیدِ یکتایی دارد و سفارشِ دوباره رد می‌شود — یعنی مشتری آن نام
     * را برای خودش **و هر مشتریِ دیگری** می‌سوزاند.
     */
    public function test_a_domain_order_frees_the_domain_row(): void
    {
        $c = $this->customer();

        $domain = Domain::create([
            'customer_id' => $c->id, 'domain' => 'expiring-test.com', 'sld' => 'expiring-test',
            'tld' => 'com', 'registrar' => 'openprovider', 'status' => 'pending',
            'provision_status' => 'none',
        ]);

        $invoice = Invoice::create([
            'customer_id' => $c->id, 'domain_id' => $domain->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 2500000, 'tax' => 0, 'total' => 2500000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);
        $invoice->forceFill(['created_at' => now()->subHours(96)])->save();

        $this->expire();

        $this->assertSame('canceled', $invoice->fresh()->status);
        $this->assertSame('cancelled', $domain->fresh()->status,
            'ردیفِ دامنه یتیم ماند — آن نام برای همیشه غیرقابلِ سفارش می‌شود');
    }

    /** ⚠️ دامنه‌ای که واقعاً ثبت شده هرگز لمس نمی‌شود */
    public function test_a_registered_domain_is_never_touched(): void
    {
        $c = $this->customer();

        $domain = Domain::create([
            'customer_id' => $c->id, 'domain' => 'live-one.com', 'sld' => 'live-one',
            'tld' => 'com', 'registrar' => 'openprovider', 'status' => 'active',
            'provision_status' => 'done',
        ]);

        $invoice = Invoice::create([
            'customer_id' => $c->id, 'domain_id' => $domain->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 2500000, 'tax' => 0, 'total' => 2500000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);
        $invoice->forceFill(['created_at' => now()->subHours(96)])->save();

        $this->expire();

        $this->assertSame('unpaid', $invoice->fresh()->status, 'فاکتورِ تمدیدِ دامنهٔ زنده لغو شد');
        $this->assertSame('active', $domain->fresh()->status);
    }

    // ═══════════════ پول بعد از لغو ═══════════════

    /**
     * 🔴 بلاکری که در بازبینیِ تهاجمی پیدا شد.
     *
     * مشتری ساعتِ ۷۱:۵۹ پرداخت را شروع می‌کند و درگاه بعد از لغو برمی‌گردد.
     * تا امروز `applyPaid` وضعیتِ فاکتور را **اصلاً نمی‌پرسید**: مبلغ روی
     * `paid` می‌نشست و `canceled` را به `paid` برمی‌گرداند، ولی سرویس چون
     * `cancelled` است از گیتِ تحویل رد نمی‌شد ⇒ پول گرفته، سرویس نداده، بی‌هیچ
     * خطایی.
     *
     * حالا کلِ مبلغ به **اعتبارِ** مشتری می‌رود.
     */
    public function test_money_landing_on_a_cancelled_invoice_becomes_customer_credit(): void
    {
        [$c, , $invoice] = $this->order();

        $this->expire();
        $this->assertSame('canceled', $invoice->fresh()->status);

        $payment = Payment::create([
            'customer_id' => $c->id, 'invoice_id' => $invoice->id,
            'gateway' => 'zarinpal', 'amount' => 500000, 'currency_code' => 'IRT',
            'status' => 'pending', 'external_ref' => 'B'.random_int(1000, 9999),
        ]);

        app(\App\Services\Payment\PaymentService::class)->settleConfirmed($payment, 'REF-1');

        $credit = (int) \App\Models\CreditEntry::where('customer_id', $c->id)->sum('amount');

        $this->assertSame(500000, $credit, 'پول روی فاکتورِ لغوشده بخار شد');
        $this->assertSame('canceled', $invoice->fresh()->status, 'فاکتورِ لغوشده دوباره زنده شد');
    }

    // ═══════════════ ایمنیِ اجرا ═══════════════

    /**
     * ⚠️ این فرمان هر ساعت داخلِ `schedule:run` می‌دود. یک استثنا کلِ آن دقیقهٔ
     * کرون را می‌کشد: تحویلِ سرویس، ثبتِ دامنه، مترِ ساعتی، همه.
     */
    public function test_a_broken_schema_never_takes_the_scheduler_down(): void
    {
        $this->order();

        \Illuminate\Support\Facades\Schema::drop('services');

        $this->artisan('invoices:expire-orders')->assertSuccessful();
    }

    /** حالتِ `--dry` هیچ‌چیز نمی‌نویسد */
    public function test_dry_run_writes_nothing(): void
    {
        [, $service, $invoice] = $this->order();

        $this->artisan('invoices:expire-orders --dry')->assertSuccessful();

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame('pending', $service->fresh()->status);
    }

    /**
     * 🔴 فرمانِ ثبت‌نشده اجرا نمی‌شود — این پروژه دقیقاً همین را یک بار خورد
     * (`domains:provision` نوشته شده بود و در `routes/console.php` نبود).
     */
    public function test_the_command_is_actually_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'invoices:expire-orders'));

        $this->assertCount(1, $events, 'در routes/console.php ثبت نشده — هرگز اجرا نمی‌شود');
    }
}
