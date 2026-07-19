<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\Cache;

/**
 * مخزن بلاگ — از دیتابیس (posts + post_translations) می‌خواند.
 * خروجی به‌صورت آرایه است تا قالب‌های موجود بدون تغییر کار کنند.
 * type=blog فقط برای بلاگ؛ پایگاه دانش با type=kb مدیریت می‌شود.
 */
class BlogRepository
{
    private string $type = 'blog';

    /** فهرست پست‌های منتشرشده (فقط متادیتا) برای زبان جاری */
    public function index(): array
    {
        $locale = app()->getLocale();

        try {
            return Cache::remember("blog.index.{$this->type}.{$locale}", 600, function () {
                return Post::query()
                    ->where('type', $this->type)->where('status', 'published')
                    ->with('translations')
                    ->orderByDesc('published_at')->orderByDesc('id')
                    ->get()
                    ->map(fn (Post $p) => $this->toArray($p, false))
                    ->all();
            });
        } catch (\Throwable $e) {
            // جدول هنوز مهاجرت نشده یا اختلال موقت دیتابیس — به‌جای 500 خالیِ سالم (شکست کش نمی‌شود)
            return [];
        }
    }

    /** یک پست کامل با محتوا */
    public function find(string $slug): ?array
    {
        $slug = preg_replace('~[^a-zA-Z0-9\-_]~', '', $slug);
        if ($slug === '') {
            return null;
        }
        try {
            // فیلتر type ضروری است: بدون آن مقاله‌های پایگاه دانش زیر /blog/ هم
            // سرو می‌شدند و گوگل آن را محتوای تکراری می‌دید.
            $post = Post::query()->where('slug', $slug)->where('type', $this->type)
                ->where('status', 'published')->with('translations')->first();
        } catch (\Throwable $e) {
            return null; // جدول هنوز مهاجرت نشده — 404 به‌جای 500
        }

        return $post ? $this->toArray($post, true) : null;
    }

    private function toArray(Post $p, bool $withContent): array
    {
        $t = $p->tr();
        $out = [
            'slug'     => $p->slug,
            'title'    => $t?->title ?? $p->slug,
            'date'     => optional($p->published_at ?? $p->created_at)->toDateString(),
            'category' => $p->category,
            'tags'     => $t?->tags ?? [],
            'excerpt'  => $t?->excerpt ?? '',
            'cover'    => $p->cover,
            'image'    => $p->image,
            'icon'     => $p->icon,
            'reading'  => $p->reading ?: 5,
            // نام پیش‌فرض باید ترجمه‌شده باشد، وگرنه «تیم سرورنت» در نسخه‌ی en/tr هم ظاهر می‌شود
            'author'   => optional($p->author)->name ?? __('ui.bl_reply_by'),
        ];
        if ($withContent) {
            $out['content'] = $t?->content ?? '';
        }

        return $out;
    }

    public function paginate(array $posts, int $page, int $perPage = 9): array
    {
        $total = count($posts);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        return ['items' => array_slice($posts, ($page - 1) * $perPage, $perPage), 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function byCategory(string $cat): array
    {
        return array_values(array_filter($this->index(), fn ($p) => ($p['category'] ?? '') === $cat));
    }

    public function byTag(string $tag): array
    {
        return array_values(array_filter($this->index(), fn ($p) => in_array($tag, $p['tags'] ?? [], true)));
    }

    public function search(string $q): array
    {
        $q = trim(mb_strtolower($q));
        if ($q === '') {
            return [];
        }

        return array_values(array_filter($this->index(), function ($p) use ($q) {
            $hay = mb_strtolower(($p['title'] ?? '').' '.($p['excerpt'] ?? '').' '.implode(' ', $p['tags'] ?? []));

            return str_contains($hay, $q);
        }));
    }

    public function related(array $post, int $n = 3): array
    {
        $same = array_values(array_filter($this->index(), fn ($p) => ($p['category'] ?? '') === ($post['category'] ?? '') && $p['slug'] !== $post['slug']));
        if (count($same) < $n) {
            foreach ($this->index() as $p) {
                if ($p['slug'] !== $post['slug'] && ! in_array($p, $same, true)) {
                    $same[] = $p;
                }
                if (count($same) >= $n) {
                    break;
                }
            }
        }

        return array_slice($same, 0, $n);
    }

    public function recent(int $n = 5): array
    {
        return array_slice($this->index(), 0, $n);
    }

    public function categoryCounts(): array
    {
        $counts = [];
        foreach ($this->index() as $p) {
            $c = $p['category'] ?? 'other';
            $counts[$c] = ($counts[$c] ?? 0) + 1;
        }

        return $counts;
    }

    public function popularTags(int $n = 16): array
    {
        $counts = [];
        foreach ($this->index() as $p) {
            foreach ($p['tags'] ?? [] as $t) {
                $counts[$t] = ($counts[$t] ?? 0) + 1;
            }
        }
        arsort($counts);

        return array_slice(array_keys($counts), 0, $n);
    }

    /** پاک‌سازی کش فهرست (پس از ساخت/ویرایش پست) */
    public static function flush(): void
    {
        foreach (['blog', 'kb'] as $type) {
            foreach (['fa', 'en', 'tr'] as $loc) {
                Cache::forget("blog.index.{$type}.{$loc}");
            }
        }
    }
}
