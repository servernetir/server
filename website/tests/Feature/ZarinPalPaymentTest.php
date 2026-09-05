<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Payment\GatewayRegistry;
use App\Services\Payment\PaymentService;
use App\Services\Payment\ZarinPalGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * پرداخت زرین‌پال.
 *
 * دو چیز اینجا قفل می‌شود که اگر بشکنند، پول واقعی از دست می‌رود:
 *   ۱) تبدیل تومان→ریال. یک صفر کم یا زیاد یعنی ده برابر یا یک‌دهم.
 *   ۲) یک‌بارمصرف بودن تسویه. رفرش صفحهٔ بازگشت نباید دوباره اعتبار بدهد.
 */
class ZarinPalPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'; // ۳۶ نویسه

    private function gateway(): ZarinPalGateway
    {
        return new ZarinPalGateway(self::MERCHANT, sandbox: false);
    }

    private function service(): PaymentService
    {
        $registry = new GatewayRegistry();
        $registry->register($this->gateway());

        return new PaymentService($registry);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active',
        ]);
    }

    private function invoice(Customer $c, int $total = 250000, string $kind = 'service'): Invoice
    {
        return Invoice::create([
            'customer_id' => $c->id, 'kind' => $kind, 'currency_code' => 'IRT',
            'subtotal' => $total, 'tax' => 0, 'total' => $total,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);
    }

    private function okRequest(string $authority = 'A00000000000000000000000000000000001'): array
    {
        return ['data' => ['code' => 100, 'authority' => $authority, 'fee' => 0], 'errors' => []];
    }

    // ─────────────────────────────────────────────────────────────────────

    /** ⚠ اگر این تست بشکند، مشتری یک‌دهم یا ده‌برابر می‌پردازد */
    public function test_the_amount_sent_to_zarinpal_is_rial_not_toman(): void
    {
        Http::fake(['api.zarinpal.com/*' => Http::response($this->okRequest())]);

        $c = $this->customer();
        $invoice = $this->invoice($c, 250_000);          // ۲۵۰٬۰۰۰ تومان

        $this->service()->begin($invoice, 'zarinpal', Request::create('/'));

        Http::assertSent(function ($request) {
            $this->assertSame(2_500_000, $request->data()['amount'], 'مبلغ باید ریال باشد');
            $this->assertSame(self::MERCHANT, $request->data()['merchant_id']);

            return true;
        });
    }

    public function test_verify_sends_the_stored_amount_not_anything_from_the_request(): void
    {
        Http::fake([
            '*request.json' => Http::response($this->okRequest()),
            '*verify.json'  => Http::response(['data' => ['code' => 100, 'ref_id' => 777], 'errors' => []]),
        ]);

        $c = $this->customer();
        $invoice = $this->invoice($c, 120_000);
        $out = $this->service()->begin($invoice, 'zarinpal', Request::create('/'));

        // مهاجم مبلغ دلخواه در بازگشت می‌فرستد
        $this->service()->settle($out->payment, [
            'Status' => 'OK', 'Authority' => $out->payment->external_ref, 'amount' => 10,
        ]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'verify.json')) {
                return false;
            }
            $this->assertSame(1_200_000, $request->data()['amount'], 'مبلغ تأیید باید از دیتابیس بیاید');

            return true;
        });
    }

    public function test_a_successful_payment_closes_the_invoice(): void
    {
        Http::fake([
            '*request.json' => Http::response($this->okRequest()),
            '*verify.json'  => Http::response([
                'data' => ['code' => 100, 'ref_id' => 123456, 'card_pan' => '603799******1234', 'fee' => 0],
                'errors' => [],
            ]),
        ]);

        $c = $this->customer();
        $invoice = $this->invoice($c, 250_000);
        $out = $this->service()->begin($invoice, 'zarinpal', Request::create('/'));

        $settle = $this->service()->settle($out->payment, [
            'Status' => 'OK', 'Authority' => $out->payment->external_ref,
        ]);

        $this->assertTrue($settle->ok);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(250_000, $invoice->paid);
        $this->assertNotNull($invoice->paid_at);

        $payment = $out->payment->fresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('123456', $payment->ref_id);
        $this->assertSame('603799******1234', $payment->card_mask);
    }

    /** رفرش صفحهٔ بازگشت — زرین‌پال کد ۱۰۱ می‌دهد */
    public function test_settling_twice_credits_the_invoice_only_once(): void
    {
        Http::fake([
            '*request.json' => Http::response($this->okRequest()),
            '*verify.json'  => Http::sequence()
                ->push(['data' => ['code' => 100, 'ref_id' => 555], 'errors' => []])
                ->push(['data' => ['code' => 101, 'ref_id' => 555], 'errors' => []]),
        ]);

        $c = $this->customer();
        $invoice = $this->invoice($c, 90_000);
        $out = $this->service()->begin($invoice, 'zarinpal', Request::create('/'));
        $cb = ['Status' => 'OK', 'Authority' => $out->payment->external_ref];

        $this->service()->settle($out->payment, $cb);
        $second = $this->service()->settle($out->payment->fresh(), $cb);

        $this->assertTrue($second->ok);
        $this->assertTrue($second->alreadySettled);

        $invoice->refresh();
        $this->assertSame(90_000, $invoice->paid, 'فاکتور دو بار اعتبار گرفت');
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->where('status', 'paid')->count());
    }

    public function test_a_topup_invoice_lands_in_the_credit_ledger(): void
    {
        Http::fake([
            '*request.json' => Http::response($this->okRequest()),
            '*verify.json'  => Http::response(['data' => ['code' => 100, 'ref_id' => 9], 'errors' => []]),
        ]);

        $c = $this->customer();
        $invoice = $this->invoice($c, 500_000, kind: 'topup');
        $out = $this->service()->begin($invoice, 'zarinpal', Request::create('/'));

        $this->service()->settle($out->payment, [
            'Status' => 'OK', 'Authority' => $out->payment->external_ref,
        ]);

        $this->assertSame(500_000, $c->fresh()->creditBalance());
        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)->count());
    }

    /**
     * تا کاربر در درگاه بود، فاکتور از راه دیگری بسته شد.
     * پول نه برمی‌گردد نه گم می‌شود — به اعتبارش می‌نشیند.
     */
    public function test_an_overpayment_goes_to_credit_instead_of_vanishing(): void
    {
        Http::fake([
            '*request.json' => Http::response($this->okRequest()),
            '*verify.json'  => Http::response(['data' => ['code' => 100, 'ref_id' => 42], 'errors' => []]),
        ]);

        $c = $this->customer();
        $invoice = $this->invoice($c, 100_000);
        $out = $this->service()->begin($invoice, 'zarinpal', Request::create('/'));

        // در همین فاصله فاکتور از راه دیگری تسویه شد
        $invoice->forceFill(['paid' => 100_000, 'status' => 'paid', 'paid_at' => now()])->save();

        $settle = $this->service()->settle($out->payment, [
            'Status' => 'OK', 'Authority' => $out->payment->external_ref,
        ]);

        $this->assertTrue($settle->ok);
        $this->assertSame(100_000, $c->fresh()->creditBalance(), 'مازاد پرداخت گم شد');
        $this->assertSame(100_000, $invoice->fresh()->paid, 'فاکتور بیش از مبلغش اعتبار گرفت');
    }

    public function test_user_cancellation_is_not_reported_as_an_error(): void
    {
        Http::fake(['*request.json' => Http::response($this->okRequest())]);

        $c = $this->customer();
        $out = $this->service()->begin($this->invoice($c), 'zarinpal', Request::create('/'));

        $settle = $this->service()->settle($out->payment, [
            'Status' => 'NOK', 'Authority' => $out->payment->external_ref,
        ]);

        $this->assertFalse($settle->ok);
        $this->assertTrue($settle->canceled);
        $this->assertSame('canceled', $out->payment->fresh()->status);
        // انصراف نباید verify صدا بزند — تماس بی‌مورد با درگاه
        Http::assertSentCount(1);
    }

    /** جلوگیری از چسباندن بازگشتِ یک پرداخت به پرداخت دیگر */
    public function test_a_mismatched_authority_is_rejected(): void
    {
        Http::fake(['*request.json' => Http::response($this->okRequest())]);

        $c = $this->customer();
        $out = $this->service()->begin($this->invoice($c), 'zarinpal', Request::create('/'));

        $settle = $this->service()->settle($out->payment, [
            'Status' => 'OK', 'Authority' => 'A99999999999999999999999999999999999',
        ]);

        $this->assertFalse($settle->ok);
        Http::assertSentCount(1);   // verify اصلاً صدا نشد
    }

    /**
     * زرین‌پال گاهی errors را آرایهٔ خالی می‌دهد و گاهی شیء.
     * خواندن کورکورانه، بازگشتِ پرداخت را با ۵۰۰ می‌شکند — یعنی مشتری پول
     * داده و صفحهٔ خطای لاراول می‌بیند.
     */
    public function test_both_shapes_of_the_errors_field_are_survivable(): void
    {
        foreach ([
            ['errors' => ['code' => -11, 'message' => 'Invalid']],
            ['errors' => [['code' => -11, 'message' => 'Invalid']]],
            ['errors' => []],
        ] as $shape) {
            Http::fake(['*request.json' => Http::response(['data' => ['code' => -11]] + $shape)]);

            $c = $this->customer();
            $out = $this->service()->begin($this->invoice($c), 'zarinpal', Request::create('/'));

            $this->assertFalse($out->ok);
            $this->assertNotEmpty($out->error, 'پیام خطا خالی ماند');
        }
    }

    public function test_an_unconfigured_merchant_id_disables_the_gateway(): void
    {
        $this->assertFalse((new ZarinPalGateway(null))->enabled());
        $this->assertFalse((new ZarinPalGateway('too-short'))->enabled());
        $this->assertTrue((new ZarinPalGateway(self::MERCHANT))->enabled());
    }

    public function test_expired_attempts_are_not_settled_automatically(): void
    {
        Http::fake(['*request.json' => Http::response($this->okRequest())]);

        $c = $this->customer();
        $out = $this->service()->begin($this->invoice($c), 'zarinpal', Request::create('/'));

        $out->payment->forceFill(['expires_at' => now()->subMinute()])->save();

        $settle = $this->service()->settle($out->payment->fresh(), [
            'Status' => 'OK', 'Authority' => $out->payment->external_ref,
        ]);

        $this->assertFalse($settle->ok);
        Http::assertSentCount(1);
    }

    public function test_starting_a_new_attempt_cancels_the_previous_open_one(): void
    {
        Http::fake(['*request.json' => Http::sequence()
            ->push($this->okRequest('A00000000000000000000000000000000001'))
            ->push($this->okRequest('A00000000000000000000000000000000002'))]);

        $c = $this->customer();
        $invoice = $this->invoice($c);

        $first  = $this->service()->begin($invoice, 'zarinpal', Request::create('/'));
        $second = $this->service()->begin($invoice, 'zarinpal', Request::create('/'));

        $this->assertSame('canceled', $first->payment->fresh()->status);
        $this->assertSame('redirected', $second->payment->fresh()->status);
    }

    public function test_a_customer_cannot_open_another_customers_invoice(): void
    {
        $mine    = $this->customer();
        $theirs  = $this->customer();
        $invoice = $this->invoice($theirs);

        $this->actingAs($mine, 'customer')
            ->get('/account/invoices/'.$invoice->id)
            ->assertNotFound();   // ۴۰۴ و نه ۴۰۳ — وگرنه وجودش تأیید می‌شود
    }

    /**
     * کارمزد هم ریال برمی‌گردد — چون مبلغ را ریالی فرستادیم.
     *
     * ستونِ `payments.fee` واحدش تومان است. تا ممیزیِ شهریور ۱۴۰۵ عددِ ریالی
     * خام در آن می‌نشست؛ چون هیچ‌کس نمی‌خواندش، ده‌برابر بودنش دیده نمی‌شد.
     * حالا که هزینهٔ دفتر از همین ستون می‌آید، این تبدیل قفل می‌شود.
     */
    public function test_the_fee_returned_by_zarinpal_is_stored_in_toman(): void
    {
        Http::fake([
            '*request.json' => Http::response($this->okRequest()),
            '*verify.json'  => Http::response([
                'data' => ['code' => 100, 'ref_id' => 55, 'fee' => 12_000, 'fee_type' => 'Merchant'],
                'errors' => [],
            ]),
        ]);

        $c = $this->customer();
        $invoice = $this->invoice($c, 250_000);
        $out = $this->service()->begin($invoice, 'zarinpal', Request::create('/'));

        $this->service()->settle($out->payment, [
            'Status' => 'OK', 'Authority' => $out->payment->external_ref,
        ]);

        // ۱۲٬۰۰۰ ریال = ۱٬۲۰۰ تومان، نه ۱۲٬۰۰۰
        $this->assertSame(1_200, (int) $out->payment->fresh()->fee);
        $this->assertSame('Merchant', $out->payment->fresh()->fee_type);
    }

    /** و همان کارمزد باید در دفتر مالی هزینه شده باشد، نه فقط ذخیره. */
    public function test_the_gateway_fee_reaches_the_business_ledger(): void
    {
        Http::fake([
            '*request.json' => Http::response($this->okRequest()),
            '*verify.json'  => Http::response([
                'data' => ['code' => 100, 'ref_id' => 56, 'fee' => 12_000, 'fee_type' => 'Merchant'],
                'errors' => [],
            ]),
        ]);

        $c = $this->customer();
        $invoice = $this->invoice($c, 250_000);
        $out = $this->service()->begin($invoice, 'zarinpal', Request::create('/'));

        $this->service()->settle($out->payment, [
            'Status' => 'OK', 'Authority' => $out->payment->external_ref,
        ]);

        $this->assertDatabaseHas('business_ledger', [
            'kind' => 'expense', 'category' => 'payment_fee', 'amount' => 1_200,
        ]);
    }
}
