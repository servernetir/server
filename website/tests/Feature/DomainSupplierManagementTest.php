<?php
namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\DomainSupplierContact;
use App\Models\Domain;
use App\Models\DomainSupplierAccount;
use App\Models\DomainSupplierOperation;
use App\Services\Domain\DomainManagement;
use App\Services\Domain\DomainSupplierLifecycle;
use App\Services\Domain\Providers\DomainProvider;
use App\Services\Domain\Providers\DomainProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainSupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['domain_suppliers.enabled' => true]);
        Http::preventStrayRequests(); Http::fake();
        $customer = Customer::create(['email' => 'management@example.test', 'password' => bcrypt('synthetic'), 'status' => 'active']);
        $account = DomainSupplierAccount::create(['provider' => 'joker', 'environment' => 'production', 'label' => 'Synthetic holder',
            'active' => true, 'sales_enabled' => false, 'credentials' => ['api_key' => 'synthetic-only']]);
        $domain = Domain::create(['customer_id' => $customer->id, 'domain' => 'management.example', 'sld' => 'management',
            'tld' => 'example', 'registrar' => 'joker', 'supplier_account_id' => $account->id,
            'supplier_object_id' => 'management.example', 'status' => 'active', 'provision_status' => 'done',
            'is_locked' => false, 'expires_at' => now()->addYear()]);
        $provider = \Mockery::mock(DomainProvider::class);
        $factory = \Mockery::mock(DomainProviderFactory::class);
        $factory->shouldReceive('forAccount')->withArgs(fn ($candidate) => $candidate->id === $account->id)->andReturn($provider);
        $this->app->instance(DomainProviderFactory::class, $factory);
        return [$domain, $account, $provider, app(DomainManagement::class)];
    }

    private function found($provider): void
    {
        $provider->shouldReceive('find')->once()->with('management.example')->andReturn([
            'found' => true, 'object_id' => 'management.example', 'expires_at' => '2027-10-31']);
    }

    public function test_queue_is_durable_and_duplicate_only_reconciles_the_same_process(): void
    {
        [$domain, $account, $provider, $management] = $this->fixture();
        $this->found($provider);
        $provider->shouldReceive('manage')->once()->with('management.example', 'management.example', 'lock', ['locked' => true])
            ->andReturn(['ok' => false, 'status' => 'queued', 'process_id' => 'p-123', 'tracking_id' => 't-123']);
        $provider->shouldReceive('reconcile')->once()->withArgs(fn ($row) => $row['process_id'] === 'p-123'
            && $row['tracking_id'] === 't-123' && $row['operation'] === 'manage_lock' && $row['request_context']['payload']['locked'] === true)
            ->andReturn(['status' => 'queued']);
        $this->assertSame('queued', $management->manage($domain, 'lock', ['locked' => true])['status']);
        $this->assertFalse($domain->fresh()->is_locked);
        $this->assertSame('queued', $management->manage($domain, 'lock', ['locked' => true])['status']);
        $op = DomainSupplierOperation::firstOrFail();
        $this->assertSame($account->id, $op->supplier_account_id);
        $this->assertNull($op->invoice_id);
        $this->assertSame(0, $op->period_months);
        $this->assertSame(1, $op->attempts);
        $this->assertDatabaseCount('domain_supplier_operations', 1);
        Http::assertNothingSent();
    }

    public function test_timeout_is_unknown_and_is_never_resubmitted_or_failed_over(): void
    {
        [$domain, $account, $provider, $management] = $this->fixture();
        $this->found($provider);
        $provider->shouldReceive('manage')->once()->andThrow(new \RuntimeException('synthetic timeout with private data'));
        $provider->shouldReceive('reconcile')->once()->withArgs(fn ($row) => $row['process_id'] === null && $row['tracking_id'] === null)
            ->andReturn(['status' => 'unknown']);
        $this->assertSame('unknown', $management->manage($domain, 'lock', ['locked' => true])['status']);
        $this->assertSame('unknown', $management->manage($domain, 'lock', ['locked' => true])['status']);
        $this->assertFalse($domain->fresh()->is_locked);
        $this->assertSame(1, DomainSupplierOperation::first()->attempts);
        $this->assertDatabaseCount('domain_supplier_operations', 1);
    }

    public function test_only_confirmed_completion_updates_nameservers_and_a_duplicate_success_is_not_resent(): void
    {
        [$domain, $account, $provider, $management] = $this->fixture();
        $this->found($provider);
        $names = ['ns2.example.test', 'ns1.example.test'];
        $provider->shouldReceive('manage')->once()->with('management.example', 'management.example', 'nameservers',
            ['nameservers' => ['ns1.example.test', 'ns2.example.test']])->andReturn(['status' => 'queued', 'process_id' => 'p-ns']);
        $provider->shouldReceive('reconcile')->twice()->andReturn(['status' => 'succeeded', 'object_id' => 'management.example']);
        $management->manage($domain, 'nameservers', ['nameservers' => $names]);
        $this->assertNull($domain->fresh()->name_servers);
        $this->assertTrue($management->reconcile(DomainSupplierOperation::firstOrFail())['ok']);
        $this->assertSame(['ns1.example.test', 'ns2.example.test'], $domain->fresh()->name_servers);
        $this->assertTrue($management->manage($domain, 'nameservers', ['nameservers' => $names])['ok']);
        $this->assertDatabaseCount('domain_supplier_operations', 1);
        $this->assertDatabaseCount('invoices', 0); $this->assertDatabaseCount('payments', 0);
    }

    public function test_changed_account_never_applies_an_old_operation_to_the_new_holder(): void
    {
        [$domain, $account, $provider, $management] = $this->fixture();
        $this->found($provider);
        $provider->shouldReceive('manage')->once()->andReturn(['status' => 'queued', 'process_id' => 'p-lock']);
        $provider->shouldNotReceive('reconcile');
        $management->manage($domain, 'lock', ['locked' => true]);
        $other = DomainSupplierAccount::create(['provider' => 'joker', 'environment' => 'production', 'label' => 'Other synthetic', 'active' => true]);
        $domain->forceFill(['supplier_account_id' => $other->id])->save();
        $this->assertSame('manual_review', $management->reconcile(DomainSupplierOperation::firstOrFail())['status']);
        $this->assertFalse($domain->fresh()->is_locked);
    }

    public function test_disabling_sales_stops_writes_but_pending_reconciliation_can_finish(): void
    {
        [$domain, $account, $provider, $management] = $this->fixture();
        $this->found($provider);
        $provider->shouldReceive('manage')->once()->andReturn(['status' => 'queued', 'process_id' => 'p-disabled']);
        $provider->shouldReceive('reconcile')->once()->andReturn(['status' => 'succeeded', 'object_id' => 'management.example']);
        $management->manage($domain, 'lock', ['locked' => true]);
        config(['domain_suppliers.enabled' => false]);
        $this->assertFalse($management->manage($domain, 'lock', ['locked' => false])['ok']);
        $this->assertTrue($management->reconcile(DomainSupplierOperation::firstOrFail())['ok']);
        $this->assertTrue($domain->fresh()->is_locked);
    }

    public function test_the_financial_domain_lock_also_blocks_management(): void
    {
        [$domain, $account, $provider, $management] = $this->fixture();
        $provider->shouldNotReceive('find'); $provider->shouldNotReceive('manage');
        $lock = Cache::lock(DomainSupplierLifecycle::domainLockKey($domain->domain), 180);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame('queued', $management->manage($domain, 'lock', ['locked' => true])['status']);
            $this->assertDatabaseCount('domain_supplier_operations', 0);
        } finally { $lock->release(); }
    }

    public function test_unknown_financial_work_blocks_management_and_unbound_authcode_is_never_generated(): void
    {
        [$domain, $account, $provider, $management] = $this->fixture();
        DomainSupplierOperation::create(['domain_id' => $domain->id, 'supplier_account_id' => $account->id,
            'operation' => 'renew', 'idempotency_key' => 'synthetic-renewal', 'fqdn' => $domain->domain,
            'period_months' => 12, 'status' => 'unknown', 'request_hash' => str_repeat('a', 64)]);
        $provider->shouldNotReceive('find'); $provider->shouldNotReceive('manage');
        $this->assertSame('queued', $management->manage($domain, 'lock', ['locked' => true])['status']);
        $this->assertSame('unsupported_operation', $management->manage($domain, 'auth_code')['error_code']);
        $this->assertDatabaseCount('domain_supplier_operations', 1);
    }

    public function test_contact_payload_stays_encrypted_and_a_wrong_object_is_not_success(): void
    {
        [$domain, $account, $provider, $management] = $this->fixture();
        $profile = CustomerProfile::withoutEvents(fn () => CustomerProfile::create([
            'customer_id' => $domain->customer_id, 'email' => 'profile@example.test', 'type' => 'individual', 'is_default' => true]));
        DomainSupplierContact::create(['supplier_account_id' => $account->id, 'customer_profile_id' => $profile->id,
            'role' => 'admin', 'handle' => 'SYNTHETIC-CONTACT-42', 'status' => 'active', 'verified_at' => now()]);
        $this->found($provider);
        $provider->shouldReceive('manage')->once()->andReturn(['status' => 'queued', 'tracking_id' => 't-contact']);
        $provider->shouldReceive('reconcile')->once()->andReturn(['status' => 'succeeded', 'object_id' => 'someone-else.example']);
        $result = $management->manage($domain, 'contacts', ['handles' => ['admin' => 'SYNTHETIC-CONTACT-42']]);
        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('SYNTHETIC-CONTACT-42', json_encode($result));
        $this->assertStringNotContainsString('SYNTHETIC-CONTACT-42', DB::table('domain_supplier_operations')->first()->request_context);
        $this->assertSame('SYNTHETIC-CONTACT-42', DomainSupplierOperation::first()->request_context['payload']['handles']['admin']);
        $this->assertSame('unknown', $management->reconcile(DomainSupplierOperation::firstOrFail())['status']);
    }
    public function test_a_contact_from_another_profile_is_never_submitted(): void
    {
        [$domain, $account, $provider, $management] = $this->fixture();
        $profile = CustomerProfile::withoutEvents(fn () => CustomerProfile::create([
            'customer_id' => $domain->customer_id, 'email' => 'profile@example.test', 'type' => 'individual', 'is_default' => true]));
        $other = Customer::create(['email' => 'other@example.test', 'password' => bcrypt('synthetic'), 'status' => 'active']);
        $otherProfile = CustomerProfile::withoutEvents(fn () => CustomerProfile::create([
            'customer_id' => $other->id, 'email' => 'other-profile@example.test', 'type' => 'individual', 'is_default' => true]));
        DomainSupplierContact::create(['supplier_account_id' => $account->id, 'customer_profile_id' => $otherProfile->id,
            'role' => 'admin', 'handle' => 'OTHER-CONTACT-42', 'status' => 'active', 'verified_at' => now()]);
        $provider->shouldNotReceive('find'); $provider->shouldNotReceive('manage');
        $this->assertFalse($management->manage($domain, 'contacts', ['handles' => ['admin' => 'OTHER-CONTACT-42']])['ok']);
        $this->assertDatabaseCount('domain_supplier_operations', 0);
    }

    public function test_an_explicit_repeat_can_reapply_a_proven_external_drift_with_a_new_journal_entry(): void
    {
        [$domain, $account, $provider, $management] = $this->fixture();
        $provider->shouldReceive('find')->twice()->andReturn([
            'found' => true, 'object_id' => 'management.example', 'expires_at' => '2027-10-31']);
        $provider->shouldReceive('manage')->twice()->andReturn(['ok' => true, 'object_id' => 'management.example']);
        $provider->shouldReceive('reconcile')->once()->withArgs(fn ($op) => $op['status'] === 'succeeded')
            ->andReturn(['status' => 'failed', 'error_code' => 'management_state_drifted']);
        $this->assertTrue($management->manage($domain, 'lock', ['locked' => true])['ok']);
        $this->assertTrue($management->manage($domain, 'lock', ['locked' => true])['ok']);
        $this->assertDatabaseCount('domain_supplier_operations', 2);
        $this->assertSame(2, DomainSupplierOperation::where('status', 'succeeded')->count());
        $this->assertSame(2, DomainSupplierOperation::pluck('idempotency_key')->unique()->count());
    }

    public function test_dnskey_management_preserves_type_in_the_encrypted_operation(): void
    {
        [$domain, $account, $provider, $management] = $this->fixture();
        $account->forceFill(['provider' => 'openprovider'])->save();
        $domain->forceFill(['registrar' => 'openprovider'])->save();
        $this->found($provider);
        $key = ['record_type' => 'DNSKEY', 'algorithm' => 13, 'flags' => 257, 'protocol' => 3,
            'public_key' => base64_encode(str_repeat('synthetic-public-material', 4))];
        $provider->shouldReceive('manage')->once()->with('management.example', 'management.example', 'dnssec', ['keys' => [$key]])
            ->andReturn(['status' => 'queued', 'process_id' => 'p-dnskey']);
        $this->assertSame('queued', $management->manage($domain, 'dnssec', ['keys' => [$key]])['status']);
        $this->assertSame('DNSKEY', DomainSupplierOperation::first()->request_context['payload']['keys'][0]['record_type']);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_a_malformed_ds_digest_never_reaches_a_supplier(): void
    {
        [$domain, $account, $provider, $management] = $this->fixture();
        $provider->shouldNotReceive('find'); $provider->shouldNotReceive('manage');
        $this->assertFalse($management->manage($domain, 'dnssec', ['keys' => [[
            'tag' => 1, 'algorithm' => 13, 'digest_type' => 2, 'digest' => 'ABCD']]])['ok']);
        $this->assertDatabaseCount('domain_supplier_operations', 0);
    }

}
