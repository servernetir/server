<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\CloudProviderOperation;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\Cloud\VpsTcCatalog;
use App\Services\Cloud\VpsTcRenewal;
use App\Services\Cloud\VpsTcPricing;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class VpsTcRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-26 10:00:00');
        Http::preventStrayRequests();
        Mail::fake();
        NavasanRates::store();
        config(['services.vpstc' => ['base_url' => 'https://portal.vps.tc', 'username' => 'fixture-user', 'password' => 'fixture-password']]);
        foreach (['vpstc_currency' => 'TRY', 'cloud_margin_pct_vpstc' => '20', 'vpstc_supplier_tax_pct' => '20',
            'pricing_fx_fee_pct_vpstc' => '0', 'vpstc_fx_rate_toman' => '5000'] as $key => $value) {
            Setting::put($key, $value);
        }
        $snapshot = app(CloudPricingSnapshot::class);
        $snapshot->publish($snapshot->rates(), [], ['vpstc' => app(VpsTcPricing::class)->fingerprint()]);
        $this->mock(VpsTcCatalog::class)->shouldReceive('quote')->andReturn([
            'product_id' => '9', 'currency' => 'TRY', 'monthly_amount' => '100.00',
            'setup_amount' => '0.00', 'total_amount' => '120.00', 'renewal_total_amount' => '120.00',
            'renewal_mode' => 'manual_invoice_deduplicated',
            'cost_eur_cents' => 300, 'setup_eur_cents' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(array $attrs = []): array
    {
        $customer = Customer::create(['email' => uniqid('renew').'@example.test', 'password' => 'x', 'status' => 'active', 'locale' => 'fa']);
        $plan = CloudPlan::create([
            'provider' => 'vpstc', 'provider_ref' => '9', 'provider_location' => 'tr-istanbul', 'location_code' => 'tr-istanbul',
            'public_name' => 'CV-2-4', 'slug' => uniqid('monthly'), 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'cost_eur_cents' => 300, 'price_eur_cents' => 360, 'price_irt' => 720000, 'is_active' => true, 'in_stock' => true,
        ]);
        $service = Service::create(array_replace([
            'customer_id' => $customer->id, 'name' => 'Monthly server', 'price' => 720000, 'tax_percent' => 0,
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'billing_mode' => 'cycle', 'status' => 'active',
            'cloud_plan_id' => $plan->id, 'provision_status' => 'done', 'activated_at' => '2026-09-30', 'next_due_at' => '2026-10-30',
        ], $attrs));
        CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'vpstc', 'provider_ref' => '301', 'location_code' => 'tr-istanbul',
            'image_key' => 'ubuntu-24.04', 'hostname' => 'sn-svc-'.$service->id, 'status' => 'running', 'ipv4' => '192.0.2.10',
            'meta' => ['vpstc_supplier_due_at' => '2026-10-30'],
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'service_id' => $service->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 720000, 'tax' => 0, 'total' => 720000, 'paid' => 720000, 'status' => 'paid', 'paid_at' => now(), 'issued_at' => now(),
        ]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'title' => 'Monthly renewal', 'quantity' => 1,
            'unit_price' => 720000, 'line_total' => 720000, 'tax_amount' => 0,
            'marketing_context' => ['operation' => 'renewal', 'provider' => 'vpstc']]);
        return [$service, $invoice];
    }

    private function bill(string $status = 'Paid', string $sid = '301', string $total = '120.00'): array
    {
        return ['invoice' => ['id' => '777', 'currency' => 'TRY', 'status' => $status, 'total' => $total,
            'items' => [['type' => 'Hosting', 'item_id' => $sid, 'qty' => '1.00', 'linetotal' => '100.00']]]];
    }

    private function supplier(array $overrides = [], bool $advanced = true): void
    {
        $service = fn ($due) => ['service' => ['id' => '301', 'total' => '100.00', 'billingcycle' => 'Monthly', 'next_due' => $due, 'status' => 'Active']];
        Http::fake($overrides + [
            'https://portal.vps.tc/api/login' => Http::response(['token' => 'fixture-token']),
            'https://portal.vps.tc/api/service/301' => Http::sequence()->push($service('2026-10-30'))->push($service($advanced ? '2026-11-30' : '2026-10-30')),
            'https://portal.vps.tc/api/balance' => Http::response(['details' => ['currency' => 'TRY', 'acc_credit' => '1000.00', 'acc_balance' => '0.00']]),
            'https://portal.vps.tc/api/service/301/renew' => Http::response(['status' => true, 'invoice_id' => '777']),
            'https://portal.vps.tc/api/invoice/777' => Http::response($this->bill()),
        ]);
    }

    public function test_queue_makes_no_supplier_request_and_preserves_local_period(): void
    {
        [$service, $invoice] = $this->invoice();
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        $renewal->queue($service, $invoice);
        $this->assertSame('2026-10-30', $service->fresh()->next_due_at->toDateString());
        $this->assertTrue($renewal->pending($service->fresh()));
        $this->assertSame(1, CloudProviderOperation::where('action', 'renew')->count());
        Http::assertNothingSent();
    }

    public function test_paid_invoice_and_exact_supplier_period_confirmation_extend_once(): void
    {
        [$service, $invoice] = $this->invoice();
        $this->supplier();
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        $this->assertTrue($renewal->process($invoice));
        $this->assertTrue($renewal->process($invoice));
        $this->assertSame('2026-11-30', $service->fresh()->next_due_at->toDateString());
        $this->assertFalse($renewal->pending($service->fresh()));
        $this->assertSame('done', CloudProviderOperation::where('action', 'renew')->sole()->state);
        Http::assertSentCount(6);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/service/301/renew'));
    }

    public function test_acknowledgement_without_due_advance_does_not_extend_local_period(): void
    {
        [$service, $invoice] = $this->invoice();
        $this->supplier(advanced: false);
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        $this->assertFalse($renewal->process($invoice));
        $this->assertSame('2026-10-30', $service->fresh()->next_due_at->toDateString());
        $this->assertTrue($renewal->pending($service->fresh()));
        $this->assertSame('supplier_period_not_confirmed', CloudProviderOperation::where('action', 'renew')->sole()->payload['last_issue']);
    }

    public function test_lost_renew_response_never_submits_a_second_renewal(): void
    {
        [$service, $invoice] = $this->invoice();
        $attempts = 0;
        $this->supplier(['https://portal.vps.tc/api/service/301/renew' => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('private-token');
        }]);
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        $this->assertFalse($renewal->process($invoice));
        $this->assertFalse($renewal->process($invoice));
        $this->assertSame('unknown', CloudProviderOperation::where('action', 'renew')->sole()->state);
        $this->assertSame('2026-10-30', $service->fresh()->next_due_at->toDateString());
        $this->assertSame(1, $attempts);
        Http::assertSentCount(3);
    }

    public function test_foreign_service_invoice_is_never_paid_or_used_as_renewal_proof(): void
    {
        [$service, $invoice] = $this->invoice();
        $this->supplier(['https://portal.vps.tc/api/invoice/777' => Http::response($this->bill('Unpaid', '302'))]);
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        $this->assertFalse($renewal->process($invoice));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/credit'));
        $this->assertSame('2026-10-30', $service->fresh()->next_due_at->toDateString());
    }

    public function test_exact_invoice_credit_is_applied_once_then_read_back(): void
    {
        [$service, $invoice] = $this->invoice();
        $this->supplier([
            'https://portal.vps.tc/api/invoice/777' => Http::sequence()->push($this->bill('Unpaid'))->push($this->bill()),
            'https://portal.vps.tc/api/invoice/777/credit' => Http::response(['status' => true]),
        ]);
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        $this->assertTrue($renewal->process($invoice));
        $this->assertTrue($renewal->process($invoice));
        Http::assertSentCount(8);
    }

    public function test_insufficient_credit_blocks_renew_before_financial_request(): void
    {
        [$service, $invoice] = $this->invoice();
        $this->supplier(['https://portal.vps.tc/api/balance' => Http::response(['details' => ['currency' => 'TRY', 'acc_credit' => '4.34']])]);
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        $this->assertFalse($renewal->process($invoice));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/renew'));
        $this->assertSame('supplier_credit_required', CloudProviderOperation::where('action', 'renew')->sole()->payload['last_issue']);
    }

    public function test_nonmonthly_retail_cycle_and_unpaid_invoice_cannot_spend(): void
    {
        [$service, $invoice] = $this->invoice(['cycle' => 'annually']);
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        $this->assertFalse($renewal->process($invoice));
        $invoice->update(['status' => 'unpaid', 'paid' => 0]);
        $this->assertFalse($renewal->process($invoice));
        Http::assertNothingSent();
    }

    public function test_payment_hook_does_not_claim_renewal_before_supplier_confirmation(): void
    {
        [$service, $invoice] = $this->invoice();
        $invoice->update(['status' => 'unpaid', 'paid' => 0]);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $service->customer_id,
            'gateway' => 'bale', 'currency_code' => 'IRT', 'amount' => 720000, 'status' => 'redirected']);
        $this->supplier(advanced: false);
        app(PaymentService::class)->settleConfirmed($payment, 'fixture-ref');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('2026-10-30', $service->fresh()->next_due_at->toDateString());
        $this->assertTrue(app(VpsTcRenewal::class)->pending($service->fresh()));
        $this->assertSame(1, CloudProviderOperation::where('action', 'renew')->count());
    }

    public function test_auto_renewed_supplier_period_never_buys_an_additional_month(): void
    {
        [$service, $invoice] = $this->invoice();
        $this->supplier(['https://portal.vps.tc/api/service/301' => Http::response(['service' => [
            'id' => '301', 'total' => '100.00', 'billingcycle' => 'Monthly', 'next_due' => '2026-11-30', 'status' => 'Active',
        ]])]);
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        $this->assertFalse($renewal->process($invoice));
        $this->assertSame('supplier_period_changed', CloudProviderOperation::where('action', 'renew')->sole()->payload['last_issue']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/renew'));
        $this->assertSame('2026-10-30', $service->fresh()->next_due_at->toDateString());
    }

    public function test_second_paid_invoice_does_not_hide_or_deadlock_the_first_pending_period(): void
    {
        [$service, $invoice] = $this->invoice();
        $second = $invoice->replicate(['number']);
        $second->save();
        $item = $invoice->items()->sole()->replicate();
        $item->invoice_id = $second->id;
        $item->save();
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        $renewal->queue($service->fresh(), $second);
        $this->assertSame($invoice->id, data_get($service->fresh()->provision_meta, 'vpstc_renewal.invoice_id'));
        $this->supplier();
        $this->assertTrue($renewal->process($invoice));
        $this->assertFalse($renewal->process($second));
        $this->assertSame('2026-11-30', $service->fresh()->next_due_at->toDateString());
        Http::assertSentCount(6);
    }

    public function test_ancillary_invoice_linked_to_a_server_does_not_buy_a_renewal(): void
    {
        [$service, $invoice] = $this->invoice();
        $invoice->items()->update(['marketing_context' => null]);
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        $this->assertFalse($renewal->process($invoice));
        $this->assertSame(0, CloudProviderOperation::where('action', 'renew')->count());
        $this->assertSame('2026-10-30', $service->fresh()->next_due_at->toDateString());
        Http::assertNothingSent();
    }

    public function test_account_change_or_parent_cancellation_blocks_any_renewal_request(): void
    {
        [$service, $invoice] = $this->invoice();
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        config(['services.vpstc.username' => 'another-account']);
        $this->assertFalse($renewal->process($invoice));
        config(['services.vpstc.username' => 'fixture-user']);
        CloudProviderOperation::create(['operation_key' => hash('sha256', 'cancel-test'), 'provider' => 'vpstc',
            'action' => 'cancel', 'resource_ref' => '301', 'state' => 'submitting', 'payload' => []]);
        $this->assertFalse($renewal->process($invoice));
        Http::assertNothingSent();
    }

    public function test_outer_payment_rollback_never_reaches_supplier(): void
    {
        [$service, $invoice] = $this->invoice();
        $invoice->update(['status' => 'unpaid', 'paid' => 0]);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $service->customer_id,
            'gateway' => 'credit', 'currency_code' => 'IRT', 'amount' => 720000, 'status' => 'redirected']);
        try {
            DB::transaction(function () use ($payment) {
                app(PaymentService::class)->settleConfirmed($payment, 'fixture-rollback');
                Http::assertNothingSent();
                throw new \RuntimeException('Rollback the caller transaction');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Rollback the caller transaction', $error->getMessage());
        }
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(0, CloudProviderOperation::where('action', 'renew')->count());
        $this->assertSame('2026-10-30', $service->fresh()->next_due_at->toDateString());
        Http::assertNothingSent();
    }

    public function test_existing_lifecycle_job_reconciles_paid_pending_period(): void
    {
        [$service, $invoice] = $this->invoice();
        app(VpsTcRenewal::class)->queue($service, $invoice);
        $this->artisan('services:lifecycle', ['--dry' => true])->assertSuccessful();
        Http::assertNothingSent();
        $this->supplier();
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->assertSame('2026-11-30', $service->fresh()->next_due_at->toDateString());
        $this->assertFalse(app(VpsTcRenewal::class)->pending($service->fresh()));
    }

    public function test_complimentary_policy_cannot_extend_a_prepaid_supplier_only_locally(): void
    {
        [$service] = $this->invoice();
        config(['billing.complimentary_services' => ['customer_id' => $service->customer_id,
            'customer_code' => $service->customer->code]]);
        try {
            app(\App\Services\Billing\ComplimentaryServices::class)->renew($service);
            $this->fail('The prepaid supplier renewal requires explicit financial review.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('renewal', $error->errors());
        }
        $this->assertSame('2026-10-30', $service->fresh()->next_due_at->toDateString());
        $this->assertSame(720000, (int) $service->fresh()->price);
        Http::assertNothingSent();
    }

    public function test_missing_verified_renewal_policy_never_submits_financial_request(): void
    {
        [$service, $invoice] = $this->invoice();
        $this->mock(VpsTcCatalog::class)->shouldReceive('quote')->andReturn([
            'monthly_amount' => '100.00', 'currency' => 'TRY', 'cost_eur_cents' => 300,
        ]);
        $this->supplier();
        $renewal = app(VpsTcRenewal::class);
        $renewal->queue($service, $invoice);
        $this->assertFalse($renewal->process($invoice));
        $this->assertSame('supplier_renewal_contract_not_verified', CloudProviderOperation::where('action', 'renew')->sole()->payload['last_issue']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/renew'));
    }
}
