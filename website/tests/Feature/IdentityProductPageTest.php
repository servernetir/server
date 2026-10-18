<?php

namespace Tests\Feature;

use App\Services\IdentityApi\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentityProductPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_localized_product_uses_public_catalog_and_preserves_net_prices(): void
    {
        Http::preventStrayRequests();
        // Presentation-only fixture: no customer, token, wallet or provider request.
        $services = app(Catalog::class)->publicList();
        foreach ($services as &$service) {
            if (in_array($service['slug'], ['shahkar-inquiry', 'id-inquiry'], true)) {
                $service['active'] = true;
                $service['price'] = $service['slug'] === 'shahkar-inquiry' ? 1502 : 7854;
            }
        }
        unset($service);
        $this->mock(Catalog::class, fn ($mock) => $mock->shouldReceive('publicList')->andReturnUsing(function () use ($services) {
            return array_map(fn ($s) => array_merge($s, ['name' => __('identity.service_'.$s['slug'])]), $services);
        }));
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $response = $this->get('http://127.0.0.1:8187'.$prefix.'/identity-api');
            $response->assertOk()->assertSee('1,502')->assertSee('7,854')
                ->assertSee('data-identity-product', false)->assertSee('data-search', false)
                ->assertDontSee('identity_product.')->assertDontSee('zohal', false)
                ->assertDontSee('source_amount')->assertDontSee('billing_policy')
                ->assertDontSee('1,653')->assertDontSee('8,640');
            $html = $response->getContent();
            $this->assertSame(35, substr_count($html, 'data-service data-category='));
            $this->assertSame(2, preg_match_all('/<article[^>]+data-active="true"/', $html));
            $this->assertStringContainsString('aria-controls="ip-panel-python"', $html);
            $this->assertStringContainsString('max_charge_irt', $html);
            $this->assertStringContainsString('prefers-reduced-motion', $html);
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//*[@id="menu-services"]//a[contains(@href,"'.$prefix.'/identity-api")]')->length);
            $this->assertGreaterThanOrEqual(2, $xpath->query('//a[contains(@href,"'.$prefix.'/identity-api") and .//b]')->length);
            if ($out = getenv('IDENTITY_UI_PREVIEW_DIR')) {
                if (!is_dir($out.$prefix)) mkdir($out.$prefix, 0755, true);
                file_put_contents($out.$prefix.'/identity-api.html', $html);
            }
        }
        Http::assertNothingSent();
    }

    public function test_unavailable_catalog_does_not_publish_a_fake_price_or_purchase_action(): void
    {
        Http::preventStrayRequests();
        foreach (['', '/en', '/tr'] as $prefix) {
            $response = $this->get($prefix.'/identity-api');
            $response->assertOk()->assertDontSee('identity_product.')->assertDontSee('zohal', false);
            $this->assertSame(0, preg_match_all('/<article[^>]+data-active="true"/', $response->getContent()));
            $this->assertSame(35, substr_count($response->getContent(), 'data-service data-category='));
        }
        Http::assertNothingSent();
    }
}
