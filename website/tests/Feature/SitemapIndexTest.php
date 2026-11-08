<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteController;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * نقشه‌های بخش‌بندی‌شده باید **دقیقاً** همان نشانی‌های `/sitemap.xml` را
 * پوشش دهند — نه کمتر (بخشی که از گزارشِ Search Console غیب شود) و نه بیشتر
 * (نشانی‌ای که فقط در یک نقشهٔ فرعی باشد و site:gate هرگز نبیندش).
 */
class SitemapIndexTest extends TestCase
{
    use RefreshDatabase;

    private function locs(string $xml): array
    {
        preg_match_all('#<loc>([^<]+)</loc>#', $xml, $m);

        return array_map(fn ($l) => html_entity_decode($l, ENT_XML1), $m[1]);
    }

    public function test_the_index_lists_only_known_non_empty_sections(): void
    {
        $res = $this->get('/sitemap-index.xml');
        $res->assertOk();
        $this->assertStringContainsString('<sitemapindex', $res->getContent());

        $sections = $this->locs($res->getContent());
        $this->assertNotEmpty($sections);
        foreach ($sections as $loc) {
            $this->assertMatchesRegularExpression('#/sitemaps/([a-z]+)\.xml$#', $loc);
            preg_match('#/sitemaps/([a-z]+)\.xml$#', $loc, $m);
            $this->assertContains($m[1], SiteController::SITEMAP_SECTIONS);
            $this->get('/sitemaps/'.$m[1].'.xml')->assertOk();
        }
    }

    public function test_sections_partition_the_full_sitemap_exactly(): void
    {
        $full = $this->locs($this->get('/sitemap.xml')->assertOk()->getContent());
        $this->assertNotEmpty($full);

        $union = [];
        foreach ($this->locs($this->get('/sitemap-index.xml')->getContent()) as $sectionUrl) {
            $path = parse_url($sectionUrl, PHP_URL_PATH);
            $part = $this->locs($this->get($path)->assertOk()->getContent());
            // هر نشانی در بخشی است که اسمش می‌گوید
            $name = basename($path, '.xml');
            foreach ($part as $loc) {
                $this->assertSame($name, SiteController::sitemapSectionFor($loc), $loc);
            }
            $union = array_merge($union, $part);
        }

        sort($full);
        sort($union);
        $this->assertSame($full, $union, 'بخش‌ها با نقشهٔ کامل یکی نیستند');
    }

    public function test_unknown_section_is_404(): void
    {
        $this->get('/sitemaps/nope.xml')->assertNotFound();
    }

    public function test_noindex_order_handoffs_are_excluded_from_all_sitemaps(): void
    {
        Http::preventStrayRequests();
        $product = Product::create([
            'name' => 'Linux hosting', 'slug' => 'sitemap-linux', 'category' => 'shared',
            'group' => 'linux', 'price' => 250000, 'setup_fee' => 0, 'cycle' => 'monthly',
            'tax_percent' => 10, 'is_active' => true,
        ]);

        $full = $this->locs($this->get('/sitemap.xml')->assertOk()->getContent());
        foreach (['', '/en', '/tr'] as $prefix) {
            $path = $prefix.'/order/'.$product->slug;
            $this->get($path)->assertOk()->assertSee('noindex,follow', false);
            $this->assertNotContains(url($path), $full);
            $this->assertContains(url($prefix.'/hosting/linux'), $full);
        }
        foreach ($full as $loc) {
            $this->assertDoesNotMatchRegularExpression('~/(?:en/|tr/)?order/~', $loc);
        }
        $index = $this->get('/sitemap-index.xml')->assertOk()->getContent();
        $this->assertStringNotContainsString('/sitemaps/orders.xml', $index);
        foreach ($this->locs($index) as $sectionUrl) {
            foreach ($this->locs($this->get(parse_url($sectionUrl, PHP_URL_PATH))->assertOk()->getContent()) as $loc) {
                $this->assertDoesNotMatchRegularExpression('~/(?:en/|tr/)?order/~', $loc);
            }
        }
        $this->get('/sitemaps/orders.xml')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_language_prefix_does_not_change_the_section(): void
    {
        foreach (['', '/en', '/tr'] as $lp) {
            $this->assertSame('blog', SiteController::sitemapSectionFor("https://servernet.cloud{$lp}/blog/x"));
            $this->assertSame('orders', SiteController::sitemapSectionFor("https://servernet.cloud{$lp}/order/vps-1"));
            $this->assertSame('hardware', SiteController::sitemapSectionFor("https://servernet.cloud{$lp}/parts/cpu/x"));
            $this->assertSame('products', SiteController::sitemapSectionFor("https://servernet.cloud{$lp}/vps/germany"));
            $this->assertSame('pages', SiteController::sitemapSectionFor("https://servernet.cloud{$lp}"));
        }
    }

    public function test_robots_advertises_the_index(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));
        $this->assertStringContainsString('Sitemap: https://servernet.cloud/sitemap-index.xml', $robots);
        $this->assertStringContainsString('Sitemap: https://servernet.cloud/sitemap.xml', $robots);
    }
}
