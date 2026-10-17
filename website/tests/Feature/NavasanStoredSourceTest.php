<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class NavasanStoredSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_wrong_currency_wrong_source_and_timeless_rows_cannot_price_a_sale(): void
    {
        $this->travelTo(now()->startOfSecond());
        config(['services.exchange.enabled' => true]);
        Http::fake();
        $valid = NavasanRates::rows()['EUR'];
        foreach ([['source' => 'alanchand.com'], ['source' => 'manual'], ['source_item' => 'usd_shakhs'],
            ['currency' => 'USD'], ['source_at' => null], ['at' => null],
            ['at' => now()->subHours(49)->toIso8601String()], ['source_at' => now()->subHours(49)->toIso8601String()],
            ['source_at' => now()->addMinutes(6)->toIso8601String()]] as $invalid) {
            $row = array_replace($valid, $invalid);
            Cache::put('fx.navasan.rates', ['EUR' => $row]);
            Setting::put('fx_last_eur', json_encode($row, JSON_THROW_ON_ERROR));
            $this->assertNull(app(ExchangeRate::class)->current('EUR'));
            $this->assertNull(app(ExchangeRate::class)->toToman('EUR'));
        }
        Http::assertNothingSent();
    }

    public function test_corrupt_cache_uses_verified_durable_rate_without_network_or_new_timestamp(): void
    {
        $this->travelTo(now()->startOfSecond());
        Http::fake();
        $at = now()->subHours(7)->toIso8601String();
        $rows = NavasanRates::store(['EUR' => 234560], $at);
        Cache::put('fx.navasan.rates', ['EUR' => ['rate_toman' => 300000, 'source' => 'manual']]);
        $row = app(ExchangeRate::class)->current('EUR');
        $this->assertSame(234560, $row['rate_toman']);
        $this->assertSame($at, $row['at']);
        $this->assertSame($at, $row['source_at']);
        $this->assertTrue($row['stale']);
        $this->assertSame($rows['EUR'], json_decode(Setting::get('fx_last_eur'), true));
        $this->travel(42)->hours();
        $this->assertNull(app(ExchangeRate::class)->current('EUR'));
        Http::assertNothingSent();
    }
}
