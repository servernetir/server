<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\DomainSupplierAccount;
use App\Models\DomainSupplierContact;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Domain\DomainProcurement;
use App\Services\Domain\Providers\DomainProvider;
use App\Services\Domain\Providers\DomainProviderFactory;
use App\Services\Domain\TldGate;
use App\Services\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainSupplierCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private DomainSupplierAccount $account;
    private int $priceIncrease = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        config(['domain_suppliers.enabled' => true, 'domain_reseller.min_margin_pct' => 0,
            'services.openprovider.username' => 'synthetic-only', 'services.openprovider.password' => 'synthetic-only',
            'services.openprovider.registrant' => [],
            'services.openprovider.nameservers' => ['ns1.external.example', 'ns2.external.example']]);
        TldGate::clear('com');
        Setting::put('domain_margin_pct', '1');
        foreach (['IRT' => 0, 'EUR' => 2] as $currency => $exponent) {
            Currency::updateOrCreate(['code' => $currency], ['exponent' => $exponent,
                'rounding_step' => $currency === 'IRT' ? 1000 : 1, 'is_active' => true, 'is_base' => $currency === 'IRT']);
        }
        DomainSupplierAccount::query()->update(['active' => false]);
        $this->account = DomainSupplierAccount::create([
            'provider' => 'joker', 'label' => 'Synthetic Joker', 'environment' => 'production',
            'active' => true, 'sales_enabled' => true, 'contract_verified' => true,
            'identity_verified' => true, 'pricing_verified' => true, 'connection_status' => 'healthy',
            'health_checked_at' => now(), 'balance_minor' => 100000, 'balance_currency' => 'EUR',
            'credentials' => ['api_key' => 'synthetic-key'],
            'capabilities' => ['tlds' => ['com' => [
                'operations' => ['create', 'renew', 'transfer'], 'period_months' => [12, 24], 'transfer_result_months' => 12,
                'idn' => true, 'premium' => true, 'privacy' => ['none'],
                'management' => ['nameservers', 'lock', 'contacts'],
            ]]],
        ]);
        $fx = \Mockery::mock(ExchangeRate::class);
        $fx->shouldReceive('current')->andReturnUsing(fn ($currency) => [
            'currency' => $currency, 'rate_toman' => 200000,
            'at' => now()->toIso8601String(), 'source_at' => now()->toIso8601String(), 'source' => 'synthetic-fixture',
        ]);
        $provider = \Mockery::mock(DomainProvider::class);
        $provider->shouldReceive('quote')->andReturnUsing(fn ($fqdn, $operation, $months, $options) => [
            'available' => $operation === 'transfer' ? false : true, 'amount_minor' => intdiv($months, 12) * 1000 + $this->priceIncrease,
            'currency' => 'EUR', 'period_months' => $months, 'premium' => false, 'tax_included' => true,
            'components' => ['account_price_minor' => intdiv($months, 12) * 1000 + $this->priceIncrease],
            'terms_hash' => 'same-synthetic-terms',
        ]);
        $provider->shouldReceive('find')->andReturn(['found' => false, 'object_id' => null]);
        $provider->shouldReceive('probe')->andReturn(['healthy' => true, 'balance_minor' => 100000, 'currency' => 'EUR']);
        $provider->shouldNotReceive('submit');
        $provider->shouldNotReceive('manage');
        $factory = \Mockery::mock(DomainProviderFactory::class);
        $factory->shouldReceive('forAccount')->withArgs(fn ($a) => $a->id === $this->account->id)->andReturn($provider);
        $this->app->instance(DomainProviderFactory::class, $factory);
        $this->app->instance(ExchangeRate::class, $fx);
    }

    private function customer(bool $reseller = false): Customer
    {
        $c = Customer::create(['email' => uniqid('checkout-').'@example.test', 'password' => bcrypt('synthetic'),
            'status' => 'active', 'locale' => 'en']);
        $profile = CustomerProfile::create(['customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'first_name' => 'Synthetic', 'last_name' => 'Customer', 'email' => $c->email, 'country' => 'US',
            'city' => 'Test City', 'address' => 'Synthetic address', 'postal_code' => '00000', 'mobile' => '+12025550100']);
        DomainSupplierContact::create(['customer_profile_id' => $profile->id, 'supplier_account_id' => $this->account->id,
            'role' => 'registrant', 'handle' => 'C-SYN-'.$profile->id, 'status' => 'active', 'verified_at' => now()]);
        if ($reseller) $c->forceFill(['is_reseller' => true, 'reseller_level' => 'starter'])->save();
        return $c;
    }

    private function quote(): DomainQuote
    {
        return app(DomainProcurement::class)->quote('checkout-synthetic.com', 'create', 12);
    }

    private function order(Customer $c, DomainQuote $quote, int $years = 1)
    {
        return $this->actingAs($c, 'customer')->from('/account/domains')
            ->post('/account/domains/order', ['quote_id' => $quote->id, 'years' => $years]);
    }

    public function test_panel_order_preserves_exact_account_quote_and_unpaid_delivery_gate(): void
    {
        $c = $this->customer();
        $quote = $this->quote();
        $this->order($c, $quote)->assertSessionHasNoErrors();
        $domain = Domain::firstOrFail();
        $invoice = Invoice::firstOrFail();
        $this->assertSame($this->account->id, $domain->supplier_account_id);
        $this->assertSame('joker', $domain->registrar);
        $this->assertSame($quote->id, $domain->quote_id);
        $this->assertNull($domain->supplier_object_id);
        $this->assertNull($domain->op_id);
        $this->assertSame('none', $domain->provision_status);
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame(0, $invoice->paid);
        $this->assertSame($this->account->id, $invoice->supplier_account_id);
        $this->assertSame(12, $invoice->supplier_quote['period_months']);
        $this->assertSame($quote->id, $invoice->supplier_quote['quote_id']);
        $this->assertSame($quote->sell_toman, $invoice->subtotal);
        $this->assertSame($invoice->id, $domain->invoice_id);
        $this->assertArrayNotHasKey('supplier_quote', $invoice->toArray());
        Http::assertNothingSent();
    }

    public function test_term_change_returns_a_repriced_checkout_before_creating_any_invoice(): void
    {
        $c = $this->customer();
        $quote = $this->quote();
        $response = $this->order($c, $quote, 2);
        $replacement = DomainQuote::latest('id')->firstOrFail();
        $this->assertNotSame($quote->id, $replacement->id);
        $this->assertSame(24, $replacement->period_months);
        $this->assertSame(4040000, $replacement->sell_toman);
        $response->assertRedirectContains('/account/domains/checkout/'.$replacement->id);
        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
        Http::assertNothingSent();
    }

    public function test_price_increase_requires_a_new_decision_and_cannot_create_an_invoice(): void
    {
        $c = $this->customer();
        $quote = $this->quote();
        $this->priceIncrease = 1;
        $this->order($c, $quote)->assertSessionHasErrors();
        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
        Http::assertNothingSent();
    }

    public function test_duplicate_order_cannot_create_a_second_domain_or_invoice(): void
    {
        $c = $this->customer();
        $quote = $this->quote();
        $this->order($c, $quote)->assertSessionHasNoErrors();
        $this->order($c, $quote)->assertSessionHasErrors();
        $this->assertSame(1, Domain::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(0, CreditEntry::count());
        Http::assertNothingSent();
    }

    public function test_a_held_fqdn_lock_prevents_a_concurrent_order_from_writing_finance(): void
    {
        $c = $this->customer();
        $quote = $this->quote();
        $lock = Cache::lock(\App\Services\Domain\DomainSupplierLifecycle::domainLockKey($quote->domain), 30);
        $this->assertTrue($lock->get());
        try {
            $this->order($c, $quote)->assertSessionHasErrors();
            $this->assertSame(0, Domain::count());
            $this->assertSame(0, Invoice::count());
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_customer_cannot_order_a_quote_claimed_by_another_customer(): void
    {
        $c = $this->customer();
        $other = $this->customer();
        $quote = $this->quote();
        $this->assertTrue($quote->claimFor($c->id));
        $this->order($other, $quote)->assertSessionHasErrors();
        $this->assertSame($c->id, $quote->fresh()->customer_id);
        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
        Http::assertNothingSent();
    }

    public function test_joker_registrar_text_without_account_evidence_never_creates_an_openprovider_order(): void
    {
        $c = $this->customer();
        $quote = DomainQuote::create(['domain' => 'unbound-synthetic.com', 'tld' => 'com', 'registrar' => 'joker',
            'cost_amount' => 100, 'cost_currency' => 'EUR', 'sell_toman' => 1000,
            'renew_toman' => 1000, 'honour_until' => now()->addMinutes(15)]);
        $this->order($c, $quote)->assertSessionHasErrors();
        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
        Http::assertNothingSent();
    }

    public function test_prefeature_quote_must_show_a_new_account_and_price_before_an_invoice_exists(): void
    {
        $c = $this->customer();
        $legacyQuote = DomainQuote::create(['domain' => 'checkout-synthetic.com', 'tld' => 'com',
            'registrar' => 'openprovider', 'cost_amount' => 100, 'cost_currency' => 'EUR',
            'sell_toman' => 100000, 'renew_toman' => 100000, 'honour_until' => now()->addMinutes(15)]);
        $response = $this->order($c, $legacyQuote);
        $replacement = DomainQuote::whereNotNull('supplier_account_id')->latest('id')->firstOrFail();
        $response->assertRedirectContains('/account/domains/checkout/'.$replacement->id);
        $this->assertSame($this->account->id, $replacement->supplier_account_id);
        $this->assertSame(2020000, $replacement->sell_toman);
        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
        Http::assertNothingSent();
    }

    public function test_unicode_and_punycode_share_the_same_fqdn_lock_and_api_stores_the_canonical_domain(): void
    {
        $c = $this->customer(true);
        CreditEntry::create(['customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => 10000000,
            'balance_after' => 10000000, 'reason' => 'synthetic_topup']);
        [, $token] = CustomerApiToken::issue($c->id, 'synthetic', ['domains:write']);
        $unicode = 'BÜCHER.COM';
        $ascii = 'xn--bcher-kva.com';
        $key = \App\Services\Domain\DomainSupplierLifecycle::domainLockKey($ascii);
        $this->assertSame($key, \App\Services\Domain\DomainSupplierLifecycle::domainLockKey($unicode));
        $lock = Cache::lock($key, 30);
        $this->assertTrue($lock->get());
        try {
            $this->withToken($token)->postJson('/api/v1/domains', ['domain' => $unicode, 'years' => 1])->assertStatus(409);
            $this->assertSame(0, Domain::count());
            $this->assertSame(0, Invoice::count());
        } finally {
            $lock->release();
        }
        $this->withToken($token)->postJson('/api/v1/domains', ['domain' => $unicode, 'years' => 1])->assertCreated();
        $domain = Domain::firstOrFail();
        $this->assertSame($ascii, $domain->domain);
        $this->assertSame('xn--bcher-kva', $domain->sld);
        $this->assertSame('com', $domain->tld);
        Http::assertNothingSent();
    }

    public function test_incoming_transfer_uses_explicit_destination_and_actual_resulting_period_without_remote_mutations(): void
    {
        config(['domain_suppliers.transfer_account_id' => $this->account->id]);
        $caps = $this->account->capabilities;
        // A transfer without an extra renewal year is a distinct contract, never an assumed +12 months.
        $caps['tlds']['de'] = $caps['tlds']['com'];
        $caps['tlds']['de']['transfer_result_months'] = 0;
        $this->account->forceFill(['capabilities' => $caps])->save();
        $c = $this->customer();
        $this->actingAs($c, 'customer')->post('/account/domains/transfer', ['domain' => 'external-synthetic.de'])
            ->assertSessionHasNoErrors();
        $domain = Domain::firstOrFail();
        $invoice = Invoice::firstOrFail();
        $quote = DomainQuote::findOrFail($domain->quote_id);
        $this->assertSame('joker', $domain->registrar);
        $this->assertSame('transfer', $domain->order_type);
        $this->assertSame('transferring', $domain->status);
        $this->assertSame('pending', $domain->transfer_status);
        $this->assertSame('none', $domain->provision_status);
        $this->assertSame($this->account->id, $domain->supplier_account_id);
        $this->assertNull($domain->supplier_object_id);
        $this->assertNull($domain->op_id);
        $this->assertSame($c->id, $quote->customer_id);
        $this->assertSame('explicit_transfer_destination', $quote->selection_reason);
        $this->assertSame('transfer', $invoice->supplier_quote['operation']);
        $this->assertSame(12, $invoice->supplier_quote['period_months']);
        $this->assertSame(0, $invoice->supplier_quote['resulting_period_months']);
        $this->assertSame($quote->id, $invoice->supplier_quote['quote_id']);
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame(0, $invoice->paid);
        $this->assertSame(__('domain-management.transfer_term', ['months' => 0]), $invoice->items()->firstOrFail()->description);
        $this->assertDatabaseCount('domain_supplier_operations', 0);
        $this->assertSame(0, CreditEntry::count());
        Http::assertNothingSent();
    }

    public function test_transfer_without_a_verified_customer_contact_cannot_create_a_paid_or_unpaid_order(): void
    {
        config(['domain_suppliers.transfer_account_id' => $this->account->id]);
        $c = $this->customer();
        DomainSupplierContact::query()->delete();
        $this->actingAs($c, 'customer')->post('/account/domains/transfer', ['domain' => 'external-synthetic.com'])
            ->assertSessionHasErrors();
        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, CreditEntry::count());
        Http::assertNothingSent();
    }

    public function test_transfer_request_cannot_automatically_move_an_existing_local_domain_to_another_account(): void
    {
        config(['domain_suppliers.transfer_account_id' => $this->account->id]);
        $c = $this->customer();
        $holding = DomainSupplierAccount::where('legacy_config', true)->firstOrFail();
        $domain = Domain::create(['customer_id' => $c->id, 'domain' => 'local-synthetic.com',
            'sld' => 'local-synthetic', 'tld' => 'com', 'registrar' => 'openprovider', 'status' => 'active',
            'provision_status' => 'done', 'supplier_account_id' => $holding->id, 'supplier_object_id' => '777', 'op_id' => 777]);
        $this->actingAs($c, 'customer')->post('/account/domains/transfer', ['domain' => 'local-synthetic.com'])
            ->assertSessionHasErrors();
        $this->assertSame(1, Domain::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame('openprovider', $domain->fresh()->registrar);
        $this->assertSame($holding->id, $domain->fresh()->supplier_account_id);
        $this->assertSame('777', $domain->fresh()->supplier_object_id);
        $this->assertSame('active', $domain->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_authenticated_search_quotes_are_bound_to_customer_before_the_row_is_returned(): void
    {
        $c = $this->customer();
        $other = $this->customer();
        $rows = app(\App\Services\Domain\DomainSearch::class)->search('checkout-synthetic.com', ['com'], $c->id);
        $quote = DomainQuote::findOrFail($rows[0]['quote_id']);
        $this->assertSame($c->id, $quote->customer_id);
        $this->assertFalse($quote->claimFor($other->id));
        $this->order($other, $quote)->assertSessionHasErrors();
        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
        $this->actingAs($c, 'customer')->get('/account/domains?register=panel-synthetic.com')->assertOk();
        $panelQuote = DomainQuote::where('domain', 'panel-synthetic.com')->latest('id')->firstOrFail();
        $this->assertSame($c->id, $panelQuote->customer_id);
        Http::assertNothingSent();
    }

    public function test_unverified_registry_contact_blocks_before_invoice_or_wallet_debit(): void
    {
        $c = $this->customer(true);
        DomainSupplierContact::query()->delete();
        $quote = $this->quote();
        $this->order($c, $quote)->assertSessionHasErrors();
        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
        CreditEntry::create(['customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => 10000000,
            'balance_after' => 10000000, 'reason' => 'synthetic_topup']);
        [, $token] = CustomerApiToken::issue($c->id, 'synthetic', ['domains:write']);
        $this->withToken($token)->postJson('/api/v1/domains', ['domain' => 'checkout-synthetic.com', 'years' => 1])
            ->assertStatus(422);
        $this->assertSame(0, Domain::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, CreditEntry::where('amount', '<', 0)->count());
        Http::assertNothingSent();
    }

    public function test_api_wallet_charge_pins_the_exact_invoice_debit_and_cannot_buy_without_operation_approval(): void
    {
        $c = $this->customer(true);
        CreditEntry::create(['customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => 10000000,
            'balance_after' => 10000000, 'reason' => 'synthetic_topup']);
        [, $token] = CustomerApiToken::issue($c->id, 'synthetic', ['domains:write']);
        $response = $this->withToken($token)->withHeader('Idempotency-Key', 'synthetic-checkout-1')
            ->postJson('/api/v1/domains', ['domain' => 'checkout-synthetic.com', 'years' => 1]);
        $response->assertCreated();
        $invoice = Invoice::firstOrFail();
        $domain = Domain::firstOrFail();
        $debit = CreditEntry::findOrFail($invoice->supplier_payment_ledger_id);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame($invoice->total, $invoice->paid);
        $this->assertSame(-$invoice->total, $debit->amount);
        $this->assertSame('domain_register_api', $debit->reason);
        $this->assertSame($c->id, $debit->customer_id);
        $this->assertSame($domain->getMorphClass(), $debit->source_type);
        $this->assertSame($domain->id, $debit->source_id);
        $this->assertSame($this->account->id, $invoice->supplier_account_id);
        $this->assertSame($domain->quote_id, $invoice->supplier_quote['quote_id']);
        $this->assertSame('pending', $domain->status);
        $this->assertSame('manual', $domain->provision_status);
        $this->assertArrayNotHasKey('supplier_quote', $response->json('data'));
        $this->assertStringNotContainsString('synthetic-key', $response->getContent());
        Http::assertNothingSent();
    }
}
