<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\ExchangeRate;
use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class NavasanCoordinatedRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['fx_last_usd', 'fx_last_eur', 'fx_last_usdt', 'fx_last_try'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['services.exchange.enabled' => true]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function oldRates(): array
    {
        Setting::put('coordinated_catalog_price', 'old');
        return NavasanRates::store(['USD' => 100000, 'EUR' => 200000, 'USDT' => 150000, 'TRY' => 5000],
            now()->subHour()->toIso8601String());
    }

    private function payload(): array
    {
        $payload = [];
        foreach (['USD' => 110000, 'EUR' => 220000, 'USDT' => 165000, 'TRY' => 5500] as $currency => $rate) {
            $payload[ExchangeRate::SOURCE_ITEMS[$currency]] = ['value' => $rate, 'date' => now()->timestamp];
        }
        return $payload;
    }

    private function durable(): array
    {
        return DB::table('settings')->whereIn('key', self::KEYS)->orderBy('key')->pluck('value', 'key')->all();
    }

    private function fakeFreshSource(): void
    {
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($this->payload())]);
    }

    public function test_slow_preparation_keeps_public_cache_and_durable_rates_old_then_applies_one_batch(): void
    {
        $old = $this->oldRates();
        $durable = $this->durable();
        $level = DB::transactionLevel();
        $this->fakeFreshSource();
        $observed = [];
        $fx = app(ExchangeRate::class);
        $result = $fx->refreshAll(function (array $rows) use ($fx, &$observed): Closure {
            $observed['prepare'] = ['level' => DB::transactionLevel(), 'cache' => Cache::get('fx.navasan.rates'),
                'durable' => $this->durable(), 'rate' => $fx->toToman('EUR')];
            return function () use ($fx, &$observed): void {
                $observed['apply'] = ['level' => DB::transactionLevel(), 'rate' => $fx->toToman('EUR')];
                Setting::put('coordinated_catalog_price', (string) $fx->toToman('EUR'));
                Setting::get('coordinated_catalog_price'); // Exercise the in-transaction request memo.
            };
        });
        $this->assertNotNull($result);
        $this->assertSame(['level' => $level, 'cache' => $old, 'durable' => $durable, 'rate' => 220000], $observed['prepare']);
        $this->assertSame(['level' => $level + 1, 'rate' => 220000], $observed['apply']);
        $this->assertSame($result, Cache::get('fx.navasan.rates'));
        foreach ($result as $currency => $row) {
            $this->assertSame($row, json_decode(Setting::get('fx_last_'.strtolower($currency)), true));
        }
        $this->assertSame('220000', Setting::get('coordinated_catalog_price'));
        $this->assertSame(220000, $fx->toToman('EUR'));
        Http::assertSentCount(1);
    }

    public function test_preparation_failure_and_non_closure_leave_the_old_generation_unchanged(): void
    {
        $old = $this->oldRates();
        $durable = $this->durable();
        foreach ([fn () => throw new \RuntimeException('Synthetic supplier failure'), fn () => 123] as $prepare) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            $this->fakeFreshSource();
            $this->assertNull(app(ExchangeRate::class)->refreshAll($prepare));
            $this->assertSame($old, Cache::get('fx.navasan.rates'));
            $this->assertSame($durable, $this->durable());
            $this->assertSame('old', Setting::get('coordinated_catalog_price'));
            $this->assertSame(200000, app(ExchangeRate::class)->toToman('EUR'));
        }
    }

    public function test_apply_failure_rolls_back_catalog_rates_and_any_rebuilt_settings_memo(): void
    {
        $old = $this->oldRates();
        $durable = $this->durable();
        $this->fakeFreshSource();
        $result = app(ExchangeRate::class)->refreshAll(fn () => function (): void {
            Setting::put('coordinated_catalog_price', 'new');
            Setting::get('fx_last_eur');
            throw new \RuntimeException('Synthetic catalogue apply failure');
        });
        $this->assertNull($result);
        $this->assertSame($old, Cache::get('fx.navasan.rates'));
        $this->assertSame($durable, $this->durable());
        $this->assertSame('old', Setting::get('coordinated_catalog_price'));
        $this->assertSame(200000, app(ExchangeRate::class)->toToman('EUR'));
    }

    public function test_durable_try_drift_during_preparation_aborts_without_overwriting_the_other_writer(): void
    {
        $old = $this->oldRates();
        $changed = json_encode(array_replace($old['TRY'], ['rate_toman' => 5100]), JSON_THROW_ON_ERROR);
        $applied = false;
        $this->fakeFreshSource();
        $result = app(ExchangeRate::class)->refreshAll(function () use ($changed, &$applied): Closure {
            DB::table('settings')->where('key', 'fx_last_try')->update(['value' => $changed]);
            return function () use (&$applied): void { $applied = true; };
        });
        $this->assertNull($result);
        $this->assertFalse($applied);
        $this->assertSame($changed, DB::table('settings')->where('key', 'fx_last_try')->value('value'));
        $this->assertSame($old, Cache::get('fx.navasan.rates'));
        $this->assertSame('old', Setting::get('coordinated_catalog_price'));
    }

    public function test_lost_refresh_lease_aborts_and_does_not_release_a_new_owners_lock(): void
    {
        $old = $this->oldRates();
        $durable = $this->durable();
        $this->fakeFreshSource();
        $successor = null;
        $applied = false;
        try {
            $result = app(ExchangeRate::class)->refreshAll(function () use (&$successor, &$applied): Closure {
                Cache::lock('fx.navasan.refresh')->forceRelease();
                $successor = Cache::lock('fx.navasan.refresh', 1800);
                if (! $successor->get()) { throw new \RuntimeException('Synthetic successor failed'); }
                return function () use (&$applied): void { $applied = true; };
            });
            $this->assertNull($result);
            $this->assertFalse($applied);
            $this->assertNotNull($successor);
            $this->assertTrue($successor->isOwnedByCurrentProcess());
            $this->assertFalse(Cache::lock('fx.navasan.refresh', 1800)->get());
            $this->assertSame($old, Cache::get('fx.navasan.rates'));
            $this->assertSame($durable, $this->durable());
        } finally {
            $successor?->release();
        }
    }

    public function test_cache_failure_after_write_rolls_back_catalog_and_restores_the_old_snapshot(): void
    {
        $store = new class extends ArrayStore {
            public bool $fail = false;
            public function put($key, $value, $seconds)
            {
                $result = parent::put($key, $value, $seconds);
                if ($key === 'fx.navasan.rates' && $this->fail) {
                    $this->fail = false;
                    throw new \RuntimeException('Synthetic post-write cache failure');
                }
                return $result;
            }
        };
        Cache::swap(new Repository($store));
        $old = $this->oldRates();
        $durable = $this->durable();
        $this->fakeFreshSource();
        $store->fail = true;
        $result = app(ExchangeRate::class)->refreshAll(fn () => function (): void {
            Setting::put('coordinated_catalog_price', 'new');
        });
        $this->assertNull($result);
        $this->assertSame($old, Cache::get('fx.navasan.rates'));
        $this->assertSame($durable, $this->durable());
        $this->assertSame('old', Setting::get('coordinated_catalog_price'));
    }

    public function test_cache_compensation_preserves_a_different_newer_batch(): void
    {
        $store = new class extends ArrayStore {
            public ?array $successor = null;
            public function put($key, $value, $seconds)
            {
                if ($key === 'fx.navasan.rates' && $this->successor !== null) {
                    $successor = $this->successor;
                    $this->successor = null;
                    parent::put($key, $successor, $seconds);
                    throw new \RuntimeException('Synthetic newer cache publication');
                }
                return parent::put($key, $value, $seconds);
            }
        };
        Cache::swap(new Repository($store));
        $this->oldRates();
        $durable = $this->durable();
        $successor = NavasanRates::rows(['USD' => 120000, 'EUR' => 240000, 'USDT' => 180000, 'TRY' => 6000]);
        $store->successor = $successor;
        $this->fakeFreshSource();
        $result = app(ExchangeRate::class)->refreshAll(fn () => fn () => null);
        $this->assertNull($result);
        $this->assertSame($successor, Cache::get('fx.navasan.rates'));
        $this->assertSame($durable, $this->durable());
        $this->assertSame('old', Setting::get('coordinated_catalog_price'));
    }

    public function test_nested_prepared_rates_restore_each_scope_even_when_the_inner_callback_throws(): void
    {
        $this->oldRates();
        $outer = NavasanRates::rows(['USD' => 110000, 'EUR' => 220000, 'USDT' => 165000, 'TRY' => 5500]);
        $inner = NavasanRates::rows(['USD' => 120000, 'EUR' => 240000, 'USDT' => 180000, 'TRY' => 6000]);
        $seen = [];
        ExchangeRate::withPreparedRates($outer, function () use ($inner, &$seen): void {
            $seen[] = app(ExchangeRate::class)->toToman('EUR');
            try {
                ExchangeRate::withPreparedRates($inner, function () use (&$seen): void {
                    $seen[] = app(ExchangeRate::class)->toToman('EUR');
                    throw new \RuntimeException('Synthetic nested failure');
                });
            } catch (\RuntimeException) {}
            $seen[] = app(ExchangeRate::class)->toToman('EUR');
        });
        $this->assertSame([220000, 240000, 220000], $seen);
        $this->assertSame(200000, app(ExchangeRate::class)->toToman('EUR'));
        Http::assertNothingSent();
    }
}
