<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostTranslation;
use App\Services\BlogRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * انتقال پست‌های seed از فایل‌های JSON (resources/blog/posts) به دیتابیس.
 * فقط پست‌های جدید اضافه می‌شوند (بر اساس slug).
 */
class SeedBlogDb extends Command
{
    /*
    | --refresh: پستِ ازقبل‌seedشده را هم از JSON به‌روز می‌کند (عنوان/خلاصه/
    | محتوا/برچسب + دسته). فقط برای پست‌های JSON-محور — پست‌های تولیدیِ AI
    | فایلی ندارند و دست نمی‌خورند. ⚠️ ویرایشِ دستیِ ادمین روی همین ۲۷ پست را
    | رونویسی می‌کند؛ برای همین پیش‌فرض نیست و فقط با فلگ صریح اجرا می‌شود
    | (مصرفش: به‌روزرسانی مقالات مهاجرت servernet.ir — بازنویسی vpn-server و
    | بازمیزبانی تصاویر).
    */
    protected $signature = 'blog:seed-db {--refresh : به‌روزرسانی پست‌های موجود از JSON}';

    protected $description = 'انتقال پست‌های JSON بلاگ به دیتابیس (idempotent)';

    public function handle(): int
    {
        $dir = resource_path('blog/posts');
        if (! File::isDirectory($dir)) {
            $this->warn('پوشه‌ی پست‌ها موجود نیست.');

            return self::SUCCESS;
        }

        $n = 0;
        foreach (File::files($dir) as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            $d = json_decode(File::get($file->getPathname()), true);
            if (! is_array($d) || empty($d['slug'])) {
                continue;
            }
            $existing = Post::where('slug', $d['slug'])->first();
            if ($existing !== null) {
                if (! $this->option('refresh')) {
                    continue;
                }

                $existing->update(['category' => $d['category'] ?? $existing->category]);
                // ⚠️ روی نمونهٔ مدل، نه query builder — وگرنه castِ آرایه‌ایِ tags اعمال نمی‌شود
                $tr = PostTranslation::where('post_id', $existing->id)->where('locale', 'fa')->first();
                $tr?->update([
                    'title'   => $d['title'] ?? $d['slug'],
                    'excerpt' => $d['excerpt'] ?? '',
                    'content' => $d['content'] ?? '',
                    'tags'    => $d['tags'] ?? [],
                ]);
                $n++;
                $this->line('↻ '.$d['slug'].' (به‌روز شد)');

                continue;
            }
            $post = Post::create([
                'slug'         => $d['slug'],
                'type'         => 'blog',
                'category'     => $d['category'] ?? 'tutorial',
                'status'       => 'published',
                'cover'        => $d['cover'] ?? 'a',
                'icon'         => $d['icon'] ?? 'book',
                'reading'      => $d['reading'] ?? 5,
                'published_at' => ($d['date'] ?? null) ? $d['date'].' 09:00:00' : now(),
            ]);
            PostTranslation::create([
                'post_id' => $post->id,
                'locale'  => 'fa',
                'title'   => $d['title'] ?? $d['slug'],
                'excerpt' => $d['excerpt'] ?? '',
                'content' => $d['content'] ?? '',
                'tags'    => $d['tags'] ?? [],
                'auto'    => false,
            ]);
            $n++;
            $this->line('• '.$d['slug']);
        }

        BlogRepository::flush();
        $this->info("منتقل شد: {$n} پست");

        return self::SUCCESS;
    }
}
