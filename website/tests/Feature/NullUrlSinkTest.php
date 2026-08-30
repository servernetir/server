<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 ۴۰۴های `/null` — یک سازوکارِ مشترک، شش نشانیِ متفاوت.
 *
 * ═══ چرا شبیهِ چند باگِ جدا به‌نظر می‌رسید ═══
 *
 * `href`/`src` عضوِ `[LegacyNullToEmptyString]` نیستند، پس یک مقدارِ خراب به
 * رشتهٔ چهارحرفیِ `"null"` تبدیل می‌شود و مرورگر آن را **نسبت به پوشهٔ سندِ
 * جاری** حل می‌کند:
 *
 *     /blog?tag=…        →  /null
 *     /cloud/<slug>      →  /cloud/null
 *     /servers/<slug>    →  /servers/null
 *     /en/blog/<slug>    →  /en/blog/null
 *
 * یعنی **یک** مقدارِ خراب، در هر صفحه یک ۴۰۴ِ متفاوت می‌سازد.
 *
 * ═══ چرا هیچ گیتی نمی‌گرفتش ═══
 *
 * مقدار نال **نیست**؛ رشتهٔ `"null"` است (از سریال‌سازیِ جاوااسکریپت، ایمپورت،
 * یا ستونی که یک بار با `String(null)` پر شده). و `!empty("null")` **درست**
 * است — پس از `@if(!empty($p['image']))` و `@empty` و هر گیتِ مشابهی بی‌صدا رد
 * می‌شود.
 *
 * ⚠️ این تست عمداً دادهٔ خراب را **می‌سازد**. تستی که با دادهٔ سالم رندر کند،
 * سبز می‌شود و هیچ‌چیز ثابت نمی‌کند — همان چیزی که تا امروز اتفاق افتاد.
 */
class NullUrlSinkTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════ ۱) خودِ صافی ═══════════════

    /** `img_url()` هر شکلِ «هیچ» را نال می‌کند، و آدرسِ واقعی را دست نمی‌زند */
    public function test_the_helper_rejects_every_shape_of_nothing(): void
    {
        foreach ([null, '', '   ', 'null', 'NULL', 'undefined', 'none', 'nil', 'false', 'NaN', 123, []] as $junk) {
            $this->assertNull(img_url($junk),
                'مقدارِ «'.json_encode($junk).'» باید نال شود، وگرنه نشانیِ نسبیِ خراب می‌سازد');
        }

        $this->assertSame('/assets/img/a.png', img_url(' /assets/img/a.png '));
        $this->assertSame('https://x/y.png', img_url('https://x/y.png'));
    }

    // ═══════════════ ۲) صفحاتی که در ردیاب Referer بودند ═══════════════

    private function postWithBrokenImage(string $slug, string $locale = 'fa'): Post
    {
        $post = Post::create([
            'type' => 'blog', 'slug' => $slug, 'status' => 'published',
            'published_at' => now()->subDay(), 'category' => 'hosting',
            'cover' => 'a', 'icon' => 'book',
            // 🔴 دقیقاً همان مقدارِ خرابی که از هر گیتِ `!empty()` رد می‌شود
            'image' => 'null',
        ]);

        foreach (['fa', 'en', 'tr'] as $l) {
            PostTranslation::create([
                'post_id' => $post->id, 'locale' => $l,
                'title' => 'عنوانِ آزمون '.$slug, 'excerpt' => 'چکیده',
                'content' => '<p>متن</p>', 'reading_minutes' => 3,
            ]);
        }

        return $post;
    }

    /**
     * @return array<int,string>  هر مقدارِ نشانیِ خرابی که در صفحه پیدا شد
     */
    private function brokenUrls(string $html): array
    {
        preg_match_all(
            '~\b(?:href|src|action|data-src|data-url-[ym])\s*=\s*"([^"]*)"~i',
            $html,
            $m
        );

        return array_values(array_unique(array_filter(
            $m[1],
            fn ($v) => in_array(strtolower(trim($v)), ['null', 'undefined', 'nan'], true)
        )));
    }

    /** 🔴 `/blog?tag=…` — مبدأِ ۴۰۴های `/null` */
    public function test_the_blog_index_never_emits_a_null_url_even_with_broken_image_data(): void
    {
        $this->postWithBrokenImage('null-guard-1');

        $html = $this->get('/blog')->assertOk()->getContent();

        $this->assertSame([], $this->brokenUrls($html),
            'صفحهٔ بلاگ نشانیِ «null» چاپ کرد — مرورگر نسبی حلش می‌کند و ۴۰۴ روی /null می‌سازد');
    }

    /** و نسخهٔ انگلیسی‌اش، که `/en/blog/null` را می‌ساخت */
    public function test_the_english_blog_post_page_never_emits_a_null_url(): void
    {
        $post = $this->postWithBrokenImage('null-guard-2');

        // ⚠️ نوشتهٔ دومی لازم است تا بخشِ «مطالبِ مرتبط» هم رندر شود؛ همان
        //    بخشی که `$p['image']` را مستقیم در `src` می‌گذاشت.
        $this->postWithBrokenImage('null-guard-3');

        $html = $this->get('/en/blog/'.$post->slug)->assertOk()->getContent();

        $this->assertSame([], $this->brokenUrls($html));
    }

    /** `/servers` و `/servers/<slug>` — مبدأِ `/servers/null` */
    public function test_the_server_pages_never_emit_a_null_url_from_a_broken_gallery(): void
    {
        // فروشگاهِ سرورِ فیزیکی دیتابیس‌محور است؛ بی‌سیدر، صفحه خالی رندر
        // می‌شود و این تست هیچ‌چیز نمی‌سنجد.
        $this->seed(\Database\Seeders\PhysicalServerSeeder::class);

        $srv = \App\Models\PhysicalServer::query()->first();

        $this->assertNotNull($srv, 'کاتالوگِ سرورِ فیزیکی خالی است — تست چیزی نمی‌سنجد');
        $srv->forceFill(['gallery' => ['null', '']])->save();

        foreach (['/servers', '/servers/'.$srv->slug, '/en/servers/'.$srv->slug] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertSame([], $this->brokenUrls($html),
                $url.' نشانیِ «null» چاپ کرد');
        }
    }

    // ═══════════════ ۳) گاردِ ساختاری ═══════════════

    /**
     * ⚠️ گاردِ آینده: هیچ ویویی نباید دوباره یک مقدارِ تصویر را با `!empty()`
     * گیت کند و خام در `src` بگذارد.
     *
     * 🔴 کامنت‌ها **اول پاک می‌شوند**. این پروژه سه بار از تستی که فایل را
     * grep می‌کند و نثرِ خودمان را می‌گیرد ضربه خورده.
     */
    public function test_no_view_gates_an_image_with_empty_and_then_prints_it_raw(): void
    {
        $bad = [];
        $root = resource_path('views');

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $src = (string) file_get_contents($file->getPathname());

            // کامنتِ Blade و کامنتِ PHP بیرون
            $src = preg_replace('~\{\{--.*?--\}\}~s', '', $src);
            $src = preg_replace('~/\*.*?\*/|(?<![:\'"])//[^\n]*~s', '', (string) $src);

            // `src="{{ $x['image'] }}"` یا `src="{{ $x['gallery'][0] }}"` — خام
            if (preg_match('~src="\{\{\s*\$[a-z_]+\[[\'"](?:image|gallery)[\'"]\]~i', (string) $src)) {
                $bad[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $bad,
            "\nاین ویوها مقدارِ خامِ تصویر را در `src` می‌گذارند. مقدارِ رشته‌ایِ «null»\n"
            ."از هر گیتِ `!empty()` رد می‌شود و نشانیِ نسبیِ «null» می‌سازد.\n"
            .'از `img_url()` استفاده کن:'."\n".implode("\n", $bad));
    }
}
