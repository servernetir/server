<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Post;
use App\Models\PostTranslation;
use App\Models\Setting;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GrowthArticlePublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_preview_and_missing_snapshot_never_write(): void
    {
        $slug = 'jupyter-hourly-gpu-guide';
        $this->artisan('content:apply-growth', ['slug' => $slug])->assertSuccessful();
        $this->assertDatabaseMissing('posts', ['slug' => $slug]);
        $this->artisan('content:apply-growth', ['slug' => $slug, '--apply' => true])->assertFailed();
        $this->assertDatabaseMissing('posts', ['slug' => $slug]);
    }

    public function test_new_article_uses_existing_cms_and_is_discoverable_in_each_language(): void
    {
        $slug = 'jupyter-hourly-gpu-guide';
        $this->artisan('content:apply-growth', ['slug' => $slug, '--apply' => true, '--expect' => 'absent'])->assertSuccessful();
        $post = Post::where('slug', $slug)->firstOrFail();
        $this->assertSame('blog', $post->type);
        $this->assertSame('published', $post->status);
        $this->assertSame(['en', 'fa', 'tr'], $post->translations()->orderBy('locale')->pluck('locale')->all());
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $response = $this->get($prefix.'/blog/'.$slug)->assertOk();
            $response->assertSee($post->translations()->where('locale', $locale)->first()->title);
            $response->assertSee('href="http://localhost'.$prefix.'/blog/'.$slug.'"', false);
            $response->assertSee('BlogPosting', false);
            $response->assertDontSee('name="robots" content="noindex', false);
        }
        $sitemap = $this->get('/sitemap.xml')->assertOk();
        foreach (['', '/en', '/tr'] as $prefix) {
            $sitemap->assertSee($prefix.'/blog/'.$slug, false);
        }
    }

    public function test_stale_snapshot_preserves_editor_changes_and_other_posts(): void
    {
        $slug = 'cpanel-vs-directadmin';
        $post = Post::create(['slug' => $slug, 'type' => 'blog', 'category' => 'hosting', 'status' => 'draft']);
        PostTranslation::create(['post_id' => $post->id, 'locale' => 'en', 'title' => 'Editor content', 'excerpt' => 'Keep me', 'content' => '<p>Reviewed by editor</p>']);
        $this->artisan('content:apply-growth', ['slug' => $slug, '--apply' => true, '--expect' => 'absent'])->assertFailed();
        $this->assertSame('Editor content', $post->translations()->first()->title);
        $this->assertSame('draft', $post->fresh()->status);
        $this->assertSame(1, Post::count());
    }

    public function test_missing_or_untranslated_guides_do_not_create_dead_or_cross_language_links(): void
    {
        $slug = 'comfyui-hourly-gpu-guide';
        $this->get('/en/gpu')->assertOk()->assertDontSee('/en/blog/'.$slug, false);
        $post = Post::create(['slug' => $slug, 'type' => 'blog', 'category' => 'tutorial', 'status' => 'published', 'published_at' => now()]);
        PostTranslation::create(['post_id' => $post->id, 'locale' => 'fa', 'title' => 'راهنمای موجود', 'excerpt' => 'نمونه', 'content' => '<p>نمونه</p>']);
        cache()->forget('blog.index.blog.fa');
        cache()->forget('blog.index.blog.en');
        $this->get('/gpu')->assertOk()->assertSee('/blog/'.$slug, false);
        $this->get('/en/gpu')->assertOk()->assertDontSee('/en/blog/'.$slug, false);
    }

    public function test_updated_products_and_tool_links_render_in_all_locales(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach (['/vps/hourly', '/hosting/python', '/cloud/n8n', '/vps/gpu', '/webtools/image-cropper', '/webtools/clip-path-generator'] as $path) {
                $this->get($prefix.$path)->assertOk();
            }
            $this->get($prefix.'/webtools/clip-path-generator')->assertSee($prefix.'/webtools/css-triangle', false);
            $this->get($prefix.'/webtools/image-cropper')->assertSee($prefix.'/webtools/image-compressor', false);
        }
    }

    public function test_reviewed_snapshot_updates_only_article_translations_and_preserves_publication_state(): void
    {
        $post = Post::create(['slug' => 'cpanel-vs-directadmin', 'type' => 'blog', 'category' => 'hosting', 'status' => 'draft', 'published_at' => '2026-07-22 12:00:00']);
        PostTranslation::create(['post_id' => $post->id, 'locale' => 'en', 'title' => 'Old comparison', 'excerpt' => 'Old', 'content' => '<p>Old</p>']);
        Artisan::call('content:apply-growth', ['slug' => $post->slug]);
        preg_match('/Current CMS snapshot: ([a-f0-9]{64})/', Artisan::output(), $matches);
        $this->assertArrayHasKey(1, $matches);
        $this->artisan('content:apply-growth', ['slug' => $post->slug, '--apply' => true, '--expect' => $matches[1]])->assertSuccessful();
        $this->assertSame('draft', $post->fresh()->status);
        $this->assertSame('2026-07-22 12:00:00', $post->fresh()->published_at->toDateTimeString());
        $this->assertSame(3, $post->translations()->count());
        $this->assertSame(1, Post::count());
        $this->artisan('content:apply-growth', ['slug' => $post->slug, '--apply' => true, '--expect' => $matches[1]])->assertFailed();
    }

    public function test_existing_gpu_installation_guide_has_scope_note_and_links_only_to_published_guides(): void
    {
        $post = Post::create(['slug' => 'gpu-server-setup-for-ai', 'type' => 'kb', 'category' => 'servers', 'status' => 'published', 'published_at' => now()]);
        foreach (['fa', 'en', 'tr'] as $locale) {
            PostTranslation::create(['post_id' => $post->id, 'locale' => $locale, 'title' => 'GPU setup', 'excerpt' => 'Setup guide', 'content' => '<h2>Driver setup</h2><p>Existing installation content.</p>']);
        }
        foreach (['', '/en', '/tr'] as $prefix) {
            $response = $this->get($prefix.'/docs/'.$post->slug)->assertOk();
            $response->assertSee('HTTPS')->assertSee('SSH');
            $response->assertSee($prefix.'/gpu', false);
            $response->assertDontSee($prefix.'/blog/comfyui-hourly-gpu-guide', false);
        }
        foreach (['/cloud/n8n', '/vps/gpu'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('"@type":"Offer"', false);
        }
    }

    public function test_cost_examples_follow_the_same_currency_quote_as_the_displayed_hourly_rate(): void
    {
        foreach (['USD' => 270000, 'EUR' => 300000, 'USDT' => 270000] as $currency => $rate) {
            Setting::put('fx_last_'.strtolower($currency), json_encode([
                'rate_toman' => $rate, 'currency' => $currency, 'at' => now()->toIso8601String(),
                'source' => 'navasan.net', 'source_item' => ExchangeRate::SOURCE_ITEMS[$currency],
                'source_at' => now()->toIso8601String(),
            ]));
        }
        app(CloudPricingSnapshot::class)->publish([270000, 300000, 270000]);
        CloudLocation::create(['code' => 'ir-tehran', 'country' => 'IR', 'city' => 'Tehran', 'is_active' => true]);
        $plan = CloudPlan::create([
            'provider' => 'proxmox', 'provider_ref' => 'estimate-fixture', 'location_code' => 'ir-tehran',
            'public_name' => 'CV-2-4', 'slug' => 'estimate-fixture', 'vcpu' => 2, 'ram_mb' => 4096,
            'disk_gb' => 40, 'disk_type' => 'nvme', 'price_irt' => 1440000, 'price_eur_cents' => 480,
            'cost_eur_cents' => 300, 'is_active' => true, 'in_stock' => true,
        ]);
        $this->assertNotSame((float) $plan->hourlyEurCents() / 100, (float) $plan->hourlyIrt() / 300000);
        foreach (['/en', '/tr'] as $prefix) {
            $response = $this->get($prefix.'/vps/hourly')->assertOk();
            foreach ([6, 24, 72] as $hours) {
                $response->assertSee('€'.number_format($plan->hourlyEurCents() * $hours / 100, 2, '.', ''));
            }
        }
        $this->get('/vps/hourly')->assertOk()->assertSee(fa_num(number_format($plan->hourlyIrt() * 6)).' تومان');
    }

    public function test_internal_links_in_reviewed_articles_resolve_to_real_localized_routes(): void
    {
        $targets = [];
        foreach (glob(resource_path('content/growth/*.json')) as $file) {
            $article = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $this->artisan('content:apply-growth', ['slug' => $article['slug'], '--apply' => true, '--expect' => 'absent'])->assertSuccessful();
            foreach ($article['translations'] as $translation) {
                preg_match_all('~href="(/[^" ]+)"~', $translation['content'], $matches);
                $targets = array_merge($targets, $matches[1]);
            }
        }
        foreach (array_unique($targets) as $target) {
            $this->get($target)->assertOk();
        }
    }
}
