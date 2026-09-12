<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\SnappPayOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * پیگیریِ خودکارِ سفارش‌های معلق.
 *
 * ═══ چرا این کرون اجباری است ═══
 *
 * 🔴 مستنداتِ اسنپ‌پی: پیاده‌سازیِ خودکارِ Get Payment Status «به دلیل عدم
 * مغایرت بین اسنپ‌پی و پذیرنده الزامی می‌باشد».
 *
 * سناریوی واقعی: مشتری پرداخت می‌کند و وسطِ بازگشت مرورگر را می‌بندد. پول از
 * حسابش کم شده، ولی نزدِ ما سفارش `pending` مانده و فاکتور پرداخت‌نشده. هیچ
 * خطایی هم تولید نمی‌شود — فقط یک مشتریِ عصبانی چند روز بعد.
 */
class SnappPayReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'snapppay.enabled'       => true,
            'snapppay.base_url'      => 'https://snapppay.test',
            'snapppay.client_id'     => 'cid',
            'snapppay.client_secret' => 'secret',
            'snapppay.username'      => 'user',
            'snapppay.password'      => 'pass',
        ]);
    }

    /** سفارشی که کاربر هرگز به صفحهٔ بازگشت نرساند. */
    private function strandedOrder(string $state = 'pending'): SnappPayOrder
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'r'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        $inv = Invoice::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT',
            'subtotal' => 0, 'tax' => 0, 'total' => 0, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv->id, 'title' => 'سرور', 'quantity' => 1,
            'unit_price' => 500_000, 'line_total' => 500_000,
            'discount' => 0, 'tax_rate_bp' => 0, 'tax_amount' => 0,
        ]);

        $inv->recalculateTotals();
        $inv->save();

        $payment = Payment::create([
            'invoice_id' => $inv->id, 'customer_id' => $c->id,
            'gateway' => 'snapppay', 'currency_code' => 'IRT',
            'amount' => $inv->total, 'status' => 'redirected',
            'external_ref' => 'PT-'.random_int(1000, 9999),
        ]);

        $order = SnappPayOrder::create([
            'payment_id' => $payment->id, 'invoice_id' => $inv->id,
            'customer_id' => $c->id, 'transaction_id' => 'S'.random_int(1000, 9999),
            'state' => $state, 'amount' => $inv->total, 'mobile' => $c->phone,
            'cart' => ['amount' => $inv->total * 10],
        ]);

        /*
        | ⚠️ `created_at` را باید **بعد از** ساخت جا انداخت.
        |
        | در `create()` نادیده گرفته می‌شود چون در `$fillable` نیست، و Eloquent
        | زمانِ حالا را می‌نشاند — یعنی سفارش «تازه» می‌مانَد و کرون عمداً
        | ردش می‌کند. تستِ اول دقیقاً به همین دلیل قرمز شد، نه به‌خاطرِ کد.
        */
        $order->forceFill(['created_at' => now()->subHour()])->save();

        return $order->fresh();
    }

    private function fake(array $over): void
    {
        Http::fake($over + [
            '*oauth/token' => Http::response(['access_token' => 'jwt', 'expires_in' => 3600]),
        ]);
    }

    // ───────────────────────────────────────────────────────────────

    /** 🔴 هستهٔ ماجرا: پولی که معلق مانده باید تمام شود و فاکتور تسویه گردد. */
    public function test_a_stranded_order_is_finished_and_the_invoice_is_paid(): void
    {
        $this->fake([
            '*payment/v1/verify' => Http::response(['successful' => true, 'response' => ['transactionId' => 'S1']]),
            '*payment/v1/settle' => Http::response(['successful' => true, 'response' => ['transactionId' => 'S1']]),
        ]);

        $order = $this->strandedOrder();

        $this->artisan('snapppay:reconcile')->assertOk();

        $this->assertSame('settled', $order->fresh()->state);
        $this->assertSame('paid', $order->invoice->fresh()->status, 'فاکتور هم باید تسویه شود');
        $this->assertTrue($order->payment->fresh()->isPaid());
    }

    /** سفارشی که فقط settleاش مانده هم برداشته می‌شود. */
    public function test_a_verified_but_unsettled_order_gets_settled(): void
    {
        $this->fake([
            '*payment/v1/settle' => Http::response(['successful' => true, 'response' => ['transactionId' => 'S1']]),
        ]);

        $order = $this->strandedOrder('verified');

        $this->artisan('snapppay:reconcile')->assertOk();

        $this->assertSame('settled', $order->fresh()->state);
        $this->assertSame('paid', $order->invoice->fresh()->status);
    }

    /** اگر اسنپ‌پی بگوید لغو شده، سفارش بسته می‌شود و فاکتور دست‌نخورده می‌مانَد. */
    public function test_a_cancelled_order_is_closed_not_paid(): void
    {
        $this->fake([
            '*payment/v1/verify'  => Http::response(['successful' => false, 'errorData' => ['message' => 'no']], 400),
            '*payment/v1/status*' => Http::response([
                'successful' => true,
                'response'   => ['transactionId' => 'S1', 'status' => 'CANCEL', 'amount' => 5_000_000],
            ]),
        ]);

        $order = $this->strandedOrder();

        $this->artisan('snapppay:reconcile')->assertOk();

        $this->assertSame('failed', $order->fresh()->state);
        $this->assertSame('unpaid', $order->invoice->fresh()->status);
    }

    /**
     * ⚠️ وضعیتِ PENDING نباید سفارش را ببندد — هنوز در جریان است و اجرای
     * بعدیِ کرون باید دوباره برش دارد.
     */
    public function test_a_pending_order_stays_open_for_the_next_run(): void
    {
        $this->fake([
            '*payment/v1/verify'  => Http::response(['successful' => false], 500),
            '*payment/v1/status*' => Http::response([
                'successful' => true,
                'response'   => ['transactionId' => 'S1', 'status' => 'PENDING', 'amount' => 5_000_000],
            ]),
        ]);

        $order = $this->strandedOrder();

        $this->artisan('snapppay:reconcile')->assertOk();

        $this->assertSame('pending', $order->fresh()->state, 'باز می‌مانَد');
        $this->assertSame('unpaid', $order->invoice->fresh()->status);
    }

    /** سفارشِ تازه دست نمی‌خورد — کاربرش احتمالاً هنوز روی صفحهٔ اسنپ‌پی است. */
    public function test_a_fresh_order_is_left_alone(): void
    {
        $this->fake([
            '*payment/v1/verify' => Http::response(['successful' => true, 'response' => ['transactionId' => 'S1']]),
            '*payment/v1/settle' => Http::response(['successful' => true, 'response' => ['transactionId' => 'S1']]),
        ]);

        $order = $this->strandedOrder();
        $order->forceFill(['created_at' => now()])->save();

        $this->artisan('snapppay:reconcile')->assertOk();

        $this->assertSame('pending', $order->fresh()->state);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'payment/v1/verify'));
    }

    /** سفارشِ تمام‌شده دوباره برداشته نمی‌شود. */
    public function test_a_settled_order_is_not_touched_again(): void
    {
        $this->fake([
            '*payment/v1/verify' => Http::response(['successful' => true, 'response' => ['transactionId' => 'S1']]),
        ]);

        $order = $this->strandedOrder('settled');

        $this->artisan('snapppay:reconcile')->assertOk();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'payment/v1/verify'));
    }

    /** درگاهِ خاموش: کرون باید بی‌صدا و بی‌تماس برگردد. */
    public function test_a_disabled_gateway_makes_the_cron_a_no_op(): void
    {
        config(['snapppay.enabled' => false]);
        $this->fake([]);

        $this->strandedOrder();

        $this->artisan('snapppay:reconcile')->assertOk();

        Http::assertNothingSent();
    }
}
