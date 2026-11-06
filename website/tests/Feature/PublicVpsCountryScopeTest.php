<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\PublicVpsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

class PublicVpsCountryScopeTest extends TestCase
{
    use RefreshDatabase, SeedsCatalogPricing;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seedCatalogPricing();
        $this->mock(CloudManager::class, fn ($mock) => $mock->shouldReceive('capabilitiesForPlan')->andReturn([
            'console' => false, 'rebuild' => true,
        ]));
        foreach (['fixture', 'hetzner'] as $provider) {
            CloudImage::create(['provider' => $provider, 'provider_ref' => 'ubuntu', 'key' => 'ubuntu-24.04',
                'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu',
                'arch' => 'x86', 'is_active' => true]);
        }
    }

    private function location(string $code, string $country, bool $active = true): CloudLocation
    {
        return new CloudLocation(['code' => $code, 'country' => $country, 'city' => $code, 'is_active' => $active]);
    }

    private function plan(string $name, string $city, array $changes = []): PublicCatalogCountingPlan
    {
        return new PublicCatalogCountingPlan(array_replace([
            'provider' => 'fixture', 'provider_ref' => $name, 'location_code' => $city,
            'slug' => $name, 'public_name' => $name, 'vcpu' => 2, 'ram_mb' => 2048,
            'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 1000,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'price_eur_cents' => 200,
            'price_irt' => 200000, 'cost_eur_cents' => 100, 'setup_eur_cents' => 0,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $changes));
    }

    /** Existing unscoped path is the reference; injected locations already delimit its displayed rows. */
    private function assertEquivalent(string $country, Collection $offers, Collection $hourly, Collection $locations, array $rates = []): array
    {
        $catalog = app(PublicVpsCatalog::class);
        $expected = $catalog->rows(null, $offers, $hourly, $locations, $rates);
        // Simulate only the candidate's canonical-city restriction, even on the baseline source.
        $cities = $locations->keys()->map(fn ($code) => CloudLocation::canonicalCode((string) $code))->flip();
        $candidate = $offers->filter(fn ($plan) => $cities->has(CloudLocation::canonicalCode($plan->location_code)));
        $this->assertSame($expected, $catalog->rows(null, $candidate, $hourly, $locations, $rates));
        $this->assertSame($expected, $catalog->rows($country, $offers, $hourly, $locations, $rates));
        Http::assertNothingSent();

        return $expected;
    }

    public function test_country_rows_match_the_full_catalogue_in_all_locales_and_skip_unrelated_prune_work(): void
    {
        $offers = collect();
        for ($city = 0; $city < 50; $city++) {
            $code = $city === 0 ? 'ph-manila' : 'de-fixture-'.$city;
            for ($size = 1; $size <= 16; $size++) {
                $plan = $this->plan('city-'.$city.'-size-'.$size, $code, [
                    'vcpu' => $size, 'ram_mb' => $size * 1024, 'disk_gb' => $size * 20,
                    'price_irt' => $size * 100000, 'price_eur_cents' => $size * 100,
                ]);
                $plan->id = $offers->count() + 1;
                $offers->push($plan);
            }
        }
        $locations = collect(['ph-manila' => $this->location('ph-manila', 'PH')]);
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $this->assertCount(16, $this->assertEquivalent('PH', $offers, collect(), $locations));
        }
        app()->setLocale('fa');
        $timings = ['unscoped_ms' => [], 'country_ms' => []];
        $catalog = app(PublicVpsCatalog::class);
        for ($run = 0; $run < 3; $run++) {
            foreach (['unscoped_ms' => null, 'country_ms' => 'PH'] as $key => $country) {
                $start = hrtime(true);
                $rows = $catalog->rows($country, $offers, collect(), $locations);
                $timings[$key][] = round((hrtime(true) - $start) / 1e6, 3);
            }
        }
        foreach ($offers as $plan) {
            $plan->reads = [];
        }
        $catalog->rows('PH', $offers, collect(), $locations);
        $unrelatedArchReads = $offers->slice(16)->sum(fn ($plan) => $plan->reads['arch'] ?? 0);
        if (getenv('SERVERNET_CATALOG_PROFILE')) {
            fwrite(STDERR, 'PUBLIC_CATALOG_PROFILE '.json_encode($timings + [
                'synthetic' => true, 'input_plans' => 800, 'input_cities' => 50, 'output_rows' => count($rows),
                'full_rows_sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'unrelated_arch_reads_country' => $unrelatedArchReads,
            ], JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
        $this->assertSame(0, $unrelatedArchReads, 'Other canonical cities must not enter the country dominance pass.');
        Http::assertNothingSent();
    }

    public function test_inactive_canonical_city_offer_still_dominates_its_visible_alias(): void
    {
        $canonical = $this->plan('canonical-cheap', 'tr-istanbul');
        $alias = $this->plan('alias-dear', 'tr-istanbul-ii', ['price_irt' => 300000, 'price_eur_cents' => 300]);
        // The inactive canonical location is deliberately absent, while its active alias is present.
        $locations = collect(['tr-istanbul-ii' => $this->location('tr-istanbul-ii', 'TR')]);
        $offers = collect([$alias, $canonical]);
        $this->assertSame([], $this->assertEquivalent('TR', $offers, collect(), $locations));
        $canonical->price_irt = 400000;
        $canonical->price_eur_cents = 400;
        $rows = $this->assertEquivalent('TR', $offers, collect(), $locations);
        $this->assertSame(['alias-dear'], array_column($rows, 'slug'));
        $this->assertSame('tr-istanbul', $rows[0]['location']);
    }

    public function test_global_same_slug_representative_is_not_reselected_for_one_country(): void
    {
        foreach (['de-berlin' => 'DE', 'ph-manila' => 'PH'] as $code => $country) {
            CloudLocation::create(['code' => $code, 'country' => $country, 'city' => $code, 'is_active' => true]);
        }
        foreach ([['de-berlin', 100, 'shared-slug'], ['ph-manila', 200, 'shared-slug'], ['ph-manila', 300, 'unique-ph']] as [$code, $cost, $slug]) {
            $attributes = $this->plan($slug, $code, ['provider' => 'hetzner', 'cost_eur_cents' => $cost])->getAttributes();
            CloudPlan::create($attributes + ['provider_ref' => $code.'-'.$slug]);
        }
        $monthly = CloudPlan::offers();
        $hourly = CloudPlan::offers(hourly: true);
        $locations = CloudLocation::where('country', 'PH')->where('is_active', true)->get()->keyBy('code');
        $this->assertSame('de-berlin', $monthly['shared-slug']->location_code);
        $expected = $this->assertEquivalent('PH', $monthly, $hourly, $locations);
        $this->assertSame(['unique-ph'], array_column($expected, 'slug'));
        $this->assertSame($expected, app(PublicVpsCatalog::class)->rows('PH'));
        $this->assertSame('de-berlin', $monthly['shared-slug']->location_code);
        Http::assertNothingSent();
    }

    public function test_iran_gate_and_unscoped_catalogue_preserve_the_existing_rows_and_links(): void
    {
        $offers = collect([$this->plan('iran', 'ir-tehran'), $this->plan('philippines', 'ph-manila')]);
        $locations = collect(['ir-tehran' => $this->location('ir-tehran', 'IR'), 'ph-manila' => $this->location('ph-manila', 'PH')]);
        $iran = $locations->only('ir-tehran');
        Setting::put('iran_sales_open_to_unverified', '0');

        // The deployed visibility gate hides restricted destinations entirely.
        // The country optimization must preserve that behavior, not expose a disabled card.
        $this->assertSame([], $this->assertEquivalent('IR', $offers, collect(), $iran));
        $this->assertSame(['philippines'], array_column(
            app(PublicVpsCatalog::class)->rows(null, $offers, collect(), $locations), 'slug'));

        // Use the real profile gate so a cached guest decision cannot hide Iran from a verified customer.
        $customer = Customer::create([
            'email' => 'country-scope@example.test', 'phone' => '09121234567',
            'password' => 'fixture-password', 'status' => 'active', 'locale' => 'fa',
        ]);
        CustomerProfile::create([
            'customer_id' => $customer->id, 'is_default' => true, 'type' => 'individual',
            'status' => 'verified', 'email' => $customer->email, 'mobile' => $customer->phone,
        ]);
        $this->actingAs($customer, 'customer');
        $rows = $this->assertEquivalent('IR', $offers, collect(), $iran);
        $this->assertSame(['iran'], array_column($rows, 'slug'));
        $this->assertStringContainsString('plan=iran&cycle=monthly', $rows[0]['monthly_url']);
        $this->assertNull($rows[0]['hourly_url']);
        $this->assertEqualsCanonicalizing(['iran', 'philippines'], array_column(
            app(PublicVpsCatalog::class)->rows(null, $offers, collect(), $locations), 'slug'));
    }

    public function test_explicit_iran_gate_opening_keeps_guest_country_rows_and_links(): void
    {
        // Settings changes take effect on the next request; start this request with the explicit opening.
        Setting::put('iran_sales_open_to_unverified', '1');
        $offers = collect([$this->plan('iran', 'ir-tehran'), $this->plan('philippines', 'ph-manila')]);
        $locations = collect(['ir-tehran' => $this->location('ir-tehran', 'IR'), 'ph-manila' => $this->location('ph-manila', 'PH')]);
        $rows = $this->assertEquivalent('IR', $offers, collect(), $locations->only('ir-tehran'));
        $this->assertSame(['iran'], array_column($rows, 'slug'));
        $this->assertStringContainsString('plan=iran&cycle=monthly', $rows[0]['monthly_url']);
        $this->assertNull($rows[0]['hourly_url']);
        $this->assertEqualsCanonicalizing(['iran', 'philippines'], array_column(
            app(PublicVpsCatalog::class)->rows(null, $offers, collect(), $locations), 'slug'));
    }

    public function test_injected_collections_and_hourly_rates_keep_their_own_scope_and_representatives(): void
    {
        $monthly = $this->plan('monthly-picked', 'ph-manila');
        $monthly->id = 1;
        $hourly = $this->plan('hourly-picked', 'ph-manila', ['price_irt' => 900000]);
        $hourly->id = 2;
        $foreign = $this->plan('caller-foreign', 'de-berlin');
        $locations = collect(['ph-manila' => $this->location('caller-presentation-code', 'PH'), 'de-berlin' => $this->location('de-berlin', 'DE')]);
        // Injected locations define the existing display scope, even if broader than country.
        $rows = $this->assertEquivalent('PH', collect([$monthly, $foreign]), collect(['monthly-picked' => $hourly]), $locations, [2 => 123400]);
        $this->assertEqualsCanonicalizing(['monthly-picked', 'caller-foreign'], array_column($rows, 'slug'));
        $row = collect($rows)->firstWhere('slug', 'monthly-picked');
        $this->assertSame(123400, $row['hourly']);
        $this->assertSame(200000, $row['monthly']);
        $this->assertStringContainsString('plan=monthly-picked&billing_mode=hourly', $row['hourly_url']);
    }

    public function test_empty_country_keeps_an_empty_result_with_global_offers_present(): void
    {
        CloudLocation::create(['code' => 'de-berlin', 'country' => 'DE', 'city' => 'Berlin', 'is_active' => true]);
        CloudPlan::create($this->plan('global-only', 'de-berlin', ['provider' => 'hetzner'])->getAttributes());
        $this->assertSame([], $this->assertEquivalent('PH', CloudPlan::offers(), CloudPlan::offers(hourly: true), collect()));
        $this->assertSame([], app(PublicVpsCatalog::class)->rows('PH'));
        Http::assertNothingSent();
    }
}

class PublicCatalogCountingPlan extends CloudPlan
{
    public array $reads = [];

    public function getAttribute($key)
    {
        $this->reads[$key] = ($this->reads[$key] ?? 0) + 1;

        return parent::getAttribute($key);
    }
}
