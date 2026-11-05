<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\DomainSupplierContact;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\DomainSupplierAccount;
use App\Models\DomainSupplierOperation;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Domain\DomainSupplierProbe;
use App\Services\Domain\Providers\DomainProvider;
use App\Services\Domain\Providers\DomainProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DomainSupplierAdminSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['domain_suppliers.enabled' => false]);
    }

    private function account(array $extra = []): DomainSupplierAccount
    {
        return DomainSupplierAccount::create($extra + [
            'provider' => 'joker', 'environment' => 'production', 'label' => 'Synthetic account',
            'credentials' => ['api_key' => 'test-key-private'], 'active' => true,
            'sales_enabled' => false, 'contract_verified' => false,
            'identity_verified' => false, 'pricing_verified' => false,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => 'synthetic-'.bin2hex(random_bytes(4)).'@example.test',
            'password' => bcrypt('test-only-password'), 'status' => 'active']);
    }

    public function test_secrets_and_procurement_details_are_hidden_and_credentials_are_encrypted(): void
    {
        $account = $this->account();
        $stored = DB::table('domain_supplier_accounts')->where('id', $account->id)->value('credentials');
        $this->assertStringNotContainsString('test-key-private', $stored);
        $this->assertSame('test-key-private', $account->fresh()->credentials['api_key']);
        $this->assertArrayNotHasKey('credentials', $account->toArray());
        $domain = new Domain(['supplier_account_id' => $account->id, 'supplier_object_id' => 'remote-private',
            'supplier_quote' => ['amount_minor' => 1100], 'cost_amount' => 1100]);
        $invoice = new Invoice(['supplier_account_id' => $account->id, 'supplier_quote' => ['amount_minor' => 1100]]);
        $quote = new DomainQuote(['supplier_account_id' => $account->id, 'cost_components' => ['discount' => 10],
            'fx_snapshot' => ['rate' => 100000], 'purchase_minor' => 1100, 'period_months' => 12]);
        foreach ([$domain, $invoice, $quote] as $model) $this->assertArrayNotHasKey('supplier_account_id', $model->toArray());
        $this->assertArrayNotHasKey('supplier_quote', $domain->toArray());
        $this->assertArrayNotHasKey('supplier_quote', $invoice->toArray());
        $this->assertArrayNotHasKey('cost_components', $quote->toArray());
        $this->assertSame(12, $quote->toArray()['period_months']);
    }

    public function test_sales_require_feature_live_environment_verification_fresh_health_and_balance(): void
    {
        $account = $this->account(['sales_enabled' => true, 'contract_verified' => true,
            'identity_verified' => true, 'pricing_verified' => true, 'connection_status' => 'healthy',
            'health_checked_at' => now(), 'balance_minor' => 50000, 'balance_currency' => 'EUR']);
        $this->assertFalse($account->saleEligible());
        config(['domain_suppliers.enabled' => true]);
        $this->assertTrue($account->saleEligible());
        $account->environment = 'ote';
        $this->assertFalse($account->saleEligible());
        $account->environment = 'production';
        $account->health_checked_at = now()->subSeconds(301);
        $this->assertFalse($account->saleEligible());
        $account->health_checked_at = now();
        $account->balance_minor = 0;
        $this->assertFalse($account->saleEligible());
    }

    public function test_admin_routes_deny_author_and_do_not_flash_invalid_secrets(): void
    {
        $author = User::factory()->create(['role' => 'author']);
        $this->actingAs($author)->get('/admin/domain-suppliers')->assertForbidden();
        $this->actingAs($author)->post('/admin/domain-suppliers', [])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/domain-suppliers', [
            'provider' => 'bad', 'environment' => 'production', 'label' => 'x', 'api_key' => 'do-not-flash-me',
        ])->assertSessionHasErrors('provider');
        $this->assertStringNotContainsString('do-not-flash-me', json_encode(session()->all()));
        $this->actingAs($admin)->get('/admin/domain-suppliers')->assertOk()->assertDontSee('test-key-private');
    }

    public function test_sales_endpoint_cannot_bypass_readiness_and_test_accounts_never_sell(): void
    {
        config(['domain_suppliers.enabled' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $account = $this->account(['environment' => 'ote']);
        $this->actingAs($admin)->post('/admin/domain-suppliers/'.$account->id.'/sales', ['enabled' => 1])
            ->assertSessionHasErrors('sales');
        $this->assertFalse($account->fresh()->sales_enabled);
        Http::assertNothingSent();
    }

    public function test_read_only_probe_preserves_reviewed_capabilities_and_never_enables_sales(): void
    {
        $caps = ['tlds' => ['com' => ['period_months' => [12], 'operations' => ['create']]]];
        $account = $this->account(['capabilities' => $caps]);
        $provider = Mockery::mock(DomainProvider::class);
        $provider->shouldReceive('probe')->once()->andReturn(['healthy' => true, 'balance_minor' => 3200,
            'currency' => 'EUR', 'capabilities' => ['operations' => ['create']]]);
        $factory = Mockery::mock(DomainProviderFactory::class);
        $factory->shouldReceive('forAccount')->once()->andReturn($provider);
        $this->assertTrue((new DomainSupplierProbe($factory))->check($account));
        $this->assertSame($caps, $account->fresh()->capabilities);
        $this->assertFalse($account->fresh()->sales_enabled);
        $this->assertSame(3200, $account->fresh()->balance_minor);
        Http::assertNothingSent();
    }

    public function test_stale_quote_models_cannot_both_claim_the_same_quote(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $quote = DomainQuote::create(['domain' => 'synthetic-claim.example', 'tld' => 'example',
            'registrar' => 'openprovider', 'cost_amount' => 10, 'cost_currency' => 'EUR',
            'sell_toman' => 1000, 'renew_toman' => 1000, 'honour_until' => now()->addMinutes(15)]);
        $stale = $quote->fresh();
        $this->assertTrue($quote->claimFor($a->id));
        $this->assertFalse($stale->claimFor($b->id));
        $this->assertTrue($stale->claimFor($a->id));
        $this->assertFalse($stale->claimFor(0));
    }

    public function test_migration_only_binds_historical_openprovider_objects_and_does_not_create_invoices(): void
    {
        $migration = require database_path('migrations/2026_10_31_150000_domain_supplier_accounts.php');
        $migration->down();
        $customer = $this->customer();
        $legacy = Domain::create(['customer_id' => $customer->id, 'domain' => 'legacy-synthetic.example',
            'sld' => 'legacy-synthetic', 'tld' => 'example', 'registrar' => 'openprovider', 'op_id' => 73,
            'status' => 'active', 'owner_handle' => 'KEEP-HANDLE', 'name_servers' => ['ns1.synthetic.example']]);
        $unbound = Domain::create(['customer_id' => $customer->id, 'domain' => 'unbound-synthetic.example',
            'sld' => 'unbound-synthetic', 'tld' => 'example', 'registrar' => 'joker', 'op_id' => 92]);
        $profile = CustomerProfile::create(['customer_id' => $customer->id, 'type' => 'individual', 'email' => 'synthetic-profile@example.test', 'mobile' => '09121111111', 'address' => 'Synthetic only address']);
        $handle = \App\Models\RegistryHandle::create(['customer_profile_id' => $profile->id,
            'registry' => 'openprovider', 'handle' => 'SYNTHETIC-OLD-73', 'role' => 'registrant',
            'status' => 'active', 'sent_data' => ['email' => 'private@example.test']]);
        $invoices = Invoice::count();
        $migration->up();
        $this->assertSame('73', $legacy->fresh()->supplier_object_id);
        $this->assertNotNull($legacy->fresh()->supplier_account_id);
        $this->assertSame(73, (int) $legacy->fresh()->op_id);
        $this->assertSame('KEEP-HANDLE', $legacy->fresh()->owner_handle);
        $this->assertSame(['ns1.synthetic.example'], $legacy->fresh()->name_servers);
        $this->assertNull($unbound->fresh()->supplier_account_id);
        $this->assertNull($unbound->fresh()->supplier_object_id);
        $this->assertSame($invoices, Invoice::count());
        $this->assertSame(0, DomainSupplierOperation::count());
        $scoped = DomainSupplierContact::where('handle', 'SYNTHETIC-OLD-73')->firstOrFail();
        $this->assertNull($scoped->verified_at);
        $this->assertSame(['email' => 'private@example.test'], $scoped->sent_data);
        $this->assertSame($handle->sent_data, $handle->fresh()->sent_data);
        $this->assertStringNotContainsString('private@example.test', DB::table('domain_supplier_contacts')->where('id', $scoped->id)->value('sent_data'));
        $this->assertFalse(DomainSupplierAccount::where('legacy_config', true)->firstOrFail()->sales_enabled);
        Http::assertNothingSent();
    }

    public function test_default_background_probe_is_disabled_without_any_http_request(): void
    {
        $this->account();
        $this->artisan('domains:suppliers-probe')->expectsOutput('supplier_feature_disabled')->assertSuccessful();
        Http::assertNothingSent();
    }
    public function test_verified_contact_mapping_requires_owner_and_cannot_reassign_another_profile(): void
    {
        $account = $this->account();
        $admin = User::factory()->create(['role' => 'admin']);
        $a = CustomerProfile::create(['customer_id' => $this->customer()->id, 'type' => 'individual', 'email' => 'synthetic-profile@example.test', 'mobile' => '09121111111', 'address' => 'Synthetic only address']);
        $b = CustomerProfile::create(['customer_id' => $this->customer()->id, 'type' => 'individual', 'email' => 'synthetic-profile@example.test', 'mobile' => '09121111111', 'address' => 'Synthetic only address']);
        $payload = ['customer_profile_id' => $a->id, 'handle' => 'SYNTHETIC-73',
            'evidence' => 'Synthetic account portfolio test', 'verified' => '1'];
        $url = '/admin/domain-suppliers/'.$account->id.'/contacts';
        $this->actingAs($admin)->post($url, $payload)->assertForbidden();
        config(['domain_suppliers.approver_user_ids' => [$admin->id]]);
        $this->post($url, $payload)->assertSessionHas('ok');
        $contact = DomainSupplierContact::firstOrFail();
        $this->assertSame($a->id, $contact->customer_profile_id);
        $this->assertSame($admin->id, $contact->verified_by);
        $this->assertNotNull($contact->verified_at);
        $this->post($url, ['customer_profile_id' => $b->id] + array_diff_key($payload, ['customer_profile_id' => true]))
            ->assertSessionHasErrors('handle');
        $this->assertSame($a->id, $contact->fresh()->customer_profile_id);
        $other = $this->account(['label' => 'Other real account']);
        $this->post('/admin/domain-suppliers/'.$other->id.'/contacts', $payload)->assertSessionHas('ok');
        $this->assertSame(2, DomainSupplierContact::where('handle', 'SYNTHETIC-73')->count());
        Http::assertNothingSent();
    }

    public function test_unresolved_fqdn_reservation_is_unique_across_supplier_accounts_until_terminal_result(): void
    {
        $a = $this->account();
        $b = $this->account(['provider' => 'openprovider']);
        $fqdn = 'cross-account-reservation.example';
        $op = DomainSupplierOperation::create(['supplier_account_id' => $a->id,
            'idempotency_key' => 'first-synthetic-order', 'fqdn' => $fqdn, 'active_fqdn' => $fqdn,
            'operation' => 'create', 'period_months' => 12, 'status' => 'unknown']);
        $blocked = false;
        try {
            DomainSupplierOperation::create(['supplier_account_id' => $b->id,
                'idempotency_key' => 'different-synthetic-order', 'fqdn' => $fqdn, 'active_fqdn' => $fqdn,
                'operation' => 'create', 'period_months' => 12, 'status' => 'prepared']);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $blocked = true;
        }
        $this->assertTrue($blocked);
        $this->assertSame(1, DomainSupplierOperation::count());
        $op->forceFill(['status' => 'failed', 'active_fqdn' => null])->save();
        $next = DomainSupplierOperation::create(['supplier_account_id' => $b->id,
            'idempotency_key' => 'second-synthetic-order', 'fqdn' => $fqdn, 'active_fqdn' => $fqdn,
            'operation' => 'create', 'period_months' => 12, 'status' => 'prepared']);
        $this->assertSame($fqdn, $next->active_fqdn);
        $this->assertArrayNotHasKey('active_fqdn', $next->toArray());
        $this->assertSame(2, DomainSupplierOperation::count());
        Http::assertNothingSent();
    }

}
