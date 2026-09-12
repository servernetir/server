<?php

namespace Tests\Unit;

use App\Support\SitemapUrlPolicy;
use Tests\TestCase;

class SitemapUrlPolicyTest extends TestCase
{
    public function test_urls_must_use_the_complete_https_canonical_origin(): void
    {
        $canonical = 'https://servernet.cloud';

        $this->assertTrue(SitemapUrlPolicy::hasCanonicalOrigin('https://servernet.cloud/en/about', $canonical));
        $this->assertTrue(SitemapUrlPolicy::hasCanonicalOrigin('https://SERVERNET.CLOUD/blog?cat=seo', $canonical));

        foreach ([
            'http://servernet.cloud/about',
            'https://www.servernet.cloud/about',
            'https://servernet.cloud:8443/about',
            'not-a-url',
        ] as $url) {
            $this->assertFalse(SitemapUrlPolicy::hasCanonicalOrigin($url, $canonical), $url);
        }

        $this->assertFalse(
            SitemapUrlPolicy::hasCanonicalOrigin('http://servernet.cloud/about', 'http://servernet.cloud'),
            'An HTTP APP_URL must not make an HTTP sitemap pass the release gate.'
        );
    }

    public function test_only_configured_blog_category_queries_are_allowed(): void
    {
        $this->assertTrue(SitemapUrlPolicy::allowsQuery('/about', null));

        foreach (['/blog', '/en/blog', '/tr/blog'] as $path) {
            $this->assertTrue(SitemapUrlPolicy::allowsQuery($path, 'cat=seo'));
        }

        foreach ([
            ['/about', 'utm_source=test'],
            ['/blog', 'page=2'],
            ['/blog', 'tag=seo'],
            ['/blog', 'q=hosting'],
            ['/blog', 'cat=unknown'],
            ['/blog', 'cat=seo&utm_source=test'],
            ['/blog', 'utm_source=test&cat=seo'],
            ['/blog', 'cat=seo%20'],
        ] as [$path, $query]) {
            $this->assertFalse(SitemapUrlPolicy::allowsQuery($path, $query), $path.'?'.$query);
        }
    }
}
