<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Setting;
use App\Services\Cloud\CountryLandingSeo;
use App\Services\Customer\IranSalesGate;
use App\Services\Customer\IranSalesVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

class IranCountryMarketingTest extends TestCase
{
    use RefreshDatabase, SeedsCatalogPricing;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 503)]);
        $this->seedCatalogPricing();
    }

    public function test_guests_and_unverified_customers_get_localized_indexable_guidance_without_restricted_catalogue_data(): void
    {
        $plan = $this->offer();
        $original = $plan->fresh()->getRawOriginal();
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach ([null, $this->customer(false)] as $customer) {
            if ($customer) {
                $this->actingAs($customer, 'customer');
            }
            foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
                $response = $this->get($prefix.'/vps/iran')->assertOk();
                $this->restrictedLanding($response, $prefix, true);
                $response->assertSee('<title>'.e(__('country-seo.restricted.title')).'</title>', false);
                $response->assertSee('content="'.e(__('country-seo.restricted.description')).'"', false);
                $response->assertDontSee(__('vps-catalog.unavailable'));
                $this->assertStringNotContainsString('country-seo.', $response->getContent());
                $this->assertSame(1, substr_count($xml, '<loc>'.url($prefix.'/vps/iran').'</loc>'));
                $this->assertFalse(IranSalesVisibility::allowsCountry('IR'));
                $this->assertFalse(IranSalesVisibility::allowsUrl($prefix.'/vps/iran'));
                $this->assertTrue(IranSalesGate::blocksIranFor($customer));
            }
        }

        $this->assertSame($original, $plan->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_only_genuine_eligible_iran_stock_can_make_the_restricted_landing_indexable(): void
    {
        $plan = $this->offer();
        foreach ([
            ['in_stock' => false],
            ['price_irt' => 0],
            ['admin_disabled' => true],
            ['slug' => 'cbm-ir-private-fixture'],
        ] as $changes) {
            $original = $plan->getAttributes();
            $plan->update($changes);
            $this->assertIranUnavailable();
            $plan->setRawAttributes($original);
            $plan->save();
        }

        CloudLocation::where('code', 'ir-tehran')->update(['is_active' => false]);
        $this->assertIranUnavailable();
        CloudLocation::where('code', 'ir-tehran')->update(['is_active' => true]);

        $plan->delete();
        $this->offer('ir-gpu-pool');
        $this->assertIranUnavailable();
        CloudPlan::query()->delete();
        $this->assertIranUnavailable();
        Http::assertNothingSent();
    }

    public function test_a_sellable_iran_plan_with_an_unusable_quote_stays_noindex_and_out_of_the_sitemap(): void
    {
        $this->offer();
        $this->mock(\App\Services\Cloud\CloudPublicQuote::class)
            ->shouldReceive('monthlyPrice')->andReturn(0);
        $this->assertIranUnavailable();
        Http::assertNothingSent();
    }

    public function test_verified_customers_and_an_explicitly_open_gate_keep_actual_plans_and_offers(): void
    {
        $plan = $this->offer();
        Setting::put(IranSalesGate::SETTING, '1');
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->visibleCatalogue($this->get($prefix.'/vps/iran')->assertOk(), $plan);
        }

        Setting::put(IranSalesGate::SETTING, '0');
        $this->actingAs($this->customer(true), 'customer');
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->visibleCatalogue($this->get($prefix.'/vps/iran')->assertOk(), $plan);
        }
        Http::assertNothingSent();
    }

    public function test_global_sitemap_does_not_change_with_geography_or_verified_access(): void
    {
        $this->offer();
        $foreign = $this->withHeaders(['CF-IPCountry' => 'US'])->get('/sitemap.xml')->assertOk()->getContent();
        $domestic = $this->withHeaders(['CF-IPCountry' => 'IR'])->get('/sitemap.xml')->assertOk()->getContent();
        $this->actingAs($this->customer(true), 'customer');
        $verified = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertSame($foreign, $domestic);
        $this->assertSame($foreign, $verified);
        Http::assertNothingSent();
    }

    public function test_a_warm_guest_page_cache_cannot_hide_the_verified_catalogue_or_publish_it_to_guests(): void
    {
        $plan = $this->offer();
        $verified = $this->customer(true);
        config(['pagecache.enabled' => true]);
        Cache::flush();
        $this->restrictedLanding($this->get('/vps/iran')->assertOk()->assertHeader('X-Cache', 'MISS'), '', true);
        $this->restrictedLanding($this->get('/vps/iran')->assertOk()->assertHeader('X-Cache', 'HIT'), '', true);

        $this->actingAs($verified, 'customer');
        $this->visibleCatalogue($this->get('/vps/iran')->assertOk()->assertHeader('X-Cache', 'BYPASS'), $plan);
        auth('customer')->logout();
        $this->app['session']->flush();
        $this->restrictedLanding($this->get('/vps/iran')->assertOk(), '', true);
        Http::assertNothingSent();
    }

    private function assertIranUnavailable(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->restrictedLanding($this->get($prefix.'/vps/iran')->assertOk(), $prefix, false);
            $this->assertStringNotContainsString('<loc>'.url($prefix.'/vps/iran').'</loc>', $xml);
        }
    }

    private function restrictedLanding(TestResponse $response, string $prefix, bool $indexable): void
    {
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('~(?:id="public-page-vps-catalog-css"|href="[^"]*/assets/css/vps-catalog\.css[^"]*")~', explode('</head>', $html)[0]);
        if (($response->baseResponse->original ?? null) instanceof \Illuminate\Contracts\View\View) {
            $this->assertSame([], $response->viewData('vpsRows'));
            $this->assertSame($indexable, $response->viewData('indexable'));
        }
        $this->assertSame(! $indexable, str_contains($html, 'name="robots" content="noindex,follow"'));
        $this->assertFalse(str_contains(strtolower($response->headers->get('X-Robots-Tag', '')), 'noindex'));
        $response->assertSee('data-vps-access-notice', false)
            ->assertSee(__('ui.iran_gate_blocked'))
            ->assertSee('href="'.url($prefix.'/login').'"', false)
            ->assertSee('href="'.url($prefix.'/contact').'"', false)
            ->assertDontSee('data-vps-row', false)
            ->assertDontSee('IR-PRIVATE-FIXTURE')
            ->assertDontSee('Iran fixture city')
            ->assertDontSee('account/cloud-store', false);
        $this->assertSame(1, preg_match('~<div class="vpsc-cities">(.*?)</div>~s', $html, $cities));
        $this->assertSame('', trim($cities[1]));

        $faq = null;
        foreach ($this->schemas($html) as $schema) {
            array_walk_recursive($schema, function ($value, $key): void {
                if ($key === '@type') {
                    $this->assertNotContains($value, ['Offer', 'ItemList']);
                }
            });
            if (($schema['@type'] ?? '') === 'FAQPage') {
                $faq = $schema;
            }
        }
        $seo = app(CountryLandingSeo::class)->content('IR', catalogRestricted: true);
        $this->assertSame($seo['faq_ld']['mainEntity'], $faq['mainEntity']);
        foreach ($seo['faq'] as $row) {
            $response->assertSee($row['q'])->assertSee($row['a']);
        }
    }

    private function visibleCatalogue(TestResponse $response, CloudPlan $plan): void
    {
        $this->assertCount(1, $response->viewData('vpsRows'));
        $this->assertTrue($response->viewData('indexable'));
        $response->assertSee('data-vps-row', false)->assertSee($plan->public_name)
            ->assertSee('Iran fixture city')->assertSee('account/cloud-store', false)
            ->assertDontSee('data-vps-access-notice', false)
            ->assertDontSee('name="robots" content="noindex,follow"', false);
        $lists = array_values(array_filter($this->schemas($response->getContent()), fn ($schema) => ($schema['@type'] ?? '') === 'ItemList'));
        $this->assertCount(1, $lists);
        $this->assertSame('Service', $lists[0]['itemListElement'][0]['item']['@type']);
        $this->assertSame('Offer', $lists[0]['itemListElement'][0]['item']['offers']['@type']);
    }

    private function schemas(string $html): array
    {
        preg_match_all('~<script\b[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $matches);
        return array_map(fn ($raw) => json_decode($raw, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
    }

    private function customer(bool $verified): Customer
    {
        $customer = Customer::create([
            'email' => ($verified ? 'verified' : 'unverified').'@example.test', 'phone' => $verified ? '09121234567' : '09121234568',
            'password' => 'fixture-password', 'status' => 'active', 'locale' => 'fa',
        ]);
        CustomerProfile::create([
            'customer_id' => $customer->id, 'is_default' => true, 'type' => 'individual',
            'status' => $verified ? 'verified' : 'draft', 'email' => $customer->email, 'mobile' => $customer->phone,
        ]);
        return $customer;
    }

    private function offer(string $code = 'ir-tehran'): CloudPlan
    {
        CloudLocation::create(['code' => $code, 'country' => 'IR', 'city' => 'Iran fixture city', 'is_active' => true]);
        return CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'ir-private-fixture', 'location_code' => $code,
            'public_name' => 'IR-PRIVATE-FIXTURE', 'slug' => 'ir-private-fixture-'.$code,
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'cpu_kind' => 'shared', 'arch' => 'x86', 'traffic_gb' => 2048,
            'cost_eur_cents' => 400, 'price_eur_cents' => 800, 'price_irt' => 810000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ]);
    }
}
