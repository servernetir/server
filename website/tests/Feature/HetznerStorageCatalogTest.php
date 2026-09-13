<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 بهای تمام‌شده = قیمتِ هتزنر + **سربارِ رساندنِ پول به او**.
 *
 * ═══ باگی که این تست برای آن نوشته شد ═══
 *
 * نسخهٔ اولِ `hetzner:storage-catalog` فقط قیمتِ خامِ API را بهای تمام‌شده
 * می‌گرفت و `CloudPricing::costWithFee()` را صدا نمی‌زد. نتیجه یک جدولِ کاملاً
 * معتبر‌به‌نظر بود که بهای هر باکس را **۱۰٪ کمتر** نشان می‌داد — و آن جدول
 * دقیقاً همان چیزی است که آدم بر اساسش قیمتِ فروش می‌گذارد.
 *
 * خرابی‌اش خاموش است: تحویل موفق می‌شود، مشتری راضی است، و ضرر فقط در
 * صورت‌حسابِ ماهانهٔ زیرساخت پیدا می‌شود — ماه‌ها بعد.
 *
 * ⚠️ `HetznerClient` (سرورِ مجازی) از همان اول درست می‌زدش. یعنی الگوی درست در
 * همان پوشه موجود بود و باز هم جا افتاد — پس تستِ مستقل لازم است، نه اتکا به
 * «همان‌طور که آن‌یکی کرده».
 */
class HetznerStorageCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalog_adds_the_hetzner_fx_fee_to_the_cost(): void
    {
        Server::create([
            'name' => 'HZ-STORAGE-01', 'type' => 'hetzner_storage',
            'hostname' => 'api.hetzner.com', 'username' => '', 'api_token' => 'tok',
            'status' => 'active', 'verify_tls' => true,
        ]);

        // عددهای گرد تا حساب با چشم قابلِ دنبال‌کردن باشد
        Setting::put('cloud_margin_pct', '0');          // حاشیه صفر ⇒ فقط اثرِ سربار دیده شود
        Setting::put('pricing_fx_fee_pct_hetzner', '10');
        Setting::put('pricing_rate_override', '100000'); // ۱ € = ۱۰۰٬۰۰۰ ت

        Http::fake(['api.hetzner.com/*' => Http::response([
            'storage_box_types' => [[
                'name' => 'bx11',
                'size' => 1099511627776,
                'subaccounts_limit' => 100,
                'prices' => [[
                    'location' => 'fsn1',
                    'price_monthly' => ['net' => '10.0000', 'gross' => '11.9000'],
                ]],
            ]],
        ], 200)]);

        $this->artisan('hetzner:storage-catalog', ['--location' => 'fsn1'])
            ->assertSuccessful()
            // ۱۰ € + ۱۰٪ سربار = ۱۱ € ⇒ ۱٬۱۰۰٬۰۰۰ تومان
            ->expectsOutputToContain('1,100,000')
            ->run();

        /*
        | ⚠️ ادعای منفی این‌جا مهم‌تر از ادعای مثبت است: ۱٬۰۰۰٬۰۰۰ همان عددی
        | است که نسخهٔ باگ‌دار چاپ می‌کرد و کاملاً موجه به‌نظر می‌رسید.
        */
        $this->artisan('hetzner:storage-catalog', ['--location' => 'fsn1'])
            ->doesntExpectOutputToContain('1,000,000')
            ->run();
    }

    /**
     * `gross` قیمتِ باVATِ آلمان است و بهای ما نیست — مالیاتِ فروشِ ایران جدا
     * و داده‌محور در `tax_rates` حساب می‌شود. خواندنِ gross یعنی دوبار مالیات.
     */
    public function test_it_reads_the_net_price_not_the_german_vat_inclusive_one(): void
    {
        Server::create([
            'name' => 'HZ-STORAGE-01', 'type' => 'hetzner_storage',
            'hostname' => 'api.hetzner.com', 'username' => '', 'api_token' => 'tok',
            'status' => 'active', 'verify_tls' => true,
        ]);

        Setting::put('cloud_margin_pct', '0');
        Setting::put('pricing_fx_fee_pct_hetzner', '0');
        Setting::put('pricing_rate_override', '100000');

        Http::fake(['api.hetzner.com/*' => Http::response([
            'storage_box_types' => [[
                'name' => 'bx11', 'size' => 1099511627776, 'subaccounts_limit' => 100,
                'prices' => [[
                    'location' => 'fsn1',
                    'price_monthly' => ['net' => '10.0000', 'gross' => '11.9000'],
                ]],
            ]],
        ], 200)]);

        $this->artisan('hetzner:storage-catalog', ['--location' => 'fsn1'])
            ->assertSuccessful()
            ->expectsOutputToContain('1,000,000')   // ۱۰ € خالص
            ->doesntExpectOutputToContain('1,190,000')
            ->run();
    }
}
