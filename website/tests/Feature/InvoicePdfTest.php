<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحهٔ چاپ/PDF فاکتور — فقط مالک، با رسید پرداخت.
 */
class InvoicePdfTest extends TestCase
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

    private function paidInvoice(Customer $c): Invoice
    {
        $inv = Invoice::create([
            'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 500000, 'tax' => 50000, 'total' => 550000, 'paid' => 550000,
            'status' => 'paid', 'issued_at' => now(), 'paid_at' => now(),
        ]);
        InvoiceItem::create(['invoice_id' => $inv->id, 'title' => 'پشتیبانی ویژه', 'quantity' => 1, 'unit_price' => 500000, 'line_total' => 500000]);
        Payment::create(['invoice_id' => $inv->id, 'customer_id' => $c->id, 'gateway' => 'zarinpal', 'currency_code' => 'IRT', 'amount' => 550000, 'status' => 'paid', 'ref_id' => '987654321', 'paid_at' => now()]);

        return $inv;
    }

    public function test_owner_can_open_the_printable_invoice_with_receipt(): void
    {
        $c = $this->customer();
        $inv = $this->paidInvoice($c);

        $this->actingAs($c, 'customer')->get("/account/invoices/{$inv->id}/print")
            ->assertOk()
            ->assertSee($inv->number)
            ->assertSee('پرداخت شد')       // مهر رسید
            ->assertSee('987654321')       // شمارهٔ پیگیری
            ->assertSee('landscape', false); // A4 افقی
    }

    public function test_guest_is_redirected(): void
    {
        $c = $this->customer();
        $inv = $this->paidInvoice($c);

        $this->get("/account/invoices/{$inv->id}/print")->assertRedirect();
    }

    public function test_stranger_gets_404(): void
    {
        $owner = $this->customer();
        $stranger = $this->customer();
        $inv = $this->paidInvoice($owner);

        $this->actingAs($stranger, 'customer')->get("/account/invoices/{$inv->id}/print")->assertNotFound();
    }
}
