<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderSummaryIndexingTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_handoff_is_noindex_in_each_language_without_creating_an_order(): void
    {
        Http::preventStrayRequests();
        $product = Product::create([
            'name' => 'هاست لینوکس', 'name_en' => 'Linux hosting', 'name_tr' => 'Linux hosting',
            'slug' => 'growth-linux', 'category' => 'shared', 'group' => 'linux',
            'price' => 250000, 'setup_fee' => 0, 'cycle' => 'monthly',
            'tax_percent' => 10, 'is_active' => true,
        ]);
        $original = $product->fresh()->getRawOriginal();

        foreach (['', '/en', '/tr'] as $prefix) {
            $response = $this->get($prefix.'/order/'.$product->slug)->assertOk();
            $html = $response->getContent();

            $this->assertStringContainsString('<meta name="robots" content="noindex,follow">', $html);
            $this->assertStringNotContainsString('<link rel="canonical"', $html);
            $this->assertStringNotContainsString('hreflang=', $html);
            $this->assertStringContainsString('id="os-cta"', $html);
            $this->assertNotEmpty($response->viewData('rows'));
            $this->assertSame($original, $product->fresh()->getRawOriginal());
        }

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Service::count());
        Http::assertNothingSent();
    }

    public function test_marketing_hosting_page_remains_indexable(): void
    {
        $html = $this->get('/hosting/linux')->assertOk()->getContent();

        $this->assertStringNotContainsString('<meta name="robots" content="noindex', $html);
        $this->assertStringContainsString('<link rel="canonical"', $html);
    }
}
