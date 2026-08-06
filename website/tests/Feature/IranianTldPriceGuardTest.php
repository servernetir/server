<?php

namespace Tests\Feature;

use App\Services\Domain\TldPriceBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 پسوندهای ایرانی هرگز از رسیلرِ اروپایی قیمت نمی‌گیرند.
 *
 * روی سایتِ زنده `/domain/ir` قیمتِ «۱۳٬۴۵۰٬۰۰۰ تومان» نشان می‌داد، در برابرِ
 * قیمتِ واقعیِ ایرنیک که حدودِ ۱۷۰٬۰۰۰ تومان است — تقریباً ۸۰ برابر.
 *
 * `DomainSearch::SUGGEST_TLDS` این را از اول می‌دانست و `.ir` را کنار گذاشته
 * بود، ولی آن محافظ فقط در جستجو بود نه در کاتالوگ. این تست هر دو در را می‌بندد.
 */
class IranianTldPriceGuardTest extends TestCase
{
    use RefreshDatabase;

    private function fakeRegistrar(): void
    {
        Http::swap(new Factory);
        Http::fake([
            '*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'T']], 200),
            '*/domains/check*' => Http::response(['code' => 0, 'data' => ['results' => [
                ['domain' => 'sn7price9check4base.ir', 'status' => 'free',
                    'price' => ['reseller' => ['price' => 65.0, 'currency' => 'EUR']]],
                ['domain' => 'sn7price9check4base.com', 'status' => 'free',
                    'price' => ['reseller' => ['price' => 10.0, 'currency' => 'EUR']]],
            ]]], 200),
            '*' => Http::response(['code' => 0, 'data' => []], 200),
        ]);

        config([
            'services.openprovider.username' => 'u',
            'services.openprovider.password' => 'p',
            'services.openprovider.base_url' => 'https://api.example.test/v1beta',
        ]);
        \App\Models\Setting::put('pricing_rate_override', '100000');
    }

    /** 🔴 حتی اگر رجیسترار قیمت بدهد، ما نشانش نمی‌دهیم */
    public function test_ir_never_gets_a_european_price(): void
    {
        $this->fakeRegistrar();

        $prices = app(TldPriceBook::class)->forTlds(['ir', 'com']);

        $this->assertArrayNotHasKey('ir', $prices, '.ir قیمتِ رسیلرِ اروپایی گرفت');
        $this->assertArrayHasKey('com', $prices, '.com باید همچنان قیمت بگیرد');
    }

    /** همهٔ زیردامنه‌های ایرانی، نه فقط خودِ .ir */
    public function test_every_iranian_tld_is_excluded(): void
    {
        $this->fakeRegistrar();

        $prices = app(TldPriceBook::class)->forTlds(['ir', 'co.ir', 'ac.ir', 'org.ir', 'ایران']);

        $this->assertSame([], $prices);
    }

    /** ⚠️ فهرستِ فقط-ایرانی نباید اصلاً به رجیسترار برود */
    public function test_an_all_iranian_list_makes_no_registrar_call(): void
    {
        $this->fakeRegistrar();

        app(TldPriceBook::class)->forTlds(['ir', 'co.ir']);

        Http::assertNothingSent();
    }

    /** و صفحهٔ /domain/ir نباید عددِ نجومی نشان دهد */
    public function test_the_ir_catalog_page_shows_no_european_price(): void
    {
        $this->fakeRegistrar();

        $html = $this->get('/domain/ir')->assertOk()->getContent();

        $this->assertStringNotContainsString('۱۳٬۴۵۰٬۰۰۰', $html);
        $this->assertStringNotContainsString('6,500,000', $html);
    }
}
