<?php

namespace Tests\Feature;

use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Payment\CryptoIssuer;
use App\Services\Payment\CryptoPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * قیمت‌گذاریِ پرداختِ رمزارز — جایی که یک عددِ غلط مستقیماً پول است.
 *
 * ⚠️ اگر نرخ **بالاتر** از واقعیت باشد، مقدارِ خواسته‌شده کم می‌شود و سرویس را
 * ارزان فروخته‌ایم؛ اگر **پایین‌تر** باشد، مشتری بیشتر می‌دهد. هیچ‌کدام خطا
 * تولید نمی‌کند و هیچ‌کدام هم دیده نمی‌شود. پس قاعده یکی است: **بی‌قیمتِ
 * مطمئن، صادر نکن.**
 */
class CryptoPricingTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'p'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);
    }

    private function invoice(string $currency, int $total): Invoice
    {
        return Invoice::create([
            'customer_id' => $this->customer()->id,
            'number' => 'INV-'.random_int(10000, 99999),
            'currency_code' => $currency,
            'subtotal' => $total, 'tax' => 0, 'total' => $total, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(), 'due_at' => now()->addDays(7),
        ]);
    }

    private function wallet(): CryptoWallet
    {
        return CryptoWallet::create([
            'chain' => 'tron', 'address' => 'TW'.substr(md5((string) random_int(1, 1e9)), 0, 32), 'is_active' => true,
        ]);
    }

    private function fx(string $currency, int $toman): void
    {
        Cache::put('fx.'.strtolower($currency).'_irt', [
            'currency' => $currency, 'rate_toman' => $toman,
            'source' => 'test', 'at' => now()->toIso8601String(),
        ], now()->addHour());
    }

    private function issue(Invoice $inv, string $asset): ?CryptoPayment
    {
        return app(CryptoIssuer::class)->issue($inv, $asset);
    }

    // ══════════════ نرخ برای هر دو ارزِ فاکتور ══════════════

    /**
     * 🔴 هر دو ارز از **یک** قیف رد می‌شوند: دارایی → تومان → ارزِ فاکتور.
     *
     * نسخهٔ قبلی برای یورو مسیرِ جداگانه‌ای داشت (دلار→تومان→یورو با یک وابستگیِ
     * اضافه) و برای TRX هیچ مسیری. یک قیف یعنی یک جای اشتباه، نه چهار تا.
     */
    public function test_the_rate_is_correct_for_both_toman_and_euro_invoices(): void
    {
        $this->fx('USD', 100_000);
        $this->fx('EUR', 200_000);          // ۱ یورو = ۲ دلار (عمداً گرد، تا حساب دیده شود)
        Cache::put('cyprice.trx_usd', 0.25, now()->addHour());

        $cases = [
            // [ارز, مبلغِ فاکتور, دارایی, نرخِ انتظار ×1e6, مقدارِ اتمیِ انتظار]

            // ۱٬۵۰۰٬۰۰۰ تومان ÷ ۱۰۰٬۰۰۰ تومان بر تتر = ۱۵ تتر
            ['IRT', 1_500_000, 'USDT', 100_000 * 1_000_000, 15_000_000],

            // ۱٬۵۰۰٬۰۰۰ ÷ (۰٫۲۵ × ۱۰۰٬۰۰۰ = ۲۵٬۰۰۰) = ۶۰ TRX
            ['IRT', 1_500_000, 'TRX', 25_000 * 1_000_000, 60_000_000],

            // €۱۰۰: یک تتر = ۱۰۰٬۰۰۰ ÷ ۲۰۰٬۰۰۰ = ۰٫۵ یورو ⇒ ۲۰۰ تتر
            ['EUR', 10_000, 'USDT', 500_000, 200_000_000],

            // €۱۰۰: یک TRX = ۲۵٬۰۰۰ ÷ ۲۰۰٬۰۰۰ = ۰٫۱۲۵ یورو ⇒ ۸۰۰ TRX
            ['EUR', 10_000, 'TRX', 125_000, 800_000_000],
        ];

        // ⚠️ حلقهٔ درون‌تستی، نه dataProvider — PHPUnit 12.5.31 آن انوتیشن را
        //    بی‌صدا نادیده می‌گیرد و تست هرگز اجرا نمی‌شود.
        foreach ($cases as [$currency, $total, $asset, $rate, $atomic]) {
            $this->wallet();
            $inv = $this->invoice($currency, $total);

            $cp = $this->issue($inv, $asset);

            $this->assertNotNull($cp, "{$asset}/{$currency} باید صادر شود");
            $this->assertSame($rate, $cp->rate_micro, "نرخِ {$asset} برای فاکتورِ {$currency}");
            $this->assertSame($atomic, $cp->amount_atomic, "مقدارِ {$asset} برای فاکتورِ {$currency}");
            $this->assertSame($currency, $cp->invoice_currency);
        }
    }

    /** ⚠️ گردکردن همیشه رو به **بالا** — کسرِ ریز نباید به ضررِ ما بیفتد */
    public function test_the_asset_amount_always_rounds_up(): void
    {
        $this->fx('USD', 30_000);
        $this->wallet();

        // ۱٬۰۰۰٬۰۰۰ ÷ ۳۰٬۰۰۰ = ۳۳٫۳۳۳… تتر
        $cp = $this->issue($this->invoice('IRT', 1_000_000), 'USDT');

        $this->assertNotNull($cp);
        $this->assertSame(33_333_334, $cp->amount_atomic, 'باید رو به بالا گرد شود');
    }

    /** ارزی که نرخِ زنده‌اش را نداریم ⇒ هیچ صدوری */
    public function test_an_invoice_in_a_currency_we_cannot_price_is_never_issued(): void
    {
        $this->fx('USD', 100_000);
        $this->wallet();

        $this->assertNull($this->issue($this->invoice('GBP', 10_000), 'USDT'),
            'بی‌نرخِ پوند نباید مبلغی حدس زده شود');
        $this->assertSame(0, CryptoPayment::count());
    }

    // ══════════════ منبعِ قیمتِ TRX ══════════════

    /** دو منبعِ هم‌داستان ⇒ قیمت پذیرفته و ذخیره می‌شود */
    public function test_two_agreeing_sources_produce_a_price(): void
    {
        $this->fakePrices(coinbase: '0.2500', kraken: '0.2510');

        $p = app(CryptoPrice::class)->refresh('TRX');

        $this->assertNotNull($p);
        // ⚠️ کمینه، نه میانگین: اگر باید خطا کنیم، به سمتی که مشتری بیشتر بفرستد
        $this->assertSame(0.25, $p);
        $this->assertSame(0.25, app(CryptoPrice::class)->cachedUsd('TRX'));
    }

    /** 🔴 یک منبعِ تنها کافی نیست — ممکن است صفحهٔ خطا با کدِ ۲۰۰ باشد */
    public function test_a_single_surviving_source_is_refused(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'api.coinbase.com/*' => Http::response(['data' => ['amount' => '0.2500']], 200),
            'api.kraken.com/*' => Http::response('down', 503),
            'api.coingecko.com/*' => Http::response('down', 503),
        ]);

        $this->assertNull(app(CryptoPrice::class)->refresh('TRX'));
        $this->assertNull(app(CryptoPrice::class)->cachedUsd('TRX'), 'هیچ‌چیز نباید ذخیره شده باشد');
    }

    /** 🔴 دو منبعِ ناهم‌داستان یعنی «نمی‌دانیم» — و نمی‌دانیم یعنی عرضه نکن */
    public function test_disagreeing_sources_are_refused(): void
    {
        $this->fakePrices(coinbase: '0.2500', kraken: '0.3200');   // ~۲۸٪ اختلاف

        $this->assertNull(app(CryptoPrice::class)->refresh('TRX'));
        $this->assertNull(app(CryptoPrice::class)->cachedUsd('TRX'));
    }

    /** پاسخِ درست‌شکل ولی بی‌معنا از بازهٔ عاقلانه بیرون می‌افتد */
    public function test_an_absurd_quote_is_thrown_away_even_if_two_sources_return_one(): void
    {
        // هر دو ۹۹۹ دلار می‌گویند — هم‌داستان‌اند ولی بی‌معنا
        $this->fakePrices(coinbase: '999', kraken: '999');

        $this->assertNull(app(CryptoPrice::class)->refresh('TRX'),
            'هم‌داستانیِ دو منبعِ غلط، غلط را درست نمی‌کند');
    }

    /**
     * 🔴 مسیرِ رندرِ صفحه **هرگز** به بیرون وصل نمی‌شود.
     *
     * وگرنه یک منبعِ کُند، صفحهٔ فاکتورِ مشتری را ده‌ها ثانیه معلق می‌کرد —
     * دقیقاً وسطِ پرداخت.
     */
    public function test_rendering_an_invoice_never_calls_a_price_api(): void
    {
        $this->fx('USD', 100_000);
        $this->wallet();

        Http::swap(new Factory);
        Http::fake();

        $c = $this->customer();
        $inv = Invoice::create([
            'customer_id' => $c->id, 'number' => 'INV-'.random_int(10000, 99999),
            'currency_code' => 'IRT', 'subtotal' => 1_000_000, 'tax' => 0,
            'total' => 1_000_000, 'paid' => 0, 'status' => 'unpaid',
            'issued_at' => now(), 'due_at' => now()->addDays(7),
        ]);

        $this->actingAs($c, 'customer')->get('/account/invoices/'.$inv->id)->assertOk();

        Http::assertNothingSent();
    }

    /** ⚠️ کرون نباید از قطعیِ منبعِ قیمت بمیرد */
    public function test_the_cron_survives_a_total_price_outage(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->artisan('crypto:watch')->assertSuccessful();

        $this->assertNull(app(CryptoPrice::class)->cachedUsd('TRX'));
    }

    /** قیمتِ گرم دوباره گرفته نمی‌شود — کرونِ هر دقیقه نباید هر دقیقه بیرون بزند */
    public function test_warm_does_not_refetch_a_fresh_price(): void
    {
        Cache::put('cyprice.trx_usd', 0.25, now()->addHour());

        Http::swap(new Factory);
        Http::fake();

        app(CryptoPrice::class)->warm();

        Http::assertNothingSent();
    }

    /**
     * ⚠️ `Http::fake` را با Factory تازه ثبت می‌کنیم — «اولین تطبیق برنده» است
     * و یک استابِ همه‌گیرِ فیکسچرِ دیگر، این را بی‌اثر می‌کند.
     */
    private function fakePrices(string $coinbase, string $kraken): void
    {
        Http::swap(new Factory);

        Http::fake([
            'api.coinbase.com/*' => Http::response(['data' => [
                'base' => 'TRX', 'currency' => 'USD', 'amount' => $coinbase,
            ]], 200),

            // ⚠️ نامِ جفت‌ارزِ کراکن عمداً «XTRXZUSD» است، نه «TRXUSD»:
            //    کد نباید به نامِ ثابت تکیه کند.
            'api.kraken.com/*' => Http::response([
                'error' => [], 'result' => ['XTRXZUSD' => ['c' => [$kraken, '1000']]],
            ], 200),

            'api.coingecko.com/*' => Http::response(['tron' => ['usd' => 0.25]], 200),
        ]);
    }
}
