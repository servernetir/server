<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use App\Providers\AppServiceProvider;
use App\Services\BlogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoMetadataAndEntityTest extends TestCase
{
    use RefreshDatabase;

    public function test_blog_freshness_uses_the_rendered_translations_update_date(): void
    {
        $post = Post::create([
            'slug' => 'translated-freshness',
            'type' => 'blog',
            'category' => 'seo',
            'status' => 'published',
            'published_at' => '2026-08-01 00:00:00',
        ]);
        $post->forceFill(['updated_at' => '2026-08-02 00:00:00'])->saveQuietly();

        $translation = PostTranslation::create([
            'post_id' => $post->id,
            'locale' => 'en',
            'title' => 'Fresh translation',
            'excerpt' => 'Fresh translation excerpt',
            'content' => '<p>Fresh translation body.</p>',
        ]);
        $translation->forceFill(['updated_at' => '2026-09-10 00:00:00'])->saveQuietly();

        app()->setLocale('en');
        $rendered = app(BlogRepository::class)->find($post->slug);

        $this->assertSame('2026-09-10', $rendered['updated'] ?? null);
    }

    public function test_open_graph_url_uses_the_same_absolute_identity_as_canonical(): void
    {
        foreach (['/', '/en', '/blog', '/en/about', '/lookup', '/lookup/mx', '/about?utm_source=review'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            preg_match('~<link rel="canonical" href="([^"]+)">~', $html, $canonical);
            preg_match('~<meta property="og:url" content="([^"]+)">~', $html, $openGraph);

            $this->assertNotEmpty($canonical[1] ?? null, $path.' has no canonical');
            $this->assertSame($canonical[1], $openGraph[1] ?? null, $path.' exposes conflicting page identities');
            $this->assertTrue(str_starts_with($canonical[1], 'http'), $path.' canonical is not absolute');
        }
    }

    public function test_organization_and_website_entities_are_consistent(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $scripts);

        $entities = [];
        foreach ($scripts[1] as $json) {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (isset($decoded['@type'])) {
                $entities[$decoded['@type']] = $decoded;
            }
        }

        $organization = $entities['Organization'] ?? [];
        $website = $entities['WebSite'] ?? [];

        $this->assertSame(rtrim(config('app.url'), '/').'/#organization', $organization['@id'] ?? null);
        $this->assertSame(url('/favicon.svg'), $organization['logo'] ?? null);
        $this->assertEqualsCanonicalizing(array_keys(AppServiceProvider::LOCALES), $organization['contactPoint']['availableLanguage'] ?? []);
        $this->assertSame(rtrim(config('app.url'), '/').'/#website', $website['@id'] ?? null);
        $this->assertSame($organization['@id'], $website['publisher']['@id'] ?? null);
        $this->assertEqualsCanonicalizing(array_keys(AppServiceProvider::LOCALES), $website['inLanguage'] ?? []);
    }

    public function test_route_view_pages_have_clean_reciprocal_hreflang_urls(): void
    {
        foreach (['/badge', '/domains/transfer'] as $basePath) {
            foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
                $html = $this->get($prefix.$basePath)->assertOk()->getContent();

                foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $alternateLocale => $alternatePrefix) {
                    $expected = url($alternatePrefix.$basePath);
                    $this->assertStringContainsString(
                        '<link rel="alternate" hreflang="'.$alternateLocale.'" href="'.$expected.'">',
                        $html
                    );
                }

                $this->assertStringContainsString(
                    '<link rel="alternate" hreflang="'.$locale.'" href="'.url($prefix.$basePath).'">',
                    $html
                );
                $this->assertStringNotContainsString('?view=', $html);
                $this->assertStringNotContainsString('?status=', $html);
            }
        }
    }

    public function test_primary_templates_have_complete_consistent_social_metadata(): void
    {
        foreach (['/', '/hosting/linux', '/cloud', '/blog', '/webtools/json-formatter', '/about'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $dom = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML($html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new \DOMXPath($dom);

            $title = trim((string) $xpath->evaluate('string(//title)'));
            $description = trim((string) $xpath->evaluate('string(//meta[@name="description"]/@content)'));

            $this->assertNotSame('', $title, $path.' has an empty title');
            $this->assertNotSame('', $description, $path.' has an empty description');
            $this->assertSame($title, (string) $xpath->evaluate('string(//meta[@property="og:title"]/@content)'), $path);
            $this->assertSame($description, (string) $xpath->evaluate('string(//meta[@property="og:description"]/@content)'), $path);
            $this->assertSame($title, (string) $xpath->evaluate('string(//meta[@name="twitter:title"]/@content)'), $path);
            $this->assertSame($description, (string) $xpath->evaluate('string(//meta[@name="twitter:description"]/@content)'), $path);

            foreach (['og:image' => 'property', 'twitter:image' => 'name'] as $name => $attribute) {
                $image = (string) $xpath->evaluate("string(//meta[@{$attribute}=\"{$name}\"]/@content)");
                $this->assertTrue(filter_var($image, FILTER_VALIDATE_URL) !== false, $path.' has a non-absolute '.$name);
            }
        }
    }

    public function test_page_schemas_reference_the_single_organization_identity(): void
    {
        $organizationId = rtrim(config('app.url'), '/').'/#organization';

        foreach (['/tools/seo', '/webtools/json-formatter'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $scripts);

            $publishers = [];
            $nestedOrganizations = 0;
            foreach ($scripts[1] as $json) {
                $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                if (($decoded['@type'] ?? null) === 'Organization') {
                    $nestedOrganizations++;
                }
                if (isset($decoded['publisher'])) {
                    $publishers[] = $decoded['publisher'];
                }
            }

            $this->assertSame(1, $nestedOrganizations, $path.' emits multiple Organization identities');
            $this->assertNotEmpty($publishers, $path.' has no publisher relationship');
            foreach ($publishers as $publisher) {
                $this->assertSame(['@id' => $organizationId], $publisher, $path);
            }
        }
    }
}
