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
}
