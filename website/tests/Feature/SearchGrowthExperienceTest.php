<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class SearchGrowthExperienceTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog(modelOver: ['context_tokens' => 128000]);
        Http::preventStrayRequests();
    }

    public function test_public_guidance_and_current_styles_render_in_all_languages_without_side_effects(): void
    {
        foreach (['comfyui-hourly-gpu-guide', 'jupyter-hourly-gpu-guide', 'n8n-backup-migration-guide', 'python-hosting-django-flask-deployment'] as $slug) {
            $this->artisan('content:apply-growth', ['slug' => $slug, '--apply' => true, '--expect' => 'absent'])->assertSuccessful();
        }
        $before = $this->model->fresh()->getRawOriginal();
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            foreach (['ai' => '/ai', 'gpu' => '/gpu', 'python' => '/hosting/python', 'n8n' => '/cloud/n8n'] as $topic => $path) {
                $html = $this->get($prefix.$path)->assertOk()->getContent();
                $nodes = $this->nodes($html);
                $this->assertSame(1, $nodes->query('//h1')->length);
                $section = '//*[@id="'.$topic.'-practical-guide"]';
                $this->assertSame(1, $nodes->query($section)->length);
                $this->assertSame(3, $nodes->query($section.'//article')->length);
                $this->assertSame(3, $nodes->query($section.'//li')->length);
                $this->assertSame(trans('search-growth.'.$topic.'.title', [], $locale), trim($nodes->query($section.'/h2')->item(0)->textContent));
                $this->assertStringContainsString('id="public-page-site-css"', $html);
                $this->assertStringNotContainsString('search-growth.', $html);
                $this->assertStringContainsString('rel="canonical" href="http://localhost'.$prefix.$path.'"', $html);
                foreach ($nodes->query($section.'//a') as $anchor) {
                    $href = $anchor->getAttribute('href');
                    if (str_starts_with($href, '#')) {
                        $this->assertSame(1, $nodes->query('//*[@id="'.substr($href, 1).'"]')->length);
                    } else {
                        $this->assertStringStartsWith('http://localhost'.$prefix.'/', $href);
                        $this->get(parse_url($href, PHP_URL_PATH))->assertOk();
                    }
                }
            }
            $gpu = $this->get($prefix.'/gpu')->getContent();
            $n8n = $this->get($prefix.'/cloud/n8n')->getContent();
            $this->assertStringContainsString('id="n8n-page-css"', $n8n);
            $this->assertDoesNotMatchRegularExpression('~<link[^>]+href="[^"]*/n8n\.css~', $n8n);
        }
        $this->assertSame($before, $this->model->fresh()->getRawOriginal());
        $this->assertDatabaseCount('ai_calls', 0);
        Http::assertNothingSent();
    }

    public function test_curl_example_uses_a_ready_chat_model_and_keeps_the_key_out_of_the_page(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            $html = $this->get($prefix.'/ai')->assertOk()->getContent();
            $code = $this->nodes($html)->query('//*[@id="ai-home-curl"]/code')->item(0)->textContent;
            $this->assertStringContainsString('Authorization: Bearer $SERVERNET_API_KEY', $code);
            $this->assertStringContainsString('Idempotency-Key: $SERVERNET_REQUEST_ID', $code);
            $this->assertStringContainsString('https://servernet.cloud/v1/chat/completions', $code);
            $this->assertStringNotContainsString('sk-test', $html);
            preg_match("~-d '(.*)'$~s", $code, $body);
            $payload = json_decode($body[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($this->model->slug, $payload['model']);
            $this->assertSame(128, $payload['max_tokens']);
            $this->assertSame([['role' => 'user', 'content' => 'Hello']], $payload['messages']);
        }
        Setting::put('ai_sales_open', '0');
        $html = $this->get('/ai')->assertOk()->getContent();
        $code = $this->nodes($html)->query('//*[@id="ai-home-curl"]/code')->item(0)->textContent;
        $this->assertStringContainsString('SELECT_A_READY_CHAT_MODEL', $code);
        $this->assertStringNotContainsString($this->model->slug, $code);
        $this->assertDatabaseCount('ai_calls', 0);
        Http::assertNothingSent();
    }

    public function test_curated_article_links_preserve_original_editorial_content_and_dates(): void
    {
        foreach (['gpu-server-selection' => '/gpu', 'hourly-vs-monthly-billing' => '/vps/hourly'] as $slug => $destination) {
            $post = Post::create(['slug' => $slug, 'type' => 'blog', 'category' => 'cloud', 'status' => 'published', 'published_at' => '2026-08-02 08:00:00']);
            foreach (['fa', 'en', 'tr'] as $locale) {
                PostTranslation::create(['post_id' => $post->id, 'locale' => $locale, 'title' => $locale.' original guide', 'excerpt' => 'Original excerpt', 'content' => '<p>Original editorial body remains.</p>']);
            }
            $before = $post->fresh()->getRawOriginal();
            foreach (['', '/en', '/tr'] as $prefix) {
                $html = $this->get($prefix.'/blog/'.$slug)->assertOk()->getContent();
                $nodes = $this->nodes($html);
                $this->assertSame(1, $nodes->query('//aside[contains(@class,"growth-guides")]//a[@href="http://localhost'.$prefix.$destination.'"]')->length);
                $this->assertStringContainsString('Original editorial body remains.', $html);
            }
            $this->assertSame($before, $post->fresh()->getRawOriginal());
            $this->assertSame(['<p>Original editorial body remains.</p>'], $post->translations()->pluck('content')->unique()->values()->all());
        }
        Http::assertNothingSent();
    }

    public function test_new_python_guide_is_published_in_three_languages_and_links_to_the_right_product(): void
    {
        $slug = 'python-hosting-django-flask-deployment';
        $this->artisan('content:apply-growth', ['slug' => $slug])->assertSuccessful();
        $this->assertDatabaseMissing('posts', ['slug' => $slug]);
        $this->artisan('content:apply-growth', ['slug' => $slug, '--apply' => true, '--expect' => 'absent'])->assertSuccessful();
        $post = Post::where('slug', $slug)->firstOrFail();
        $this->assertSame(['en', 'fa', 'tr'], $post->translations()->orderBy('locale')->pluck('locale')->all());
        foreach (['', '/en', '/tr'] as $prefix) {
            $html = $this->get($prefix.'/blog/'.$slug)->assertOk()->getContent();
            $nodes = $this->nodes($html);
            $this->assertSame(1, $nodes->query('//h1')->length);
            $this->assertStringContainsString('href="http://localhost'.$prefix.'/hosting/python"', $html);
            $this->assertStringNotContainsString('href="http://localhost'.$prefix.'/hosting/wordpress" class="btn', $html);
            $this->assertStringContainsString('/healthz', $html);
            $this->assertStringContainsString('BlogPosting', $html);
            $this->get($prefix.'/hosting/python')->assertOk()->assertSee($prefix.'/blog/'.$slug, false);
            $this->get('/sitemap.xml')->assertOk()->assertSee($prefix.'/blog/'.$slug, false);
        }
        Http::assertNothingSent();
    }

    public function test_unpublished_and_untranslated_guides_are_not_promoted(): void
    {
        $slug = 'python-hosting-django-flask-deployment';
        $post = Post::create(['slug' => $slug, 'type' => 'blog', 'category' => 'hosting', 'status' => 'draft']);
        PostTranslation::create(['post_id' => $post->id, 'locale' => 'fa', 'title' => 'راهنمای آزمایشی', 'excerpt' => 'نمونه', 'content' => '<p>نمونه</p>']);
        $this->get('/hosting/python')->assertOk()->assertDontSee('/blog/'.$slug, false);
        $post->update(['status' => 'published', 'published_at' => now()]);
        cache()->forget('blog.index.blog.fa');
        $this->get('/hosting/python')->assertOk()->assertSee('/blog/'.$slug, false);
        foreach (['en', 'tr'] as $locale) {
            $html = $this->get('/'.$locale.'/hosting/python')->assertOk()->getContent();
            $this->assertStringNotContainsString('/'.$locale.'/blog/'.$slug, $html);
        }
        Http::assertNothingSent();
    }

    public function test_gpu_styles_inline_valid_runtime_css_and_fall_back_when_the_asset_is_missing_or_unsafe(): void
    {
        foreach (['.gpu-current-runtime{color:cyan}', null, '@import "other.css";', '</style><script>alert(1)</script>'] as $css) {
            $styles = new class($css) extends \App\Services\PublicPageStyles {
                public function __construct(private ?string $gpuCss) {}

                protected function readStylesheet(string $asset): ?string
                {
                    return $asset === 'assets/css/gpu-alternatives.css' ? $this->gpuCss : parent::readStylesheet($asset);
                }
            };
            $this->app->instance(\App\Services\PublicPageStyles::class, $styles);
            $html = $this->get('/gpu')->assertOk()->getContent();
            $nodes = $this->nodes($html);
            $this->assertSame($css === '.gpu-current-runtime{color:cyan}' ? 1 : 0, $nodes->query('//style[@id="gpu-alternatives-css"]')->length);
            if ($css === '.gpu-current-runtime{color:cyan}') {
                $this->assertSame($css, $nodes->query('//style[@id="gpu-alternatives-css"]')->item(0)->textContent);
            } else {
                $this->assertSame(1, $nodes->query('//link[contains(@href,"/gpu-alternatives.css")]')->length);
            }
        }
        Http::assertNothingSent();
    }

    private function nodes(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new \DOMXPath($document);
    }
}
