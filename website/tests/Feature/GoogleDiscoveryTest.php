<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\Growth\GoogleDiscovery;
use App\Services\Growth\SearchConsoleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::put('google_discovery_enabled', '1');
        Setting::put('google_client_id', 'example.apps.googleusercontent.com');
        Setting::putSecret('google_client_secret', 'test-secret');
    }

    private function post(): Post
    {
        $post = Post::create(['slug' => 'discovery-example', 'type' => 'blog', 'status' => 'published', 'published_at' => now()->subMinute()]);
        foreach (['fa', 'en', 'tr'] as $locale) {
            $post->translations()->create(['locale' => $locale, 'title' => 'Example '.$locale, 'content' => '<p>Complete article</p>']);
        }
        return $post;
    }

    private function receipt(): array
    {
        return json_decode(DB::table('growth_automation_runs')->where('run_key', 'like', 'google-discovery:%')->latest('id')->value('summary'), true);
    }

    public function test_complete_languages_and_published_date_are_required_and_revisions_are_unique(): void
    {
        $post = $this->post();
        $service = app(GoogleDiscovery::class);
        $first = $service->enqueue($post);
        $this->assertCount(3, $first['urls']);
        $this->assertSame($first, $service->enqueue($post));
        $post->translations()->where('locale', 'tr')->update(['content' => 'Changed body']);
        $this->assertNotSame($first['revision'], $service->enqueue($post->fresh('translations'))['revision']);
        $post->translations()->where('locale', 'tr')->delete();
        $this->assertNull($service->enqueue($post->fresh('translations')));
        $post->update(['published_at' => now()->addDay()]);
        $this->assertNull($service->enqueue($post->fresh('translations')));
        Http::assertNothingSent();
    }

    public function test_missing_grant_stays_queued_without_network_and_pause_does_no_work(): void
    {
        $service = app(GoogleDiscovery::class);
        $service->enqueue($this->post());
        $service->run();
        $this->assertSame('waiting_authorization', $this->receipt()['stage']);
        $this->assertSame(0, $this->receipt()['attempts']);
        Setting::put('google_discovery_enabled', '0');
        $this->assertSame(['stage' => 'paused'], $service->run());
        Http::assertNothingSent();
    }

    public function test_sitemap_submission_then_three_inspections_do_not_claim_instant_indexing(): void
    {
        $service = app(GoogleDiscovery::class);
        $receipt = $service->enqueue($this->post());
        $xml = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($receipt['urls'] as $url) {
            $xml .= '<url><loc>'.$url.'</loc><lastmod>'.now()->toDateString().'</lastmod></url>';
        }
        Http::fake([SearchConsoleClient::SITEMAP => Http::response($xml.'</urlset>')]);
        $client = $this->mock(SearchConsoleClient::class);
        $client->shouldReceive('status')->andReturn(['connected' => true]);
        $client->shouldReceive('submitSitemap')->once();
        $client->shouldReceive('inspect')->times(3)->andReturn(['verdict' => 'NEUTRAL', 'coverageState' => 'Discovered - currently not indexed']);
        $service->run();
        $this->assertSame('sitemap_submitted', $this->receipt()['stage']);
        $this->assertArrayNotHasKey('all_indexed', $this->receipt());
        $service->run(); // not due: no duplicate submission
        $this->travel(6)->hours();
        $service->run();
        $this->assertFalse($this->receipt()['all_indexed']);
        $this->assertSame('monitoring', $this->receipt()['stage']);
        $this->assertCount(3, $this->receipt()['inspection']);
    }

    public function test_missing_sitemap_locale_retries_without_sending_to_google(): void
    {
        $service = app(GoogleDiscovery::class);
        $service->enqueue($this->post());
        Http::fake([SearchConsoleClient::SITEMAP => Http::response('<urlset/>')]);
        $client = $this->mock(SearchConsoleClient::class);
        $client->shouldReceive('status')->andReturn(['connected' => true]);
        $client->shouldNotReceive('submitSitemap');
        $service->run();
        $this->assertSame('three_language_sitemap_entries_missing', $this->receipt()['code']);
        $this->assertSame('retry_wait', $this->receipt()['stage']);
        $service->run();
        Http::assertSentCount(1);
    }

    public function test_unpublished_or_replaced_post_cannot_be_submitted(): void
    {
        $post = $this->post();
        app(GoogleDiscovery::class)->enqueue($post);
        $post->update(['status' => 'draft']);
        app(GoogleDiscovery::class)->run();
        $this->assertSame('superseded', $this->receipt()['stage']);
        Http::assertNothingSent();
    }

    private function fakeGrant(string $scope = SearchConsoleClient::SCOPE): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_in' => 3600, 'scope' => $scope]),
            'https://www.googleapis.com/webmasters/v3/sites/*' => Http::response(['siteUrl' => SearchConsoleClient::SITE, 'permissionLevel' => 'siteOwner']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['email_verified' => true, 'email' => 'owner@example.com']),
        ]);
    }

    public function test_grant_is_encrypted_property_verified_and_separate_from_reporting(): void
    {
        Setting::putSecret('bale_manager_reporting_google_token', 'readonly-grant');
        $this->fakeGrant();
        $client = app(SearchConsoleClient::class);
        $client->exchange('code', 'verifier', 'https://console.servernet.cloud/admin/calendar/google/callback');
        $this->assertTrue($client->status()['connected']);
        $this->assertStringNotContainsString('test-refresh', Setting::query()->whereKey(SearchConsoleClient::KEY)->value('value'));
        $client->disconnect();
        $this->assertFalse($client->status()['connected']);
        $this->assertSame('readonly-grant', Setting::getSecret('bale_manager_reporting_google_token'));
    }

    public function test_readonly_grant_is_rejected_before_property_access(): void
    {
        $this->fakeGrant('https://www.googleapis.com/auth/webmasters.readonly');
        try {
            app(SearchConsoleClient::class)->exchange('code', 'verifier', 'https://console.servernet.cloud/admin/calendar/google/callback');
            $this->fail('Readonly grant accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('persistent_write_grant_required', $e->getMessage());
        }
        $this->assertFalse(app(SearchConsoleClient::class)->status()['connected']);
        Http::assertSentCount(1);
    }

    public function test_only_own_blog_urls_are_allowed(): void
    {
        foreach (['https://evil.example/blog/x', 'http://servernet.cloud/blog/x', 'https://servernet.cloud/blog/x?token=secret', 'https://servernet.cloud/account/x', 'https://servernet.cloud/blog/x/../admin'] as $url) {
            $this->assertFalse(SearchConsoleClient::allowedUrl($url));
        }
    }

    public function test_admin_oauth_uses_pkce_and_existing_callback_and_rejects_expired_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->post('/admin/ai-growth/google-discovery/connect')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertStringEndsWith('/admin/calendar/google/callback', $query['redirect_uri']);
        $this->assertSame('offline', $query['access_type']);
        $this->travel(11)->minutes();
        $this->get('/admin/calendar/google/callback?state='.urlencode($query['state']).'&code=example')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_customer_cannot_configure_connection(): void
    {
        $this->actingAs(User::factory()->create())->post('/admin/ai-growth/google-discovery/connect')->assertForbidden();
        Http::assertNothingSent();
    }
}
