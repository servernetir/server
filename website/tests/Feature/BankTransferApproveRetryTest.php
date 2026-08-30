<?php

namespace Tests\Feature;

use App\Models\BankTransferReceipt;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * تأییدِ «واریز به حساب» باید idempotent باشد.
 *
 * ⚠️ باگِ واقعیِ پروداکشن: ردیفِ Payment بیرونِ تراکنشِ تسویه ساخته و کامیت
 * می‌شد. اگر تسویه بعدش می‌شکست، رسید pending می‌ماند ولی پرداخت باقی بود؛
 * تلاشِ دوبارهٔ مدیر به یکتاییِ external_ref می‌خورد:
 *   Duplicate entry '687646546' for key 'payments_external_ref_unique'
 * یعنی تأییدِ آن رسید **برای همیشه** قفل می‌شد و پولِ مشتری معلق می‌ماند.
 */
class BankTransferApproveRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 's'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function receipt(string $reference): BankTransferReceipt
    {
        $customer = Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => 275000, 'tax' => 0, 'total' => 275000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);

        return BankTransferReceipt::create([
            'customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'reference' => $reference, 'amount' => 275000, 'status' => 'pending',
            'paid_at' => now(),
        ]);
    }

    /**
     * سناریوی واقعی: پرداختی با همان شناسه از تلاشِ شکست‌خوردهٔ قبلی مانده.
     * تأییدِ دوباره باید کار کند، نه ۵۰۰ بدهد.
     */
    public function test_approve_succeeds_when_a_payment_row_already_exists(): void
    {
        $receipt = $this->receipt('687646546');

        // بازماندهٔ تلاشِ قبلی
        Payment::create([
            'invoice_id' => $receipt->invoice_id, 'customer_id' => $receipt->customer_id,
            'gateway' => 'bank_transfer', 'currency_code' => 'IRT', 'amount' => 275000,
            'status' => 'redirected', 'external_ref' => '687646546',
        ]);

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/bank-transfers/{$receipt->id}/approve")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // پرداختِ تکراری ساخته نشده
        $this->assertSame(1, Payment::where('external_ref', '687646546')->count());

        // و رسید بسته و فاکتور تسویه شده
        $this->assertSame('approved', $receipt->fresh()->status);
        $this->assertSame('paid', $receipt->invoice->fresh()->status);
    }

    /** تأییدِ عادی (بدونِ بازمانده) هم مثلِ قبل کار کند */
    public function test_normal_approve_still_settles_the_invoice(): void
    {
        $receipt = $this->receipt('999111');

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/bank-transfers/{$receipt->id}/approve")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $receipt->fresh()->status);
        $this->assertSame('paid', $receipt->invoice->fresh()->status);
        $this->assertSame(1, Payment::where('external_ref', '999111')->count());
    }

    /** دو بار زدنِ دکمهٔ تأیید نباید دو بار پول به اعتبار بنشیند */
    public function test_double_approve_does_not_double_credit(): void
    {
        $receipt = $this->receipt('555222');
        $staff = $this->staff();

        $this->actingAs($staff, 'web')->post("/admin/bank-transfers/{$receipt->id}/approve");
        $this->actingAs($staff, 'web')->post("/admin/bank-transfers/{$receipt->id}/approve");

        $this->assertSame(1, Payment::where('external_ref', '555222')->count());
        $this->assertSame(275000, (int) $receipt->invoice->fresh()->paid);
    }
}
