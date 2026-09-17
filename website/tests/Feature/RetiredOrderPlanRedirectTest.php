<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحهٔ سفارشِ پلنِ بازنشسته → ۳۰۱ به صفحهٔ همان خانوادهٔ محصول.
 *
 * Search Console (۱۶ سپتامبر ۲۰۲۶) `/en/order/download-2` و
 * `/en/order/backup-2` را زیرِ «Not found (404)» نشان داد: پلن‌هایی که از
 * فروش برداشته شده‌اند ولی نشانی‌شان در تاریخچهٔ خزش و لینک‌های بیرونی
 * زنده است. ۴۰۴ هم اعتبارِ آن نشانی را دور می‌ریزد، هم بازدیدکننده را
 * بی‌راه رها می‌کند.
 *
 * نیمی از ادعاها عمداً **منفی**اند: ۳۰۱ِ کورِ هر اسلاگِ ناشناخته به یک
 * صفحه را گوگل «soft 404» می‌خوانَد — بدتر از ۴۰۴ِ صادقانه.
 */
class RetiredOrderPlanRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_inactive_plan_redirects_to_its_family_page(): void
    {
        Product::create([
            'name' => 'بکاپ ۲', 'slug' => 'backup-2', 'group' => 'backup', 'category' => 'shared',
            'price' => 500000, 'price_eur' => 0, 'setup_fee' => 0, 'cycle' => 'monthly', 'tax_percent' => 10,
            'is_active' => false,
        ]);

        $this->get('/order/backup-2')->assertStatus(301)->assertRedirect(url('/hosting/backup'));
    }

    /** پلنی که اصلاً دیگر ردیفی ندارد هم همان مسیر را می‌گیرد — و در زبانِ خودش. */
    public function test_a_deleted_plan_redirects_within_its_own_language(): void
    {
        $this->get('/en/order/download-4')->assertStatus(301)->assertRedirect(url('/en/hosting/download'));
        $this->get('/tr/order/reseller-wordpress-3')->assertStatus(301)->assertRedirect(url('/tr/hosting/reseller-wordpress'));
    }

    public function test_an_active_plan_is_still_served_not_redirected(): void
    {
        Product::create([
            'name' => 'بکاپ ۳', 'slug' => 'backup-3', 'group' => 'backup', 'category' => 'shared',
            'price' => 500000, 'price_eur' => 0, 'setup_fee' => 0, 'cycle' => 'monthly', 'tax_percent' => 10,
            'is_active' => true,
        ]);

        $this->get('/order/backup-3')->assertOk();
    }

    /** اسلاگی که به هیچ خانوادهٔ واقعی نمی‌خورد، ۴۰۴ِ صادقانه می‌ماند. */
    public function test_an_unknown_family_stays_a_404(): void
    {
        foreach (['/order/not-a-family-2', '/order/license-cpanel', '/order/backup', '/order/wp-admin-1'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }
}
