<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * canonical و robots و hreflangِ **حالت‌های فهرستِ بلاگ**.
 *
 * ═══ خرابی‌ای که این تست قفل می‌کند (Search Console، ۹ شهریور ۱۴۰۵) ═══
 *
 * لایوت canonical را از `url()->current()` می‌سازد و آن متد **رشتهٔ پرس‌وجو
 * را دور می‌ریزد**. پس هر پانزده صفحهٔ فهرست خودشان را `/blog` اعلام
 * می‌کردند. گوگل صفحهٔ صفحه‌بندی‌شده‌ای که خودش را صفحهٔ اول می‌خوانَد
 * «تکراری» می‌گیرد: از ایندکس بیرونش می‌گذارد و خیلی کمتر می‌خزدش — و
 * پست‌های ۱۰ به بعد **فقط** از همان صفحه‌ها لینک دارند. گزارشِ ایندکس:
 * ۶۵۴ نشانی «Discovered – currently not indexed» با «آخرین خزش: N/A».
 *
 * 🔴 هیچ تستی نمی‌گرفتش چون همه `/blog` را باز می‌کردند و آن‌جا رفتار
 * **درست** است: canonicalِ صفحهٔ اول واقعاً `/blog` است. خرابی فقط با
 * پارامتر دیده می‌شود. هر جا رفتارِ صفحه به رشتهٔ پرس‌وجو وابسته است،
 * تست هم باید با پرس‌وجو زده شود.
 */
class BlogListingCanonicalTest extends TestCase
{
    use RefreshDatabase;

    /** بیش از دو صفحه لازم است تا صفحه‌بندی واقعاً وجود داشته باشد (۹ در هر صفحه). */
    private const SEEDED = 25;

    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 1; $i <= self::SEEDED; $i++) {
            $post = Post::create([
                'slug'         => 'canon-guard-'.$i,
                'type'         => 'blog',
                'category'     => $i === 1 ? 'seo' : 'hosting',
                'status'       => 'published',
                'published_at' => now()->subDays(self::SEEDED - $i + 1),
            ]);

            foreach (['fa', 'en', 'tr'] as $locale) {
                PostTranslation::create([
                    'post_id' => $post->id,
                    'locale'  => $locale,
                    'title'   => 'نگهبانِ canonical '.$i.' ('.$locale.')',
                    'excerpt' => 'خلاصهٔ کوتاه.',
                    'content' => '<p>متنِ نمونه.</p>',
                    'tags'    => ['canon-tag'],
                ]);
            }
        }
    }

    private function canonicalOf(string $url): ?string
    {
        $html = $this->get($url)->assertOk()->getContent();

        return preg_match('~<link rel="canonical" href="([^"]+)"~', $html, $m) ? $m[1] : null;
    }

    public function test_the_first_page_canonicalises_to_the_bare_listing(): void
    {
        $this->assertSame(url('/blog'), $this->canonicalOf('/blog'));
    }

    /** 🔴 قلبِ ماجرا: صفحهٔ دو باید **خودش** را canonical کند، نه صفحهٔ اول. */
    public function test_a_paginated_page_canonicalises_to_itself(): void
    {
        $this->assertSame(url('/blog').'?page=2', $this->canonicalOf('/blog?page=2'));
    }

    public function test_a_paginated_page_is_still_indexable(): void
    {
        $this->get('/blog?page=2')->assertOk()->assertDontSee('name="robots"', false);
    }

    /**
     * ⚠️ عددِ خارج از بازه به آخرین صفحه **مهار** می‌شود و canonical هم باید
     * همان مهارشده را بگوید. با عددِ خام، `?page=999` و `?page=1000` و … هر
     * کدام خودشان را canonical می‌کردند: فضای خزشِ بی‌پایان از محتوای یکسان.
     */
    public function test_an_out_of_range_page_canonicalises_to_the_last_real_page(): void
    {
        $last = (int) ceil(self::SEEDED / (int) config('blog.per_page', 9));

        $this->assertSame(url('/blog').'?page='.$last, $this->canonicalOf('/blog?page=999'));
    }

    public function test_a_category_listing_canonicalises_to_itself(): void
    {
        $this->assertSame(url('/blog').'?cat=seo', $this->canonicalOf('/blog?cat=seo'));
    }

    /** دستهٔ ناشناخته کلِ فهرست را رندر می‌کند، پس canonicalش هم باید همان باشد. */
    public function test_an_unknown_category_falls_back_to_the_bare_listing(): void
    {
        $this->assertSame(url('/blog'), $this->canonicalOf('/blog?cat=not-a-real-category'));
    }

    /**
     * تگ و جست‌وجو `noindex,follow` می‌شوند و **هیچ** canonicalی نمی‌دهند.
     *
     * canonicalِ دروغ («این همان /blog است») از noindex بدتر است: مجموعهٔ
     * تگ‌ها بی‌کران است و به گوگل یاد می‌دهد canonicalهای این سایت را جدی
     * نگیرد — درست همان چیزی که کلِ این تعمیر برای بازسازی‌اش است.
     */
    public function test_tag_and_search_listings_are_noindex_without_a_canonical(): void
    {
        foreach (['/blog?tag=canon-tag', '/blog?q=canonical'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('<meta name="robots" content="noindex,follow">', $html, $url);
            $this->assertStringNotContainsString('rel="canonical"', $html, $url);
        }
    }

    /**
     * ⚠️ hreflang هم باید همان پارامترها را ببرد.
     *
     * `$localeUrls` فقط پارامترهای **روت** را می‌شناسد، پس بی‌تعمیر، صفحهٔ ۲
     * به گوگل می‌گفت معادلِ انگلیسی‌اش `/en/blog` (صفحهٔ **اول**) است — یعنی
     * سه صفحهٔ متفاوت خودشان را ترجمهٔ هم اعلام می‌کردند.
     */
    public function test_hreflang_carries_the_same_query_on_a_paginated_page(): void
    {
        $html = $this->get('/blog?page=2')->assertOk()->getContent();

        foreach (['/blog?page=2' => 'fa', '/en/blog?page=2' => 'en', '/tr/blog?page=2' => 'tr'] as $path => $lang) {
            $this->assertStringContainsString(
                '<link rel="alternate" hreflang="'.$lang.'" href="'.url($path).'">',
                $html,
                $lang
            );
        }
    }

    public function test_the_english_listing_canonicalises_to_the_english_url(): void
    {
        $this->assertSame(url('/en/blog').'?page=2', $this->canonicalOf('/en/blog?page=2'));
    }

    /**
     * ⚠️ صفحهٔ ۱ هرگز با `page=1` لینک نمی‌شود.
     *
     * `?page=1` همان محتوای `/blog` است با آدرسی دیگر — یک تکراریِ اضافه که
     * خزنده باید بخزد و کنار بگذارد، در سایتی که بودجهٔ خزشش همین حالا کم
     * است. (ادعا روی خودِ نشانه‌گذاری است، نه روی نیتِ کد.)
     */
    public function test_the_pager_never_links_to_page_one_with_a_parameter(): void
    {
        $html = $this->get('/blog?page=2')->assertOk()->getContent();

        $this->assertStringNotContainsString('page=1"', $html);
        $this->assertStringNotContainsString('page=1&', $html);
        $this->assertStringContainsString('href="'.url('/blog').'"', $html, 'صفحهٔ ۱ باید لینکِ بی‌پارامتر داشته باشد.');
    }

    /** دسته‌های پرمحتوا باید در نقشهٔ سایت باشند — حالا که صفحهٔ فرودِ واقعی‌اند. */
    public function test_the_sitemap_lists_the_category_listings(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>'.url('/blog').'?cat=seo</loc>', $xml);
        $this->assertStringContainsString('<loc>'.url('/en/blog').'?cat=hosting</loc>', $xml);
    }

    /** ⚠️ دستهٔ بی‌پست اعلام نمی‌شود — نشانیِ خالی در نقشه = دعوتِ خزنده به هیچ. */
    public function test_the_sitemap_skips_an_empty_category(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString('?cat=business', $xml);
    }

    /** صفحه‌های صفحه‌بندی عمداً در نقشهٔ سایت نیستند — از فهرست لینک دارند. */
    public function test_the_sitemap_does_not_list_paginated_pages(): void
    {
        $this->assertStringNotContainsString('?page=', $this->get('/sitemap.xml')->assertOk()->getContent());
    }
}
