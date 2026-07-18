<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\BlogRepository;
use App\Services\DocsRepository;
use Illuminate\Console\Command;

/**
 * انتشار پیش‌نویس‌هایی که زمان انتشارشان رسیده است.
 * با کرانِ ساعتی اجرا می‌شود تا مقاله‌ها به‌صورت طبیعی و پراکنده منتشر شوند.
 */
class PublishDue extends Command
{
    protected $signature = 'content:publish-due {--limit=10 : حداکثر انتشار در هر اجرا}';

    protected $description = 'انتشار مقاله‌های پیش‌نویسی که زمانشان فرا رسیده';

    public function handle(): int
    {
        $due = Post::query()
            ->where('status', 'draft')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('published_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($due->isEmpty()) {
            $this->line('چیزی برای انتشار نیست.');

            return self::SUCCESS;
        }

        foreach ($due as $p) {
            $p->update(['status' => 'published']);
            $this->line('✓ منتشر شد: '.$p->slug.'  ('.$p->published_at->format('Y-m-d H:i').')');
        }

        BlogRepository::flush();
        DocsRepository::flush();
        $this->info('منتشر شد: '.$due->count().' مقاله');

        return self::SUCCESS;
    }
}
