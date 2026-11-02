<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Cloud\PublicVpsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

class CountrySitemapEligibilityTest extends TestCase
{
    use RefreshDatabase, SeedsCatalogPricing;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seedCatalogPricing();
    }

    public function test_an_empty_configured_country_is_noindex_and_not_reintroduced_by_the_generic_catalogue(): void
    {
        $this->offer('at-vienna', 'AT');
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->get($prefix.'/vps/sweden')->assertOk()
                ->assertSee('name="robots" content="noindex,follow"', false);
        }

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->assertSame(1, substr_count($xml, '<loc>'.url($prefix.'/vps/austria').'</loc>'));
            $this->assertSame(0, substr_count($xml, '<loc>'.url($prefix.'/vps/sweden').'</loc>'));
        }
        Http::assertNothingSent();
    }

    public function test_global_country_eligibility_preserves_iran_legacy_offers_and_non_country_products(): void
    {
        $this->offer('ir-tehran', 'IR');
        $this->offer('de-shared', 'DE');
        $this->offer('at-vienna', 'AT');
        $this->offer('sg-singapore', 'SG');
        CloudLocation::where('code', 'sg-singapore')->update(['is_active' => false]);

        // Sitemap eligibility must not depend on visitor-specific rendered rows.
        $this->mock(PublicVpsCatalog::class)->shouldNotReceive('rows');
        $foreign = $this->withHeaders(['CF-IPCountry' => 'US'])->get('/sitemap.xml')->assertOk()->getContent();
        $domestic = $this->withHeaders(['CF-IPCountry' => 'IR'])->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertSame($foreign, $domestic);
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach (['iran', 'germany', 'austria'] as $slug) {
                $this->assertSame(1, substr_count($foreign, '<loc>'.url($prefix.'/vps/'.$slug).'</loc>'));
            }
            $this->assertSame(0, substr_count($foreign, '<loc>'.url($prefix.'/vps/singapore').'</loc>'));
            $this->assertSame(1, substr_count($foreign, '<loc>'.url($prefix.'/vps/international').'</loc>'));
        }
        $this->assertSame(0, substr_count($foreign, '/cloud/de-shared</loc>'));
        Http::assertNothingSent();
    }

    public function test_a_country_is_removed_when_its_only_global_offer_becomes_unsellable(): void
    {
        $plan = $this->offer('se-stockholm', 'SE');
        $available = $this->get('/sitemap.xml')->assertOk()->getContent();
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->assertSame(1, substr_count($available, '<loc>'.url($prefix.'/vps/sweden').'</loc>'));
        }

        $plan->update(['price_irt' => 0]);
        $unavailable = $this->get('/sitemap.xml')->assertOk()->getContent();
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->get($prefix.'/vps/sweden')->assertOk()
                ->assertSee('name="robots" content="noindex,follow"', false);
            $this->assertSame(0, substr_count($unavailable, '<loc>'.url($prefix.'/vps/sweden').'</loc>'));
        }
        Http::assertNothingSent();
    }

    public function test_metal_and_gpu_only_locations_are_not_vps_country_shelves(): void
    {
        $metal = $this->offer('fr-paris', 'FR');
        $metal->update(['slug' => 'cbm-fr-paris']);
        $gpu = $this->offer('us-gpu-pool', 'US');
        $this->assertSame(2, CloudPlan::query()->sellable()->whereKey([$metal->id, $gpu->id])->count());

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach (['france', 'usa'] as $slug) {
                $this->assertSame(0, substr_count($xml, '<loc>'.url($prefix.'/vps/'.$slug).'</loc>'));
            }
        }
        Http::assertNothingSent();
    }

    public function test_a_raw_sellable_plan_without_a_positive_checkout_quote_is_not_advertised(): void
    {
        $plan = $this->offer('fr-paris', 'FR');
        $this->assertTrue(CloudPlan::query()->sellable()->whereKey($plan->id)->exists());
        $this->mock(\App\Services\Cloud\CloudPublicQuote::class)
            ->shouldReceive('monthlyPrice')
            ->withArgs(fn (CloudPlan $candidate) => $candidate->id === $plan->id)
            ->andReturn(0);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->assertSame(0, substr_count($xml, '<loc>'.url($prefix.'/vps/france').'</loc>'));
        }
        Http::assertNothingSent();
    }

    private function offer(string $code, string $country): CloudPlan
    {
        CloudLocation::create(['code' => $code, 'country' => $country, 'city' => 'Fixture city', 'is_active' => true]);

        return CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'sitemap-'.$code, 'location_code' => $code,
            'public_name' => 'Fixture plan', 'slug' => 'sitemap-'.$code,
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'cpu_kind' => 'shared', 'arch' => 'x86', 'traffic_gb' => 2048,
            'cost_eur_cents' => 400, 'price_eur_cents' => 800, 'price_irt' => 810000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }
}
