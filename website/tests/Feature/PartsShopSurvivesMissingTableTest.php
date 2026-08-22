<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * فروشگاهِ قطعات نباید روی سرورِ مهاجرت‌نخورده ۵۰۰ بدهد.
 *
 * ═══ چرا این خطر واقعی است ═══
 *
 * مهاجرتِ پروداکشن دستِ کاربر است (CLAUDE.md) و بینِ لحظه‌ای که کد بالا
 * می‌رود و لحظه‌ای که مدیر مهاجرت را می‌زند، یک بازهٔ **واقعی** هست. در همان
 * بازه هر بازدیدکننده‌ای که روی «قطعات سرور» در مگامنو بزند صفحهٔ خطا می‌دید.
 *
 * 🔴 این دقیقاً همان چیزی است که ماژولِ تقویم یک بار سرِ ما آورد. صفحهٔ
 * «به‌زودی» بی‌نهایت بهتر از ۵۰۰ روی صفحهٔ عمومیِ فروشگاه است.
 *
 * ⚠️ `sitemap.xml` هم در همین فهرست است: نقشهٔ سایتِ ۵۰۰ یعنی گوگل موقتاً
 * هیچ صفحه‌ای از سایت را نمی‌تواند کشف کند — آسیبش از یک صفحهٔ خراب بیشتر است.
 */
class PartsShopSurvivesMissingTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // شبیه‌سازیِ سروری که کد را گرفته ولی مهاجرت نخورده
        Schema::dropIfExists('server_parts');
    }

    public function test_no_public_page_breaks_without_the_parts_table(): void
    {
        $this->assertFalse(Schema::hasTable('server_parts'), 'پیش‌شرطِ تست: جدول نباید باشد');

        foreach ([
            '/parts',
            '/parts/cpu',
            '/parts/compare',
            '/parts/compare?parts=anything',
            '/servers/hp/gen9',
            '/sitemap.xml',
            '/',
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    /** صفحهٔ محصول بی‌جدول ۴۰۴ می‌دهد — که درست است، چون محصولی وجود ندارد. */
    public function test_a_product_page_is_a_clean_404_not_a_crash(): void
    {
        $this->get('/parts/cpu/anything')->assertNotFound();
    }

    /** هاب باید «به‌زودی» بگوید، نه فهرستِ خالیِ بی‌توضیح. */
    public function test_the_hub_explains_itself_instead_of_showing_nothing(): void
    {
        $this->get('/parts')->assertOk()->assertSee(__('ui.parts_soon'));
    }

    /** مگامنو نباید لینکِ شکسته بسازد — روت‌ها مستقل از جدول‌اند. */
    public function test_the_menu_links_still_resolve(): void
    {
        $home = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('/parts/cpu', $home);
        $this->assertStringContainsString('/servers/hp/gen9', $home);
    }
}
