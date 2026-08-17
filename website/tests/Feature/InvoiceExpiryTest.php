<?php

namespace Tests\Feature;

use App\Models\BankTransferReceipt;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مهلتِ پرداختِ پیش‌فاکتور — و مهم‌تر، چیزهایی که **نباید** لغو شوند.
 *
 * ⚠️ ادعاها روی وضعیتِ واقعیِ ردیف‌اند نه بر خروجیِ فرمان: فرمانی که «۳ لغو شد»
 * چاپ کند و ردیف را دست‌نخورده بگذارد، سبز به‌نظر می‌رسد و هیچ‌کاری نکرده.
 */
class InvoiceExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'inv'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function invoice(Customer $c, array $over = []): Invoice
    {
        return Invoice::create(array_merge([
            'customer_id'   => $c->id,
            'kind'          => 'service',
            'currency_code' => 'IRT',
            'subtotal'      => 1_000_000,
            'tax'           => 0,
            'total'         => 1_000_000,
            'paid'          => 0,
            'status'        => 'unpaid',
            'issued_at'     => now()->subDays(5),
            'due_at'        => now()->subDays(3),
        ], $over));
    }

    /** مهلت خودبه‌خود روی هر فاکتورِ تازه می‌نشیند — بی‌آنکه کنترلر کاری کند */
    public function test_every_new_invoice_gets_a_deadline_without_the_caller_asking(): void
    {
        config()->set('billing.invoice_hold_hours', 48);

        $inv = Invoice::create([
            'customer_id' => $this->customer()->id,
            'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 1000, 'tax' => 0, 'total' => 1000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $this->assertNotNull($inv->due_at, 'فاکتورِ تازه مهلت نگرفت.');
        $this->assertSame(48, (int) round($inv->issued_at->diffInHours($inv->due_at)));
    }

    /**
     * ⚠️ صفر یعنی «خاموش»، نه «فوراً منقضی».
     *
     * اگر تنظیمات خراب یا خالی باشد، نباید هر فاکتورِ تازه در همان ثانیه
     * قابلِ لغو شود — یعنی یک اشتباهِ پیکربندی نباید کلِ فروش را پاک کند.
     */
    public function test_zero_hours_disables_the_deadline_instead_of_expiring_instantly(): void
    {
        config()->set('billing.invoice_hold_hours', 0);

        $inv = Invoice::create([
            'customer_id' => $this->customer()->id,
            'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 1000, 'tax' => 0, 'total' => 1000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $this->assertNull($inv->due_at);
    }

    public function test_a_stale_unpaid_invoice_is_cancelled(): void
    {
        $inv = $this->invoice($this->customer());

        $this->artisan('invoices:expire')->assertSuccessful();

        $this->assertSame('canceled', $inv->fresh()?->status);
    }

    /** فاکتوری که هنوز مهلت دارد دست نمی‌خورد */
    public function test_an_invoice_still_within_its_window_is_untouched(): void
    {
        $inv = $this->invoice($this->customer(), ['due_at' => now()->addDay()]);

        $this->artisan('invoices:expire')->assertSuccessful();

        $this->assertSame('unpaid', $inv->fresh()?->status);
    }

    /**
     * 🔴 فاکتورِ تمدیدِ سرویسِ **زنده** هرگز لغو نمی‌شود.
     *
     * `services:lifecycle` روی وجودِ همان فاکتورِ پرداخت‌نشده کار می‌کند:
     * یادآوری، «سررسید گذشت»، و در نهایت تعلیق. لغوش یعنی آن زنجیره کور شود و
     * مشتری برای همیشه سرویسِ رایگان بگیرد، بی‌آنکه کسی خبردار شود.
     */
    public function test_a_renewal_invoice_of_a_live_service_is_never_cancelled(): void
    {
        $c = $this->customer();

        foreach (['active', 'suspended'] as $status) {
            $svc = Service::create([
                'customer_id' => $c->id, 'name' => 'هاست', 'plan' => 'p',
                'cycle' => 'monthly', 'price' => 1000, 'currency_code' => 'IRT',
                'status' => $status,
            ]);

            $inv = $this->invoice($c, ['service_id' => $svc->id]);

            $this->artisan('invoices:expire')->assertSuccessful();

            $this->assertSame('unpaid', $inv->fresh()?->status,
                "فاکتورِ تمدیدِ سرویسِ {$status} لغو شد — زنجیرهٔ تعلیق کور می‌شود.");
        }
    }

    /**
     * 🔴 رسیدِ بانکیِ در انتظارِ بررسی یعنی مشتری پول را فرستاده.
     *
     * لغوِ خودکار این‌جا بدترین حالتِ ممکن برای اعتماد است: پولِ واریزشده و
     * فاکتورِ لغوشده.
     */
    public function test_an_invoice_awaiting_a_bank_receipt_is_never_cancelled(): void
    {
        $c = $this->customer();
        $inv = $this->invoice($c);

        BankTransferReceipt::create([
            'customer_id' => $c->id,
            'invoice_id'  => $inv->id,
            'amount'      => $inv->total,
            'reference'   => '123456',
            'status'      => 'pending',
        ]);

        $this->artisan('invoices:expire')->assertSuccessful();

        $this->assertSame('unpaid', $inv->fresh()?->status);
    }

    /** پرداختِ جزئی یعنی پولی گرفته‌ایم — تصمیمش با آدم است */
    public function test_a_partially_paid_invoice_is_never_cancelled(): void
    {
        $inv = $this->invoice($this->customer(), ['paid' => 250_000]);

        $this->artisan('invoices:expire')->assertSuccessful();

        $this->assertSame('unpaid', $inv->fresh()?->status);
    }

    /**
     * لغو باید چیزی را که رزرو شده **آزاد** کند.
     *
     * وگرنه ردیفِ `pending` تا ابد می‌مانَد و همان شلوغی فقط از جدولِ دیگری سر
     * درمی‌آورد — و دامنهٔ `pending` در پنلِ مدیر مثلِ یک سفارشِ گیرکردهٔ واقعی
     * دیده می‌شود.
     */
    public function test_cancelling_releases_the_reserved_order(): void
    {
        $c = $this->customer();

        $svc = Service::create([
            'customer_id' => $c->id, 'name' => 'هاست', 'plan' => 'p',
            'cycle' => 'monthly', 'price' => 1000, 'currency_code' => 'IRT',
            'status' => 'pending',
        ]);

        $dom = Domain::create([
            'customer_id' => $c->id, 'domain' => 'held.com', 'sld' => 'held', 'tld' => 'com',
            'registrar' => 'openprovider', 'status' => 'pending',
            'provision_status' => 'none', 'period_years' => 1,
        ]);

        $this->invoice($c, ['service_id' => $svc->id, 'domain_id' => $dom->id]);

        $this->artisan('invoices:expire')->assertSuccessful();

        $this->assertSame('cancelled', $svc->fresh()?->status);
        $this->assertSame('cancelled', $dom->fresh()?->status);
    }

    /**
     * 🔴 دامنه‌ای که ثبتش شروع شده آزاد نمی‌شود.
     *
     * `provision_status` غیرِ `none` یعنی ممکن است همین حالا نزدِ رجیسترار در
     * حالِ ثبت باشد؛ لغوِ ردیف، دامنهٔ واقعاً ثبت‌شده را از پنلِ مشتری غیب
     * می‌کند در حالی که پولش رفته.
     */
    public function test_a_domain_already_in_flight_is_not_released(): void
    {
        $c = $this->customer();

        $dom = Domain::create([
            'customer_id' => $c->id, 'domain' => 'inflight.com', 'sld' => 'inflight', 'tld' => 'com',
            'registrar' => 'openprovider', 'status' => 'pending',
            'provision_status' => 'running', 'period_years' => 1,
        ]);

        $this->invoice($c, ['domain_id' => $dom->id]);

        $this->artisan('invoices:expire')->assertSuccessful();

        $this->assertSame('pending', $dom->fresh()?->status,
            'دامنه‌ای که در حالِ ثبت بود لغو شد.');
    }

    /** `--dry` هیچ عارضه‌ای ندارد */
    public function test_dry_run_changes_nothing(): void
    {
        $inv = $this->invoice($this->customer());

        $this->artisan('invoices:expire --dry')->assertSuccessful();

        $this->assertSame('unpaid', $inv->fresh()?->status);
    }
}
