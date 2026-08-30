<?php

namespace Tests\Feature;

use App\Models\BaleContact;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Payment\BaleGateway;
use App\Services\Payment\GatewayRegistry;
use App\Services\Payment\PaymentService;
use App\Services\Bale\BaleSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * پرداخت با بله — از کیف پول، تسویه با وب‌هوک.
 *
 * محورها:
 *   • مبلغ به ریال فرستاده می‌شود (×۱۰)
 *   • بدون اتصال بله، پرداخت شروع نمی‌شود (پیام راهنما)
 *   • PreCheckout مبلغِ نادرست را رد می‌کند (پول برداشته نشود)
 *   • SuccessfulPayment فاکتور را تسویه می‌کند، idempotent
 */
class BalePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bale.token' => 'bot-tok', 'services.bale.wallet' => 'WALLET-TEST-1111111111111111']);
    }

    private function service(): PaymentService
    {
        $registry = new GatewayRegistry();
        $registry->register(new BaleGateway(new BaleSender('bot-tok'), 'WALLET-TEST-1111111111111111'));
        $this->app->instance(GatewayRegistry::class, $registry);

        return new PaymentService($registry);
    }

    private function customerWithBale(bool $linked = true): Customer
    {
        $c = Customer::create([
            'email' => 'b'.random_int(1, 99999).'@x.com',
            'phone' => '09121234567', 'password' => 'secret1234', 'status' => 'active',
        ]);
        if ($linked) {
            BaleContact::create(['mobile' => '09121234567', 'chat_id' => '4242', 'linked_at' => now()]);
        }

        return $c;
    }

    private function invoice(Customer $c, int $total = 250000): Invoice
    {
        return Invoice::create([
            'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => $total, 'tax' => 0, 'total' => $total,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);
    }

    private function hookUrl(): string
    {
        return '/bale/webhook/'.substr(hash('sha256', 'bot-tok'), 0, 32);
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_the_invoice_amount_is_sent_to_bale_in_rial(): void
    {
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => true])]);

        $c = $this->customerWithBale();
        $out = $this->service()->begin($this->invoice($c, 250_000), 'bale', Request::create('/'));

        $this->assertTrue($out->ok);
        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'sendInvoice')) {
                return false;
            }
            $this->assertSame('4242', $req->data()['chat_id']);
            $this->assertSame(2_500_000, $req->data()['prices'][0]['amount'], 'باید ریال باشد');

            return true;
        });
    }

    public function test_without_a_linked_bale_the_payment_does_not_start(): void
    {
        Http::fake();
        $c = $this->customerWithBale(linked: false);

        $out = $this->service()->begin($this->invoice($c), 'bale', Request::create('/'));

        $this->assertFalse($out->ok);
        $this->assertStringContainsString('بله', $out->error);
        Http::assertNothingSent();
    }

    public function test_pre_checkout_with_wrong_amount_is_rejected(): void
    {
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => true])]);
        $c = $this->customerWithBale();
        $out = $this->service()->begin($this->invoice($c, 250_000), 'bale', Request::create('/'));

        // بله مبلغ غلط می‌فرستد
        $this->postJson($this->hookUrl(), [
            'pre_checkout_query' => ['id' => 'q1', 'invoice_payload' => $out->payment->external_ref, 'total_amount' => 999],
        ])->assertOk();

        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'answerPreCheckoutQuery')) {
                return false;
            }
            $this->assertFalse($req->data()['ok'], 'مبلغ غلط باید رد شود');

            return true;
        });
        $this->assertSame('redirected', $out->payment->fresh()->status);
    }

    public function test_pre_checkout_with_correct_amount_is_accepted(): void
    {
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => true])]);
        $c = $this->customerWithBale();
        $out = $this->service()->begin($this->invoice($c, 250_000), 'bale', Request::create('/'));

        $this->postJson($this->hookUrl(), [
            'pre_checkout_query' => ['id' => 'q1', 'invoice_payload' => $out->payment->external_ref, 'total_amount' => 2_500_000],
        ])->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'answerPreCheckoutQuery') && $req->data()['ok'] === true);
    }

    public function test_successful_payment_settles_the_invoice(): void
    {
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => true])]);
        $c = $this->customerWithBale();
        $invoice = $this->invoice($c, 250_000);
        $out = $this->service()->begin($invoice, 'bale', Request::create('/'));

        $this->postJson($this->hookUrl(), [
            'message' => [
                'chat' => ['id' => 4242],
                'successful_payment' => [
                    'invoice_payload' => $out->payment->external_ref,
                    'total_amount' => 2_500_000,
                    'provider_payment_charge_id' => 'TRK-99',
                ],
            ],
        ])->assertOk();

        $this->assertSame('paid', $out->payment->fresh()->status);
        $this->assertSame('TRK-99', $out->payment->fresh()->ref_id);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_a_duplicate_successful_payment_does_not_double_settle(): void
    {
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => true])]);
        $c = $this->customerWithBale();
        $invoice = $this->invoice($c, 100_000);
        $out = $this->service()->begin($invoice, 'bale', Request::create('/'));

        $sp = ['message' => ['chat' => ['id' => 4242], 'successful_payment' => [
            'invoice_payload' => $out->payment->external_ref, 'total_amount' => 1_000_000,
            'provider_payment_charge_id' => 'TRK-1',
        ]]];

        $this->postJson($this->hookUrl(), $sp);
        $this->postJson($this->hookUrl(), $sp);   // بله دو بار فرستاد

        $invoice->refresh();
        $this->assertSame(100_000, $invoice->paid, 'فاکتور دو بار تسویه شد');
    }
}
