<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Services\Cloud\HourlyHold;
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * کیفِ پول روی نصبی که جدول‌های AI را **ندارد**.
 *
 * ═══ رخداد (شهریور ۱۴۰۵، پیش‌پروازِ انتشار) ═══
 *
 * DRY روی سرورِ زنده گفت `NEW app/Services/Finance/Wallet.php` — یعنی کلِ
 * بازسازیِ کیفِ پول (M3) هرگز منتشر نشده بود. و مهاجرت‌های AI روی MariaDB با
 * خطای کلیدِ خارجی شکسته‌اند، پس `ai_reservations` آن‌جا نیست.
 *
 * 🔴 `reservedOf()` سرِ راهِ **هر نوشتنِ پولی** است (فاکتور، دامنه، کسرِ ساعتی،
 * تنظیمِ مدیر). اگر روی جدولِ ناموجود پرس‌وجو کند، نخستین انتشار کلِ مسیرِ پولِ
 * سایت را می‌خواباند — و علتش هیچ ربطی به AI ندارد.
 */
class WalletWithoutAiTablesTest extends TestCase
{
    use RefreshDatabase;

    private function customer(int $credit): Customer
    {
        $c = Customer::create([
            'email' => 'noai'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);

        CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => $credit,
            'balance_after' => $credit, 'reason' => 'topup', 'source_type' => Customer::class,
            'source_id' => $c->id, 'note' => 'test',
        ]);

        return $c;
    }

    /** جدول را واقعاً می‌اندازیم — شبیه‌سازیِ config کافی نیست */
    private function dropAiReservations(): void
    {
        Schema::dropIfExists('ai_reservations');
        Wallet::flushSchemaCache();
        HourlyHold::flush();
    }

    protected function tearDown(): void
    {
        Wallet::flushSchemaCache();
        parent::tearDown();
    }

    public function test_money_still_moves_when_the_ai_reservation_table_is_missing(): void
    {
        $c = $this->customer(500_000);
        $this->dropAiReservations();

        $wallet = app(Wallet::class);

        $this->assertSame(0, $wallet->reservedOf($c->id));
        $this->assertSame(500_000, $wallet->availableOf($c->id));

        $wallet->debit($c->id, 'IRT', 120_000, 'invoice', $c, 'test');

        $this->assertSame(380_000, $wallet->balanceOf($c->id));
        $this->assertSame(380_000, $wallet->availableOf($c->id));
    }

    /** ذخیرهٔ نگهداریِ ساعتی مستقل از AI است و باید همان‌جا هم نگه‌دارنده بمانَد */
    public function test_the_hourly_hold_reserve_still_counts_without_ai_tables(): void
    {
        $c = $this->customer(100_000);
        $this->dropAiReservations();

        \App\Models\Service::create([
            'customer_id' => $c->id, 'name' => 'VPS ساعتی', 'currency_code' => 'IRT',
            'price' => 570_000, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => 800, 'status' => 'active', 'activated_at' => now(),
            'on_credit_out' => 'suspend', 'hold_rate_irt' => 700, 'hold_reserve_irt' => 16_800,
        ]);

        $wallet = app(Wallet::class);

        $this->assertSame(16_800, $wallet->reservedOf($c->id));
        $this->assertSame(83_200, $wallet->availableOf($c->id));
    }
}
