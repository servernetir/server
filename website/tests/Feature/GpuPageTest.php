<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحهٔ فرودِ سرور گرافیکی — /gpu در هر سه زبان.
 *
 * ⚠️ ادعاها روی **محتوای رندرشده** است نه کدِ ۲۰۰: صفحه‌ای با کلیدِ ترجمه‌نشده
 * یا جدولِ خالی هم ۲۰۰ می‌دهد و سالم به‌نظر می‌رسد.
 */
class GpuPageTest extends TestCase
{
    use RefreshDatabase;

    private function seedGpuPlan(array $over = []): CloudPlan
    {
        CloudLocation::firstOrCreate(
            ['code' => 'global-gpu'],
            ['country' => 'شبکهٔ توزیع‌شده', 'is_active' => true, 'sort' => 1],
        );

        return CloudPlan::create(array_merge([
            'provider'          => 'salad',
            'provider_ref'      => 'gc-4090',
            'provider_location' => 'global',
            'location_code'     => 'global-gpu',
            'public_name'       => 'RTX 4090',
            'slug'              => 'cv-8c-30g-100d-global-gpu-rtx-4090',
            'vcpu'              => 8,
            'ram_mb'            => 30720,
            'disk_gb'           => 100,
            'disk_type'         => 'ssd',
            'traffic_gb'        => 0,
            'cpu_kind'          => 'shared',
            'arch'              => 'x86',
            'cost_eur_cents'    => 4000,
            'price_eur_cents'   => 6000,
            'price_irt'         => 7_200_000,
            'is_active'         => true,
            'in_stock'          => true,
            'admin_disabled'    => false,
            'gpu_model'         => 'RTX 4090',
            'gpu_count'         => 1,
            'is_interruptible'  => true,
        ], $over));
    }

    public function test_the_page_answers_in_all_three_languages(): void
    {
        $this->seedGpuPlan();

        foreach (['/gpu', '/en/gpu', '/tr/gpu'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    /**
     * 🔴 کلیدِ ترجمه‌نشده = کاربر متنِ خام «ui.gpu_h1» می‌بیند.
     *
     * قرارداد پروژه: هر کلیدِ تازه باید در **هر سه** فایل زبان باشد. این تست
     * همان را از سمتِ خروجی می‌سنجد، نه با شمردنِ کلیدها.
     */
    public function test_no_untranslated_key_leaks_into_any_language(): void
    {
        $this->seedGpuPlan();

        foreach (['/gpu', '/en/gpu', '/tr/gpu'] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $this->assertDoesNotMatchRegularExpression('~\bui\.gpu_[a-z0-9_]+~', $html,
                "کلیدِ ترجمه‌نشده در {$url} چاپ شد.");
        }
    }

    /**
     * 🔴 هشدارِ قطع‌شدنی‌بودن باید **روی صفحه** باشد و پیش از پیکربند.
     *
     * این محصول حتی در بالاترین اولویت قطع می‌شود. مشتری‌ای که این را نبیند و
     * ماشینش وسطِ کار برود، حق دارد شکایت کند — و تعهدِ /sla پشتِ این نیست.
     */
    public function test_the_interruptible_warning_is_on_the_page_before_the_configurator(): void
    {
        $this->seedGpuPlan();

        $html = (string) $this->get('/gpu')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.gpu_warn_t'), $html, 'هشدارِ قطع‌شدنی نیست');

        $warn = strpos($html, 'gpu-warn');
        $cfg = strpos($html, 'gpu-cards');

        $this->assertNotFalse($warn);
        $this->assertNotFalse($cfg);
        $this->assertLessThan($cfg, $warn,
            'هشدار بعد از پیکربند آمده — تصمیمِ خرید پیش از دیدنش گرفته می‌شود.');
    }

    /**
     * ⚠️ «تعداد» یعنی چند ماشینِ جدا، نه چند کارت در یک ماشین.
     *
     * در اسپکِ زیرساخت این `replicas` است. اگر جملهٔ توضیحی حذف شود، مشتری
     * انتظارِ یک باکسِ چندکارته پیدا می‌کند و SSH که زد یکی می‌بیند.
     */
    public function test_the_unit_counter_says_it_means_separate_machines(): void
    {
        $this->seedGpuPlan();

        $html = (string) $this->get('/gpu')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.gpu_units_d'), $html,
            'توضیحِ «هر واحد یک ماشینِ جداست» از صفحه افتاده.');
    }

    /**
     * 🔴 پلنِ **بی‌GPU** نباید در این صفحه بیاید.
     *
     * فیلتر روی `gpu_model` است نه نامِ زیرساخت؛ بی‌آن، هر VPSِ معمولی هم
     * این‌جا به‌عنوان «سرور گرافیکی» فروخته می‌شد.
     */
    public function test_a_plan_without_a_gpu_never_appears_here(): void
    {
        $this->seedGpuPlan();
        $this->seedGpuPlan([
            'provider_ref' => 'plain-1',
            'slug'         => 'cv-2c-4g-40d-global-gpu',
            'public_name'  => 'پلنِ ساده',
            'gpu_model'    => null,
            'gpu_count'    => null,
        ]);

        $html = (string) $this->get('/gpu')->assertOk()->getContent();

        $this->assertStringContainsString('RTX 4090', $html);
        $this->assertStringNotContainsString('پلنِ ساده', $html,
            'پلنِ بدونِ GPU در صفحهٔ گرافیکی نشان داده شد.');
    }

    /**
     * ⚠️ پلنِ **نافروختنی** هم نباید بیاید — صفحه از `offers()` می‌خواند، همان
     * منبعی که فروشگاه دارد. وگرنه مشتری چیزی می‌بیند که سبد نمی‌فروشد.
     */
    public function test_an_unsellable_gpu_plan_is_not_shown(): void
    {
        $this->seedGpuPlan(['in_stock' => false]);

        $html = (string) $this->get('/gpu')->assertOk()->getContent();

        $this->assertStringNotContainsString('RTX 4090', $html);
        $this->assertStringContainsString(__('ui.gpu_empty_t'), $html);
    }

    /** کاتالوگِ خالی باید صفحهٔ توضیحی بدهد، نه ۵۰۰ */
    public function test_an_empty_catalogue_still_renders(): void
    {
        $this->get('/gpu')->assertOk()->assertSee(__('ui.gpu_empty_t'), false);
    }

    /**
     * 🔴 قاعدهٔ ثبت‌شده: هیچ مقداری با `{{ }}` واردِ جاوااسکریپتِ inline نشود.
     *
     * کوتیشنِ HTML-escape‌شده کلِ بلوک را با SyntaxError می‌کُشد و صفحه ۲۰۰ و
     * ظاهراً سالم می‌مانَد — پیکربند بی‌صدا از کار می‌افتد.
     */
    public function test_the_inline_script_carries_no_html_entities(): void
    {
        $this->seedGpuPlan();

        $html = (string) $this->get('/gpu')->assertOk()->getContent();

        preg_match_all('~<script>(.*?)</script>~s', $html, $m);

        $this->assertNotEmpty($m[1], 'بلوکِ اسکریپتِ پیکربند رندر نشد.');

        foreach ($m[1] as $js) {
            $this->assertStringNotContainsString('&quot;', $js);
            $this->assertStringNotContainsString('&#039;', $js);
        }
    }

    /**
     * ⚠️ صفحهٔ تازه **نباید** جبرانِ هدر بگذارد — `#main` یک‌جا رزرو می‌کند.
     * قاعدهٔ ثبت‌شدهٔ `FixedHeaderOffsetTest`.
     */
    public function test_the_page_does_not_compensate_for_the_fixed_header_itself(): void
    {
        $this->seedGpuPlan();

        $html = (string) $this->get('/gpu')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('~\.gpu-[a-z-]*\s*\{[^}]*padding-top\s*:\s*1[0-9]{2}px~', $html,
            'صفحه خودش جبرانِ هدر گذاشته — با #main دو بار جبران می‌شود.');
    }

    /** قیمتِ نمایش‌داده‌شده همان نرخِ ساعتیِ مدل است، نه عددِ ساخته‌شده در ویو */
    public function test_the_hourly_rate_comes_from_the_model(): void
    {
        $plan = $this->seedGpuPlan();

        Setting::put('pricing_rate_override', '100000');

        $html = (string) $this->get('/gpu')->assertOk()->getContent();

        $this->assertStringContainsString(cloud_price($plan->hourlyIrt()), $html,
            'نرخِ ساعتیِ صفحه با نرخِ مدل نمی‌خوانَد.');
    }
}
