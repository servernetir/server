<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\{CreditEntry, EngineOperation, EngineVm, Invoice, Payment};
use App\Services\Billing\CloudRenewalPrice;
use App\Services\Cloud\Engine\{EngineBillingPolicy, EnginePaidGate, EngineProvider};
use App\Services\Notify\{AdminNotifier, CustomerNotifier, Notifier};
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Mail};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\EngineInventory;
use Tests\TestCase;

class EngineBillingTermsTest extends TestCase
{
    use RefreshDatabase, EngineInventory;

    public static function terms(): array
    {
        return [['monthly', 1000000, '2027-02-28'], ['quarterly', 2850000, '2027-04-30'],
            ['semiannual', 5400000, '2027-07-31'], ['yearly', 9600000, '2028-01-31']];
    }

    #[DataProvider('terms')]
    public function test_settled_term_renewal_and_replay_preserve_exact_amount_and_calendar_months(string $cycle, int $price, string $due): void
    {
        $this->travelTo(now('UTC')->setDate(2027, 1, 31)->setTime(12, 0));
        Http::preventStrayRequests(); Mail::fake();
        $this->mock(Notifier::class)->shouldIgnoreMissing();
        $this->mock(CustomerNotifier::class)->shouldIgnoreMissing();
        $this->mock(AdminNotifier::class)->shouldIgnoreMissing();
        $plan = $this->enginePlan($this->engineNode());
        $service = $this->engineService($plan);
        $quoted = CloudStoreController::priceForCycle($plan, $cycle);
        $this->assertSame($price, $quoted);
        $service->update(['cycle' => $cycle, 'price' => $quoted, 'status' => 'active', 'provision_status' => 'done',
            'activated_at' => now()->subYear(), 'next_due_at' => now()->startOfDay()]);
        $invoice = Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
            'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => $quoted, 'tax' => 0,
            'total' => $quoted, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(), 'due_at' => now()]);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $service->customer_id,
            'gateway' => 'bale', 'currency_code' => 'IRT', 'amount' => $quoted,
            'status' => 'redirected', 'external_ref' => 'term-fixture-'.$cycle]);
        $this->assertTrue(app(PaymentService::class)->settleConfirmed($payment, 'term-ref-'.$cycle)->ok);
        $this->assertTrue(app(PaymentService::class)->settleConfirmed($payment->fresh(), 'term-ref-'.$cycle)->ok);
        $this->assertSame($due, $service->fresh()->next_due_at->toDateString());
        $this->assertSame($price, (int) $invoice->fresh()->paid);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertTrue(app(EnginePaidGate::class)->allows($service->fresh()));
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, DB::table('business_ledger')->where('source_type', Payment::class)->where('source_id', $payment->id)->count());
        $this->assertSame(0, CreditEntry::where('customer_id', $service->customer_id)->count());
        config(['billing.cycles.'.$cycle.'.discount_pct' => 90]);
        $this->assertSame($price, app(CloudRenewalPrice::class)->quote($service->fresh()), 'A later discount must not lower the locked contract.');
        $this->assertSame($price, (int) $invoice->fresh()->total);
        Http::assertNothingSent();
    }

    public function test_integer_quotes_are_exact_above_javascript_integer_precision_and_reject_overflow(): void
    {
        $plan = $this->enginePlan($this->engineNode());
        $plan->price_irt = 9007199254740993;
        $this->assertSame(9007199254750000, app(EngineBillingPolicy::class)->quote($plan, 'monthly'));
        $this->assertSame(0, app(EngineBillingPolicy::class)->quote($plan, 'once'));
        $plan->price_irt = PHP_INT_MAX;
        $this->expectException(\DomainException::class);
        app(EngineBillingPolicy::class)->quote($plan, 'yearly');
    }

    #[DataProvider('terms')]
    public function test_initial_settlement_admits_one_vm_for_the_full_purchased_term(string $cycle, int $price, string $due): void
    {
        $this->travelTo(now('UTC')->setDate(2027, 1, 31)->setTime(12, 0));
        config(['cloud-engine.enabled' => true]); Http::preventStrayRequests(); Mail::fake();
        $this->mock(Notifier::class)->shouldIgnoreMissing();
        $this->mock(CustomerNotifier::class)->shouldIgnoreMissing();
        $this->mock(AdminNotifier::class)->shouldIgnoreMissing();
        $plan = $this->enginePlan($this->engineNode()); $service = $this->engineService($plan);
        $service->update(['cycle' => $cycle, 'price' => $price, 'status' => 'pending']);
        $spec = ['name' => 'sn-svc-'.$service->id, 'labels' => ['snet-service' => $service->id],
            'plan_ref' => $plan->provider_ref, 'image_ref' => 'ubuntu-24.04'];
        $this->assertFalse(app(EngineProvider::class)->createServer($spec)['ok']);
        $this->assertSame(0, EngineVm::count());
        $invoice = Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
            'currency_code' => 'IRT', 'kind' => 'service', 'total' => $price, 'subtotal' => $price, 'paid' => 0, 'status' => 'unpaid']);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $service->customer_id,
            'gateway' => 'bale', 'currency_code' => 'IRT', 'amount' => $price, 'status' => 'redirected', 'external_ref' => 'initial-'.$cycle]);
        $this->assertTrue(app(PaymentService::class)->settleConfirmed($payment, 'initial-ref-'.$cycle)->ok);
        $this->assertTrue(app(PaymentService::class)->settleConfirmed($payment->fresh(), 'initial-ref-'.$cycle)->ok);
        $this->assertSame($due, $service->fresh()->next_due_at->toDateString());
        $created = app(EngineProvider::class)->createServer($spec);
        $this->assertTrue($created['ok']);
        $this->assertSame($created['ref'], app(EngineProvider::class)->createServer($spec)['ref']);
        $this->assertSame(1, EngineVm::count()); $this->assertSame(1, EngineOperation::count());
        $this->assertSame($price, (int) $invoice->fresh()->paid);
        Http::assertNothingSent();
    }

    public function test_unknown_or_one_off_cycles_cannot_provision_even_with_a_paid_invoice(): void
    {
        $service = $this->engineService($this->enginePlan($this->engineNode()));
        foreach (['once', 'external', 'invalid'] as $cycle) {
            $service->cycle = $cycle;
            $this->assertFalse(app(EnginePaidGate::class)->allows($service));
        }
        $this->assertSame(['monthly', 'quarterly', 'semiannual', 'yearly'], app(EngineBillingPolicy::class)->cycles());
    }
}
