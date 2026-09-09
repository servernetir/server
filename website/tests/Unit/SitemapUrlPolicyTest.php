<?php

namespace Tests\Unit;

use App\Support\SitemapUrlPolicy;
use Tests\TestCase;

class SitemapUrlPolicyTest extends TestCase
{
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
