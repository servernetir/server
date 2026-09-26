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
            ['country' => 'XX', 'is_active' => true, 'sort' => 1],
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

    /**
     * چند کارت با این نام در **انتخابگرِ** صفحه هست؟
     *
     * ⚠️ شمارشِ نام در کلِ HTML دو بار غلط از آب درآمد: یک بار JSON-LDِ Product
     * نام را در Offer تکرار می‌کرد، و یک بار جدول‌های «کدام کارت» و «هزینهٔ
     * واقعی» — که هر دو باید نامِ کارت را نشان بدهند. پس دامنه را به خودِ
     * انتخابگر می‌بندیم: یکتاسازی دربارهٔ کارت‌های **قابلِ انتخاب** است.
     */
    private function cardCount(string $name): int
    {
        $html = (string) $this->get('/gpu')->assertOk()->getContent();
        $start = strpos($html, 'id="gpu-cards"');

        if ($start === false) {
            return 0;
        }

        $end = strpos($html, '<div class="gpu-side"', $start);

        return substr_count(substr($html, $start, ($end === false ? strlen($html) : $end) - $start), $name);
    }

    /**
     * 🔴 دو کلاسِ GPU با نامِ یکسان و مشخصات/قیمتِ نمایشیِ یکسان (نمونهٔ
     * واقعیِ کاتالوگ: «RTX PRO 6000 Blackwell» در دو نسخهٔ زیرساختی) نباید دو
     * کارتِ بایت‌به‌بایت تکراری بسازند — مشتری فقط گیج می‌شود.
     */
    public function test_identical_duplicate_cards_collapse_into_one(): void
    {
        $this->seedGpuPlan([
            'public_name' => 'RTX PRO 6000 TESTCARD', 'gpu_model' => 'RTX PRO 6000 TESTCARD',
            'provider_ref' => 'gc-pro-a', 'slug' => 'cv-8c-30g-100d-global-gpu-pro-a',
        ]);
        $this->seedGpuPlan([
            'public_name' => 'RTX PRO 6000 TESTCARD', 'gpu_model' => 'RTX PRO 6000 TESTCARD',
            'provider_ref' => 'gc-pro-b', 'slug' => 'cv-8c-30g-100d-global-gpu-pro-b',
        ]);

        $this->assertSame(1, $this->cardCount('RTX PRO 6000 TESTCARD'),
            'کارتِ تکراریِ هم‌نام و هم‌مشخصات باید یکی شود.');
    }

    /** ولی اگر چیزی که مشتری می‌بیند فرق کند — حتی فقط قیمت — هر دو می‌مانند */
    public function test_same_name_with_a_different_price_keeps_both_cards(): void
    {
        $this->seedGpuPlan([
            'public_name' => 'RTX PRO 6000 TESTCARD', 'gpu_model' => 'RTX PRO 6000 TESTCARD',
            'provider_ref' => 'gc-pro-a', 'slug' => 'cv-8c-30g-100d-global-gpu-pro-a',
        ]);
        $this->seedGpuPlan([
            'public_name' => 'RTX PRO 6000 TESTCARD', 'gpu_model' => 'RTX PRO 6000 TESTCARD',
            'provider_ref' => 'gc-pro-b', 'slug' => 'cv-8c-30g-100d-global-gpu-pro-b',
            'price_irt' => 9_900_000,
        ]);

        $this->assertSame(2, $this->cardCount('RTX PRO 6000 TESTCARD'),
            'قیمتِ متفاوت یعنی دو عرضهٔ واقعاً متفاوت — هیچ‌کدام نباید غیب شود.');
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

    /** @return list<array<string,mixed>> */
    private function ldBlocks(string $html): array
    {
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);

        return array_values(array_filter(array_map(fn ($j) => json_decode($j, true), $m[1])));
    }

    /**
     * Product با Offerِ **ساعتی** — قیمتش همان عددِ مدل.
     *
     * GSC (۱۶ سپتامبر ۲۰۲۶): /gpu هفتمین صفحهٔ پرکلیک بود و «اجاره gpu» در رتبهٔ
     * ۷–۹، ولی برخلافِ /vps/hourly هیچ Productی نداشت و در «Product snippets»
     * نبود. قیمتِ schema اگر از عددِ دیگری بیاید، همان قیمتِ دروغی است که
     * Merchant listings بعد از چند روز پرچم می‌زند.
     */
    public function test_the_page_emits_an_hourly_product_offer_from_the_model_price(): void
    {
        $plan = $this->seedGpuPlan();
        Setting::put('pricing_rate_override', '100000');

        $fa = collect($this->ldBlocks((string) $this->get('/gpu')->assertOk()->getContent()))
            ->firstWhere('@type', 'Product');

        $this->assertNotNull($fa, 'Product در /gpu نیست');
        $offer = $fa['offers'][0];
        $this->assertSame('IRR', $offer['priceCurrency']);
        $this->assertSame((int) schema_price_irr($plan->hourlyIrt()), $offer['price'], 'ریال = تومان × ۱۰ از مدل');
        $this->assertSame('HUR', $offer['priceSpecification']['unitCode'], 'باید ساعتی خوانده شود نه ماهانه');

        $en = collect($this->ldBlocks((string) $this->get('/en/gpu')->assertOk()->getContent()))
            ->firstWhere('@type', 'Product');
        $this->assertSame('EUR', $en['offers'][0]['priceCurrency']);
        $this->assertSame($plan->hourlyEurCents() / 100, $en['offers'][0]['price']);
    }

    /** بی‌کارتِ فروختنی، Productِ بی‌قیمت ساخته نمی‌شود — نشانه‌گذاریِ نبود از غلط بهتر است. */
    public function test_no_product_schema_without_a_sellable_card(): void
    {
        $types = array_column($this->ldBlocks((string) $this->get('/gpu')->assertOk()->getContent()), '@type');

        $this->assertNotContains('Product', $types);
    }

    /**
     * FAQPage فقط از پرسش‌هایی که **روی صفحه دیده می‌شوند**.
     *
     * رهنمودِ گوگل: محتوای FAQ در schema باید برای کاربر قابلِ مشاهده باشد.
     * پس هر پاسخِ schema باید عیناً در HTMLِ رندرشده هم باشد.
     */
    public function test_faq_schema_only_repeats_visible_questions(): void
    {
        $this->seedGpuPlan();

        foreach (['/gpu', '/en/gpu', '/tr/gpu'] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();
            $faq = collect($this->ldBlocks($html))->firstWhere('@type', 'FAQPage');

            $this->assertNotNull($faq, $url.': FAQPage نیست');
            $this->assertGreaterThanOrEqual(2, count($faq['mainEntity']));

            $visible = html_entity_decode(strip_tags((string) preg_replace('~<script\b.*?</script>~s', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            foreach ($faq['mainEntity'] as $q) {
                $this->assertStringContainsString($q['name'], $visible, $url.': پرسشِ نامرئی در schema');
                $this->assertStringContainsString($q['acceptedAnswer']['text'], $visible, $url.': پاسخِ نامرئی در schema');
            }
        }
    }

    /**
     * 🔴 هیچ کلیدِ خامِ `ui.*` — نه فقط `ui.gpu_*`.
     *
     * ═══ رخداد (۴ مهر ۱۴۰۵) ═══
     * ویوِ /gpu با جدولِ راهنما، هزینه، ۱۲ پرسش و بخشِ پایانِ اعتبار به
     * سرورِ زنده رفت و کلیدهایش نرفت: ۸۸ کلیدِ خام روی صفحه و در FAQPage
     * («ui.gpu_faq1_q»). تستِ قبلی فقط `ui.gpu_` را می‌گشت و کلیدهای
     * `ui.cl_*`ِ پارشالِ پایانِ اعتبار از زیرش رد می‌شد.
     */
    public function test_no_raw_translation_key_of_any_prefix_leaks(): void
    {
        $this->seedGpuPlan();

        foreach (['/gpu', '/en/gpu', '/tr/gpu'] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $this->assertDoesNotMatchRegularExpression('~(?<![\w/.-])ui\.[a-z][a-z0-9]*_[a-z0-9_]+~', $html,
                "کلیدِ خامِ ترجمه در {$url}.");
        }
    }

    /**
     * 🔴 مشتری باید پیش از خرید بداند کدام ابزار آماده است و چه چیزی نیست.
     *
     * ═══ رخداد (۲ و ۴ مهر ۱۴۰۵) ═══
     * TK-260924-8595: برای ComfyUI با نودهای سفارشی خرید و وجه برگشت.
     * TK-260926-5019: «کدام آماده است؟ چند برنامه روی یک GPU؟ Workflow
     * سفارشی؟» — هیچ‌کدام روی صفحه جواب نداشت، و کارتِ ComfyUI «رابط وب»
     * وعده می‌داد درحالی‌که ایمیجِ تحویلی فقط API است (ریشه‌اش 404).
     */
    public function test_the_page_answers_what_runs_and_never_promises_a_comfyui_web_ui(): void
    {
        $this->seedGpuPlan();

        foreach (['fa' => '/gpu', 'en' => '/en/gpu', 'tr' => '/tr/gpu'] as $lang => $url) {
            app()->setLocale($lang);
            $html = (string) $this->get($url)->assertOk()->getContent();
            $faq  = collect($this->ldBlocks($html))->firstWhere('@type', 'FAQPage');
            $asked = array_column($faq['mainEntity'], 'name');

            foreach (range(13, 18) as $n) {
                $this->assertContains(__('ui.gpu_faq'.$n.'_q'), $asked, "{$url}: پرسشِ {$n} در FAQ نیست.");
            }
        }

        app()->setLocale('fa');
        $this->assertStringNotContainsString('رابط وب و API', __('ui.gpu_app_img_d'),
            'کارتِ ComfyUI دوباره رابطِ وب وعده می‌دهد؛ ایمیجِ تحویلی فقط API است.');
        $this->assertStringNotContainsString('web UI and API', __('ui.gpu_app_img_d', [], 'en'));
        $this->assertStringContainsString('Jupyter', __('ui.gpu_faq17_a'),
            'پاسخِ Workflow سفارشی باید بگوید تنها راهش Jupyter است.');
    }
}
