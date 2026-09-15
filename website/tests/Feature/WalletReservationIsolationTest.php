<?php

namespace Tests\Feature;

use App\Models\AiReservation;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Ai\AiReservations;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M3-correct — جداسازیِ پولِ رزروشده از خرجِ عادیِ سیستم.
 *
 * 🔴 نکتهٔ اصلاحی: رزرو تنها داخلِ AiReservations محافظ نیست. هر مسیرِ
 *    کسرِ عادی (پرداختِ فاکتور از اعتبار، مترِ ساعتی، سفارشِ دامنه،
 *    تنظیمِ دستیِ مدیر) باید «در دسترس» را ببیند — منهایِ رزروهایِ زنده.
 *    این تست‌ها همان سناریوی ردِ M3 را می‌سازند که رزروِ ۸k + خرجِ عادیِ
 *    ۶k + تسویهٔ ۸k قرار بود −۴k بسازد.
 *
 * ⚠️ SQLite تک‌رشته‌ای است — این‌جا معنایِ پول و ترتیبِ منطقی سنجیده
 * می‌شود؛ اثباتِ هم‌زمانیِ واقعی کارِ harnessِ MariaDB است.
 */
class WalletReservationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'email' => 'wi'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => null, 'status' => 'active', 'locale' => 'fa',
        ], $over));
    }

    private function funded(int $credit = 10_000): array
    {
        $c = $this->customer();
        app(Wallet::class)->credit($c->id, 'IRT', $credit, 'topup', $c, 'شارژِ آزمون');

        return [$c, $credit];
    }

    private function wallet(): Wallet
    {
        return app(Wallet::class);
    }

    private function reservations(): AiReservations
    {
        return app(AiReservations::class);
    }

    private function unpaidInvoice(Customer $c, int $total): Invoice
    {
        return Invoice::create([
            'customer_id' => $c->id, 'kind' => 'hosting', 'currency_code' => 'IRT',
            'subtotal' => $total, 'tax' => 0, 'total' => $total,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);
    }

    // ═══════ ۱. خرجِ عادی پولِ رزروشده را نمی‌خورد ═══════

    public function test_ordinary_debit_cannot_consume_reserved_funds(): void
    {
        // سناریوی ردِ M3: 10k موجودی، 8k رزرو — کسرِ عادیِ 3k باید رد شود
        [$c] = $this->funded(10_000);
        $w = $this->wallet();
        $svc = $this->reservations();

        $this->assertTrue($svc->reserve($c->id, 8_000, 'k1')->ok);

        try {
            $w->debit($c->id, 'IRT', 3_000, 'test_debit', $c);
            $this->fail('کسرِ عادی نباید به پولِ رزروشده دست می‌زد');
        } catch (WalletException $e) {
            $this->assertSame('insufficient_funds', $e->errorCode);
        }

        $this->assertSame(10_000, $w->balanceOf($c->id), 'کسرِ ردشده دفتر را ننوشت');
        $this->assertSame(2_000, $w->availableOf($c->id));
        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)->count());
    }

    // ═══════ ۲. کسرِ مجاز داخلِ در دسترس + تسویه = دقیقاً صفر ═══════

    public function test_allowed_ordinary_debit_then_settle_lands_exactly_on_zero(): void
    {
        [$c] = $this->funded(10_000);
        $w = $this->wallet();
        $svc = $this->reservations();

        $res = $svc->reserve($c->id, 8_000, 'k1')->reservation;
        $w->debit($c->id, 'IRT', 2_000, 'test_debit', $c, 'خرجِ عادیِ مجاز');

        // در دسترس الان صفر است — تسویه با exclusionِ رزروِ خودش جلو می‌رود
        $this->assertSame(0, $w->availableOf($c->id));
        $outcome = $svc->settle($res);

        $this->assertTrue($outcome->ok, 'تسویه نباید دوباره رزروِ خودش را کم کند');
        $this->assertSame(0, $w->balanceOf($c->id), '10k − 2k − 8k = 0، نه منفی');
        $this->assertSame(0, $w->availableOf($c->id));
        $this->assertSame(0, $svc->heldOf($c->id));
    }

    // ═══════ ۳. ترتیب برعکس: خرجِ عادی اول، رزرو دوم ═══════

    public function test_reservation_cannot_consume_what_ordinary_debit_already_took(): void
    {
        [$c] = $this->funded(10_000);
        $w = $this->wallet();
        $svc = $this->reservations();

        $w->debit($c->id, 'IRT', 6_000, 'test_debit', $c);

        $this->assertFalse($svc->reserve($c->id, 5_000, 'k1')->ok,
            'رزرو هم باید فقط «در دسترسِ» بعد از خرجِ عادی را ببیند');
        $this->assertSame('insufficient_funds', $svc->reserve($c->id, 5_000, 'k1')->code);

        $this->assertTrue($svc->reserve($c->id, 4_000, 'k2')->ok, 'مرزِ دقیق مجاز است');
        $this->assertSame(4_000, $svc->heldOf($c->id));
    }

    // ═══════ ۴. دو رزرو + یک خرجِ عادی، یک موجودی مشترک ═══════

    public function test_two_reservations_and_ordinary_debit_share_one_balance(): void
    {
        [$c] = $this->funded(10_000);
        $w = $this->wallet();
        $svc = $this->reservations();

        $this->assertTrue($svc->reserve($c->id, 4_000, 'k1')->ok);
        $this->assertTrue($svc->reserve($c->id, 3_000, 'k2')->ok);

        $w->debit($c->id, 'IRT', 3_000, 'test_debit', $c, 'آخرینِ در دسترس');
        $this->assertSame(0, $w->availableOf($c->id));

        $this->expectException(WalletException::class);
        $w->debit($c->id, 'IRT', 1, 'test_debit', $c);
    }

    // ═══════ ۵. تسویهٔ تکراری دفتر را دوبار نمی‌نویسد ═══════

    public function test_settlement_after_allowed_debit_never_goes_negative_and_never_duplicates(): void
    {
        [$c] = $this->funded(10_000);
        $w = $this->wallet();
        $svc = $this->reservations();

        $res = $svc->reserve($c->id, 6_000, 'k1')->reservation;
        $w->debit($c->id, 'IRT', 4_000, 'test_debit', $c);

        $first = $svc->settle($res);
        $again = $svc->settle($res->fresh());

        $this->assertTrue($first->ok);
        $this->assertTrue($again->ok);
        $this->assertTrue($again->already, 'تسویهٔ دوباره «قبلاً» است، نه کسرِ دوباره');
        $this->assertSame(0, $w->balanceOf($c->id), '10k − 4k − 6k = 0');
        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)->where('reason', 'ai_reservation')->count(),
            'تسویه دقیقاً یک ردیفِ دفتر می‌نویسد');
    }

    // ═══════ ۶. آزادسازی پول را برمی‌گرداند به در دسترس ═══════

    public function test_release_returns_funds_to_available_pool(): void
    {
        [$c] = $this->funded(10_000);
        $w = $this->wallet();
        $svc = $this->reservations();

        $res = $svc->reserve($c->id, 5_000, 'k1')->reservation;
        $w->debit($c->id, 'IRT', 2_000, 'test_debit', $c);
        $this->assertSame(3_000, $w->availableOf($c->id));

        $this->assertTrue($svc->release($res)->ok);

        $this->assertSame(8_000, $w->availableOf($c->id), 'آزادسازی پول را به استخر برگرداند');
        $this->assertSame(0, $svc->heldOf($c->id));
        $this->assertSame(8_000, $w->balanceOf($c->id), 'آزادسازی دفتر نمی‌نویسد');
    }

    // ═══════ ۷. مسیرِ واقعیِ فاکتور: پرداخت از اعتبار رزروآگاه است ═══════

    public function test_pay_credit_is_denied_when_reservation_consumes_funds(): void
    {
        [$c] = $this->funded(10_000);
        $inv = $this->unpaidInvoice($c, 8_000);
        $svc = $this->reservations();

        // 10k موجودی، 8k رزرو — فاکتورِ 8k نباید از پولِ رزروشده بخرد
        $this->assertTrue($svc->reserve($c->id, 8_000, 'k1')->ok);

        $this->actingAs($c, 'customer')
            ->post('/account/invoices/'.$inv->id.'/pay-credit')
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame('unpaid', $inv->fresh()->status, 'فاکتور پرداخت‌نشده ماند');
        $this->assertSame(10_000, $this->wallet()->balanceOf($c->id), 'هیچ کسری نوشته نشد');
    }

    // ═══════ ۸. همان مسیر وقتی جا هست: idempotency حفظ می‌شود ═══════

    public function test_pay_credit_still_pays_and_is_idempotent_under_wallet(): void
    {
        [$c] = $this->funded(10_000);
        $w = $this->wallet();
        $inv = $this->unpaidInvoice($c, 8_000);

        $this->actingAs($c, 'customer')
            ->post('/account/invoices/'.$inv->id.'/pay-credit')
            ->assertRedirect();

        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertSame(2_000, $w->balanceOf($c->id));

        // دوباره — دوباره‌ی همان پرداخت نباید دوباره کسر کند
        $this->actingAs($c, 'customer')
            ->post('/account/invoices/'.$inv->id.'/pay-credit')
            ->assertRedirect();

        $this->assertSame(2_000, $w->balanceOf($c->id), 'پرداختِ تکراری دوباره کسر نکرد');
        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)->where('reason', 'invoice_payment')->count());
    }

    // ═══════ ۹. تنظیمِ دستیِ مدیر پولِ رزروشده را نمی‌خورد ═══════

    public function test_admin_subtract_is_reservation_aware(): void
    {
        [$c] = $this->funded(10_000);
        $svc = $this->reservations();
        $this->assertTrue($svc->reserve($c->id, 8_000, 'k1')->ok);

        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post('/admin/customers/'.$c->id.'/credit', [
                'direction' => 'subtract', 'amount' => 3_000, 'note' => 'کسرِ دستی',
            ])->assertSessionHasErrors();

        $this->assertSame(10_000, $this->wallet()->balanceOf($c->id),
            'تنظیمِ دستیِ ردشده هیچ سطری ننوشت');

        // کسرِ داخلِ در دسترس مجاز است
        $this->actingAs($admin)
            ->post('/admin/customers/'.$c->id.'/credit', [
                'direction' => 'subtract', 'amount' => 2_000, 'note' => 'کسرِ مجاز',
            ])->assertRedirect();

        $this->assertSame(8_000, $this->wallet()->balanceOf($c->id));
        $this->assertSame(0, $this->wallet()->availableOf($c->id));
    }

    // ═══════ ۱۰. جمعِ نهاییِ دفتر و رزروهایِ زنده دقیق است ═══════

    public function test_final_ledger_sum_and_active_reservation_totals_are_exact(): void
    {
        [$c] = $this->funded(10_000);
        $w = $this->wallet();
        $svc = $this->reservations();

        $a = $svc->reserve($c->id, 4_000, 'a')->reservation;
        $svc->reserve($c->id, 3_000, 'b');
        $w->debit($c->id, 'IRT', 2_000, 'test_debit', $c, 'خرجِ عادی');
        $svc->settle($a);
        $svc->release(AiReservation::where('customer_id', $c->id)->where('idempotency_key', 'b')->firstOrFail());

        // دفتر: +10k − 4k (تسویه) − 2k (خرجِ عادی) = 4k
        $this->assertSame(4_000, $w->balanceOf($c->id));
        $this->assertSame(4_000, (int) CreditEntry::where('customer_id', $c->id)->sum('amount'),
            'جمعِ دفتر (حقیقتِ تنزل‌ناپذیر) دقیقاً 4k است');
        $this->assertSame(0, $svc->heldOf($c->id), 'هیچ رزروِ زنده‌ای نماند');
        $this->assertSame(0, AiReservation::holding()->where('customer_id', $c->id)->count());
    }
}
