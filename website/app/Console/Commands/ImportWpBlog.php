<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * ایمپورت بلاگ وردپرس (فایل خروجی WXR) به پست‌های JSON بلاگ سرورنت.
 * اسلاگ‌ها حفظ می‌شوند تا 301 از servernet.ir/blog/{slug} تمیز باشد.
 *
 *   php artisan blog:import-wp storage/app/servernet.WordPress.xml
 */
class ImportWpBlog extends Command
{
    protected $signature = 'blog:import-wp {file : مسیر فایل خروجی WordPress (WXR .xml)} {--dry : فقط گزارش، بدون نوشتن}';

    protected $description = 'ایمپورت پست‌های بلاگ وردپرس با حفظ اسلاگ برای مهاجرت و 301';

    /** نگاشت دسته‌های وردپرس به دسته‌های بلاگ سرورنت */
    private array $catMap = [
        'آموزش' => 'tutorial', 'تکنولوژی' => 'tech', 'فناوری' => 'tech',
        'بازاریابی' => 'business', 'کسب و کار' => 'business', 'کسب‌وکار' => 'business',
        'سئو' => 'seo', 'سرور' => 'hosting', 'هاست' => 'hosting', 'میزبانی' => 'hosting',
        'امنیت' => 'security', 'ابر' => 'cloud', 'شبکه' => 'hosting',
    ];

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! File::exists($file)) {
            $this->error("فایل پیدا نشد: {$file}");

            return self::FAILURE;
        }

        $xml = simplexml_load_string(File::get($file), 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            $this->error('خواندن XML ناموفق بود.');

            return self::FAILURE;
        }
        $ns = $xml->getNamespaces(true);
        $dir = resource_path('blog/posts');
        File::ensureDirectoryExists($dir);

        $cover = ['a', 'b', 'c', 'd', 'e', 'f'];
        $i = 0;
        $count = 0;

        foreach ($xml->channel->item as $item) {
            $wp = $item->children($ns['wp'] ?? 'http://wordpress.org/export/1.2/');
            if ((string) $wp->post_type !== 'post' || (string) $wp->status !== 'publish') {
                continue;
            }

            $slug = trim((string) $wp->post_name);
            $slug = $slug !== '' ? urldecode($slug) : \Illuminate\Support\Str::slug((string) $item->title);
            // فقط اسلاگ‌های امن ASCII برای URL و 301
            if (! preg_match('~^[a-z0-9\-]+$~', $slug)) {
                $slug = 'post-'.((string) $wp->post_id);
            }

            $content = (string) $item->children($ns['content'] ?? 'http://purl.org/rss/1.0/modules/content/')->encoded;
            $excerptRaw = trim(strip_tags((string) $item->children($ns['excerpt'] ?? 'http://wordpress.org/export/1.2/excerpt/')->encoded));
            $excerpt = $excerptRaw !== '' ? $excerptRaw : mb_substr(trim(preg_replace('~\s+~u', ' ', strip_tags($content))), 0, 180);

            $category = 'tutorial';
            $tags = [];
            foreach ($item->category as $c) {
                $domain = (string) $c['domain'];
                $name = trim((string) $c);
                if ($domain === 'category') {
                    $category = $this->catMap[$name] ?? $category;
                } elseif ($domain === 'post_tag' && $name !== '') {
                    $tags[] = $name;
                }
            }

            $post = [
                'slug'     => $slug,
                'title'    => trim((string) $item->title),
                'date'     => date('Y-m-d', strtotime((string) $wp->post_date ?: (string) $item->pubDate)),
                'category' => $category,
                'tags'     => array_values(array_unique(array_slice($tags, 0, 6))),
                'excerpt'  => $excerpt,
                'cover'    => $cover[$i % 6],
                'icon'     => config('blog.categories.'.$category.'.icon', 'book'),
                'author'   => 'تیم سرورنت',
                'content'  => $this->cleanContent($content),
            ];
            $i++;

            if ($this->option('dry')) {
                $this->line("• {$slug}  —  {$post['title']}  [{$category}]");
            } else {
                File::put($dir.'/'.$slug.'.json', json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
            $count++;
        }

        $this->info(($this->option('dry') ? 'یافت شد: ' : 'وارد شد: ').$count.' پست');
        $this->comment('کش بلاگ را پاک کنید: php artisan cache:clear');

        return self::SUCCESS;
    }

    /** پاکسازی محتوای وردپرس: حذف شورت‌کد و اسکریپت/آی‌فریم خارجی */
    private function cleanContent(string $html): string
    {
        $html = preg_replace('~\[[^\]]+\]~', '', $html);                  // شورت‌کدها
        $html = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html);
        $html = preg_replace('~<iframe\b[^>]*>.*?</iframe>~is', '', $html);
        // پاراگراف‌بندی ساده اگر متن خام بود
        if (! str_contains($html, '<p') && ! str_contains($html, '<h')) {
            $html = '<p>'.implode('</p><p>', array_filter(array_map('trim', preg_split('~\n{2,}~', $html)))).'</p>';
        }

        return trim($html);
    }
}
