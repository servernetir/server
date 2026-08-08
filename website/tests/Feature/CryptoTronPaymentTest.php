<?php

namespace Tests\Feature;

use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Payment\CryptoReconciler;
use App\Services\Payment\TronWatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * درگاهِ رمزارزِ خودمان — فاز ۱، ترون.
 *
 * ⚠️ این‌جا پولِ واقعی در میان است و هیچ واسطه‌ای نیست که اشتباهمان را بگیرد.
 * پس تست‌ها روی همان چیزهایی‌اند که اگر بشکنند **پول جابه‌جا می‌شود**:
 * حسابِ دوبارهٔ یک تراکنش، اختصاصِ هم‌زمانِ یک آدرس، و پرداختِ دیرهنگام.
 */
class CryptoTronPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): Invoice
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'c'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        return Invoice::create([
            'customer_id' => $c->id, 'number' => 'INV-'.random_int(10000, 99999),
            'currency_code' => 'EUR', 'subtotal' => 10000, 'tax' => 0, 'total' => 10000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(), 'due_at' => now()->addDays(7),
        ]);
    }

    private function payment(Invoice $inv, array $over = []): CryptoPayment
    {
        $w = CryptoWallet::create(['chain' => 'tron', 'address' => 'TTest'.random_int(1000, 9999), 'is_active' => true]);

        $cp = CryptoPayment::create($over + [
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

    /** یک واریزیِ کافی باید فاکتور را تسویه کند */
    public function test_a_sufficient_deposit_confirms_and_settles_the_invoice(): void
    {
        $inv = $this->invoice();
        $cp = $this->payment($inv);

        $this->fakeDeposits([['txid' => 'TX-AAA', 'amount' => 10_000_000]]);

        app(CryptoReconciler::class)->sweep();

        $cp->refresh();
        $this->assertSame('confirmed', $cp->status);
        $this->assertSame('TX-AAA', $cp->txid);
        $this->assertSame('paid', $inv->fresh()->status, 'فاکتور باید تسویه شده باشد');
    }

    /**
     * 🔴 مهم‌ترین تست: یک تراکنش نباید **دو بار** حساب شود.
     *
     * کرون هر دقیقه می‌دود و TronGrid همان تراکنش را بارها برمی‌گرداند. بدونِ
     * محافظ، هر دقیقه به مبلغِ رسیده اضافه می‌شد و یک واریزِ کوچک هم فاکتور را
     * تسویه می‌کرد.
     */
    public function test_the_same_transaction_is_never_counted_twice(): void
    {
        $inv = $this->invoice();
        $cp = $this->payment($inv, ['amount_atomic' => 30_000_000]);

        $this->fakeDeposits([['txid' => 'TX-DUP', 'amount' => 10_000_000]]);

        $rec = app(CryptoReconciler::class);
        $rec->sweep();
        $rec->sweep();
        $rec->sweep();

        $cp->refresh();
        $this->assertSame(10_000_000, $cp->received_atomic,
            'سه بار پایش نباید مبلغ را سه برابر کند');
        $this->assertNotSame('confirmed', $cp->status, 'مبلغ کافی نبوده، نباید تأیید شود');
    }

    /** کم‌پرداخت به بازبینیِ دستی می‌رود، نه تأیید و نه دور ریختن */
    public function test_an_underpayment_goes_to_manual_review_not_confirmation(): void
    {
        $inv = $this->invoice();
        $cp = $this->payment($inv);

        // ۵٪ کمتر — بیرون از رواداریِ ۱٪
        $this->fakeDeposits([['txid' => 'TX-LOW', 'amount' => 9_500_000]]);

        app(CryptoReconciler::class)->sweep();

        $cp->refresh();
        $this->assertSame('manual', $cp->status);
        $this->assertNotSame('paid', $inv->fresh()->status, 'فاکتورِ کم‌پرداخت نباید تسویه شود');
        $this->assertGreaterThan(0, $cp->received_atomic, 'پولِ رسیده نباید گم شود');
    }

    /** رواداریِ ۱٪ برای کارمزدِ صرافی باید بپذیرد */
    public function test_a_tiny_shortfall_within_tolerance_still_confirms(): void
    {
        $inv = $this->invoice();
        $cp = $this->payment($inv);

        $this->fakeDeposits([['txid' => 'TX-FEE', 'amount' => 9_950_000]]);   // ۰٫۵٪ کم

        app(CryptoReconciler::class)->sweep();

        $this->assertSame('confirmed', $cp->refresh()->status);
    }

    /**
     * 🔴 یک آدرس نباید هم‌زمان به دو پرداخت برسد.
     *
     * بی‌این، اولین واریز به هر دو فاکتور نسبت داده می‌شود و یک سرویسِ
     * پرداخت‌نشده فعال می‌شود.
     */
    public function test_an_address_is_never_claimed_by_two_payments(): void
    {
        CryptoWallet::create(['chain' => 'tron', 'address' => 'TOnlyOne', 'is_active' => true]);

        $first = CryptoWallet::claim('tron', 101);
        $second = CryptoWallet::claim('tron', 202);

        $this->assertNotNull($first);
        $this->assertNull($second, 'استخر یک آدرس داشت؛ دومی باید دست خالی برگردد');
    }

    /**
     * ⚠️ آدرسِ آزادشده نباید **بلافاصله** دوباره داده شود.
     *
     * پرداختِ دیرهنگامِ مشتریِ قبلی وگرنه به فاکتورِ نفرِ بعدی می‌نشیند.
     */
    public function test_a_released_address_stays_in_cooldown(): void
    {
        $w = CryptoWallet::create(['chain' => 'tron', 'address' => 'TCool', 'is_active' => true]);
        CryptoWallet::claim('tron', 1);
        $w->refresh()->release();

        $this->assertNull(CryptoWallet::claim('tron', 2),
            'آدرس باید در دورهٔ خنک‌شدن باشد');

        $this->travel(CryptoWallet::COOLDOWN_HOURS + 1)->hours();

        $this->assertNotNull(CryptoWallet::claim('tron', 3),
            'بعد از دورهٔ خنک‌شدن باید دوباره قابل استفاده باشد');
    }

    /** پرداختِ منقضی آدرسش را آزاد می‌کند — وگرنه استخر ته می‌کشد */
    public function test_expiry_frees_the_address(): void
    {
        $inv = $this->invoice();
        $cp = $this->payment($inv, ['expires_at' => now()->subMinute()]);

        $this->fakeDeposits([]);
        app(CryptoReconciler::class)->sweep();

        $this->assertSame('expired', $cp->refresh()->status);
        $this->assertNull($cp->wallet->refresh()->busy_payment_id);
    }

    /**
     * ⚠️ قطعیِ TronGrid نباید چیزی را خراب کند.
     *
     * پاسخِ خطا یعنی «الان چیزی ندیدم»، نه «پولی نیامده». پرداختِ باز باید باز
     * بماند تا دقیقهٔ بعد.
     */
    public function test_a_chain_api_outage_leaves_open_payments_untouched(): void
    {
        $inv = $this->invoice();
        $cp = $this->payment($inv);

        Http::swap(new Factory);
        Http::fake(['api.trongrid.io/*' => Http::response('gateway down', 502)]);

        app(CryptoReconciler::class)->sweep();

        $this->assertSame('pending', $cp->refresh()->status);
        $this->assertSame(0, $cp->received_atomic);
    }

    /**
     * مهلتِ پرداخت — کارفرما ۵۹ دقیقه خواست چون برداشت از صرافی گاهی طول
     * می‌کشد و ۲۰ دقیقه کم بود.
     *
     * ⚠️ این تست عدد را قفل می‌کند و **دلیلش** را هم: تا وقتی مهلت باز است نرخ
     * قفل مانده. برای استیبل‌کوین بی‌خطر است، ولی دارایی نوسانی باید مهلتِ
     * کوتاه‌تری بگیرد — پس مهلت به‌ازای هر دارایی تعریف می‌شود، نه یک عددِ
     * سراسری که روزی برای بیت‌کوین هم برداشته شود.
     */
    public function test_the_payment_window_is_per_asset_and_stablecoins_get_the_long_one(): void
    {
        $assets = \App\Services\Payment\CryptoIssuer::ASSETS;

        $this->assertSame(59, $assets['USDT']['window'],
            'کارفرما برای تتر ۵۹ دقیقه خواست');

        $this->assertLessThan($assets['USDT']['window'], $assets['TRX']['window'],
            'دارایی نوسانی نباید همان مهلتِ بلندِ استیبل‌کوین را بگیرد — نرخ قفل است');

        foreach ($assets as $name => $spec) {
            $this->assertArrayHasKey('window', $spec,
                "دارایی «{$name}» مهلتِ خودش را ندارد و به پیش‌فرض می‌افتد");
        }
    }

    /**
     * 🔴 واریزِ خودِ TRX (نه توکن) هم باید تسویه کند.
     *
     * ⚠️ TRX تا امروز هرگز عرضه نمی‌شد (قیمتِ زنده نداشتیم)، پس این مسیرِ کد
     * ساخته شده بود ولی **هیچ تستی نداشت**. حالا که با منبعِ قیمت واقعاً قابلِ
     * انتخاب است، مسیرش هم باید قفل شود: انتقالِ ساده، تراکنشِ موفق، و همان
     * `settleConfirmed` که بقیهٔ درگاه‌ها از آن رد می‌شوند.
     */
    public function test_a_native_trx_deposit_confirms_and_settles_the_invoice(): void
    {
        $inv = $this->invoice();
        $cp = $this->payment($inv, ['asset' => 'TRX', 'amount_atomic' => 60_000_000]);

        $this->fakeNativeDeposits([['txid' => 'TX-TRX-1', 'amount' => 60_000_000]]);

        app(CryptoReconciler::class)->sweep();

        $cp->refresh();
        $this->assertSame('confirmed', $cp->status);
        $this->assertSame('TX-TRX-1', $cp->txid);
        $this->assertSame('paid', $inv->fresh()->status);
    }

    /** ⚠️ تراکنشِ ناموفقِ زنجیره پول نیست — نباید شمرده شود */
    public function test_a_failed_trx_transaction_is_never_counted(): void
    {
        $inv = $this->invoice();
        $cp = $this->payment($inv, ['asset' => 'TRX', 'amount_atomic' => 60_000_000]);

        $this->fakeNativeDeposits([['txid' => 'TX-TRX-BAD', 'amount' => 60_000_000, 'ret' => 'REVERT']]);

        app(CryptoReconciler::class)->sweep();

        $cp->refresh();
        $this->assertSame('pending', $cp->status);
        $this->assertSame(0, $cp->received_atomic);
        $this->assertNotSame('paid', $inv->fresh()->status);
    }

    /** 🔴 کلیدِ خصوصی هیچ‌جای این حوزه نباید باشد */
    public function test_no_private_key_material_anywhere_in_the_crypto_layer(): void
    {
        /*
        | ⚠️ فهرست **صریح** است، نه glob.
        |
        | نسخهٔ اول روی `Services/Crypto/*.php` می‌گشت. وقتی کلاس‌ها جابه‌جا
        | شدند آن پوشه دیگر وجود نداشت، glob آرایهٔ خالی می‌داد و تست بی‌آنکه
        | چیزی بسنجد سبز می‌مانْد — یعنی محافظِ «کلید خصوصی روی سرور نباشد»
        | دقیقاً وقتی که کد جابه‌جا می‌شود از کار می‌افتاد.
        */
        $files = [
            app_path('Services/Payment/TronWatcher.php'),
            app_path('Services/Payment/CryptoReconciler.php'),
            app_path('Services/Payment/CryptoIssuer.php'),
            app_path('Services/Payment/CryptoPrice.php'),
        ];

        foreach ($files as $f) {
            $this->assertFileExists($f, 'فایلِ لایهٔ رمزارز جابه‌جا شده و این محافظ دیگر چیزی را نمی‌سنجد');
        }

        foreach ($files as $f) {
            $src = file_get_contents($f);

            foreach (['privateKey', 'private_key', 'mnemonic', 'seedPhrase', 'sign('] as $needle) {
                $this->assertStringNotContainsString($needle, $src,
                    basename($f).' نباید هیچ نشانی از کلید خصوصی یا امضا داشته باشد — '
                    .'این لایه فقط می‌خوانَد');
            }
        }
    }

    /**
     * ⚠️ `Http::fake` را با یک Factory تازه ثبت می‌کنیم.
     *
     * استابِ همه‌گیرِ فیکسچرهای دیگر «اولین تطبیق برنده» است و هر fakeِ بعدی
     * را بی‌اثر می‌کند — تلهٔ مستندِ همین پروژه.
     */
    /**
     * واریزیِ خودِ TRX — شکلِ پاسخِ TronGrid برای تراکنشِ ساده، نه TRC20.
     *
     * ⚠️ عمداً روی مسیرِ `/transactions` (بدونِ `/trc20`) پاسخ می‌دهد، تا اگر
     * روزی واچر مسیرها را قاطی کرد، تست بگیردش.
     */
    private function fakeNativeDeposits(array $deposits): void
    {
        Http::swap(new Factory);

        Http::fake(['api.trongrid.io/*' => function ($request) use ($deposits) {
            if (str_contains($request->url(), '/trc20')) {
                return Http::response(['data' => []], 200);
            }

            return Http::response([
                'data' => array_map(fn ($d) => [
                    'txID' => $d['txid'],
                    'ret' => [['contractRet' => $d['ret'] ?? 'SUCCESS']],
                    'raw_data' => ['contract' => [[
                        'type' => 'TransferContract',
                        'parameter' => ['value' => ['amount' => $d['amount']]],
                    ]]],
                    'block_timestamp' => now()->getTimestampMs(),
                ], $deposits),
            ], 200);
        }]);
    }

    private function fakeDeposits(array $deposits): void
    {
        Http::swap(new Factory);

        // ⚠️ آدرسِ مقصد از خودِ URL برداشته می‌شود، چون واچر عمداً `to` را
        //    دوباره می‌سنجد و استابِ با آدرسِ ثابت، آن محافظ را دور می‌زد —
        //    یعنی تست سبز می‌شد بی‌آنکه چیزی را ثابت کند.
        Http::fake(['api.trongrid.io/*' => function ($request) use ($deposits) {
            preg_match('~/accounts/([^/]+)/~', $request->url(), $m);
            $addr = $m[1] ?? '';

            return Http::response([
                'data' => array_map(fn ($d) => [
                    'transaction_id' => $d['txid'],
                    'to' => $addr,
                    'value' => (string) $d['amount'],
                    'token_info' => ['decimals' => 6, 'symbol' => 'USDT'],
                    'block_timestamp' => now()->getTimestampMs(),
                ], $deposits),
            ], 200);
        }]);
    }
}
