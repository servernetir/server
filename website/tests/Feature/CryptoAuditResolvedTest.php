<?php

namespace Tests\Feature;

use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * حسابرسیِ رمزارز: پروندهٔ **رسیدگی‌شده** دیگر قرمز نیست.
 *
 * ═══ چرا این تفکیک لازم شد ═══
 *
 * نسخهٔ اولِ `crypto:audit` هر بار همان موردِ قدیمی را قرمز نشان می‌داد — حتی
 * بعد از اینکه مدیر سرویس را بسته بود. هشداری که همیشه روشن است از روزِ دوم
 * نادیده گرفته می‌شود، و آن‌وقت موردِ **تازه** هم بینِ همان قرمزیِ همیشگی گم
 * می‌شود. همان درسی که در `SystemHealth` و نشانِ منوی پنل ثبت شده.
 *
 * ⚠️ دو ادعا لازم است، نه یکی: «بسته دیگر قرمز نیست» به‌تنهایی با یک
 * `return` بی‌قید هم سبز می‌شود. ادعای دوم ثابت می‌کند حسابرسی هنوز چشم دارد.
 */
class CryptoAuditResolvedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * یک پروندهٔ «واریزیِ قدیمی» می‌سازد و تراکنشش را روی زنجیره جعل می‌کند.
     *
     * @return array{0: Invoice, 1: CryptoPayment}
     */
    private function staleCase(string $address, string $txid): array
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'au'.random_int(1, 999999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        $inv = Invoice::create([
            'customer_id' => $c->id, 'number' => 'INV-'.random_int(10000, 99999),
            'currency_code' => 'EUR', 'subtotal' => 10000, 'tax' => 0, 'total' => 10000,
            'paid' => 10000, 'status' => 'paid', 'issued_at' => now(), 'due_at' => now()->addDays(7),
        ]);

        $w = CryptoWallet::create(['chain' => 'tron', 'address' => $address, 'is_active' => true]);

        $cp = CryptoPayment::create([
            'invoice_id' => $inv->id, 'customer_id' => $c->id,
            'crypto_wallet_id' => $w->id, 'chain' => 'tron', 'asset' => 'USDT',
            'network' => 'TRC20', 'address' => $address,
            'amount_atomic' => 10_000_000, 'decimals' => 6,
            'invoice_amount' => 10000, 'invoice_currency' => 'EUR', 'rate_micro' => 1_000_000,
            'received_atomic' => 10_000_000, 'txid' => $txid,
            'status' => 'confirmed', 'confirmed_at' => now(),
            'expires_at' => now()->addMinutes(20),
        ]);

        // زنجیره می‌گوید این تراکنش ۹ روز پیش رخ داده — یعنی پیش از خودِ پرداخت
        Http::swap(new Factory);
        Http::fake(['api.trongrid.io/*' => function ($request) use ($txid) {
            preg_match('~/accounts/([^/]+)/~', $request->url(), $m);

            return Http::response(['data' => [[
                'transaction_id' => $txid,
                'to' => $m[1] ?? '',
                'value' => '10000000',
                'token_info' => ['decimals' => 6, 'symbol' => 'USDT'],
                'block_timestamp' => (now()->timestamp - 9 * 86400) * 1000,
            ]]], 200);
        }]);

        return [$inv, $cp];
    }

    /** 🔴 پروندهٔ باز باید قرمز باشد — وگرنه حسابرسی کور است. */
    public function test_an_open_case_is_reported(): void
    {
        [$inv] = $this->staleCase('TAudit01', 'TX-OPEN-CASE');

        $this->artisan('crypto:audit --days=60')
            ->expectsOutputToContain('🔴')
            ->doesntExpectOutputToContain('رسیدگی شده')
            ->assertSuccessful();
    }

    /** ✅ فاکتورِ لغوشده = رسیدگی‌شده، نه هشدارِ همیشگی. */
    public function test_a_canceled_invoice_is_marked_resolved(): void
    {
        [$inv] = $this->staleCase('TAudit02', 'TX-CANCELED');

        // ⚠️ همان املایی که `InvoiceCanceller` می‌نویسد: یک l
        $inv->forceFill(['status' => 'canceled'])->save();

        $this->artisan('crypto:audit --days=60')
            ->expectsOutputToContain('رسیدگی شده')
            ->doesntExpectOutputToContain('🔴 #')
            ->assertSuccessful();
    }

    /**
     * 🔴 آژیر فقط وقتی پروندهٔ **باز** هست زنگ می‌زند.
     *
     * آژیری که هر هفته صدا کند — حتی وقتی همه‌چیز سالم است — از هفتهٔ دوم
     * خوانده نمی‌شود، و آن‌وقت موردِ واقعی هم دیده نمی‌شود.
     */
    public function test_the_alert_only_fires_when_something_is_open(): void
    {
        [$inv] = $this->staleCase('TAudit04', 'TX-ALERT-OPEN');

        $spy = new class extends \App\Services\Notify\AdminNotifier
        {
            public int $fired = 0;

            public function __construct() {}

            public function event(string $title, array $rows = [], ?string $url = null, string $emoji = '🔔', array $buttons = [], ?string $key = null): void
            {
                $this->fired++;
            }
        };
        $this->app->instance(\App\Services\Notify\AdminNotifier::class, $spy);

        // پروندهٔ باز ⇒ باید زنگ بزند
        $this->artisan('crypto:audit --days=60 --notify')->assertSuccessful();
        $this->assertSame(1, $spy->fired, 'پروندهٔ باز بود ولی آژیر نزد');

        // حالا رسیدگی می‌شود ⇒ دیگر نباید زنگ بزند
        $inv->forceFill(['status' => 'canceled'])->save();

        $this->artisan('crypto:audit --days=60 --notify')->assertSuccessful();
        $this->assertSame(1, $spy->fired, 'با پروندهٔ رسیدگی‌شده هم آژیر زد — از هفتهٔ دوم نادیده می‌شود');
    }

    /** بی‌`--notify` هرگز آژیر نمی‌رود — اجرای دستیِ مدیر نباید به کسی پیام بدهد. */
    public function test_without_the_flag_no_alert_is_sent(): void
    {
        $this->staleCase('TAudit05', 'TX-NO-FLAG');

        $spy = new class extends \App\Services\Notify\AdminNotifier
        {
            public int $fired = 0;

            public function __construct() {}

            public function event(string $title, array $rows = [], ?string $url = null, string $emoji = '🔔', array $buttons = [], ?string $key = null): void
            {
                $this->fired++;
            }
        };
        $this->app->instance(\App\Services\Notify\AdminNotifier::class, $spy);

        $this->artisan('crypto:audit --days=60')->assertSuccessful();
        $this->assertSame(0, $spy->fired);
    }

    /** ✅ سرویسِ بسته‌شده هم یعنی رسیدگی‌شده — همان کاری که کارفرما کرد. */
    public function test_a_dead_service_marks_the_case_resolved(): void
    {
        [$inv] = $this->staleCase('TAudit03', 'TX-DEAD-SERVICE');

        $svc = Service::create([
            'customer_id' => $inv->customer_id, 'name' => 'سرویسِ لغوشده',
            'currency_code' => 'EUR', 'price' => 10000, 'cycle' => 'monthly',
            'status' => 'cancelled', 'cancelled_at' => now(),
        ]);

        $inv->forceFill(['service_id' => $svc->id])->save();

        $this->artisan('crypto:audit --days=60')
            ->expectsOutputToContain('رسیدگی شده')
            ->doesntExpectOutputToContain('🔴 #')
            ->assertSuccessful();
    }
}
