<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\SnappPayOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * پنلِ ادمینِ اسنپ‌پی — همان سناریویی که در جلسهٔ دمو اجرا می‌شود.
 *
 * ═══ سناریوی دمو، کلمه‌به‌کلمه از پیامِ اسنپ‌پی ═══
 *
 *   «شناسه تراکنش اسنپ پی در بخش سفارشات پنل ادمین سایت پذیرنده جستجو بشود.
 *    سپس … ابتدا محصول بدون تخفیف با تعداد 2 را به تعداد 1 کاهش دهید و …
 *    محصولی که از ابتدا تخفیف دار بود را از سفارش حذف کنید.
 *    در نهایت سفارش را کنسل کنید.»
 *
 *   «قابلیت آپدیت چندین بار روی یک سفارش قابل اعمال باشد و کنسل بر روی
 *    سفارشی که آپدیت شده باشد هم قابل اعمال باشد.»
 *
 * 🔴 آخرین جمله دلیلِ وجودِ `test_the_full_demo_scenario` است: دو کاهشِ پشتِ
 * سرِ هم روی یک فاکتور، دومی را در دفترِ مالی بی‌صدا می‌بلعید.
 */
class SnappPayAdminTest extends TestCase
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

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    /**
     * سفارشِ نهایی‌شده با دو ردیف، مثلِ سبدِ دمو:
     *   · محصولِ بی‌تخفیف × ۲  (۲۰۰٬۰۰۰ ت)
     *   · محصولِ تخفیف‌دار × ۱  (۳۰۰٬۰۰۰ ت، ۵۰٬۰۰۰ تخفیف)
     */
    private function settledOrder(): SnappPayOrder
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'd'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        $inv = Invoice::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT',
            'subtotal' => 0, 'tax' => 0, 'total' => 0, 'paid' => 0,
            'status' => 'paid', 'issued_at' => now(), 'paid_at' => now(),
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv->id, 'title' => 'بی‌تخفیف', 'quantity' => 2,
            'unit_price' => 100_000, 'line_total' => 200_000,
            'discount' => 0, 'tax_rate_bp' => 0, 'tax_amount' => 0,
        ]);

        InvoiceItem::create([
            'invoice_id' => $inv->id, 'title' => 'تخفیف‌دار', 'quantity' => 1,
            'unit_price' => 300_000, 'line_total' => 300_000,
            'discount' => 50_000, 'tax_rate_bp' => 0, 'tax_amount' => 0,
        ]);

        $inv->recalculateTotals();
        $inv->forceFill(['paid' => $inv->total])->save();   // ۴۵۰٬۰۰۰

        $payment = Payment::create([
            'invoice_id' => $inv->id, 'customer_id' => $c->id,
            'gateway' => 'snapppay', 'currency_code' => 'IRT',
            'amount' => $inv->total, 'status' => 'paid', 'paid_at' => now(),
            'external_ref' => 'PT-'.random_int(1000, 9999),
        ]);

        return SnappPayOrder::create([
            'payment_id' => $payment->id, 'invoice_id' => $inv->id,
            'customer_id' => $c->id, 'transaction_id' => 'S'.random_int(10000, 99999),
            'state' => 'settled', 'amount' => $inv->total, 'mobile' => $c->phone,
            'settled_at' => now(),
        ]);
    }

    private function fakeOk(): void
    {
        Http::fake([
            '*oauth/token'       => Http::response(['access_token' => 'jwt', 'expires_in' => 3600]),
            '*payment/v1/update' => Http::response(['successful' => true, 'response' => ['transactionId' => 'S1']]),
            '*payment/v1/cancel' => Http::response(['successful' => true, 'response' => ['transactionId' => 'S1']]),
        ]);
    }

    private function refundTotal(): int
    {
        return (int) BusinessEntry::where('kind', 'refund')->sum('amount');
    }

    // ───────────────────────── نمایش و جست‌وجو ─────────────────────────

    /** 🔴 خواستهٔ صریحِ اسنپ‌پی: شمارهٔ تراکنش در پنل دیده و جست‌وجو شود. */
    public function test_the_transaction_id_is_searchable(): void
    {
        $order = $this->settledOrder();
        $other = $this->settledOrder();

        $html = $this->actingAs($this->admin(), 'web')
            ->get('/admin/snapppay?q='.$order->transaction_id)
            ->assertOk()->getContent();

        $this->assertStringContainsString($order->transaction_id, $html);
        $this->assertStringNotContainsString($other->transaction_id, $html, 'فقط همان سفارش');
    }

    public function test_the_order_page_renders(): void
    {
        $order = $this->settledOrder();

        $this->actingAs($this->admin(), 'web')
            ->get('/admin/snapppay/'.$order->id)
            ->assertOk()
            ->assertSee($order->transaction_id)
            ->assertSee('کاهشِ سفارش')
            ->assertSee('لغوِ کاملِ سفارش');
    }

    public function test_a_customer_cannot_reach_the_panel(): void
    {
        $order = $this->settledOrder();

        $this->actingAs($order->customer, 'customer')
            ->get('/admin/snapppay')
            ->assertRedirect(route('admin.login'));
    }

    // ───────────────────────── سناریوی کاملِ دمو ─────────────────────────

    public function test_the_full_demo_scenario(): void
    {
        $this->fakeOk();
        $order = $this->settledOrder();
        $admin = $this->admin();
        [$plain, $discounted] = $order->invoice->items()->orderBy('id')->get()->all();

        // ۱) محصولِ بی‌تخفیف از ۲ به ۱
        $this->actingAs($admin, 'web')
            ->post('/admin/snapppay/'.$order->id.'/update', [
                'confirm' => 'UPDATE',
                'qty' => [$plain->id => 1, $discounted->id => 1],
            ])->assertSessionHas('ok');

        $this->assertSame(350_000, $order->invoice->fresh()->total);
        $this->assertSame(100_000, $this->refundTotal());

        // ۲) محصولِ تخفیف‌دار کاملاً حذف
        $this->actingAs($admin, 'web')
            ->post('/admin/snapppay/'.$order->id.'/update', [
                'confirm' => 'UPDATE',
                'qty' => [$plain->id => 1, $discounted->id => 0],
            ])->assertSessionHas('ok');

        $inv = $order->invoice->fresh();
        $this->assertSame(100_000, $inv->total);
        $this->assertSame(1, $inv->items()->count(), 'ردیفِ حذف‌شده واقعاً رفته، نه count صفر');

        // 🔴 دومین بازگشت هم باید در دفتر باشد — همان باگی که این تست برایش نوشته شد
        $this->assertSame(350_000, $this->refundTotal());

        // ۳) لغوِ سفارشِ کاهش‌یافته
        $this->actingAs($admin, 'web')
            ->post('/admin/snapppay/'.$order->id.'/cancel', ['confirm' => 'CANCEL'])
            ->assertSessionHas('ok');

        $this->assertSame('canceled', $order->fresh()->state);
        $this->assertSame('refunded', $order->invoice->fresh()->status);

        // جمعِ کلِ بازگشت = کلِ پرداختِ اولیه، نه بیشتر نه کمتر
        $this->assertSame(450_000, $this->refundTotal());

        // هر سه عمل، با نامِ ادمین، در ردِ حسابرسی
        $this->assertSame(3, $order->events()->whereIn('kind', ['update', 'cancel'])
            ->where('ok', true)->where('created_by', $admin->id)->count());
    }

    /** مستندات: آیتمِ حذف‌شده باید از cartItems برداشته شود. */
    public function test_a_removed_item_is_absent_from_the_cart_sent(): void
    {
        $this->fakeOk();
        $order = $this->settledOrder();
        [$plain, $discounted] = $order->invoice->items()->orderBy('id')->get()->all();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/snapppay/'.$order->id.'/update', [
                'confirm' => 'UPDATE',
                'qty' => [$plain->id => 2, $discounted->id => 0],
            ]);

        Http::assertSent(function ($r) use ($discounted) {
            if (! str_contains($r->url(), 'payment/v1/update')) {
                return false;
            }

            $ids = array_column($r['cartList'][0]['cartItems'], 'id');

            return ! in_array($discounted->id, $ids, true) && $r['amount'] === 2_000_000;
        });
    }

    // ───────────────────────────── گاردها ─────────────────────────────

    /** ⚠️ تأییدیهٔ متنی اجباری است: یک کلیکِ اشتباه نباید بدهیِ مشتری را برگرداند. */
    public function test_nothing_happens_without_the_typed_confirmation(): void
    {
        $this->fakeOk();
        $order = $this->settledOrder();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/snapppay/'.$order->id.'/cancel', ['confirm' => 'yes'])
            ->assertSessionHasErrors('confirm');

        $this->assertSame('settled', $order->fresh()->state);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'payment/v1/cancel'));
    }

    /** 🔴 مستندات: «درخواست آپدیت باید مبلغی کمتر از مبلغ کل سفارش باشد.» */
    public function test_an_update_cannot_raise_the_amount(): void
    {
        $this->fakeOk();
        $order = $this->settledOrder();
        [$plain, $discounted] = $order->invoice->items()->orderBy('id')->get()->all();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/snapppay/'.$order->id.'/update', [
                'confirm' => 'UPDATE',
                'qty' => [$plain->id => 5, $discounted->id => 1],
            ])->assertSessionHas('err');

        $this->assertSame(450_000, $order->invoice->fresh()->total);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'payment/v1/update'));
    }

    /**
     * 🔴 اگر اسنپ‌پی نپذیرفت، فاکتور **نباید** کم‌شده بمانَد — وگرنه مبلغِ ما و
     * بدهیِ مشتری از هم جدا می‌شوند و هیچ‌کس نمی‌فهمد.
     */
    public function test_a_rejected_update_leaves_the_invoice_untouched(): void
    {
        Http::fake([
            '*oauth/token'       => Http::response(['access_token' => 'jwt', 'expires_in' => 3600]),
            '*payment/v1/update' => Http::response([
                'successful' => false, 'errorData' => ['errorCode' => 400, 'message' => 'rejected'],
            ], 400),
        ]);

        $order = $this->settledOrder();
        [$plain, $discounted] = $order->invoice->items()->orderBy('id')->get()->all();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/snapppay/'.$order->id.'/update', [
                'confirm' => 'UPDATE',
                'qty' => [$plain->id => 1, $discounted->id => 0],
            ])->assertSessionHas('err');

        $inv = $order->invoice->fresh();
        $this->assertSame(450_000, $inv->total, 'کاهش برگشت خورد');
        $this->assertSame(2, $inv->items()->count(), 'ردیفِ حذف‌شده برگشت');
        $this->assertSame(0, $this->refundTotal(), 'پولی برنگشته، پس دفتر هم چیزی ثبت نکرد');
        $this->assertSame(1, $order->events()->where('kind', 'update')->where('ok', false)->count());
    }

    public function test_an_unsettled_order_cannot_be_changed(): void
    {
        $this->fakeOk();
        $order = $this->settledOrder();
        $order->forceFill(['state' => 'verified'])->save();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/snapppay/'.$order->id.'/cancel', ['confirm' => 'CANCEL'])
            ->assertSessionHas('err');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'payment/v1/cancel'));
    }
}
