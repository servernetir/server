<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Cloud\CloudCountry;
use App\Services\Cloud\CloudPublicQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

class UnifiedVpsCountryTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalogPricing;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seedCatalogPricing();
    }

    private function offer(string $code, string $country, string $name, int $price = 810000): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => $code], [
            'country' => $country, 'city' => ucwords(str_replace('-', ' ', substr($code, 3))), 'is_active' => true,
        ]);

        return CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => $name, 'location_code' => $code,
            'public_name' => $name, 'slug' => 'cv-2c-4g-'.$code,
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'cpu_kind' => 'shared', 'arch' => 'x86', 'traffic_gb' => 2048,
            'cost_eur_cents' => 400, 'price_eur_cents' => 800, 'price_irt' => $price,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    public function test_monthly_only_quote_does_not_require_an_annual_price(): void
    {
        $plan = new CloudPlan(['provider' => 'vpstc', 'price_irt' => 1234000]);
        $quote = app(CloudPublicQuote::class)->quote($plan);
        $this->assertSame(CloudStoreController::priceForCycle($plan, 'monthly'), $quote['irt']);
        $this->assertGreaterThan(0, $quote['irt']);
        $this->assertArrayNotHasKey('yearly_irt', $quote);
        $this->assertArrayNotHasKey('contact', $quote);
        $this->assertSame(0, CloudStoreController::priceForCycle($plan, 'yearly'));
        $this->assertFalse($plan->supportsHourly());
    }

    public function test_missing_monthly_price_is_not_a_free_offer(): void
    {
        $quote = app(CloudPublicQuote::class)->quote(new CloudPlan(['provider' => 'vpstc', 'price_irt' => 0]));
        $this->assertTrue($quote['contact']);
        $this->assertArrayNotHasKey('irt', $quote);
    }

    public function test_one_country_contains_all_cities_even_when_their_resources_match(): void
    {
        $a = $this->offer('us-new-york', 'US', 'EAST', 990000);
        $b = $this->offer('us-los-angeles', 'US', 'WEST', 1190000);
        $res = $this->get('/vps/usa')->assertOk()->assertViewIs('pages.vps-country');
        $rows = collect($res->viewData('vpsRows'));
        $this->assertEqualsCanonicalizing(['us-new-york', 'us-los-angeles'], $rows->pluck('location')->all());
        foreach ([$a, $b] as $plan) {
            $row = $rows->firstWhere('slug', $plan->slug);
            $this->assertSame(CloudStoreController::priceForCycle($plan, 'monthly'), $row['monthly']);
            $this->assertStringContainsString('location='.$plan->location_code, $row['monthly_url']);
        }
        $res->assertSee('EAST')->assertSee('WEST')->assertDontSee('hetzner');
    }

    public function test_city_aliases_redirect_directly_to_the_country_in_each_language(): void
    {
        $this->offer('us-new-york', 'US', 'EAST');
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->get($prefix.'/cloud/us-new-york')->assertStatus(301)->assertRedirect($prefix.'/vps/usa');
            $this->get($prefix.'/cloud/tr-istanbul-ii')->assertStatus(301)->assertRedirect($prefix.'/vps/turkiye');
        }
    }

    public function test_countries_without_legacy_marketing_config_have_a_real_country_page(): void
    {
        $this->offer('jp-tokyo', 'JP', 'TOKYO');
        $this->assertSame('japan', CloudCountry::marketingSlug('JP'));
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->get($prefix.'/vps/japan')->assertOk()->assertSee('TOKYO');
            $this->get($prefix.'/cloud/jp-tokyo')->assertStatus(301)->assertRedirect($prefix.'/vps/japan');
        }
        $this->get('/vps/not-a-country')->assertNotFound();
    }

    public function test_sitemap_contains_one_country_url_and_no_redirecting_city_urls(): void
    {
        $this->offer('us-new-york', 'US', 'EAST');
        $this->offer('us-los-angeles', 'US', 'WEST');
        $this->offer('jp-tokyo', 'JP', 'TOKYO');
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->assertSame(1, substr_count($xml, '<loc>'.url($prefix.'/vps/usa').'</loc>'));
            $this->assertSame(1, substr_count($xml, '<loc>'.url($prefix.'/vps/japan').'</loc>'));
        }
        $this->assertStringNotContainsString('/cloud/us-new-york</loc>', $xml);
        $this->assertStringNotContainsString('/cloud/jp-tokyo</loc>', $xml);
    }

    public function test_infrastructure_aliases_use_the_same_public_city_label(): void
    {
        $this->offer('tr-istanbul', 'TR', 'MAIN');
        $this->offer('tr-istanbul-ii', 'TR', 'ALIAS', 990000);
        $rows = collect($this->get('/vps/turkiye')->assertOk()->viewData('vpsRows'));
        $this->assertSame(['tr-istanbul'], $rows->pluck('location')->unique()->values()->all());
        $this->assertCount(1, $rows->pluck('city')->unique());
        $this->assertSame(CloudLocation::flagSvgFor('TR'), $rows->first()['flag_svg']);
    }

    public function test_unavailable_prices_do_not_enter_the_public_table(): void
    {
        $plan = $this->offer('us-new-york', 'US', 'UNPRICED');
        $plan->update(['price_irt' => 0]);
        $this->get('/vps/usa')->assertOk()->assertViewHas('vpsRows', []);
    }

    public function test_hourly_page_keeps_every_city_and_uses_a_real_hourly_checkout_link(): void
    {
        $a = $this->offer('us-new-york', 'US', 'EAST');
        $this->offer('jp-tokyo', 'JP', 'TOKYO');
        $res = $this->get('/vps/hourly')->assertOk();
        $rows = collect($res->viewData('vpsRows'));
        $this->assertCount(2, $rows);
        $east = $rows->firstWhere('slug', $a->slug);
        $this->assertSame($a->hourlyIrt(), $east['hourly']);
        $this->assertStringContainsString('billing_mode=hourly', $east['hourly_url']);
        $res->assertSee('EAST')->assertSee('TOKYO')->assertSee('data-vps-billing', false);
    }
}
