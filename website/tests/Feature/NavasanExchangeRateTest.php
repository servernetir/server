<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NavasanExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config()->set('services.exchange.enabled', true);
        Setting::query()->whereIn('key', ['fx_last_usd', 'fx_last_eur', 'fx_last_usdt', 'fx_last_try'])->delete();
        Cache::forget('fx.navasan.rates');
        Cache::forget('settings.all');
        app()->forgetInstance('settings.request-memo');
        foreach (array_keys(ExchangeRate::SOURCE_ITEMS) as $currency) {
            Cache::forget('fx.'.strtolower($currency).'_irt');
        }
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function payload(): array
    {
        return [
            'usd_shakhs' => ['value' => 273380, 'date' => now()->subMinutes(5)->timestamp],
            'eur_hav' => ['value' => 305110, 'date' => now()->subMinutes(5)->timestamp],
            'usd_usdt' => ['value' => 264250, 'date' => now()->subMinutes(5)->timestamp],
            'try_hav' => ['value' => 5640, 'date' => now()->subMinutes(5)->timestamp],
            // Similar rows must not be confused with the owner's chosen rates.
            'usd_sherkat' => ['value' => 270820, 'date' => now()->timestamp],
            'usd_sell' => ['value' => 190000, 'date' => now()->timestamp],
            'eur' => ['value' => 220000, 'date' => now()->timestamp],
            'try' => ['value' => 5300, 'date' => now()->timestamp],
        ];
    }

    public function test_one_request_publishes_four_distinct_toman_rates_from_the_selected_rows(): void
    {
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($this->payload())]);
        $fx = app(ExchangeRate::class);
        $rows = $fx->refreshAll();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), ExchangeRate::SOURCE_URL.'?'));
        foreach (['USD' => 273380, 'EUR' => 305110, 'USDT' => 264250, 'TRY' => 5640] as $currency => $rate) {
            $this->assertSame($rate, $rows[$currency]['rate_toman']);
            $this->assertSame('navasan.net', $rows[$currency]['source']);
            $this->assertSame('IRT', $rows[$currency]['unit']);
            $this->assertSame(ExchangeRate::SOURCE_ITEMS[$currency], $rows[$currency]['source_item']);
            $this->assertSame(now()->subMinutes(5)->toIso8601String(), $rows[$currency]['source_at']);
            $this->assertSame($rate, $fx->toToman($currency));
            $stored = json_decode(Setting::get('fx_last_'.strtolower($currency)), true);
            $this->assertSame($rows[$currency], $stored);
        }
        Http::assertSentCount(1);
    }

    public function test_failed_or_partial_responses_never_replace_any_previous_rate(): void
    {
        $fx = app(ExchangeRate::class);
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($this->payload())]);
        $fx->refreshAll();
        $before = array_map(fn ($currency) => $fx->current($currency), array_combine(array_keys(ExchangeRate::SOURCE_ITEMS), array_keys(ExchangeRate::SOURCE_ITEMS)));
        $persisted = Setting::whereIn('key', ['fx_last_usd', 'fx_last_eur', 'fx_last_usdt', 'fx_last_try'])->pluck('value', 'key')->all();

        $missing = $this->payload();
        unset($missing['usd_usdt']);
        $invalid = [$missing];
        foreach ([null, true, [], 'abc', 0, 19999, 5000001, 264250.5] as $value) {
            $payload = $this->payload();
            $payload['usd_usdt']['value'] = $value;
            $invalid[] = $payload;
        }
        foreach ([null, '', true, [], now()->subHours(49)->timestamp, now()->addMinutes(6)->timestamp, now()->timestamp * 1000] as $date) {
            $payload = $this->payload();
            $payload['eur_hav']['date'] = $date;
            $invalid[] = $payload;
        }
        $invalid[] = '<html>273,380 273,380 273,380</html>';
        $invalid[] = '{"usd_shakhs":';

        foreach ($invalid as $payload) {
            Http::swap(new Factory);
            Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($payload)]);
            $this->assertNull($fx->refreshAll());
            foreach (array_keys(ExchangeRate::SOURCE_ITEMS) as $currency) {
                $this->assertSame($before[$currency], $fx->current($currency));
            }
            $this->assertSame($persisted, Setting::whereIn('key', array_keys($persisted))->pluck('value', 'key')->all());
        }

        Http::swap(new Factory);
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($this->payload(), 503)]);
        $this->assertNull($fx->refreshAll());
        $this->assertSame($before['USD'], $fx->current('USD'));
    }

    public function test_cache_expiry_preserves_provenance_without_extending_the_source_lifetime(): void
    {
        $payload = $this->payload();
        foreach (array_keys(ExchangeRate::SOURCE_ITEMS) as $currency) {
            $payload[ExchangeRate::SOURCE_ITEMS[$currency]]['date'] = now()->subHours(40)->timestamp;
        }
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($payload)]);
        $fx = app(ExchangeRate::class);
        $this->assertNotNull($fx->refreshAll());
        Cache::forget('fx.navasan.rates');
        $fallback = $fx->current('USDT');
        $this->assertTrue($fallback['stale']);
        $this->assertSame('navasan.net', $fallback['source']);
        $this->assertSame('usd_usdt', $fallback['source_item']);
        $this->travel(9)->hours();
        $this->assertNull($fx->lastKnown('USDT'));
    }

    public function test_disabled_refresh_and_unsupported_currencies_never_call_a_source(): void
    {
        Http::fake();
        $fx = app(ExchangeRate::class);
        $this->assertNull($fx->refresh('GBP'));
        config()->set('services.exchange.enabled', false);
        $this->assertNull($fx->refreshAll());
        $this->assertNull($fx->toToman('USDT'));
        Http::assertNothingSent();
    }

    public function test_cold_price_reads_never_fetch_even_when_background_refresh_is_enabled(): void
    {
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($this->payload())]);
        $fx = app(ExchangeRate::class);
        $this->assertNull($fx->toToman('EUR'));
        $this->assertNull($fx->toToman('USD'));
        $this->assertNull($fx->toToman('USDT'));
        Http::assertNothingSent();
    }

    public function test_an_older_upstream_snapshot_cannot_roll_prices_back(): void
    {
        $fx = app(ExchangeRate::class);
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($this->payload())]);
        $fx->refreshAll();
        $before = $fx->current('EUR');
        $payload = $this->payload();
        $payload['eur_hav'] = ['value' => 200000, 'date' => now()->subHour()->timestamp];
        Http::swap(new Factory);
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($payload)]);
        $this->assertNull($fx->refreshAll());
        $this->assertSame($before, $fx->current('EUR'));
    }

    public function test_an_overlapping_refresh_does_not_fetch_or_change_the_snapshot(): void
    {
        $fx = app(ExchangeRate::class);
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($this->payload())]);
        $before = $fx->refreshAll();
        $persisted = Setting::whereIn('key', ['fx_last_usd', 'fx_last_eur', 'fx_last_usdt', 'fx_last_try'])
            ->pluck('value', 'key')->all();
        Http::swap(new Factory);
        Http::fake();

        $lock = Cache::lock('fx.navasan.refresh', 90);
        $this->assertTrue($lock->get());
        try {
            $this->assertNull($fx->refreshAll());
            Http::assertNothingSent();
            $this->assertSame($before, Cache::get('fx.navasan.rates'));
            $this->assertSame($persisted, Setting::whereIn('key', array_keys($persisted))->pluck('value', 'key')->all());
        } finally {
            $lock->release();
        }
    }

    public function test_failed_cache_publication_restores_cached_and_durable_rates(): void
    {
        $store = new class extends \Illuminate\Cache\ArrayStore {
            public ?string $failure = null;

            public function put($key, $value, $seconds)
            {
                if ($key === 'fx.navasan.rates' && $this->failure !== null) {
                    $failure = $this->failure;
                    $this->failure = null; // Permit the compensating restore.
                    if ($failure === 'false') {
                        return false;
                    }
                    if ($failure === 'after_write') {
                        parent::put($key, $value, $seconds);
                    }
                    throw new \RuntimeException('Synthetic cache publication failure');
                }

                return parent::put($key, $value, $seconds);
            }
        };
        Cache::swap(new \Illuminate\Cache\Repository($store));
        $fx = app(ExchangeRate::class);
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($this->payload())]);
        $before = $fx->refreshAll();
        $this->assertNotNull($before);
        $persisted = Setting::whereIn('key', ['fx_last_usd', 'fx_last_eur', 'fx_last_usdt', 'fx_last_try'])
            ->pluck('value', 'key')->all();
        $payload = $this->payload();
        $payload['eur_hav'] = ['value' => 310000, 'date' => now()->timestamp];

        foreach (['throw', 'false', 'after_write'] as $failure) {
            $store->failure = $failure;
            Http::swap(new Factory);
            Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($payload)]);

            $this->assertNull($fx->refreshAll(), $failure);
            $this->assertSame($before, Cache::get('fx.navasan.rates'), $failure);
            $this->assertSame($persisted, Setting::whereIn('key', array_keys($persisted))->pluck('value', 'key')->all(), $failure);
            foreach ($persisted as $key => $value) {
                $this->assertSame($value, Setting::get($key), $failure);
            }
            $lock = Cache::lock('fx.navasan.refresh', 90);
            $this->assertTrue($lock->get(), 'Failed publication must release its refresh lock.');
            $lock->release();
        }
    }
}
