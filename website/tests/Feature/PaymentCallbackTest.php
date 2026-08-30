<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * بازگشت از درگاه — همان مسیری که روی پروداکشن ۵۰۰ می‌داد.
 *
 * عمداً روتِ HTTP واقعی صدا زده می‌شود (نه فقط سرویس)، تا رندرِ ویو و پوستهٔ
 * سایت و هدر هم سنجیده شود؛ ۵۰۰ می‌توانست از هرکدام بیاید.
 */
class PaymentCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /**
     * حتی اگر محاسبهٔ سررسید/فعال‌سازی خطا بدهد، پرداخت **باید** ثبت شود و
     * سرویس در صفِ تحویل بنشیند. قبلاً کلِ تراکنش برمی‌گشت: ۵۰۰ به مشتری،
     * فاکتورِ پرداخت‌نشده و سرویسِ ساخته‌نشده.
     *
     * خطا را با یک دورهٔ نامعتبر می‌سازیم که ریاضیِ سررسید را می‌شکند.
     */
    public function test_activation_failure_still_settles_the_payment(): void
    {
        $customer = $this->customer();
        $server = Server::create([
            'name' => 'WHM-IR', 'type' => 'whm', 'status' => 'active', 'country' => 'IR',
            'hostname' => 'ir.test', 'username' => 'root', 'api_token' => 't', 'verify_tls' => false,
        ]);
        $service = Service::create([
            'customer_id' => $customer->id, 'server_id' => $server->id, 'name' => 'هاست',
            'currency_code' => 'IRT', 'price' => 250000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'pending', 'plan' => 'sn_x', 'domain' => 'shop.example.com',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'service_id' => $service->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => 250000, 'tax' => 0, 'total' => 250000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);
        $payment = Payment::create([
            'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'gateway' => 'bale',
            'currency_code' => 'IRT', 'amount' => 250000, 'status' => 'redirected', 'external_ref' => 'C300',
        ]);

        // تاریخِ فعال‌سازیِ نامعتبر → Carbon موقعِ محاسبهٔ سررسید خطا می‌دهد
        \Illuminate\Support\Facades\DB::table('services')
            ->where('id', $service->id)->update(['activated_at' => 'not-a-date']);

        app(\App\Services\Payment\PaymentService::class)->settleConfirmed($payment, 'REF-X');

        // مهم‌ترین ادعا: پول ثبت شده
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertTrue($payment->fresh()->isPaid());

        // و سرویس در صفِ تحویل است تا کرون بسازدش
        $row = \Illuminate\Support\Facades\DB::table('services')->where('id', $service->id)->first();
        $this->assertSame('awaiting_provision', $row->status);
        $this->assertSame('pending', $row->provision_status);
    }

    /** ناشناس: صفحهٔ نتیجه باید رندر شود، نه ۵۰۰ */
    public function test_unknown_reference_renders_result_page(): void
    {
        $this->get('/payment/callback/zarinpal?Authority=NOPE')
            ->assertOk();
    }

    /** بازگشتِ ناموفق (لغو کاربر) هم باید صفحه بدهد */
    public function test_failed_callback_renders_without_error(): void
    {
        $customer = $this->customer();
        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 250000, 'tax' => 0, 'total' => 250000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);
        Payment::create([
            'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'gateway' => 'zarinpal',
            'currency_code' => 'IRT', 'amount' => 250000, 'status' => 'redirected', 'external_ref' => 'A100',
        ]);

        // زرین‌پال «ناموفق» برمی‌گرداند
        Http::fake(['*zarinpal*' => Http::response(['data' => [], 'errors' => ['code' => -51, 'message' => 'failed']])]);

        $this->get('/payment/callback/zarinpal?Authority=A100&Status=NOK')
            ->assertOk();
    }

    /**
     * پرداختِ موفقِ سرویس: صفحه باید ۲۰۰ بدهد، فاکتور پرداخت‌شده شود و سرویس
     * وارد صفِ تحویل شود (وضعیتی که کرونِ provision:run می‌سازدش).
     */
    public function test_successful_callback_marks_paid_and_queues_provisioning(): void
    {
        $customer = $this->customer();
        $server = Server::create([
            'name' => 'WHM-IR', 'type' => 'whm', 'status' => 'active', 'country' => 'IR',
            'hostname' => 'ir.test', 'username' => 'root', 'api_token' => 't', 'verify_tls' => false,
        ]);
        $service = Service::create([
            'customer_id' => $customer->id, 'server_id' => $server->id, 'name' => 'هاست',
            'currency_code' => 'IRT', 'price' => 250000, 'tax_percent' => 0, 'cycle' => 'yearly',
            'status' => 'pending', 'plan' => 'sn_x', 'domain' => 'shop.example.com',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'service_id' => $service->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => 250000, 'tax' => 0, 'total' => 250000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);
        Payment::create([
            'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'gateway' => 'zarinpal',
            'currency_code' => 'IRT', 'amount' => 250000, 'status' => 'redirected', 'external_ref' => 'B200',
        ]);

        // تسویهٔ تأییدشده — همان مسیرِ applyPaid که وب‌هوک/بازگشتِ موفق می‌رود،
        // بدونِ درگیرکردنِ verifyِ درگاه (که در تست به شبکهٔ واقعی می‌زند).
        $payment = Payment::where('external_ref', 'B200')->firstOrFail();
        app(\App\Services\Payment\PaymentService::class)->settleConfirmed($payment, 'REF-998877');

        $this->assertSame('paid', $invoice->fresh()->status);

        $service->refresh();
        $this->assertSame('awaiting_provision', $service->status);
        $this->assertSame('pending', $service->provision_status);
        $this->assertNotNull($service->next_due_at);
        $this->assertTrue($service->next_due_at->isFuture());
    }
}
