<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * لغوِ سفارشِ تحویل‌نشده توسط مشتری + بازگشتِ وجه به کیفِ پول.
 *
 * چرا این تست‌ها: این مسیر **پول برمی‌گرداند**. یک باگِ کوچک یعنی پرداختِ دوباره
 * به یک نفر، یا لغوِ سرویسِ شخصِ دیگر. هر دو باید غیرممکن باشند.
 */
class ServiceCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** سرویسِ تحویل‌نشده با یک فاکتورِ پرداخت‌شده */
    private function undelivered(Customer $c, int $paid = 500000, array $over = []): Service
    {
        $s = Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'سرور مجازی تستی', 'currency_code' => 'IRT',
            'price' => $paid, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'failed',
            'activated_at' => now(),
        ], $over));

        Invoice::create([
            'customer_id' => $c->id, 'service_id' => $s->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => $paid, 'tax' => 0, 'total' => $paid,
            'paid' => $paid, 'status' => 'paid', 'issued_at' => now(),
        ]);

        return $s;
    }

    public function test_customer_can_cancel_undelivered_order_and_gets_refund(): void
    {
        $c = $this->customer();
        $s = $this->undelivered($c, 500000);

        $this->assertSame(0, $c->creditBalance('IRT'));

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/cancel")
            ->assertRedirect();

        $this->assertSame('cancelled', $s->fresh()->status);
        $this->assertSame(500000, $c->creditBalance('IRT'), 'مبلغ باید به کیفِ پول برگردد');
        $this->assertDatabaseHas('credit_ledger', [
            'source_id' => $s->id, 'reason' => 'refund', 'amount' => 500000,
        ]);
    }

    /** 🔴 دوبار زدنِ دکمه نباید دو بار پول برگرداند */
    public function test_double_cancel_refunds_only_once(): void
    {
        $c = $this->customer();
        $s = $this->undelivered($c, 500000);

        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/cancel");
        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/cancel");

        $this->assertSame(500000, $c->creditBalance('IRT'), 'فقط یک‌بار باید برگردد');
        $this->assertSame(1, CreditEntry::where('source_id', $s->id)->where('reason', 'refund')->count());
    }

    /** 🔴 مشتری نباید بتواند سرویسِ شخصِ دیگری را لغو کند */
    public function test_cannot_cancel_someone_elses_service(): void
    {
        $mine = $this->customer();
        $other = $this->customer();
        $s = $this->undelivered($other, 500000);

        $this->actingAs($mine, 'customer')
            ->post("/account/services/{$s->id}/cancel")
            ->assertNotFound();

        $this->assertSame('awaiting_provision', $s->fresh()->status);
        $this->assertSame(0, $mine->creditBalance('IRT'));
        $this->assertSame(0, $other->creditBalance('IRT'));
    }

    /** سرویسِ سالمِ تحویل‌شده از این مسیر لغو نمی‌شود */
    public function test_delivered_service_cannot_be_cancelled_here(): void
    {
        $c = $this->customer();
        $s = $this->undelivered($c, 500000, ['status' => 'active', 'provision_status' => 'done']);

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/cancel")
            ->assertSessionHasErrors();

        $this->assertSame('active', $s->fresh()->status);
        $this->assertSame(0, $c->creditBalance('IRT'), 'نباید پولی برگردد');
    }

    /** کسرهای ساعتی هم باید در بازگشتِ وجه حساب شوند */
    public function test_hourly_charges_are_refunded_too(): void
    {
        $c = $this->customer();
        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'سرور ساعتی', 'currency_code' => 'IRT',
            'price' => 720000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'billing_mode' => 'hourly', 'hourly_rate_irt' => 1000,
            'status' => 'awaiting_provision', 'provision_status' => 'failed', 'activated_at' => now(),
        ]);

        // مشتری شارژ کرده و ۳ ساعت کسر شده
        CreditEntry::create(['customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => 50000,
            'balance_after' => 50000, 'reason' => 'topup', 'source_type' => Customer::class,
            'source_id' => $c->id, 'note' => 't']);
        CreditEntry::create(['customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => -3000,
            'balance_after' => 47000, 'reason' => 'cloud_hourly', 'source_type' => Service::class,
            'source_id' => $s->id, 'note' => '۳ ساعت']);

        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/cancel")->assertRedirect();

        // ۵۰۰۰۰ − ۳۰۰۰ + بازگشتِ ۳۰۰۰ = ۵۰۰۰۰
        $this->assertSame(50000, $c->creditBalance('IRT'));
        $this->assertSame('cancelled', $s->fresh()->status);
    }
}
