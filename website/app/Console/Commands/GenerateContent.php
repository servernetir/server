<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostTranslation;
use App\Services\AiContent;
use App\Services\BlogRepository;
use App\Services\ContentCalendar;
use App\Services\DocsRepository;
use App\Services\InternalLinks;
use Illuminate\Console\Command;

/**
 * تولید مقاله از روی برنامه‌ی محتوا (resources/content/plan.php).
 * هر مقاله: نگارش فارسی با AI → ترجمه به en/tr → ذخیره به‌صورت پیش‌نویسِ زمان‌بندی‌شده.
 * انتشار واقعی را دستور content:publish-due انجام می‌دهد.
 *
 * زمان‌بندی از `ContentCalendar` می‌آید: سهمیهٔ ۲ تا ۵ مطلب در روز که بینِ
 * **همهٔ** نوع‌ها مشترک است، تا پنجرهٔ انتشار در طولِ سال یکنواخت پر شود.
 */
class GenerateContent extends Command
{
    protected $signature = 'content:generate
                            {--limit=3    : چند مقاله در این اجرا}
                            {--days=2     : بازه‌ی زمان‌بندی انتشار — فقط با --spread}
                            {--plan=plan  : نام فایل برنامه در resources/content}
                            {--daily      : منسوخ — همان رفتارِ تقویم (نگه داشته شده برای سازگاری)}
                            {--spread     : زمان‌بندیِ قدیمیِ تصادفی به‌جای تقویم}
                            {--slug=      : تولید یک عنوان مشخص}
                            {--dry        : فقط نشان بده چه چیزی تولید می‌شود}';

    protected $description = 'تولید مقاله‌های برنامه‌ریزی‌شده با هوش مصنوعی در سه زبان';

    private ContentCalendar $calendar;

    public function handle(AiContent $ai, InternalLinks $links): int
    {
        $this->calendar = new ContentCalendar;

        $plan = $this->plan();
        if (! $plan) {
            $this->error('resources/content/plan.php خالی یا موجود نیست.');

            return self::FAILURE;
        }

        // عنوان‌هایی که هنوز ساخته نشده‌اند
        $done = Post::pluck('slug')->all();
        $pending = array_values(array_filter($plan, fn ($p) => ! in_array($p['slug'], $done, true)));

        if ($slug = $this->option('slug')) {
            $pending = array_values(array_filter($pending, fn ($p) => $p['slug'] === $slug));
        }

        if (! $pending) {
            $this->info('همه‌ی مقاله‌های برنامه ساخته شده‌اند ✓');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $batch = array_slice($pending, 0, $limit);

        $capacity = $this->calendar->remainingCapacity();
        $this->line('باقی‌مانده در برنامه: '.count($pending)
            .' — در این اجرا: '.count($batch)
            .' — ظرفیتِ آزادِ تقویم تا پایان سال: '.$capacity);

        if ($this->option('dry')) {
            foreach ($batch as $p) {
                $this->line('  • '.$p['slug'].'  ['.$p['category'].']  '.$p['fa']);
            }

            return self::SUCCESS;
        }

        // تقویم پر است — تولیدِ بیشتر یعنی مقاله‌ای که هیچ روزی برای انتشار ندارد
        if (! $this->option('spread') && $capacity < 1) {
            $this->warn('تقویمِ انتشار تا پایانِ سال پر است — چیزی تولید نشد.');

            return self::SUCCESS;
        }

        if (! $ai->enabled()) {
            $this->error('کلید هوش مصنوعی تنظیم نشده است.');

            return self::FAILURE;
        }

        $ok = 0;
        foreach ($batch as $p) {
            $this->line('› '.$p['slug']);

            $type = $p['type'] ?? 'blog';

            // نوبتِ انتشار **پیش از** تماس با هوش مصنوعی گرفته می‌شود: اگر تقویم
            // پر باشد، نباید پولِ یک مقاله را خرج کنیم که جایی برای انتشار ندارد.
            $slot = $this->option('spread')
                ? $this->slot((int) $this->option('days'))
                : $this->calendar->nextSlot($p['date'] ?? null);

            if ($slot === null) {
                $this->warn('  ⏹ تقویم پر شد — بقیهٔ صف در اجرای بعدی.');
                break;
            }

            $fa = $ai->article([
                'title'    => $p['fa'],
                'keyword'  => $p['keyword'] ?? $p['fa'],
                'category' => $p['category'],
                'brief'    => $p['brief'] ?? '',
                'audience' => $p['audience'] ?? null,
                'links'    => $links->promptBlock($p['category'], $type, $p['slug']),
            ]);

            if (! $fa) {
                $this->warn('  ✗ نگارش فارسی ناموفق — رد شد');

                continue;
            }

            // هر لینکی که مدل ساخته و واقعاً حل نمی‌شود، همین‌جا باز می‌شود
            $fa['content'] = $links->sanitize($fa['content']);

            $post = Post::create([
                'slug'         => $p['slug'],
                'type'         => $type,
                'category'     => $p['category'],
                'status'       => 'draft',                  // انتشار با زمان‌بندی
                'cover'        => $p['cover'] ?? ['a', 'b', 'c', 'd'][array_rand(['a', 'b', 'c', 'd'])],
                'icon'         => $p['icon'] ?? 'book',
                'reading'      => $this->readingTime($fa['content']),
                'published_at' => $slot,
            ]);

            PostTranslation::create([
                'post_id' => $post->id, 'locale' => 'fa',
                'title' => $fa['title'], 'excerpt' => $fa['excerpt'],
                'content' => $fa['content'], 'tags' => $fa['tags'], 'auto' => true,
            ]);
            $this->line('  ✓ فارسی · لینکِ داخلی: '.$links->countInternal($fa['content']));

            foreach (['en', 'tr'] as $loc) {
                $t = $ai->translate($fa, $loc);
                if (! $t) {
                    $this->warn("  ✗ ترجمه‌ی {$loc} ناموفق — بعداً از پنل قابل تولید است");

                    continue;
                }
                // عنوان برنامه را ترجیح بده اگر تعریف شده (یکدستی دسته‌بندی و عنوان‌ها)
                PostTranslation::create([
                    'post_id' => $post->id, 'locale' => $loc,
                    'title' => $p[$loc] ?? $t['title'], 'excerpt' => $t['excerpt'],
                    // لینک‌های داخلی باید در همان زبان بمانند، وگرنه خوانندهٔ
                    // انگلیسی وسطِ متن به نسخهٔ فارسی پرتاب می‌شود
                    'content' => $links->localize($t['content'], $loc),
                    'tags' => $t['tags'], 'auto' => true,
                ]);
                $this->line("  ✓ {$loc}");
            }

            $ok++;
            $this->line('  ⏰ انتشار: '.$post->published_at->format('Y-m-d H:i'));
        }

        BlogRepository::flush();
        DocsRepository::flush();
        $this->info("ساخته شد: {$ok} مقاله (پیش‌نویسِ زمان‌بندی‌شده)");

        return self::SUCCESS;
    }

    /**
     * زمان‌بندیِ قدیمی: یک لحظهٔ تصادفی در N روزِ آینده. فقط با `--spread`.
     *
     * ⚠️ این حالت می‌تواند چند مقاله را روی یک روز بریزد و روزهای دیگر را خالی
     * بگذارد. برای پرکردنِ یک شکافِ مشخص مفید است، نه برای تولیدِ روزمره.
     */
    private function slot(int $days): \Illuminate\Support\Carbon
    {
        $days = max(1, $days);

        return now()
            ->addDays(random_int(0, $days))
            ->setTime(random_int(9, 20), random_int(0, 59));
    }

    private function readingTime(string $html): int
    {
        $text = trim(strip_tags($html));
        $words = max(preg_match_all('/\S+/u', $text), 1);

        return max(2, min(20, (int) ceil($words / 220)));
    }

    private function plan(): array
    {
        $name = preg_replace('~[^a-z0-9\-_]~i', '', (string) $this->option('plan')) ?: 'plan';
        $file = resource_path('content/'.$name.'.php');

        return is_file($file) ? (array) require $file : [];
    }
}
