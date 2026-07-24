<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خریدِ آنلاینِ پکیج توسط مشتری — سفارش → سرویس + پیش‌فاکتور → آمادهٔ تحویل.
 */
class StoreOrderTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'name' => 'هاست لینوکس', 'category' => 'shared', 'price' => 250000,
            'setup_fee' => 0, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
        ], $over));
    }

    public function test_customer_can_order_a_package_and_gets_a_proforma(): void
    {
        $server = Server::create(['name' => 'WHM-1', 'type' => 'whm', 'status' => 'active']);
        $product = $this->product(['server_id' => $server->id, 'plan' => 'WP-5', 'requires_domain' => true]);
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->id}", ['domain' => 'client-site.com'])
            ->assertRedirect();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('pending', $service->status);
        $this->assertSame($server->id, $service->server_id);      // به سرورِ تحویل وصل شد
        $this->assertSame('WP-5', $service->plan);
        $this->assertSame('client-site.com', $service->domain);

        $invoice = Invoice::where('service_id', $service->id)->firstOrFail();
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame(275000, $invoice->total);               // 250000 + 10% مالیات
    }

    public function test_setup_fee_is_added_to_first_invoice(): void
    {
        $product = $this->product(['setup_fee' => 100000]);
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')->post("/account/order/{$product->id}")->assertRedirect();

        $invoice = Invoice::where('service_id', Service::first()->id)->firstOrFail();
        // (250000 + 100000) + 10% = 385000
        $this->assertSame(385000, $invoice->total);
        $this->assertSame(2, $invoice->items()->count());          // پکیج + راه‌اندازی
    }

    public function test_hosting_requires_a_domain(): void
    {
        $product = $this->product(['requires_domain' => true]);
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->id}", [])
            ->assertSessionHasErrors('domain');

        $this->assertSame(0, Service::count());   // سرویسی ساخته نشد
    }

    public function test_inactive_product_cannot_be_ordered(): void
    {
        $product = $this->product(['is_active' => false]);
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->id}")
            ->assertSessionHasErrors();

        $this->assertSame(0, Service::count());
    }

    public function test_guest_cannot_order(): void
    {
        $product = $this->product();
        $this->post("/account/order/{$product->id}")->assertRedirect();   // به ورود
        $this->assertSame(0, Service::count());
    }
}
