<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحهٔ `/knowledge` نباید چیزی ادعا کند که وجود ندارد.
 *
 * ═══ چهار ادعای ساختگی که روی سایتِ زنده بود ═══
 *
 * ۱) **«۱۶۴ مقاله»** — شش دستهٔ مستندات با شمارشِ سخت‌کد (۴۲+۳۵+۲۸+۱۹+۲۴+۱۶)،
 *    در حالی که کلِ پایگاه دانش **۳۵** سند دارد. تقریباً پنج برابر، و با
 *    انتشارِ هر سندِ تازه فقط غلط‌تر می‌شد.
 * ۲) سه **وبینارِ** ساختگی با تاریخ و ساعتِ مشخص و دکمهٔ «ثبت‌نام»ی که به
 *    `href="#"` می‌رفت.
 * ۳) سه برنامهٔ **یادگیری** («دورهٔ ۱۲ جلسه‌ای»، «پادکستِ هر دو هفته»، «میتاپِ
 *    فصلی») بی‌هیچ لینکی.
 * ۴) شش **مقالهٔ جعلی** با تاریخِ جعلی که هر وقت بلاگ خالی برمی‌گشت رندر
 *    می‌شدند — روی پروداکشن دیده نمی‌شد، ولی یک قطعیِ دیتابیس کافی بود.
 *
 * ⚠️ هیچ‌کدام خطا تولید نمی‌کردند و صفحه همیشه ۲۰۰ بود. این دقیقاً همان صفحه‌ای
 * است که برای نشان‌دادنِ اعتبارِ فنیِ تیم ساخته شده — همان قاعدهٔ `/status` که
 * عمداً هیچ عددِ آپتایمِ ساختگی نمی‌سازد.
 */
class KnowledgePageTellsTheTruthTest extends TestCase
{
    use RefreshDatabase;

    private function makeDoc(string $slug, string $section): void
    {
        $p = Post::create([
            'slug' => $slug, 'type' => 'kb', 'status' => 'published',
            'category' => $section, 'published_at' => now()->subDay(),
        ]);

        PostTranslation::create([
            'post_id' => $p->id, 'locale' => 'fa', 'slug' => $slug,
            'title' => 'سندِ '.$slug, 'body' => 'متن', 'excerpt' => 'خلاصه',
        ]);
    }

    /**
     * 🔴 ادعای اصلی: شمارش باید با تعدادِ **واقعیِ** سندها بخواند.
     */
    public function test_the_document_count_matches_reality(): void
    {
        $this->makeDoc('a', 'hosting');
        $this->makeDoc('b', 'hosting');
        $this->makeDoc('c', 'domains');

        $html = $this->get('/knowledge')->assertOk()->getContent();

        preg_match_all('~<small>([۰-۹0-9]+)\s*'.preg_quote(__('ui.kb_articles'), '~').'</small>~u', $html, $m);

        $counts = array_map(
            fn ($n) => (int) strtr($n, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']),
            $m[1]);

        $this->assertSame(3, array_sum($counts),
            'جمعِ شمارشِ کارت‌ها با تعدادِ واقعیِ سندها نمی‌خوانَد');
        $this->assertSame([2, 1], $counts, 'شمارشِ هر دسته باید جدا و درست باشد');
    }

    /** بخشِ بی‌سند اصلاً کارت نمی‌گیرد — «۰ مقاله» هم یک ادعای بی‌فایده است. */
    public function test_an_empty_section_gets_no_card(): void
    {
        $this->makeDoc('only', 'hosting');

        $html = $this->get('/knowledge')->assertOk()->getContent();

        $this->assertStringNotContainsString(lc(config('docs.sections.billing'))['t'], $html,
            'بخشی که هیچ سندی ندارد نباید کارت داشته باشد');
    }

    /** کارت باید به سندِ واقعی برود، نه همه‌شان به یک آدرس. */
    public function test_each_card_links_into_its_own_section(): void
    {
        $this->makeDoc('doc-one', 'hosting');
        $this->makeDoc('doc-two', 'domains');

        $html = $this->get('/knowledge')->assertOk()->getContent();

        $this->assertStringContainsString('/docs/doc-one', $html);
        $this->assertStringContainsString('/docs/doc-two', $html);
    }

    /**
     * 🔴 هیچ‌کدام از چهار بخشِ ساختگی نباید برگردد.
     *
     * ⚠️ سنجش روی **متنِ رندرشده** است نه روی config: اگر روزی کسی آرایه را
     * دوباره پر کند، این تست همان لحظه قرمز می‌شود و مجبور است آگاهانه
     * تصمیم بگیرد.
     */
    public function test_no_fabricated_content_is_advertised(): void
    {
        $this->makeDoc('real', 'hosting');

        $html = $this->get('/knowledge')->assertOk()->getContent();

        foreach ([
            'Query Monitor'      => 'مقالهٔ ساختگی',
            'iRedMail'           => 'مقالهٔ ساختگی',
            'رادیو زیرساخت'      => 'پادکستِ ناموجود',
            'میتاپ DevOps تهران' => 'رویدادِ ناموجود',
        ] as $needle => $what) {
            $this->assertStringNotContainsString($needle, $html, "{$what} دوباره روی صفحه آمده");
        }
    }

    /**
     * 🔴 دکمهٔ مرده هم یک ادعاست.
     *
     * «ثبت‌نام» با `href="#"` یعنی بازدیدکننده کلیک می‌کند و هیچ اتفاقی
     * نمی‌افتد — بدتر از نبودنِ دکمه.
     */
    public function test_no_dead_call_to_action_survives(): void
    {
        $this->makeDoc('real', 'hosting');

        $html = $this->get('/knowledge')->assertOk()->getContent();

        /*
         * ⚠️ سنجش روی خودِ دکمهٔ «ثبت‌نام» است، نه روی `href="#"`ِ کلِ صفحه.
         * نسخهٔ اولِ همین تست دومی را می‌سنجید و قرمز شد — چون منوی سراسری
         * برای بازکردنِ کشوها از `href="#"` استفاده می‌کند و آن **درست** است.
         * تستی که رفتارِ سالم را قرمز کند، خودش دیر یا زود خاموش می‌شود.
         */
        $this->assertStringNotContainsString(__('ui.kb_register'), $html,
            'دکمهٔ ثبت‌نامِ وبینار برگشته — رویدادی که وجود ندارد');
    }

    /**
     * ⚠️ بلاگِ خالی باید **خالی** بماند، نه پر از مقالهٔ ساختگی.
     *
     * روی پروداکشن این حالت دیده نمی‌شد چون نوشتهٔ واقعی هست؛ ولی یک قطعیِ
     * دیتابیس یا نصبِ تازه کافی بود تا سایت شش مقالهٔ نداشته را تبلیغ کند.
     */
    public function test_an_empty_blog_advertises_nothing(): void
    {
        $html = $this->get('/knowledge')->assertOk()->getContent();

        $this->assertStringNotContainsString('Query Monitor', $html);
        $this->assertStringNotContainsString('کپچا', $html);
    }
}
