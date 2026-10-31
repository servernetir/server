<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainSupplierAccount;
use App\Models\Setting;
use App\Services\Domain\DomainProcurement;
use App\Services\Domain\Pricing\DomainProcurementException;
use App\Services\Domain\Providers\DomainProvider;
use App\Services\Domain\Providers\DomainProviderFactory;
use App\Services\Domain\Providers\JokerAdapter;
use App\Services\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainProcurementTest extends TestCase
{
    use RefreshDatabase;

    private DomainProcurement $procurement;
    private array $remote = [];
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['domain_suppliers.enabled' => true, 'domain_reseller.min_margin_pct' => 0,
            'domain_suppliers.fx_max_age_seconds' => 172800, 'domain_suppliers.health_max_age_seconds' => 300]);
        Setting::put('domain_margin_pct', '1');
        foreach (['IRT' => 0, 'EUR' => 2, 'USD' => 2] as $currency => $exponent) {
            Currency::updateOrCreate(['code' => $currency], ['exponent' => $exponent,
                'rounding_step' => $currency === 'IRT' ? 1000 : 1, 'is_active' => true, 'is_base' => $currency === 'IRT']);
        }
        DomainSupplierAccount::query()->update(['active' => false]);
        $fx = \Mockery::mock(ExchangeRate::class);
        $fx->shouldReceive('current')->andReturnUsing(fn ($currency) => [
            'currency' => $currency, 'rate_toman' => $currency === 'EUR' ? 200000 : 100000,
            'at' => now()->toIso8601String(), 'source_at' => now()->toIso8601String(), 'source' => 'navasan.net',
        ]);
        $factory = \Mockery::mock(DomainProviderFactory::class);
        $factory->shouldReceive('forAccount')->andReturnUsing(function ($account) {
            $id = $account->id;
            $provider = \Mockery::mock(DomainProvider::class);
            $provider->shouldReceive('quote')->andReturnUsing(function ($fqdn, $operation, $months, $options) use ($id) {
                $this->calls[] = [$id, $fqdn, $operation, $months, $options];
                if (($this->remote[$id]['throw'] ?? false)) throw new \RuntimeException('private upstream credential detail');
                return array_merge([
                    'available' => true, 'amount_minor' => 1000, 'currency' => 'EUR',
                    'period_months' => $months, 'premium' => false, 'tax_included' => true,
                    'components' => ['subtotal_minor' => 1000, 'tax_minor' => 0, 'privacy_minor' => 0],
                    'terms_hash' => 'same-terms',
                ], $this->remote[$id] ?? []);
            });
            $provider->shouldReceive('probe')->andReturnUsing(fn () => [
                'healthy' => true, 'balance_minor' => $this->remote[$id]['balance_minor'] ?? 100000,
                'currency' => $this->remote[$id]['currency'] ?? 'EUR',
            ]);
            $provider->shouldReceive('find')->andReturnUsing(fn () => [
                'found' => $this->remote[$id]['found'] ?? true, 'object_id' => 'real-object-'.$id,
                'expires_at' => $this->remote[$id]['expires_at'] ?? now()->addYear()->startOfDay()->toIso8601String(),
            ]);
            return $provider;
        });
        $this->procurement = new DomainProcurement($factory, $fx);
        $this->app->instance(DomainProcurement::class, $this->procurement);
    }

    private function account(string $provider = 'openprovider', array $overrides = []): DomainSupplierAccount
    {
        return DomainSupplierAccount::create(array_merge([
            'provider' => $provider, 'label' => 'Synthetic '.$provider, 'environment' => 'production',
            'active' => true, 'sales_enabled' => true, 'contract_verified' => true,
            'identity_verified' => true, 'pricing_verified' => true, 'connection_status' => 'healthy',
            'health_checked_at' => now(), 'balance_minor' => 100000, 'balance_currency' => 'EUR',
            'credentials' => ['api_key' => 'synthetic-only'], 'settings' => [],
            'capabilities' => ['tlds' => ['com' => [
                'operations' => ['create', 'renew', 'transfer'], 'period_months' => [12, 24],
                'idn' => true, 'premium' => true, 'privacy' => ['none'], 'transfer_result_months' => 12,
                'management' => ['nameservers', 'lock', 'contacts'],
            ]]],
        ], $overrides));
    }

    private function domain(DomainSupplierAccount $account): Domain
    {
        $customer = Customer::create(['code' => 'SN-TEST-'.random_int(100000, 999999),
            'email' => uniqid().'@example.test', 'password' => 'synthetic', 'status' => 'active']);
        return Domain::create(['customer_id' => $customer->id, 'domain' => 'example.com',
            'sld' => 'example', 'tld' => 'com', 'registrar' => $account->provider, 'status' => 'active',
            'supplier_account_id' => $account->id, 'supplier_object_id' => 'real-object-'.$account->id,
            'expires_at' => now()->addYear()->startOfDay()]);
    }

    private function assertReason(string $reason, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected procurement rejection '.$reason);
        } catch (DomainProcurementException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertStringNotContainsString('private upstream', $e->getMessage());
        }
    }

    public function test_compares_actual_discounted_full_term_cost_across_currencies_and_records_evidence(): void
    {
        $op = $this->account();
        $joker = $this->account('joker');
        $this->remote[$op->id] = ['amount_minor' => 1500]; // 15 EUR = 3m IRT
        $this->remote[$joker->id] = ['amount_minor' => 2500, 'currency' => 'USD',
            'components' => ['list_minor' => 5000, 'discount_minor' => 2500, 'tax_minor' => 0]]; // 2.5m
        $quote = $this->procurement->quote('example.com', 'create', 24);
        $this->assertSame($joker->id, $quote->supplier_account_id);
        $this->assertSame(24, (int) $quote->period_months);
        $this->assertSame(2525000, (int) $quote->sell_toman);
        $this->assertSame('lowest_eligible_landed_cost', $quote->selection_reason);
        $this->assertSame('navasan.net', $quote->fx_snapshot['source']);
        $this->assertSame(2500, $quote->cost_components['discount_minor']);
        $this->assertFalse(array_key_exists('supplier_account_id', $quote->toArray()));
        $this->assertFalse(array_key_exists('cost_components', $quote->toArray()));
    }

    public function test_supplier_tax_already_included_is_never_added_twice(): void
    {
        Setting::put('domain_supplier_vat_pct', '20');
        config(['domain_pricing.supplier_vat_pct' => 20]);
        $a = $this->account('joker');
        $this->remote[$a->id] = ['amount_minor' => 1200,
            'components' => ['subtotal_minor' => 1000, 'tax_minor' => 200]];
        $quote = $this->procurement->quote('example.com', 'create', 12);
        $this->assertSame(1200, (int) $quote->purchase_minor);
        $this->assertSame(2424000, (int) $quote->sell_toman);
    }

    public function test_real_joker_funding_fee_changes_the_cheapest_retail_offer_but_not_the_required_provider_balance(): void
    {
        $op = $this->account('openprovider', ['balance_currency' => 'USD']);
        $joker = $this->account('joker', ['balance_currency' => 'USD', 'balance_minor' => 1000,
            'settings' => ['supplier_fee_pct' => 10]]);
        $openProvider = \Mockery::mock(DomainProvider::class);
        $openProvider->shouldReceive('quote')->andReturn([
            'available' => true, 'amount_minor' => 1050, 'currency' => 'USD', 'period_months' => 12,
            'premium' => false, 'tax_included' => true, 'components' => ['subtotal_minor' => 1050],
            'terms_hash' => 'synthetic-openprovider-terms',
        ]);
        $openProvider->shouldReceive('probe')->andReturn(['healthy' => true, 'balance_minor' => 100000, 'currency' => 'USD']);
        $factory = \Mockery::mock(DomainProviderFactory::class);
        $factory->shouldReceive('forAccount')->andReturnUsing(fn ($account) => $account->provider === 'joker'
            ? new JokerAdapter($account) : $openProvider);
        $fx = \Mockery::mock(ExchangeRate::class);
        $fx->shouldReceive('current')->with('USD')->andReturnUsing(fn () => [
            'currency' => 'USD', 'rate_toman' => 100000, 'at' => now()->toIso8601String(),
            'source_at' => now()->toIso8601String(), 'source' => 'navasan.net',
        ]);
        $procurement = new DomainProcurement($factory, $fx);
        $reply = fn ($body) => Http::response("Status-Code: 1000\nStatus-Text: OK\n\n".$body, 200);
        Http::fake([
            '*/login' => Http::response("Status-Code: 1000\nAuth-SID: synthetic-joker-session\n\n", 200),
            '*/domain-check' => $reply("domain-status: available\ndomain-class: standard\ndomain-price-create: 10.00 USD 1y\n"),
            '*/query-account-balance' => Http::response("Status-Code: 1000\nColumns: available,pending,total,currency\nSeparator: TAB\n\n10.00\t0.00\t10.00\tUSD\n", 200),
        ]);

        $quote = $procurement->quote('example.com', 'create', 12);
        $this->assertSame($op->id, $quote->supplier_account_id); // 10.50 < Joker's landed 11.00
        $this->assertSame(1061000, (int) $quote->sell_toman); // current 1% margin, rounded to 1000 IRT
        $this->assertSame([], $quote->raw['excluded']); // Joker's 10.00 spendable balance covers its debit.

        $op->update(['active' => false]);
        $jokerOnly = $procurement->quote('example.com', 'create', 12);
        $this->assertSame($joker->id, $jokerOnly->supplier_account_id);
        $this->assertSame(1100, (int) $jokerOnly->purchase_minor);
        $this->assertSame(1000, $jokerOnly->raw['supplier_quote']['provider_debit_minor']);
        $this->assertSame(100, $jokerOnly->cost_components['supplier_fee_minor']);
        $this->assertSame(1111000, (int) $jokerOnly->sell_toman);

        $op->update(['active' => true]);
        $joker->update(['settings' => ['supplier_fee_pct' => 0]]);
        $withoutFundingFee = $procurement->quote('example.com', 'create', 12);
        $this->assertSame($joker->id, $withoutFundingFee->supplier_account_id);
        $this->assertSame(1010000, (int) $withoutFundingFee->sell_toman);
    }

    public function test_real_openprovider_balance_covers_actual_debit_while_fees_and_tax_reserve_affect_selection(): void
    {
        $op = $this->account('openprovider', ['balance_currency' => 'USD', 'balance_minor' => 1100,
            'credentials' => ['username' => 'synthetic-op', 'password' => 'synthetic-only'],
            'settings' => ['supplier_vat_pct' => 20, 'supplier_fee_pct' => 10]]);
        $this->account('joker', ['balance_currency' => 'USD']);
        $jokerProvider = \Mockery::mock(DomainProvider::class);
        $jokerProvider->shouldReceive('quote')->andReturn([
            'available' => true, 'amount_minor' => 1400, 'provider_debit_minor' => 1400, 'currency' => 'USD',
            'period_months' => 12, 'premium' => false, 'tax_included' => true,
            'components' => ['final_account_domain_minor' => 1400], 'terms_hash' => 'synthetic-joker-terms',
        ]);
        $jokerProvider->shouldReceive('probe')->andReturn(['healthy' => true, 'balance_minor' => 100000, 'currency' => 'USD']);
        $realFactory = new DomainProviderFactory;
        $factory = \Mockery::mock(DomainProviderFactory::class);
        $factory->shouldReceive('forAccount')->andReturnUsing(fn ($account) => $account->provider === 'openprovider'
            ? $realFactory->forAccount($account) : $jokerProvider);
        $fx = \Mockery::mock(ExchangeRate::class);
        $fx->shouldReceive('current')->with('USD')->andReturnUsing(fn () => [
            'currency' => 'USD', 'rate_toman' => 100000, 'at' => now()->toIso8601String(),
            'source_at' => now()->toIso8601String(), 'source' => 'navasan.net',
        ]);
        Http::fake(['*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'synthetic-op-token']]),
            '*/domains/check' => Http::response(['code' => 0, 'data' => ['results' => [['status' => 'free']]]]),
            '*/domains/prices*' => Http::response(['code' => 0, 'data' => ['is_premium' => false,
                'price' => ['reseller' => ['price' => '10.00', 'currency' => 'USD']]]]),
            '*/resellers*' => Http::response(['code' => 0, 'data' => ['vatperc' => '10', 'balance' => '11.00', 'currency' => 'USD']])]);
        $quote = (new DomainProcurement($factory, $fx))->quote('example.com', 'create', 12);
        $this->assertSame($op->id, $quote->supplier_account_id); // landed 13.20 < 14.00
        $this->assertSame([], $quote->raw['excluded']); // balance 11.00 covers its actual wallet debit
        $this->assertSame(1100, $quote->raw['supplier_quote']['provider_debit_minor']);
        $this->assertSame(1320, (int) $quote->purchase_minor);
        $this->assertSame(100, $quote->cost_components['actual_supplier_tax_minor']);
        $this->assertSame(100, $quote->cost_components['supplier_tax_reserve_minor']);
        $this->assertSame(1334000, (int) $quote->sell_toman);
    }

    public function test_revalidation_rejects_a_higher_wallet_debit_hidden_by_unchanged_landed_cost(): void
    {
        $account = $this->account();
        $this->remote[$account->id] = ['amount_minor' => 1200, 'provider_debit_minor' => 1000];
        $quote = $this->procurement->quote('example.com', 'create', 12);
        $this->remote[$account->id]['provider_debit_minor'] = 1100;
        $this->assertReason('supplier_quote_changed', fn () => $this->procurement->revalidate($quote));
    }

    public function test_a_wrong_period_promotion_is_disqualified(): void
    {
        $cheap = $this->account('joker');
        $valid = $this->account();
        $this->remote[$cheap->id] = ['amount_minor' => 100, 'period_months' => 12];
        $this->remote[$valid->id] = ['amount_minor' => 3000];
        $quote = $this->procurement->quote('example.com', 'create', 24);
        $this->assertSame($valid->id, $quote->supplier_account_id);
        $this->assertSame('supplier_quote_invalid', $quote->raw['excluded'][0]['reason']);
    }

    public function test_insufficient_balance_or_unready_test_accounts_cannot_win(): void
    {
        $poor = $this->account('joker');
        $ote = $this->account('joker', ['environment' => 'ote']);
        $unverified = $this->account('joker', ['identity_verified' => false]);
        $ready = $this->account();
        $this->remote[$poor->id] = ['amount_minor' => 100, 'balance_minor' => 99];
        $this->remote[$ready->id] = ['amount_minor' => 2000];
        $quote = $this->procurement->quote('example.com', 'create', 12);
        $this->assertSame($ready->id, $quote->supplier_account_id);
        $this->assertNotContains($ote->id, array_column($this->calls, 0));
        $this->assertNotContains($unverified->id, array_column($this->calls, 0));
    }

    public function test_missing_capabilities_and_unsupported_premium_fail_closed(): void
    {
        $a = $this->account();
        $caps = $a->capabilities;
        $caps['tlds']['com']['premium'] = false;
        $a->update(['capabilities' => $caps]);
        $this->remote[$a->id] = ['premium' => true];
        $this->assertReason('no_eligible_supplier', fn () => $this->procurement->quote('example.com', 'create', 12));
        $a->update(['capabilities' => []]);
        $this->assertReason('no_eligible_supplier', fn () => $this->procurement->quote('example.com', 'create', 12));
    }

    public function test_auth_or_price_failure_is_unchecked_never_taken(): void
    {
        $a = $this->account('joker');
        $this->remote[$a->id] = ['throw' => true];
        $this->assertReason('no_eligible_supplier', fn () => $this->procurement->quote('example.com', 'create', 12));
        $rows = app(\App\Services\Domain\DomainSearch::class)->search('example.com', ['com']);
        $this->assertSame('unchecked', $rows[0]['state']);
        $this->assertFalse($rows[0]['orderable']);
    }

    public function test_existing_renewal_is_pinned_to_real_account_even_when_another_is_cheaper(): void
    {
        $holding = $this->account('joker', ['sales_enabled' => false]);
        $other = $this->account();
        $this->remote[$holding->id] = ['amount_minor' => 2000];
        $this->remote[$other->id] = ['amount_minor' => 1];
        $quote = $this->procurement->quoteForDomain($this->domain($holding), 'renew', 12);
        $this->assertSame($holding->id, $quote->supplier_account_id);
        $this->assertSame('bound_supplier_account', $quote->selection_reason);
        $this->assertSame([$holding->id], array_column($this->calls, 0));
    }

    public function test_two_accounts_at_the_same_registrar_never_share_a_renewal_route(): void
    {
        $holding = $this->account('joker');
        $cheaper = $this->account('joker');
        $this->remote[$holding->id] = ['amount_minor' => 2000];
        $this->remote[$cheaper->id] = ['amount_minor' => 100];
        $domain = $this->domain($holding);
        $quote = $this->procurement->quoteForDomain($domain, 'renew', 12);
        $this->assertSame($holding->id, $quote->supplier_account_id);
        $this->assertSame([$holding->id], array_column($this->calls, 0));
    }

    public function test_imported_privacy_is_not_silently_removed_when_renewing(): void
    {
        $a = $this->account();
        $domain = $this->domain($a);
        $domain->update(['whois_privacy' => true]);
        $this->assertReason('privacy_selection_required', fn () => $this->procurement->quoteForDomain($domain, 'renew', 12));
        $this->assertSame([], $this->calls);
    }

    public function test_customer_quote_is_bound_at_creation_without_ambient_authentication(): void
    {
        $this->account();
        $customer = Customer::create(['email' => 'quoteowner@example.test', 'password' => 'synthetic', 'status' => 'active']);
        $quote = $this->procurement->quote('example.com', 'create', 12, [], null, $customer->id);
        $this->assertSame($customer->id, (int) $quote->customer_id);
        $this->assertFalse($quote->claimFor($customer->id + 1));
    }

    public function test_renewal_quote_freezes_the_original_expiration_and_rejects_an_already_extended_domain(): void
    {
        $account = $this->account('joker');
        $domain = $this->domain($account);
        $prior = $domain->expires_at->copy();
        $quote = $this->procurement->quoteForDomain($domain, 'renew', 12);
        $snapshot = $this->procurement->snapshot($quote);
        $this->assertSame($prior->toIso8601String(), $snapshot['prior_expires_at']);
        $this->assertSame($prior->copy()->addYear()->toIso8601String(), $snapshot['target_expires_at']);
        $this->assertSame($domain->customer_id, (int) $quote->customer_id);
        $domain->update(['supplier_quote' => $snapshot, 'expires_at' => $prior->copy()->addYear()]);
        $this->assertReason('renewal_expiry_changed', fn () => $this->procurement->revalidateDomain($domain, 'renew', 12));
    }

    public function test_transfer_uses_only_explicit_destination_and_records_resulting_term_without_existing_object(): void
    {
        $destination = $this->account('joker');
        $cheaper = $this->account();
        $this->remote[$destination->id] = ['found' => false, 'available' => false, 'amount_minor' => 2000];
        $this->remote[$cheaper->id] = ['found' => false, 'available' => false, 'amount_minor' => 1];
        $quote = $this->procurement->quoteTransfer('external.com', 12, $destination);
        $this->assertSame($destination->id, $quote->supplier_account_id);
        $this->assertSame('explicit_transfer_destination', $quote->selection_reason);
        $snapshot = $this->procurement->snapshot($quote);
        $this->assertSame(12, $snapshot['resulting_period_months']);
        $this->assertTrue($snapshot['incoming_transfer']);
        $this->assertSame([$destination->id], array_column($this->calls, 0));
        $this->assertSame($quote->id, $this->procurement->revalidate($quote)->id);
    }

    public function test_transfer_resulting_period_comes_from_reviewed_tld_evidence_and_is_not_assumed_one_year(): void
    {
        $destination = $this->account('joker');
        $caps = $destination->capabilities;
        $caps['tlds']['com']['transfer_result_months'] = 0; // Synthetic no-extension registry rule.
        $destination->update(['capabilities' => $caps]);
        $this->remote[$destination->id] = ['found' => false, 'available' => false];
        $quote = $this->procurement->quoteTransfer('external.com', 12, $destination);
        $this->assertSame(12, (int) $quote->period_months); // Quoted charge period.
        $this->assertSame(0, $this->procurement->snapshot($quote)['resulting_period_months']);
    }

    public function test_free_or_already_managed_domains_do_not_become_incoming_transfers(): void
    {
        $destination = $this->account('joker');
        $this->remote[$destination->id] = ['found' => false, 'available' => true];
        $this->assertReason('transfer_source_not_registered', fn () => $this->procurement->quoteTransfer('external.com', 12, $destination));
        $this->remote[$destination->id] = ['found' => true, 'available' => false];
        $this->assertReason('transfer_destination_portfolio_unverified', fn () => $this->procurement->quoteTransfer('external.com', 12, $destination));
        $this->domain($destination);
        $this->assertReason('existing_domain_transfer_requires_review', fn () => $this->procurement->quoteTransfer('example.com', 12, $destination));
    }

    public function test_transfer_cannot_be_selected_implicitly_or_without_verified_resulting_period(): void
    {
        $destination = $this->account('joker');
        $this->assertReason('explicit_transfer_destination_required', fn () => $this->procurement->quote('external.com', 'transfer', 12));
        $caps = $destination->capabilities;
        unset($caps['tlds']['com']['transfer_result_months']);
        $destination->update(['capabilities' => $caps]);
        $this->remote[$destination->id] = ['found' => false, 'available' => false];
        $this->assertReason('transfer_result_period_unverified', fn () => $this->procurement->quoteTransfer('external.com', 12, $destination));
    }

    public function test_unbound_renewal_cannot_look_up_openprovider_as_a_fallback(): void
    {
        $this->account();
        $this->assertReason('supplier_binding_required', fn () => $this->procurement->quote('example.com', 'renew', 12));
        $this->assertSame([], $this->calls);
    }

    public function test_expired_quote_and_changed_price_or_conditions_need_new_customer_decision(): void
    {
        $a = $this->account();
        $quote = $this->procurement->quote('example.com', 'create', 12);
        $this->remote[$a->id] = ['amount_minor' => 1001];
        $this->assertReason('supplier_quote_changed', fn () => $this->procurement->revalidate($quote));
        $this->remote[$a->id] = ['terms_hash' => 'changed-conditions'];
        $this->assertReason('supplier_quote_changed', fn () => $this->procurement->revalidate($quote));
        $this->remote[$a->id] = [];
        $quote->update(['honour_until' => now()->subSecond()]);
        $this->assertReason('supplier_quote_expired', fn () => $this->procurement->revalidate($quote));
    }

    public function test_renewal_quote_needs_account_access_not_just_registrar_name(): void
    {
        $a = $this->account('joker');
        $domain = $this->domain($a);
        $domain->update(['supplier_object_id' => 'unverified-object']);
        $this->assertReason('no_eligible_supplier', fn () => $this->procurement->quoteForDomain($domain, 'renew', 12));
    }

    public function test_idn_language_is_preserved_in_the_immutable_quote_terms(): void
    {
        $a = $this->account();
        $quote = $this->procurement->quote('مثال.com', 'create', 12, ['language' => 'fas']);
        $this->assertStringContainsString('xn--', $quote->domain);
        $this->assertSame('fas', $this->procurement->snapshot($quote)['options']['language']);
        $this->assertSame('fas', $this->calls[0][4]['language']);
    }

    public function test_reseller_quote_floor_uses_landed_snapshot_without_taxing_it_again(): void
    {
        $a = $this->account('joker');
        Setting::put('domain_supplier_vat_pct', '20');
        $this->remote[$a->id] = ['amount_minor' => 1200];
        $quote = $this->procurement->quote('example.com', 'create', 12);
        $program = \Mockery::mock(\App\Services\Domain\Reseller\ResellerProgram::class);
        $program->shouldReceive('isReseller')->andReturn(true);
        $program->shouldReceive('discountPct')->andReturn(10.0);
        $customer = Customer::create(['code' => 'SN-RESELLER-TEST', 'email' => 'reseller@example.test',
            'password' => 'synthetic', 'status' => 'active']);
        $pricing = new \App\Services\Domain\Reseller\ResellerPricing($program,
            app(\App\Services\Domain\DomainSearch::class));
        $result = $pricing->forQuote($quote, $customer);
        $this->assertSame(2400000, $result['floor']);
        $this->assertSame(2400000, $result['price']);
    }

    public function test_invoice_expiry_is_rejected_before_payment_or_delivery_without_repricing(): void
    {
        $a = $this->account('joker');
        $domain = $this->domain($a);
        $domain->update(['status' => 'pending', 'period_years' => 1]);
        $quote = $this->procurement->quote('example.com', 'create', 12);
        $quote->claimFor($domain->customer_id);
        $snapshot = $this->procurement->snapshot($quote);
        $domain->update(['supplier_quote' => $snapshot]);
        $invoice = \App\Models\Invoice::create(['number' => 'DPS-EXPIRED-TEST',
            'customer_id' => $domain->customer_id, 'domain_id' => $domain->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'status' => 'unpaid', 'subtotal' => $quote->sell_toman,
            'tax' => 0, 'total' => $quote->sell_toman, 'paid' => 0,
            'supplier_account_id' => $a->id, 'supplier_quote' => $snapshot]);
        $domain->update(['invoice_id' => $invoice->id]);
        $quote->update(['honour_until' => now()->subSecond()]);
        $pricing = app(\App\Services\Domain\LiveDomainPricing::class);
        foreach (['reviewInvoice', 'assertInvoice'] as $method) {
            try {
                $pricing->{$method}($invoice);
                $this->fail('Expired quote was accepted by '.$method);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertSame(__('domain-pricing.changed'), $e->errors()['domain'][0]);
            }
        }
        $this->assertSame((int) $quote->sell_toman, (int) $invoice->fresh()->total);
        $invoice->update(['status' => 'paid', 'paid' => $invoice->total]);
        try {
            $pricing->assertDelivery($domain, 'create', 1);
            $this->fail('Expired paid quote was sent for delivery');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame(__('domain-pricing.changed'), $e->errors()['domain'][0]);
        }
    }

    public function test_stale_exchange_rate_cannot_create_an_orderable_quote(): void
    {
        $this->account();
        $fx = \Mockery::mock(ExchangeRate::class);
        $fx->shouldReceive('current')->andReturn(['rate_toman' => 200000,
            'at' => now()->subDays(3)->toIso8601String(), 'source_at' => now()->subDays(3)->toIso8601String()]);
        $factory = \Mockery::mock(DomainProviderFactory::class);
        $provider = \Mockery::mock(DomainProvider::class);
        $provider->shouldReceive('quote')->andReturn(['available' => true, 'amount_minor' => 1000,
            'currency' => 'EUR', 'period_months' => 12, 'premium' => false, 'tax_included' => true, 'components' => []]);
        $factory->shouldReceive('forAccount')->andReturn($provider);
        $service = new DomainProcurement($factory, $fx);
        $this->assertReason('no_eligible_supplier', fn () => $service->quote('example.com', 'create', 12));
    }
}
