<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudDominance;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudDominancePartitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::put('cloud_margin_pct', '0');
        $this->mock(ExchangeRate::class, fn ($mock) => $mock->shouldReceive('toToman')->with('EUR')->andReturn(100000));
        $this->mock(CloudPricingSnapshot::class, fn ($mock) => $mock->shouldReceive('current')->andReturn(true));
        $this->mock(CloudManager::class, fn ($mock) => $mock->shouldReceive('capabilitiesForPlan')->andReturn([
            'console' => false, 'rebuild' => true,
        ]));
        CloudImage::create(['provider' => 'fixture', 'provider_ref' => 'ubuntu', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => 'x86', 'is_active' => true]);
    }

    private function plan(string $name, string $city, array $changes = []): CloudPlan
    {
        return new CloudPlan(array_replace([
            'provider' => 'fixture', 'provider_ref' => $name, 'location_code' => $city,
            'slug' => $name, 'public_name' => $name, 'vcpu' => 2, 'ram_mb' => 2048,
            'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 1000,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'price_eur_cents' => 200,
            'price_irt' => 200000, 'cost_eur_cents' => 100, 'setup_eur_cents' => 0,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $changes));
    }

    public function test_global_catalogue_keeps_the_same_products_and_order_as_pairwise_contract_checks(): void
    {
        $plans = collect([
            $this->plan('istanbul-dear', 'tr-istanbul-ii', ['price_irt' => 300000, 'price_eur_cents' => 300]),
            $this->plan('berlin', 'de-berlin'),
            $this->plan('istanbul-cheap', 'tr-istanbul'),
            $this->plan('frankfurt', 'de-frankfurt'),
            $this->plan('berlin-setup-tradeoff', 'de-berlin', ['price_irt' => 100000, 'price_eur_cents' => 100, 'setup_eur_cents' => 200]),
            $this->plan('berlin-arm', 'de-berlin', ['arch' => 'arm']),
            $this->plan('frankfurt-out-of-stock', 'de-frankfurt', ['in_stock' => false, 'price_irt' => 100000, 'price_eur_cents' => 100]),
        ]);
        $reference = $plans->reject(fn ($b) => $plans->contains(fn ($a) => CloudDominance::dominates($a, $b)))->values();
        $actual = CloudDominance::prune($plans);

        $this->assertSame($reference->all(), $actual->all());
        $this->assertSame(['berlin', 'istanbul-cheap', 'frankfurt', 'berlin-setup-tradeoff', 'berlin-arm', 'frankfurt-out-of-stock'], $actual->pluck('public_name')->all());
        Http::assertNothingSent();
    }

    public function test_unique_city_catalogue_remains_unchanged_without_loading_contract_dependencies(): void
    {
        $plans = collect(range(1, 800))->map(fn ($i) => $this->plan('plan-'.$i, 'fixture-city-'.$i));
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        $this->assertSame($plans->all(), CloudDominance::prune($plans)->all());
        $this->assertSame([], \Illuminate\Support\Facades\DB::getQueryLog());
        Http::assertNothingSent();
    }
}
