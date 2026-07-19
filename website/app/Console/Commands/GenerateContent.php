<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostTranslation;
use App\Services\AiContent;
use App\Services\BlogRepository;
use Illuminate\Console\Command;

/**
 * تولید مقاله از روی برنامه‌ی محتوا (resources/content/plan.php).
 * هر مقاله: نگارش فارسی با AI → ترجمه به en/tr → ذخیره به‌صورت پیش‌نویسِ زمان‌بندی‌شده.
 * انتشار واقعی را دستور content:publish-due انجام می‌دهد.
 */
class GenerateContent extends Command
{
    protected $signature = 'content:generate
                            {--limit=3    : چند مقاله در این اجرا}
                            {--days=2     : بازه‌ی زمان‌بندی انتشار (روز از امروز)}
                            {--plan=plan  : نام فایل برنامه در resources/content}
                            {--daily      : هر مقاله در یک روز جداگانه منتشر شود}
                            {--slug=      : تولید یک عنوان مشخص}
                            {--dry        : فقط نشان بده چه چیزی تولید می‌شود}';

    protected $description = 'تولید مقاله‌های برنامه‌ریزی‌شده با هوش مصنوعی در سه زبان';

    public function handle(AiContent $ai): int
    {
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
        $this->line('باقی‌مانده در برنامه: '.count($pending).' — در این اجرا: '.count($batch));

        if ($this->option('dry')) {
            foreach ($batch as $p) {
                $this->line('  • '.$p['slug'].'  ['.$p['category'].']  '.$p['fa']);
            }

            return self::SUCCESS;
        }

        if (! $ai->enabled()) {
            $this->error('کلید هوش مصنوعی تنظیم نشده است.');

            return self::FAILURE;
        }

        $ok = 0;
        foreach ($batch as $p) {
            $this->line('› '.$p['slug']);

            $fa = $ai->article([
                'title'    => $p['fa'],
                'keyword'  => $p['keyword'] ?? $p['fa'],
                'category' => $p['category'],
                'brief'    => $p['brief'] ?? '',
            ]);

            if (! $fa) {
                $this->warn('  ✗ نگارش فارسی ناموفق — رد شد');

                continue;
            }

            $type = $p['type'] ?? 'blog';
            $post = Post::create([
                'slug'         => $p['slug'],
                'type'         => $type,
                'category'     => $p['category'],
                'status'       => 'draft',                  // انتشار با زمان‌بندی
                'cover'        => $p['cover'] ?? ['a', 'b', 'c', 'd'][array_rand(['a', 'b', 'c', 'd'])],
                'icon'         => $p['icon'] ?? 'book',
                'reading'      => $this->readingTime($fa['content']),
                'published_at' => $this->option('daily')
                    ? $this->nextFreeDay($type)
                    : $this->slot((int) $this->option('days')),
            ]);

            PostTranslation::create([
                'post_id' => $post->id, 'locale' => 'fa',
                'title' => $fa['title'], 'excerpt' => $fa['excerpt'],
                'content' => $fa['content'], 'tags' => $fa['tags'], 'auto' => true,
            ]);
            $this->line('  ✓ فارسی');

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
                    'content' => $t['content'], 'tags' => $t['tags'], 'auto' => true,
                ]);
                $this->line("  ✓ {$loc}");
            }

            $ok++;
            $this->line('  ⏰ انتشار: '.$post->published_at->format('Y-m-d H:i'));
        }

        BlogRepository::flush();
        $this->info("ساخته شد: {$ok} مقاله (پیش‌نویسِ زمان‌بندی‌شده)");

        return self::SUCCESS;
    }

    /** یک زمان تصادفی در ساعات کاری طی N روز آینده */
    private function slot(int $days): \Illuminate\Support\Carbon
    {
        $days = max(1, $days);

        return now()
            ->addDays(random_int(0, $days))
            ->setTime(random_int(9, 20), random_int(0, 59));
    }

    /**
     * روز آزاد بعدی برای این نوع محتوا — یعنی روزی که هنوز هیچ مطلبی برایش
     * زمان‌بندی نشده. نتیجه: دقیقاً یک انتشار در هر روز.
     */
    private function nextFreeDay(string $type): \Illuminate\Support\Carbon
    {
        $taken = Post::where('type', $type)
            ->whereNotNull('published_at')
            ->pluck('published_at')
            ->map(fn ($d) => $d->toDateString())
            ->flip();

        $day = now()->addDay()->startOfDay();
        while ($taken->has($day->toDateString())) {
            $day->addDay();
        }

        return $day->setTime(random_int(9, 18), random_int(0, 59));
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
