<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\DomainQuote;
use App\Services\Domain\DomainSearch;
use App\Services\Domain\OpenProviderClient;
use App\Services\ExchangeRate;
use Database\Seeders\BillingFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingFoundationSeeder::class);

        config([
            'services.openprovider.username' => 'u',
            'services.openprovider.password' => 'p',
            'services.openprovider.margin'   => ['default' => 25],
        ]);

        // نرخ دلار ثابت برای تست: ۱۰۰٬۰۰۰ تومان
        Cache::put('fx.usd_irt', [
            'currency' => 'USD', 'rate_toman' => 100000,
            'source' => 'test', 'at' => now()->toIso8601String(),
        ], now()->addHour());
    }

    private function fakeOp(array $results): void
    {
        Http::fake([
            '*/auth/login'     => Http::response(['code' => 0, 'data' => ['token' => 'tok']], 200),
            '*/domains/check'  => Http::response(['code' => 0, 'data' => ['results' => $results]], 200),
        ]);
    }

    public function test_available_domain_gets_price_in_toman_with_margin(): void
    {
        $this->fakeOp([[
            'domain' => 'example.com', 'status' => 'free',
            'price' => ['reseller' => ['price' => 10.0, 'currency' => 'USD']],
        ]]);

        $out = app(DomainSearch::class)->search('example.com', ['com']);

        $this->assertCount(1, $out);
        $r = $out[0];

        $this->assertTrue($r['available']);
        $this->assertTrue($r['orderable']);
        $this->assertSame('available', $r['status']);

        // ۱۰ دلار × ۱۰۰٬۰۰۰ = ۱٬۰۰۰٬۰۰۰ تومان، +۲۵٪ سود = ۱٬۲۵۰٬۰۰۰
        $this->assertSame(1_250_000, $r['price_toman']);
    }

    public function test_taken_domain_is_not_orderable_and_has_no_price(): void
    {
        $this->fakeOp([[
            'domain' => 'google.com', 'status' => 'active',
        ]]);

        $r = app(DomainSearch::class)->search('google.com', ['com'])[0];

        $this->assertFalse($r['available']);
        $this->assertFalse($r['orderable']);
        $this->assertNull($r['price_toman']);
        $this->assertSame('unavailable', $r['status']);
    }

    public function test_premium_domain_is_flagged_and_uses_its_own_price(): void
    {
        // پرمیوم: قیمت خیلی بالاتر از استاندارد، و فقط از پاسخ check می‌آید
        $this->fakeOp([[
            'domain' => 'cars.com', 'status' => 'free', 'is_premium' => true,
            'price' => ['reseller' => ['price' => 2000.0, 'currency' => 'USD']],
        ]]);

        $r = app(DomainSearch::class)->search('cars.com', ['com'])[0];

        $this->assertTrue($r['is_premium']);
        $this->assertSame('premium', $r['status']);
        // ۲۰۰۰ × ۱۰۰٬۰۰۰ × ۱٫۲۵ = ۲۵۰٬۰۰۰٬۰۰۰ — نه قیمت استاندارد
        $this->assertSame(250_000_000, $r['price_toman']);
    }

    public function test_no_price_from_registrar_means_no_price_shown(): void
    {
        // آزاد است ولی رسیلری قیمت نداد → نباید عددی از خودمان بسازیم
        $this->fakeOp([[
            'domain' => 'weird.com', 'status' => 'free',
        ]]);

        $r = app(DomainSearch::class)->search('weird.com', ['com'])[0];

        $this->assertTrue($r['available']);
        $this->assertFalse($r['orderable']);
        $this->assertNull($r['price_toman']);
        $this->assertSame('no_price', $r['reason']);
    }

    public function test_missing_exchange_rate_blocks_the_price(): void
    {
        Cache::forget('fx.usd_irt');
        // جلوی دریافت زندهٔ نرخ را هم بگیر
        Http::fake([
            '*/auth/login'    => Http::response(['code' => 0, 'data' => ['token' => 'tok']], 200),
            '*/domains/check' => Http::response(['code' => 0, 'data' => ['results' => [[
                'domain' => 'example.com', 'status' => 'free',
                'price' => ['reseller' => ['price' => 10.0, 'currency' => 'USD']],
            ]]]], 200),
            'alanchand.com/*' => Http::response('بدون قیمت', 200),
        ]);

        $r = app(DomainSearch::class)->search('example.com', ['com'])[0];

        // بدون نرخ ارز، قیمتی که نمی‌توانیم پایش بایستیم نشان نمی‌دهیم
        $this->assertFalse($r['orderable']);
        $this->assertSame('fx_unavailable', $r['reason']);
    }

    public function test_every_shown_price_is_backed_by_a_stored_quote(): void
    {
        $this->fakeOp([[
            'domain' => 'example.com', 'status' => 'free',
            'price' => ['reseller' => ['price' => 10.0, 'currency' => 'USD']],
        ]]);

        $r = app(DomainSearch::class)->search('example.com', ['com'])[0];

        $quote = DomainQuote::find($r['quote_id']);
        $this->assertNotNull($quote);
        $this->assertSame($r['price_toman'], (int) $quote->sell_toman);
        $this->assertTrue($quote->isHonourable());
        $this->assertSame('openprovider', $quote->registrar);
    }

    public function test_query_is_normalised(): void
    {
        $this->fakeOp([]);
        $svc = app(DomainSearch::class);

        // همهٔ این‌ها باید به example.com برسند
        foreach (['https://example.com/path', '  EXAMPLE.com  ', 'example.com?x=1'] as $q) {
            $out = $svc->search($q, []);
            $this->assertSame('example.com', $out[0]['domain'], "ورودی: $q");
        }
    }

    public function test_client_treats_code_not_http_status_as_the_error(): void
    {
        // این API روی خطای احراز هویت هم HTTP 500 می‌دهد — نباید موفق تلقی شود
        Http::fake([
            '*/auth/login' => Http::response(['code' => 196, 'desc' => 'Authentication/Authorization Failed'], 500),
        ]);

        $this->assertNull(app(OpenProviderClient::class)->token(true));
    }
}
