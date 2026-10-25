<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitDeploymentArticleReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_reviewed_article_has_complete_locales_verified_sources_and_safe_references(): void
    {
        $source = json_decode(file_get_contents(resource_path('content/growth/git-basics-for-deployment.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('git-basics-for-deployment', $source['slug']);
        $this->assertSame(['fa', 'en', 'tr'], array_keys($source['translations']));

        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $translation = $source['translations'][$locale];
            $html = $translation['content'];

            $this->assertNotEmpty($translation['title']);
            $this->assertNotEmpty($translation['excerpt']);
            $this->assertGreaterThanOrEqual(5, substr_count($html, '<h2>'));
            $this->assertStringContainsString('X-Hub-Signature-256', $html);
            $this->assertStringContainsString('X-GitHub-Delivery', $html);
            $this->assertStringContainsString('docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries', $html);
            $this->assertStringContainsString('docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/', $html);
            $this->assertStringContainsString('href="'.$prefix.'/vps/hourly"', $html);
            $this->assertStringNotContainsString('<?php', $html);
            $this->assertStringNotContainsString("shell_exec(", $html);
            $this->assertStringNotContainsString('git reset --hard HEAD~1', $html);
        }
    }

    public function test_guarded_refresh_preserves_existing_post_and_renders_all_languages(): void
    {
        $post = Post::create([
            'slug' => 'git-basics-for-deployment',
            'type' => 'blog',
            'category' => 'tutorial',
            'status' => 'published',
            'published_at' => '2026-08-13 08:00:00',
        ]);
        foreach (['fa', 'en', 'tr'] as $locale) {
            PostTranslation::create([
                'post_id' => $post->id,
                'locale' => $locale,
                'title' => 'Prior '. $locale,
                'excerpt' => 'Previously reviewed',
                'content' => '<p>Outdated tutorial.</p>',
            ]);
        }

        Artisan::call('content:apply-growth', ['slug' => $post->slug]);
        preg_match('/Current CMS snapshot: ([a-f0-9]{64})/', Artisan::output(), $matches);
        $this->assertArrayHasKey(1, $matches);

        $this->artisan('content:apply-growth', [
            'slug' => $post->slug,
            '--apply' => true,
            '--expect' => $matches[1],
        ])->assertSuccessful();

        $this->assertSame($post->id, $post->fresh()->id);
        $this->assertSame('2026-08-13 08:00:00', $post->fresh()->published_at->format('Y-m-d H:i:s'));
        $this->assertSame('published', $post->fresh()->status);
        $this->assertSame(3, $post->translations()->count());

        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $response = $this->get($prefix.'/blog/'.$post->slug)->assertOk();
            $response->assertSee('X-Hub-Signature-256', false);
            $response->assertSee($prefix.'/vps/hourly', false);
            $this->assertStringNotContainsString('<?php', $response->getContent());
        }

        $this->artisan('content:apply-growth', [
            'slug' => $post->slug,
            '--apply' => true,
            '--expect' => $matches[1],
        ])->assertFailed();
    }
}
