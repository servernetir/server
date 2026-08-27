<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\UndeliveredRefund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * بازگشتِ خودکارِ وجه برای سرویسِ پول‌گرفته و هرگز تحویل‌نشده، در لحظهٔ لغو.
 *
 * ═══ چرا (۵ شهریور ۱۴۰۵) ═══
 *
 * هاستِ پایتونِ مشتری تحویل نشد (سرورِ ایران در دسترس نبود)، مشتری لغو کرد و
 * مدیر مجبور شد دستی لغو بزند و دستی اعتبار بدهد. این تست‌ها قرارداد را قفل
 * می‌کنند: لغوِ تحویل‌نشده = برگشتِ paidِ فاکتورها به کیفِ پول، **یک بار**،
 * فقط برای سرویسی که واقعاً چیزی آن‌طرف ندارد.
 */
class UndeliveredRefundTest extends TestCase
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

    /** @return array{0: Customer, 1: Service, 2: Invoice} */
    private function paidUndelivered(string $provisionStatus = 'failed'): array
    {
        $c = Customer::create([
            'email' => 'u'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);

        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'هاست پایتون — PY-5', 'currency_code' => 'IRT',
            'price' => 490000, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'active',
            'provision_status' => $provisionStatus,
        ]);

        $inv = Invoice::create([
            'customer_id' => $c->id, 'service_id' => $s->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => 490000, 'tax' => 49000,
            'total' => 539000, 'paid' => 539000, 'status' => 'paid',
            'issued_at' => now(), 'paid_at' => now(),
        ]);

        return [$c, $s, $inv];
    }

    // ═══════════ خودِ سرویسِ ریفاند ═══════════

    /** 🔴 لغوِ تحویل‌نشده = کلِ paid به کیفِ پول، فاکتور refunded */
    public function test_cancelling_an_undelivered_service_refunds_the_paid_invoice(): void
    {
        [$c, $s, $inv] = $this->paidUndelivered();

        $total = app(UndeliveredRefund::class)->maybeRefund($s, 'staff');

        $this->assertSame(539000, $total);
        $this->assertSame(539000, $c->creditBalance('IRT'));

        $entry = CreditEntry::where('reason', UndeliveredRefund::REASON)->first();
        $this->assertNotNull($entry);
        $this->assertSame($inv->id, (int) $entry->source_id);

        $this->assertSame('refunded', $inv->fresh()->status);
    }

    /** 🔴 دو بار لغو (یا دو اجرای هم‌زمانِ مدیر و مشتری) = فقط یک برگشت */
    public function test_the_refund_is_idempotent(): void
    {
        [$c, $s] = $this->paidUndelivered();

        $svc = app(UndeliveredRefund::class);
        $svc->maybeRefund($s, 'staff');
        $again = $svc->maybeRefund($s->fresh(), 'customer');

        $this->assertSame(0, $again, 'بارِ دوم چیزی برنگرداند.');
        $this->assertSame(539000, $c->creditBalance('IRT'));
        $this->assertSame(1, CreditEntry::where('reason', UndeliveredRefund::REASON)->count());
    }

    /** سرویسِ تحویل‌شده (done) هرگز خودکار ریفاند نمی‌شود — تصمیمِ مدیر است */
    public function test_a_delivered_service_is_never_auto_refunded(): void
    {
        [$c, $s] = $this->paidUndelivered('done');

        $total = app(UndeliveredRefund::class)->maybeRefund($s, 'staff');

        $this->assertSame(0, $total);
        $this->assertSame(0, $c->creditBalance('IRT'));
    }

    /** سرویسِ ساعتی (کیفِ پولی) مسیرِ خودش را دارد و این‌جا مستثناست */
    public function test_an_hourly_service_is_excluded(): void
    {
        [$c, $s] = $this->paidUndelivered();
        $s->forceFill(['billing_mode' => 'hourly'])->save();

        $total = app(UndeliveredRefund::class)->maybeRefund($s->fresh(), 'staff');

        $this->assertSame(0, $total);
        $this->assertSame(0, $c->creditBalance('IRT'));
    }

    /** فاکتورِ پرداخت‌نشده چیزی برنمی‌گردانَد (paid=0 یعنی پولی نگرفته‌ایم) */
    public function test_an_unpaid_invoice_refunds_nothing(): void
    {
        [$c, $s, $inv] = $this->paidUndelivered();
        $inv->forceFill(['paid' => 0, 'status' => 'unpaid'])->save();

        $total = app(UndeliveredRefund::class)->maybeRefund($s, 'staff');

        $this->assertSame(0, $total);
        $this->assertSame(0, $c->creditBalance('IRT'));
    }

    // ═══════════ سیم‌کشی به مسیرهای لغو ═══════════

    /** 🔴 لغو از پنلِ مدیر همان مسیر را می‌دواند و مبلغ را در فلش می‌گوید */
    public function test_the_admin_cancel_path_triggers_the_refund(): void
    {
        [$c, $s] = $this->paidUndelivered();

        $res = $this->actingAs($this->staff(), 'web')
            ->post('/admin/services/'.$s->id.'/status', ['status' => 'cancelled']);

        $res->assertRedirect();
        $this->assertSame('cancelled', $s->fresh()->status);
        $this->assertSame(539000, $c->creditBalance('IRT'));
        $this->assertStringContainsString('برگشت', (string) session('ok'));
    }

    /** لغوِ سرویسِ تحویل‌شده از پنلِ مدیر، هیچ اعتباری جابه‌جا نمی‌کند */
    public function test_the_admin_cancel_of_a_delivered_service_moves_no_money(): void
    {
        [$c, $s] = $this->paidUndelivered('done');

        $this->actingAs($this->staff(), 'web')
            ->post('/admin/services/'.$s->id.'/status', ['status' => 'cancelled']);

        $this->assertSame('cancelled', $s->fresh()->status);
        $this->assertSame(0, $c->creditBalance('IRT'));
    }
}
