<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudCatalogSync;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\Domain\TldPriceBook;
use App\Services\ExchangeRate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Support\NavasanRates;

class FetchDollarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mock(TldPriceBook::class)->shouldReceive('refreshAll')->byDefault()
            ->andReturn(['count' => 10, 'total' => 10, 'excluded' => 0]);
    }

    private function rates(): array
    {
        $rows = [];
        foreach (['USD' => 92500, 'EUR' => 110000, 'USDT' => 94000, 'TRY' => 5000] as $currency => $rate) {
            $rows[$currency] = ['currency' => $currency, 'rate_toman' => $rate, 'unit' => 'IRT',
                'source' => 'navasan.net', 'source_item' => ExchangeRate::SOURCE_ITEMS[$currency], 'source_at' => now()->toIso8601String(), 'at' => now()->toIso8601String()];
        }

        return $rows;
    }

    public function test_success_refreshes_all_four_currencies_then_reprices_the_catalog(): void
    {
        Setting::put('cloud_margin_pct', '50');
        NavasanRates::store(['USD' => 92500, 'EUR' => 100000, 'USDT' => 94000, 'TRY' => 5000]);
        app(CloudPricingSnapshot::class)->publish([92500, 100000, 94000]);
        \App\Models\CloudLocation::create([
            'code' => 'de-falkenstein', 'country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true,
        ]);
        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'fx-test',
            'location_code' => 'de-falkenstein', 'public_name' => 'FX Test',
            'slug' => 'fx-test', 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20,
            'cost_eur_cents' => 1000, 'price_eur_cents' => 1500, 'price_irt' => 1000000,
            'is_active' => true, 'in_stock' => true,
        ]);
        $this->assertSame(1000000, \App\Services\Cloud\CloudCountry::served()['DE']['cheapest_irt']);
        $rows = $this->rates();
        $this->partialMock(ExchangeRate::class, function ($mock) use ($rows) {
            $mock->shouldReceive('refreshAll')->once()->andReturnUsing(function () use ($rows) {
                foreach ($rows as $currency => $row) {
                    Setting::put('fx_last_'.strtolower($currency), json_encode($row, JSON_THROW_ON_ERROR));
                }
                Cache::put('fx.navasan.rates', $rows, now()->addHours(6));
                return $rows;
            });
        });
        Cache::put('page:gen', 10);

        $this->artisan('fx:dollar')
            ->expectsOutputToContain('USD: 92,500 تومان · EUR: 110,000 تومان · USDT: 94,000 تومان · TRY: 5,000 تومان')
            ->assertSuccessful();

        $this->assertSame(1650000, (int) $plan->fresh()->price_irt);
        $this->assertSame(1650000, \App\Services\Cloud\CloudCountry::served()['DE']['cheapest_irt']);
        $this->assertGreaterThan(10, Cache::get('page:gen'));
        Http::assertNothingSent();
    }

    public function test_show_reads_all_four_cached_rates_without_refreshing_or_repricing(): void
    {
        foreach ($this->rates() as $currency => $row) {
            Cache::put('fx.'.strtolower($currency).'_irt', $row, now()->addHour());
        }
        $this->mock(CloudCatalogSync::class)->shouldNotReceive('reprice');
        $this->mock(TldPriceBook::class)->shouldNotReceive('refreshAll');

        $this->artisan('fx:dollar --show')
            ->expectsOutputToContain('USD: 92,500')
            ->expectsOutputToContain('EUR: 110,000')
            ->expectsOutputToContain('USDT: 94,000')
            ->expectsOutputToContain('TRY: 5,000')
            ->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_show_mode_handles_empty_cache(): void
    {
        $this->artisan('fx:dollar --show')
            ->expectsOutputToContain('هنوز نرخی ذخیره نشده')
            ->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_failed_batch_does_not_reprice_or_purge_previous_prices(): void
    {
        $this->mock(ExchangeRate::class)->shouldReceive('refreshAll')->once()->andReturnNull();
        $this->mock(CloudCatalogSync::class)->shouldNotReceive('reprice');
        $this->mock(TldPriceBook::class)->shouldNotReceive('refreshAll');
        Cache::put('page:gen', 10);

        $this->artisan('fx:dollar')->assertFailed();

        $this->assertSame(10, Cache::get('page:gen'));
        Http::assertNothingSent();
    }

    public function test_repricing_failure_is_reported_as_failure(): void
    {
        $this->mock(ExchangeRate::class)->shouldReceive('refreshAll')->once()->andReturn($this->rates());
        $this->mock(CloudCatalogSync::class)->shouldReceive('reprice')->once()
            ->andThrow(new \RuntimeException('Synthetic repricing failure'));
        $this->mock(TldPriceBook::class)->shouldReceive('refreshAll')->once()
            ->andReturn(['count' => 10, 'total' => 10, 'excluded' => 0]);

        $this->artisan('fx:dollar')->assertFailed();
    }

    public function test_incomplete_domain_prices_make_the_sync_fail(): void
    {
        $this->mock(ExchangeRate::class)->shouldReceive('refreshAll')->once()->andReturn($this->rates());
        $this->mock(CloudCatalogSync::class)->shouldReceive('reprice')->once()->andReturn(0);
        $this->mock(TldPriceBook::class)->shouldReceive('refreshAll')->once()
            ->andThrow(new \RuntimeException('Openprovider catalogue pagination is incomplete.'));

        $this->artisan('fx:dollar')->assertFailed();
    }

    public function test_rates_and_product_sync_run_every_six_hours_with_overlap_protection(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'fx:dollar'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('0 */6 * * *', $event->expression);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        // Existing domain warm-up cadence is preserved by this recovery.
    }

    public function test_daily_price_sync_runs_in_background_without_delaying_other_scheduled_work(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'cloud:sync --prices'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('40 5 * * *', $event->expression);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
    }
}
