<?php

namespace Tests\Feature;

use App\Models\BankTransferReceipt;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * واریز به حساب — ثبت رسید توسط مشتری، تأیید توسط مدیر، تسویهٔ خودکار.
 */
class BankTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        // مشخصات بانکی را پر می‌کنیم تا گزینه فعال باشد
        Setting::put('bank_sheba', '000000000000000000000000');
        Setting::put('bank_holder', 'اطمینان داده‌پردازان دانش');
    }

    private function staff(): User
    {
        return User::create(['name' => 'مدیر', 'email' => 's'.random_int(1, 99999).'@x.com', 'password' => bcrypt('x'), 'role' => 'admin']);
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999), 'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa']);
    }

    private function serviceInvoice(Customer $c): array
    {
        $service = Service::create(['customer_id' => $c->id, 'name' => 'هاست', 'currency_code' => 'IRT', 'price' => 200000, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'pending']);
        $invoice = Invoice::create(['customer_id' => $c->id, 'service_id' => $service->id, 'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 200000, 'tax' => 0, 'total' => 200000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);

        return [$service, $invoice];
    }

    public function test_bank_option_hidden_until_details_set(): void
    {
        $this->assertTrue(Setting::bankReady());
        Setting::put('bank_sheba', null);
        Setting::put('bank_account', null);
        $this->assertFalse(Setting::bankReady());
    }

    public function test_customer_submits_receipt_creating_a_pending_record(): void
    {
        $c = $this->customer();
        [, $invoice] = $this->serviceInvoice($c);

        $this->actingAs($c, 'customer')
            ->post("/account/invoices/{$invoice->id}/bank-transfer", ['reference' => 'TRK-12345'])
            ->assertRedirect();

        $r = BankTransferReceipt::first();
        $this->assertNotNull($r);
        $this->assertSame('pending', $r->status);
        $this->assertSame(200000, $r->amount);
        $this->assertSame($invoice->id, $r->invoice_id);
    }

    public function test_customer_cannot_submit_for_another_persons_invoice(): void
    {
        $mine = $this->customer();
        $other = $this->customer();
        [, $invoice] = $this->serviceInvoice($other);

        $this->actingAs($mine, 'customer')
            ->post("/account/invoices/{$invoice->id}/bank-transfer", ['reference' => 'X'])
            ->assertNotFound();
    }

    public function test_admin_approval_settles_invoice_and_activates_service(): void
    {
        $c = $this->customer();
        [$service, $invoice] = $this->serviceInvoice($c);
        $receipt = BankTransferReceipt::create(['customer_id' => $c->id, 'invoice_id' => $invoice->id, 'amount' => 200000, 'reference' => 'TRK-9', 'status' => 'pending']);

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/bank-transfers/{$receipt->id}/approve")
            ->assertRedirect();

        $this->assertSame('approved', $receipt->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('active', $service->fresh()->status);   // فعال شد
    }

    public function test_admin_rejection_marks_rejected(): void
    {
        $c = $this->customer();
        [, $invoice] = $this->serviceInvoice($c);
        $receipt = BankTransferReceipt::create(['customer_id' => $c->id, 'invoice_id' => $invoice->id, 'amount' => 200000, 'reference' => 'TRK-8', 'status' => 'pending']);

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/bank-transfers/{$receipt->id}/reject", ['reject_reason' => 'مبلغ نمی‌خواند'])
            ->assertRedirect();

        $this->assertSame('rejected', $receipt->fresh()->status);
        $this->assertSame('unpaid', $invoice->fresh()->status);   // تسویه نشد
    }

    public function test_admin_can_save_bank_settings(): void
    {
        $this->actingAs($this->staff(), 'web')
            ->post('/admin/settings', ['bank_holder' => 'شرکت الف', 'bank_sheba' => '123456789012345678901234'])
            ->assertRedirect();

        $this->assertSame('شرکت الف', Setting::get('bank_holder'));
    }
}
