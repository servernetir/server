<?php

namespace Tests\Feature;

use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Payment\CryptoReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * واریزیِ **قدیمی** نباید فاکتورِ تازه را تسویه کند.
 *
 * ═══ حادثهٔ واقعی که این تست از آن آمد (۸ شهریور ۱۴۰۵) ═══
 *
 * 🔴 مشتریِ تازه ثبت‌نام کرد، با رمزارز سرور خرید، فاکتورش «پرداخت‌شده» شد و
 * سرویس فعال — و **هیچ پولی به حسابِ ما نیامده بود**. `txid`ِ ثبت‌شده روی
 * پرداختش، تراکنشی از هفتهٔ پیش بود.
 *
 * علت: `TronWatcher` ۵۰ تراکنشِ آخرِ آدرس را برمی‌گرداند، بی‌هیچ فیلترِ زمانی؛
 * و آدرس‌ها از استخر **بازاستفاده** می‌شوند. هر واریزیِ قدیمیِ آن آدرس که در
 * جدولِ ما ثبت نشده بود (واریزیِ دیرهنگامِ بعد از انقضا، جابه‌جاییِ داخلی،
 * هر چیزی) روی اولین پرداختِ بازِ بعدی می‌نشست.
 *
 * ⚠️ گاردِ «txid تکراری» این را نمی‌گرفت: آن فقط تراکنشی را رد می‌کند که
 * **قبلاً ثبت شده**. تراکنشی که هرگز ثبت نشده بود از کنارش رد می‌شد — یعنی
 * محافظِ موجود دقیقاً همین حالت را پوشش نمی‌داد.
 */
class CryptoStaleDepositTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): Invoice
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'sd'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        return Invoice::create([
            'customer_id' => $c->id, 'number' => 'INV-'.random_int(10000, 99999),
            'currency_code' => 'EUR', 'subtotal' => 10000, 'tax' => 0, 'total' => 10000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(), 'due_at' => now()->addDays(7),
        ]);
    }

    /** پرداختِ بازِ تازه‌ساخت روی یک آدرسِ بازاستفاده‌شده */
    private function payment(Invoice $inv, string $address): CryptoPayment
    {
        $w = CryptoWallet::firstOrCreate(
            ['chain' => 'tron', 'address' => $address],
            ['is_active' => true],
        );

        $cp = CryptoPayment::create([
            'invoice_id' => $inv->id, 'customer_id' => $inv->customer_id,
            'crypto_wallet_id' => $w->id, 'chain' => 'tron', 'asset' => 'USDT',
            'network' => 'TRC20', 'address' => $w->address,
            'amount_atomic' => 10_000_000, 'decimals' => 6,
            'invoice_amount' => 10000, 'invoice_currency' => 'EUR', 'rate_micro' => 1_000_000,
            'status' => 'pending', 'expires_at' => now()->addMinutes(20),
        ]);

        $w->forceFill(['busy_payment_id' => $cp->id])->save();

        return $cp;
    }

    /** @param  array<int,array{txid:string,amount:int,ageSeconds:int}>  $deposits */
    private function fakeDeposits(array $deposits): void
    {
        Http::swap(new Factory);

        Http::fake(['api.trongrid.io/*' => function ($request) use ($deposits) {
            preg_match('~/accounts/([^/]+)/~', $request->url(), $m);
            $addr = $m[1] ?? '';

            return Http::response([
                'data' => array_map(fn ($d) => [
                    'transaction_id' => $d['txid'],
                    'to' => $addr,
                    'value' => (string) $d['amount'],
                    'token_info' => ['decimals' => 6, 'symbol' => 'USDT'],
                    // ⚠️ سنِ واریزی نسبت به «الان»، بر حسبِ میلی‌ثانیه
                    'block_timestamp' => (now()->timestamp - $d['ageSeconds']) * 1000,
                ], $deposits),
            ], 200);
        }]);
    }

    /**
     * 🔴 بازتولیدِ دقیقِ حادثه: واریزیِ یک‌هفته‌ایِ همان آدرس، روی پرداختِ تازه.
     *
     * ادعا روی **فاکتور** است نه فقط وضعیتِ پرداخت: چیزی که ضرر می‌زند
     * «paid» شدنِ فاکتور و فعال‌شدنِ سرویس است.
     */
    public function test_a_week_old_deposit_never_settles_a_fresh_payment(): void
    {
        $inv = $this->invoice();
        $cp = $this->payment($inv, 'TReused0001');

        // همان مبلغِ درست — ولی تراکنش مالِ هفتهٔ پیش است
        $this->fakeDeposits([['txid' => 'TX-OLD-WEEK', 'amount' => 10_000_000, 'ageSeconds' => 7 * 86400]]);

        app(CryptoReconciler::class)->sweep();

        $this->assertSame('unpaid', $inv->fresh()->status,
            'فاکتور با واریزیِ هفتهٔ پیش تسویه شد — همان حادثه دوباره رخ داد');
        $this->assertNotSame('confirmed', $cp->fresh()->status);
        $this->assertSame(0, (int) $cp->fresh()->received_atomic,
            'مبلغِ واریزیِ قدیمی نباید حتی جزئی هم شمرده شود');
    }

    /** واریزیِ چند دقیقه پیش از ساختِ پرداخت هم رد می‌شود — مرزِ نزدیک. */
    public function test_a_deposit_minutes_before_the_payment_is_also_rejected(): void
    {
        $inv = $this->invoice();
        $cp = $this->payment($inv, 'TReused0002');

        $this->fakeDeposits([['txid' => 'TX-10MIN-EARLY', 'amount' => 10_000_000, 'ageSeconds' => 600]]);

        app(CryptoReconciler::class)->sweep();

        $this->assertSame('unpaid', $inv->fresh()->status);
        $this->assertSame(0, (int) $cp->fresh()->received_atomic);
    }

    /**
     * ✅ ضدنمونه: واریزیِ **واقعی** (بعد از ساختِ پرداخت) باید تسویه کند.
     *
     * بی‌این ادعا، رفع می‌توانست با «هیچ‌چیز را قبول نکن» سبز شود — و درگاه
     * بی‌صدا از کار می‌افتاد.
     */
    public function test_a_genuine_deposit_after_the_payment_still_settles(): void
    {
        $inv = $this->invoice();
        $cp = $this->payment($inv, 'TReused0003');

        $this->fakeDeposits([['txid' => 'TX-GENUINE', 'amount' => 10_000_000, 'ageSeconds' => 0]]);

        app(CryptoReconciler::class)->sweep();

        $this->assertSame('confirmed', $cp->fresh()->status, 'پرداختِ واقعی تأیید نشد — درگاه از کار افتاده');
        $this->assertSame('paid', $inv->fresh()->status);
    }

    /**
     * ⚠️ اختلافِ ساعتِ سرور با زمانِ بلاک نباید پرداختِ درست را رد کند.
     */
    public function test_a_small_clock_skew_does_not_break_a_real_payment(): void
    {
        $inv = $this->invoice();
        $this->payment($inv, 'TReused0004');

        $this->fakeDeposits([['txid' => 'TX-SKEW', 'amount' => 10_000_000, 'ageSeconds' => 60]]);

        app(CryptoReconciler::class)->sweep();

        $this->assertSame('paid', $inv->fresh()->status, 'حاشیهٔ ساعت پرداختِ درست را رد کرد');
    }

    /**
     * 🔴 زمانِ ناشناخته ⇒ بازبینیِ دستی، نه تأییدِ خوش‌بینانه.
     *
     * اگر درایورِ زنجیره‌ای روزی `timestamp` ندهد، گاردِ زمانی بی‌صدا بی‌اثر
     * می‌شود و همین حفره برمی‌گردد. این ادعا آن حالت را قفل می‌کند.
     */
    public function test_a_deposit_without_a_timestamp_goes_to_manual_review(): void
    {
        $inv = $this->invoice();
        $cp = $this->payment($inv, 'TReused0005');

        Http::swap(new Factory);
        Http::fake(['api.trongrid.io/*' => function ($request) {
            preg_match('~/accounts/([^/]+)/~', $request->url(), $m);

            return Http::response(['data' => [[
                'transaction_id' => 'TX-NO-TIME',
                'to' => $m[1] ?? '',
                'value' => '10000000',
                'token_info' => ['decimals' => 6, 'symbol' => 'USDT'],
                'block_timestamp' => 0,          // زنجیره زمان نداد
            ]]], 200);
        }]);

        app(CryptoReconciler::class)->sweep();

        $this->assertSame('unpaid', $inv->fresh()->status, 'واریزیِ بی‌زمان فاکتور را تسویه کرد');
        $this->assertSame('manual', $cp->fresh()->status, 'به صفِ بازبینیِ دستی نرفت');
    }
}
