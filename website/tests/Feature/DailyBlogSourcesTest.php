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
        config(['daily_blog.feeds' => ['https://developer.nvidia.com/blog/feed/'], 'daily_blog.trend_signals' => false]);
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
        config(['daily_blog.feeds' => ['https://developer.nvidia.com/blog/feed/'], 'daily_blog.trend_signals' => false]);
        Http::preventStrayRequests();
        Http::fake(['https://developer.nvidia.com/blog/feed/' => Http::response('<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss>&x;</rss>')]);
        $this->expectExceptionMessage('no_fresh_official_source');
        app(DailyBlogSources::class)->discover();
    }

    public function test_verified_popularity_can_outrank_freshness_but_unrelated_urls_cannot(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 11, 20)->setTime(6, 0));
        config(['daily_blog.feeds' => ['https://kubernetes.io/feed.xml'], 'daily_blog.trend_signals' => true]);
        $xml = '<rss><channel>';
        foreach (['new' => 1, 'popular' => 2] as $slug => $hours) {
            $xml .= '<item><title>GPU cloud news</title><link>https://kubernetes.io/blog/'.$slug.'</link><pubDate>'.now()->subHours($hours)->toRfc2822String().'</pubDate></item>';
        }
        $xml .= '</channel></rss>';
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($xml) {
            if ($request->url() === 'https://kubernetes.io/feed.xml') {
                return Http::response($xml);
            }
            if (str_starts_with($request->url(), 'https://hn.algolia.com/api/v1/search?')) {
                $popular = str_contains(urldecode($request->url()), '/popular');

                return Http::response(['hits' => [['url' => $popular ? 'https://kubernetes.io/blog/popular' : 'https://evil.example/',
                    'points' => 180, 'num_comments' => 30, 'objectID' => '12345', 'created_at_i' => now()->subHour()->timestamp]]]);
            }

            return Http::response('<article>'.str_repeat('Official Kubernetes GPU details and limitations. ', 30).'</article>');
        });
        $source = app(DailyBlogSources::class)->discover();
        $this->assertSame('https://kubernetes.io/blog/popular', $source['url']);
        $this->assertSame('observed', $source['trend']['status']);
        $this->assertSame(180, $source['trend']['points']);
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'https://evil.example'));
    }
}
