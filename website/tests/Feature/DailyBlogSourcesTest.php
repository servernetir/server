<?php

namespace Tests\Feature;

use App\Services\Growth\DailyBlogSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DailyBlogSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_rejects_stale_future_and_external_urls_and_uses_official_snapshot(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 11, 16)->setTime(6, 0));
        config(['daily_blog.feeds' => ['https://developer.nvidia.com/blog/feed/']]);
        $xml = '<rss><channel>';
        foreach (['https://developer.nvidia.com/blog/gpu-news/' => now()->subHour(), 'https://evil.example/gpu' => now(),
            'https://developer.nvidia.com/blog/stale' => now()->subDays(9), 'https://developer.nvidia.com/blog/future' => now()->addDay()] as $url => $date) {
            $xml .= '<item><title>GPU inference model news</title><link>'.$url.'</link><pubDate>'.$date->toRfc2822String().'</pubDate></item>';
        }
        $xml .= '</channel></rss>';
        Http::preventStrayRequests();
        Http::fake(['https://developer.nvidia.com/blog/feed/' => Http::response($xml),
            'https://developer.nvidia.com/blog/gpu-news/' => Http::response('<article>'.str_repeat('Official GPU model details and limitations. ', 30).'</article>')]);
        $result = app(DailyBlogSources::class)->discover();
        $this->assertSame('https://developer.nvidia.com/blog/gpu-news/', $result['url']);
        $this->assertSame(1, $result['candidates_considered']);
        $this->assertStringContainsString('Official GPU', $result['text']);
        Http::assertSentCount(2);
    }

    public function test_xml_entities_are_never_resolved(): void
    {
        config(['daily_blog.feeds' => ['https://developer.nvidia.com/blog/feed/']]);
        Http::preventStrayRequests();
        Http::fake(['https://developer.nvidia.com/blog/feed/' => Http::response('<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss>&x;</rss>')]);
        $this->expectExceptionMessage('no_fresh_official_source');
        app(DailyBlogSources::class)->discover();
    }
}
