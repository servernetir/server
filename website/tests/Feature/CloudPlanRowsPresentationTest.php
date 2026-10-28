<?php

namespace Tests\Feature;

use App\Http\Controllers\CloudCatalogController;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Cloud\CloudDominance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class CloudPlanRowsPresentationTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $locations = collect([
            new CountingPresentationLocation(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt']),
            new CountingPresentationLocation(['code' => 'tr-istanbul', 'country' => 'TR', 'city' => 'Istanbul',
                'label_fa' => 'استانبول اصلی', 'label_en' => 'Istanbul primary', 'label_tr' => 'İstanbul ana']),
            new CountingPresentationLocation(['code' => 'tr-istanbul-ii', 'country' => 'TR', 'city' => 'Istanbul',
                'label_fa' => 'استانبول دیگر', 'label_en' => 'Istanbul alternate', 'label_tr' => 'İstanbul diğer']),
        ])->keyBy('code');
        $offers = collect([
            $this->plan('first', 'de-frankfurt', 200000),
            $this->plan('second', 'de-frankfurt', 100000),
            $this->plan('primary', 'tr-istanbul', 300000),
            $this->plan('alias', 'tr-istanbul-ii', 400000),
            $this->plan('missing', 'unknown-city', 500000),
        ])->keyBy('slug');
        $hourly = collect([
            $this->plan('first', 'de-frankfurt', 200000),
            $this->plan('second', 'de-frankfurt', 100000),
            $this->plan('primary', 'tr-istanbul', 300000),
            $this->plan('alias', 'tr-istanbul-ii', 400000),
        ])->keyBy('slug');
        $hourly['second']->fixtureIrt = 0;
        $hourly['primary']->fixtureMetal = true;
        $hourly['alias']->fixtureEur = 0;

        return [$offers, $locations, $hourly];
    }

    private function plan(string $slug, string $city, int $price): CountingPresentationPlan
    {
        // Keep the fixture rows through pruning without needing provider services.
        return new CountingPresentationPlan(['slug' => $slug, 'public_name' => $slug, 'location_code' => $city,
            'is_active' => false, 'price_irt' => $price, 'price_eur_cents' => 250, 'vcpu' => 2,
            'ram_mb' => 2048, 'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 1000, 'cpu_kind' => 'shared']);
    }

    public function test_rows_match_the_previous_mapping_in_all_locales_with_repeated_alias_and_missing_locations(): void
    {
        Http::preventStrayRequests();
        $controller = app(CloudCatalogController::class);
        $method = new ReflectionMethod($controller, 'planRows');
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            [$offers, $locations, $hourly] = $this->fixtures();
            $before = $this->previousRows($controller, $offers, $locations, $hourly);
            $locations->each(fn ($location) => $location->labelCalls = 0);
            $hourly->each(function ($plan) { $plan->irtCalls = $plan->eurCalls = 0; });
            $after = $method->invoke($controller, $offers, $locations, $hourly);
            $this->assertSame($before, $after, $locale);
            $this->assertCount(4, $after);
            $this->assertSame(1, $locations['de-frankfurt']->labelCalls);
            $this->assertSame(1, $locations['tr-istanbul']->labelCalls);
            $this->assertSame(1, $locations['tr-istanbul-ii']->labelCalls);
            $this->assertNotSame($after[2]['loc'], $after[3]['loc']);
            $this->assertSame('tr-istanbul', $after[2]['loc_code']);
            $this->assertSame('tr-istanbul', $after[3]['loc_code']);
            $this->assertSame([1, 1], [$hourly['first']->irtCalls, $hourly['first']->eurCalls]);
            $this->assertSame([1, 0], [$hourly['second']->irtCalls, $hourly['second']->eurCalls]);
            $this->assertSame([0, 0], [$hourly['primary']->irtCalls, $hourly['primary']->eurCalls]);
            $this->assertSame([1, 1], [$hourly['alias']->irtCalls, $hourly['alias']->eurCalls]);
        }
        Http::assertNothingSent();
    }

    public function test_mapping_without_hourly_offers_does_not_calculate_hourly_prices(): void
    {
        Http::preventStrayRequests();
        [$offers, $locations] = $this->fixtures();
        $rows = (new ReflectionMethod(CloudCatalogController::class, 'planRows'))
            ->invoke(app(CloudCatalogController::class), $offers, $locations);
        $this->assertSame([null, null, null, null], array_column($rows, 'hourly_price'));
        $this->assertSame([null, null, null, null], array_column($rows, 'hourly_buy_url'));
        foreach ($offers as $plan) { $this->assertSame([0, 0], [$plan->irtCalls, $plan->eurCalls]); }
        Http::assertNothingSent();
    }

    /** Frozen pre-optimization display mapping: the public row contract must stay identical. */
    private function previousRows(CloudCatalogController $controller, Collection $offers, Collection $locations, Collection $hourlyOffers): array
    {
        $rows = [];
        $locUrl = new ReflectionMethod($controller, 'locUrl');
        $buyUrl = new ReflectionMethod($controller, 'buyUrl');
        foreach (CloudDominance::prune($offers->values()) as $p) {
            $loc = $locations->get($p->location_code);
            if ($loc === null) { continue; }
            $hourly = $hourlyOffers->get($p->slug);
            $hasHourly = $hourly !== null && ! $hourly->isMetal() && $hourly->hourlyIrt() > 0 && $hourly->hourlyEurCents() > 0;
            $hourlyPrice = $hasHourly ? (app()->getLocale() === 'fa'
                ? fa_num(number_format($hourly->hourlyIrt())).' تومان'
                : '€'.number_format($hourly->hourlyEurCents() / 100, 2)) : null;
            $rows[] = [
                'slug' => (string) $p->slug, 'name' => (string) $p->public_name,
                'vcpu' => (int) $p->vcpu, 'ram_mb' => (int) $p->ram_mb,
                'ram' => $p->ramLabel(), 'disk' => $p->diskLabel(), 'traffic' => $p->trafficLabel(),
                'cpu_kind' => $p->cpuKindLabel(), 'price' => $p->priceLabel(),
                'price_n' => (int) $p->price_irt, 'eur' => (int) $p->price_eur_cents,
                'loc_code' => CloudLocation::canonicalCode((string) $p->location_code),
                'loc' => $loc->label(), 'city' => $loc->cityLabel() !== '' ? $loc->cityLabel() : $loc->countryLabel(),
                'country' => strtoupper((string) $loc->country), 'country_label' => $loc->countryLabel(),
                'flag' => $loc->flagEmoji(), 'flag_svg' => $loc->flagSvg(),
                'loc_url' => $locUrl->invoke($controller, (string) $p->location_code),
                'buy_url' => $buyUrl->invoke($controller, (string) $p->location_code, (string) $p->slug),
                'hourly_price' => $hourlyPrice,
                'hourly_buy_url' => $hasHourly ? $buyUrl->invoke($controller, (string) $p->location_code, (string) $p->slug, 'hourly') : null,
            ];
        }
        usort($rows, fn ($a, $b) => $a['price_n'] <=> $b['price_n'] ?: $a['vcpu'] <=> $b['vcpu'] ?: strcmp($a['loc_code'], $b['loc_code']));
        return $rows;
    }
}

class CountingPresentationLocation extends CloudLocation
{
    public int $labelCalls = 0;

    public function label(?string $locale = null): string
    {
        $this->labelCalls++;
        return parent::label($locale);
    }
}

class CountingPresentationPlan extends CloudPlan
{
    public int $irtCalls = 0;
    public int $eurCalls = 0;
    public int $fixtureIrt = 3500;
    public int $fixtureEur = 4;
    public bool $fixtureMetal = false;

    public function hourlyIrt(): int { $this->irtCalls++; return $this->fixtureIrt; }
    public function hourlyEurCents(): int { $this->eurCalls++; return $this->fixtureEur; }
    public function isMetal(): bool { return $this->fixtureMetal; }
}
