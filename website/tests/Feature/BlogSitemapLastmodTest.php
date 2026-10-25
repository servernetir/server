<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlogSitemapLastmodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_editing_one_translation_updates_only_its_lastmod_and_preserves_publication(): void
    {
        $post = $this->createArticle('translated-article');
        foreach (['fa', 'en', 'tr'] as $locale) {
            $this->createTranslation($post, $locale, '2026-08-02 08:00:00');
        }
        $this->assertSame([
            '/blog/translated-article' => '2026-08-02',
            '/en/blog/translated-article' => '2026-08-02',
            '/tr/blog/translated-article' => '2026-08-02',
        ], $this->blogDates());

        $this->travelTo(new \DateTimeImmutable('2026-10-25 12:00:00'));
        $post->translations()->where('locale', 'en')->firstOrFail()->update(['content' => '<p>Substantially reviewed English body.</p>']);

        $this->assertSame([
            '/blog/translated-article' => '2026-08-02',
            '/en/blog/translated-article' => '2026-10-25',
            '/tr/blog/translated-article' => '2026-08-02',
        ], $this->blogDates());
        $this->assertSame('2026-08-02 08:00:00', $post->fresh()->published_at->format('Y-m-d H:i:s'));
        $this->assertSame('published', $post->fresh()->status);
        $this->travelBack();
    }

    public function test_an_earlier_translation_edit_never_moves_lastmod_before_publication(): void
    {
        $post = $this->createArticle('prepared-before-publication');
        $this->createTranslation($post, 'fa', '2026-07-30 08:00:00');
        $this->createTranslation($post, 'en', '2026-08-01 12:00:00');

        $this->assertSame([
            '/blog/prepared-before-publication' => '2026-08-02',
            '/en/blog/prepared-before-publication' => '2026-08-02',
        ], $this->blogDates());
    }

    public function test_missing_translation_and_drafts_stay_absent_and_post_metadata_does_not_refresh_content_dates(): void
    {
        $post = $this->createArticle('persian-only');
        $this->createTranslation($post, 'fa', '2026-08-04 08:00:00');
        $draft = $this->createArticle('draft-article', 'draft');
        $this->createTranslation($draft, 'fa', '2026-10-25 08:00:00');
        $this->createTranslation($draft, 'en', '2026-10-25 08:00:00');

        DB::table('posts')->where('id', $post->id)->update(['updated_at' => '2026-10-25 12:00:00', 'reading' => 12]);

        $this->assertSame(['/blog/persian-only' => '2026-08-04'], $this->blogDates());
    }

    public function test_creation_date_remains_the_fallback_when_publication_and_translation_edit_dates_are_missing(): void
    {
        $post = $this->createArticle('legacy-without-published-at');
        $this->createTranslation($post, 'fa', '2026-07-29 08:00:00');
        DB::table('posts')->where('id', $post->id)->update(['published_at' => null]);
        DB::table('post_translations')->where('post_id', $post->id)->update(['updated_at' => null]);

        $this->assertSame(['/blog/legacy-without-published-at' => '2026-07-31'], $this->blogDates());
    }

    private function createArticle(string $slug, string $status = 'published'): Post
    {
        $post = new Post(['slug' => $slug, 'type' => 'blog', 'category' => 'hosting', 'status' => $status, 'published_at' => '2026-08-02 08:00:00']);
        $post->created_at = '2026-07-31 08:00:00';
        $post->updated_at = '2026-10-20 12:00:00';
        $post->save();

        return $post;
    }

    private function createTranslation(Post $post, string $locale, string $updated): void
    {
        $translation = new PostTranslation(['post_id' => $post->id, 'locale' => $locale, 'title' => $locale.' '.$post->slug, 'excerpt' => 'Isolated fixture', 'content' => '<p>Original article body.</p>']);
        $translation->created_at = '2026-07-29 08:00:00';
        $translation->updated_at = $updated;
        $translation->save();
    }

    /** @return array<string,string> */
    private function blogDates(): array
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $document = new \DOMDocument;
        $this->assertTrue($document->loadXML($response->getContent()));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $dates = [];
        foreach ($xpath->query('//sm:url') as $url) {
            $path = parse_url($xpath->query('sm:loc', $url)->item(0)->textContent, PHP_URL_PATH);
            if (! preg_match('~^/(?:en/|tr/)?blog/[^/]+$~', $path)) {
                continue;
            }
            $lastmod = $xpath->query('sm:lastmod', $url);
            $this->assertSame(1, $lastmod->length);
            $dates[$path] = $lastmod->item(0)->textContent;
        }

        return $dates;
    }
}
