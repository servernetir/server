<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تعمیرِ لینکِ متنِ مقاله‌های موجود — `content:relink`.
 *
 * 🔴 فیکسچر عمداً همان آدرس‌های **واقعیِ** پروداکشن است که `links:content`
 * پیدا کرد، نه نمونهٔ ساختگی. هر سه هرگز روی این سایت وجود نداشته‌اند؛ مدل
 * در گذشته از خودش ساختشان و متن سال‌ها در دیتابیس ماند.
 */
class RelinkContentTest extends TestCase
{
    use RefreshDatabase;

    private function seedTranslation(string $slug, string $locale, string $content): PostTranslation
    {
        $p = Post::firstOrCreate(
            ['slug' => $slug],
            ['type' => 'blog', 'category' => 'seo', 'status' => 'published', 'published_at' => now()->subYear()]
        );

        return PostTranslation::create([
            'post_id' => $p->id, 'locale' => $locale, 'title' => 't', 'content' => $content,
        ]);
    }

    public function test_a_dead_internal_link_is_unwrapped_but_its_text_survives(): void
    {
        $t = $this->seedTranslation('fixing-duplicate-content', 'fa',
            '<p>مثلاً <a href="/product/laptop-asus">صفحهٔ محصول</a> را ببینید.</p>');

        $this->artisan('content:relink')->assertSuccessful();

        $after = $t->fresh()->content;

        $this->assertStringNotContainsString('/product/laptop-asus', $after);
        $this->assertStringContainsString('صفحهٔ محصول', $after, 'متنِ جمله نباید حذف شود، فقط لینک.');
    }

    /** آدرسِ فارسیِ کدگذاری‌شده هم همان‌قدر مرده است. */
    public function test_a_percent_encoded_dead_link_is_unwrapped_too(): void
    {
        $t = $this->seedTranslation('internal-linking-strategy', 'fa',
            '<p>در <a href="/%D8%B1%D8%A7%D9%87%D9%86%D9%85%D8%A7%DB%8C-%D8%AE%D8%B1%DB%8C%D8%AF">راهنما</a> هست.</p>');

        $this->artisan('content:relink')->assertSuccessful();

        $this->assertStringNotContainsString('<a ', $t->fresh()->content);
        $this->assertStringContainsString('راهنما', $t->fresh()->content);
    }

    public function test_a_live_internal_link_is_left_alone(): void
    {
        $t = $this->seedTranslation('good-post', 'fa',
            '<p>در <a href="/webtools">ابزارها</a> ببینید.</p>');

        $this->artisan('content:relink')->assertSuccessful();

        $this->assertStringContainsString('href="/webtools"', $t->fresh()->content);
    }

    /** همان محافظِ بین‌زبانی، این‌بار روی محتوای قدیمی. */
    public function test_a_translation_gets_its_links_localized(): void
    {
        $t = $this->seedTranslation('legacy-en', 'en',
            '<p>See <a href="/webtools">tools</a>.</p>');

        $this->artisan('content:relink')->assertSuccessful();

        $this->assertStringContainsString('href="/en/webtools"', $t->fresh()->content);
    }

    /** ⚠️ حالتِ آزمایشی باید **هیچ** عارضه‌ای نداشته باشد. */
    public function test_dry_run_changes_nothing(): void
    {
        $t = $this->seedTranslation('fixing-duplicate-content', 'fa',
            '<p><a href="/product/laptop-asus">x</a></p>');
        $before = $t->content;

        $this->artisan('content:relink', ['--dry' => true])->assertSuccessful();

        $this->assertSame($before, $t->fresh()->content);
    }

    /** اجرای دوباره نباید چیزی را دوباره عوض کند — وگرنه هر بار کش بی‌دلیل می‌پرد. */
    public function test_running_twice_is_a_no_op(): void
    {
        $t = $this->seedTranslation('legacy-en', 'en', '<p>See <a href="/webtools">tools</a>.</p>');

        $this->artisan('content:relink')->assertSuccessful();
        $once = $t->fresh()->content;

        $this->artisan('content:relink')
            ->expectsOutputToContain('هیچ ترجمه‌ای نیاز به تعمیر نداشت')
            ->assertSuccessful();

        $this->assertSame($once, $t->fresh()->content);
    }
}
