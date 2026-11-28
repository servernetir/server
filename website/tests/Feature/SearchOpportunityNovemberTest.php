<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchOpportunityNovemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_and_latency_tools_explain_the_result_and_link_to_the_right_product(): void
    {
        Http::preventStrayRequests();
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            foreach (['/tools/domain-ideas' => '/domains', '/lookup/ping' => '/vps/hourly', '/lookup/global-ping' => '/vps/hourly', '/tools/speedtest' => '/vps/hourly'] as $path => $target) {
                $html = $this->get($prefix.$path)->assertOk()->getContent();
                $dom = $this->xpath($html);
                $this->assertSame(1, $dom->query('//h1')->length, $locale.$path);
                $this->assertSame(url($prefix.$path), $dom->evaluate('string(//link[@rel="canonical"]/@href)'));
                $this->assertSame(url($prefix.$target), $dom->evaluate('string(//a[@data-tool-product]/@href)'));
                $this->assertSame(basename($path), $dom->evaluate('string(//a[@data-tool-product]/@data-tool-slug)'));
                $this->assertNotEmpty($dom->evaluate('string(//meta[@name="description"]/@content)'));
                if ($path === '/tools/domain-ideas') {
                    $this->assertGreaterThanOrEqual(7, $dom->query('//div[contains(@class,"tl-faq")]//details')->length);
                }
                if ($path === '/lookup/ping') {
                    $this->assertStringContainsString('TCP', $dom->evaluate('string(//meta[@name="description"]/@content)'));
                    $this->assertStringContainsString('ICMP', $dom->evaluate('string(//meta[@name="description"]/@content)'));
                    $label = trans('ui.lk_ping_loss', [], $locale);
                    $this->assertTrue(str_contains($html, $label) || str_contains($html, json_encode($label)));
                }
            }
            $this->get($prefix.'/webtools/image-cropper')->assertOk();
            $this->get($prefix.'/webtools/svg-to-png')->assertOk();
        }
        Http::assertNothingSent();
    }

    public function test_commercial_metadata_is_localized_and_does_not_replace_the_verified_purchase_rules(): void
    {
        $hourly = $this->get('/en/vps/hourly')->assertOk()->getContent();
        $this->assertStringContainsString('Pay-as-You-Go', $this->xpath($hourly)->evaluate('string(//title)'));
        $this->assertStringContainsString('Cost Calculator', $this->xpath($hourly)->evaluate('string(//title)'));
        $gpu = $this->get('/tr/gpu')->assertOk()->getContent();
        $this->assertStringContainsString('Ekran Kartı Kiralama', $this->xpath($gpu)->evaluate('string(//title)'));
        $this->assertStringContainsString('Ekran Kartı Kiralama', $this->xpath($gpu)->evaluate('string(//h1)'));
        // Do not change the current locale-specific Iran visibility policy to seek more traffic.
        $this->get('/en/vps/iran')->assertNotFound();
    }

    public function test_recover_only_known_legacy_routes_and_keep_unknown_urls_missing(): void
    {
        foreach ([
            '/host' => '/hosting/linux',
            '/9-روش-برای-افزایش-سرعت-وردپرس' => '/blog/wordpress-speed-optimization',
            '/طراحی-وب-سایت-حرفه-ای-ساخت-وب-سایت-اختص' => '/solutions/web-design',
        ] as $old => $new) {
            $this->get($old)->assertStatus(301)->assertRedirect($new);
        }
        $this->get('/19332-2')->assertNotFound();
        $this->get('/tr/urmia/web-design')->assertStatus(410);
    }

    public function test_editorial_links_do_not_change_published_articles(): void
    {
        $posts = ['php-version-upgrade-guide' => '/vps/hourly', 'iranian-discord-servers' => '/vps/discord', 'wordpress-speed-optimization' => '/hosting/linux'];
        foreach ($posts as $slug => $target) {
            $post = Post::create(['slug' => $slug, 'type' => 'blog', 'category' => 'tutorial', 'status' => 'published', 'published_at' => '2026-09-01 00:00:00']);
            foreach (['fa', 'en', 'tr'] as $locale) {
                PostTranslation::create(['post_id' => $post->id, 'locale' => $locale, 'title' => 'Original title '.$locale, 'excerpt' => 'Original excerpt', 'content' => '<p>Editorial content remains unchanged.</p>']);
            }
            foreach (['', '/en', '/tr'] as $prefix) {
                $html = $this->get($prefix.'/blog/'.$slug)->assertOk()->getContent();
                $this->assertStringContainsString('Editorial content remains unchanged.', $html);
                $this->assertSame(1, $this->xpath($html)->query('//aside[contains(@class,"growth-guides")]//a[@href="'.url($prefix.$target).'"]')->length);
            }
            $this->assertSame(['<p>Editorial content remains unchanged.</p>'], $post->translations()->pluck('content')->unique()->values()->all());
        }
    }

    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        return new \DOMXPath($doc);
    }
}
