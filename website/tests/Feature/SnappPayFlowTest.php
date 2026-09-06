<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SnappPayOrder;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * چرخهٔ کاملِ پرداختِ اقساطی: token → کالبک → verify → settle.
 *
 * ═══ چیزی که این فایل نگه می‌دارد ═══
 *
 * 🔴 **settle اجباری است.** مستنداتِ اسنپ‌پی: «عدمِ فراخوانیِ verify منجر به
 * بازگشتِ مبلغ از حسابِ کاربر می‌شود» و «تمامی سفارشاتی که به مرحلهٔ Verify
 * رسیده‌اند نیازمندِ فراخوانیِ Settle می‌باشند». سفارشی که verify شده و
 * settle نشده، پولی است که نه دستِ ماست نه دستِ مشتری — و هیچ خطایی هم
 * تولید نمی‌کند.
 *
 * 🔴 **کالبک حکم نیست.** فرمِ بازگشتی POST است و بدونِ CSRF؛ هر کسی می‌تواند
 * `state=OK` بفرستد. تنها چیزی که فاکتور را تسویه می‌کند پاسخِ سرور-به-سرور
 * است.
 */
class SnappPayFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'snapppay.enabled'       => true,
            'snapppay.base_url'      => 'https://snapppay.test',
            'snapppay.client_id'     => 'cid',
            'snapppay.client_secret' => 'secret',
            'snapppay.username'      => 'user',
            'snapppay.password'      => 'pass',
        ]);
    }

    // ─────────────────────────── فیکسچرها ───────────────────────────

    private function customer(): Customer
    {
        return Customer::create([
            'code'     => 'SN-'.random_int(100000, 999999),
            'email'    => 'f'.random_int(1, 999999).'@example.com',
            'phone'    => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-pass-123'),
            'status'   => 'active',
        ]);
    }

    private function invoice(?Customer $c = null, int $unit = 500_000): Invoice
    {
        $c ??= $this->customer();

        $inv = Invoice::create([
            'customer_id'   => $c->id,
            'currency_code' => 'IRT',
            'subtotal'      => 0, 'tax' => 0, 'total' => 0, 'paid' => 0,
            'status'        => 'unpaid', 'issued_at' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv->id, 'title' => 'سرور ابری',
            'quantity'   => 1, 'unit_price' => $unit, 'line_total' => $unit,
            'discount'   => 0, 'tax_rate_bp' => 0, 'tax_amount' => 0,
        ]);

        $inv->recalculateTotals();
        $inv->save();

        return $inv->fresh(['items']);
    }

    /** پاسخ‌های اسنپ‌پی، همان قالبی که مستندات می‌دهد. */
    private function fake(array $over = []): void
    {
        Http::fake($over + [
            '*oauth/token'    => Http::response(['access_token' => 'jwt-x', 'expires_in' => 3600]),
            '*payment/v1/token' => Http::response([
                'successful' => true,
                'response'   => ['paymentToken' => 'PT-1', 'paymentPageUrl' => 'https://snapppay.test/pay/PT-1'],
            ]),
            '*payment/v1/verify' => Http::response([
                'successful' => true, 'response' => ['transactionId' => 'S0001'],
            ]),
            '*payment/v1/settle' => Http::response([
                'successful' => true, 'response' => ['transactionId' => 'S0001'],
            ]),
        ]);
    }

    private function begin(Invoice $inv)
    {
        return app(PaymentService::class)->begin($inv, 'snapppay', Request::create('/', 'GET'));
    }

    // ───────────────────────────── شروع ─────────────────────────────

    public function test_a_successful_start_creates_an_order_and_redirects(): void
    {
        $this->fake();
        $inv = $this->invoice();

        $out = $this->begin($inv);

        $this->assertTrue($out->ok, $out->error ?? '');
        $this->assertSame('https://snapppay.test/pay/PT-1', $out->redirectUrl);
        $this->assertSame('PT-1', $out->payment->external_ref, 'توکن همان external_ref است');

        $order = SnappPayOrder::where('payment_id', $out->payment->id)->firstOrFail();
        $this->assertSame('pending', $order->state);
        $this->assertSame($inv->total, $order->amount);
        $this->assertNotEmpty($order->cart, 'سبدِ فرستاده‌شده برای update و ممیزی نگه داشته می‌شود');
    }

    /** مبلغِ ریالی باید در بدنهٔ واقعیِ درخواست باشد، نه فقط در تستِ سبد. */
    public function test_the_request_body_carries_rial(): void
    {
        $this->fake();
        $inv = $this->invoice(unit: 500_000);

        $this->begin($inv);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'payment/v1/token')) {
                return false;
            }

            return $request['amount'] === 5_000_000;   // ۵۰۰٬۰۰۰ تومان
        });
    }

    public function test_a_customer_without_a_mobile_cannot_use_installments(): void
    {
        $this->fake();
        $c = $this->customer();
        $c->forceFill(['phone' => null])->save();

        $out = $this->begin($this->invoice($c));

        $this->assertFalse($out->ok);
        $this->assertStringContainsString('موبایل', (string) $out->error);
        $this->assertSame(0, SnappPayOrder::count(), 'سفارشی ساخته نمی‌شود');
    }

    /** 🔴 سبدِ کلِ فاکتور را توصیف می‌کند، پس پرداختِ جزئی ممکن نیست. */
    public function test_a_partly_paid_invoice_is_refused(): void
    {
        $this->fake();
        $inv = $this->invoice();
        $inv->forceFill(['paid' => 100_000])->save();

        $out = $this->begin($inv->fresh(['items']));

        $this->assertFalse($out->ok);
        $this->assertStringContainsString('کلِ فاکتور', (string) $out->error);
    }

    // ──────────────────────── کالبک و نهایی‌سازی ────────────────────────

    public function test_the_callback_verifies_settles_and_pays_the_invoice(): void
    {
        $this->fake();
        $inv = $this->invoice();
        $out = $this->begin($inv);
        $order = SnappPayOrder::where('payment_id', $out->payment->id)->firstOrFail();

        $this->post('/payment/callback/snapppay', [
            'transactionId' => $order->transaction_id,
            'state'         => 'OK',
            'amount'        => $inv->total * 10,
        ])->assertOk();

        $order->refresh();
        $this->assertSame('settled', $order->state);
        $this->assertNotNull($order->verified_at);
        $this->assertNotNull($order->settled_at);

        $this->assertSame('paid', $inv->fresh()->status, 'فاکتور تسویه شد');

        // هر دو تماس واقعاً رفتند — settle فراموش‌شدنی نیست
        Http::assertSent(fn ($r) => str_contains($r->url(), 'payment/v1/verify'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'payment/v1/settle'));
    }

    /**
     * 🔴 اگر settle نگیرد، فاکتور **نباید** پرداخت‌شده اعلام شود.
     *
     * این بدترین خرابیِ ممکنِ این درگاه است: سرویس تحویل داده می‌شود ولی پول
     * سمتِ اسنپ‌پی نهایی نشده و برمی‌گردد.
     */
    public function test_a_failed_settle_does_not_mark_the_invoice_paid(): void
    {
        $this->fake([
            '*payment/v1/settle' => Http::response([
                'successful' => false,
                'errorData'  => ['errorCode' => 500, 'message' => 'boom'],
            ], 500),
            '*payment/v1/status*' => Http::response([
                'successful' => true,
                'response'   => ['transactionId' => 'S1', 'status' => 'VERIFY', 'amount' => 5_000_000],
            ]),
        ]);

        $inv = $this->invoice();
        $out = $this->begin($inv);
        $order = SnappPayOrder::where('payment_id', $out->payment->id)->firstOrFail();

        $this->post('/payment/callback/snapppay', [
            'transactionId' => $order->transaction_id, 'state' => 'OK',
        ])->assertOk();

        $this->assertSame('unpaid', $inv->fresh()->status);
        $this->assertSame('verified', $order->fresh()->state, 'برای پیگیریِ کرون باز می‌مانَد');
    }

    /**
     * وقتی settle پاسخ نداد ولی اسنپ‌پی می‌گوید SETTLE، حکم با اسنپ‌پی است.
     * این همان مسیرِ رفعِ مغایرتی است که مستندات اجباری کرده.
     */
    public function test_the_remote_status_settles_what_the_call_could_not(): void
    {
        $this->fake([
            '*payment/v1/settle' => Http::response([], 500),
            '*payment/v1/status*' => Http::response([
                'successful' => true,
                'response'   => ['transactionId' => 'S1', 'status' => 'SETTLE', 'amount' => 5_000_000],
            ]),
        ]);

        $inv = $this->invoice();
        $out = $this->begin($inv);
        $order = SnappPayOrder::where('payment_id', $out->payment->id)->firstOrFail();

        $this->post('/payment/callback/snapppay', [
            'transactionId' => $order->transaction_id, 'state' => 'OK',
        ])->assertOk();

        $this->assertSame('settled', $order->fresh()->state);
        $this->assertSame('paid', $inv->fresh()->status);
    }

    /** انصرافِ کاربر خطا نیست و نباید فاکتور را خراب کند. */
    public function test_a_failed_state_is_a_cancellation_not_an_error(): void
    {
        $this->fake();
        $inv = $this->invoice();
        $out = $this->begin($inv);
        $order = SnappPayOrder::where('payment_id', $out->payment->id)->firstOrFail();

        $this->post('/payment/callback/snapppay', [
            'transactionId' => $order->transaction_id, 'state' => 'FAILED',
        ])->assertOk();

        $this->assertSame('failed', $order->fresh()->state);
        $this->assertSame('unpaid', $inv->fresh()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'payment/v1/verify'));
    }

    /** شناسهٔ ناشناخته نباید هیچ فاکتوری را لمس کند. */
    public function test_an_unknown_transaction_id_settles_nothing(): void
    {
        $this->fake();
        $inv = $this->invoice();
        $this->begin($inv);

        $this->post('/payment/callback/snapppay', [
            'transactionId' => 'NOPE9', 'state' => 'OK',
        ])->assertOk();

        $this->assertSame('unpaid', $inv->fresh()->status);
    }

    /** ⚠️ تکرارِ کالبک نباید verify را دو بار بفرستد — مستندات صریحاً منع کرده. */
    public function test_a_repeated_callback_does_not_verify_twice(): void
    {
        $this->fake();
        $inv = $this->invoice();
        $out = $this->begin($inv);
        $order = SnappPayOrder::where('payment_id', $out->payment->id)->firstOrFail();

        $body = ['transactionId' => $order->transaction_id, 'state' => 'OK'];

        $this->post('/payment/callback/snapppay', $body)->assertOk();
        $this->post('/payment/callback/snapppay', $body)->assertOk();

        $verifies = 0;
        Http::assertSent(function ($r) use (&$verifies) {
            if (str_contains($r->url(), 'payment/v1/verify')) {
                $verifies++;
            }

            return true;
        });

        $this->assertSame(1, $verifies, 'verify فقط یک بار');
    }

    /** درگاهِ خاموش اصلاً شروع نمی‌شود. */
    public function test_a_disabled_gateway_starts_nothing(): void
    {
        config(['snapppay.enabled' => false]);
        $this->fake();

        $out = $this->begin($this->invoice());

        $this->assertFalse($out->ok);
        $this->assertSame(0, SnappPayOrder::count());
    }
}
