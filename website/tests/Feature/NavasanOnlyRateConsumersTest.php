<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Ai\AiFx;
use App\Services\Cloud\CloudPricing;
use App\Services\Domain\TldPriceBook;
use App\Services\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class NavasanOnlyRateConsumersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Http::fake();
        config(['services.exchange.enabled' => true]);
        foreach (['pricing_rate_override', 'pricing_usd_rate_override', 'pricing_usdt_rate_override'] as $key) {
            Setting::put($key, '4000000'); // Old administrative values must never become a rate source.
        }
        Setting::put('pricing_baseline_rate', '304000');
    }

    private function invoke(object $object, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    public function test_supplier_currency_normalisation_ignores_all_manual_rate_values(): void
    {
        NavasanRates::store();
        Setting::put('cloud_margin_pct', '0');
        Setting::put('pricing_fx_fee_pct', '0');
        $this->assertSame(['eur_cents' => 1000, 'irt' => 2000000], app(CloudPricing::class)->priceFor(1000));
        $this->assertSame(200000, $this->invoke(app(\App\Services\Cloud\ArvanClient::class), 'eurToman'));
        $this->assertSame(100000, $this->invoke(app(\App\Services\Cloud\SaladClient::class), 'usdToman'));
        $this->assertSame(0.5, $this->invoke(app(\App\Services\Cloud\OvhClient::class), 'toEurFactor', 'USD'));
        $this->assertSame(['cost_eur_cents' => 500, 'cost_hour_eur_micro' => 5000],
            $this->invoke(app(\App\Services\Cloud\LightNodeClient::class), 'supplierCost', ['month_usd' => 10, 'hour_usd' => 0.01]));
        $this->assertSame(200000, $this->invoke(app(\App\Http\Controllers\Admin\FleetController::class), 'eurRate'));
        foreach ([true, false] as $live) {
            $this->assertSame(150000.0, $this->invoke(app(\App\Services\Payment\CryptoIssuer::class), 'tomanPerCurrency', 'USDT', $live));
        }
        Http::assertNothingSent();
    }

    public function test_manual_values_cannot_keep_currency_pricing_open_without_a_valid_navasan_rate(): void
    {
        $this->assertSame(0, app(CloudPricing::class)->toman(1000));
        $this->assertNull($this->invoke(app(\App\Services\Cloud\OvhClient::class), 'toEurFactor', 'USD'));
        $this->assertNull($this->invoke(app(\App\Services\Cloud\LightNodeClient::class), 'supplierCost', ['month_usd' => 10, 'hour_usd' => 0.01]));
        $this->assertSame(0.0, $this->invoke(app(\App\Services\Payment\CryptoIssuer::class), 'tomanPerCurrency', 'USDT', true));
        $this->assertNull(app(AiFx::class)->quote('USD'));
        $this->assertNull(app(AiFx::class)->displayRate('EUR'));
        Http::assertNothingSent();
    }

    public function test_ai_uses_navasan_and_preserves_its_stale_buffer_and_downward_price_guard(): void
    {
        NavasanRates::store();
        $ai = app(AiFx::class);
        $this->assertSame(100000, $ai->quote('USD')->rate);
        $this->assertSame(200000, $ai->displayRate('EUR'));
        NavasanRates::store(['USD' => 100000, 'EUR' => 200000], now()->subHours(7)->toIso8601String());
        $this->assertSame(102000, $ai->quote('USD')->rate);
        $this->assertSame(100000, $ai->displayRate('USD'));
        NavasanRates::store(['USD' => 80000]);
        $guarded = $ai->quote('USD');
        $this->assertSame(98940, $guarded->rate);
        $this->assertSame('ratchet', $guarded->source);
        Http::assertNothingSent();
    }

    public function test_domain_operations_convert_their_own_navasan_currency_and_preserve_null_and_free(): void
    {
        Storage::fake('local');
        config(['domain_reseller.min_margin_pct' => 0, 'domain_pricing.supplier_vat_pct' => 0]);
        Setting::put('domain_margin_pct', '0');
        NavasanRates::store();
        Storage::disk('local')->put(TldPriceBook::SNAPSHOT_PATH, json_encode([
            'version' => 1, 'fetched_at' => now()->toIso8601String(), 'total' => 2, 'excluded' => [],
            'supplier_vat_pct' => 0, 'rows' => [
                'example' => ['min_years' => 1, 'max_years' => 10, 'register' => ['amount' => 10, 'currency' => 'EUR'],
                    'renew' => ['amount' => 20, 'currency' => 'USD'], 'transfer' => ['amount' => 0, 'currency' => 'USDT'], 'promotion' => null],
                'nullable' => ['min_years' => 2, 'max_years' => 10, 'register' => ['amount' => 10, 'currency' => 'USDT'],
                    'renew' => null, 'transfer' => null, 'promotion' => null],
            ],
        ], JSON_THROW_ON_ERROR));
        $rows = app(TldPriceBook::class)->cachedFullForTlds(['example', 'nullable']);
        $this->assertSame(['min_years' => 1, 'max_years' => 10, 'register' => 2000000, 'renew' => 2000000, 'transfer' => 0], $rows['example']);
        $this->assertSame(['min_years' => 2, 'max_years' => 10, 'register' => 1500000, 'renew' => null, 'transfer' => null], $rows['nullable']);
        Http::assertNothingSent();
    }
}
