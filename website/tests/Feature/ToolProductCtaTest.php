<?php

namespace Tests\Feature;

use App\Http\Controllers\WebToolsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ToolProductCtaTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_eligible_tool_renders_localized_product_links_without_changing_its_free_offer(): void
    {
        Http::preventStrayRequests();
        $paths = array_merge(
            array_map(fn ($slug) => '/tools/'.$slug, ['seo', 'whois', 'ip', 'domain-ideas', 'speedtest']),
            array_map(fn ($slug) => '/webtools/'.$slug, WebToolsController::slugs()),
        );

        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            foreach ($paths as $path) {
                $response = $this->get($prefix.$path)->assertOk();
                $html = $response->getContent();
                $xpath = $this->document($html);
                $cta = $xpath->query('//section[@aria-labelledby="tool-next-step-title"]');
                $this->assertSame(1, $cta->length, $prefix.$path);
                $links = $xpath->query('.//a[@data-tool-product]', $cta->item(0));
                $this->assertSame(1, $links->length, $prefix.$path);
                $this->assertContains($links->item(0)->getAttribute('href'), [
                    url($prefix.'/domains'), url($prefix.'/vps/hourly'), url($prefix.'/hosting/linux'),
                ]);
                $this->assertSame(basename($path), $links->item(0)->getAttribute('data-tool-slug'));
                foreach (['/status', '/sla'] as $target) {
                    $this->assertSame(1, $xpath->query('.//a[@href="'.url($prefix.$target).'"]', $cta->item(0))->length);
                }
                $this->assertStringNotContainsString('tool-next-step.', $cta->item(0)->textContent);
                $this->assertSame(1, $xpath->query('//h1')->length);
                $this->assertSame(url($prefix.$path), $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
                $this->assertSame(40, strlen($xpath->evaluate('string(//meta[@name="csrf-token"]/@content)')));

                $apps = [];
                foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
                    $schema = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);
                    if (($schema['@type'] ?? '') === 'WebApplication') $apps[] = $schema;
                }
                $this->assertCount(1, $apps);
                $this->assertSame($locale, $apps[0]['inLanguage']);
                $this->assertEquals(0, $apps[0]['offers']['price']);
                $this->assertSame('IRR', $apps[0]['offers']['priceCurrency']);
            }
        }
        Http::assertNothingSent();
    }

    public function test_representative_tools_link_to_the_matching_product_family_in_every_locale(): void
    {
        $expectations = [
            '/tools/whois' => ['domain', '/domains'],
            '/tools/domain-ideas' => ['domain', '/domains'],
            '/tools/ip' => ['vps', '/vps/hourly'],
            '/tools/seo' => ['hosting', '/hosting/linux'],
            '/webtools/qr-generator' => ['domain', '/domains'],
            '/webtools/json-formatter' => ['vps', '/vps/hourly'],
            '/webtools/meta-tag-generator' => ['hosting', '/hosting/linux'],
            '/webtools/text-counter' => ['project', '/hosting/linux'],
        ];
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach ($expectations as $path => [$family, $target]) {
                $xpath = $this->document($this->get($prefix.$path)->assertOk()->getContent());
                $this->assertSame(1, $xpath->query('//section[@aria-labelledby="tool-next-step-title"]//a[@data-tool-product="'.$family.'" and @href="'.url($prefix.$target).'"]')->length);
            }
        }
    }

    private function document(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }
}
