<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteController;
use App\Models\Post;
use App\Models\PostTranslation;
use App\Models\Product;
use App\Services\DocsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_kb_sitemaps_and_hreflang_only_advertise_real_translations(): void
    {
        Http::preventStrayRequests();
        $make = function (string $slug, array $locales, string $status = 'published'): Post {
            $post = Post::create([
                'slug' => $slug, 'type' => 'kb', 'category' => 'hosting',
                'status' => $status, 'published_at' => '2026-08-02 08:00:00',
            ]);
            foreach ($locales as $locale) {
                PostTranslation::create([
                    'post_id' => $post->id, 'locale' => $locale, 'title' => $locale.' localized title',
                    'excerpt' => 'Fixture excerpt', 'content' => '<p>Fixture body.</p>',
                ]);
            }

            return $post;
        };
        $post = $make('kb-localized', ['fa', 'en']);
        $make('kb-english-only', ['en']);
        $make('kb-unpublished', ['fa', 'en', 'tr'], 'draft');
        DocsRepository::flush();

        foreach (['/sitemap.xml', '/sitemaps/docs.xml'] as $map) {
            $locs = $this->locs($this->get($map)->assertOk()->getContent());
            foreach (['/docs/kb-localized', '/en/docs/kb-localized', '/en/docs/kb-english-only'] as $path) {
                $this->assertContains(url($path), $locs);
            }
            foreach (['/tr/docs/kb-localized', '/docs/kb-english-only', '/tr/docs/kb-english-only', '/docs/kb-unpublished'] as $path) {
                $this->assertNotContains(url($path), $locs);
            }
        }

        foreach (['/docs/kb-localized', '/en/docs/kb-localized'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('content="noindex,follow"', $html);
            $this->assertStringContainsString('<link rel="canonical" href="'.url($path).'">', $html);
            $this->assertStringContainsString('<link rel="alternate" hreflang="fa" href="'.url('/docs/kb-localized').'">', $html);
            $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="'.url('/en/docs/kb-localized').'">', $html);
            $this->assertStringNotContainsString('<link rel="alternate" hreflang="tr"', $html);
            $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="'.url('/docs/kb-localized').'">', $html);
        }

        $englishOnly = $this->get('/en/docs/kb-english-only')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/en/docs/kb-english-only').'">', $englishOnly);
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="'.url('/en/docs/kb-english-only').'">', $englishOnly);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="'.url('/en/docs/kb-english-only').'">', $englishOnly);
        $this->assertStringNotContainsString('<link rel="alternate" hreflang="fa"', $englishOnly);

        foreach (['/tr/docs/kb-localized', '/docs/kb-english-only', '/tr/docs/kb-english-only'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString('content="noindex,follow"', $html);
            $this->assertStringNotContainsString('<link rel="canonical"', $html);
            $this->assertStringNotContainsString('<link rel="alternate" hreflang=', $html);
        }
        $this->get('/en/docs')->assertOk()->assertSee(url('/en/docs/kb-localized'), false);
        $this->get('/tr/docs')->assertOk()->assertDontSee(url('/tr/docs/kb-localized'), false);

        // A newly available translation restores normal discovery without any route changes.
        PostTranslation::create([
            'post_id' => $post->id, 'locale' => 'tr', 'title' => 'Turkish localized title',
            'excerpt' => 'Fixture excerpt', 'content' => '<p>Fixture body.</p>',
        ]);
        DocsRepository::flush();
        $sitemap = $this->locs($this->get('/sitemaps/docs.xml')->assertOk()->getContent());
        $this->assertContains(url('/tr/docs/kb-localized'), $sitemap);
        $this->get('/tr/docs/kb-localized')->assertOk()->assertSee('<link rel="canonical" href="'.url('/tr/docs/kb-localized').'">', false);
        Http::assertNothingSent();
    }

    public function test_discovery_lists_do_not_load_article_bodies_but_detail_still_does(): void
    {
        Http::preventStrayRequests();
        $body = '<p>'.str_repeat('fixture article body ', 2500).'</p>';
        foreach (['blog', 'kb'] as $type) {
            $post = Post::create([
                'slug' => 'metadata-'.$type, 'type' => $type, 'category' => 'hosting',
                'status' => 'published', 'published_at' => '2026-08-02 08:00:00',
            ]);
            PostTranslation::create([
                'post_id' => $post->id, 'locale' => 'fa', 'title' => 'Metadata title '.$type,
                'excerpt' => 'Fixture excerpt', 'content' => $body, 'tags' => ['fixture'],
            ]);
        }

        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $locs = $this->locs($this->get('/sitemap.xml')->assertOk()->getContent());
            $tree = app(DocsRepository::class)->tree();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertContains(url('/blog/metadata-blog'), $locs);
        $this->assertContains(url('/docs/metadata-kb'), $locs);
        $this->assertContains(['slug' => 'metadata-kb', 'title' => 'Metadata title kb'], $tree['hosting']['items']);
        $translationQueries = array_values(array_filter($queries, static fn (array $query) =>
            (bool) preg_match('/\bfrom\s+["`]?post_translations["`]?\b/i', $query['query'])));
        $this->assertGreaterThanOrEqual(2, count($translationQueries));
        foreach ($translationQueries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\bselect\s+\*|\bcontent\b/i', $query['query'],
                'List and sitemap reads must not select article bodies.');
        }

        $this->assertSame($body, app(DocsRepository::class)->find('metadata-kb')['content']);
        $this->assertSame($body, app(\App\Services\BlogRepository::class)->find('metadata-blog')['content']);
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
