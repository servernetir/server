<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لغو فاکتورِ در انتظار پرداخت توسط مشتری.
 */
class InvoiceCancelTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    public function test_customer_can_cancel_unpaid_invoice_and_its_pending_service(): void
    {
        $c = $this->customer();
        $service = Service::create(['customer_id' => $c->id, 'name' => 'هاست', 'currency_code' => 'IRT', 'price' => 200000, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'pending']);
        $inv = Invoice::create(['customer_id' => $c->id, 'service_id' => $service->id, 'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 200000, 'tax' => 0, 'total' => 200000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);

        $this->actingAs($c, 'customer')->post("/account/invoices/{$inv->id}/cancel")->assertRedirect();

        $this->assertSame('canceled', $inv->fresh()->status);
        $this->assertSame('cancelled', $service->fresh()->status);   // سرویسِ منتظر هم لغو شد
    }

    public function test_paid_invoice_cannot_be_cancelled(): void
    {
        $c = $this->customer();
        $inv = Invoice::create(['customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 100000, 'tax' => 0, 'total' => 100000, 'paid' => 100000, 'status' => 'paid', 'issued_at' => now(), 'paid_at' => now()]);

        $this->actingAs($c, 'customer')->post("/account/invoices/{$inv->id}/cancel")->assertSessionHasErrors();
        $this->assertSame('paid', $inv->fresh()->status);
    }

    public function test_active_service_is_not_cancelled_when_a_renewal_invoice_is_cancelled(): void
    {
        $c = $this->customer();
        $service = Service::create(['customer_id' => $c->id, 'name' => 'هاست', 'currency_code' => 'IRT', 'price' => 200000, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'active', 'activated_at' => now()]);
        $renewal = Invoice::create(['customer_id' => $c->id, 'service_id' => $service->id, 'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 200000, 'tax' => 0, 'total' => 200000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);

        $this->actingAs($c, 'customer')->post("/account/invoices/{$renewal->id}/cancel")->assertRedirect();

        $this->assertSame('canceled', $renewal->fresh()->status);
        $this->assertSame('active', $service->fresh()->status);   // سرویسِ فعال دست‌نخورده
    }

    public function test_stranger_cannot_cancel_someone_elses_invoice(): void
    {
        $owner = $this->customer();
        $stranger = $this->customer();
        $inv = Invoice::create(['customer_id' => $owner->id, 'kind' => 'topup', 'currency_code' => 'IRT', 'subtotal' => 100000, 'tax' => 0, 'total' => 100000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);

        $this->actingAs($stranger, 'customer')->post("/account/invoices/{$inv->id}/cancel")->assertNotFound();
        $this->assertSame('unpaid', $inv->fresh()->status);
    }
}
