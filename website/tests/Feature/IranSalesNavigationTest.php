<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\IdentityVerification;
use App\Models\MenuOverride;
use App\Services\Customer\IranSalesVisibility;
use App\Services\MenuManager;
use App\Services\ServerMenu;
use App\Services\SiteMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class IranSalesNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pagecache.enabled' => false]);
        Http::preventStrayRequests();
        NavasanRates::cloudSnapshot();
        Cache::flush();
        foreach (['ir-tehran' => 'IR', 'de-frankfurt' => 'DE'] as $code => $country) {
            CloudLocation::create(['code' => $code, 'country' => $country,
                'city' => $country === 'IR' ? 'Tehran' : 'Frankfurt', 'is_active' => true]);
            CloudPlan::create(['provider' => 'hetzner', 'provider_ref' => 'navigation-'.$code,
                'location_code' => $code, 'public_name' => 'CV-2-4', 'slug' => 'navigation-'.$code,
                'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
                'cost_eur_cents' => 300, 'price_eur_cents' => $country === 'IR' ? 570 : 920,
                'price_irt' => $country === 'IR' ? 570000 : 920000,
                'is_active' => true, 'in_stock' => true]);
        }
    }

    private function customer(bool $shahkar): Customer
    {
        $customer = Customer::create(['email' => 'navigation-'.random_int(1, 999999).'@example.test',
            'phone' => '0912'.random_int(1000000, 9999999), 'password' => 'isolated-password', 'status' => 'active']);
        // Manual approval alone must never change Iran purchase visibility.
        CustomerProfile::create(['customer_id' => $customer->id, 'is_default' => true,
            'type' => 'individual', 'status' => 'verified', 'email' => $customer->email]);
        if ($shahkar) {
            IdentityVerification::create(['customer_id' => $customer->id,
                'national_id_enc' => Crypt::encryptString('synthetic-identity'),
                'national_id_hash' => hash('sha256', 'navigation-'.$customer->id),
                'birth_date' => '1990-01-01', 'mobile' => $customer->phone,
                'status' => 'verified', 'shahkar_matched' => true, 'shahkar_at' => now(), 'verified_at' => now()]);
        }

        return $customer;
    }

    private function menu(): array
    {
        return app(ServerMenu::class)->build(app(MenuManager::class)->mega(app(SiteMenu::class)->mega())['vps']);
    }

    private function footerIranLinks(): array
    {
        return collect(app(MenuManager::class)->footer('en'))->flatMap(fn ($column) => $column['items'])
            ->filter(fn ($item) => str_contains($item['href'], '/vps/iran') || str_contains($item['href'], '/dedicated/iran'))->all();
    }

    public function test_shared_menu_caches_do_not_leak_customer_eligibility_in_either_direction(): void
    {
        $this->assertArrayNotHasKey('IR', $this->menu()['cloud']);
        $this->assertSame([], $this->footerIranLinks());
        $customer = $this->customer(true);
        Auth::guard('customer')->setUser($customer);
        $this->assertArrayHasKey('IR', $this->menu()['cloud']);
        $this->assertArrayHasKey('IR', $this->menu()['dedicated']);
        $this->assertNotEmpty($this->footerIranLinks());
        Auth::guard('customer')->forgetUser();
        $this->assertArrayNotHasKey('IR', $this->menu()['cloud']);
        $this->assertArrayNotHasKey('IR', $this->menu()['dedicated']);
        $this->assertSame([], $this->footerIranLinks());
        $this->assertArrayHasKey('DE', $this->menu()['cloud']);
    }

    public function test_guest_and_manually_verified_customer_do_not_get_iran_navigation_in_any_language(): void
    {
        foreach ([null, $this->customer(false)] as $customer) {
            if ($customer) {
                $this->actingAs($customer, 'customer');
            }
            foreach (['/', '/en', '/tr'] as $path) {
                $html = $this->get($path)->assertOk()->getContent();
                $this->assertStringNotContainsString('data-country="IR"', $html);
                $this->assertStringNotContainsString('data-sn-region="IR"', $html);
                $this->assertDoesNotMatchRegularExpression('~href="[^"\s]*/(?:vps|dedicated)/iran(?:[?"/])~', $html);
                $this->assertStringContainsString('data-country="DE"', $html);
            }
        }
    }

    public function test_cloud_and_hourly_purchase_catalogues_exclude_iran_without_shahkar(): void
    {
        foreach ([null, $this->customer(false)] as $customer) {
            if ($customer) {
                $this->actingAs($customer, 'customer');
            }
            $this->get('/cloud')->assertOk()->assertViewHas('rows', fn ($rows) =>
                count($rows) === 1 && $rows[0]['country'] === 'DE');
            $this->get('/vps/hourly')->assertOk()->assertViewHas('countries', fn ($countries) =>
                count($countries) === 1 && $countries[0]['iso'] === 'DE');
        }
        $this->actingAs($this->customer(true), 'customer');
        $this->get('/cloud')->assertOk()->assertViewHas('rows', fn ($rows) => count($rows) === 2);
        $this->get('/vps/hourly')->assertOk()->assertViewHas('countries', fn ($countries) => count($countries) === 2);
    }

    public function test_direct_iran_information_pages_keep_their_canonical_and_have_no_purchase_links(): void
    {
        foreach (['/vps/iran', '/dedicated/iran', '/cloud/ir-tehran'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('rel="canonical" href="'.url($path).'"', $html);
            $this->assertDoesNotMatchRegularExpression('~href="[^"]*(?:location|loc)=ir(?:-|%2D)~i', $html);
            $this->assertStringContainsString(__('iran-sales.required'), $html);
        }
    }

    public function test_administrator_added_iran_links_are_filtered_after_cached_overrides(): void
    {
        MenuOverride::create(['menu' => 'mega', 'path' => 'mega:custom:iran-nav', 'visible' => true,
            'label_en' => 'Custom Iran link', 'custom' => ['scope' => 'vps', 'url' => '/en/cloud/ir-tehran']]);
        MenuManager::forget();
        $menu = app(MenuManager::class)->mega(app(SiteMenu::class)->mega());
        $this->assertStringNotContainsString('/en/cloud/ir-tehran', json_encode($menu));
        Auth::guard('customer')->setUser($this->customer(true));
        $menu = app(MenuManager::class)->mega(app(SiteMenu::class)->mega());
        $this->assertStringContainsString('/en/cloud/ir-tehran', json_encode($menu, JSON_UNESCAPED_SLASHES));
    }

    public function test_country_destinations_are_filtered_without_matching_unrelated_iran_content(): void
    {
        foreach (['/en/vps/iran', '/tr/dedicated/iran', '/cloud/ir-tehran',
            '/account/cloud-store?location=ir-tehran', '/account/checkout/example?country=IR'] as $url) {
            $this->assertFalse(IranSalesVisibility::allowsUrl($url), $url);
        }
        foreach (['/domain/ir', '/blog/iran-network-guide', '/cloud/de-frankfurt', '/account/services'] as $url) {
            $this->assertTrue(IranSalesVisibility::allowsUrl($url), $url);
        }
    }

    public function test_home_starting_price_and_featured_plans_use_eligible_foreign_offers(): void
    {
        $this->get('/')->assertOk()
            ->assertViewHas('plans', fn ($plans) => count($plans) === 1 && $plans[0]['irt'] === 920000)
            ->assertViewHas('products', fn ($products) => $products[2]['irt'] === 920000);
        $this->actingAs($this->customer(true), 'customer');
        $this->get('/')->assertOk()->assertViewHas('products', fn ($products) => $products[2]['irt'] === 570000);
        Auth::guard('customer')->forgetUser();
        $this->get('/')->assertOk()->assertViewHas('products', fn ($products) => $products[2]['irt'] === 920000);
    }

    public function test_hosting_starting_price_uses_existing_eligible_country_quote_without_personalizing_shared_cache(): void
    {
        $servers = [];
        foreach (['IR', 'DE'] as $country) {
            $servers[$country] = \App\Models\Server::create(['name' => 'Synthetic '.$country, 'country' => $country,
                'type' => 'whm', 'hostname' => strtolower($country).'.example.test', 'username' => 'synthetic',
                'api_token' => 'synthetic-token', 'status' => 'active']);
        }
        $product = \App\Models\Product::create(['name' => 'Mixed hosting', 'slug' => 'navigation-1',
            'category' => 'shared', 'group' => 'navigation', 'locations' => ['IR', 'DE'],
            'server_id' => $servers['IR']->id, 'price' => 200000, 'base_price_eur' => 2,
            'cycle' => 'monthly', 'is_active' => true]);
        $catalog = app(\App\Services\CatalogPricing::class);
        $raw = $catalog->map()[$product->slug];
        $foreign = $product->monthlyEquivalent('monthly', 'DE');
        $this->assertSame($foreign, $catalog->minimumQuote('navigation')['irt']);
        $this->assertSame($raw, $catalog->map()[$product->slug]);
        Auth::guard('customer')->setUser($this->customer(true));
        $this->assertSame($product->monthlyEquivalent('monthly'), $catalog->minimumQuote('navigation')['irt']);
        Auth::guard('customer')->forgetUser();
        $this->assertSame($foreign, $catalog->minimumQuote('navigation')['irt']);
        $this->assertSame($raw, $catalog->map()[$product->slug]);
    }

    private function orderProduct(bool $mixed = false): \App\Models\Product
    {
        $server = \App\Models\Server::create(['name' => 'Synthetic Iran', 'country' => 'IR',
            'type' => 'whm', 'hostname' => 'ir.example.test', 'username' => 'synthetic',
            'api_token' => 'synthetic-token', 'status' => 'active']);
        if ($mixed) {
            \App\Models\Server::create(['name' => 'Synthetic Germany', 'country' => 'DE',
                'type' => 'whm', 'hostname' => 'de.example.test', 'username' => 'synthetic',
                'api_token' => 'synthetic-token', 'status' => 'active']);
        }

        return \App\Models\Product::create(['name' => 'Synthetic hosting', 'slug' => 'navigation-order',
            'category' => 'shared', 'group' => 'navigation', 'locations' => $mixed ? ['IR', 'DE'] : ['IR'],
            'server_id' => $server->id, 'price' => 200000, 'base_price_eur' => 2,
            'cycle' => 'monthly', 'is_active' => true]);
    }

    public function test_iran_only_order_summary_retains_information_but_has_no_purchase_controls_or_handoff(): void
    {
        $product = $this->orderProduct();
        foreach ([null, $this->customer(false)] as $customer) {
            if ($customer) {
                $this->actingAs($customer, 'customer');
            }
            foreach (['', '/en', '/tr'] as $prefix) {
                $response = $this->get($prefix.'/order/'.$product->slug)->assertOk()
                    ->assertViewHas('iranPurchaseRestricted', true)
                    ->assertViewHas('defaultRow', fn ($row) => $row['href'] === null && $row['total'] > 0);
                $html = $response->getContent();
                $this->assertStringContainsString('rel="canonical" href="'.url($prefix.'/order/'.$product->slug).'"', $html);
                $this->assertStringNotContainsString('id="os-cta"', $html);
                $this->assertStringNotContainsString('/go/pay?', $html);
                $this->assertStringContainsString('id="os-iran-note"', $html);
                $handoff = $this->get($prefix.'/go/pay?sku='.$product->slug.'&cycle=yearly')->assertRedirect($prefix.'/order/'.$product->slug);
                $this->assertStringNotContainsString('sig=', $handoff->headers->get('Location'));
                $handoff->assertHeader('Cache-Control', 'no-store, private');
            }
        }
    }

    public function test_successful_shahkar_allows_iran_order_summary_and_signed_handoff(): void
    {
        $product = $this->orderProduct();
        $this->actingAs($this->customer(true), 'customer');
        $this->get('/order/'.$product->slug)->assertOk()->assertViewHas('iranPurchaseRestricted', false)
            ->assertSee('id="os-cta"', false)->assertDontSee('id="os-iran-note"', false);
        $response = $this->get('/go/pay?sku='.$product->slug.'&cycle=yearly')->assertStatus(302);
        $this->assertStringContainsString('/account/order/'.$product->slug, $response->headers->get('Location'));
        $this->assertStringContainsString('sig=', $response->headers->get('Location'));
    }

    public function test_mixed_order_summary_remains_available_using_the_existing_eligible_foreign_price(): void
    {
        $product = $this->orderProduct(true);
        \App\Models\Server::create(['name' => 'Synthetic US', 'country' => 'US',
            'type' => 'whm', 'hostname' => 'us.example.test', 'username' => 'synthetic',
            'api_token' => 'synthetic-token', 'status' => 'active']);
        $product->update(['locations' => ['IR', 'DE', 'US']]);
        config(['billing.locations.DE.multiplier' => 2.0, 'billing.locations.US.multiplier' => 1.0]);
        $this->actingAs($this->customer(false), 'customer');
        $this->get('/order/'.$product->slug)->assertOk()->assertViewHas('iranPurchaseRestricted', false)
            ->assertViewHas('defaultRow', fn ($row) => $row['total'] === $product->priceForCycle('yearly', 'DE'))
            ->assertSee('id="os-cta"', false);
        $checkout = $this->get('/account/order/'.$product->slug)->assertOk()->assertViewHas('countries', ['DE', 'US']);
        $dom = new \DOMDocument;
        @$dom->loadHTML($checkout->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame('DE', $xpath->query('//input[@name="country" and @checked]')->item(0)->getAttribute('value'));
        $this->assertLessThan($product->priceForCycle('yearly', 'DE'), $product->priceForCycle('yearly', 'US'));
        $response = $this->get('/go/pay?sku='.$product->slug.'&cycle=yearly')->assertStatus(302);
        $this->assertStringContainsString('sig=', $response->headers->get('Location'));
    }
}
