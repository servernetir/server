<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostTranslation;
use App\Services\DocsRepository;
use Illuminate\Console\Command;

/**
 * ساخت مستندات پایه‌ی سرورنت (محتوای اصیل، سه‌زبانه).
 * idempotent: مقاله‌های موجود بر اساس slug دوباره ساخته نمی‌شوند.
 * منبع محتوا: resources/docs/seed.php
 */
class SeedDocs extends Command
{
    protected $signature = 'docs:seed {--force : بازنویسی مقاله‌های موجود}';

    protected $description = 'ساخت/به‌روزرسانی مستندات پایه در سه زبان';

    public function handle(): int
    {
        $file = resource_path('docs/seed.php');
        if (! is_file($file)) {
            $this->error('resources/docs/seed.php پیدا نشد.');

            return self::FAILURE;
        }

        $docs = require $file;
        $new = 0;
        $upd = 0;

        foreach ($docs as $d) {
            $existing = Post::where('slug', $d['slug'])->first();
            if ($existing && ! $this->option('force')) {
                continue;
            }

            $post = $existing ?: new Post;
            $post->fill([
                'slug'         => $d['slug'],
                'type'         => 'kb',
                'category'     => $d['section'],
                'status'       => 'published',
                'cover'        => $d['cover'] ?? 'a',
                'icon'         => $d['icon'] ?? 'book',
                'reading'      => $d['reading'] ?? 4,
                'published_at' => $post->published_at ?? now(),
            ]);
            $post->save();

            foreach (['fa', 'en', 'tr'] as $loc) {
                if (empty($d[$loc]['title'])) {
                    continue;
                }
                PostTranslation::updateOrCreate(
                    ['post_id' => $post->id, 'locale' => $loc],
                    [
                        'title'   => $d[$loc]['title'],
                        'excerpt' => $d[$loc]['excerpt'] ?? '',
                        'content' => $d[$loc]['content'] ?? '',
                        'tags'    => $d[$loc]['tags'] ?? [],
                        'auto'    => false,
                    ]
                );
            }

            $existing ? $upd++ : $new++;
            $this->line(($existing ? '↻ ' : '+ ').$d['slug']);
        }

        DocsRepository::flush();
        \App\Services\BlogRepository::flush();
        $this->info("مستندات: {$new} جدید، {$upd} به‌روزرسانی");

        return self::SUCCESS;
    }
}
