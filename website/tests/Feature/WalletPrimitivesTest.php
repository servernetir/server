<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletException;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M3 — ابتکاریِ کیفِ پول: همان دفتر، حالت با قفل و گارد.
 *
 * قواعدِ قفل‌شده:
 *   - جمعِ دفتر حقیقت است؛ balance_after مشاوره‌ای.
 *   - برداشت با گاردِ موجودی؛ مرزِ دقیق (برابرِ موجودی) رد می‌شود نه منفی.
 *   - مسیرِ پرداخت (settleConfirmed → credit) از M3 به بعد از همین Wallet
 *     می‌گذرد — این تست‌ها رگرسیونِ همان مسیرند.
 */
class WalletPrimitivesTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'email' => 'w'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => null, 'status' => 'active', 'locale' => 'fa',
        ], $over));
    }

    private function wallet(): Wallet
    {
        return app(Wallet::class);
    }

    // ═══════════════ سازگاریِ اعتبار ═══════════════

    public function test_credit_adds_ledger_row_with_advisory_balance(): void
    {
        $c = $this->customer();
        $w = $this->wallet();

        $w->credit($c->id, 'IRT', 100_000, 'topup', $c, 'شارژ');
        $w->credit($c->id, 'IRT', 50_000, 'adjustment', $c, null);

        $this->assertSame(150_000, $w->balanceOf($c->id));
        $this->assertSame(2, CreditEntry::where('customer_id', $c->id)->count());

        // balance_after مشاوره‌ای ولی در حالتِ عادی درست است
        $this->assertSame(150_000, (int) CreditEntry::where('customer_id', $c->id)->latest('id')->value('balance_after'));
    }

    public function test_credit_rejects_non_positive_amount(): void
    {
        $c = $this->customer();

        $this->expectException(WalletException::class);
        $this->wallet()->credit($c->id, 'IRT', 0, 'topup', $c);
    }

    // ═══════════════ برداشت ═══════════════

    public function test_debit_success(): void
    {
        $c = $this->customer();
        $w = $this->wallet();
        $w->credit($c->id, 'IRT', 100_000, 'topup', $c);

        $entry = $w->debit($c->id, 'IRT', 40_000, 'test_debit', $c, 'برداشت');

        $this->assertSame(-40_000, $entry->amount);
        $this->assertSame(60_000, $w->balanceOf($c->id));
    }

    public function test_debit_insufficient_funds(): void
    {
        $c = $this->customer();
        $w = $this->wallet();
        $w->credit($c->id, 'IRT', 10_000, 'topup', $c);

        try {
            $w->debit($c->id, 'IRT', 10_001, 'test_debit', $c);
            $this->fail('باید WalletException می‌داد');
        } catch (WalletException $e) {
            $this->assertSame('insufficient_funds', $e->errorCode);
        }

        $this->assertSame(10_000, $w->balanceOf($c->id), 'برداشتِ ردشده دفتر را ننوشت');
    }

    public function test_debit_exact_boundary_is_allowed_and_beyond_is_not(): void
    {
        $c = $this->customer();
        $w = $this->wallet();
        $w->credit($c->id, 'IRT', 10_000, 'topup', $c);

        // برابرِ موجودی: مجاز — دقیقاً صفر می‌مانَد، منفی نمی‌شود
        $w->debit($c->id, 'IRT', 10_000, 'test_debit', $c);
        $this->assertSame(0, $w->balanceOf($c->id));

        $this->expectException(WalletException::class);
        $w->debit($c->id, 'IRT', 1, 'test_debit', $c);
    }

    // ═══════════════ balance_after مشاوره‌ای ═══════════════

    public function test_advisory_balance_after_mismatch_does_not_change_truth(): void
    {
        $c = $this->customer();
        $w = $this->wallet();
        $w->credit($c->id, 'IRT', 100_000, 'topup', $c);

        // خراب‌کاریِ عمدی: انگار یک نوشتنِ قدیمی balance_after غلط زده
        CreditEntry::where('customer_id', $c->id)->update(['balance_after' => 999_999_999]);

        $this->assertSame(100_000, $w->balanceOf($c->id), 'حقیقت جمع است، نه ستونِ مشاوره‌ای');

        // گاردِ برداشت هم روی جمع است، نه روی آخرینِ balance_after
        try {
            $w->debit($c->id, 'IRT', 100_001, 'test_debit', $c);
            $this->fail('گارد باید روی جمعِ دفتر می‌بود');
        } catch (WalletException $e) {
            $this->assertSame('insufficient_funds', $e->errorCode);
        }
    }

    // ═══════════════ بازگشت روی خطا ═══════════════

    public function test_exception_rolls_back_whole_write(): void
    {
        $c = $this->customer();
        $w = $this->wallet();
        $w->credit($c->id, 'IRT', 100_000, 'topup', $c);

        try {
            DB::transaction(function () use ($w, $c) {
                $w->credit($c->id, 'IRT', 50_000, 'topup', $c);   // باید با کلِ تراکنش برگردد
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(100_000, $w->balanceOf($c->id), 'نوشتنِ نیمه‌کاره نماند');
        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)->count());
    }

    public function test_credit_composes_inside_existing_transaction(): void
    {
        // applyPaid همین‌طور صدا می‌زند: Wallet داخلِ تراکنشِ بازِ فراخوان
        // می‌نشیند و با کلِ آن یکجا commit می‌شود
        $c = $this->customer();
        $w = $this->wallet();

        DB::transaction(function () use ($w, $c) {
            $w->credit($c->id, 'IRT', 10_000, 'topup', $c);
            $w->credit($c->id, 'IRT', 10_000, 'topup', $c);
        });

        $this->assertSame(20_000, $w->balanceOf($c->id));
    }

    // ═══════════════ رگرسیونِ مسیرِ پرداخت ═══════════════

    public function test_payment_settle_confirmed_topup_routes_through_wallet(): void
    {
        $c = $this->customer();

        $inv = Invoice::create([
            'customer_id' => $c->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => 200_000, 'tax' => 0, 'total' => 200_000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $payment = Payment::create([
            'invoice_id' => $inv->id, 'customer_id' => $c->id,
            'gateway' => 'credit', 'currency_code' => 'IRT',
            'amount' => 200_000, 'status' => 'redirected',
        ]);

        $outcome = app(PaymentService::class)->settleConfirmed($payment, 'reg-m3-1');

        $this->assertTrue($outcome->ok);
        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertSame(200_000, $this->wallet()->balanceOf($c->id), 'topup از مسیرِ Wallet نوشته شد');

        $entry = CreditEntry::where('customer_id', $c->id)->where('reason', 'topup')->first();
        $this->assertNotNull($entry);
        $this->assertSame(200_000, $entry->balance_after);
        $this->assertSame(Invoice::class, $entry->source_type);
        $this->assertSame($inv->id, $entry->source_id);
    }

    public function test_payment_settle_confirmed_is_idempotent_for_credit(): void
    {
        $c = $this->customer();

        $inv = Invoice::create([
            'customer_id' => $c->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => 50_000, 'tax' => 0, 'total' => 50_000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $payment = Payment::create([
            'invoice_id' => $inv->id, 'customer_id' => $c->id,
            'gateway' => 'credit', 'currency_code' => 'IRT',
            'amount' => 50_000, 'status' => 'redirected',
        ]);

        $svc = app(PaymentService::class);

        $this->assertTrue($svc->settleConfirmed($payment, 'reg-m3-2')->ok);
        $second = $svc->settleConfirmed($payment, 'reg-m3-2');

        $this->assertTrue($second->ok);
        $this->assertTrue($second->alreadySettled, 'تکرارِ تسویه نباید دوباره اعتبار بدهد');
        $this->assertSame(50_000, $this->wallet()->balanceOf($c->id));
        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)->count());
    }
}
