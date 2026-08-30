<?php

namespace Tests\Feature;

use App\Models\ServerPart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * هر صفحهٔ دسته باید متنِ **خودش** را داشته باشد.
 *
 * ═══ نقصی که این را لازم کرد ═══
 *
 * 🔴 نسخهٔ اولِ فروشگاه، هر ۹ دسته را با یک پاراگرافِ معرفیِ یکسان و یک توضیحِ
 * متای تقریباً یکسان منتشر می‌کرد — ۹ دسته × ۳ زبان = **۲۷ صفحه با متنِ
 * یکسان**. گوگل این را محتوای تکراری می‌بیند و در بهترین حالت یکی را نگه
 * می‌دارد و بقیه را کنار می‌گذارد؛ یعنی هشت دسته از نُه دسته عملاً از نتایج
 * حذف می‌شدند.
 *
 * ⚠️ هیچ‌چیز خطا نمی‌داد. هر ۲۷ صفحه ۲۰۰ می‌دادند و درست رندر می‌شدند. تنها
 * راهِ دیدنش، ادعایی است که **یکتایی** را بسنجد نه سلامت را.
 */
class PartsCategoryContentIsUniqueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /** @return list<string> */
    private function categories(): array
    {
        return array_keys(ServerPart::CATEGORIES);
    }

    /**
     * 🔴 هستهٔ تست: پاراگرافِ معرفی در هیچ دو دسته‌ای یکی نباشد.
     */
    public function test_no_two_categories_share_an_intro_paragraph(): void
    {
        foreach (['fa', 'en', 'tr'] as $locale) {
            $seen = [];

            foreach ($this->categories() as $category) {
                $intro = (string) config("parts_content.{$category}.intro.{$locale}", '');

                $this->assertNotSame('', $intro, "دستهٔ «{$category}» در زبان {$locale} معرفی ندارد");
                $this->assertArrayNotHasKey(
                    $intro,
                    $seen,
                    "دستهٔ «{$category}» و «".($seen[$intro] ?? '?')."» در زبان {$locale} معرفیِ یکسان دارند"
                );

                $seen[$intro] = $category;
            }
        }
    }

    /** توضیحِ متا هم باید یکتا باشد — همان استدلال، جای دیگر. */
    public function test_no_two_categories_share_a_meta_description(): void
    {
        foreach (['fa', 'en', 'tr'] as $locale) {
            $seen = [];

            foreach ($this->categories() as $category) {
                $meta = (string) config("parts_content.{$category}.meta.{$locale}", '');

                $this->assertNotSame('', $meta, "دستهٔ «{$category}» در زبان {$locale} توضیحِ متا ندارد");
                $this->assertArrayNotHasKey($meta, $seen, "توضیحِ متای «{$category}» تکراری است");

                $seen[$meta] = $category;
            }
        }
    }

    /**
     * ⚠️ ادعا روی **خروجیِ رندرشده**، نه فقط روی config.
     *
     * config می‌تواند درست باشد و قالب همچنان متنِ عمومی چاپ کند — دقیقاً
     * همان چیزی که یک بار اتفاق افتاد.
     */
    public function test_the_rendered_page_actually_shows_its_own_intro(): void
    {
        foreach ($this->categories() as $category) {
            $html = $this->get('/parts/'.$category)->assertOk()->getContent();

            $intro = (string) config("parts_content.{$category}.intro.fa");
            $this->assertStringContainsString(
                e($intro),
                $html,
                "صفحهٔ «{$category}» معرفیِ خودش را چاپ نمی‌کند"
            );

            $meta = (string) config("parts_content.{$category}.meta.fa");
            $this->assertStringContainsString(e($meta), $html, "توضیحِ متای «{$category}» در صفحه نیست");
        }
    }

    /**
     * 🔴 schema.org FAQ فقط وقتی منتشر شود که پرسش‌ها **روی صفحه** باشند.
     *
     * دادهٔ ساختاریافته‌ای که محتوایش در صفحه نیست، طبق قواعد گوگل تخلف است و
     * می‌تواند اعتبارِ کلِ دادهٔ ساختاریافتهٔ دامنه را ببرد — ریسکش از سودش
     * بیشتر است.
     */
    public function test_faq_schema_is_published_only_when_the_questions_are_on_the_page(): void
    {
        foreach ($this->categories() as $category) {
            $html = $this->get('/parts/'.$category)->assertOk()->getContent();
            $faq = (array) config("parts_content.{$category}.faq", []);

            if ($faq === []) {
                $this->assertStringNotContainsString('"FAQPage"', $html, "«{$category}» بی‌پرسش، schema FAQ دارد");

                continue;
            }

            $this->assertStringContainsString('"@type":"FAQPage"', $html);

            /*
            | 🔴 بلوک‌های JSON-LD **حذف** می‌شوند و بعد ادعا زده می‌شود.
            |
            | نسخهٔ اولِ این تست همین کار را نمی‌کرد و در آزمونِ جهش زنده ماند:
            | متنِ پرسش هم در schema هست هم در بدنه، پس وقتی بدنه را حذف
            | کردیم تست همچنان متن را — داخلِ خودِ schema — پیدا کرد و سبز
            | ماند. یعنی دقیقاً همان چیزی را که قرار بود بگیرد نمی‌گرفت.
            */
            $visible = preg_replace('~<script[^>]*application/ld\+json[^>]*>.*?</script>~s', '', $html);

            foreach ($faq as $row) {
                $this->assertStringContainsString(
                    e($row['q']['fa']),
                    $visible,
                    "پرسشِ «{$row['q']['fa']}» در schema هست ولی در متنِ دیدنیِ صفحه نیست"
                );
                $this->assertStringContainsString(e($row['a']['fa']), $visible);
            }
        }
    }

    /** راهنمای خرید باید واقعاً رندر شود، نه فقط در config بماند. */
    public function test_the_buying_guide_is_rendered(): void
    {
        foreach ($this->categories() as $category) {
            $guide = (array) config("parts_content.{$category}.guide", []);
            $this->assertNotEmpty($guide, "دستهٔ «{$category}» راهنمای خرید ندارد");

            $html = $this->get('/parts/'.$category)->getContent();

            foreach ($guide as $section) {
                $this->assertStringContainsString(e($section['h']['fa']), $html);
                $this->assertStringContainsString(e($section['p']['fa']), $html);
            }
        }
    }

    /**
     * ⚠️ متنِ انگلیسی و ترکی باید واقعاً ترجمه باشد، نه کپیِ فارسی.
     *
     * برگشتِ خودکار به فارسی صفحه را «سالم» نشان می‌دهد و همان است که این نوع
     * جاافتادگی را نامرئی می‌کند.
     */
    public function test_every_locale_gets_its_own_language(): void
    {
        foreach ($this->categories() as $category) {
            $fa = (string) config("parts_content.{$category}.intro.fa");

            foreach (['en', 'tr'] as $locale) {
                $other = (string) config("parts_content.{$category}.intro.{$locale}");

                $this->assertNotSame($fa, $other, "«{$category}» در {$locale} همان متنِ فارسی را دارد");
                $this->assertDoesNotMatchRegularExpression(
                    '/[\x{0600}-\x{06FF}]/u',
                    $other,
                    "معرفیِ «{$category}» در {$locale} متنِ فارسی دارد"
                );
            }
        }
    }
}
