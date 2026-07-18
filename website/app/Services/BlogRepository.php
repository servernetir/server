<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * مخزن بلاگ flat-file — هر پست یک فایل JSON در resources/blog/posts.
 * فهرست (متادیتا) کش می‌شود؛ محتوای کامل فقط در صفحه‌ی تک‌پست خوانده می‌شود.
 * ساختار پست: slug,title,date,category,tags[],excerpt,cover,icon,reading,author,content
 */
class BlogRepository
{
    private string $dir;

    public function __construct()
    {
        $this->dir = resource_path('blog/posts');
    }

    /** فهرست همه‌ی پست‌ها (فقط متادیتا) مرتب‌شده بر اساس تاریخ نزول */
    public function index(): array
    {
        return Cache::remember('blog.index.v1', 600, function () {
            if (! File::isDirectory($this->dir)) {
                return [];
            }
            $posts = [];
            foreach (File::files($this->dir) as $file) {
                if ($file->getExtension() !== 'json') {
                    continue;
                }
                $p = json_decode(File::get($file->getPathname()), true);
                if (! is_array($p) || empty($p['slug'])) {
                    continue;
                }
                unset($p['content']); // فهرست سبک بماند
                $p['reading'] = $p['reading'] ?? 5;
                $posts[] = $p;
            }
            usort($posts, fn ($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

            return $posts;
        });
    }

    /** یک پست کامل با محتوا */
    public function find(string $slug): ?array
    {
        $slug = preg_replace('~[^a-zA-Z0-9\-_]~', '', $slug);
        $path = $this->dir.'/'.$slug.'.json';
        if ($slug === '' || ! File::exists($path)) {
            return null;
        }
        $p = json_decode(File::get($path), true);
        if (! is_array($p) || empty($p['slug'])) {
            return null;
        }
        $p['reading'] = $p['reading'] ?? $this->readingTime($p['content'] ?? '');

        return $p;
    }

    /** صفحه‌بندی فهرست */
    public function paginate(array $posts, int $page, int $perPage = 9): array
    {
        $total = count($posts);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        return [
            'items' => array_slice($posts, ($page - 1) * $perPage, $perPage),
            'page'  => $page,
            'pages' => $pages,
            'total' => $total,
        ];
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

    /** پست‌های مرتبط: هم‌دسته، به‌جز خودش */
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

    /** دسته‌ها با تعداد پست */
    public function categoryCounts(): array
    {
        $counts = [];
        foreach ($this->index() as $p) {
            $c = $p['category'] ?? 'other';
            $counts[$c] = ($counts[$c] ?? 0) + 1;
        }

        return $counts;
    }

    /** تگ‌های پرتکرار */
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

    private function readingTime(string $html): int
    {
        $words = str_word_count(strip_tags($html)) ?: mb_strlen(strip_tags($html)) / 5;

        return max(1, (int) ceil($words / 200));
    }
}
