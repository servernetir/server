<?php
namespace Tests\Feature;

use App\Models\{Customer, CustomerProfile, DomainSupplierAccount, DomainSupplierContactRequest};
use App\Services\Domain\{DomainRegistrationReadiness, DomainSupplierContactProvisioner};
use App\Services\Domain\Providers\{DomainProvider, DomainProviderFactory, DomainProviderException, JokerAdapter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class DomainSupplierContactProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        config(['domain_suppliers.enabled' => true, 'domain_suppliers.contact_provisioning_enabled' => true,
            'services.openprovider.registrant' => []]);
        $c = Customer::create(['email' => 'synthetic-contact@example.test', 'password' => bcrypt('test'), 'status' => 'active']);
        $profile = CustomerProfile::create(['customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'first_name' => 'Synthetic', 'last_name' => 'Contact', 'email' => $c->email, 'country' => 'US',
            'address' => 'Test Street 12', 'city' => 'Test City', 'postal_code' => '12345', 'mobile' => '+12025550100']);
        $a = DomainSupplierAccount::create(['provider' => 'joker', 'label' => 'Synthetic account', 'environment' => 'production',
            'active' => true, 'sales_enabled' => true, 'contract_verified' => true, 'identity_verified' => true,
            'pricing_verified' => true, 'connection_status' => 'healthy', 'health_checked_at' => now(),
            'balance_minor' => 100000, 'balance_currency' => 'USD', 'credentials' => ['api_key' => 'synthetic-key'],
            'capabilities' => ['tlds' => ['com' => ['contact_creation' => 'standard'], 'co.uk' => ['contact_creation' => 'standard']]]]);
        $p = \Mockery::mock(DomainProvider::class); $f = \Mockery::mock(DomainProviderFactory::class);
        $f->shouldReceive('forAccount')->withArgs(fn ($candidate) => $candidate->id === $a->id)->andReturn($p);
        $this->app->instance(DomainProviderFactory::class, $f);
        return [$c, $profile, $a, $p, app(DomainSupplierContactProvisioner::class)];
    }

    public function test_customer_checkout_queues_contact_before_money_then_reconciles_once(): void
    {
        [$c, , $a, $p, $service] = $this->fixture();
        $p->shouldReceive('createContact')->once()->withArgs(fn ($tld, $payload, $tx) => $tld === 'com'
            && $payload['email'] === $c->email && str_starts_with($tx, 'sn-contact-'))
            ->andReturn(['status' => 'queued', 'process_id' => 'synthetic-process']);
        $this->assertFalse(app(DomainRegistrationReadiness::class)->ready($c, $a->id, 'com'));
        $request = DomainSupplierContactRequest::firstOrFail();
        $this->assertSame('queued', $request->status); $this->assertSame(1, $request->attempts);
        $this->assertDatabaseCount('invoices', 0); $this->assertDatabaseCount('payments', 0);
        $this->assertStringNotContainsString($c->email, DB::table('domain_supplier_contact_requests')->value('payload'));
        $p->shouldReceive('reconcileContact')->once()->withArgs(fn ($context) => $context['process_id'] === 'synthetic-process'
            && $context['tld'] === 'com')->andReturn(['status' => 'succeeded', 'handle' => 'CCOM-SYNTHETIC']);
        $this->assertTrue($service->ensure($c, $a, 'com'));
        $this->assertTrue($service->ensure($c, $a, 'com'));
        $this->assertSame('CCOM-SYNTHETIC', $service->current($c, $a, 'com')->handle);
        $this->assertNull($request->fresh()->active_key);
        $this->assertSame(1, $request->fresh()->attempts);
        $this->assertArrayNotHasKey('handle', $request->fresh()->toArray());
        Http::assertNothingSent();
    }

    public function test_timeout_stays_unknown_and_never_resubmits_even_after_profile_changes(): void
    {
        [$c, $profile, $a, $p, $service] = $this->fixture();
        $p->shouldReceive('createContact')->once()->andThrow(new \RuntimeException('synthetic private transport'));
        $this->assertFalse($service->ensure($c, $a, 'com'));
        $p->shouldReceive('reconcileContact')->once()->andReturn(['status' => 'unknown']);
        $this->assertFalse($service->ensure($c, $a, 'com'));
        $profile->update(['address' => 'Different Test Street 13']);
        $this->assertFalse($service->ensure($c->fresh(), $a, 'com'));
        $this->assertDatabaseCount('domain_supplier_contact_requests', 1);
        $this->assertSame(1, DomainSupplierContactRequest::first()->attempts);
    }

    public function test_missing_real_profile_fields_or_unverified_capability_never_creates_contact(): void
    {
        [$c, $profile, $a, $p, $service] = $this->fixture();
        $p->shouldNotReceive('createContact');
        $profile->update(['postal_code' => null]);
        $this->assertFalse($service->ensure($c, $a, 'com'));
        $profile->update(['postal_code' => '12345']);
        $this->assertFalse($service->ensure($c, $a, 'unverified'));
        $this->assertDatabaseCount('domain_supplier_contact_requests', 0);
    }

    public function test_binding_cannot_be_reused_after_account_credentials_change(): void
    {
        [$c, , $a, $p, $service] = $this->fixture();
        $p->shouldReceive('createContact')->once()->andReturn(['status' => 'succeeded', 'handle' => 'CCOM-SYNTHETIC']);
        $this->assertTrue($service->ensure($c, $a, 'com'));
        $a->update(['credentials' => ['api_key' => 'different-synthetic-key']]);
        $this->assertNull($service->current($c, $a, 'com'));
        $this->assertFalse($service->ensure($c, $a, 'com'));
    }

    public function test_failed_creation_is_terminal_but_reconciliation_runs_when_sales_disabled(): void
    {
        [$c, , $a, $p, $service] = $this->fixture();
        $p->shouldReceive('createContact')->once()->andReturn(['status' => 'queued', 'process_id' => 'p-test']);
        $service->ensure($c, $a, 'com');
        $a->update(['sales_enabled' => false]); config(['domain_suppliers.contact_provisioning_enabled' => false]);
        $p->shouldReceive('reconcileContact')->once()->andReturn(['status' => 'succeeded', 'handle' => 'CCOM-SYNTHETIC']);
        $service->reconcile(DomainSupplierContactRequest::firstOrFail());
        $this->assertSame('succeeded', DomainSupplierContactRequest::first()->status);
    }

    public function test_joker_contact_creation_is_not_delivered_from_ack_and_uses_documented_phone(): void
    {
        $a = new DomainSupplierAccount(['provider' => 'joker', 'environment' => 'ote', 'credentials' => ['api_key' => 'synthetic']]);
        Http::preventStrayRequests();
        Http::fake(['*/login' => Http::response("Status-Code: 0\nAuth-SID: test-session\n\ncom\n"),
            '*/contact-create' => Http::response("Status-Code: 0\nProc-Id: p-test\nTracking-Id: t-test\nResult: ACK\n\n")]);
        $payload = ['name' => ['first_name' => 'Synthetic', 'last_name' => 'Contact'], 'email' => 'synthetic@example.test',
            'address' => ['street' => 'Test Street 12', 'city' => 'Test City', 'zipcode' => '12345', 'country' => 'US'],
            'phone' => ['country_code' => '+1', 'area_code' => '202', 'subscriber_number' => '5550100']];
        $r = (new JokerAdapter($a))->createContact('com', $payload, 'sn-contact-1');
        $this->assertSame('queued', $r['status']); $this->assertNull($r['handle']);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/contact-create') && $req['phone'] === '+1.2025550100'
            && $req->method() === 'POST' && ! str_contains($req->url(), 'synthetic@example.test'));
    }

    public function test_joker_contact_result_requires_exact_zero_cost_process_and_tld_portfolio_handle(): void
    {
        Http::preventStrayRequests();
        $a = new DomainSupplierAccount(['provider' => 'joker', 'environment' => 'ote', 'credentials' => ['api_key' => 'synthetic']]);
        $columns = 'order_id,proc_id,created_at,status,type,amount_brutto,amount_netto,currency,identifier';
        Http::fake(['*/login' => Http::response("Status-Code: 0\nAuth-SID: test-session\n\ncom\n"),
            '*/query-order-list*' => Http::response("Status-Code: 1000\nColumns: $columns\n\n1\tp-test\t2026-11-02T12:00:00Z\tok\tcreate-contact\t0.00\t0.00\t\tCCOM-SYNTHETIC\n"),
            '*/query-contact-list' => Http::response("Status-Code: 1000\n\nCCOM-SYNTHETIC\n")]);
        $r = (new JokerAdapter($a))->reconcileContact(['process_id' => 'p-test', 'tld' => 'com']);
        $this->assertSame(['status' => 'succeeded', 'handle' => 'CCOM-SYNTHETIC'], $r);
        $this->assertSame('unknown', (new JokerAdapter($a))->reconcileContact(['process_id' => 'other-process', 'tld' => 'com'])['status']);
    }
}
