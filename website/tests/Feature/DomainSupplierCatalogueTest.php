<?php

namespace Tests\Feature;

use App\Models\{Currency, DomainSupplierAccount, Setting};
use App\Services\Domain\{DomainProcurement, DomainSupplierCatalogue, DomainSupplierProbe, OpenProviderClient, TldPriceBook};
use App\Services\Domain\Providers\{DomainProvider, DomainProviderFactory, JokerAdapter, OpenProviderAdapter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Storage};
use Tests\TestCase;

class DomainSupplierCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests(); Storage::fake('local');
        config(['domain_suppliers.enabled' => true, 'domain_reseller.min_margin_pct' => 0]);
        Setting::put('domain_margin_pct', '1');
        foreach (['IRT' => 0, 'USD' => 2] as $code => $exponent) {
            Currency::updateOrCreate(['code' => $code], ['exponent' => $exponent, 'rounding_step' => $code === 'IRT' ? 1000 : 1,
                'is_active' => true, 'is_base' => $code === 'IRT']);
        }
        DomainSupplierAccount::query()->update(['active' => false]);
        $fx = \Mockery::mock(\App\Services\ExchangeRate::class);
        $fx->shouldReceive('current')->andReturnUsing(fn ($code) => ['currency' => $code, 'rate_toman' => 100000,
            'at' => now()->toIso8601String(), 'source_at' => now()->toIso8601String(), 'source' => 'synthetic']);
        $this->app->instance(\App\Services\ExchangeRate::class, $fx);
    }

    private function account(string $provider = 'joker'): DomainSupplierAccount
    {
        $tlds = [];
        foreach (['com', 'org', 'co.uk', 'com.au'] as $tld) $tlds[$tld] = ['operations' => ['create', 'renew', 'transfer'],
            'period_months' => [12, 24], 'privacy' => ['none'], 'idn' => false, 'premium' => false,
            'management' => ['nameservers', 'lock', 'contacts']];
        return DomainSupplierAccount::create(['provider' => $provider, 'environment' => 'production', 'label' => 'Synthetic catalogue',
            'active' => true, 'sales_enabled' => true, 'contract_verified' => true, 'identity_verified' => true,
            'pricing_verified' => true, 'connection_status' => 'healthy', 'health_checked_at' => now(),
            'balance_minor' => 100000, 'balance_currency' => 'USD', 'credentials' => ['api_key' => 'synthetic'],
            'capabilities' => ['tlds' => $tlds]]);
    }

    private function offer(string $tld, int $amount, string $operation = 'create', int $months = 12): array
    {
        return ['tld' => $tld, 'operation' => $operation, 'period_months' => $months, 'currency' => 'USD',
            'amount_minor' => $amount, 'provider_debit_minor' => $amount, 'premium' => false, 'tax_included' => true,
            'valid_until' => now()->addDay()->toIso8601String(), 'components' => []];
    }

    private function factory(array $catalogues): void
    {
        $factory = \Mockery::mock(DomainProviderFactory::class);
        $factory->shouldReceive('forAccount')->andReturnUsing(function ($a) use ($catalogues) {
            $p = \Mockery::mock(DomainProvider::class);
            if ($catalogues[$a->id] === false) $p->shouldReceive('catalogue')->once()->andThrow(new \RuntimeException('synthetic'));
            else $p->shouldReceive('catalogue')->once()->andReturn(['offers' => $catalogues[$a->id],
                'suffixes' => array_values(array_unique(array_column($catalogues[$a->id], 'tld'))), 'source' => 'synthetic']);
            $p->shouldNotReceive('submit'); $p->shouldNotReceive('manage');
            return $p;
        });
        $this->app->instance(DomainProviderFactory::class, $factory);
    }

    public function test_public_price_book_compares_accounts_and_pins_renewal_estimate_to_winner(): void
    {
        $joker = $this->account(); $op = $this->account('openprovider');
        $this->factory([$joker->id => [$this->offer('com', 1000), $this->offer('com', 2000, 'renew'), $this->offer('co.uk', 2000)],
            $op->id => [$this->offer('com', 1200), $this->offer('com', 500, 'renew'), $this->offer('co.uk', 1000)]]);
        app(TldPriceBook::class)->refreshAll();
        $rows = app(TldPriceBook::class)->cachedFullForTlds(['com', 'co.uk']);
        $this->assertSame(1010000, $rows['com']['register']);
        $this->assertSame(2020000, $rows['com']['renew']);
        $this->assertSame(1010000, $rows['co.uk']['register']);
        $this->assertNull($rows['com']['transfer']);
        $this->assertSame(['co.uk', 'com'], app(TldPriceBook::class)->catalogTlds());
        $this->assertStringNotContainsString('account_id', json_encode($rows));
        Http::assertNothingSent();
    }

    public function test_catalogue_never_compares_one_year_with_two_year_totals(): void
    {
        $a = $this->account(); $b = $this->account('openprovider');
        $this->factory([$a->id => [$this->offer('com', 1200)], $b->id => [$this->offer('com', 1000, 'create', 24)]]);
        app(TldPriceBook::class)->refreshAll();
        $row = app(TldPriceBook::class)->cachedFullForTlds(['com'])['com'];
        $this->assertSame(1, $row['min_years']); $this->assertSame(1212000, $row['register']);
        $this->assertSame(2, $row['max_years']);
    }

    public function test_outage_excludes_only_that_account_and_keeps_owner_sales_intent(): void
    {
        $a = $this->account(); $b = $this->account('openprovider');
        $this->factory([$a->id => false, $b->id => [$this->offer('org', 1000)]]);
        $summary = app(TldPriceBook::class)->refreshAll();
        $this->assertSame(1, $summary['excluded']);
        $this->assertSame(1010000, app(TldPriceBook::class)->cachedFullForTlds(['org'])['org']['register']);
        $this->assertTrue($a->fresh()->sales_enabled);
        $this->assertSame('failed', $a->fresh()->connection_status);
    }

    public function test_changed_credentials_stale_snapshot_and_unverified_suffix_fail_closed(): void
    {
        $a = $this->account();
        $this->factory([$a->id => [$this->offer('com', 1000), $this->offer('unverified', 1000)]]);
        app(TldPriceBook::class)->refreshAll();
        $this->assertSame(['com'], app(TldPriceBook::class)->catalogTlds());
        $a->update(['credentials' => ['api_key' => 'changed-synthetic']]);
        $this->assertSame([], app(TldPriceBook::class)->catalogTlds());
        $this->travel(25)->hours(); $a->update(['health_checked_at' => now()]);
        $this->assertSame([], app(TldPriceBook::class)->catalogTlds());
    }

    public function test_joker_live_columns_use_actual_currency_exact_terms_and_active_promotions(): void
    {
        $columns = array_merge(['type', 'operation', 'tld', 'tld-currency', 'currency'],
            array_map(fn ($y) => 'price-'.$y.'y', range(1, 10)), ['valid_from', 'valid_until']);
        $row = fn ($type, $one, $two, $from = 'n/a', $until = 'n/a') => implode("\t", array_merge(
            [$type, 'create', 'co.uk', 'EUR', 'USD', $one, $two], array_fill(0, 8, 'n/a'), [$from, $until]));
        $body = $row('domain', '10.00', '20.00')."\n".$row('domain_promo', '8.00', '21.00',
            now()->subDay()->format('Y-m-d H:i:s O'), now()->addDay()->format('Y-m-d H:i:s O'));
        Http::fake(['*/login' => Http::response("Status-Code: 0\nAuth-SID: synthetic-session\n\nco.uk\n"),
            '*/query-price-list*' => Http::response("Status-Code: 1000\nColumns: ".implode(',', $columns)."\n\n".$body)]);
        $catalogue = (new JokerAdapter($this->account()))->catalogue();
        $this->assertCount(2, $catalogue['offers']);
        $this->assertSame([800, 2100], array_column($catalogue['offers'], 'amount_minor'));
        $this->assertSame([12, 24], array_column($catalogue['offers'], 'period_months'));
        $this->assertSame(['USD', 'USD'], array_column($catalogue['offers'], 'currency'));
        $this->assertSame(1000, $catalogue['offers'][0]['promotion_evidence']['regular_amount_minor']);
        $this->assertArrayNotHasKey('promotion_evidence', $catalogue['offers'][1]);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/query-price-list?') && $r->method() === 'GET');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'synthetic') && str_contains($r->url(), 'api-key'));
    }

    public function test_openprovider_catalogue_shares_vat_and_funding_pipeline_and_never_multiplies_create(): void
    {
        $a = $this->account('openprovider'); $a->settings = ['supplier_fee_pct' => 10];
        $client = \Mockery::mock(OpenProviderClient::class);
        $client->shouldReceive('resellerInfo')->once()->andReturn(['ok' => true, 'data' => ['vatperc' => 20]]);
        $client->shouldReceive('listTlds')->once()->with(1000, 0, true)->andReturn(['ok' => true, 'total' => 1, 'results' => [[
            'name' => 'com.au', 'status' => 'ACT', 'min_period' => 2, 'max_period' => 5, 'renew_available' => false,
            'transfer_available' => false, 'prices' => ['reseller_price' => ['reseller' => ['price' => '10.00', 'currency' => 'USD']]],
        ]]]);
        $offers = (new OpenProviderAdapter($a, $client))->catalogue()['offers'];
        $this->assertCount(1, $offers); $this->assertSame(24, $offers[0]['period_months']);
        $this->assertSame(1320, $offers[0]['amount_minor']); $this->assertSame(1200, $offers[0]['provider_debit_minor']);
    }

    public function test_openprovider_iso_promotion_expires_to_regular_price_without_stale_discount(): void
    {
        $this->freezeTime();
        $a = $this->account('openprovider');
        $client = \Mockery::mock(OpenProviderClient::class);
        $client->shouldReceive('resellerInfo')->twice()->andReturn(['ok' => true, 'data' => ['vatperc' => 0]]);
        $client->shouldReceive('listTlds')->twice()->andReturn(['ok' => true, 'total' => 1, 'results' => [[
            'name' => 'com', 'status' => 'ACT', 'min_period' => 1, 'max_period' => 1,
            'prices' => ['reseller_price' => ['reseller' => ['price' => '8.00', 'currency' => 'USD'],
                'promotion_details' => ['start_date' => now()->subDay()->toIso8601String(),
                    'end_date' => now()->addMinutes(2)->toIso8601String(),
                    'non_promo_price' => ['reseller' => ['price' => '12.00', 'currency' => 'USD']]]]],
        ]]]);
        $adapter = new OpenProviderAdapter($a, $client);
        $offer = $adapter->catalogue()['offers'][0];
        $this->assertSame(800, $offer['amount_minor']);
        $this->assertSame(1200, $offer['promotion_evidence']['regular_amount_minor']);
        $this->assertSame(now()->addMinutes(2)->timestamp, \Illuminate\Support\Carbon::parse($offer['valid_until'])->timestamp);
        $this->travel(3)->minutes();
        $this->assertSame(1200, $adapter->catalogue()['offers'][0]['amount_minor']);
    }
    private function promo(array $offer, int $regular): array
    {
        return $offer + ['promotion' => true, 'promotion_evidence' => ['regular_amount_minor' => $regular,
            'currency' => $offer['currency'], 'period_months' => $offer['period_months'],
            'starts_at' => now()->subDay()->toIso8601String(), 'ends_at' => now()->addHour()->toIso8601String()]];
    }

    private function storeCatalogue(DomainSupplierAccount $account, array $offers): void
    {
        Storage::disk('local')->put(DomainSupplierCatalogue::PATH, json_encode(['version' => 1,
            'fetched_at' => now()->toIso8601String(), 'accounts' => [(string) $account->id => [
                'identity' => DomainSupplierCatalogue::identity($account), 'offers' => $offers]]]));
    }

    public function test_cheapest_actual_offer_wins_and_only_its_own_original_price_is_public(): void
    {
        $a = $this->account(); $b = $this->account('openprovider');
        $promo = $this->promo($this->offer('com', 800), 1200);
        $rows = app(DomainProcurement::class)->cataloguePrices([
            ['account' => $a, 'offers' => [$promo]], ['account' => $b, 'offers' => [$this->offer('com', 900)]]]);
        $this->assertSame(808000, $rows['com']['register']);
        $this->assertSame(1212000, $rows['com']['regular_price_toman']);
        $this->assertSame(33, $rows['com']['discount_percent']);
        $this->assertTrue($rows['com']['is_promotion']);
        $this->assertStringNotContainsString('account_id', json_encode($rows));
        // A cheaper non-promotional competitor wins without a made-up old price.
        $rows = app(DomainProcurement::class)->cataloguePrices([
            ['account' => $a, 'offers' => [$promo]], ['account' => $b, 'offers' => [$this->offer('com', 700)]]]);
        $this->assertSame(707000, $rows['com']['register']);
        $this->assertFalse($rows['com']['is_promotion']);
        $this->assertNull($rows['com']['regular_price_toman']);
        Http::assertNothingSent();
    }

    public function test_bad_reference_currency_term_or_dates_never_create_a_customer_discount(): void
    {
        $a = $this->account();
        foreach (['currency' => 'EUR', 'period_months' => 24,
            'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->subMinute()->toIso8601String(),
            'regular_amount_minor' => PHP_INT_MAX] as $key => $value) {
            $offer = $this->promo($this->offer('com', 800), 1200);
            $offer['promotion_evidence'][$key] = $value;
            $rows = app(DomainProcurement::class)->cataloguePrices([['account' => $a, 'offers' => [$offer]]]);
            $this->assertSame(808000, $rows['com']['register']);
            $this->assertFalse($rows['com']['is_promotion'], $key);
            $this->assertNull($rows['com']['regular_price_toman']);
        }
        Http::assertNothingSent();
    }

    public function test_joker_exact_discount_uses_matching_private_account_evidence_without_extra_api_reads(): void
    {
        $this->freezeTime(); $a = $this->account();
        $offer = $this->promo($this->offer('com', 800), 1200);
        $offer['promotion_evidence']['ends_at'] = now()->addMinute()->toIso8601String();
        $this->storeCatalogue($a, [$offer]);
        $start = $offer['promotion_evidence']['starts_at']; $end = $offer['promotion_evidence']['ends_at'];
        Http::fake(['*/login' => Http::response("Status-Code: 1000\nAuth-SID: synthetic-session\n\n"),
            '*/domain-check' => Http::response("Status-Code: 1000\n\ndomain-status: available\ndomain-class: standard\ndomain-price-create: 8.00 USD 1y\ndomain-price-promo: $start $end\n")]);
        $quote = (new JokerAdapter($a))->quote('synthetic.com', 'create', 12);
        $this->assertSame(1200, $quote['promotion_evidence']['regular_amount_minor']);
        $this->assertSame(strtotime($end), strtotime($quote['valid_until']));
        $a->update(['credentials' => ['api_key' => 'changed-synthetic']]);
        $this->assertNull((new JokerAdapter($a))->quote('synthetic.com', 'create', 12)['promotion_evidence']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'query-price-list'));
    }

    public function test_openprovider_exact_discount_uses_same_term_and_account_catalogue_reference(): void
    {
        $a = $this->account('openprovider');
        $offer = $this->promo($this->offer('com', 800), 1200); $this->storeCatalogue($a, [$offer]);
        $client = \Mockery::mock(OpenProviderClient::class);
        $client->shouldReceive('check')->once()->andReturn(['ok' => true, 'results' => [['status' => 'free', 'is_premium' => false]]]);
        $client->shouldReceive('domainPrice')->once()->with('synthetic', 'com', 'create', 1)->andReturn(['ok' => true, 'data' => [
            'is_premium' => false, 'is_promotion' => true, 'price' => ['reseller' => ['price' => '8.00', 'currency' => 'USD']],
            'promotion_data' => ['start_date' => strtotime($offer['promotion_evidence']['starts_at']),
                'end_date' => strtotime($offer['promotion_evidence']['ends_at'])]]]);
        $client->shouldReceive('resellerInfo')->once()->andReturn(['ok' => true, 'data' => ['vatperc' => 0]]);
        $quote = (new OpenProviderAdapter($a, $client))->quote('synthetic.com', 'create', 12);
        $this->assertSame(800, $quote['amount_minor']);
        $this->assertSame(1200, $quote['promotion_evidence']['regular_amount_minor']);
        Http::assertNothingSent();
    }

    public function test_legacy_catalogue_and_exact_search_display_same_verified_discount_and_expire_it(): void
    {
        $this->freezeTime();
        config(['domain_suppliers.enabled' => false, 'services.openprovider.username' => 'synthetic-user',
            'services.openprovider.password' => 'synthetic-password', 'domain_pricing.supplier_vat_pct' => 0,
            'domain_pricing.supplier_fee_pct' => 0]);
        $row = ['min_years' => 1, 'max_years' => 10, 'register' => ['amount' => 8, 'currency' => 'USD'],
            'renew' => ['amount' => 20, 'currency' => 'USD'], 'transfer' => null,
            'promotion' => ['start' => now()->subDay()->timestamp, 'end' => now()->addMinute()->timestamp,
                'regular' => ['amount' => 12, 'currency' => 'USD']]];
        Storage::disk('local')->put(TldPriceBook::SNAPSHOT_PATH, json_encode(['version' => 1,
            'fetched_at' => now()->toIso8601String(), 'total' => 1, 'excluded' => [], 'supplier_vat_pct' => 0,
            'supplier_identity' => \App\Services\Domain\Providers\LegacyAccountIdentity::currentFingerprint(), 'rows' => ['com' => $row]]));
        $book = app(TldPriceBook::class); $catalogue = $book->cachedFullForTlds(['com'])['com'];
        $this->assertSame(808000, $catalogue['register']);
        $this->assertSame(1212000, $catalogue['regular_price_toman']);
        $display = $book->promotionForQuote('com', 8, 'USD', 1, 808000);
        $this->assertTrue($display['is_promotion']);
        $this->assertSame(33, $display['discount_percent']);
        foreach ([['com', 8, 'USD', 2, 808000, false], ['com', 9, 'USD', 1, 808000, false],
            ['com', 8, 'USD', 1, 808000, true], ['com', 8, 'USD', 1, 900000, false]] as $args) {
            $this->assertFalse($book->promotionForQuote(...$args)['is_promotion']);
        }
        $this->travel(2)->minutes();
        $this->assertFalse($book->promotionForQuote('com', 8, 'USD', 1, 808000)['is_promotion']);
        $this->assertSame(1212000, $book->cachedFullForTlds(['com'])['com']['register']);
        Http::assertNothingSent();
    }

    public function test_search_promotion_index_is_reused_only_until_explicit_scope_end(): void
    {
        $this->freezeTime(); $a = $this->account();
        $offer = $this->promo($this->offer('com', 800), 1200); $this->storeCatalogue($a, [$offer]);
        $start = $offer['promotion_evidence']['starts_at']; $end = $offer['promotion_evidence']['ends_at'];
        Http::fake(['*/login' => Http::response("Status-Code: 1000\nAuth-SID: synthetic-session\n\n"),
            '*/domain-check' => Http::response("Status-Code: 1000\n\ndomain-status: available\ndomain-class: standard\ndomain-price-create: 8.00 USD 1y\ndomain-price-promo: $start $end\n")]);
        $adapter = new JokerAdapter($a); $adapter->shareSearchReads(true);
        $this->assertSame(1200, $adapter->quote('synthetic.com', 'create', 12)['promotion_evidence']['regular_amount_minor']);
        $offer['promotion_evidence']['regular_amount_minor'] = 1400; $this->storeCatalogue($a, [$offer]);
        $this->assertSame(1200, $adapter->quote('other.com', 'create', 12)['promotion_evidence']['regular_amount_minor']);
        $adapter->shareSearchReads(false);
        $this->assertSame(1400, $adapter->quote('synthetic.com', 'create', 12)['promotion_evidence']['regular_amount_minor']);
        $this->assertSame(800, $adapter->quote('synthetic.com', 'create', 12)['amount_minor']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'query-price-list'));
    }

}
