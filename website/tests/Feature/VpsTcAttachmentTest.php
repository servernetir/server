<?php

namespace Tests\Feature;

use App\Models\{CloudInstance, CloudLocation, CloudPlan, CloudProviderOperation, Customer, Invoice, Service, Setting, User};
use App\Services\Billing\{ComplimentaryServices, RenewalTerms};
use App\Services\Cloud\{CloudPricingSnapshot, VpsTcApi, VpsTcAttachment, VpsTcCatalog, VpsTcClient};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VpsTcAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private CloudPlan $plan;
    private Customer $customer;
    private array $remote;
    private array $vm;
    private array $quote;
    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 09:00:00');
        Http::preventStrayRequests();
        config(['services.vpstc' => ['base_url' => 'https://portal.vps.tc', 'username' => 'fixture-user', 'password' => 'fixture-api-secret']]);
        $this->customer = Customer::create(['email' => 'existing-vps@example.test', 'password' => 'fixture', 'status' => 'active']);
        CloudLocation::create(['code' => 'tr-test', 'country' => 'TR', 'city' => 'Test', 'is_active' => true]);
        $this->plan = CloudPlan::create(['provider' => 'vpstc', 'provider_ref' => '12', 'provider_location' => 'tr-test',
            'location_code' => 'tr-test', 'slug' => 'attachment-test', 'public_name' => 'CV-1-1', 'vcpu' => 1,
            'ram_mb' => 1024, 'disk_gb' => 20, 'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 100,
            'setup_eur_cents' => 0, 'price_eur_cents' => 140, 'price_irt' => 100000, 'is_active' => true, 'in_stock' => true]);
        Setting::put('vpstc_currency', 'TRY');
        $mapping = ['account_fingerprint' => app(VpsTcApi::class)->accountFingerprint(), 'products' => [
            ['product_id' => '12', 'location_ref' => 'tr-test', 'power_cost_mode' => 'included']]];
        Setting::put('vpstc_catalog_manifest', json_encode($mapping));
        $this->quote = ['product_id' => '12', 'currency' => 'TRY', 'monthly_amount' => '10.00', 'location_ref' => 'tr-test',
            'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20, 'cost_eur_cents' => 100, 'setup_eur_cents' => 0];
        $this->mock(VpsTcCatalog::class, function ($m) use ($mapping) {
            $m->shouldReceive('quote')->with('12')->andReturnUsing(fn () => $this->quote);
            $m->shouldReceive('manifest')->andReturn($mapping);
        });
        $this->mock(CloudPricingSnapshot::class, fn ($m) => $m->shouldReceive('current')->with('vpstc')->andReturn(true));
        $this->remote = ['id' => '83', 'status' => 'Active', 'billingcycle' => 'Monthly', 'name' => 'NVMe Test',
            'domain' => 'original-hostname', 'total' => '10.00', 'date_created' => '2026-10-12', 'next_due' => '2026-11-12'];
        $this->vm = ['id' => '901', 'built' => true, 'locked' => false, 'status' => 'running', 'cores' => 1,
            'memory' => '1024', 'disk' => 20, 'ipv4' => '198.51.100.44', 'username' => 'root',
            'password' => 'private-existing-guest-secret', 'template_name' => 'Debian 13'];
        $this->data = ['provider_ref' => '83', 'expected_ipv4' => '198.51.100.44', 'name' => 'Existing VPS',
            'cycle' => 'monthly', 'price' => 100000, 'activated_at' => '2026-10-12', 'next_due_at' => '2026-11-12'];
        Http::fake([
            '*/api/login' => Http::response(['token' => 'fixture-token']),
            '*/api/service/83' => fn () => Http::response(['service' => $this->remote]),
            '*/api/order/12' => Http::response(['product' => ['id' => '12', 'name' => 'NVMe Test']]),
            '*/api/service/83/vms' => Http::response(['vms' => [['id' => '901']]]),
            '*/api/service/83/vms/901' => fn () => Http::response(['vm' => $this->vm]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function attach(array $overrides = []): Service
    {
        return app(VpsTcAttachment::class)->attach($this->customer, $this->plan, array_replace($this->data, $overrides));
    }

    private function expectRejection(array $overrides = []): void
    {
        try { $this->attach($overrides); $this->fail('Unverified attachment must fail.'); }
        catch (ValidationException) { $this->assertDatabaseCount('services', 0); }
    }

    public function test_read_only_attachment_preserves_real_due_and_encrypted_access_without_faking_payment(): void
    {
        $s = $this->attach(); $i = $s->cloudInstance; $op = CloudProviderOperation::firstOrFail();
        $this->assertSame('2026-11-12', $s->next_due_at->toDateString());
        $this->assertSame(100000, $s->price);
        $this->assertSame(10, $s->tax_percent);
        $this->assertSame('debian-13', $s->cloud_image_key);
        $this->assertSame('2026-11-12', $i->meta['vpstc_supplier_due_at']);
        $this->assertSame('private-existing-guest-secret', $i->password());
        $this->assertStringNotContainsString('private-existing-guest-secret', $s->getRawOriginal('password'));
        $this->assertStringNotContainsString('private-existing-guest-secret', $op->getRawOriginal('payload'));
        $this->assertSame('attach', $op->action);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(0, CloudProviderOperation::where('action', 'create')->count());
        $client = app(VpsTcClient::class);
        $this->assertSame('running', $client->serverStatus('83')['status']);
        $this->assertTrue($client->canPower($i));
        $spec = $op->payload['_spec']; $spec['labels'] = ['snet-service' => (string) $s->id];
        $this->assertSame('running', $client->createServer($spec)['status']);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' && ! str_ends_with($r->url(), '/login'));
    }

    public function test_wrong_ip_dates_price_or_customer_internal_flag_cannot_write_an_attachment(): void
    {
        foreach ([['expected_ipv4' => '198.51.100.45'], ['activated_at' => '2026-10-13'],
            ['next_due_at' => '2026-11-13'], ['price' => 99999], ['cycle' => 'yearly'],
            ['internal_infrastructure' => true, 'price' => 0]] as $bad) { $this->expectRejection($bad); }
        $this->assertDatabaseCount('cloud_instances', 0);
        $this->assertDatabaseCount('cloud_provider_operations', 0);
    }

    public function test_mismatched_native_resources_cancellation_or_masked_password_cannot_be_adopted(): void
    {
        $this->vm['memory'] = 2048; $this->expectRejection(); $this->vm['memory'] = 1024;
        $this->vm['password'] = '********'; $this->expectRejection(); $this->vm['password'] = 'fixture-secret';
        $this->remote['cancellation_request'] = ['status' => 'pending']; $this->expectRejection();
        unset($this->remote['cancellation_request']);
        $this->quote['cost_eur_cents'] = 101; $this->expectRejection();
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' && ! str_ends_with($r->url(), '/login'));
    }

    public function test_second_attachment_cannot_create_a_second_service_for_the_same_guest(): void
    {
        $this->attach();
        try { $this->attach(); $this->fail('Duplicate import must fail.'); } catch (ValidationException) {}
        $this->assertDatabaseCount('services', 1);
        $this->assertDatabaseCount('cloud_instances', 1);
        $this->assertDatabaseCount('cloud_provider_operations', 1);
    }

    public function test_changing_the_local_owner_invalidates_management_receipt(): void
    {
        $s = $this->attach();
        $other = Customer::create(['email' => 'other-owner@example.test', 'password' => 'fixture']);
        $s->update(['customer_id' => $other->id]);
        $client = app(VpsTcClient::class);
        $this->assertFalse($client->serverStatus('83')['ok']);
        $this->assertFalse($client->canPower($s->cloudInstance->fresh()));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' && ! str_ends_with($r->url(), '/login'));
    }

    public function test_only_the_bound_owner_can_mark_an_internal_relay_exempt_and_protected(): void
    {
        config(['billing.complimentary_services' => ['customer_id' => $this->customer->id, 'customer_code' => $this->customer->code]]);
        $s = $this->attach(['internal_infrastructure' => true, 'price' => 0]);
        $this->assertTrue($s->isInternalInfrastructure());
        $this->assertSame(0, $s->price); $this->assertSame(0, $s->tax_percent);
        $this->assertFalse(Service::customerRecurring()->whereKey($s->id)->exists());
        $this->assertNull(RenewalTerms::ensureInvoice($s));
        $this->actingAs($this->customer, 'customer')->post('/account/services/'.$s->id.'/terminate/start')
            ->assertSessionHasErrors()->assertSessionMissing('svc_terminate_ctx');
        $this->assertFalse(app(ComplimentaryServices::class)->renew($s));
        $this->assertDatabaseCount('invoices', 0);
        config(['billing.complimentary_services.customer_code' => 'different-code']);
        $this->assertFalse($s->isInternalInfrastructure());
        $this->assertTrue(Service::customerRecurring()->whereKey($s->id)->exists());
    }

    public function test_admin_form_connects_the_existing_paid_guest_but_customer_cannot_submit_it(): void
    {
        $payload = $this->data + ['customer_id' => $this->customer->id, 'cloud_plan_id' => $this->plan->id];
        $this->actingAs($this->customer, 'customer')->post('/admin/cloud/attach', $payload)->assertRedirect();
        $this->assertDatabaseCount('services', 0);
        $admin = User::create(['name' => 'Fixture administrator', 'email' => 'admin-fixture@example.test', 'password' => 'fixture', 'role' => 'admin']);
        $this->actingAs($admin, 'web')->get('/admin/cloud/attach?customer_id='.$this->customer->id)
            ->assertOk()->assertSee('name="expected_ipv4"', false);
        $this->actingAs($admin, 'web')->post('/admin/cloud/attach', $payload)
            ->assertRedirect('/admin/customers/'.$this->customer->id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('services', 1);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(100000, Service::firstOrFail()->price);
    }

    public function test_internal_flag_without_the_exact_owner_marker_does_not_hide_a_recurring_service(): void
    {
        config(['billing.complimentary_services' => ['customer_id' => $this->customer->id, 'customer_code' => $this->customer->code]]);
        $s = $this->attach();
        $s->update(['provision_meta' => ['internal_infrastructure' => true]]);
        $this->assertFalse($s->fresh()->isInternalInfrastructure());
        $this->assertTrue(Service::customerRecurring()->whereKey($s->id)->exists());
    }
}
