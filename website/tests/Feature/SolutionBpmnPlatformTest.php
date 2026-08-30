<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * صفحهٔ /solutions/bpmn-designer از «ابزار رایگان رسم» به «دروازهٔ پلتفرم
 * BPMS سازمانی» ارتقا یافت — بی‌آنکه طراحِ رایگان از صفحه حذف شود.
 *
 * چهار بخشِ تازه (پلتفرم اجرایی / کاربرد صنعتی / امنیت و حاکمیت داده /
 * مقایسهٔ سه‌ستونه) در `solution.blade.php` **اختیاری** پیاده شده‌اند: تا وقتی
 * کلیدشان در config نباشد رندر نمی‌شوند. پس مهم‌ترین تستِ این فایل آن است که
 * نُه راهکارِ دیگر دست‌نخورده مانده باشند.
 */
class SolutionBpmnPlatformTest extends TestCase
{
    /** @return array<string,string> پیشوندِ زبان => نشانی */
    private const URLS = [
        'fa' => '/solutions/bpmn-designer',
        'en' => '/en/solutions/bpmn-designer',
        'tr' => '/tr/solutions/bpmn-designer',
    ];

    private function html(string $loc): string
    {
        return $this->get(self::URLS[$loc])->assertOk()->getContent();
    }

    public function test_all_three_languages_render_the_new_sections(): void
    {
        foreach (array_keys(self::URLS) as $loc) {
            $html = $this->html($loc);

            $this->assertStringContainsString('id="platform"', $html, "بخشِ پلتفرم در $loc نیامد");
            $this->assertStringContainsString('id="usecases"', $html, "بخشِ کاربردها در $loc نیامد");
            $this->assertStringContainsString('id="security"', $html, "بخشِ امنیت در $loc نیامد");
        }
    }

    /** کلیدِ خام یعنی ترجمه جا افتاده و کاربر متنِ فنی می‌بیند */
    public function test_no_raw_translation_keys_leak(): void
    {
        foreach (array_keys(self::URLS) as $loc) {
            $this->assertDoesNotMatchRegularExpression('~ui\.[a-z_]+~', $this->html($loc),
                "کلیدِ خام در $loc چاپ شده");
        }
    }

    /** 🔴 طراحِ رایگان باید بمانَد — قلابِ ورودِ قیف است، نه چیزی که حذف شود */
    public function test_the_free_designer_is_still_offered(): void
    {
        $fa = $this->html('fa');

        $this->assertStringContainsString('https://bpmn.servernet.cloud', $fa,
            'لینکِ طراحِ رایگان نباید حذف شود');
        $this->assertStringContainsString('طراح رایگان', $fa);
    }

    /** قیف: دکمهٔ اصلی به دمو/مشاوره می‌رود، طراحِ رایگان دکمهٔ ثانویه است */
    public function test_primary_cta_points_to_the_enterprise_demo(): void
    {
        $fa = $this->html('fa');

        $this->assertStringContainsString('دموی پلتفرم سازمانی', $fa);
        $this->assertStringContainsString('مشاورهٔ استقرار سازمانی', $fa);
    }

    public function test_comparison_table_has_three_columns(): void
    {
        $fa = $this->html('fa');

        foreach (['طراح رایگان', 'پلتفرم سازمانی سرورنت', 'ابزار خارجی'] as $col) {
            $this->assertStringContainsString($col, $fa, "ستونِ «{$col}» در جدولِ مقایسه نیست");
        }
    }

    /** پرسش‌های سازمانیِ بریف واقعاً روی صفحه باشند */
    public function test_enterprise_faq_questions_are_present(): void
    {
        $fa = $this->html('fa');

        $this->assertStringContainsString('On-Premise', $fa);
        $this->assertStringContainsString('به سیستم‌های فعلی ما وصل می‌شود؟', $fa);
        $this->assertStringContainsString('پشتیبانی و آموزش دارید؟', $fa);
    }

    /** سئو: عنوان باید هر دو روی را داشته باشد، نه فقط «طراح رایگان» */
    public function test_meta_title_covers_designer_and_platform(): void
    {
        $fa = $this->html('fa');

        $this->assertMatchesRegularExpression('~<title>[^<]*BPMN[^<]*</title>~u', $fa);
        $this->assertStringContainsString('BPMS', $fa, 'کلیدواژهٔ پلتفرم سازمانی باید در صفحه باشد');
    }

    /**
     * 🔴 مهم‌ترین: بخش‌های تازه اختیاری‌اند. اگر روزی کسی `@if` را بردارد،
     * نُه صفحهٔ دیگر یا خطا می‌دهند یا بخشِ خالی نشان می‌دهند.
     */
    public function test_other_solution_pages_are_untouched(): void
    {
        $others = array_diff(array_keys(config('solutions')), ['bpmn-designer']);
        $this->assertGreaterThanOrEqual(5, count($others), 'راهکارهای دیگر باید وجود داشته باشند');

        foreach ($others as $slug) {
            $html = $this->followingRedirects()->get("/solutions/{$slug}")->assertOk()->getContent();

            $this->assertStringNotContainsString('id="platform"', $html, "$slug نباید بخشِ پلتفرم بگیرد");
            $this->assertStringNotContainsString('id="usecases"', $html, "$slug نباید بخشِ کاربردها بگیرد");
            $this->assertStringNotContainsString('id="security"', $html, "$slug نباید بخشِ امنیت بگیرد");
            $this->assertDoesNotMatchRegularExpression('~ui\.[a-z_]+~', $html, "کلیدِ خام در $slug");
        }
    }

    /** جدولِ دو ستونهٔ راهکارهای دیگر نباید ستونِ سومِ خالی بگیرد */
    public function test_two_column_comparisons_stay_two_column(): void
    {
        $withCompare = null;

        foreach (array_diff(array_keys(config('solutions')), ['bpmn-designer']) as $slug) {
            if (! empty(config("solutions.{$slug}.fa.compare"))) { $withCompare = $slug; break; }
        }

        if ($withCompare === null) {
            $this->markTestSkipped('راهکارِ دیگری با جدولِ مقایسه نداریم');
        }

        $html = $this->followingRedirects()->get("/solutions/{$withCompare}")->assertOk()->getContent();

        // ستونِ سوم فقط با compare_them2 می‌آید؛ این راهکار آن را ندارد
        $this->assertEmpty(config("solutions.{$withCompare}.fa.compare_them2"));
        $this->assertStringNotContainsString('<td>—</td>', $html,
            'ستونِ سومِ خالی یعنی شرطِ اختیاری‌بودن شکسته است');
    }
}
