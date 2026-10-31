<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\DomainSupplierAccount;
use App\Models\DomainSupplierContact;
use App\Models\User;
use App\Models\DomainSupplierOperation;
use App\Models\Invoice;
use App\Services\Domain\Providers\DomainProvider;
use App\Services\Domain\Providers\DomainProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainSupplierRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        config(['services.openprovider.username' => 'synthetic-account', 'services.openprovider.password' => 'synthetic-key']);
        $legacy = DomainSupplierAccount::where('legacy_config', true)->firstOrFail();
        $legacy->forceFill(['settings' => [\App\Services\Domain\Providers\LegacyAccountIdentity::SETTING
            => \App\Services\Domain\Providers\LegacyAccountIdentity::currentFingerprint()]])->save();
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => uniqid('domain-').'@example.test', 'password' => bcrypt('test'), 'status' => 'active']);
    }

    private function domain(Customer $customer, array $values = []): Domain
    {
        return Domain::create(array_merge(['customer_id' => $customer->id, 'domain' => 'routing.example',
            'sld' => 'routing', 'tld' => 'example', 'registrar' => 'joker', 'op_id' => 777,
            'status' => 'active', 'provision_status' => 'done', 'is_locked' => false,
            'expires_at' => now()->addYear()], $values));
    }

    private function account(): DomainSupplierAccount
    {
        return DomainSupplierAccount::create(['provider' => 'joker', 'environment' => 'production',
            'label' => 'Synthetic account', 'active' => true, 'credentials' => ['api_key' => 'synthetic-only']]);
    }

    private function provider(DomainSupplierAccount $account): \Mockery\MockInterface
    {
        $provider = \Mockery::mock(DomainProvider::class);
        $factory = \Mockery::mock(DomainProviderFactory::class);
        $factory->shouldReceive('forAccount')->withArgs(fn ($a) => $a->id === $account->id)->andReturn($provider);
        $this->app->instance(DomainProviderFactory::class, $factory);
        return $provider;
    }

    public function test_panel_management_checks_customer_ownership_before_any_provider_call(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $domain = $this->domain($owner);
        $this->actingAs($other, 'customer')->post('/account/domains/'.$domain->id.'/nameservers', [
            'ns' => ['ns1.external.example', 'ns2.external.example'],
        ])->assertNotFound();
        $this->post('/account/domains/'.$domain->id.'/authcode')->assertNotFound();
        $this->post('/account/domains/'.$domain->id.'/contacts', ['contacts' => ['admin' => 1]])->assertNotFound();
        $this->post('/account/domains/'.$domain->id.'/dnssec', ['enabled' => false])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_joker_label_and_openprovider_id_never_route_to_the_default_account(): void
    {
        $customer = $this->customer();
        $domain = $this->domain($customer);
        $this->actingAs($customer, 'customer')->post('/account/domains/'.$domain->id.'/nameservers', [
            'ns' => ['ns1.external.example', 'ns2.external.example'],
        ])->assertSessionHasErrors();
        $this->post('/account/domains/'.$domain->id.'/lock', ['lock' => true])->assertSessionHasErrors();
        $this->post('/account/domains/'.$domain->id.'/authcode')->assertSessionHasErrors();
        $this->assertNull($domain->fresh()->name_servers);
        Http::assertNothingSent();
    }

    public function test_api_ownership_and_malformed_provider_identity_prevent_remote_writes(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $domain = $this->domain($owner);
        [, $token] = CustomerApiToken::issue($other->id, 'synthetic', ['domains:manage']);
        $this->withToken($token)->putJson('/api/v1/domains/'.$domain->domain.'/nameservers', [
            'nameservers' => ['ns1.external.example', 'ns2.external.example'],
        ])->assertNotFound();
        [, $ownToken] = CustomerApiToken::issue($owner->id, 'synthetic', ['domains:manage']);
        $this->withToken($ownToken)->postJson('/api/v1/domains/'.$domain->domain.'/lock', ['locked' => true])
            ->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_existing_openprovider_management_keeps_the_original_account_when_feature_is_off(): void
    {
        config(['domain_suppliers.enabled' => false]);
        $customer = $this->customer();
        $account = DomainSupplierAccount::where('legacy_config', true)->firstOrFail();
        $domain = $this->domain($customer, ['registrar' => 'openprovider',
            'supplier_account_id' => $account->id, 'supplier_object_id' => '777']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'synthetic-token']]),
            '*/domains/777' => Http::response(['code' => 0, 'data' => []])]);
        $this->actingAs($customer, 'customer')->post('/account/domains/'.$domain->id.'/lock', ['lock' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($domain->fresh()->is_locked);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/domains/777') && $request->method() === 'PUT');
        Http::assertSentCount(2);
    }

    public function test_normalized_order_on_legacy_login_uses_its_real_object_even_without_op_id(): void
    {
        config(['domain_suppliers.enabled' => true]);
        $customer = $this->customer();
        $account = DomainSupplierAccount::where('legacy_config', true)->firstOrFail();
        $domain = $this->domain($customer, ['registrar' => 'openprovider', 'op_id' => null,
            'supplier_account_id' => $account->id, 'supplier_object_id' => '9988',
            'supplier_quote' => ['quote_id' => 123, 'supplier_account_id' => $account->id]]);
        $provider = $this->provider($account);
        $provider->shouldReceive('find')->once()->with('routing.example')->andReturn(['found' => true, 'object_id' => '9988']);
        $provider->shouldReceive('manage')->once()->with('routing.example', '9988', 'lock', ['locked' => true])
            ->andReturn(['ok' => true, 'data' => []]);
        $this->actingAs($customer, 'customer')->post('/account/domains/'.$domain->id.'/lock', ['lock' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($domain->fresh()->is_locked);
        $this->assertNull($domain->fresh()->op_id);
        Http::assertNothingSent();
    }

    public function test_linked_management_uses_only_the_holding_account_and_verified_object(): void
    {
        config(['domain_suppliers.enabled' => true]);
        $customer = $this->customer();
        $account = $this->account();
        $domain = $this->domain($customer, ['supplier_account_id' => $account->id, 'supplier_object_id' => 'routing.example']);
        $provider = $this->provider($account);
        $provider->shouldReceive('find')->once()->with('routing.example')->andReturn([
            'found' => true, 'object_id' => 'routing.example', 'expires_at' => '2027-10-31',
        ]);
        $provider->shouldReceive('manage')->once()->with('routing.example', 'routing.example', 'lock', ['locked' => true])
            ->andReturn(['ok' => true, 'data' => []]);
        [, $token] = CustomerApiToken::issue($customer->id, 'synthetic', ['domains:manage']);
        $this->withToken($token)->postJson('/api/v1/domains/'.$domain->domain.'/lock', ['locked' => true])->assertOk();
        $this->assertTrue($domain->fresh()->is_locked);
        Http::assertNothingSent();
    }

    public function test_portfolio_mismatch_never_mutates_another_account_object(): void
    {
        config(['domain_suppliers.enabled' => true]);
        $customer = $this->customer();
        $account = $this->account();
        $domain = $this->domain($customer, ['supplier_account_id' => $account->id, 'supplier_object_id' => 'routing.example']);
        $provider = $this->provider($account);
        $provider->shouldReceive('find')->once()->andReturn(['found' => true, 'object_id' => 'different.example']);
        $provider->shouldNotReceive('manage');
        $this->actingAs($customer, 'customer')->post('/account/domains/'.$domain->id.'/lock', ['lock' => true])
            ->assertSessionHasErrors();
        $this->assertFalse($domain->fresh()->is_locked);
        Http::assertNothingSent();
    }

    public function test_expiry_repair_does_not_use_an_openprovider_id_for_joker(): void
    {
        $domain = $this->domain($this->customer(), ['expires_at' => null]);
        $this->artisan('domains:lifecycle')->assertSuccessful();
        $this->assertNull($domain->fresh()->expires_at);
        Http::assertNothingSent();
    }

    public function test_expiry_repair_reads_the_verified_holding_account(): void
    {
        config(['domain_suppliers.enabled' => true]);
        $account = $this->account();
        $domain = $this->domain($this->customer(), ['expires_at' => null,
            'supplier_account_id' => $account->id, 'supplier_object_id' => 'routing.example']);
        $provider = $this->provider($account);
        $provider->shouldReceive('find')->once()->with('routing.example')->andReturn([
            'found' => true, 'object_id' => 'routing.example', 'expires_at' => now()->addYear()->toDateString(),
        ]);
        $this->artisan('domains:lifecycle')->assertSuccessful();
        $this->assertSame(now()->addYear()->toDateString(), $domain->fresh()->expires_at->toDateString());
        Http::assertNothingSent();
    }

    public function test_an_unknown_financial_journal_is_never_requeued_or_refunded_by_the_legacy_cron(): void
    {
        $account = DomainSupplierAccount::where('legacy_config', true)->firstOrFail();
        $domain = $this->domain($this->customer(), ['registrar' => 'openprovider', 'supplier_account_id' => $account->id,
            'supplier_object_id' => '777', 'status' => 'pending', 'provision_status' => 'manual',
            'updated_at' => now()->subDays(3)]);
        $invoice = Invoice::create(['customer_id' => $domain->customer_id, 'domain_id' => $domain->id,
            'kind' => 'domain', 'currency_code' => 'IRT', 'subtotal' => 100, 'total' => 100,
            'paid' => 100, 'status' => 'paid', 'issued_at' => now(), 'paid_at' => now()]);
        DomainSupplierOperation::create(['domain_id' => $domain->id, 'invoice_id' => $invoice->id,
            'supplier_account_id' => $account->id, 'operation' => 'create', 'idempotency_key' => 'unknown-order',
            'fqdn' => $domain->domain, 'period_months' => 12, 'status' => 'unknown', 'request_hash' => str_repeat('a', 64)]);
        $this->artisan('domains:resolve-stuck', ['--hours' => 1])->assertSuccessful();
        $this->assertSame('manual', $domain->fresh()->provision_status);
        $this->assertSame('pending', $domain->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame('unknown', DomainSupplierOperation::first()->status);
        Http::assertNothingSent();
    }

    public function test_customer_cannot_select_a_verified_contact_from_another_customer_profile(): void
    {
        config(['domain_suppliers.enabled' => true]);
        $owner = $this->customer();
        $other = $this->customer();
        $ownProfile = CustomerProfile::create(['customer_id' => $owner->id, 'email' => $owner->email, 'type' => 'individual', 'is_default' => true]);
        $otherProfile = CustomerProfile::create(['customer_id' => $other->id, 'email' => $other->email, 'type' => 'individual', 'is_default' => true]);
        $account = $this->account();
        $domain = $this->domain($owner, ['supplier_account_id' => $account->id, 'supplier_object_id' => 'routing.example']);
        $foreignContact = DomainSupplierContact::create(['supplier_account_id' => $account->id,
            'customer_profile_id' => $otherProfile->id, 'role' => 'registrant', 'handle' => 'PRIVATE-OTHER-HANDLE',
            'status' => 'active', 'verified_at' => now()]);
        $this->actingAs($owner, 'customer')->post('/account/domains/'.$domain->id.'/contacts',
            ['contacts' => ['admin' => $foreignContact->id]])->assertSessionHasErrors();
        $this->assertSame(0, DomainSupplierOperation::count());
        Http::assertNothingSent();
    }

    public function test_invalid_dnssec_digest_cannot_create_a_management_operation(): void
    {
        config(['domain_suppliers.enabled' => true]);
        $owner = $this->customer();
        $account = $this->account();
        $domain = $this->domain($owner, ['supplier_account_id' => $account->id, 'supplier_object_id' => 'routing.example']);
        $this->actingAs($owner, 'customer')->post('/account/domains/'.$domain->id.'/dnssec',
            ['enabled' => true, 'keys' => [['tag' => 100, 'algorithm' => 13, 'digest_type' => 2, 'digest' => 'ABCDEF']]])
            ->assertSessionHasErrors();
        $this->assertSame(0, DomainSupplierOperation::count());
        Http::assertNothingSent();
    }

    public function test_panel_forms_render_localized_routes_csrf_and_only_customer_scoped_contact_choices(): void
    {
        config(['domain_suppliers.enabled' => true]);
        $owner = $this->customer();
        $other = $this->customer();
        $profile = CustomerProfile::create(['customer_id' => $owner->id, 'email' => $owner->email, 'type' => 'individual', 'is_default' => true]);
        $foreignProfile = CustomerProfile::create(['customer_id' => $other->id, 'email' => $other->email, 'type' => 'individual', 'is_default' => true]);
        $account = $this->account();
        $account->forceFill(['capabilities' => ['tlds' => ['example' => ['management' => ['contacts', 'dnssec']]]]])->save();
        $domain = $this->domain($owner, ['supplier_account_id' => $account->id, 'supplier_object_id' => 'routing.example']);
        $ownContact = DomainSupplierContact::create(['supplier_account_id' => $account->id,
            'customer_profile_id' => $profile->id, 'role' => 'registrant', 'handle' => 'PRIVATE-OWN-HANDLE',
            'status' => 'active', 'verified_at' => now()]);
        $foreignContact = DomainSupplierContact::create(['supplier_account_id' => $account->id,
            'customer_profile_id' => $foreignProfile->id, 'role' => 'registrant', 'handle' => 'PRIVATE-FOREIGN-HANDLE',
            'status' => 'active', 'verified_at' => now()]);
        $this->mock(\App\Services\Domain\DomainRenewalInvoicer::class)->shouldReceive('effectivePerYear')->andReturn(1000000);
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $response = $this->actingAs($owner, 'customer')->get($prefix.'/account/domains/'.$domain->id)->assertOk();
            $html = $response->getContent();
            $doc = new \DOMDocument;
            @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($doc);
            foreach (['dnssec', 'contacts'] as $action) {
                $forms = $xpath->query('//form[contains(@action,"'.$prefix.'/account/domains/'.$domain->id.'/'.$action.'")]');
                $this->assertSame(1, $forms->length, $locale.' '.$action.' route');
                $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $forms->item(0))->length);
            }
            $this->assertGreaterThan(0, $xpath->query('//select[@name="contacts[admin]"]/option[@value="'.$ownContact->id.'"]')->length);
            $this->assertSame(0, $xpath->query('//select[@name="contacts[admin]"]/option[@value="'.$foreignContact->id.'"]')->length);
            $this->assertStringNotContainsString('PRIVATE-OWN-HANDLE', $html);
            $this->assertStringNotContainsString('PRIVATE-FOREIGN-HANDLE', $html);
            $this->assertStringNotContainsString('synthetic-only', $html);
            $this->assertStringNotContainsString('domain-management.', $html);
        }
        Http::assertNothingSent();
    }

    public function test_admin_supplier_panel_renders_all_three_translations_without_secret_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->account();
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $response = $this->actingAs($admin)->get('/admin/domain-suppliers')->assertOk();
            $response->assertSee(__('domain-suppliers.title'), false);
            $this->assertStringNotContainsString('synthetic-only', $response->getContent());
            $this->assertStringNotContainsString('value="synthetic', $response->getContent());
            $this->assertStringNotContainsString('domain-suppliers.', $response->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_verified_link_preview_and_confirmation_do_not_create_historical_finance_or_dns(): void
    {
        $this->mock(\App\Services\Dns\DomainZoneProvisioner::class)->shouldNotReceive('ensure');
        $customer = $this->customer();
        $account = $this->account();
        $provider = $this->provider($account);
        $provider->shouldReceive('find')->twice()->with('existing.example')->andReturn([
            'found' => true, 'object_id' => 'existing.example', 'expires_at' => now()->addYear()->toDateString(),
        ]);
        $args = ['--account' => $account->id, '--domain' => 'existing.example', '--customer' => $customer->id];
        $this->artisan('domains:supplier-link', $args)->assertSuccessful();
        $this->assertSame(0, Domain::count());
        $this->artisan('domains:supplier-link', $args + ['--confirm' => true])->assertSuccessful();
        $domain = Domain::firstOrFail();
        $this->assertSame($account->id, $domain->supplier_account_id);
        $this->assertSame('existing.example', $domain->supplier_object_id);
        $this->assertSame($customer->id, $domain->customer_id);
        $this->assertNull($domain->op_id);
        $this->assertNull($domain->owner_handle);
        $this->assertNull($domain->name_servers);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, CreditEntry::count());
        $this->assertDatabaseCount('registry_handles', 0);
        $this->assertDatabaseCount('domain_supplier_operations', 0);
        Http::assertNothingSent();
    }

    public function test_verified_link_refuses_a_customer_change_even_when_portfolio_lookup_succeeds(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $domain = $this->domain($owner, ['op_id' => null]);
        $account = $this->account();
        $provider = $this->provider($account);
        $provider->shouldReceive('find')->once()->with('routing.example')->andReturn([
            'found' => true, 'object_id' => 'routing.example', 'expires_at' => now()->addYear()->toDateString(),
        ]);
        $this->artisan('domains:supplier-link', ['--account' => $account->id,
            '--domain' => 'routing.example', '--customer' => $other->id, '--confirm' => true])->assertFailed();
        $this->assertSame($owner->id, $domain->fresh()->customer_id);
        $this->assertNull($domain->fresh()->supplier_account_id);
        Http::assertNothingSent();
    }
}
