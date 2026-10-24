<?php

namespace Tests\Feature;

use App\Models\ServerPart;
use Database\Seeders\PhysicalServerSeeder;
use Database\Seeders\ServerPartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhysicalShopSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhysicalServerSeeder::class);
        $this->seed(ServerPartSeeder::class);
    }

    public function test_hpe_server_links_to_relevant_generation_parts_without_claiming_exact_compatibility(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            $response = $this->get($prefix.'/servers/hpe-proliant-dl380-gen10')->assertOk();
            $html = $response->getContent();

            $this->assertStringContainsString($prefix.'/parts/cpu?gen=gen10', $html);
            $this->assertStringContainsString($prefix.'/parts/ram?gen=gen10', $html);
            $this->assertStringContainsString($prefix.'/parts/disk?gen=gen10', $html);
            $this->assertStringContainsString($prefix.'/parts/raid?gen=gen10', $html);
            $this->assertStringContainsString('DL380 Gen10', $html);
        }

        $this->get('/servers/dell-poweredge-r740')
            ->assertOk()
            ->assertDontSee('/parts/cpu?gen=gen10', false);
    }

    public function test_part_has_category_specific_purchase_guidance_and_comparison_path(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            $html = $this->get($prefix.'/parts/cpu/xeon-gold-6248r')->assertOk()->getContent();

            $this->assertStringContainsString($prefix.'/parts/compare?parts=xeon-gold-6248r%2C', $html);
            $this->assertStringContainsString($prefix.'/parts/cpu', $html);
            $this->assertStringContainsString($prefix.'/servers', $html);
            $this->assertStringNotContainsString('shop_seo.', $html);
        }
    }

    public function test_a_quote_only_part_keeps_breadcrumbs_without_invalid_product_schema(): void
    {
        ServerPart::where('slug', 'xeon-gold-6248r')->update(['price_contact' => true]);

        $html = $this->get('/parts/cpu/xeon-gold-6248r')->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringNotContainsString('"@type":"Product"', $html);
    }

    public function test_every_part_remains_reachable_through_crawlable_category_pagination(): void
    {
        $example = ServerPart::where('category', 'cpu')->firstOrFail();
        for ($i = 1; $i <= 20; $i++) {
            $copy = $example->replicate();
            $copy->slug = 'test-cpu-'.$i;
            $copy->name = ['fa' => 'پردازنده آزمون '.$i, 'en' => 'Test CPU '.$i, 'tr' => 'Test CPU '.$i];
            $copy->popular = false;
            $copy->sort = 1000 + $i;
            $copy->save();
        }

        $first = $this->get('/parts/cpu')->assertOk()->getContent();
        $second = $this->get('/parts/cpu?page=2&utm_source=test')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.url('/parts/cpu').'?page=2"', $first);
        $this->assertStringContainsString('href="'.url('/parts/cpu').'?page=2"', $second);
        $this->assertStringContainsString('test-cpu-20', $second);
        $this->assertStringNotContainsString('test-cpu-20', $first);
        $this->assertStringNotContainsString('utm_source=test', $second);
        $this->assertStringContainsString('hreflang="tr" href="'.url('/tr/parts/cpu').'?page=2"', $second);
        $this->assertStringContainsString('href="'.url('/parts/cpu').'?page=2"', $this->get('/parts/cpu?page=999')->assertOk()->getContent());

        $filtered = $this->get('/parts/cpu?sort=name&page=2')->assertOk()->getContent();
        $this->assertStringContainsString('name="robots" content="noindex,follow"', $filtered);
        $this->assertStringNotContainsString('rel="canonical"', $filtered);
        $this->assertStringContainsString('sort=name', $filtered);
    }
}
