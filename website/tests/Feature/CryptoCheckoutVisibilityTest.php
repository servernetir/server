<?php

namespace Tests\Feature;

use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Payment\CryptoIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * «گزینهٔ رمزارز اصلاً تو فاکتور ظاهر نشد» — گزارشِ کارفرما.
 *
 * ═══ علتِ واقعی ═══
 *
 * کارت‌ها **و** پنل‌های رمزارزِ صفحهٔ فاکتور هر دو از `CryptoIssuer::available()`
 * می‌آمدند، که فقط دارایی‌هایی را برمی‌گرداند که آدرسِ **آزاد** دارند. استخر یک
 * آدرس داشت؛ پس همان لحظه که مشتری «دریافت آدرس» را می‌زد، آن تنها آدرس مشغول
 * می‌شد و در بارگذاریِ بعدی کلِ روشِ پرداخت — از جمله جعبه‌ای که آدرس و مبلغ و
 * شمارشِ معکوسِ خودِ او را داشت — از صفحه غیب می‌شد. صفحه ۲۰۰ می‌داد و هیچ
 * خطایی هم تولید نمی‌شد.
 *
 * ⚠️ اینجا هیچ گاردِ پولی شل نمی‌شود: پرداختِ باز فقط **دیده** می‌شود؛ آدرسِ
 * تازه هنوز فقط از استخرِ آزاد و با ادعای اتمی گرفته می‌شود.
 */
class CryptoCheckoutVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
        | 🔴 «نرخ سرد» و «قیمتِ بازارِ نبود» فقط وقتی سرد می‌مانند که fetchِ
        | در لحظه هم شکست بخورد. این فایل هیچ استابی نداشت و تا امروز فقط به
        | این دلیل سبز بود که HTTPSِ ماشینِ محلیِ توسعه خراب بود؛ روی هر
        | ماشینِ سالمی نرخِ واقعی می‌آمد و تست قرمز می‌شد. پاسخِ خالیِ
        | همه‌گیر یعنی هر fetch «ناموفقِ بی‌صدا» — همان فرضِ سناریوها.
        | (طبق قاعده‌ی ثبت‌شده، در کل فایل فقط همین یک fake ثبت می‌شود.)
        */
        \Illuminate\Support\Facades\Http::fake();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'v'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'),
            'status' => 'active',
        ]);
    }

    private function invoice(Customer $c, string $currency = 'IRT'): Invoice
    {
        $amount = $currency === 'IRT' ? 1_500_000 : 10000;

        return Invoice::create([
            'customer_id' => $c->id,
            'number' => 'INV-'.random_int(10000, 99999),
            'currency_code' => $currency,
            'subtotal' => $amount, 'tax' => 0, 'total' => $amount, 'paid' => 0,
            'status' => 'unpaid',
            'issued_at' => now(), 'due_at' => now()->addDays(7),
        ]);
    }

    private function wallet(string $address = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'): CryptoWallet
    {
        return CryptoWallet::create(['chain' => 'tron', 'address' => $address, 'is_active' => true]);
    }

    /**
     * نرخِ ارز — بدونِ آن صدور عمداً شکست می‌خورد (fail-closed).
     *
     * ⚠️ از وقتی نرخ‌ها از نوسان می‌آیند (`3acf65f9`)، `ExchangeRate` فقط ردیفِ
     * با منشأ و زمانِ معتبر را می‌پذیرد، و تتر نرخِ **مستقلِ** `USDT` دارد. این
     * فایل هنوز کلیدِ قدیمیِ `fx.usd_irt` را بی‌منشأ می‌گذاشت و چهار تستش روی
     * develop قرمز بود. حالا دلار و تتر هر دو با همان شکلِ واقعی ثبت می‌شوند.
     */
    private function fx(string $currency = 'USD', int $toman = 100_000): void
    {
        $row = fn (string $cur, int $rate) => [
            'currency' => $cur, 'rate_toman' => $rate,
            'source' => 'navasan.net', 'source_item' => \App\Services\ExchangeRate::SOURCE_ITEMS[$cur],
            'at' => now()->toIso8601String(), 'source_at' => now()->toIso8601String(),
        ];

        $snapshot = (array) Cache::get('fx.navasan.rates', []);
        $snapshot[$currency] = $row($currency, $toman);
        $snapshot['USDT'] ??= $row('USDT', $toman);

        Cache::put('fx.navasan.rates', $snapshot, now()->addHour());
    }

    // ───────────────────────── دیده‌شدن ─────────────────────────

    /** یک آدرسِ آزاد ⇒ کارتِ رمزارز باید در صفحهٔ فاکتور باشد */
    public function test_a_free_wallet_makes_the_crypto_card_appear_on_a_payable_invoice(): void
    {
        $this->wallet();
        $this->fx();

        $c = $this->customer();
        $inv = $this->invoice($c, 'IRT');

        $this->assertTrue($inv->isPayable(), 'پیش‌شرط: فاکتور باید قابل پرداخت باشد');

        $html = $this->actingAs($c, 'customer')->get('/account/invoices/'.$inv->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-m="crypto"', $html, 'کارتِ رمزارز باید باشد');
        $this->assertStringContainsString('id="pane-crypto"', $html, 'پنلِ رمزارز باید ساخته شود');
        $this->assertStringContainsString('<option value="USDT"', $html, 'USDT باید در فهرستِ انتخاب باشد');
        $this->assertStringNotContainsString(__('ui.cy_busy'), $html,
            'آدرس آزاد است؛ نباید «موقتاً در دسترس نیست» بگوید');
    }

    /**
     * 🔴 باگِ گزارشِ کارفرما، بازسازی‌شده.
     *
     * مشتری آدرس می‌گیرد (تنها آدرسِ استخر قفل می‌شود) و به فاکتور برمی‌گردد —
     * آدرس و مهلتِ خودش باید هنوز روی صفحه باشد.
     */
    public function test_an_open_payment_stays_visible_even_when_it_holds_the_last_address(): void
    {
        $this->wallet();
        $this->fx();

        $c = $this->customer();
        $inv = $this->invoice($c, 'IRT');

        $this->actingAs($c, 'customer')
            ->post('/account/invoices/'.$inv->id.'/crypto', ['asset' => 'USDT'])
            ->assertRedirect();

        $cp = CryptoPayment::where('invoice_id', $inv->id)->latest('id')->firstOrFail();
        $this->assertSame('pending', $cp->status);
        $this->assertNotSame('', $cp->address, 'پرداخت باید آدرس گرفته باشد');
        $this->assertNull(CryptoWallet::claim('tron', 999), 'پیش‌شرط: استخر دیگر آزاد نیست');

        $html = $this->actingAs($c, 'customer')->get('/account/invoices/'.$inv->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString($cp->address, $html,
            'آدرسی که مشتری باید به آن پول بفرستد باید روی صفحه باشد');
        $this->assertStringContainsString($cp->amountHuman(), $html, 'مبلغِ دقیق باید دیده شود');
        $this->assertStringContainsString('id="pane-crypto"', $html, 'پنلِ پرداختِ باز نباید ناپدید شود');
        $this->assertStringContainsString('data-m="crypto"', $html, 'کارتِ رمزارز نباید ناپدید شود');
    }

    /** و پنلِ پرداختِ باز باید **باز** باشد، نه پشتِ یک کلیکِ دیگر */
    public function test_the_open_payment_pane_is_expanded_on_arrival(): void
    {
        $this->wallet();
        $this->fx();

        $c = $this->customer();
        $inv = $this->invoice($c, 'IRT');

        $this->actingAs($c, 'customer')->post('/account/invoices/'.$inv->id.'/crypto', ['asset' => 'USDT']);

        $html = $this->actingAs($c, 'customer')->get('/account/invoices/'.$inv->id)
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<div class="pm-pane cyx" id="pane-crypto"\s*>~', $html,
            'پنلِ پرداختِ باز نباید hidden باشد'
        );
    }

    /**
     * 🔴 مشتریِ دوم در حالی که آدرس دستِ نفرِ اول است، **سکوت** نمی‌بیند.
     *
     * پنهان‌کردنِ کلِ روشِ پرداخت دفاع‌پذیر است ولی نامرئی؛ کاربر فکر می‌کند
     * قابلیت خراب است. حالا صریح می‌گوید «موقتاً در دسترس نیست».
     */
    public function test_a_second_customer_sees_a_temporarily_unavailable_state_not_silence(): void
    {
        $this->wallet();
        $this->fx();

        $first = $this->customer();
        $firstInv = $this->invoice($first, 'IRT');
        $this->actingAs($first, 'customer')->post('/account/invoices/'.$firstInv->id.'/crypto', ['asset' => 'USDT']);

        $second = $this->customer();
        $secondInv = $this->invoice($second, 'IRT');

        $html = $this->actingAs($second, 'customer')->get('/account/invoices/'.$secondInv->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.cy_busy'), $html,
            'وقتی همهٔ آدرس‌ها مشغول‌اند باید گفته شود، نه اینکه روش پرداخت غیب شود');
        $this->assertStringContainsString('data-m="crypto"', $html, 'کارت باید بماند');
        $this->assertStringNotContainsString('id="pane-crypto"', $html,
            'کارتِ مشغول پنل ندارد — کاری برای انجام‌دادن نیست');
        $this->assertStringNotContainsString($firstInv->number, $html,
            'هیچ‌چیزی از فاکتورِ مشتریِ دیگر نباید نشت کند');
    }

    /** آدرسِ فعالی در استخر نیست ⇒ هیچ وعده‌ای هم نمی‌دهیم */
    public function test_an_empty_pool_shows_nothing_at_all_not_a_false_promise(): void
    {
        $this->fx();

        $c = $this->customer();
        $inv = $this->invoice($c, 'IRT');

        $html = $this->actingAs($c, 'customer')->get('/account/invoices/'.$inv->id)
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('data-m="crypto"', $html);
        $this->assertStringNotContainsString(__('ui.cy_busy'), $html,
            '«کمی بعد برگرد» وقتی هیچ آدرسی نیست، یک وعدهٔ دروغ است');
    }

    /**
     * ⚠️ نرخِ ساعتیِ ارز که سرد باشد، کارت می‌ماند ولی «موقتاً» می‌شود —
     * چون کرونِ `fx:dollar` ظرفِ یک ساعت گرمش می‌کند. عرضه‌اش اما ممنوع است:
     * بی‌نرخ، مبلغ حدسی می‌شود.
     */
    public function test_a_cold_exchange_rate_downgrades_the_option_but_never_prices_a_guess(): void
    {
        $this->wallet();
        Cache::forget('fx.navasan.rates');
        Cache::forget('fx.usd_irt');

        $c = $this->customer();
        $inv = $this->invoice($c, 'IRT');

        $html = $this->actingAs($c, 'customer')->get('/account/invoices/'.$inv->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.cy_busy'), $html);
        $this->assertStringNotContainsString('id="pane-crypto"', $html);

        // و اگر با همان حال زور بزند، هیچ آدرسی صادر نمی‌شود
        $this->actingAs($c, 'customer')
            ->post('/account/invoices/'.$inv->id.'/crypto', ['asset' => 'USDT'])
            ->assertSessionHasErrors('crypto');

        $this->assertSame(0, CryptoPayment::count(), 'بی‌نرخ نباید پرداختی ساخته شود');
    }

    // ───────────────────────── TRX و قیمت ─────────────────────────

    /** 🔴 TRX بی‌قیمتِ بازار **عرضه نمی‌شود** — نه ready، نه busy، نه حدس */
    public function test_trx_is_not_offered_without_a_market_price(): void
    {
        $this->wallet();
        $this->fx();
        Cache::forget('cyprice.trx_usd');

        $c = $this->customer();
        $inv = $this->invoice($c, 'IRT');

        $offers = app(CryptoIssuer::class)->offers('IRT');

        $this->assertArrayHasKey('USDT', $offers, 'تتر لنگرِ دلاری دارد و باید بماند');
        $this->assertArrayNotHasKey('TRX', $offers, 'بی‌قیمت، TRX نباید در فهرست باشد');

        $this->actingAs($c, 'customer')
            ->post('/account/invoices/'.$inv->id.'/crypto', ['asset' => 'TRX'])
            ->assertSessionHasErrors('crypto');
    }

    /** با قیمتِ بازار، TRX یک گزینهٔ واقعی می‌شود و مبلغش از همان قیمت می‌آید */
    public function test_trx_becomes_a_real_option_once_a_market_price_exists(): void
    {
        $this->wallet();
        $this->fx('USD', 100_000);
        Cache::put('cyprice.trx_usd', 0.25, now()->addHour());

        $c = $this->customer();
        $inv = $this->invoice($c, 'IRT');   // ۱٬۵۰۰٬۰۰۰ تومان

        $html = $this->actingAs($c, 'customer')->get('/account/invoices/'.$inv->id)
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<option value="TRX"(?![^>]*disabled)~', $html, 'TRX باید قابلِ انتخاب باشد');

        $this->actingAs($c, 'customer')
            ->post('/account/invoices/'.$inv->id.'/crypto', ['asset' => 'TRX'])
            ->assertRedirect();

        $cp = CryptoPayment::where('invoice_id', $inv->id)->latest('id')->firstOrFail();

        // یک TRX = ۰٫۲۵ دلار × ۱۰۰٬۰۰۰ تومان = ۲۵٬۰۰۰ تومان
        // ۱٬۵۰۰٬۰۰۰ ÷ ۲۵٬۰۰۰ = ۶۰ TRX = ۶۰٬۰۰۰٬۰۰۰ sun
        $this->assertSame(25_000 * 1_000_000, $cp->rate_micro);
        $this->assertSame(60_000_000, $cp->amount_atomic);
        $this->assertSame('TRX', $cp->asset);
    }

    // ───────────────────────── طراحیِ تازه (یک کارت) ─────────────────────────

    /** دو دارایی، **یک** کارت؛ انتخابِ ارز داخلِ پنل با مبلغِ تقریبی */
    public function test_two_assets_render_one_card_and_a_coin_picker_with_estimates(): void
    {
        $this->wallet();
        $this->fx('USD', 100_000);
        Cache::put('cyprice.trx_usd', 0.25, now()->addHour());

        $c = $this->customer();
        $inv = $this->invoice($c, 'IRT');   // ۱٬۵۰۰٬۰۰۰ تومان

        $html = $this->actingAs($c, 'customer')->get('/account/invoices/'.$inv->id)
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-m="crypto"'), 'فقط یک کارتِ رمزارز');
        $this->assertStringContainsString('data-value="USDT"', $html);
        $this->assertStringContainsString('data-value="TRX"', $html);
        // ۱٬۵۰۰٬۰۰۰ ÷ ۱۰۰٬۰۰۰ = ۱۵ USDT ؛ ÷ ۲۵٬۰۰۰ = ۶۰ TRX
        $this->assertStringContainsString('data-est="15"', $html);
        $this->assertStringContainsString('data-est="60"', $html);
    }

    /** صفحهٔ پرداختِ باز: QRِ آدرس، دکمه‌های کپی و شمارشِ معکوسِ حلقه‌ای */
    public function test_the_open_payment_shows_a_qr_copy_buttons_and_a_ring_timer(): void
    {
        $this->wallet();
        $this->fx();

        $c = $this->customer();
        $inv = $this->invoice($c, 'IRT');
        $this->actingAs($c, 'customer')->post('/account/invoices/'.$inv->id.'/crypto', ['asset' => 'USDT']);
        $cp = CryptoPayment::where('invoice_id', $inv->id)->latest('id')->firstOrFail();

        $html = $this->actingAs($c, 'customer')->get('/account/invoices/'.$inv->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $html, 'QR درون‌خطی (بی‌منبعِ بیرونی)');
        $this->assertStringContainsString('data-copy="'.$cp->address.'"', $html);
        $this->assertStringContainsString('data-copy="'.$cp->amountHuman().'"', $html);
        $this->assertStringContainsString('id="cy-ring"', $html);
        $this->assertMatchesRegularExpression('~data-total="\d+"~', $html);
        $this->assertStringNotContainsString('id="cy-pick"', $html,
            'با پرداختِ باز، انتخابِ ارزِ دیگر پیشنهاد نمی‌شود (آدرسِ دیگری قفل می‌کرد)');
    }
}
