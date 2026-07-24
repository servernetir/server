<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * فروش سرویس به مشتری + فعال‌سازی خودکار پس از پرداخت + تغییر رمز.
 */
class AdminServiceTest extends TestCase
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
            'password' => bcrypt('oldsecret'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    public function test_selling_a_service_creates_a_pending_service_and_unpaid_invoice(): void
    {
        $c = $this->customer();

        $this->actingAs($this->staff(), 'web')->post("/admin/customers/{$c->id}/services", [
            'name' => 'پشتیبانی ویژه', 'description' => 'اختصاصی',
            'price' => 500000, 'tax_percent' => 10, 'cycle' => 'monthly',
        ])->assertRedirect("/admin/customers/{$c->id}");

        $service = Service::first();
        $this->assertSame('pending', $service->status);
        $this->assertSame(500000, $service->price);

        $invoice = Invoice::where('service_id', $service->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame(500000, $invoice->subtotal);
        $this->assertSame(50000, $invoice->tax);
        $this->assertSame(550000, $invoice->total);
        $this->assertSame('unpaid', $invoice->status);
    }

    public function test_paying_the_invoice_activates_the_service_and_sets_next_due(): void
    {
        $c = $this->customer();
        $service = Service::create([
            'customer_id' => $c->id, 'name' => 'س', 'currency_code' => 'IRT',
            'price' => 100000, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'pending',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $c->id, 'service_id' => $service->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => 100000, 'tax' => 0, 'total' => 100000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);
        $payment = Payment::create([
            'invoice_id' => $invoice->id, 'customer_id' => $c->id, 'gateway' => 'bale',
            'currency_code' => 'IRT', 'amount' => 100000, 'status' => 'redirected', 'external_ref' => 'X',
        ]);

        app(PaymentService::class)->settleConfirmed($payment, 'REF-1');

        $service->refresh();
        $this->assertSame('active', $service->status);
        $this->assertNotNull($service->activated_at);
        $this->assertNotNull($service->next_due_at);
        $this->assertTrue($service->next_due_at->isFuture());
    }

    public function test_once_service_has_no_next_due_after_payment(): void
    {
        $c = $this->customer();
        $service = Service::create([
            'customer_id' => $c->id, 'name' => 'یک‌بار', 'currency_code' => 'IRT',
            'price' => 100000, 'tax_percent' => 0, 'cycle' => 'once', 'status' => 'pending',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $c->id, 'service_id' => $service->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => 100000, 'tax' => 0, 'total' => 100000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);
        $payment = Payment::create([
            'invoice_id' => $invoice->id, 'customer_id' => $c->id, 'gateway' => 'bale',
            'currency_code' => 'IRT', 'amount' => 100000, 'status' => 'redirected', 'external_ref' => 'Y',
        ]);

        app(PaymentService::class)->settleConfirmed($payment, 'REF-2');

        $service->refresh();
        $this->assertSame('active', $service->status);
        $this->assertNull($service->next_due_at);
    }

    public function test_admin_can_change_customer_password(): void
    {
        $c = $this->customer();

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/customers/{$c->id}/password", ['password' => 'brandnew1234'])
            ->assertRedirect();

        $this->assertTrue(Hash::check('brandnew1234', $c->fresh()->password));
    }

    public function test_password_change_rejects_short_password(): void
    {
        $c = $this->customer();

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/customers/{$c->id}/password", ['password' => 'short'])
            ->assertSessionHasErrors('password');
    }

    public function test_customer_sees_only_their_services(): void
    {
        $mine = $this->customer();
        $other = $this->customer();
        Service::create(['customer_id' => $mine->id, 'name' => 'مالِ من', 'price' => 1, 'cycle' => 'once', 'status' => 'active', 'currency_code' => 'IRT']);
        Service::create(['customer_id' => $other->id, 'name' => 'مالِ دیگری', 'price' => 1, 'cycle' => 'once', 'status' => 'active', 'currency_code' => 'IRT']);

        $this->actingAs($mine, 'customer')->get('/account/services')
            ->assertOk()
            ->assertSee('مالِ من')
            ->assertDontSee('مالِ دیگری');
    }
}
