<?php

namespace Tests\Feature;

use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `/admin/crypto-wallets` — استخرِ آدرس‌ها، پرداخت‌های در جریان، صفِ بازبینی.
 *
 * ⚠️ چرا «در جریان» اهمیت دارد: استخرِ کوچک یعنی گزینهٔ رمزارز مرتب برای
 * مشتریِ بعدی «موقتاً در دسترس نیست» می‌شود. بدونِ فهرستی از پرداخت‌های باز،
 * مدیر هیچ راهی ندارد بفهمد چرا — و همان ابهام یک بار به «قابلیت اصلاً کار
 * نمی‌کند» تعبیر شد.
 */
class AdminCryptoWalletsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 's'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function openPayment(array $over = []): CryptoPayment
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        $inv = Invoice::create([
            'customer_id' => $c->id, 'number' => 'INV-'.random_int(10000, 99999),
            'currency_code' => 'IRT', 'subtotal' => 1_500_000, 'tax' => 0,
            'total' => 1_500_000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $w = CryptoWallet::create([
            'chain' => 'tron', 'address' => 'TW'.substr(md5((string) random_int(1, 1e9)), 0, 32),
            'is_active' => true,
        ]);

        $cp = CryptoPayment::create($over + [
            'invoice_id' => $inv->id, 'customer_id' => $c->id, 'crypto_wallet_id' => $w->id,
            'chain' => 'tron', 'asset' => 'USDT', 'network' => 'TRC20', 'address' => $w->address,
            'amount_atomic' => 15_000_000, 'decimals' => 6,
            'invoice_amount' => 1_500_000, 'invoice_currency' => 'IRT',
            'rate_micro' => 100_000_000_000, 'status' => 'pending',
            'expires_at' => now()->addMinutes(42),
        ]);

        $w->forceFill(['busy_payment_id' => $cp->id])->save();

        return $cp;
    }

    /** 🔴 ادعای اصلی: پرداختِ باز باید در پنلِ مدیر دیده شود */
    public function test_open_crypto_payments_are_listed_with_address_and_time_left(): void
    {
        $cp = $this->openPayment();

        $res = $this->actingAs($this->staff(), 'web')->get('/admin/settings?tab=accounts');

        $res->assertOk();
        $res->assertSee('پرداخت‌های در جریان');
        $res->assertSee('#'.$cp->invoice_id);
        $res->assertSee($cp->address);
        $res->assertSee($cp->amountHuman());
        $res->assertSee('USDT');
        $res->assertSee('42 دقیقه', false);
        $res->assertSee('در انتظار واریز');
    }

    /** پرداختِ منقضیِ تازه هم می‌آید — آدرسش هنوز آزاد نشده */
    public function test_a_recently_expired_payment_still_shows(): void
    {
        $this->openPayment(['status' => 'expired', 'expires_at' => now()->subHour()]);

        $res = $this->actingAs($this->staff(), 'web')->get('/admin/settings?tab=accounts');

        $res->assertOk();
        $res->assertSee('منقضی');
    }

    /** ⚠️ پرداختِ تأییدشده «در جریان» نیست و نباید فهرست را شلوغ کند */
    public function test_a_confirmed_payment_is_not_in_the_inflight_list(): void
    {
        $cp = $this->openPayment(['status' => 'confirmed', 'confirmed_at' => now()]);

        $res = $this->actingAs($this->staff(), 'web')->get('/admin/settings?tab=accounts');

        $res->assertOk();
        $res->assertSee('الان هیچ پرداخت رمزارزی باز نیست.');
        // ⚠️ آدرس هنوز در جدولِ استخر هست (باید باشد)؛ چیزی که نباید باشد،
        //    ردیفِ همین پرداخت است — و لینکِ فاکتور فقط در همان جدول‌هاست.
        $res->assertDontSee('/admin/invoices/'.$cp->invoice_id, false);
    }

    /** صفحه بدونِ هیچ داده‌ای هم باید سالم بیاید */
    public function test_the_page_is_fine_with_an_empty_pool(): void
    {
        $res = $this->actingAs($this->staff(), 'web')->get('/admin/settings?tab=accounts');

        $res->assertOk();
        $res->assertSee('استخر آدرس‌های دریافت');
        $res->assertSee('الان هیچ پرداخت رمزارزی باز نیست.');
    }

    /**
     * دورهٔ خنک‌شدن تنظیم‌پذیر است (۶ شهریور: «کریپتو هی غیرفعال می‌شود»)
     * — ۶ ساعتِ ثابت با استخرِ کوچک یعنی روشِ پرداختِ ساعت‌ها بسته.
     */
    public function test_the_cooldown_window_is_configurable(): void
    {
        $w = \App\Models\CryptoWallet::create([
            'chain' => 'tron', 'address' => 'TCooldown00000000000000000000000000',
            'is_active' => true, 'busy_payment_id' => 123,
        ]);

        \App\Models\Setting::put('crypto_cooldown_hours', '1');
        $w->release();
        $this->assertTrue($w->fresh()->cooldown_until->between(now()->addMinutes(55), now()->addMinutes(65)),
            'با تنظیمِ ۱، خنک‌شدن باید حدودِ یک ساعت باشد.');

        // خالی/نامعتبر = پیش‌فرضِ کد؛ سقف ۴۸
        \App\Models\Setting::put('crypto_cooldown_hours', null);
        $this->assertSame(\App\Models\CryptoWallet::COOLDOWN_HOURS, \App\Models\CryptoWallet::cooldownHours());

        \App\Models\Setting::put('crypto_cooldown_hours', '999');
        $this->assertSame(48, \App\Models\CryptoWallet::cooldownHours());
    }

    /**
     * 🔴 خودترمیمیِ استخر: ولتِ «مشغول»ی که پرداختش دیگر باز نیست آزاد می‌شود.
     *
     * رخدادِ واقعی: busy:1 با open_payments:0 در /system/crypto-status — یک
     * ادعای قدیمی، آدرس را برای همیشه از استخر کم کرده بود.
     */
    public function test_the_sweep_frees_wallets_held_by_dead_payments(): void
    {
        \Illuminate\Support\Facades\Http::fake();

        // گرفتارِ پرداختِ ناموجود ⇒ آزاد (با خنک‌شدن)
        $orphan = \App\Models\CryptoWallet::create([
            'chain' => 'tron', 'address' => 'TOrphan0000000000000000000000000000',
            'is_active' => true, 'busy_payment_id' => 999999,
        ]);

        // گرفتارِ پرداختِ هنوز باز ⇒ دست‌نخورده
        $c = \App\Models\Customer::create([
            'email' => 'cw'.random_int(1, 99999).'@example.com',
            'password' => 'secret1234', 'status' => 'active',
        ]);
        $inv = \App\Models\Invoice::create([
            'customer_id' => $c->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => 1000, 'tax' => 0, 'total' => 1000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);
        $cp = \App\Models\CryptoPayment::create([
            'invoice_id' => $inv->id, 'customer_id' => $c->id, 'chain' => 'tron',
            'asset' => 'USDT', 'network' => 'TRC20', 'address' => 'TBusy00000000000000000000000000000x',
            'amount_atomic' => 1000, 'decimals' => 6, 'invoice_amount' => 1000,
            'invoice_currency' => 'IRT', 'rate_micro' => 1, 'status' => 'seen',
            'expires_at' => now()->addHour(),
        ]);
        $held = \App\Models\CryptoWallet::create([
            'chain' => 'tron', 'address' => 'TBusy00000000000000000000000000000x',
            'is_active' => true, 'busy_payment_id' => $cp->id,
        ]);

        app(\App\Services\Payment\CryptoReconciler::class)->sweep();

        $this->assertNull($orphan->fresh()->busy_payment_id, 'ادعای مرده باید آزاد شود.');
        $this->assertNotNull($orphan->fresh()->cooldown_until, 'آزادسازی با خنک‌شدن — پرداختِ دیرهنگام.');
        $this->assertSame($cp->id, (int) $held->fresh()->busy_payment_id, 'پرداختِ باز نباید آزاد شود.');
    }
}
