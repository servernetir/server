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

    public function test_persian_guests_and_unverified_customers_see_prices_but_not_order_links(): void
    {
        $plan = $this->offer();
        $original = $plan->fresh()->getRawOriginal();
        foreach ([null, $this->customer(false)] as $customer) {
            if ($customer) {
                $this->actingAs($customer, 'customer');
            }
            $page = $this->get('/vps/iran')->assertOk();
            $page->assertSee($plan->public_name)->assertSee('data-vps-row', false)
                ->assertSee(__('ui.iran_purchase_verify'))->assertSee('account/verify')
                ->assertDontSee('account/cloud-store?location=ir', false);
            $this->assertNull($page->viewData('vpsRows')[0]['monthly_url']);
            $this->assertTrue(IranSalesGate::blocksIranFor($customer));
            foreach (['/en', '/tr'] as $prefix) {
                $this->get($prefix.'/vps/iran')->assertNotFound();
                $this->get($prefix.'/cloud/ir-tehran')->assertNotFound();
            }
        }
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>'.url('/vps/iran').'</loc>', $xml);
        foreach (['/en', '/tr'] as $prefix) {
            $this->assertStringNotContainsString('<loc>'.url($prefix.'/vps/iran').'</loc>', $xml);
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
            ->shouldReceive('monthlyPrice')->andReturn(0)
            ->getMock()->shouldReceive('quote')->andReturn(['irt' => 0]);
        $this->assertIranUnavailable();
        Http::assertNothingSent();
    }

    public function test_shahkar_verified_customer_can_order_in_persian_only_is_listed(): void
    {
        $plan = $this->offer();
        $this->actingAs($this->customer(true), 'customer');
        $this->visibleCatalogue($this->get('/vps/iran')->assertOk(), $plan);
        foreach (['/en', '/tr'] as $prefix) {
            $this->get($prefix.'/vps/iran')->assertNotFound();
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

    public function test_foreign_catalogue_crosslinks_exclude_iran_and_persian_landing_has_no_foreign_alternates(): void
    {
        $this->offer();
        CloudLocation::create(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);

        foreach (['/en', '/tr'] as $prefix) {
            $this->get($prefix.'/cloud')->assertOk()
                ->assertSee('href="'.url($prefix.'/cloud/de-frankfurt').'"', false)
                ->assertDontSee('href="'.url($prefix.'/cloud/ir-tehran').'"', false);
            $this->get($prefix.'/dedicated/germany')->assertOk()
                ->assertDontSee('href="'.url($prefix.'/dedicated/iran').'"', false)
                ->assertDontSee('href="'.url($prefix.'/dedicated/iran-infrastructure').'"', false)
                ->assertDontSee('href="'.url($prefix.'/cloud/ir-tehran').'"', false);
        }

        $this->get('/cloud')->assertOk()->assertSee('href="'.url('/cloud/ir-tehran').'"', false);
        $this->get('/dedicated/germany')->assertOk()
            ->assertSee('href="'.url('/dedicated/iran').'"', false)
            ->assertSee('href="'.url('/dedicated/iran-infrastructure').'"', false);

        foreach (['/vps/iran', '/dedicated/iran', '/dedicated/iran-infrastructure'] as $path) {
            $page = $this->get($path)->assertOk()
                ->assertSee('rel="alternate" hreflang="fa"', false);
            foreach (['en', 'tr'] as $locale) {
                $page->assertDontSee('hreflang="'.$locale.'"', false)
                    ->assertDontSee('href="'.url('/'.$locale.$path).'"', false);
            }
        }

        Http::assertNothingSent();
    }

    public function test_foreign_hourly_pages_describe_eligible_locations_without_promoting_iran(): void
    {
        $this->offer();
        $this->get('/en/vps/hourly')->assertOk()
            ->assertSee('Where are hourly VPS plans available?')
            ->assertDontSee('Do you offer hourly VPS in Iran?')
            ->assertDontSee('our Iranian data centres');
        $this->get('/tr/vps/hourly')->assertOk()
            ->assertSee('Saatlik VPS planları hangi konumlarda sunuluyor?')
            ->assertDontSee('İran’da saatlik VPS var mı?')
            ->assertDontSee('İran veri merkezlerimizde');
        $this->get('/vps/hourly')->assertOk()->assertSee('سرور مجازی ساعتی ایران هم دارید؟');
        Http::assertNothingSent();
    }

    public function test_a_warm_guest_page_cache_does_not_grant_purchase_or_hide_the_verified_catalogue(): void
    {
        $plan = $this->offer();
        $verified = $this->customer(true);
        config(['pagecache.enabled' => true]);
        Cache::flush();
        $this->get('/vps/iran')->assertOk()->assertHeader('X-Cache', 'MISS')->assertSee('احراز هویت و خرید');
        $this->get('/vps/iran')->assertOk()->assertHeader('X-Cache', 'HIT')->assertSee('احراز هویت و خرید');
        $this->actingAs($verified, 'customer');
        $this->visibleCatalogue($this->get('/vps/iran')->assertOk()->assertHeader('X-Cache', 'BYPASS'), $plan);
        $this->get('/en/vps/iran')->assertNotFound();
        $this->get('/tr/vps/iran')->assertNotFound();
        Http::assertNothingSent();
    }

    private function assertIranUnavailable(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->get('/vps/iran')->assertOk()
            ->assertDontSee('data-vps-row', false)
            ->assertSee('name="robots" content="noindex,follow"', false);
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->assertStringNotContainsString('<loc>'.url($prefix.'/vps/iran').'</loc>', $xml);
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
        if ($verified) {
            $customer->identityVerification()->create([
                'national_id_enc' => 'synthetic', 'national_id_hash' => hash('sha256', $customer->email),
                'birth_date' => '1990-01-01', 'mobile' => $customer->phone,
                'status' => 'verified', 'shahkar_matched' => true,
            ]);
        }
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
