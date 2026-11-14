<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class NavasanTryRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['services.exchange.enabled' => true]);
        Http::preventStrayRequests();
    }

    private function payload(): array
    {
        return [
            'usd_shakhs' => ['value' => 273380, 'date' => now()->subMinutes(5)->timestamp],
            'eur_hav' => ['value' => 305110, 'date' => now()->subMinutes(5)->timestamp],
            'usd_usdt' => ['value' => 264250, 'date' => now()->subMinutes(5)->timestamp],
            'try_hav' => ['value' => 5640, 'date' => now()->subMinutes(5)->timestamp],
            'try' => ['value' => 5300, 'date' => now()->timestamp],
            'try_sell' => ['value' => 5200, 'date' => now()->timestamp],
        ];
    }

    public function test_same_public_request_stores_remittance_try_in_toman_without_a_rial_division(): void
    {
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($this->payload())]);
        $fx = app(ExchangeRate::class);
        $rows = $fx->refreshAll();
        $this->assertSame(['USD', 'EUR', 'USDT', 'TRY'], array_keys($rows));
        $this->assertSame(5640, $rows['TRY']['rate_toman']);
        $this->assertSame('IRT', $rows['TRY']['unit']);
        $this->assertSame('try_hav', $rows['TRY']['source_item']);
        $this->assertSame('navasan.net', $rows['TRY']['source']);
        $this->assertSame(now()->subMinutes(5)->toIso8601String(), $rows['TRY']['source_at']);
        $this->assertSame($rows['TRY'], json_decode(Setting::get('fx_last_try'), true));
        $this->assertSame(5640, $fx->toToman('TRY'));
        Cache::forget('fx.navasan.rates');
        $this->assertSame(5640, $fx->current('TRY')['rate_toman']);
        $this->assertTrue($fx->current('TRY')['stale']);
        Http::assertSentCount(1);
    }

    public function test_invalid_try_does_not_block_core_rates_or_replace_the_last_verified_try(): void
    {
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($this->payload())]);
        $fx = app(ExchangeRate::class);
        $previous = $fx->refreshAll();
        $stored = Setting::whereIn('key', ['fx_last_usd', 'fx_last_eur', 'fx_last_usdt', 'fx_last_try'])
            ->pluck('value', 'key')->all();
        $invalid = [];
        $missing = $this->payload();
        unset($missing['try_hav']);
        $invalid[] = $missing; // Cash try and try_sell cannot replace remittance try_hav.
        foreach ([null, true, [], '', 0, 99, 100001, 5640.5, '5,640', '1e3'] as $value) {
            $payload = $this->payload();
            $payload['try_hav']['value'] = $value;
            $invalid[] = $payload;
        }
        foreach ([now()->subHours(49)->timestamp, now()->addMinutes(6)->timestamp,
            now()->timestamp * 1000, '۱۴۰۵/۰۸/۰۴', null] as $date) {
            $payload = $this->payload();
            $payload['try_hav']['date'] = $date;
            $invalid[] = $payload;
        }
        $wrongUnit = $this->payload();
        $wrongUnit['try_hav']['unit'] = 'IRR';
        $invalid[] = $wrongUnit;
        $older = $this->payload();
        $older['try_hav']['date'] = now()->subHour()->timestamp;
        $invalid[] = $older;
        foreach ($invalid as $payload) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($payload)]);
            $rows = $fx->refreshAll();
            $this->assertSame(['USD', 'EUR', 'USDT'], array_keys($rows));
            $this->assertSame($rows, Cache::get('fx.navasan.rates'));
            $this->assertSame($previous['TRY']['rate_toman'], $fx->current('TRY')['rate_toman']);
            $this->assertSame($previous['TRY']['source_at'], $fx->current('TRY')['source_at']);
            $this->assertSame($stored, Setting::whereIn('key', array_keys($stored))->pluck('value', 'key')->all());
            Http::assertSentCount(1);
        }
    }

    public function test_cached_try_requires_explicit_toman_unit_correct_source_and_bounded_source_age(): void
    {
        $valid = NavasanRates::rows()['TRY'];
        foreach ([['unit' => 'IRR'], ['unit' => null], ['source_item' => 'try'],
            ['source_item' => 'try_sell'], ['source' => 'manual'], ['currency' => 'USD'],
            ['source_at' => now()->subHours(49)->toIso8601String()],
            ['at' => now()->subHours(49)->toIso8601String()],
            ['source_at' => now()->addMinutes(6)->toIso8601String()],
            ['at' => 'now'], ['source_at' => 'now']] as $changes) {
            $row = array_replace($valid, $changes);
            Cache::put('fx.navasan.rates', ['TRY' => $row]);
            Setting::put('fx_last_try', json_encode($row, JSON_THROW_ON_ERROR));
            $this->assertNull(app(ExchangeRate::class)->current('TRY'));
        }
        $row = $valid;
        unset($row['unit']);
        Cache::put('fx.navasan.rates', ['TRY' => $row]);
        Setting::put('fx_last_try', json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertNull(app(ExchangeRate::class)->toToman('TRY'));
        Http::assertNothingSent();
    }

    public function test_rejected_try_allows_coordinated_core_publication_without_publishing_an_unverified_try(): void
    {
        $payload = $this->payload();
        $payload['try_hav']['value'] = 369720;
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($payload)]);
        $fx = app(ExchangeRate::class);
        $applied = false;
        $rows = $fx->refreshAll(function (array $rates) use (&$applied): \Closure {
            $this->assertSame(['USD', 'EUR', 'USDT'], array_keys($rates));
            $this->assertSame(305110, app(ExchangeRate::class)->toToman('EUR'));
            $this->assertNull(app(ExchangeRate::class)->toToman('TRY'));
            return static function () use (&$applied): void { $applied = true; };
        });
        $this->assertTrue($applied);
        $this->assertSame(305110, $fx->toToman('EUR'));
        $this->assertNull($fx->toToman('TRY'));
        $this->assertNull(Setting::get('fx_last_try'));
        $this->assertArrayNotHasKey('TRY', $rows);
    }

    public function test_try_reads_never_fetch_or_extend_an_expired_quote(): void
    {
        $fx = app(ExchangeRate::class);
        $this->assertNull($fx->current('TRY'));
        $rows = NavasanRates::store();
        $this->assertSame(5000, $fx->toToman('TRY'));
        Cache::forget('fx.navasan.rates');
        $fallback = $fx->lastKnown('TRY');
        $this->assertSame($rows['TRY']['source_at'], $fallback['source_at']);
        $this->travel(49)->hours();
        $this->assertNull($fx->toToman('TRY'));
        Http::assertNothingSent();
    }
}
