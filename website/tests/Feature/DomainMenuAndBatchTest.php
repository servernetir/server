<?php

namespace Tests\Feature;

use App\Services\Domain\DomainSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * منوی دامنه و بارگذاریِ تدریجیِ پسوندها.
 *
 * 🔴 دو خرابیِ واقعی که این تست‌ها می‌بندند:
 *   ۱) منوی «دامنه» هیچ لینکی به جستجوی واقعی نداشت — فقط صفحاتِ بازاریابی.
 *   ۲) همان صفحات قیمتِ **سخت‌کد** نشان می‌دادند: `.com` را ۱٬۲۹۰٬۰۰۰ در حالی
 *      که استعلامِ زنده ۲٬۰۱۶٬۰۰۰ بود. مشتری قیمتی می‌دید که نمی‌توانست بخرد.
 */
class DomainMenuAndBatchTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════ منو ═══════════════

    public function test_the_products_menu_links_to_the_real_domain_search(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(route('domain.search'), $html,
            'منوی دامنه باید به جستجوی واقعی برود، نه فقط صفحاتِ بازاریابی');
    }

    // ═══════════════ قیمتِ صفحاتِ دامنه ═══════════════

    /** 🔴 عددِ سخت‌کد نباید روی صفحهٔ دامنه بیاید */
    public function test_domain_catalog_pages_do_not_show_a_hardcoded_price(): void
    {
        $html = $this->get('/domain/popular-tlds')->assertOk()->getContent();

        // ۱٬۲۹۰٬۰۰۰ عددی است که واقعاً در config نشسته بود
        $this->assertStringNotContainsString('۱٬۲۹۰٬۰۰۰', $html);
        $this->assertStringNotContainsString('1,290,000', $html);
        $this->assertStringContainsString(__('ui.dsr_quote_price'), $html);
    }

    /** و دکمه باید به جستجو برود، جایی که قیمتِ واقعی می‌آید */
    public function test_domain_catalog_pages_send_the_visitor_to_the_live_search(): void
    {
        $html = $this->get('/domain/popular-tlds')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.dsr_check_btn'), $html);
        $this->assertStringContainsString(route('domain.search'), $html);
    }

    /** ⚠️ صفحاتِ سرور نباید قربانیِ این تغییر شوند */
    public function test_server_pages_still_show_their_live_prices(): void
    {
        $this->get('/vps/germany')->assertOk();
        $this->get('/hosting/linux')->assertOk();
    }

    // ═══════════════ بارگذاریِ تدریجی ═══════════════

    /**
     * 🔴 اندازهٔ دسته باید از سقفِ اعتبارسنجیِ روت کمتر باشد.
     *
     * روت `tlds` را حداکثر ۱۲ می‌پذیرد. دستهٔ بزرگ‌تر یعنی **هر** درخواست با
     * خطای اعتبارسنجی برمی‌گردد و کاربر هیچ نتیجه‌ای نمی‌بیند — خرابی‌ای که
     * فقط در مرورگر پیداست، نه در تستِ سرور.
     */
    public function test_the_batch_size_fits_the_api_validation_limit(): void
    {
        $this->assertLessThanOrEqual(12, DomainSearch::BATCH);

        foreach (DomainSearch::restBatches() as $batch) {
            $this->assertLessThanOrEqual(12, count($batch));
        }
        $this->assertLessThanOrEqual(12, count(DomainSearch::firstBatch()));
    }

    /** هیچ پسوندی نباید بین دسته‌ها گم شود */
    public function test_batching_loses_no_tld(): void
    {
        $all = (new \ReflectionClass(DomainSearch::class))->getConstant('SUGGEST_TLDS');

        $batched = array_merge(
            DomainSearch::firstBatch(),
            ...DomainSearch::restBatches()
        );

        $this->assertSame($all, $batched);
    }

    /** دستهٔ اول باید پرتقاضاترین‌ها باشد — کاربر اول اینها را می‌بیند */
    public function test_the_first_batch_leads_with_the_popular_tlds(): void
    {
        $first = DomainSearch::firstBatch();

        $this->assertSame('com', $first[0]);
        $this->assertContains('net', $first);
        $this->assertContains('org', $first);
    }

    /** صفحهٔ جستجو باید دسته‌ها را به جاوااسکریپت بدهد */
    public function test_the_search_page_ships_the_batches_to_the_browser(): void
    {
        $html = $this->get('/domains')->assertOk()->getContent();

        $this->assertStringContainsString('tld_first', $html);
        $this->assertStringContainsString('tld_rest', $html);
        $this->assertStringContainsString('id="dm-more"', $html);
    }
}
