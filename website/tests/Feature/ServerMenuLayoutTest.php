<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\ServerMenu;
use App\Services\SiteMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ServerMenuLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_catalog_country_is_rendered_with_a_flag_in_both_menus(): void
    {
        \Tests\Support\NavasanRates::cloudSnapshot();
        $customer = \App\Models\Customer::create(['email' => 'server-menu@example.test',
            'phone' => '09123334444', 'password' => 'isolated-password', 'status' => 'active']);
        \Tests\Support\ShahkarFixture::verify($customer);
        $this->actingAs($customer, 'customer');
        $countries = array_diff(array_keys(CloudLocation::COUNTRIES), ['XX']);
        foreach ($countries as $iso) {
            $code = strtolower($iso).'-menu-test';
            CloudLocation::create(['code' => $code, 'country' => $iso, 'city' => $iso, 'is_active' => true]);
            CloudPlan::create(['provider' => 'hetzner', 'provider_ref' => $code,
                'location_code' => $code, 'public_name' => 'Test', 'slug' => $code,
                'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'cost_eur_cents' => 379,
                'price_eur_cents' => 570, 'price_irt' => 570000, 'is_active' => true, 'in_stock' => true]);
        }
        Cache::flush();
        foreach (['/' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $url => $locale) {
            $html = $this->get($url)->assertOk()->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($dom);
            foreach ($countries as $iso) {
                $links = $xpath->query('//*[@data-server-panel="cloud"]//a[@data-country="'.$iso.'"]');
                $this->assertSame(2, $links->length, $locale.': '.$iso.' missing/duplicated');
                foreach ($links as $link) {
                    $this->assertSame(1, $link->getElementsByTagName('img')->length, $iso.' must have an SVG flag');
                }
            }
            $this->assertSame(2, $xpath->query('//*[@data-server-panel="dedicated"]//a[@data-country="DE"]')->length);
            $this->assertSame(0, $xpath->query('//*[@data-server-panel="dedicated"]//a[@data-country="SG"]')->length);
            $this->assertStringContainsString('brand=ibm', $html);
            $this->assertStringContainsString('sm-gpu', $html);
            if ($path = getenv('SERVER_MENU_PREVIEW_DIR')) {
                file_put_contents($path.'/'.$locale.'.html', $html);
            }
        }
    }

    public function test_hidden_groups_stay_hidden_and_relabelled_groups_keep_their_role(): void
    {
        $section = app(SiteMenu::class)->mega()['vps'];
        $section['groups'] = array_values(array_filter($section['groups'], fn ($g) => ! in_array($g['server_role'], ['hardware', 'parts'], true)));
        foreach ($section['groups'] as &$group) {
            $group['en'] = 'Administrator title';
        }
        $menu = app(ServerMenu::class)->build($section);
        $this->assertFalse($menu['hardware']);
        $this->assertSame([], $menu['parts']);
        $this->assertArrayHasKey('DE', $menu['cloud']);
        $this->assertArrayHasKey('DE', $menu['dedicated']);
    }

    public function test_brand_deep_links_select_the_brand_before_javascript_runs(): void
    {
        foreach (['hp', 'dell', 'ibm'] as $brand) {
            $response = $this->get('/servers?brand='.$brand)->assertOk();
            $response->assertViewHas('activeBrand', $brand);
            $html = $response->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('//*[@id="srv-grid"]/a[not(@hidden)]') as $card) {
                $this->assertSame($brand, $card->getAttribute('data-brand'));
            }
        }
        $this->get('/servers?brand[]=invalid')->assertOk()->assertViewHas('activeBrand', 'all');
    }
}
