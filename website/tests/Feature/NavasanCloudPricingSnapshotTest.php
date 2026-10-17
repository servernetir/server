<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Services\Cloud\CloudPricingSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class NavasanCloudPricingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Cache::forget('fx.navasan.rates');
    }

    public function test_cloud_sale_closes_until_catalog_prices_match_current_navasan_rates(): void
    {
        NavasanRates::store();
        $snapshot = app(CloudPricingSnapshot::class);
        $snapshot->publish([100000, 200000, 150000]);
        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'test-1', 'provider_location' => 'fsn1',
            'location_code' => 'de-fsn1', 'public_name' => 'CV-1', 'slug' => 'cv-test',
            'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20, 'disk_type' => 'nvme',
            'traffic_gb' => 1000, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 100, 'price_eur_cents' => 120, 'price_irt' => 240000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ]);

        $this->assertSame(1, CloudPlan::query()->sellable()->count());
        $this->assertNull($plan->blockedReason());

        NavasanRates::store(['USD' => 110000, 'EUR' => 210000, 'USDT' => 160000]);
        $this->assertSame(0, CloudPlan::query()->sellable()->count());
        $this->assertSame('price', $plan->fresh()->blockedReason());

        $snapshot->publish([110000, 210000, 160000]);
        $this->assertSame(1, CloudPlan::query()->sellable()->count());
    }
}
