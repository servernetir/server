<?php

namespace Tests\Feature;

use App\Models\{Currency, Customer, CustomerProfile, Domain, DomainQuote, DomainSupplierAccount, DomainSupplierContact, DomainSupplierOperation, Invoice, Payment, Product, Server, Service, Setting};
use App\Services\Domain\{DomainProcurement, DomainRegistrar, DomainSaleAuthorization, LiveDomainPricing, TldGate};
use App\Services\Domain\Providers\{DomainProvider, DomainProviderFactory};
use App\Services\ExchangeRate;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail, Storage};
use Tests\TestCase;

class DomainPurchasePathsTest extends TestCase
{
    use RefreshDatabase;

    private array $accounts = [];
    private array $reads = [];
    private array $submissions = [];
    private bool $taken = false;

    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests(); Http::fake(); Mail::fake(); Storage::fake('local');
        config(['domain_suppliers.enabled' => true, 'domain_suppliers.authorization_mode' => 'production',
            'domain_suppliers.production_caps_minor' => ['USD' => 100000], 'domain_reseller.min_margin_pct' => 0,
            'domain_suppliers.contact_provisioning_enabled' => false, 'billing.tax_percent' => 10]);
        Setting::put('domain_margin_pct', '1'); Setting::put('price_margin_pct', '0');
        foreach (['IRT' => 0, 'USD' => 2, 'EUR' => 2] as $code => $exponent) {
            Currency::updateOrCreate(['code' => $code], ['exponent' => $exponent, 'is_active' => true,
                'is_base' => $code === 'IRT', 'rounding_step' => $code === 'IRT' ? 1000 : 1]);
        }
        $fx = \Mockery::mock(ExchangeRate::class);
        $fx->shouldReceive('current')->andReturnUsing(fn ($code) => ['currency' => $code, 'rate_toman' => 100000,
            'at' => now()->toIso8601String(), 'source_at' => now()->toIso8601String(), 'source' => 'synthetic']);
        $this->app->instance(ExchangeRate::class, $fx);
        DomainSupplierAccount::query()->update(['active' => false]);
        $factory = \Mockery::mock(DomainProviderFactory::class);
        foreach (['openprovider', 'joker'] as $provider) {
            $a = DomainSupplierAccount::create(['provider' => $provider, 'environment' => 'production',
                'label' => 'Synthetic '.$provider, 'active' => true, 'sales_enabled' => true,
                'contract_verified' => true, 'identity_verified' => true, 'pricing_verified' => true,
                'connection_status' => 'healthy', 'health_checked_at' => now(),
                'balance_minor' => 100000, 'balance_currency' => 'USD',
                'credentials' => ['api_key' => 'synthetic-only'],
                'capabilities' => ['default' => DomainSupplierAccount::STANDARD_PROFILE]]);
            $this->accounts[$provider] = $a;
            $p = \Mockery::mock(DomainProvider::class);
            $p->shouldReceive('quote')->andReturnUsing(function ($fqdn, $operation, $months) use ($provider) {
                $this->reads[] = [$provider, $fqdn, $months];
                $cloud = str_ends_with($fqdn, '.cloud');
                $amount = ($provider === 'joker' ? ($cloud ? 300 : 1200) : ($cloud ? 2000 : 1000)) * intdiv($months, 12);
                return ['available' => ! $this->taken, 'amount_minor' => $amount, 'provider_debit_minor' => $amount,
                    'currency' => 'USD', 'period_months' => $months, 'premium' => false, 'tax_included' => true,
                    'components' => ['account_domain_minor' => $amount], 'terms_hash' => 'synthetic-terms'];
            });
            $p->shouldReceive('probe')->andReturn(['healthy' => true, 'balance_minor' => 100000, 'currency' => 'USD']);
            $p->shouldReceive('find')->andReturn(['found' => false]);
            $p->shouldReceive('submit')->andReturnUsing(function ($operation, $fqdn, $months) use ($provider) {
                $this->submissions[] = [$provider, $operation, $fqdn, $months];
                return ['status' => 'queued', 'process_id' => 'SYNTHETIC-REGISTRATION'];
            });
            $p->shouldNotReceive('createContact'); $p->shouldNotReceive('manage');
            $factory->shouldReceive('forAccount')->withArgs(fn ($account) => $account->id === $a->id)->andReturn($p);
        }
        $this->app->instance(DomainProviderFactory::class, $factory);
        config(['domain_suppliers.production_account_ids' => array_map(fn ($a) => $a->id, $this->accounts)]);
        foreach (['com', 'cloud'] as $tld) TldGate::clear($tld);
    }

    private function customer(): Customer
    {
        $c = Customer::create(['email' => uniqid('synthetic-hosting-').'@example.test', 'password' => 'synthetic',
            'status' => 'active', 'locale' => 'fa']);
        $profile = CustomerProfile::create(['customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'verified', 'first_name' => 'Synthetic', 'last_name' => 'Owner', 'email' => $c->email,
            'country' => 'DE', 'city' => 'Example', 'address' => 'Synthetic address', 'postal_code' => '12345', 'mobile' => '+49123456789']);
        foreach ($this->accounts as $a) DomainSupplierContact::create(['customer_profile_id' => $profile->id,
            'supplier_account_id' => $a->id, 'role' => 'registrant', 'handle' => 'SYNTHETIC-'.$profile->id.'-'.$a->id,
            'status' => 'active', 'verified_at' => now()]);
        return $c;
    }

    private function product(): Product
    {
        $server = Server::create(['name' => 'Synthetic WHM', 'type' => 'whm', 'status' => 'active', 'country' => 'DE',
            'hostname' => 'hosting.example.test', 'username' => 'synthetic', 'api_token' => 'synthetic-only',
            'capacity' => 100, 'nameservers' => 'ns1.hosting.example.test,ns2.hosting.example.test']);
        return Product::create(['name' => 'Synthetic hosting', 'category' => 'shared', 'group' => 'linux',
            'server_id' => $server->id, 'plan' => 'synthetic-plan', 'requires_domain' => true, 'currency_code' => 'IRT',
            'base_price_eur' => '2.5', 'base_setup_eur' => '0.1', 'price' => 250000,
            'setup_fee' => 10000, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true]);
    }

    private function quote(Customer $c, string $name = 'synthetic-hosting.com'): array
    {
        return $this->actingAs($c, 'customer')->postJson('/account/order-domain-quote', ['domain' => $name])
            ->assertOk()->assertJsonPath('ok', true)->json();
    }

    private function place(Customer $c, Product $p, array $quote)
    {
        return $this->actingAs($c, 'customer')->from('/account/order/'.$p->slug)->post('/account/order/'.$p->slug,
            ['country' => 'DE', 'cycle' => 'monthly', 'domain_mode' => 'buy', 'domain_buy' => $quote['domain'],
                'domain_quote_id' => $quote['quote_id']]);
    }

    public function test_home_and_public_domain_search_both_choose_the_cheaper_account(): void
    {
        foreach (['com' => 'openprovider', 'cloud' => 'joker'] as $tld => $winner) {
            $name = 'synthetic-public.'.$tld; $this->reads = [];
            $home = $this->postJson('/api/domain-check', ['domain' => $name])->assertOk()->json();
            $this->assertTrue($home['result']['available']);
            $this->assertCount(2, $this->reads, 'a free primary name must not wait for other suffixes');
            $selected = DomainQuote::latest('id')->firstOrFail();
            $this->assertSame($winner, $selected->registrar);
            $public = $this->postJson('/api/domains/search', ['q' => 'synthetic-public', 'tlds' => [$tld]])->assertOk()->json();
            $this->assertSame($selected->sell_toman, $public['results'][0]['price_toman']);
            $this->assertSame($winner, DomainQuote::findOrFail($public['results'][0]['quote_id'])->registrar);
        }
        $this->assertSame([], $this->submissions);
    }

    public function test_hosting_quotes_are_authenticated_localized_and_hide_supplier_details(): void
    {
        $this->postJson('/account/order-domain-quote', ['domain' => 'synthetic-hosting.com'])->assertUnauthorized();
        $c = $this->customer();
        foreach (['', '/en', '/tr'] as $prefix) {
            $body = $this->actingAs($c, 'customer')->postJson($prefix.'/account/order-domain-quote', ['domain' => 'synthetic-hosting.com'])
                ->assertOk()->assertJsonPath('price_toman', 1010000)->assertJsonPath('total_toman', 1111000)->json();
            $this->assertArrayNotHasKey('provider', $body); $this->assertArrayNotHasKey('supplier_account_id', $body);
            $this->assertSame($c->id, DomainQuote::findOrFail($body['quote_id'])->customer_id);
        }
        $this->assertDatabaseCount('services', 0); $this->assertDatabaseCount('invoices', 0);
    }

    public function test_hosting_mixed_invoice_keeps_cheapest_supplier_and_regional_nameservers(): void
    {
        $c = $this->customer(); $p = $this->product(); $quote = $this->quote($c);
        $this->place($c, $p, $quote)->assertSessionHasNoErrors()->assertRedirect();
        $invoice = Invoice::firstOrFail(); $domain = Domain::firstOrFail();
        $this->assertSame(1397000, $invoice->total);
        $this->assertSame(127000, $invoice->tax);
        $this->assertSame($domain->id, $invoice->domain_id);
        $this->assertSame($this->accounts['openprovider']->id, $invoice->supplier_account_id);
        $this->assertSame($quote['quote_id'], $invoice->supplier_quote['quote_id']);
        $this->assertSame(['ns1.hosting.example.test', 'ns2.hosting.example.test'], $domain->name_servers);
        $this->assertSame('none', $domain->provision_status); $this->assertSame('unpaid', $invoice->status);
        $this->assertSame(1, $invoice->items()->where('title', 'ثبتِ دامنهٔ '.$domain->domain)->count());
        app(LiveDomainPricing::class)->assertInvoice($invoice);
        $this->assertSame([], $this->submissions);
        $this->place($c, $p, $quote)->assertSessionHasErrors('domain_buy');
        $this->assertDatabaseCount('services', 1); $this->assertDatabaseCount('invoices', 1);
    }

    public function test_combined_order_submits_only_the_winner_after_settlement_and_never_twice(): void
    {
        $c = $this->customer(); $p = $this->product(); $quote = $this->quote($c, 'synthetic-hosting.cloud');
        $this->place($c, $p, $quote)->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail(); $domain = Domain::firstOrFail();
        $this->assertSame('joker', $domain->registrar); $this->assertSame([], $this->submissions);
        app(DomainSaleAuthorization::class)->record($invoice, $c, 'panel');
        $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $c->id, 'gateway' => 'bale',
            'currency_code' => 'IRT', 'amount' => $invoice->total, 'status' => 'pending']);
        $this->assertTrue(app(PaymentService::class)->settleConfirmed($payment, 'SYNTHETIC-PAYMENT')->ok);
        $this->assertSame('pending', $domain->fresh()->provision_status);
        $this->assertSame('awaiting_provision', Service::firstOrFail()->status);
        app(DomainRegistrar::class)->register($domain->fresh());
        $this->assertSame([['joker', 'create', $quote['domain'], 12]], $this->submissions);
        $this->assertSame($this->accounts['joker']->id, DomainSupplierOperation::firstOrFail()->supplier_account_id);
        app(DomainRegistrar::class)->register($domain->fresh());
        $this->assertCount(1, $this->submissions);
    }

    public function test_other_customers_quote_changed_name_expiry_and_missing_profile_do_not_create_orders(): void
    {
        $c = $this->customer(); $other = $this->customer(); $p = $this->product(); $quote = $this->quote($c);
        $this->place($other, $p, $quote)->assertSessionHasErrors('domain_buy');
        $changed = $quote; $changed['domain'] = 'different-synthetic.com';
        $this->place($c, $p, $changed)->assertSessionHasErrors('domain_buy');
        $c->profiles()->update(['is_default' => false]);
        $this->place($c, $p, $quote)->assertSessionHasErrors('domain_buy');
        DomainQuote::findOrFail($quote['quote_id'])->update(['honour_until' => now()->subSecond()]);
        $this->place($c, $p, $quote)->assertSessionHasErrors('domain_buy');
        $this->assertDatabaseCount('services', 0); $this->assertDatabaseCount('invoices', 0); $this->assertDatabaseCount('domains', 0);
        $this->assertSame([], $this->submissions);
    }

    public function test_registered_name_cannot_be_ordered_and_having_a_domain_does_not_buy_one(): void
    {
        $c = $this->customer(); $p = $this->product(); $this->taken = true;
        $this->actingAs($c, 'customer')->postJson('/account/order-domain-quote', ['domain' => 'taken-synthetic.com'])->assertUnprocessable();
        $this->actingAs($c, 'customer')->post('/account/order/'.$p->slug,
            ['country' => 'DE', 'cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'owned-synthetic.com'])->assertSessionHasNoErrors();
        $this->assertNull(Invoice::firstOrFail()->domain_id); $this->assertDatabaseCount('domains', 0);
        $this->assertSame([], $this->submissions);
    }

    public function test_status_remains_available_when_joker_is_healthy_and_openprovider_is_not(): void
    {
        $this->accounts['openprovider']->update(['connection_status' => 'failed']);
        $this->getJson('/api/domains/status')->assertOk()->assertJsonPath('configured', true)->assertJsonPath('connected', true);
        $this->accounts['joker']->update(['connection_status' => 'failed']);
        $this->getJson('/api/domains/status')->assertOk()->assertJsonPath('connected', false);
        Http::assertNothingSent();
    }
}
