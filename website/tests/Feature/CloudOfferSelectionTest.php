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

class CloudOfferSelectionTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::put('cloud_margin_pct', '0');
        $this->mock(ExchangeRate::class, fn ($mock) => $mock->shouldReceive('toToman')->with('EUR')->andReturn(100000));
        $this->mock(CloudPricingSnapshot::class, function ($mock) {
            $mock->shouldReceive('current')->andReturn(true);
            $mock->shouldReceive('excludedProviders')->andReturn([]);
        });
        $this->mock(CloudManager::class, fn ($mock) => $mock->shouldReceive('capabilitiesForPlan')->andReturn([
            'console' => false, 'rebuild' => true, 'reset_password' => true, 'extra_ip' => false,
        ]));
    }

    private function plan(array $changes = []): CloudPlan
    {
        $this->sequence++;
        return CloudPlan::create(array_replace([
            'provider' => 'fixture-a', 'provider_ref' => 'resource-'.$this->sequence, 'provider_location' => 'istanbul',
            'location_code' => 'tr-istanbul', 'slug' => 'cv-fixture-'.$this->sequence, 'public_name' => 'CV-2-4',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 50, 'disk_type' => 'nvme', 'cpu_kind' => 'shared',
            'arch' => 'x86', 'traffic_gb' => 1000, 'network_terms' => ['charge_mode' => 'hard_cap', 'extra_charges' => false],
            'cost_eur_cents' => 100, 'price_eur_cents' => 200, 'price_irt' => 200000, 'setup_eur_cents' => 0,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $changes));
    }

    private function image(string $provider, string $family = 'ubuntu', string $key = 'ubuntu-24.04'): void
    {
        CloudImage::create(['provider' => $provider, 'provider_ref' => $provider.'-'.$key, 'key' => $key,
            'kind' => 'os', 'family' => $family, 'version' => $family === 'windows' ? '2022' : '24.04',
            'label' => $key, 'arch' => 'x86', 'min_ram_mb' => 1024, 'min_disk_gb' => 20, 'is_active' => true]);
    }

    public function test_istanbul_aliases_share_one_sales_page_and_cheapest_equivalent_offer(): void
    {
        $this->image('fixture-a'); $this->image('fixture-b');
        foreach (['tr-istanbul' => 'Istanbul', 'tr-istanbul-ii' => 'Istanbul II'] as $code => $city) {
            \App\Models\CloudLocation::create(['code' => $code, 'city' => $city, 'country' => 'TR', 'is_active' => true]);
        }
        $old = $this->plan(['provider' => 'fixture-a', 'location_code' => 'tr-istanbul-ii',
            'price_irt' => 300000, 'price_eur_cents' => 300]);
        $best = $this->plan(['provider' => 'fixture-b', 'price_irt' => 200000, 'price_eur_cents' => 200]);
        $groups = app(\App\Http\Controllers\Account\CloudStoreController::class)->locationGroups();
        $this->assertSame(['tr-istanbul'], collect($groups)->flatMap(fn ($group) => $group['locations'])->pluck('code')->all());
        $this->assertCount(2, CloudPlan::offers('tr-istanbul'));
        $this->assertSame([$best->id], CloudDominance::prune(CloudPlan::offers('tr-istanbul'))->pluck('id')->all());
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->get($prefix.'/cloud/tr-istanbul-ii')->assertStatus(301)
                ->assertRedirect(url($prefix.'/vps/turkiye'));
            $this->get($prefix.'/cloud/tr-istanbul')->assertStatus(301)->assertRedirect(url($prefix.'/vps/turkiye'));
            $this->get($prefix.'/vps/turkiye')->assertOk()
                ->assertSee($best->slug)->assertDontSee($old->slug);
        }
        $this->assertSame('tr-istanbul-ii', $old->fresh()->location_code);
        $this->assertSame($old->id, CloudPlan::bestForSlug($old->slug)?->id);
        Http::assertNothingSent();
    }

    public function test_same_contract_chooses_customer_price_instead_of_lowest_supplier_cost(): void
    {
        $this->image('fixture-a');
        $this->plan(['slug' => 'same-public-slug', 'cost_eur_cents' => 100, 'price_irt' => 200000, 'price_eur_cents' => 200]);
        $best = $this->plan(['slug' => 'same-public-slug', 'cost_eur_cents' => 120, 'price_irt' => 150000, 'price_eur_cents' => 150]);
        $this->assertSame($best->id, CloudPlan::offers('tr-istanbul')->sole()->id);
        $this->assertSame($best->id, CloudPlan::shelf('tr-istanbul')->sole()->id);
        $this->assertSame($best->id, CloudPlan::bestForSlug('same-public-slug')?->id);
        $this->assertSame(150000, $best->fresh()->price_irt);
        $this->assertSame(120, $best->fresh()->cost_eur_cents);
        Http::assertNothingSent();
    }

    public function test_equivalent_cross_provider_more_expensive_display_alias_is_hidden_but_checkout_stays_valid(): void
    {
        $this->image('fixture-a'); $this->image('fixture-b');
        $old = $this->plan(['provider' => 'fixture-a', 'slug' => 'old-alias', 'price_irt' => 300000, 'price_eur_cents' => 300]);
        $best = $this->plan(['provider' => 'fixture-b', 'slug' => 'new-alias', 'price_irt' => 200000, 'price_eur_cents' => 200]);
        // Shelf retains aliases until the store can preserve ?plan= links.
        $this->assertSame([$best->id], CloudDominance::prune(CloudPlan::shelf('tr-istanbul'))->pluck('id')->all());
        $this->assertSame($old->id, CloudPlan::offers('tr-istanbul')->get('old-alias')?->id);
        $this->assertSame($old->id, CloudPlan::bestForSlug('old-alias')?->id);
        Http::assertNothingSent();
    }

    public function test_same_slug_setup_tradeoff_keeps_the_original_representative(): void
    {
        $this->image('fixture-a');
        $original = $this->plan(['slug' => 'legacy-same', 'cost_eur_cents' => 90,
            'price_irt' => 150000, 'price_eur_cents' => 150, 'setup_eur_cents' => 0]);
        $this->plan(['slug' => 'legacy-same', 'cost_eur_cents' => 100,
            'price_irt' => 100000, 'price_eur_cents' => 100, 'setup_eur_cents' => 100]);
        $this->assertSame($original->id, CloudPlan::offers()->sole()->id);
        $this->assertSame($original->id, CloudPlan::shelf()->sole()->id);
    }

    public function test_same_slug_without_matching_os_proof_does_not_switch_to_a_cheaper_different_contract(): void
    {
        $this->image('fixture-a');
        $original = $this->plan(['slug' => 'legacy-same', 'provider' => 'fixture-a', 'cost_eur_cents' => 90,
            'price_irt' => 200000, 'price_eur_cents' => 200]);
        $this->plan(['slug' => 'legacy-same', 'provider' => 'fixture-b', 'cost_eur_cents' => 100,
            'price_irt' => 100000, 'price_eur_cents' => 100]);
        $this->assertSame($original->id, CloudPlan::offers()->sole()->id);
        $this->assertSame($original->id, CloudPlan::bestForSlug('legacy-same')?->id);
    }

    public function test_missing_os_proof_or_different_capabilities_keeps_distinct_provider_offers(): void
    {
        $this->plan(['provider' => 'fixture-a', 'price_irt' => 100000, 'price_eur_cents' => 100]);
        $this->plan(['provider' => 'fixture-b', 'price_irt' => 200000, 'price_eur_cents' => 200]);
        $this->assertCount(2, CloudPlan::shelf('tr-istanbul'));
        $this->image('fixture-a'); $this->image('fixture-b');
        $this->mock(CloudManager::class, fn ($mock) => $mock->shouldReceive('capabilitiesForPlan')->andReturnUsing(
            fn ($plan) => ['console' => $plan->provider === 'fixture-b', 'rebuild' => true]
        ));
        $this->assertCount(2, CloudPlan::shelf('tr-istanbul'));
    }

    public function test_windows_catalogs_do_not_imply_equivalent_supplier_licence_terms(): void
    {
        $this->image('fixture-a', 'windows', 'windows-2022');
        $this->image('fixture-b', 'windows', 'windows-2022');
        $this->plan(['provider' => 'fixture-a', 'price_irt' => 100000, 'price_eur_cents' => 100]);
        $this->plan(['provider' => 'fixture-b', 'price_irt' => 200000, 'price_eur_cents' => 200]);
        $this->assertCount(2, CloudPlan::shelf('tr-istanbul'));
    }

    public function test_first_payment_and_renewal_tradeoffs_remain_visible(): void
    {
        $this->image('fixture-a');
        $a = $this->plan(['price_irt' => 100000, 'price_eur_cents' => 100, 'setup_eur_cents' => 100]);
        $b = $this->plan(['price_irt' => 150000, 'price_eur_cents' => 150, 'setup_eur_cents' => 0]);
        $this->assertCount(2, CloudPlan::shelf('tr-istanbul'));
        $this->assertCount(2, CloudDominance::prune(collect([$a, $b])));
    }

    public function test_different_location_and_country_are_not_removed_by_a_cheaper_plan(): void
    {
        $cheap = $this->plan(['location_code' => 'de-frankfurt', 'price_irt' => 100000, 'price_eur_cents' => 100]);
        $otherCity = $this->plan(['location_code' => 'de-berlin', 'price_irt' => 200000, 'price_eur_cents' => 200]);
        $otherCountry = $this->plan(['location_code' => 'tr-istanbul', 'price_irt' => 300000, 'price_eur_cents' => 300]);
        $this->assertCount(3, CloudPlan::shelf());
        $this->assertCount(3, CloudDominance::prune(collect([$cheap, $otherCity, $otherCountry])));
    }

    public function test_architecture_cpu_disk_traffic_and_network_value_remain_distinct(): void
    {
        $this->image('fixture-a');
        $base = $this->plan(['price_irt' => 100000, 'price_eur_cents' => 100]);
        $others = collect([
            $this->plan(['arch' => 'arm']), $this->plan(['cpu_kind' => 'dedicated']),
            $this->plan(['disk_type' => 'ssd']), $this->plan(['traffic_gb' => 2000]),
            $this->plan(['network_terms' => ['charge_mode' => 'unmetered', 'extra_charges' => false]]),
        ]);
        $this->assertCount(6, CloudPlan::shelf('tr-istanbul'));
        foreach ($others as $other) { $this->assertFalse(CloudDominance::dominates($base, $other)); }
    }

    public function test_monthly_only_purchase_does_not_hide_the_hourly_capable_offer(): void
    {
        $a = $this->plan(['provider' => 'aeza', 'cost_hour_eur_micro' => null, 'price_irt' => 100000, 'price_eur_cents' => 100]);
        $b = $this->plan(['provider' => 'hetzner', 'price_irt' => 200000, 'price_eur_cents' => 200]);
        $this->image('aeza'); $this->image('hetzner');
        $this->assertCount(2, CloudPlan::shelf('tr-istanbul'));
        $this->assertFalse(CloudDominance::dominates($a, $b));
        $this->assertSame([$b->id], CloudPlan::offers('tr-istanbul', true)->pluck('id')->all());
    }

    public function test_stock_and_disabled_provider_fallback_remain_the_source_of_truth(): void
    {
        $this->plan(['provider' => 'fixture-a', 'slug' => 'same', 'price_irt' => 100000, 'price_eur_cents' => 100, 'in_stock' => false]);
        $good = $this->plan(['provider' => 'fixture-b', 'slug' => 'same', 'price_irt' => 200000, 'price_eur_cents' => 200]);
        $this->assertSame($good->id, CloudPlan::offers('tr-istanbul')->sole()->id);
        $this->assertSame($good->id, CloudPlan::shelf('tr-istanbul')->sole()->id);
        CloudPlan::where('provider', 'fixture-a')->update(['in_stock' => true]);
        Setting::put('cloud_disabled_providers', 'fixture-a');
        $this->assertSame($good->id, CloudPlan::bestForSlug('same')?->id);
        $this->assertSame($good->id, CloudPlan::shelf('tr-istanbul')->sole()->id);
    }

    public function test_stale_supplier_prices_cannot_replace_a_current_offer(): void
    {
        $this->plan(['provider' => 'fixture-a', 'slug' => 'same', 'price_irt' => 100000, 'price_eur_cents' => 100]);
        $current = $this->plan(['provider' => 'fixture-b', 'slug' => 'same', 'price_irt' => 200000, 'price_eur_cents' => 200]);
        $this->mock(CloudPricingSnapshot::class, function ($mock) {
            $mock->shouldReceive('current')->andReturnUsing(fn ($provider = null) => $provider !== 'fixture-a');
            $mock->shouldReceive('excludedProviders')->andReturn(['fixture-a']);
        });
        $this->assertSame($current->id, CloudPlan::offers()->sole()->id);
        $this->assertSame($current->id, CloudPlan::shelf()->sole()->id);
    }
}
