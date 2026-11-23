<?php

namespace Tests\Feature;

use App\Support\HomeServiceDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HomeDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function studio(bool $enabled): void
    {
        Http::preventStrayRequests();
        config(['studio.enabled' => $enabled, 'studio.client_secret' => str_repeat('s', 48),
            'studio.redirect_uri' => 'https://studio.servernet.test/auth/callback',
            'studio.downloads_enabled' => false, 'services.gtm.enabled' => false]);
    }

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($dom);
    }

    private function schemas(string $html): array
    {
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $scripts);

        return array_column(array_map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $scripts[1]), null, '@type');
    }

    public function test_home_directory_is_visible_without_carousel_or_javascript_and_matches_metadata(): void
    {
        $this->studio(true);
        $routes = ['vps'=>'/cloud', 'gpu'=>'/gpu', 'studio'=>'/ai/studio', 'n8n'=>'/cloud/n8n', 'hosting'=>'/hosting/linux', 'email'=>'/transactional-email'];
        foreach (['fa'=>'', 'en'=>'/en', 'tr'=>'/tr'] as $locale => $prefix) {
            $html = $this->get($prefix ?: '/')->assertOk()->getContent();
            $xpath = $this->dom($html);
            $title = (string) $xpath->evaluate('string(//title)');
            $description = (string) $xpath->evaluate('string(//meta[@name="description"]/@content)');
            $this->assertSame(trans('home_discovery.meta_title', [], $locale), $title);
            $this->assertSame(trans('home_discovery.meta_description', [], $locale), $description);
            $this->assertSame(1, $xpath->query('//h1')->length);
            $this->assertSame(0, $xpath->query('//*[@data-hero-carousel]//*[@id="service-directory"]')->length);
            $this->assertSame(0, $xpath->query('//*[@id="service-directory"]/ancestor-or-self::*[@hidden or @inert or @aria-hidden="true"]')->length);
            $this->assertSame(6, $xpath->query('//*[@id="service-directory"]//*[@data-service-guide]')->length);
            $schema = $this->schemas($html);
            $list = $schema['ItemList'];
            $this->assertSame($list['@id'], $schema['WebPage']['mainEntity']['@id']);
            $this->assertSame($title, $schema['WebPage']['name']);
            $this->assertSame($description, $schema['WebPage']['description']);
            $this->assertSame(6, $list['numberOfItems']);
            $this->assertCount(6, $list['itemListElement']);
            $i = 0;
            foreach ($routes as $key => $path) {
                $item = $list['itemListElement'][$i++];
                $card = '//*[@data-service-guide="'.$key.'"]';
                $this->assertSame($i, $item['position']);
                $this->assertSame(url($prefix.$path), $item['item']['url']);
                $this->assertSame($item['item']['url'], (string) $xpath->evaluate('string('.$card.'//a/@href)'));
                $this->assertSame($item['item']['name'], trim((string) $xpath->evaluate('string('.$card.'//h3)')));
                $this->assertSame($item['item']['description'], trim((string) $xpath->evaluate('string('.$card.'//p[1])')));
                $this->assertSame($schema['Organization']['@id'], $item['item']['provider']['@id']);
            }
            $this->assertStringNotContainsString('home_discovery.', $html);
        }
        Http::assertNothingSent();
    }

    public function test_public_studio_has_three_canonical_languages_and_a_distinct_download_policy(): void
    {
        $this->studio(true);
        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $section = $this->get('/sitemaps/ai.xml')->assertOk()->getContent();
        $llms = $this->get('/llms.txt')->assertOk()->getContent();
        foreach (['fa'=>'', 'en'=>'/en', 'tr'=>'/tr'] as $locale => $prefix) {
            $path = $prefix.'/ai/studio';
            $html = $this->get($path)->assertOk()->getContent();
            $xpath = $this->dom($html);
            $this->assertSame(url($path), (string) $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
            $this->assertSame(trans('studio_landing.meta_title', [], $locale), (string) $xpath->evaluate('string(//title)'));
            $this->assertSame(0, $xpath->query('//meta[@name="robots" and contains(@content,"noindex")]')->length);
            foreach (['fa'=>'', 'en'=>'/en', 'tr'=>'/tr', 'x-default'=>''] as $lang => $alt) {
                $this->assertSame(url($alt.'/ai/studio'), (string) $xpath->evaluate('string(//link[@hreflang="'.$lang.'"]/@href)'));
            }
            $this->assertStringContainsString('href="https://studio.servernet.cloud/"', $html);
            $this->assertStringContainsString('href="'.url($prefix.'/ai/models').'"', $html);
            $this->assertStringContainsString('href="'.url($prefix.'/studio/download').'#studio-web-install"', $html);
            $this->assertStringContainsString(url($path).'</loc>', $sitemap);
            $this->assertStringContainsString(url($path).'</loc>', $section);
            $this->assertStringContainsString(']('.url($path).')', $llms);
            $this->assertStringNotContainsString('studio_landing.', $html);
            $this->get($prefix.'/studio/download')->assertOk()->assertSee('noindex,follow', false);
            $this->assertStringNotContainsString(url($prefix.'/studio/download').'</loc>', $sitemap);
        }
        $this->assertStringNotContainsString('/studio/download)', $llms);
        Http::assertNothingSent();
    }

    public function test_disabled_studio_is_not_advertised_in_directory_sitemaps_or_llms(): void
    {
        $this->studio(false);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertArrayNotHasKey('studio', HomeServiceDirectory::entries());
        $this->assertStringNotContainsString('data-service-guide="studio"', $html);
        $this->assertSame(5, $this->schemas($html)['ItemList']['numberOfItems']);
        $this->assertStringContainsString(__('home_discovery.meta_description_without_studio'), $html);
        $this->get('/ai/studio')->assertNotFound();
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/ai/studio', false);
        $this->get('/llms.txt')->assertOk()->assertDontSee('/ai/studio', false);
        Http::assertNothingSent();
    }
}
