<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Domain\LiveDomainPricing;
use App\Services\Domain\OpenProviderClient;
use App\Services\Domain\TldPriceBook;
use App\Services\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NavasanDomainPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.exchange.enabled' => true,
            'domain_pricing.supplier_vat_pct' => 0, 'domain_pricing.supplier_fee_pct' => 0,
            'domain_reseller.min_margin_pct' => 10]);
        Setting::put('domain_margin_pct', '10');
    }

    private function rates(int $euro, int $usd = 100000): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response([
            'eur_hav' => ['value' => $euro, 'date' => now()->timestamp],
            'usd_shakhs' => ['value' => $usd, 'date' => now()->timestamp],
            'usd_usdt' => ['value' => 105000, 'date' => now()->timestamp],
            'try_hav' => ['value' => 5600, 'date' => now()->timestamp],
        ])]);
        $this->assertNotNull(app(ExchangeRate::class)->refreshAll());
    }

    public function test_openprovider_create_renew_transfer_follow_their_own_cost_currency_and_new_navasan_rate(): void
    {
        $op = \Mockery::mock(OpenProviderClient::class);
        $op->shouldReceive('resellerInfo')->andReturn(['ok' => true, 'data' => ['vatperc' => 0]]);
        foreach (['create' => 10, 'renew' => 20, 'transfer' => 15] as $operation => $amount) {
            $op->shouldReceive('domainPrice')->with('example', 'com', $operation, 1)
                ->andReturn(['ok' => true, 'data' => ['price' => ['reseller' => ['price' => $amount, 'currency' => 'EUR']]]]);
        }
        $pricing = new LiveDomainPricing($op);
        foreach ([200000, 300000] as $eur) {
            $this->rates($eur);
            foreach (['create' => 10, 'renew' => 20, 'transfer' => 15] as $operation => $amount) {
                $quote = $pricing->quote('example', 'com', $operation);
                $this->assertSame($amount * $eur, $quote['wholesale_toman']);
                $minimum = (int) ($amount * $eur * 1.1);
                $this->assertGreaterThanOrEqual($minimum, $quote['sell_toman']);
                $this->assertLessThanOrEqual($minimum + 1000, $quote['sell_toman']);
            }
            $this->assertSame(1000000, $pricing->fromAmount(10, 'USD', 'com')['wholesale_toman']);
        }
        $this->rates(300000, 150000);
        $this->assertSame(1500000, $pricing->fromAmount(10, 'USD', 'com')['wholesale_toman']);
    }

    public function test_cached_catalogues_cannot_reuse_converted_prices_after_fx_changes(): void
    {
        config(['domain_reseller.min_margin_pct' => 0]);
        Setting::put('domain_margin_pct', '0');
        $op = \Mockery::mock(OpenProviderClient::class);
        $prices = [];
        foreach (['reseller_price' => 10, 'renew_price' => 20, 'transfer_price' => 15] as $operation => $amount) {
            $prices[$operation] = ['reseller' => ['price' => $amount, 'currency' => 'EUR']];
        }
        $op->shouldReceive('listTlds')->once()->with(1000, 0, true)->andReturn([
            'ok' => true, 'total' => 1, 'results' => [[
                'name' => 'com', 'status' => 'ACT', 'min_period' => 1, 'max_period' => 10,
                'renew_available' => true, 'transfer_available' => true, 'prices' => $prices,
            ]],
        ]);
        $op->shouldReceive('resellerInfo')->once()->andReturn(['ok' => true, 'data' => ['vatperc' => 0]]);
        $op->shouldNotReceive('domainPrice');
        $op->shouldNotReceive('check');
        $this->app->instance(OpenProviderClient::class, $op);
        $book = new TldPriceBook($op);
        $this->rates(200000);
        $book->refreshAll();
        $native = Storage::disk('local')->get(TldPriceBook::SNAPSHOT_PATH);
        $this->assertSame(['com' => 2000000], $book->forTlds(['com']));
        $this->assertSame(['min_years' => 1, 'max_years' => 10,
            'register' => 2000000, 'renew' => 4000000, 'transfer' => 3000000], $book->fullForTlds(['com'])['com']);
        $this->rates(300000);
        $this->assertSame(['com' => 3000000], $book->cachedForTlds(['com']));
        Http::assertSentCount(1); // Only the explicit FX refresh; catalog reads have no remote calls.
        $this->assertSame(['com' => 3000000], $book->forTlds(['com']));
        $this->assertSame(['min_years' => 1, 'max_years' => 10,
            'register' => 3000000, 'renew' => 6000000, 'transfer' => 4500000], $book->fullForTlds(['com'])['com']);
        $this->assertSame(['com' => 3000000], $book->cachedForTlds(['com']));
        $this->assertSame($native, Storage::disk('local')->get(TldPriceBook::SNAPSHOT_PATH));
    }
}
