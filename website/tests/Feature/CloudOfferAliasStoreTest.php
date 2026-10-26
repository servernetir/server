<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\Cloud\CloudPricingSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

/** Exercise the actual store GET, including its legacy alias and hourly price maps. */
class CloudOfferAliasStoreTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        NavasanRates::store(['USD' => 100000, 'EUR' => 100000, 'USDT' => 100000, 'TRY' => 5000]);
        Setting::put('cloud_margin_pct', '0');
        $this->mock(CloudPricingSnapshot::class, function ($mock) {
            $mock->shouldReceive('current')->andReturn(true);
            $mock->shouldReceive('excludedProviders')->andReturn([]);
        });
        CloudLocation::create(['code' => 'tr-istanbul', 'country' => 'TR', 'city' => 'Istanbul', 'is_active' => true]);
        CloudImage::create(['provider' => 'hetzner', 'provider_ref' => 'fixture-ubuntu', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => 'x86', 'min_ram_mb' => 512, 'min_disk_gb' => 10, 'is_active' => true]);
        $customer = Customer::create(['email' => 'offer-alias@example.test', 'phone' => '09121234567',
            'password' => 'fixture-secret', 'status' => 'active', 'locale' => 'fa']);
        $this->actingAs($customer, 'customer');
    }

    private function plan(string $slug, int $retailIrt, int $retailEur, int $cost = 100): CloudPlan
    {
        $this->sequence++;
        return CloudPlan::create(['provider' => 'hetzner', 'provider_ref' => 'fixture-'.$this->sequence,
            'provider_location' => 'istanbul', 'location_code' => 'tr-istanbul',
            'public_name' => 'CV-2-4', 'slug' => $slug, 'vcpu' => 2, 'ram_mb' => 4096,
            'disk_gb' => 50, 'disk_type' => 'nvme', 'cpu_kind' => 'shared', 'arch' => 'x86',
            'traffic_gb' => 1000, 'network_terms' => ['charge_mode' => 'hard_cap', 'extra_charges' => false],
            'cost_eur_cents' => $cost, 'price_eur_cents' => $retailEur, 'price_irt' => $retailIrt,
            'setup_eur_cents' => 0, 'is_active' => true, 'in_stock' => true, 'admin_disabled' => false]);
    }

    public function test_normal_store_get_hides_the_more_expensive_equivalent_alias(): void
    {
        $this->plan('old-alias', 300000, 300);
        $this->plan('cheaper-alias', 200000, 200);
        $response = $this->get(route('account.cloud.store', ['location' => 'tr-istanbul'], false))->assertOk();
        $response->assertViewHas('planCards', fn ($cards) => array_column($cards, 'slug') === ['cheaper-alias']);
        $response->assertViewHas('selectedSlug', 'cheaper-alias')->assertViewHas('planMoved', false);
        Http::assertNothingSent();
    }

    public function test_requested_legacy_alias_remains_selected_and_available_for_checkout(): void
    {
        $old = $this->plan('old-alias', 300000, 300);
        $this->plan('cheaper-alias', 200000, 200);
        $response = $this->get(route('account.cloud.store', ['location' => 'tr-istanbul', 'plan' => 'old-alias'], false))->assertOk();
        $response->assertViewHas('selectedSlug', 'old-alias')->assertViewHas('planMoved', false)
            ->assertViewHas('planCards', fn ($cards) => in_array('old-alias', array_column($cards, 'slug'), true)
                && in_array('cheaper-alias', array_column($cards, 'slug'), true));
        $this->assertSame($old->id, CloudPlan::offers('tr-istanbul')->get('old-alias')?->id);
        Http::assertNothingSent();
    }

    public function test_hourly_display_uses_the_same_retail_representative_as_checkout(): void
    {
        $expensive = $this->plan('same-slug', 720000, 720, 100);
        $cheap = $this->plan('same-slug', 360000, 360, 120);
        $this->assertGreaterThan($cheap->hourlyIrt(), $expensive->hourlyIrt());
        // CloudStoreController::order selects its hourly offer through this exact path.
        $checkout = CloudPlan::offers('tr-istanbul', true)->get('same-slug');
        $this->assertSame($cheap->id, $checkout?->id);
        $response = $this->get(route('account.cloud.store', ['location' => 'tr-istanbul'], false))->assertOk();
        $response->assertViewHas('hourlyMap', fn ($map) => ($map['same-slug']['rate'] ?? null) === $checkout->hourlyIrt());
        Http::assertNothingSent();
    }
}
