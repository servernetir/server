<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\Cache;

/**
 * مستندات — مقاله‌ها از دیتابیس (type='kb') و بخش‌بندی از config/docs.php.
 * ساختار درختی سایدبار: بخش → مقاله‌ها (به ترتیب ساخت).
 */
class DocsRepository
{
    /** درخت کامل: [sectionKey => ['meta'=>…, 'items'=>[…]]] فقط بخش‌های دارای مقاله */
    public function tree(): array
    {
        $locale = app()->getLocale();

        try {
            return Cache::remember("docs.tree.{$locale}", 600, function () {
                $sections = config('docs.sections', []);
                $posts = Post::query()
                    ->where('type', 'kb')->where('status', 'published')
                    ->with('translations')->orderBy('id')->get();

                $tree = [];
                foreach ($sections as $key => $meta) {
                    $items = $posts->where('category', $key)
                        ->map(fn (Post $p) => [
                            'slug'  => $p->slug,
                            'title' => optional($p->tr())->title ?? $p->slug,
                        ])->values()->all();

                    if ($items) {
                        $tree[$key] = ['meta' => $meta, 'items' => $items];
                    }
                }

                return $tree;
            });
        } catch (\Throwable $e) {
            return []; // جدول هنوز مهاجرت نشده — به‌جای 500، خالی
        }
    }

    /** یک مقاله‌ی مستندات با محتوا */
    public function find(string $slug): ?array
    {
        $slug = preg_replace('~[^a-zA-Z0-9\-_]~', '', $slug);
        if ($slug === '') {
            return null;
        }

        try {
            $p = Post::query()->where('slug', $slug)->where('type', 'kb')
                ->where('status', 'published')->with('translations')->first();
        } catch (\Throwable $e) {
            return null;
        }
        if (! $p) {
            return null;
        }

        $t = $p->tr();
        $section = config('docs.sections.'.$p->category);

        return [
            'slug'     => $p->slug,
            'title'    => $t?->title ?? $p->slug,
            'excerpt'  => $t?->excerpt ?? '',
            // تنزل h1→h2 در بدنه — همان قاعدهٔ BlogRepository (ممیزی ۷، RG-H1-15):
            // H1 مالِ قالب است؛ مولدِ AI گاهی تیترِ بدنه را h1 می‌نویسد.
            'content'  => preg_replace('~<(/?)h1\b~i', '<$1h2', $t?->content ?? ''),
            'tags'     => $t?->tags ?? [],
            'category' => $p->category,
            'section'  => $section,
            'date'     => optional($p->published_at ?? $p->created_at)->toDateString(),
            'reading'  => $p->reading ?: 4,
        ];
    }

    /** مقاله‌ی قبلی و بعدی در همان بخش (ناوبری پایین صفحه) */
    public function neighbours(string $slug, string $section): array
    {
        $items = $this->tree()[$section]['items'] ?? [];
        $i = array_search($slug, array_column($items, 'slug'), true);
        if ($i === false) {
            return ['prev' => null, 'next' => null];
        }

        return [
            'prev' => $items[$i - 1] ?? null,
            'next' => $items[$i + 1] ?? null,
        ];
    }

    public static function flush(): void
    {
        foreach (['fa', 'en', 'tr'] as $l) {
            Cache::forget("docs.tree.{$l}");
        }
    }
}
