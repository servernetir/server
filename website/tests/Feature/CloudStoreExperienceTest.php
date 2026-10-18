<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class CloudStoreExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        NavasanRates::cloudSnapshot();
        Http::preventStrayRequests();
        $this->actingAs(Customer::create([
            'email' => 'experience@example.test', 'phone' => '09123334444',
            'password' => 'isolated-password', 'status' => 'active', 'locale' => 'fa',
        ]), 'customer');
        CloudImage::create([
            'provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => 'x86', 'min_disk_gb' => 5, 'is_active' => true,
        ]);
        foreach ([
            ['de-frankfurt', 'DE', 'Frankfurt', 570000, true],
            ['de-ref', 'DE', null, 710000, true],
            ['ch-zurich', 'CH', 'Zurich', 840000, true],
            ['ch-z-rich', 'CH', 'Zürich', 960000, true],
            ['fi-helsinki', 'FI', 'Helsinki', 570000, false],
        ] as [$code, $country, $city, $price, $stock]) {
            CloudLocation::create(['code' => $code, 'country' => $country, 'city' => $city, 'is_active' => true]);
            CloudPlan::create([
                'provider' => 'hetzner', 'provider_ref' => 'fixture-'.$code, 'provider_location' => 'fixture',
                'location_code' => $code, 'public_name' => 'CV-2-4', 'slug' => 'plan-'.$code,
                'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
                'traffic_gb' => 1024, 'cpu_kind' => 'shared', 'arch' => 'x86',
                'cost_eur_cents' => 300, 'price_eur_cents' => 570, 'price_irt' => $price,
                'is_active' => true, 'in_stock' => $stock,
            ]);
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($document);
    }

    public function test_all_location_codes_are_present_in_one_native_combined_picker(): void
    {
        $response = $this->get('/account/cloud-store?location=de-frankfurt')->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(0, $xpath->query('//*[@id="cvb-step-2"]')->length);
        $this->assertSame(4, $xpath->query('//div[@class="cvb-step-b"]')->length);
        foreach (CloudLocation::pluck('country', 'code') as $code => $country) {
            $links = $xpath->query('//*[@id="cvb-step-1"]//details[@data-country="'.$country.'"]//a[@data-city="'.$code.'"]');
            $this->assertSame(1, $links->length, $code.' must remain individually reachable');
            parse_str((string) parse_url($links->item(0)->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame($code, $query['location']);
        }
        $this->assertSame(3, $xpath->query('//*[@id="cvb-step-1"]//details/summary[@data-nat]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="cvb-step-1"]//summary[@href]')->length);
        Http::assertNothingSent();
    }

    public function test_each_datacentre_displays_its_own_server_price_anchor(): void
    {
        $response = $this->get('/account/cloud-store?location=de-frankfurt')->assertOk();
        $anchors = $response->viewData('anchors');
        $xpath = $this->xpath($response->getContent());
        foreach (['de-frankfurt' => 570000, 'de-ref' => 710000, 'ch-zurich' => 840000, 'ch-z-rich' => 960000] as $code => $price) {
            $link = $xpath->query('//a[@data-city="'.$code.'"]')->item(0);
            $this->assertInstanceOf(DOMElement::class, $link);
            $this->assertSame($price, $anchors[$code]['irt']);
            $this->assertStringContainsString(cloud_price($anchors[$code]['irt']), $link->textContent);
        }
        $shut = $xpath->query('//a[@data-city="fi-helsinki"]')->item(0);
        $this->assertStringContainsString('is-shut', $shut->getAttribute('class'));
        $this->assertStringContainsString(__('ui.cvb_c_soldout'), $shut->textContent);
        $this->assertStringNotContainsString(cloud_price(570000), $shut->textContent);
    }

    public function test_globe_city_identity_is_language_independent_and_generic_locations_are_explicit(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            $html = $this->get($prefix.'/account/cloud-store?location=de-frankfurt')->assertOk()->getContent();
            $xpath = $this->xpath($html);
            $real = $xpath->query('//a[@data-city="de-frankfurt"]')->item(0);
            $this->assertSame('Frankfurt', $real->getAttribute('data-city-label'), $prefix);
            $this->assertSame('false', $real->getAttribute('data-approximate'));
            $generic = $xpath->query('//a[@data-city="de-ref"]')->item(0);
            $this->assertSame('true', $generic->getAttribute('data-approximate'));
            $this->assertSame('', $generic->getAttribute('data-city-label'));
            $this->assertStringNotContainsString('ui.cvb', $html);
            $this->assertStringNotContainsString('cloud-store.location', $html);
        }
    }

    public function test_combined_picker_keeps_one_server_owned_location_field_and_no_client_price_fields(): void
    {
        $html = $this->get('/account/cloud-store?location=ch-z-rich&plan=plan-ch-z-rich')->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $location = $xpath->query('//form[@id="cvb-form"]//input[@name="location"]');
        $this->assertSame(1, $location->length);
        $this->assertSame('ch-z-rich', $location->item(0)->getAttribute('value'));
        $this->assertSame(1, $xpath->query('//form[@id="cvb-form"]//input[@name="_token"]')->length);
        $this->assertSame(0, $xpath->query('//form[@id="cvb-form"]//input[@name="price" or @name="amount" or @name="provider" or @name="provider_ref"]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="cvb-form"]//input[@name="plan" and @value="plan-ch-z-rich" and @checked]')->length);
    }

    public function test_advanced_field_errors_open_the_review_step_and_the_advanced_controls(): void
    {
        foreach (['ssh_key_new', 'ssh_key_id', 'extra_ipv4'] as $field) {
            $errors = (new ViewErrorBag)->put('default', new MessageBag([$field => 'Invalid fixture value']));
            $html = $this->withSession(['errors' => $errors])->get('/account/cloud-store?location=de-frankfurt')->assertOk()->getContent();
            $this->assertMatchesRegularExpression('~"openStep"\s*:\s*5\b~', $html, $field.' error must be reachable');
            $xpath = $this->xpath($html);
            $this->assertSame(1, $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " cvb-adv ") and @open]')->length);
        }
    }

    public function test_every_location_link_preserves_explicit_hourly_billing_without_javascript(): void
    {
        $html = $this->get('/account/cloud-store?location=de-frankfurt&billing_mode=hourly&cycle=quarterly&image=ubuntu-24.04')->assertOk()->getContent();
        $xpath = $this->xpath($html);
        foreach ($xpath->query('//a[@data-city]') as $link) {
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame($link->getAttribute('data-city'), $query['location']);
            $this->assertSame('hourly', $query['billing_mode'] ?? null);
            $this->assertSame('quarterly', $query['cycle']);
            $this->assertSame('ubuntu-24.04', $query['image']);
        }
    }
}
