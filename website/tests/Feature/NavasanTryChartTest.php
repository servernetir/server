<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\{ExchangeRate};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

class NavasanTryChartTest extends TestCase
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
        $r = [];
        foreach (['usd_shakhs' => 280000, 'eur_hav' => 308000, 'usd_usdt' => 269000, 'try_hav' => 371650] as $key => $value) {
            $r[$key] = ['value' => $value, 'date' => now()->subMinutes(2)->timestamp];
        }
        return $r;
    }

    private function chart(): array
    {
        return [[10, now()->subDays(2)->timestamp, 5300, 5400, 5290, 5350],
            [11, now()->subHours(5)->timestamp, 5400, 5450, 5390, 5420]];
    }

    public function test_rejected_remittance_uses_dated_navasan_cash_chart_in_coordinated_publication(): void
    {
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($this->payload()),
            ExchangeRate::TRY_CHART_URL.'*' => Http::response($this->chart())]);
        $fx = app(ExchangeRate::class);
        $applied = false;
        $rows = $fx->refreshAll(function (array $rates) use (&$applied): \Closure {
            $this->assertSame(5420, app(ExchangeRate::class)->toToman('TRY'));
            $this->assertSame('cash_daily_chart', $rates['TRY']['source_kind']);
            return static function () use (&$applied): void { $applied = true; };
        });
        $this->assertTrue($applied);
        $this->assertSame(5420, $rows['TRY']['rate_toman']);
        $this->assertSame($rows['TRY'], json_decode(Setting::get('fx_last_try'), true));
        $this->assertSame(now()->subHours(5)->toIso8601String(), $rows['TRY']['source_at']);
        Cache::forget('fx.navasan.rates');
        $this->assertSame(5420, $fx->toToman('TRY'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), ExchangeRate::TRY_CHART_URL)
            && $request['item'] === 'try' && $request->hasHeader('X-Requested-With', 'XMLHttpRequest'));
    }

    public function test_invalid_charts_never_fabricate_a_rate_or_extend_source_age(): void
    {
        $fx = app(ExchangeRate::class);
        $good = $this->chart();
        $bad = [[], ['html' => 5420], [[$good[1][0], $good[1][1], 5400, 5450, 5390]], array_reverse($good), [$good[1], $good[1]]];
        foreach ([0, 99, 100001, '5,420', 5420.5, null, true, '1e3'] as $value) {
            $p = $good; $p[1][5] = $value; $bad[] = $p;
        }
        foreach ([now()->subHours(49)->timestamp, now()->addMinutes(6)->timestamp, now()->timestamp * 1000] as $time) {
            $bad[] = [[11, $time, 5400, 5450, 5390, 5420]];
        }
        $bad[] = [[11, now()->timestamp, 5400, 5410, 5390, 5420]];
        foreach ($bad as $points) { $this->assertNull($fx->extractTryChart(json_encode($points))); }
        $this->assertNull($fx->extractTryChart('<script>5420</script>'));
        $row = $fx->extractTryChart(json_encode($good));
        foreach ([['source_url' => 'https://example.com/'], ['source_kind' => 'manual'], ['unit' => 'IRR']] as $change) {
            $invalid = array_replace($row, $change);
            Setting::put('fx_last_try', json_encode($invalid));
            Cache::put('fx.navasan.rates', ['TRY' => $invalid]);
            $this->assertNull($fx->toToman('TRY'));
        }
        Http::assertNothingSent();
    }

    public function test_chart_failure_keeps_core_rates_available_without_publishing_try(): void
    {
        Http::fake([ExchangeRate::SOURCE_URL.'*' => Http::response($this->payload()),
            ExchangeRate::TRY_CHART_URL.'*' => Http::response('unavailable', 503)]);
        $rows = app(ExchangeRate::class)->refreshAll();
        $this->assertSame(['USD', 'EUR', 'USDT'], array_keys($rows));
        $this->assertNull(app(ExchangeRate::class)->toToman('TRY'));
        Http::assertSentCount(2);
    }
}
