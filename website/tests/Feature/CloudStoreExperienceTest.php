<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Services\Cloud\SaladClient;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        $this->assertSame(3, $xpath->query('//div[@class="cvb-step-b"]')->length);
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

    public function test_unknown_cities_have_location_names_and_separate_real_prices_in_all_languages(): void
    {
        foreach ([['ru-amd', 'AMD', 2570000, true], ['ru-shared', 'Shared', 2810000, true], ['ru-ref', null, 2900000, false]] as [$code, $city, $price, $stock]) {
            CloudLocation::create(['code' => $code, 'country' => 'RU', 'city' => $city, 'is_active' => true]);
            $plan = CloudPlan::where('location_code', 'de-ref')->firstOrFail()->replicate();
            $plan->forceFill(['provider_ref' => 'fixture-'.$code, 'location_code' => $code,
                'slug' => 'plan-'.$code, 'price_irt' => $price, 'in_stock' => $stock])->save();
        }

        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $locale) {
            $response = $this->get($prefix.'/account/cloud-store?location=ru-amd')->assertOk();
            $xpath = $this->xpath($response->getContent());
            $anchors = $response->viewData('anchors');
            $group = collect($response->viewData('groups'))->firstWhere('country', 'RU');
            $this->assertNotNull($group);
            $this->assertSame(min($anchors['ru-amd']['irt'], $anchors['ru-shared']['irt']), $group['fromIrt']);
            $countryPrice = $xpath->query('//details[@data-country="RU"]/summary//span[@class="cvb-nat-p"]/b')->item(0);
            $this->assertStringContainsString(cloud_price($group['fromIrt']), $countryPrice->textContent);

            $names = [];
            foreach (['ru-amd', 'ru-shared', 'ru-ref'] as $code) {
                $link = $xpath->query('//a[@data-city="'.$code.'"]')->item(0);
                $name = trim($xpath->query('.//span[@class="cvb-city-t"]/b', $link)->item(0)->textContent);
                $names[] = $name;
                $this->assertStringContainsString($group['label'], $name, $locale);
                $this->assertTrue(collect([1, 2, 3])->contains(fn ($n) => str_contains($name, __('ui.cvb_dc_n', ['n' => fa_num($n)]))), $name);
                $this->assertStringNotContainsString(__('ui.cvb_upto_cores', ['n' => fa_num(2)]), $name);
                $this->assertSame('', $link->getAttribute('data-city-label'));
                $this->assertSame('true', $link->getAttribute('data-approximate'));
                if ($code === 'ru-ref') {
                    $this->assertSame(0, $xpath->query('.//small', $link)->length);
                    $this->assertStringContainsString(__('ui.cvb_c_soldout'), $link->textContent);
                } else {
                    $amount = cloud_price($anchors[$code]['irt']);
                    $this->assertStringNotContainsString($amount, $name);
                    $priceNode = $xpath->query('.//small', $link)->item(0);
                    $this->assertStringContainsString($amount, $priceNode->textContent);
                }
            }
            $this->assertCount(3, array_unique($names));
            $selectedLabel = $xpath->query('//*[@id="cvx-globe-name"]')->item(0)->textContent;
            $this->assertStringNotContainsString(cloud_price($anchors['ru-amd']['irt']), $selectedLabel);
            $this->assertStringNotContainsString('Moscow', $selectedLabel);
            $this->assertStringNotContainsString('مسکو', $selectedLabel);
        }
        Http::assertNothingSent();
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

    public function test_advanced_field_errors_open_the_final_software_step_and_the_advanced_controls(): void
    {
        foreach (['ssh_key_new' => str_repeat('a', 6001), 'ssh_key_id' => 'invalid-id', 'extra_ipv4' => -1] as $field => $invalid) {
            // Follow real validation and JSON session serialization. Injecting a
            // ViewErrorBag object directly is not the application's session format.
            $redirect = $this->from('/account/cloud-store?location=de-frankfurt')->post('/account/cloud-store', [
                'location' => 'de-frankfurt', 'plan' => 'plan-de-frankfurt',
                'image' => 'ubuntu-24.04', 'cycle' => 'monthly', $field => $invalid,
            ])->assertRedirect('/account/cloud-store?location=de-frankfurt')->assertSessionHasErrors($field);
            $cookieName = (string) config('session.cookie');
            $this->withCookie($cookieName, $redirect->getCookie($cookieName)->getValue());
            $html = $this->get('/account/cloud-store?location=de-frankfurt')->assertOk()->getContent();
            $this->assertMatchesRegularExpression('~"openStep"\s*:\s*4\b~', $html, $field.' error must be reachable');
            $xpath = $this->xpath($html);
            $this->assertSame(1, $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " cvb-adv ") and @open]')->length);
        }
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
        Http::assertNothingSent();
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

    public function test_server_name_is_one_order_field_above_checkout_without_a_separate_stage(): void
    {
        $html = $this->get('/account/cloud-store?location=de-frankfurt')->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//form[@id="cvb-form"]//input[@name="label"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="cvx-name-home"]//*[@id="cvx-name-fields"]//input[@id="cvb-label" and @name="label"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="cvx-name-home"]/following-sibling::button[@id="cvb-submit"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="cvx-mobile-name"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-step="5" or @data-go="5" or @data-spine="5"]')->length);
        $this->assertSame(0, $xpath->query('//button[contains(concat(" ", normalize-space(@class), " "), " cvx-next ")]')->length);
        // Without JavaScript all stages remain visible and native checkout still works.
        $this->assertSame(2, $xpath->query('//button[(@id="cvb-submit" or @id="cvb-submit-2") and @type="submit"]')->length);
        Http::assertNothingSent();
    }

    public function test_invalid_server_name_returns_to_final_checkout_with_its_original_value(): void
    {
        $invalid = str_repeat('a', 65);
        $redirect = $this->from('/account/cloud-store?location=de-frankfurt')->post('/account/cloud-store', [
            'location' => 'de-frankfurt', 'plan' => 'plan-de-frankfurt',
            'image' => 'ubuntu-24.04', 'cycle' => 'monthly', 'label' => $invalid,
        ])->assertRedirect('/account/cloud-store?location=de-frankfurt')->assertSessionHasErrors('label');
        $cookieName = (string) config('session.cookie');
        $this->withCookie($cookieName, $redirect->getCookie($cookieName)->getValue());
        $html = $this->get('/account/cloud-store?location=de-frankfurt')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~"openStep"\s*:\s*4\b~', $html);
        $xpath = $this->xpath($html);
        $field = $xpath->query('//*[@id="cvx-name-fields"]//input[@name="label"]');
        $this->assertSame(1, $field->length);
        $this->assertSame($invalid, $field->item(0)->getAttribute('value'));
        $this->assertSame(1, $xpath->query('//*[@id="cvx-name-fields"]//div[contains(concat(" ", normalize-space(@class), " "), " danger ")]')->length);
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
        Http::assertNothingSent();
    }

    public function test_gpu_store_has_two_decisions_and_keeps_its_request_action(): void
    {
        CloudLocation::create(['code' => 'global-gpu', 'country' => 'XX', 'city' => '', 'is_active' => true]);
        CloudPlan::create([
            'provider' => 'salad', 'provider_ref' => 'fixture-gpu', 'provider_location' => 'global',
            'location_code' => 'global-gpu', 'slug' => 'fixture-gpu', 'public_name' => 'RTX 4090',
            'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 150, 'arch' => 'x86',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'gpu_model' => 'RTX 4090',
            'gpu_vram_mb' => 24576, 'gpu_count' => 1, 'is_interruptible' => true,
            'cost_eur_cents' => 8640, 'price_eur_cents' => 15750, 'price_irt' => 18900000,
            'cost_hour_eur_micro' => 120000, 'is_active' => true, 'in_stock' => true,
        ]);
        CloudImage::create([
            'provider' => 'salad', 'provider_ref' => SaladClient::APPS['gpu-jupyter']['ref'],
            'key' => 'gpu-jupyter', 'kind' => 'app', 'family' => 'gpu-jupyter',
            'label' => 'Jupyter', 'arch' => 'x86', 'is_active' => true,
        ]);
        $html = $this->get('/account/cloud-store?location=global-gpu')->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $this->assertSame(2, $xpath->query('//section[@data-step]')->length);
        foreach ([3, 4] as $step) {
            $this->assertSame(1, $xpath->query('//*[@id="cvb-step-'.$step.'"]')->length);
        }
        $this->assertSame(0, $xpath->query('//*[@id="cvb-step-1" or @id="cvb-step-5"]')->length);
        foreach (['cvb-submit', 'cvb-submit-2'] as $id) {
            $button = $xpath->query('//*[@id="'.$id.'"]')->item(0);
            $this->assertStringContainsString(__('ui.gpu_request_submit'), $button->textContent);
            $this->assertStringNotContainsString(__('ui.cvb_pay'), $button->textContent);
        }
        $this->assertSame(1, $xpath->query('//form[@id="cvb-form"]//input[@name="label"]')->length);
        Http::assertNothingSent();
    }
}
