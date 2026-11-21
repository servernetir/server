<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Finance\Wallet;
use App\Services\Payment\GatewayRegistry;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentService;
use App\Services\Payment\StartResult;
use App\Services\Payment\VerifyResult;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentTerminalStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
    }

    private function invoice(): Invoice
    {
        $customer = Customer::create(['email' => 'terminal@example.test', 'status' => 'active', 'locale' => 'fa']);

        return Invoice::create(['customer_id' => $customer->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => 100000, 'tax' => 0, 'total' => 100000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now()]);
    }

    private function service(Closure $start, Closure $verify): PaymentService
    {
        $registry = new GatewayRegistry;
        $registry->register(new InterleavedPaymentGateway($start, $verify));

        return new PaymentService($registry);
    }

    public static function nonPaidResults(): array
    {
        return ['failed' => [false], 'canceled' => [true]];
    }

    #[DataProvider('nonPaidResults')]
    public function test_late_negative_verification_cannot_downgrade_paid_or_credit_again(bool $canceled): void
    {
        $invoice = $this->invoice();
        $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id,
            'gateway' => 'zarinpal', 'currency_code' => 'IRT', 'amount' => 100000,
            'status' => 'redirected', 'expires_at' => now()->addMinutes(10)]);
        $service = $this->service(fn () => StartResult::fail('unused'),
            function (Payment $stale) use (&$service, $canceled): VerifyResult {
                // Another callback commits while this gateway request is in flight.
                $this->assertTrue($service->settleConfirmed($stale->fresh(), 'synthetic-reference')->ok);

                return $canceled ? VerifyResult::canceled() : VerifyResult::fail('temporary verification failure');
            });

        $outcome = $service->settle($payment, []);
        $service->settleConfirmed($payment->fresh(), 'synthetic-reference');

        $this->assertSame(100000, app(Wallet::class)->balanceOf($invoice->customer_id));
        $this->assertSame(1, CreditEntry::where('customer_id', $invoice->customer_id)->count());
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue($outcome->ok);
        $this->assertTrue($outcome->alreadySettled);
        $this->assertSame('synthetic-reference', $outcome->payment->ref_id);
    }

    public static function startResults(): array
    {
        return ['start failure' => [false], 'start redirect' => [true]];
    }

    #[DataProvider('startResults')]
    public function test_late_start_result_cannot_overwrite_a_paid_callback(bool $redirect): void
    {
        $invoice = $this->invoice();
        $service = $this->service(function (Payment $payment) use (&$service, $redirect): StartResult {
            $this->assertTrue($service->settleConfirmed($payment->fresh(), 'synthetic-reference')->ok);

            return $redirect ? StartResult::redirect('https://gateway.invalid/pay', 'synthetic-start') : StartResult::fail('start timeout');
        }, fn () => VerifyResult::fail('unused'));

        $outcome = $service->begin($invoice, 'zarinpal', Request::create('/account/invoices/pay', 'POST'));
        $payment = Payment::firstOrFail();
        $service->settleConfirmed($payment->fresh(), 'synthetic-reference');

        $this->assertSame(100000, app(Wallet::class)->balanceOf($invoice->customer_id));
        $this->assertSame(1, CreditEntry::where('customer_id', $invoice->customer_id)->count());
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue($outcome->ok);
        $this->assertNull($outcome->redirectUrl);
    }

    public function test_late_start_redirect_cannot_reactivate_a_superseded_attempt(): void
    {
        $invoice = $this->invoice();
        $service = $this->service(function (Payment $payment): StartResult {
            Payment::whereKey($payment->id)->update(['status' => 'canceled']);

            return StartResult::redirect('https://gateway.invalid/pay', 'synthetic-start');
        }, fn () => VerifyResult::fail('unused'));

        $outcome = $service->begin($invoice, 'zarinpal', Request::create('/account/invoices/pay', 'POST'));

        $this->assertSame('canceled', Payment::firstOrFail()->status);
        $this->assertFalse($outcome->ok);
        $this->assertNull($outcome->redirectUrl);
        $this->assertSame(0, CreditEntry::count());
    }
}

final class InterleavedPaymentGateway implements PaymentGateway
{
    public function __construct(private Closure $onStart, private Closure $onVerify) {}

    public function key(): string
    {
        return 'zarinpal';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function currency(): string
    {
        return 'IRT';
    }

    public function minimum(): int
    {
        return 1;
    }

    public function start(Payment $payment, string $callbackUrl): StartResult
    {
        return ($this->onStart)($payment);
    }

    public function verify(Payment $payment, array $callback): VerifyResult
    {
        return ($this->onVerify)($payment);
    }
}
