<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * حذف مشتری و فاکتور از پنل مدیریت — با محافظِ مالی.
 */
class AdminDeleteTest extends TestCase
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

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    public function test_admin_can_delete_customer_without_financial_history(): void
    {
        $c = $this->customer();

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/customers/{$c->id}/delete")
            ->assertRedirect(route('admin.customers'));

        $this->assertNull(Customer::find($c->id));
    }

    public function test_customer_with_paid_invoice_cannot_be_deleted(): void
    {
        $c = $this->customer();
        Invoice::create([
            'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 100000, 'tax' => 0, 'total' => 100000, 'paid' => 100000,
            'status' => 'paid', 'issued_at' => now(), 'paid_at' => now(),
        ]);

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/customers/{$c->id}/delete")
            ->assertSessionHasErrors();

        $this->assertNotNull(Customer::find($c->id));   // دست‌نخورده مانده
    }

    public function test_admin_can_delete_unpaid_invoice_and_cancels_its_pending_service(): void
    {
        $c = $this->customer();
        $svc = Service::create([
            'customer_id' => $c->id, 'name' => 'هاست', 'currency_code' => 'IRT',
            'price' => 200000, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'pending',
        ]);
        $inv = Invoice::create([
            'customer_id' => $c->id, 'service_id' => $svc->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 200000, 'tax' => 0, 'total' => 200000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/invoices/{$inv->id}/delete")
            ->assertRedirect(route('admin.customer', $c->id));

        $this->assertNull(Invoice::find($inv->id));
        $this->assertSame('cancelled', $svc->fresh()->status);
    }

    public function test_paid_invoice_cannot_be_deleted(): void
    {
        $c = $this->customer();
        $inv = Invoice::create([
            'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 100000, 'tax' => 0, 'total' => 100000, 'paid' => 100000,
            'status' => 'paid', 'issued_at' => now(), 'paid_at' => now(),
        ]);

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/invoices/{$inv->id}/delete")
            ->assertSessionHasErrors();

        $this->assertNotNull(Invoice::find($inv->id));
    }
}
