<?php

namespace Tests\Feature;

use App\Services\SiteAudit;
use Tests\TestCase;

/**
 * بررسی سایت — هفت بُعد، و «چه کار کنم» به‌جای «چند گرفتی».
 *
 * ═══ چرا این کلاس لازم است ═══
 *
 * این گزارش سه فهرستِ **موازی** دارد که باید همیشه با هم بخوانند:
 *
 *   ۱) کلیدهای چک در `SiteAudit`
 *   ۲) عنوان و توضیحِ همان کلیدها در `config/tools.php`  (× سه زبان)
 *   ۳) راهکارِ همان کلیدها در `resources/content/audit-fixes.php` (× سه زبان)
 *
 * جاافتادنِ هرکدام **خطا نمی‌دهد**: کلیدِ بی‌متادیتا نامِ خامِ انگلیسی‌اش را
 * روی صفحه چاپ می‌کند، و چکِ بی‌راهکار فقط می‌گوید «مشکل داری» و کاربر را با
 * همان مشکل تنها می‌گذارد — یعنی دقیقاً همان چیزی که این بازطراحی برای رفعش
 * بود. سه تستِ اولِ این کلاس همین سه فهرست را به هم قفل می‌کنند.
 */
class ToolSeoAuditTest extends TestCase
{
    private function checkKeys(): array
    {
        preg_match_all(
            "~check\('([a-z0-9_]+)'~",
            (string) file_get_contents(app_path('Services/SiteAudit.php')),
            $m
        );

        return array_values(array_unique($m[1]));
    }

    private function fixes(): array
    {
        return (array) require resource_path('content/audit-fixes.php');
    }

    // ═══════════════ ۱) سه فهرستِ موازی ═══════════════

    public function test_every_check_has_a_title_in_all_three_languages(): void
    {
        $meta = (array) config('tools.checks');

        foreach ($this->checkKeys() as $key) {
            $this->assertArrayHasKey($key, $meta, "چکِ «{$key}» در config/tools.php متادیتا ندارد — نامِ خام روی صفحه چاپ می‌شود");

            foreach (['fa', 'en', 'tr'] as $l) {
                $this->assertNotEmpty($meta[$key][$l]['t'] ?? null, "عنوانِ {$l} برای «{$key}» نیست");
                $this->assertNotEmpty($meta[$key][$l]['d'] ?? null, "توضیحِ {$l} برای «{$key}» نیست");
            }
        }
    }

    /**
     * 🔴 هر چکی که می‌تواند شکست بخورد باید راهکار داشته باشد.
     *
     * ⚠️ `cert_issuer` تنها استثناست و عمدی: همیشه `pass` است و فقط نامِ
     * صادرکننده را گزارش می‌کند. چیزی برای «درست‌کردن» ندارد.
     */
    public function test_every_actionable_check_ships_a_fix(): void
    {
        $informational = ['cert_issuer'];
        $fixes = $this->fixes();

        foreach ($this->checkKeys() as $key) {
            if (in_array($key, $informational, true)) {
                continue;
            }

            $this->assertArrayHasKey($key, $fixes, "چکِ «{$key}» راهکار ندارد — گزارش می‌گوید مشکل داری و راه‌حل نمی‌دهد");

            foreach (['fa', 'en', 'tr'] as $l) {
                $this->assertNotEmpty($fixes[$key][$l]['fix'] ?? null, "راهکارِ {$l} برای «{$key}» نیست");
            }
        }
    }

    public function test_no_orphan_metadata_or_fix_is_left_behind(): void
    {
        $keys = $this->checkKeys();

        $this->assertSame([], array_values(array_diff(array_keys((array) config('tools.checks')), $keys)),
            'متادیتایی مانده که هیچ چکی ندارد — یا چک حذف شده و متادیتایش نه، یا کلید تایپی دارد');

        $this->assertSame([], array_values(array_diff(array_keys($this->fixes()), $keys)),
            'راهکاری مانده که هیچ چکی ندارد');
    }

    // ═══════════════ ۲) هفت بُعد ═══════════════

    public function test_the_seven_dimensions_exist_and_each_names_its_audience(): void
    {
        $cats = (array) config('tools.categories');
        $who = (array) config('tools.audience');

        foreach (['seo', 'performance', 'security', 'accessibility', 'network', 'mobile', 'best'] as $c) {
            $this->assertArrayHasKey($c, $cats, "دستهٔ «{$c}» نیست");
            $this->assertArrayHasKey($c, $who, "دستهٔ «{$c}» مخاطب ندارد");

            foreach (['fa', 'en', 'tr'] as $l) {
                $this->assertNotEmpty($cats[$c][$l] ?? null);
                $this->assertNotEmpty($who[$c][$l] ?? null);
            }
        }
    }

    /**
     * وزن‌ها باید ۱۰۰ شوند.
     *
     * ⚠️ نه برای زیبایی: `run()` امتیازِ کل را بر مجموعِ وزن‌ها تقسیم می‌کند، پس
     * جمعِ غیرِ ۱۰۰ هم کار می‌کند و هیچ خطایی نمی‌دهد — فقط سهمِ هر دسته با آنچه
     * فکر می‌کنیم فرق دارد. این تست همان اختلافِ خاموش را می‌گیرد.
     */
    public function test_category_weights_sum_to_one_hundred(): void
    {
        $r = new \ReflectionClass(SiteAudit::class);
        $w = $r->getConstant('WEIGHTS');

        $this->assertSame(100, array_sum($w), 'جمعِ وزن‌ها ۱۰۰ نیست: '.json_encode($w));
        $this->assertSame(
            ['seo', 'performance', 'security', 'accessibility', 'network', 'mobile', 'best'],
            array_keys($w)
        );
    }

    /**
     * 🔴 چکی که همیشه `pass` می‌دهد نباید برگردد.
     *
     * `tap_targets` دقیقاً همین بود: اندازهٔ ناحیهٔ لمسی بدونِ رندرِ صفحه معلوم
     * نیست، ولی چک یک `pass`ِ ثابت می‌داد. هم امتیاز را تصنعی بالا می‌برد، هم به
     * کاربر می‌گفت «بررسی شد، مشکلی نیست» در حالی که هیچ بررسی‌ای نشده بود.
     */
    public function test_the_always_passing_check_did_not_come_back(): void
    {
        $src = (string) file_get_contents(app_path('Services/SiteAudit.php'));

        $this->assertStringNotContainsString("check('tap_targets'", $src);
        $this->assertArrayNotHasKey('tap_targets', (array) config('tools.checks'));

        /*
         * و هیچ چکِ **تازه‌ای** با وضعیتِ سخت‌کدِ pass اضافه نشود.
         *
         * ⚠️ `cert_issuer` مجاز است و تنها استثنا: وزنش ۱ است و کارش گزارشِ نامِ
         * صادرکنندهٔ گواهی است، نه قضاوت. فهرست عمداً صریح است تا استثنای بعدی
         * هم یک تصمیمِ آگاهانه باشد، نه چیزی که بی‌صدا از تست رد شود.
         */
        $allowed = ['cert_issuer'];

        preg_match_all("~check\('([a-z0-9_]+)', 'pass',~", $src, $m);
        $this->assertSame([], array_values(array_diff($m[1], $allowed)),
            'چکی با وضعیتِ ثابتِ pass اضافه شده — چیزی را نمی‌سنجد ولی امتیاز می‌دهد: '.implode(', ', $m[1]));
    }

    // ═══════════════ ۳) برنامهٔ اقدام ═══════════════

    /**
     * برنامه از **همان** وزن‌های چک ساخته می‌شود و فقط کارِ واقعی داخلش است.
     *
     * فیکسچرِ ساختگی به‌جای تماسِ شبکه: این تست دربارهٔ **مرتب‌سازی** است، نه
     * دربارهٔ اینکه فلان سایت امروز چه وضعی دارد.
     */
    public function test_the_action_plan_ranks_by_impact_and_never_lists_a_pass(): void
    {
        $audit = new SiteAudit();
        $m = new \ReflectionMethod($audit, 'actionPlan');
        $m->setAccessible(true);

        $plan = $m->invoke($audit, [
            'seo' => [
                ['key' => 'small_warn', 'status' => 'warn', 'weight' => 1],
                ['key' => 'big_fail',   'status' => 'fail', 'weight' => 6],
                ['key' => 'fine',       'status' => 'pass', 'weight' => 9],
            ],
            'security' => [
                ['key' => 'mid_fail', 'status' => 'fail', 'weight' => 4],
                ['key' => 'big_warn', 'status' => 'warn', 'weight' => 5],
            ],
        ]);

        /*
         * ترتیب = وزنِ چک × شدت (fail = ۲، warn = ۱):
         *   big_fail  ۶ × ۲ = ۱۲
         *   mid_fail  ۴ × ۲ =  ۸   ← خطای کم‌وزن‌تر از هشدارِ پروزن‌تر جلو می‌زند
         *   big_warn  ۵ × ۱ =  ۵
         *   small_warn ۱ × ۱ = ۱
         *
         * این ترتیب عمدی است: چیزی که **شکسته** است پیش از چیزی که فقط
         * می‌شود بهترش کرد می‌آید، حتی اگر دومی مهم‌تر به‌نظر برسد.
         */
        $this->assertSame(['big_fail', 'mid_fail', 'big_warn', 'small_warn'], array_column($plan, 'key'));

        foreach ($plan as $p) {
            $this->assertNotSame('pass', $p['status'], 'ردیفِ سالم در فهرستِ کارها آمده');
            $this->assertArrayHasKey('cat', $p, 'هر کار باید بگوید مالِ کدام بُعد است');
        }

        $this->assertSame(12.0, $plan[0]['priority']);
        $this->assertSame(8.0, $plan[1]['priority']);
        $this->assertSame(5.0, $plan[2]['priority']);
    }

    public function test_the_plan_is_capped_so_it_stays_a_plan_not_a_wall(): void
    {
        $audit = new SiteAudit();
        $m = new \ReflectionMethod($audit, 'actionPlan');
        $m->setAccessible(true);

        $many = [];
        foreach (range(1, 30) as $i) {
            $many[] = ['key' => 'k'.$i, 'status' => 'fail', 'weight' => $i];
        }

        $this->assertCount(
            (new \ReflectionClass(SiteAudit::class))->getConstant('PLAN_SIZE'),
            $m->invoke($audit, ['seo' => $many])
        );
    }

    public function test_a_clean_site_gets_an_empty_plan_not_a_fake_one(): void
    {
        $audit = new SiteAudit();
        $m = new \ReflectionMethod($audit, 'actionPlan');
        $m->setAccessible(true);

        $this->assertSame([], $m->invoke($audit, [
            'seo' => [['key' => 'a', 'status' => 'pass', 'weight' => 5]],
        ]));
    }

    // ═══════════════ ۴) صفحه ═══════════════

    public function test_the_page_ships_the_fixes_and_the_audience_labels(): void
    {
        $html = $this->get('/tools/seo')->assertOk()->getContent();

        $this->assertStringContainsString('"fixes"', $html, 'راهکارها باید با صفحه بیایند، نه در هر پاسخِ API');
        $this->assertStringContainsString('audit-plan', $html);
        $this->assertStringContainsString('audit-filter', $html);

        // مخاطبِ هر بُعد روی صفحه دیده شود — همان چیزی که این ابزار را از یک
        // «نمرهٔ سئو» جدا می‌کند
        foreach (['طراح سایت و UI/UX', 'مدیر شبکه', 'مدیر انفورماتیک'] as $who) {
            $this->assertStringContainsString($who, $html);
        }
    }

    public function test_no_raw_translation_keys_leak_in_any_language(): void
    {
        foreach (['/tools/seo', '/en/tools/seo', '/tr/tools/seo'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('ui.au_', $html, "کلیدِ خام در {$url}");
            $this->assertStringNotContainsString('ui.tl_', $html, "کلیدِ خام در {$url}");
        }
    }

    /** ورودیِ بی‌معنی نباید موتور را بترکاند */
    public function test_a_bad_url_is_rejected_cleanly(): void
    {
        foreach (['', 'not a url', 'http://localhost/', 'http://127.0.0.1/'] as $bad) {
            $r = app(SiteAudit::class)->run($bad);

            $this->assertFalse($r['ok'] ?? true, "«{$bad}» نباید پذیرفته شود");
            $this->assertArrayHasKey('error', $r);
        }
    }
}
