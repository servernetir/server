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
            return Cache::remember("blog.index.{$this->type}.{$locale}", 600, function () use ($locale) {
                return Post::query()
                    ->where('type', $this->type)->where('status', 'published')
                    ->with('translations')
                    ->orderByDesc('published_at')->orderByDesc('id')
                    ->get()
                    /*
                    | روی en/tr فقط پستِ واقعاً ترجمه‌شده فهرست می‌شود. بی‌این،
                    | fallbackِ fa در tr() عنوانِ فارسی را به فهرستِ بلاگ، بلوکِ
                    | «راهنماها»ی ~۱۱۰ صفحهٔ محصول و «مطالبِ مرتبط» می‌برد
                    | (بررسی سراسری زبان، مرداد ۱۴۰۵). پست با رسیدنِ ترجمه‌اش
                    | (کرونِ translate-missing یا stepِ دستی) خودکار ظاهر می‌شود.
                    | find() عمداً فیلتر ندارد: URL مستقیم بهتر است fa بدهد تا ۴۰۴.
                    */
                    ->when($locale !== 'fa', fn ($posts) => $posts->filter(
                        fn (Post $p) => $p->translations->contains('locale', $locale)))
                    ->values()
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
            /*
            | تنزلِ h1 → h2 در بدنه — ممیزی ۷ (RG-H1-15): مولدِ هوشِ مصنوعی گاهی
            | تیترِ اولِ مقاله را <h1> می‌نویسد و صفحه دو H1 می‌گیرد (۷ پستِ زنده
            | در خزشِ ۳ شهریور). H1ِ صفحه مالِ قالب است؛ سرفصلِ بدنه از h2 شروع
            | می‌شود. این‌جا و نه در blade، چون هر مصرف‌کنندهٔ آینده هم امن بماند —
            | و نه در مولد، چون پست‌های موجودِ DB هم باید درمان شوند.
            */
            $out['content'] = preg_replace('~<(/?)h1\b~i', '<$1h2', $t?->content ?? '');
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

    /** برچسبِ مشترک از دستهٔ مشترک قوی‌تر است — نسبتش همین را می‌گوید. */
    private const TAG_WEIGHT = 3;

    private const CATEGORY_WEIGHT = 1;

    /**
     * 🔴 «مرتبط» یعنی هم‌موضوع، نه هم‌دسته.
     *
     * ═══ خرابیِ واقعی که این را لازم کرد ═══
     *
     * نسخهٔ قبلی هم‌دسته‌ها را **به ترتیبِ انتشار** برمی‌داشت و سه تای اول را
     * می‌داد. نتیجه‌اش روی سایتِ زنده سنجیده شد و دقیقاً همان چیزی بود که
     * انتظار می‌رفت — در هر دسته، **همهٔ** پست‌ها به همان سه تای ثابت لینک
     * می‌دادند:
     *
     *     voip           → virtualization · ipv6 · green-hosting
     *     virtualization → voip · ipv6 · green-hosting
     *     ipv6           → voip · virtualization · green-hosting
     *
     * یعنی از ۱۰۳ نوشته، فقط سه‌چهارتای هر دسته لینکِ داخلی می‌گرفتند و بقیه
     * از نظرِ لینک‌سازی یتیم بودند. نه خطایی، نه صفحهٔ خرابی — فقط ارزشی که
     * هیچ‌وقت پخش نمی‌شد.
     *
     * ⚠️ و برچسب‌ها که قوی‌ترین سیگنالِ ربط‌اند، **اصلاً خوانده نمی‌شدند** —
     * با اینکه هر نوشته حدود ده برچسب دارد و خودِ صفحه نمایششان می‌دهد.
     *
     * ═══ حالا ═══
     *
     * امتیاز = (برچسبِ مشترک × ۳) + (دستهٔ مشترک × ۱)، و در تساوی، تازه‌تر
     * جلوتر. کاملاً **قطعی** است: هیچ تصادفی در کار نیست، وگرنه صفحه در هر
     * بارگذاری فرق می‌کرد و کش و خزنده هر دو گیج می‌شدند.
     *
     * ⚠️ اگر هیچ نامزدی امتیاز نگرفت (نوشتهٔ بی‌برچسب در دسته‌ای تک‌نفره)،
     * تازه‌ترین‌ها پر می‌کنند — ویجتِ خالی از ویجتِ نه‌چندان‌مرتبط بدتر است.
     */
    public function related(array $post, int $n = 3): array
    {
        $mine = self::tagKeys($post['tags'] ?? []);
        $cat = $post['category'] ?? '';

        $scored = [];

        foreach ($this->index() as $i => $p) {
            if (($p['slug'] ?? '') === ($post['slug'] ?? '')) {
                continue;
            }

            $shared = count(array_intersect($mine, self::tagKeys($p['tags'] ?? [])));
            $score = $shared * self::TAG_WEIGHT
                + (($p['category'] ?? '') === $cat && $cat !== '' ? self::CATEGORY_WEIGHT : 0);

            if ($score > 0) {
                // ⚠️ `$i` ترتیبِ انتشار است و به‌عنوانِ شکنندهٔ تساوی می‌آید، نه
                //    به‌عنوانِ معیارِ اصلی؛ وگرنه همان خرابیِ قبلی برمی‌گردد.
                $scored[] = ['score' => $score, 'order' => $i, 'post' => $p];
            }
        }

        usort($scored, fn ($a, $b) => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        $out = array_map(fn ($r) => $r['post'], array_slice($scored, 0, $n));

        // پرکردنِ جای خالی با تازه‌ترین‌ها — فقط اگر امتیازی پیدا نشد
        if (count($out) < $n) {
            $have = array_column($out, 'slug');

            foreach ($this->index() as $p) {
                if (count($out) >= $n) {
                    break;
                }
                if (($p['slug'] ?? '') !== ($post['slug'] ?? '') && ! in_array($p['slug'] ?? '', $have, true)) {
                    $out[] = $p;
                }
            }
        }

        return $out;
    }

    /**
     * برچسب‌ها را برای **مقایسه** یکسان می‌کند.
     *
     * ⚠️ بی‌این، «زیرساخت شبکه» و «زیرساخت‌شبکه» (نیم‌فاصله) و «زيرساخت» (یِ
     * عربی) سه برچسبِ متفاوت شمرده می‌شوند و خوشه بی‌صدا تکه‌تکه می‌شود —
     * همان تلهٔ نیم‌فاصله و ی/ک که در CLAUDE.md برای جستجو ثبت شده.
     *
     * @param  array<int,string>  $tags
     * @return array<int,string>
     */
    private static function tagKeys(array $tags): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($t) => preg_replace('~\s+~u', ' ', trim(strtr(mb_strtolower((string) $t), [
                'ي' => 'ی', 'ك' => 'ک', "\u{200c}" => ' ',
            ]))),
            $tags))));
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
