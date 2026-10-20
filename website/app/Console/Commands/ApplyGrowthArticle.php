<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostTranslation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Apply one reviewed, versioned article without refreshing unrelated CMS posts. */
class ApplyGrowthArticle extends Command
{
    protected $signature = 'content:apply-growth {slug} {--apply : Write the reviewed article} {--expect= : Snapshot from a fresh preview; use absent for a new slug}';

    protected $description = 'Preview or apply one reviewed growth article with a CMS snapshot guard';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
            $this->error('Invalid article slug.');

            return self::FAILURE;
        }
        $file = resource_path('content/growth/'.$slug.'.json');
        if (! is_file($file)) {
            $this->error('No reviewed source exists for this slug.');

            return self::FAILURE;
        }
        $article = json_decode(file_get_contents($file), true);
        if (! is_array($article) || ($article['slug'] ?? '') !== $slug
            || array_keys($article['translations'] ?? []) !== ['fa', 'en', 'tr']) {
            $this->error('Source must match the slug and contain complete fa/en/tr translations.');

            return self::FAILURE;
        }
        foreach ($article['translations'] as $translation) {
            foreach (['title', 'excerpt', 'content'] as $field) {
                if (! is_string($translation[$field] ?? null) || trim($translation[$field]) === '') {
                    $this->error('A required translation field is empty.');

                    return self::FAILURE;
                }
            }
        }
        $current = Post::where('slug', $slug)->with('translations')->first();
        $snapshot = $this->snapshot($current);
        $this->line('Source SHA-256: '.hash_file('sha256', $file));
        $this->line('Current CMS snapshot: '.$snapshot);
        foreach ($article['translations'] as $locale => $translation) {
            $this->line($locale.': '.$translation['title']);
        }
        if (! $this->option('apply')) {
            $this->info('Preview only. Review the source and current CMS content before --apply --expect=<snapshot>.');

            return self::SUCCESS;
        }
        if (! is_string($this->option('expect')) || ! hash_equals($snapshot, $this->option('expect'))) {
            $this->error('Missing or stale --expect snapshot; nothing was written.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($slug, $article, $snapshot): void {
            $post = Post::where('slug', $slug)->lockForUpdate()->first();
            if ($post) {
                $post->setRelation('translations', $post->translations()->lockForUpdate()->get());
            }
            if (! hash_equals($snapshot, $this->snapshot($post))) {
                throw new \RuntimeException('CMS article changed after preview; apply aborted.');
            }
            if ($post && $post->type !== 'blog') {
                throw new \RuntimeException('The slug belongs to a different content type.');
            }
            if (! $post) {
                $post = Post::create([
                    'slug' => $slug, 'type' => 'blog', 'category' => $article['category'] ?? 'tutorial',
                    'status' => 'published', 'cover' => 'a', 'icon' => $article['icon'] ?? 'book',
                    'reading' => $article['reading'] ?? 8, 'source' => 'reviewed-growth-20261020',
                    'published_at' => now(),
                ]);
            }
            foreach ($article['translations'] as $locale => $translation) {
                PostTranslation::updateOrCreate(['post_id' => $post->id, 'locale' => $locale], [
                    'title' => $translation['title'], 'excerpt' => $translation['excerpt'],
                    'content' => $translation['content'], 'tags' => $translation['tags'] ?? [], 'auto' => false,
                ]);
            }
        });
        foreach (['fa', 'en', 'tr'] as $locale) {
            Cache::forget('blog.index.blog.'.$locale);
        }
        $this->info('Article saved. Existing publication status, author, publication date and unrelated posts preserved.');

        return self::SUCCESS;
    }

    private function snapshot(?Post $post): string
    {
        if (! $post) {
            return 'absent';
        }
        $translations = $post->translations->sortBy('locale')->map(fn ($t) => [
            $t->locale, $t->title, $t->excerpt, $t->content, $t->tags, $t->auto, (string) $t->updated_at,
        ])->values()->all();

        return hash('sha256', json_encode([
            $post->id, $post->type, $post->status, $post->category, $post->author_id,
            (string) $post->published_at, (string) $post->updated_at, $translations,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
