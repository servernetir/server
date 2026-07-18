<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostTranslation;
use App\Services\AiContent;
use App\Services\BlogRepository;
use App\Services\DocsRepository;
use Illuminate\Console\Command;

/**
 * تکمیل ترجمه‌های جامانده — اگر ترجمه‌ی en یا tr یک پست به هر دلیلی ساخته نشده باشد.
 */
class TranslateMissing extends Command
{
    protected $signature = 'content:translate-missing {--limit=3 : چند پست در این اجرا}';

    protected $description = 'ساخت ترجمه‌های en/tr که جا افتاده‌اند';

    public function handle(AiContent $ai): int
    {
        if (! $ai->enabled()) {
            $this->error('کلید هوش مصنوعی تنظیم نشده است.');

            return self::FAILURE;
        }

        $posts = Post::with('translations')->get()
            ->filter(function (Post $p) {
                $have = $p->translations->pluck('locale')->all();

                return in_array('fa', $have, true)
                    && (! in_array('en', $have, true) || ! in_array('tr', $have, true));
            })
            ->take(max(1, (int) $this->option('limit')));

        if ($posts->isEmpty()) {
            $this->info('همه‌ی پست‌ها هر سه زبان را دارند ✓');

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($posts as $p) {
            $fa = $p->translations->firstWhere('locale', 'fa');
            $have = $p->translations->pluck('locale')->all();
            $this->line('› '.$p->slug);

            foreach (['en', 'tr'] as $loc) {
                if (in_array($loc, $have, true)) {
                    continue;
                }
                $t = $ai->translate([
                    'title' => $fa->title, 'excerpt' => $fa->excerpt,
                    'content' => $fa->content, 'tags' => $fa->tags ?? [],
                ], $loc);

                if (! $t) {
                    $this->warn("  ✗ {$loc} ناموفق");

                    continue;
                }
                PostTranslation::updateOrCreate(
                    ['post_id' => $p->id, 'locale' => $loc],
                    ['title' => $t['title'], 'excerpt' => $t['excerpt'],
                        'content' => $t['content'], 'tags' => $t['tags'], 'auto' => true]
                );
                $this->line("  ✓ {$loc}");
                $done++;
            }
        }

        BlogRepository::flush();
        DocsRepository::flush();
        $this->info("ترجمه‌ی ساخته‌شده: {$done}");

        return self::SUCCESS;
    }
}
