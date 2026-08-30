<?php

namespace Tests\Feature;

use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\ExchangeRate;
use App\Services\Payment\CryptoIssuer;
use App\Services\Payment\CryptoReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * دو چیزی که مشتری را زمین می‌زند: «نمی‌توانم پرداخت کنم» و «پولم جای اشتباه رفت».
 *
 * ═══ ۱) در دسترس بودن ═══
 *
 * 🔴 کارفرما دید: «۵ ولت آزاد است ولی پرداختِ رمزارز برای مشتری غیرفعال».
 * علت ولت نبود — نرخِ دلار فقط در **کش** (۶ ساعت) زندگی می‌کرد و تنها
 * منبعش اسکرپِ یک سایتِ ایرانی بود. آن صفحه که پارس نشود یا قطع باشد، کش سرد
 * می‌شود و `offers()` هر دو دارایی را `busy` می‌کند: درگاهِ اصلیِ مشتریِ
 * خارجی، گروگانِ یک نقطهٔ شکستِ تکی.
 *
 * ═══ ۲) تطبیق ═══
 *
 * 🔴 آدرس‌ها بازاستفاده می‌شوند و تنها چیزی که واریزی را به «کدام پرداخت» گره
 * می‌زد، مبلغ بود — و دو فاکتورِ هم‌مبلغ، مبلغِ اتمیِ یکسان می‌گرفتند.
 */
class CryptoAvailabilityAndMatchingTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(int $total = 10000): Invoice
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'av'.random_int(1, 999999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        return Invoice::create([
            'customer_id' => $c->id, 'number' => 'INV-'.random_int(10000, 99999),
            'currency_code' => 'EUR', 'subtotal' => $total, 'tax' => 0, 'total' => $total,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(), 'due_at' => now()->addDays(7),
        ]);
    }

    private function wallets(int $n = 3): void
    {
        for ($i = 0; $i < $n; $i++) {
            CryptoWallet::create([
                'chain' => 'tron', 'address' => 'TPool'.$i.random_int(100, 999), 'is_active' => true,
            ]);
        }
    }

    // ─────────────────── ۱) در دسترس بودن ───────────────────

    /**
     * 🔴 کشِ سرد نباید درگاه را بکُشد وقتی نرخِ پایدارِ تازه‌ای داریم.
     *
     * این دقیقاً همان چیزی است که کارفرما دید: استخرِ سالم، درگاهِ خاموش.
     */
    public function test_a_cold_cache_falls_back_to_the_last_known_rate(): void
    {
        $this->wallets();
        Cache::flush();                                   // هیچ نرخی در کش نیست

        // آخرین نرخِ موفق، از دیروز
        Setting::put('fx_last_usd', json_encode([
            'rate_toman' => 900_000, 'at' => now()->subHours(20)->toIso8601String(), 'source' => 'test',
        ]));
        Setting::put('fx_last_eur', json_encode([
            'rate_toman' => 1_000_000, 'at' => now()->subHours(20)->toIso8601String(), 'source' => 'test',
        ]));

        $states = array_map(fn ($a) => $a['state'], app(CryptoIssuer::class)->offers('EUR'));

        $this->assertSame(CryptoIssuer::READY, $states['USDT'] ?? null,
            'با نرخِ پایدارِ تازه، درگاه باید در دسترس باشد — مشتری نمی‌تواند پرداخت کند');
    }

    /**
     * ⚠️ ولی نرخِ **خیلی کهنه** پشتوانه نیست: فروش با نرخِ یک‌هفته‌ای ضرر است.
     *
     * بی‌این مرز، رفعِ بالا خودش یک راهِ ارزان‌فروشیِ دائمی می‌شد.
     */
    public function test_a_very_stale_rate_is_not_used(): void
    {
        $this->wallets();
        Cache::flush();

        Setting::put('fx_last_usd', json_encode([
            'rate_toman' => 900_000, 'at' => now()->subDays(7)->toIso8601String(), 'source' => 'test',
        ]));

        $this->assertNull(app(ExchangeRate::class)->current('USD'),
            'نرخِ یک‌هفته‌ای نباید به‌عنوان نرخِ معتبر برگردد');
    }

    /** نرخِ پایدار هم از همان بازهٔ اعتبارِ مسیرِ اصلی رد می‌شود. */
    public function test_an_out_of_range_stored_rate_is_ignored(): void
    {
        Cache::flush();
        Setting::put('fx_last_usd', json_encode([
            'rate_toman' => 3, 'at' => now()->toIso8601String(), 'source' => 'test',
        ]));

        $this->assertNull(app(ExchangeRate::class)->current('USD'));
    }

    // ─────────────────── ۲) تطبیق ───────────────────

    /**
     * 🔴 دو فاکتورِ هم‌مبلغ نباید مبلغِ اتمیِ یکسان بگیرند.
     *
     * وگرنه واریزیِ یکی می‌تواند فاکتورِ دیگری را تسویه کند — و هیچ گاردی
     * نمی‌فهمد، چون از دیدِ زنجیره هر دو «همان مبلغ» را خواسته‌اند.
     */
    public function test_two_identical_invoices_get_different_atomic_amounts(): void
    {
        $this->wallets();
        Cache::flush();
        Setting::put('fx_last_usd', json_encode([
            'rate_toman' => 900_000, 'at' => now()->toIso8601String(), 'source' => 'test',
        ]));
        Setting::put('fx_last_eur', json_encode([
            'rate_toman' => 1_000_000, 'at' => now()->toIso8601String(), 'source' => 'test',
        ]));

        $issuer = app(CryptoIssuer::class);

        $a = $issuer->issue($this->invoice(10000), 'USDT');
        $b = $issuer->issue($this->invoice(10000), 'USDT');

        $this->assertNotNull($a, 'پرداختِ اول صادر نشد');
        $this->assertNotNull($b, 'پرداختِ دوم صادر نشد');
        $this->assertNotSame((int) $a->amount_atomic, (int) $b->amount_atomic,
            'دو فاکتورِ هم‌مبلغ، مبلغِ یکسان گرفتند — واریزیِ یکی می‌تواند دیگری را تسویه کند');
    }

    /** دمِ یکتا باید **ناچیز** باشد: کمتر از یک هزارمِ واحد. */
    public function test_the_uniqueness_tail_stays_negligible(): void
    {
        $this->wallets();
        Cache::flush();
        Setting::put('fx_last_usd', json_encode([
            'rate_toman' => 900_000, 'at' => now()->toIso8601String(), 'source' => 'test',
        ]));
        Setting::put('fx_last_eur', json_encode([
            'rate_toman' => 1_000_000, 'at' => now()->toIso8601String(), 'source' => 'test',
        ]));

        $issuer = app(CryptoIssuer::class);
        $a = $issuer->issue($this->invoice(10000), 'USDT');
        $b = $issuer->issue($this->invoice(10000), 'USDT');

        $this->assertLessThan(1000, abs((int) $a->amount_atomic - (int) $b->amount_atomic),
            'دم بیش از ۹۹۹ واحدِ اتمی است — مشتری تفاوت را می‌بیند');
    }

    /**
     * 🔴 واریزیِ با مبلغِ **کاملاً متفاوت** نباید این پرداخت را تسویه کند.
     *
     * سناریو: واریزیِ دیرهنگامِ مشتریِ قبلی روی آدرسِ بازاستفاده‌شده، که
     * زمانش بعد از ساختِ پرداختِ تازه است (پس گاردِ زمانی نمی‌گیردش).
     */
    public function test_a_deposit_with_a_foreign_amount_goes_to_manual_not_settled(): void
    {
        $inv = $this->invoice();
        $w = CryptoWallet::create(['chain' => 'tron', 'address' => 'TMatch01', 'is_active' => true]);

        $cp = CryptoPayment::create([
            'invoice_id' => $inv->id, 'customer_id' => $inv->customer_id,
            'crypto_wallet_id' => $w->id, 'chain' => 'tron', 'asset' => 'USDT',
            'network' => 'TRC20', 'address' => $w->address,
            'amount_atomic' => 10_000_000, 'decimals' => 6,
            'invoice_amount' => 10000, 'invoice_currency' => 'EUR', 'rate_micro' => 1_000_000,
            'status' => 'pending', 'expires_at' => now()->addMinutes(20),
        ]);
        $w->forceFill(['busy_payment_id' => $cp->id])->save();

        // مبلغِ کسِ دیگر — خیلی بیشتر از آنچه این پرداخت خواسته، و زمانش «الان»
        Http::swap(new Factory);
        Http::fake(['api.trongrid.io/*' => function ($request) {
            preg_match('~/accounts/([^/]+)/~', $request->url(), $m);

            return Http::response(['data' => [[
                'transaction_id' => 'TX-FOREIGN',
                'to' => $m[1] ?? '',
                'value' => '95000000',                 // ۹۵ تتر در برابرِ ۱۰ تترِ خواسته‌شده
                'token_info' => ['decimals' => 6, 'symbol' => 'USDT'],
                'block_timestamp' => now()->getTimestampMs(),
            ]]], 200);
        }]);

        app(CryptoReconciler::class)->sweep();

        $this->assertSame('unpaid', $inv->fresh()->status,
            'واریزیِ با مبلغِ بیگانه فاکتور را تسویه کرد');
        $this->assertSame('manual', $cp->fresh()->status,
            'باید به بازبینیِ دستی می‌رفت، نه تأیید و نه سکوت');
    }

    /** ✅ ضدنمونه: مبلغِ درست (با دمِ یکتا) باید تسویه کند. */
    public function test_the_exact_amount_still_settles(): void
    {
        $inv = $this->invoice();
        $w = CryptoWallet::create(['chain' => 'tron', 'address' => 'TMatch02', 'is_active' => true]);

        $cp = CryptoPayment::create([
            'invoice_id' => $inv->id, 'customer_id' => $inv->customer_id,
            'crypto_wallet_id' => $w->id, 'chain' => 'tron', 'asset' => 'USDT',
            'network' => 'TRC20', 'address' => $w->address,
            'amount_atomic' => 10_000_437, 'decimals' => 6,          // با دمِ یکتا
            'invoice_amount' => 10000, 'invoice_currency' => 'EUR', 'rate_micro' => 1_000_000,
            'status' => 'pending', 'expires_at' => now()->addMinutes(20),
        ]);
        $w->forceFill(['busy_payment_id' => $cp->id])->save();

        Http::swap(new Factory);
        Http::fake(['api.trongrid.io/*' => function ($request) {
            preg_match('~/accounts/([^/]+)/~', $request->url(), $m);

            return Http::response(['data' => [[
                'transaction_id' => 'TX-EXACT',
                'to' => $m[1] ?? '',
                'value' => '10000437',
                'token_info' => ['decimals' => 6, 'symbol' => 'USDT'],
                'block_timestamp' => now()->getTimestampMs(),
            ]]], 200);
        }]);

        app(CryptoReconciler::class)->sweep();

        $this->assertSame('confirmed', $cp->fresh()->status, 'پرداختِ درست تأیید نشد — درگاه از کار افتاده');
        $this->assertSame('paid', $inv->fresh()->status);
    }

    /** ولتِ پرداختِ منقضی باید آزاد شود تا مشتریِ بعدی بتواند بپردازد. */
    public function test_an_expired_payment_frees_its_wallet(): void
    {
        $inv = $this->invoice();
        $w = CryptoWallet::create(['chain' => 'tron', 'address' => 'TFree01', 'is_active' => true]);

        $cp = CryptoPayment::create([
            'invoice_id' => $inv->id, 'customer_id' => $inv->customer_id,
            'crypto_wallet_id' => $w->id, 'chain' => 'tron', 'asset' => 'USDT',
            'network' => 'TRC20', 'address' => $w->address,
            'amount_atomic' => 10_000_000, 'decimals' => 6,
            'invoice_amount' => 10000, 'invoice_currency' => 'EUR', 'rate_micro' => 1_000_000,
            'status' => 'pending', 'expires_at' => now()->subMinutes(5),      // مهلت گذشته
        ]);
        $w->forceFill(['busy_payment_id' => $cp->id])->save();

        Http::swap(new Factory);
        Http::fake(['api.trongrid.io/*' => Http::response(['data' => []], 200)]);

        app(CryptoReconciler::class)->sweep();

        $this->assertSame('expired', $cp->fresh()->status);
        $this->assertNull($w->fresh()->busy_payment_id, 'ولت آزاد نشد — از استخر کم می‌مانَد');
    }
}
