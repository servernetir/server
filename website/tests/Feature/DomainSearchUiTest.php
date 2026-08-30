<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ظاهرِ صفحهٔ دامنه، فیلترها، و دو باگِ گزارش‌شدهٔ جدول.
 */
class DomainSearchUiTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════ صفحهٔ جستجو ═══════════════

    public function test_the_search_page_has_the_filter_checkboxes(): void
    {
        $html = $this->get('/domains')->assertOk()->getContent();

        foreach (['f-taken', 'f-premium', 'f-unavail', 'f-sort'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
    }

    /** ⚠️ فیلترها تا نتیجه‌ای نباشد پنهانند — نوارِ خالی بالای صفحهٔ خالی بی‌معناست */
    public function test_the_filter_bar_starts_hidden(): void
    {
        $html = $this->get('/domains')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~id="dm-filters"\s+hidden~', $html);
        $this->assertMatchesRegularExpression('~id="dm-table"\s+hidden~', $html);
    }

    /** هر سه زبان باید متنِ خودشان را بگیرند، نه کلیدِ خام */
    public function test_every_locale_renders_real_strings(): void
    {
        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $loc) {
            $html = $this->get($prefix.'/domains')->assertOk()->getContent();

            $this->assertStringNotContainsString('ui.dsr_', $html, "زبان {$loc} کلیدِ خام دارد");
        }
    }

    /** شمارندهٔ نتایج باید جای‌نگهدارِ جاوااسکریپت را داشته باشد، نه عددِ ثابت */
    public function test_the_count_template_reaches_the_browser(): void
    {
        $html = $this->get('/domains')->assertOk()->getContent();

        $this->assertStringContainsString('count_tpl', $html);
        $this->assertStringContainsString('__N__', $html);
    }

    // ═══════════════ باگِ سرستونِ چسبنده ═══════════════

    /**
     * 🔴 `.plan-table thead th` با `position:sticky; top:0` بود، ولی سایت هدرِ
     * **ثابت** دارد — پس سرستون زیرِ منو می‌رفت و به‌شکلِ یک متنِ شناور
     * («سرور ابری»، «پردازندهٔ اختصاصی») گوشهٔ صفحه ظاهر می‌شد.
     */
    public function test_the_plan_table_header_is_not_sticky(): void
    {
        $css = file_get_contents(public_path('assets/css/site.css'));

        $i = strpos($css, '.plan-table thead th{');
        $this->assertNotFalse($i, 'قاعدهٔ سرستون پیدا نشد');

        $rule = substr($css, $i, strpos($css, '}', $i) - $i);

        $this->assertStringNotContainsString('sticky', $rule,
            'سرستونِ چسبنده زیرِ هدرِ ثابت می‌رود و متنِ شناور می‌سازد');
    }

    /** ستون‌ها باید وسط‌چین باشند */
    public function test_the_plan_table_columns_are_centred(): void
    {
        $css = file_get_contents(public_path('assets/css/site.css'));

        $this->assertMatchesRegularExpression(
            '~\.plan-table th,\s*\.plan-table td\{[^}]*text-align:center~',
            $css
        );
    }

    // ═══════════════ ترافیکِ نامحدود ═══════════════

    private function plan(int $trafficGb): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-x'], [
            'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true, 'sort' => 0,
        ]);

        return CloudPlan::create([
            'provider' => 'p', 'provider_ref' => 'r'.$trafficGb, 'location_code' => 'de-x',
            'slug' => 'cv-'.$trafficGb, 'public_name' => 'CV', 'vcpu' => 2, 'ram_mb' => 4096,
            'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => $trafficGb,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 400,
            'price_eur_cents' => 600, 'price_irt' => 1000000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ]);
    }

    public function test_traffic_shows_unlimited_when_the_setting_is_on(): void
    {
        Setting::put('cloud_traffic_unlimited', '1');

        $this->assertSame('نامحدود', $this->plan(20480)->trafficLabel('fa'));
        $this->assertSame('Unlimited', $this->plan(1024)->trafficLabel('en'));
        $this->assertSame('Sınırsız', $this->plan(512)->trafficLabel('tr'));
    }

    /** خاموش که باشد، عددِ واقعی برمی‌گردد */
    public function test_traffic_shows_the_real_figure_when_the_setting_is_off(): void
    {
        $this->assertSame('20 TB', $this->plan(20480)->trafficLabel('en'));
    }

    /**
     * 🔴 مهم: برچسب عوض می‌شود، **عدد نه**.
     *
     * اگر `traffic_gb` را دست می‌زدیم، قاعدهٔ حذفِ پلنِ مغلوب دو پلن با ترافیکِ
     * متفاوت را یکی می‌شمرد و یکی‌شان بی‌دلیل از صفحه غیب می‌شد.
     */
    public function test_the_unlimited_label_does_not_change_the_stored_number(): void
    {
        Setting::put('cloud_traffic_unlimited', '1');

        $p = $this->plan(20480);
        $p->trafficLabel('fa');

        $this->assertSame(20480, (int) $p->fresh()->traffic_gb);
    }
}
