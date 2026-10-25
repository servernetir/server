<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductGuideRelevanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_python_pages_show_the_selected_reading_path_in_each_language(): void
    {
        $slugs = ['diagnosing-slow-website', 'configuring-dns-for-new-site', 'measuring-real-user-performance'];
        foreach (array_reverse($slugs) as $slug) {
            $this->createGuidePost($slug);
        }
        $this->createGuidePost('iranian-discord-servers');

        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $response = $this->get($prefix.'/hosting/python')->assertOk();
            $cards = $this->guideCards($response->getContent());
            $this->assertSame(array_map(fn ($slug) => $prefix.'/blog/'.$slug, $slugs), array_column($cards, 'path'));
            $this->assertSame(array_map(fn ($slug) => $locale.' '.$slug, $slugs), array_column($cards, 'title'));
        }
    }

    public function test_storage_pages_link_the_existing_comparison_without_altering_sales_readiness(): void
    {
        $slug = 'object-storage-vs-block-storage';
        $this->createGuidePost($slug, category: 'cloud');
        $this->createGuidePost('unrelated-cloud-article', category: 'cloud');

        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            foreach (['object-storage', 'block-storage'] as $kind) {
                $response = $this->get($prefix.'/cloud/'.$kind)->assertOk();
                $this->assertSame([['path' => $prefix.'/blog/'.$slug, 'title' => $locale.' '.$slug]], $this->guideCards($response->getContent()));
                $response->assertDontSee('class="sn-storage-buy"', false);
                $this->assertSame(1, $this->xpath($response->getContent())->query('//h1')->length);
                if ($kind === 'object-storage') {
                    $response->assertSee(__('storage.preparing'));
                }
            }
        }

        // Storage Box has a different job; this change does not add the Object/Block path there.
        $response = $this->get('/cloud/storage-box')->assertOk();
        $this->assertSame([], $this->guideCards($response->getContent()));
    }

    public function test_hosting_reading_paths_prioritize_the_php_refresh_and_control_panel_comparison(): void
    {
        $slugs = ['php-version-upgrade-guide', 'cpanel-vs-directadmin', 'diagnosing-slow-website'];
        foreach ($slugs as $slug) {
            $this->createGuidePost($slug);
        }

        foreach (['', '/en', '/tr'] as $prefix) {
            foreach (['linux', 'wordpress'] as $kind) {
                $response = $this->get($prefix.'/hosting/'.$kind)->assertOk();
                $this->assertSame(array_map(fn ($slug) => $prefix.'/blog/'.$slug, $slugs), array_column($this->guideCards($response->getContent()), 'path'));
            }
        }
    }

    public function test_selected_guides_never_link_drafts_knowledge_base_entries_missing_posts_or_fallback_translations(): void
    {
        config(['blog.product_guide_posts.hosting/python' => [
            'missing-guide', 'draft-guide', 'knowledge-guide', 'persian-only', 'published-guide', 'published-guide',
        ]]);
        $this->createGuidePost('draft-guide', status: 'draft');
        $this->createGuidePost('knowledge-guide', type: 'kb');
        $this->createGuidePost('persian-only', locales: ['fa']);
        $this->createGuidePost('published-guide');
        $this->createGuidePost('unrelated-filler');

        foreach (['en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame(['published-guide'], array_column(blog_guides('hosting', 3, $locale.'/hosting/python'), 'slug'));
        }
        app()->setLocale('fa');
        $this->assertSame(['persian-only', 'published-guide'], array_column(blog_guides('hosting', 3, 'hosting/python'), 'slug'));
        $this->assertSame(['persian-only'], array_column(blog_guides('hosting', 1, 'hosting/python'), 'slug'));
    }

    public function test_a_mapped_product_with_no_translated_guide_does_not_fill_with_unrelated_articles(): void
    {
        $this->createGuidePost('object-storage-vs-block-storage', locales: ['fa'], category: 'cloud');
        $this->createGuidePost('unrelated-cloud-article', category: 'cloud');

        foreach (['/en', '/tr'] as $prefix) {
            $response = $this->get($prefix.'/cloud/object-storage')->assertOk();
            $this->assertSame([], $this->guideCards($response->getContent()));
        }
    }

    public function test_unmapped_products_keep_deterministic_category_rotation_and_fallback(): void
    {
        foreach (['one', 'two', 'three', 'four'] as $slug) {
            $this->createGuidePost($slug);
        }
        $this->createGuidePost('cloud-only', category: 'cloud');

        $first = blog_guides('hosting', 3, 'unmapped/page-a');
        $this->assertCount(3, $first);
        $this->assertSame(['hosting'], array_values(array_unique(array_column($first, 'category'))));
        $this->assertSame($first, blog_guides('hosting', 3, 'unmapped/page-a'));
        $this->assertNotSame(array_column($first, 'slug'), array_column(blog_guides('hosting', 3, 'unmapped/page-b'), 'slug'));

        $withFallback = blog_guides('cloud', 3, 'unmapped/page-a');
        $this->assertSame('cloud-only', $withFallback[0]['slug']);
        $this->assertCount(3, array_unique(array_column($withFallback, 'slug')));
    }

    public function test_refreshed_articles_link_to_the_current_storage_and_hosting_pages_in_both_placements(): void
    {
        $this->createGuidePost('object-storage-vs-block-storage', category: 'cloud');
        $this->createGuidePost('php-version-upgrade-guide');
        // The active storage template does not use these legacy catalog claims.
        foreach (['fa', 'en', 'tr'] as $locale) {
            config(['catalog.cloud.object-storage.'.$locale.'.hero_d' => 'RETIRED_STORAGE_MARKETING_COPY']);
        }

        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            foreach (['object-storage-vs-block-storage' => '/cloud/object-storage', 'php-version-upgrade-guide' => '/hosting/linux'] as $slug => $destination) {
                $response = $this->get($prefix.'/blog/'.$slug)->assertOk();
                $xpath = $this->xpath($response->getContent());
                $author = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " bp-author-cta ")]');
                $main = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " sol-cta-btns ")]/a');
                foreach ([$author, $main] as $links) {
                    $this->assertSame(1, $links->length);
                    $this->assertSame($prefix.$destination, parse_url($links->item(0)->getAttribute('href'), PHP_URL_PATH));
                }
                if ($slug === 'object-storage-vs-block-storage') {
                    $response->assertSee(__('storage.object-storage_intro'));
                    $response->assertDontSee('RETIRED_STORAGE_MARKETING_COPY');
                }
            }
        }
    }

    private function createGuidePost(string $slug, array $locales = ['fa', 'en', 'tr'], string $category = 'hosting', string $status = 'published', string $type = 'blog'): Post
    {
        $post = Post::create(['slug' => $slug, 'type' => $type, 'category' => $category, 'status' => $status, 'published_at' => now()->subDay()]);
        foreach ($locales as $locale) {
            PostTranslation::create(['post_id' => $post->id, 'locale' => $locale, 'title' => $locale.' '.$slug, 'excerpt' => 'Fixture excerpt', 'content' => '<h2>Fixture</h2><p>Isolated test content.</p>']);
        }

        return $post;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }

    /** @return list<array{path:string,title:string}> */
    private function guideCards(string $html): array
    {
        $links = $this->xpath($html)->query('//article[contains(concat(" ", normalize-space(@class), " "), " blog-card ")]//h2/a');
        $cards = [];
        foreach ($links as $link) {
            $cards[] = ['path' => parse_url($link->getAttribute('href'), PHP_URL_PATH), 'title' => trim($link->textContent)];
        }

        return $cards;
    }
}
