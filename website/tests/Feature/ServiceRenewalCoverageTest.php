<?php
namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\{CloudInstance, CloudPlan, Customer, Invoice, Payment, Service};
use App\Services\Billing\ServiceRenewals;
use App\Services\Cloud\{CloudAddons, CloudPricingSnapshot};
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceRenewalCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.internal_service_ids' => []]);
        $this->travelTo(now()->setDate(2026, 11, 14)->startOfDay());
        Http::preventStrayRequests();
        Mail::fake();
        $this->mock(CloudPricingSnapshot::class)->shouldReceive('current')->andReturn(true);
        $this->partialMock(CloudAddons::class)->shouldReceive('extraIpMonthlyToman')->andReturn(100000);
    }

    private function fixture(string $provider = 'hetzner'): array
    {
        $c = Customer::create(['email' => uniqid().'@example.test', 'password' => 'fixture', 'status' => 'active', 'locale' => 'fa']);
        $p = CloudPlan::create(['provider' => $provider, 'provider_ref' => uniqid('plan-'), 'location_code' => 'de-fsn',
            'public_name' => 'Fixture server', 'slug' => uniqid('cloud'), 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'price_irt' => 1000000, 'cost_eur_cents' => 0, 'cost_hour_eur_micro' => 0, 'is_active' => true, 'in_stock' => true]);
        $s = Service::create(['customer_id' => $c->id, 'name' => 'Fixture server', 'cloud_plan_id' => $p->id,
            'cycle' => 'monthly', 'billing_mode' => 'cycle', 'price' => 1100000, 'tax_percent' => 10, 'currency_code' => 'IRT',
            'cloud_addons' => ['extra_ipv4' => 1], 'status' => 'active', 'provision_status' => 'done',
            'activated_at' => '2026-10-30', 'next_due_at' => '2026-11-30']);
        $i = CloudInstance::create(['service_id' => $s->id, 'provider' => $provider, 'provider_ref' => 'instance-1',
            'location_code' => 'de-fsn', 'image_key' => 'ubuntu', 'hostname' => 'fixture', 'status' => 'running',
            'ipv4' => '192.0.2.10', 'meta' => ['vpstc_supplier_due_at' => '2026-11-30']]);
        return [$s, $p, $i];
    }

    public function test_supported_servers_use_checkout_periods_prices_addons_and_tax(): void
    {
        foreach (['hetzner', 'arvan', 'proxmox', 'lightnode', 'salad'] as $provider) {
            [$s, $p] = $this->fixture($provider);
            $options = app(ServiceRenewals::class)->options($s);
            $this->assertSame(CloudStoreController::cycles(), array_keys($options), $provider);
            foreach ($options as $cycle => $q) {
                $this->assertSame(CloudStoreController::priceForCycle($p, $cycle, $s->cloud_addons), $q['price']);
                $this->assertSame((int) round($q['price'] * .1), $q['tax']);
            }
        }
        Http::assertNothingSent();
    }

    public function test_invoice_and_duplicate_payment_extend_original_due_date_only_once(): void
    {
        [$s] = $this->fixture();
        $r = app(ServiceRenewals::class);
        $q = $r->quote($s, 'quarterly');
        $invoice = $r->issue($s, 'quarterly', $q, $r->hash($q));
        $this->assertSame($invoice->id, $r->issue($s, 'quarterly', $q, $r->hash($q))->id);
        $this->assertSame($q['total'], $invoice->total);
        $this->assertSame('2026-11-30', $s->fresh()->next_due_at->toDateString());
        $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $s->customer_id,
            'gateway' => 'credit', 'currency_code' => 'IRT', 'amount' => $invoice->total, 'status' => 'pending']);
        app(PaymentService::class)->settleConfirmed($payment, 'fixture-'.$payment->id);
        app(PaymentService::class)->settleConfirmed($payment->fresh(), 'fixture-'.$payment->id);
        $this->assertSame('2027-02-28', $s->fresh()->next_due_at->toDateString());
        Http::assertNothingSent();
    }

    public function test_native_monthly_provider_rejects_longer_terms_without_issuing_invoice(): void
    {
        [$s] = $this->fixture('vpstc');
        $r = app(ServiceRenewals::class);
        $this->assertSame(['monthly'], array_keys($r->options($s)));
        try { $r->quote($s, 'quarterly'); $this->fail('Unsupported native term'); }
        catch (ValidationException) { $this->assertDatabaseCount('invoices', 0); }
        Http::assertNothingSent();
    }

    public function test_unsupported_suppliers_and_hourly_billing_are_excluded(): void
    {
        foreach (['aeza', 'ovh', 'hetzner-robot'] as $provider) {
            [$s] = $this->fixture($provider);
            $this->assertSame([], app(ServiceRenewals::class)->options($s));
        }
        [$s] = $this->fixture();
        $s->update(['billing_mode' => 'hourly']);
        $this->assertSame([], app(ServiceRenewals::class)->options($s->fresh()));
        Http::assertNothingSent();
    }

    public function test_bad_binding_unknown_addons_and_missing_prices_are_not_sold(): void
    {
        foreach (['provider', 'location', 'deleted', 'reference', 'addons', 'price', 'anchor'] as $bad) {
            [$s, $p, $i] = $this->fixture($bad === 'anchor' ? 'vpstc' : 'hetzner');
            match ($bad) {
                'provider' => $i->update(['provider' => 'arvan']),
                'location' => $i->update(['location_code' => 'ir-thr']),
                'deleted' => $i->update(['status' => 'deleted']),
                'reference' => $i->update(['provider_ref' => '']),
                'addons' => $s->update(['cloud_addons' => ['unknown' => 1]]),
                'price' => $p->update(['price_irt' => 0]),
                'anchor' => $i->update(['meta' => []]),
            };
            $this->assertSame([], app(ServiceRenewals::class)->options($s->fresh()), $bad);
        }
    }

    public function test_binding_changes_after_preview_abort_without_changing_terms(): void
    {
        [$s, $p, $i] = $this->fixture();
        $r = app(ServiceRenewals::class);
        $q = $r->quote($s, 'quarterly');
        $i->update(['provider_ref' => 'replacement-instance']);
        try { $r->issue($s, 'quarterly', $q, $r->hash($q)); $this->fail('Stale allocation'); }
        catch (ValidationException) {
            $this->assertDatabaseCount('invoices', 0);
            $this->assertSame('monthly', $s->fresh()->cycle);
        }
    }

    public function test_stale_and_quarantined_catalog_prices_are_blocked(): void
    {
        [$s, $p] = $this->fixture();
        $this->mock(CloudPricingSnapshot::class)->shouldReceive('current')->andReturn(false);
        $this->assertSame([], app(ServiceRenewals::class)->options($s));
        $this->mock(CloudPricingSnapshot::class)->shouldReceive('current')->andReturn(true);
        \App\Models\Setting::put(CloudPricingSnapshot::KEY, json_encode(['excluded_plans' => [$p->id]]));
        $this->assertSame([], app(ServiceRenewals::class)->options($s));
    }

    public function test_native_monthly_payment_waits_for_supplier_reconciliation(): void
    {
        [$s] = $this->fixture('vpstc');
        $r = app(ServiceRenewals::class);
        $q = $r->quote($s, 'monthly');
        $invoice = $r->issue($s, 'monthly', $q, $r->hash($q));
        $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $s->customer_id,
            'gateway' => 'credit', 'currency_code' => 'IRT', 'amount' => $invoice->total, 'status' => 'pending']);
        app(PaymentService::class)->settleConfirmed($payment, 'fixture-'.$payment->id);
        $this->assertSame('2026-11-30', $s->fresh()->next_due_at->toDateString());
        $this->assertSame($invoice->id, data_get($s->fresh()->provision_meta, 'vpstc_renewal.invoice_id'));
        $this->assertSame([], $r->options($s->fresh()));
        Http::assertNothingSent();
    }

    public function test_server_preview_ownership_locales_and_back_link(): void
    {
        [$s] = $this->fixture();
        [$other] = $this->fixture();
        $url = '/account/services/'.$s->id.'/renew';
        $this->actingAs($other->customer, 'customer')->get($url)->assertNotFound();
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->actingAs($s->customer, 'customer')->get($prefix.$url)->assertOk()
                ->assertSee('quarterly')->assertSee($prefix.'/account/servers', false);
        }
        $this->assertDatabaseCount('invoices', 0);
        Http::assertNothingSent();
    }
}
