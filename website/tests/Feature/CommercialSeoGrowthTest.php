<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Cloud\CountryLandingSeo;
use App\Services\Cloud\PublicVpsCatalog;
use App\Services\PublicPageStyles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

class CommercialSeoGrowthTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalogPricing;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seedCatalogPricing();
    }

    public function test_hourly_service_preserves_visible_prices_currencies_and_order_links(): void
    {
        $plan = $this->seedPlan('de-frankfurt', 'DE', 'Frankfurt');
        $original = $plan->fresh()->getRawOriginal();

        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $locale) {
            $response = $this->get($prefix.'/vps/hourly')->assertOk();
            $schemas = $this->schemas($response->getContent());
            $service = $this->service($schemas);
            $this->assertSame(['@id' => rtrim(config('app.url'), '/').'/#organization'], $service['provider']);
            $this->assertNotContains('Product', array_column($schemas, '@type'));
            $featured = $response->viewData('featured');
            $this->assertNotEmpty($featured);
            $this->assertCount(count($featured), $service['offers']);
            foreach ($service['offers'] as $index => $offer) {
                $this->assertEquals($featured[$index]['ld_price'], $offer['price']);
                $this->assertSame($locale === 'fa' ? 'IRR' : 'EUR', $offer['priceCurrency']);
                $this->assertSame('HUR', $offer['priceSpecification']['unitCode']);
                $this->assertSame($offer['price'], $offer['priceSpecification']['price']);
                $this->assertSame($featured[$index]['buy_url'], $offer['url']);
                $this->assertStringContainsString('billing_mode=hourly', $offer['url']);
                foreach (['shippingDetails', 'hasMerchantReturnPolicy', 'priceValidUntil', 'validFrom'] as $field) {
                    $this->assertArrayNotHasKey($field, $offer);
                }
            }
            $this->assertStringContainsString('id="hourly-explorer-css"', $response->getContent());
            $this->assertDoesNotMatchRegularExpression('~<link[^>]+href="[^"]*/hourly-explorer\.css~', $response->getContent());
        }
        $this->assertSame($original, $plan->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_empty_hourly_and_gpu_catalogs_do_not_promise_capacity_or_prices(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach (['/vps/hourly', '/gpu'] as $path) {
                $schemas = $this->schemas($this->get($prefix.$path)->assertOk()->getContent());
                $service = $this->service($schemas);
                $this->assertArrayNotHasKey('offers', $service);
                $this->assertArrayNotHasKey('availability', $service);
                $this->assertNotContains('Product', array_column($schemas, '@type'));
            }
        }
        Http::assertNothingSent();
    }

    public function test_hosting_guides_and_metadata_keep_localized_links_without_inventing_offers(): void
    {
        config(['hosting.products.windows.offer_schema' => false, 'hosting.products.linux.offer_schema' => false]);
        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $locale) {
            foreach (['windows' => 'linux', 'linux' => 'windows'] as $slug => $other) {
                $html = $this->get($prefix.'/hosting/'.$slug)->assertOk()->getContent();
                $copy = trans('hosting-seo.'.$slug, [], $locale);
                $this->assertStringContainsString('<title>'.e($copy['title']), $html);
                $this->assertStringContainsString('content="'.e($copy['description']).'"', $html);
                $this->assertStringContainsString('id="hosting-stack-title"', $html);
                $this->assertStringContainsString('href="'.url($prefix.'/hosting/'.$other).'"', $html);
                $this->assertStringContainsString('href="'.url($prefix.'/vps/hourly').'"', $html);
                $this->assertStringContainsString('href="'.url($prefix.'/contact').'"', $html);
                $service = $this->service($this->schemas($html));
                $this->assertArrayNotHasKey('offers', $service);
                $this->assertSame(['@id' => rtrim(config('app.url'), '/').'/#organization'], $service['provider']);
                $this->assertStringNotContainsString('hosting-seo.', $html);
            }
        }
        Http::assertNothingSent();
    }

    public function test_country_hourly_guidance_requires_an_actual_hourly_checkout_link(): void
    {
        $this->seedPlan('ph-manila', 'PH', 'Manila');
        $rows = [];
        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $locale) {
            $response = $this->get($prefix.'/vps/philippines')->assertOk();
            $rows[$locale] = $response->viewData('vpsRows');
            $this->assertNotEmpty(array_filter(array_column($rows[$locale], 'hourly_url')));
            $seo = app(CountryLandingSeo::class)->content('PH');
            $this->assertStringContainsString(e($seo['hourly_title']), $response->getContent());
            $this->assertStringContainsString('href="'.url($prefix.'/vps/hourly').'#hourly-calculator"', $response->getContent());
        }
        $this->mock(PublicVpsCatalog::class)->shouldReceive('rows')->with('PH')->andReturnUsing(
            fn () => array_map(fn ($row) => array_replace($row, ['hourly' => 0, 'hourly_label' => null, 'hourly_url' => null]), $rows[app()->getLocale()])
        );
        foreach (['', '/en', '/tr'] as $prefix) {
            $html = $this->get($prefix.'/vps/philippines')->assertOk()->getContent();
            $seo = app(CountryLandingSeo::class)->content('PH');
            $this->assertStringNotContainsString(e($seo['hourly_title']), $html);
        }
        Http::assertNothingSent();
    }

    public function test_widget_css_is_independent_of_page_fonts_and_rejects_unsafe_input(): void
    {
        foreach (['/vps/hourly', '/en/vps/hourly', '/tr/vps/hourly'] as $path) {
            app()->instance('request', Request::create($path));
            $this->assertSame('.hv-filter{display:flex}', $this->styles('.hv-filter{display:flex}')->auxiliary('assets/css/hourly-explorer.css'));
            foreach ([null, '', '@import "other.css";', 'a{background:url(../unreviewed.png)}', '</STYLE><script>bad</script>'] as $css) {
                $this->assertNull($this->styles($css)->auxiliary('assets/css/hourly-explorer.css'));
            }
        }
        foreach (['/gpu', '/account/cloud-store', '/vps/hourly-extra'] as $path) {
            app()->instance('request', Request::create($path));
            $this->assertNull($this->styles('a{color:red}')->auxiliary('assets/css/hourly-explorer.css'));
        }
    }

    public function test_widget_uses_the_external_stylesheet_if_inlining_is_unavailable(): void
    {
        $this->app->instance(PublicPageStyles::class, $this->styles(null));
        $html = $this->get('/vps/hourly')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="hourly-explorer-css"', $html);
        $this->assertMatchesRegularExpression('~<link[^>]+href="[^"]*/hourly-explorer\.css~', $html);
    }

    public function test_every_hosting_catalog_page_has_a_localized_identity_and_valid_jsonld(): void
    {
        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $locale) {
            foreach (array_keys(config('hosting.products')) as $slug) {
                $path = $prefix.'/hosting/'.$slug;
                $html = $this->get($path)->assertOk()->getContent();
                $document = new \DOMDocument;
                $previous = libxml_use_internal_errors(true);
                $document->loadHTML($html);
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
                $xpath = new \DOMXPath($document);
                $this->assertSame(1, $xpath->query('//h1')->length, $path);
                $this->assertSame(1, $xpath->query('//title')->length, $path);
                $this->assertSame($locale, $document->documentElement->getAttribute('lang'), $path);
                $this->assertSame(url($path), $xpath->evaluate('string(//link[@rel="canonical"]/@href)'), $path);
                $this->assertNotEmpty($xpath->evaluate('string(//meta[@name="description"]/@content)'), $path);
                $this->assertNotEmpty($this->schemas($html), $path);
                $this->assertSame('Service', $this->service($this->schemas($html))['@type'], $path);
            }
        }
        Http::assertNothingSent();
    }

    public function test_all_mapped_country_templates_are_usable_without_invented_stock(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach (\App\Services\Cloud\CloudCountry::marketingSlugs() as $slug) {
                $path = $prefix.'/vps/'.$slug;
                $response = $this->get($path)->assertOk();
                $html = $response->getContent();
                $this->assertSame([], $response->viewData('vpsRows'), $path);
                $this->assertMatchesRegularExpression('~<meta name="robots" content="noindex,follow">~', $html, $path);
                $this->assertStringNotContainsString('"@type":"Offer"', $html, $path);
                $this->assertStringNotContainsString(':country', $html, $path);
                $this->assertNotEmpty($this->schemas($html), $path);
            }
        }
        Http::assertNothingSent();
    }

    private function styles(?string $css): PublicPageStyles
    {
        return new class($css) extends PublicPageStyles
        {
            public function __construct(private ?string $css) {}
            protected function readStylesheet(string $asset): ?string
            {
                return $asset === 'assets/css/hourly-explorer.css' ? $this->css : null;
            }
        };
    }

    private function seedPlan(string $code, string $country, string $city): CloudPlan
    {
        CloudLocation::create(['code' => $code, 'country' => $country, 'city' => $city, 'is_active' => true]);

        return CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'seo-'.$code, 'location_code' => $code,
            'public_name' => 'SEO-CV-2', 'slug' => 'seo-cv-2-'.$code, 'vcpu' => 2, 'ram_mb' => 4096,
            'disk_gb' => 40, 'disk_type' => 'nvme', 'cost_eur_cents' => 379, 'price_eur_cents' => 570,
            'price_irt' => 1440000, 'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function schemas(string $html): array
    {
        preg_match_all('~<script[^>]*type="application/ld\\+json"[^>]*>(.*?)</script>~s', $html, $matches);

        return array_map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $matches[1]);
    }

    private function service(array $schemas): array
    {
        $services = array_values(array_filter($schemas, fn ($item) => ($item['@type'] ?? null) === 'Service'));
        $this->assertCount(1, $services);

        return $services[0];
    }
}
