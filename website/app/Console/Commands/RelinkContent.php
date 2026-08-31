<?php

namespace App\Console\Commands;

use App\Models\PostTranslation;
use App\Services\BlogRepository;
use App\Services\DocsRepository;
use App\Services\InternalLinks;
use Illuminate\Console\Command;

/**
 * تعمیرِ لینک‌های داخلیِ متنِ مقاله‌های **موجود**.
 *
 * ═══ چرا لازم شد ═══
 *
 * `links:content` بالاخره اجرا شد (سال‌ها با «no such column: body» می‌ترکید)
 * و ۹ لینکِ شکسته در سه پستِ قدیمی پیدا کرد:
 *
 *     /product/laptop-asus
 *     /cookie-policy
 *     /راهنمای-خرید-لپ-تاپ
 *
 * هیچ‌کدام هرگز روی این سایت وجود نداشته‌اند — مدل در گذشته از خودش ساخته
 * بودشان. برای مقاله‌های **تازه** این دیگر ممکن نیست (`InternalLinks` فهرستِ
 * بسته می‌دهد و بعد هر لینک را واقعاً حل می‌کند)، ولی متنِ قدیمی در دیتابیس
 * است و هیچ دیپلویی به آن دست نمی‌زند.
 *
 * ═══ چه می‌کند ═══
 *
 * دقیقاً همان دو تابعی که خطِ تولید استفاده می‌کند، روی متنِ موجود:
 *   • `sanitize()` — لینکِ داخلیِ حل‌نشدنی را **باز** می‌کند (متن می‌مانَد،
 *     لینک می‌رود) و لینکِ بیرونی را `nofollow noopener` می‌کند.
 *   • `localize()` — لینکِ نسخهٔ en/tr را در همان زبان نگه می‌دارد.
 *
 * ⚠️ لینکِ مرده را **حذف** نمی‌کند، باز می‌کند: جملهٔ نویسنده دست‌نخورده
 * می‌مانَد و فقط مقصدِ ناموجود می‌رود. حذفِ متن یعنی جمله‌ای که معنایش را از
 * دست می‌دهد.
 *
 * ═══ چرا زمان‌بندی نشده ═══
 *
 * ⚠️ عمداً در `routes/console.php` نیست. این فرمان **محتوای منتشرشده را
 * بازنویسی می‌کند**، و کارِ خودکاری که هر هفته بی‌خبر متنِ سایت را عوض کند،
 * روزی چیزی را خراب می‌کند که کسی متوجهش نمی‌شود. گزارشِ هفتگی کارِ
 * `links:content` است؛ تصمیمِ تعمیر مالِ آدم است.
 *
 *     php artisan content:relink --dry     فقط بگو چه چیزی عوض می‌شود
 *     php artisan content:relink           انجامش بده
 */
class RelinkContent extends Command
{
    protected $signature = 'content:relink
                            {--dry   : فقط گزارش بده، چیزی ننویس}
                            {--slug= : فقط یک مقالهٔ مشخص}';

    protected $description = 'باز کردن لینک‌های داخلیِ شکسته و محلی‌کردن لینکِ ترجمه‌ها';

    public function handle(InternalLinks $links): int
    {
        $rows = PostTranslation::query()
            ->join('posts', 'posts.id', '=', 'post_translations.post_id')
            ->when($this->option('slug'), fn ($q, $s) => $q->where('posts.slug', $s))
            ->select([
                'post_translations.id',
                'post_translations.locale',
                'post_translations.content',
                'posts.slug',
            ])
            ->get();

        $this->line('بررسی: '.$rows->count().' ترجمه');

        $changed = 0;
        $unwrapped = 0;

        foreach ($rows as $row) {
            $before = (string) $row->content;

            if (trim($before) === '') {
                continue;
            }

            $after = $links->localize($links->sanitize($before), $row->locale);

            if ($after === $before) {
                continue;
            }

            // شمارشِ لینک‌هایی که باز شده‌اند — عددی که واقعاً معنی دارد
            $lost = substr_count($before, '<a ') - substr_count($after, '<a ');
            $unwrapped += max(0, $lost);
            $changed++;

            $this->line(sprintf('  %-4s %-38s %s',
                $row->locale,
                mb_substr($row->slug, 0, 38),
                $lost > 0 ? "{$lost} لینکِ شکسته باز شد" : 'لینک محلی شد'
            ));

            if (! $this->option('dry')) {
                PostTranslation::whereKey($row->id)->update(['content' => $after]);
            }
        }

        if ($changed === 0) {
            $this->info('✅ هیچ ترجمه‌ای نیاز به تعمیر نداشت.');

            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $this->warn("حالتِ آزمایشی — {$changed} ترجمه عوض می‌شد ({$unwrapped} لینکِ شکسته). "
                .'برای انجام، همین فرمان را بدونِ --dry بزن.');

            return self::SUCCESS;
        }

        BlogRepository::flush();
        DocsRepository::flush();

        // متنِ صفحه عوض شده — کشِ صفحه هم باید برود، وگرنه تا TTL نسخهٔ کهنه سرو می‌شود
        try {
            \App\Http\Middleware\PageCache::purge();
        } catch (\Throwable $e) {
            $this->warn('کشِ صفحه پاک نشد: '.mb_substr($e->getMessage(), 0, 80));
        }

        $this->info("تعمیر شد: {$changed} ترجمه · {$unwrapped} لینکِ شکسته باز شد");

        return self::SUCCESS;
    }
}
