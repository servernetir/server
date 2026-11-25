<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyDiscoveryAliasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_public_hubs_redirect_in_the_requested_locale(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach ([
                '/category' => '/blog',
                '/tools' => '/webtools',
                '/vps/compare-locations' => '/cloud#locations',
            ] as $from => $to) {
                $this->get($prefix.$from)->assertStatus(301)->assertRedirect(url($prefix.$to));
            }
        }
    }

    public function test_old_sitemap_entry_points_redirect_to_current_sitemaps(): void
    {
        $this->get('/sitemap_index.xml')->assertStatus(301)->assertRedirect(url('/sitemap-index.xml'));
        foreach ([
            '/post-sitemap.xml', '/sitemap_blogs_1.xml', '/blog-categories-sitemap.xml',
            '/author-sitemap.xml', '/store-products-sitemap.xml', '/sitemap.rss',
        ] as $path) {
            $this->get($path)->assertStatus(301)->assertRedirect(url('/sitemap.xml'));
        }
    }

    public function test_unknown_paths_keep_their_404_instead_of_being_hidden(): void
    {
        foreach ([
            '/category/unmapped-9f3a', '/en/tools/unmapped-9f3a', '/en/ai/null',
            '/en/vps/unmapped-9f3a', '/unmapped-9f3a-sitemap.xml',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
