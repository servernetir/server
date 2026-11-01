<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use App\Models\User;
use App\Services\BlogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BlogIndexAuthorLoadingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public static function locales(): array
    {
        return ['Persian' => ['fa'], 'English' => ['en'], 'Turkish' => ['tr']];
    }

    #[DataProvider('locales')]
    public function test_cold_and_warm_indexes_preserve_localized_metadata_with_one_author_query(string $locale): void
    {
        $first = User::factory()->create(['name' => 'First author']);
        $second = User::factory()->create(['name' => 'Second author']);
        $expected = [];
        foreach (['shared-a', 'shared-b', 'shared-c', 'shared-d'] as $slug) {
            $expected[] = $this->article($slug, $first->id, 'First author', $locale);
        }
        $expected[] = $this->article('other-author', $second->id, 'Second author', $locale);
        $expected[] = $this->article('unassigned', null, trans('ui.bl_reply_by', [], $locale), $locale);
        $expected[] = $this->article('missing-author', 9_000_001, trans('ui.bl_reply_by', [], $locale), $locale);
        $persianOnly = $this->article('persian-only', $first->id, 'First author', 'fa', ['fa']);
        if ($locale === 'fa') {
            $expected[] = $persianOnly;
        }
        $this->article('draft-not-listed', $first->id, 'First author', $locale, status: 'draft');
        $this->article('knowledge-not-listed', $first->id, 'First author', $locale, type: 'kb');
        $expected = array_reverse($expected);

        app()->setLocale($locale);
        Cache::flush();
        [$cold, $coldQueries] = $this->readIndex();
        [$warm, $warmQueries] = $this->readIndex();

        // Check the complete public metadata contract before the performance assertion.
        $this->assertSame($expected, $cold);
        $this->assertSame($expected, $warm);
        $this->assertSame([], $warmQueries, 'A warm metadata cache should need no SQL.');
        Http::assertNothingSent();
        $this->assertCount(1, $this->authorQueries($coldQueries),
            $locale.' cold index: '.count($cold).' preserved rows; '.count($coldQueries).' total SQL queries.');
        $this->assertCount(3, $coldQueries, 'A cold index needs only posts, translations and authors.');
    }

    #[DataProvider('locales')]
    public function test_author_queries_do_not_grow_with_the_number_of_posts(string $locale): void
    {
        $author = User::factory()->create(['name' => 'Shared author']);
        app()->setLocale($locale);
        $counts = [];
        $expected = [];

        foreach ([3, 15] as $size) {
            while (count($expected) < $size) {
                $expected[] = $this->article('sized-'.count($expected), $author->id, 'Shared author', $locale);
            }
            Cache::flush();
            [$rows, $queries] = $this->readIndex();
            $this->assertSame(array_reverse($expected), $rows);
            $counts[] = count($this->authorQueries($queries));
        }

        Http::assertNothingSent();
        $this->assertSame([1, 1], $counts, $locale.' author query counts for 3 and 15 published posts.');
    }

    private function article(
        string $slug,
        ?int $authorId,
        string $authorName,
        string $expectedLocale,
        array $locales = ['fa', 'en', 'tr'],
        string $status = 'published',
        string $type = 'blog',
    ): array {
        $post = Post::create([
            'slug' => $slug, 'type' => $type, 'category' => 'tutorial', 'status' => $status,
            'cover' => 'a', 'image' => null, 'icon' => 'book', 'reading' => 7,
            'author_id' => $authorId, 'published_at' => '2026-10-01 08:00:00',
        ]);
        foreach ($locales as $locale) {
            $translation = PostTranslation::create([
                'post_id' => $post->id, 'locale' => $locale, 'title' => $locale.' title '.$slug,
                'excerpt' => $locale.' excerpt '.$slug, 'content' => '<p>'.$locale.' body '.$slug.'</p>',
                'tags' => ['cluster', $locale],
            ]);
            $translation->forceFill(['updated_at' => '2026-10-09 08:00:00'])->saveQuietly();
        }

        return [
            'slug' => $slug, 'title' => $expectedLocale.' title '.$slug,
            'date' => '2026-10-01', 'updated' => '2026-10-09', 'category' => 'tutorial',
            'tags' => ['cluster', $expectedLocale], 'excerpt' => $expectedLocale.' excerpt '.$slug,
            'cover' => 'a', 'image' => null, 'icon' => 'book', 'reading' => 7,
            'author' => $authorName, 'untranslated' => false,
        ];
    }

    private function readIndex(): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $rows = app(BlogRepository::class)->index();

            return [$rows, DB::getQueryLog()];
        } finally {
            DB::disableQueryLog();
        }
    }

    private function authorQueries(array $queries): array
    {
        return array_values(array_filter($queries, static fn (array $query) =>
            (bool) preg_match('/\bfrom\s+["`]?users["`]?\b/i', $query['query'])));
    }
}
