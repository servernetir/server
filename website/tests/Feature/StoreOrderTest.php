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
            ->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'client-site.com'])
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

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'domain_mode' => 'subdomain', 'subdomain' => 'mysite'])
            ->assertRedirect();

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
            ->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'domain_mode' => 'have'])   // بدونِ دامنه
            ->assertSessionHasErrors('domain');

        $this->assertSame(0, Service::count());   // سرویسی ساخته نشد
    }

    public function test_inactive_product_cannot_be_ordered(): void
    {
        $product = $this->product(['is_active' => false]);
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'x.com'])
            ->assertSessionHasErrors();

        $this->assertSame(0, Service::count());
    }

    public function test_guest_cannot_order(): void
    {
        $product = $this->product();
        $this->post("/account/order/{$product->slug}", ['cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'x.com'])
            ->assertRedirect();   // به ورود
        $this->assertSame(0, Service::count());
    }

    // ───────────── انتخابِ مکان (ایران/آلمان) و دورهٔ پرداخت ─────────────

    private function whm(string $name, ?string $country, array $over = []): Server
    {
        return Server::create(array_merge([
            'name' => $name, 'type' => 'whm', 'status' => 'active',
            'country' => $country, 'hostname' => strtolower($name).'.test',
            'username' => 'root', 'api_token' => 't',
        ], $over));
    }

    /** مشتری آلمان را انتخاب می‌کند → سرویس روی سرورِ آلمان ساخته می‌شود */
    public function test_chosen_location_decides_the_delivery_server(): void
    {
        $ir = $this->whm('WHM-IR', 'IR');
        $de = $this->whm('WHM-DE', 'DE');
        $product = $this->product(['plan' => 'sn_wp_5', 'requires_domain' => true]);
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}", [
                'country' => 'DE', 'cycle' => 'monthly',
                'domain_mode' => 'have', 'domain' => 'de-site.com',
            ])->assertRedirect();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame($de->id, $service->server_id);
        $this->assertNotSame($ir->id, $service->server_id);
    }

    /** مکانی که سرورِ فعال ندارد پذیرفته نمی‌شود */
    public function test_location_without_an_active_server_is_rejected(): void
    {
        $this->whm('WHM-IR', 'IR');                       // فقط ایران داریم
        $product = $this->product();
        $customer = $this->customer();

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}", [
                'country' => 'DE', 'cycle' => 'monthly',
                'domain_mode' => 'have', 'domain' => 'x.com',
            ])->assertSessionHasErrors('country');

        $this->assertSame(0, Service::count());
    }

    /** سرورِ پر (max_accounts) نباید مقصدِ خرید شود */
    public function test_full_server_is_not_offered_as_a_location(): void
    {
        $this->whm('WHM-IR', 'IR', ['max_accounts' => 5, 'active_accounts' => 5]);
        $product = $this->product();

        $this->assertSame([], $product->availableCountries());
    }

    /** دورهٔ سالانه: مبلغ با تخفیف قفل می‌شود و سررسید ۱۲ ماه بعد است */
    public function test_yearly_cycle_locks_the_discounted_price(): void
    {
        $this->whm('WHM-IR', 'IR');
        $product = $this->product(['price' => 250000]);
        $customer = $this->customer();

        // مقصدِ IR از این تاریخ پشتِ دروازهٔ احراز است (IranSalesGate)؛ این
        // تست دربارهٔ قیمت است نه دروازه — مشتری‌اش احرازشده می‌شود.
        \App\Models\CustomerProfile::create([
            'customer_id' => $customer->id, 'is_default' => true, 'type' => 'individual',
            'status' => 'verified', 'email' => $customer->email, 'mobile' => $customer->phone,
        ]);

        $this->actingAs($customer, 'customer')
            ->post("/account/order/{$product->slug}", [
                'country' => 'IR', 'cycle' => 'yearly',
                'domain_mode' => 'have', 'domain' => 'yearly.com',
            ])->assertRedirect();

        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('yearly', $service->cycle);
        // ۲۵۰٬۰۰۰ × ۱۲ ماه × ۸۰٪ = ۲٬۴۰۰٬۰۰۰ (مضربِ ۱۰٬۰۰۰)
        $this->assertSame(2400000, $service->price);
        $this->assertSame(0, $service->price % 10000);

        $invoice = Invoice::where('service_id', $service->id)->firstOrFail();
        $this->assertSame(2640000, $invoice->total);          // + ۱۰٪ مالیات
    }

    /**
     * تخفیفِ سالانه‌ای که در سایت تبلیغ می‌شود باید همان باشد که فاکتور می‌شود —
     * وگرنه وعده و صورت‌حساب یکی نیست.
     */
    public function test_advertised_yearly_discount_matches_what_is_charged(): void
    {
        $advertised = (int) config('billing.cycles.yearly.discount_pct');
        $product = $this->product(['price' => 1000000]);

        $expected = price_toman((int) round(1000000 * 12 * (100 - $advertised) / 100));

        $this->assertSame($expected, $product->priceForCycle('yearly'));
        $this->assertGreaterThan(0, $advertised);
    }

    /** شش‌ماهه واقعاً وجود دارد و ارزان‌تر از ۶ ماهِ ماهانه است */
    public function test_semiannual_cycle_exists_and_beats_six_monthlies(): void
    {
        $product = $this->product(['price' => 250000]);

        $semi = $product->priceForCycle('semiannual');
        $this->assertSame(1350000, $semi);                    // ۲۵۰k × ۶ × ۹۰٪
        $this->assertLessThan($product->priceForCycle('monthly') * 6, $semi);       // < ۱٬۵۰۰٬۰۰۰
        $this->assertLessThan($product->priceForCycle('quarterly') * 2, $semi);    // < ۱٬۴۲۰٬۰۰۰ — دورهٔ بلندتر همیشه ارزان‌تر
        $this->assertLessThan($product->priceForCycle('yearly'), $semi);           // و از سالانه کمتر

        // سررسیدِ شش‌ماهه دقیقاً ۶ ماه جلو می‌رود
        $service = new Service(['cycle' => 'semiannual']);
        $this->assertSame(
            '2026-07-31',
            $service->nextDueFrom(\Illuminate\Support\Carbon::parse('2026-01-31'))->toDateString()
        );
    }

    /** محدودسازیِ پکیج به یک مکان، مکانِ دیگر را حذف می‌کند */
    public function test_product_locations_restriction_narrows_the_choice(): void
    {
        $this->whm('WHM-IR', 'IR');
        $this->whm('WHM-DE', 'DE');

        $anywhere = $this->product(['slug' => 'anywhere']);
        $this->assertEqualsCanonicalizing(['IR', 'DE'], $anywhere->availableCountries());

        $deOnly = $this->product(['slug' => 'de-only', 'locations' => ['DE']]);
        $this->assertSame(['DE'], $deOnly->availableCountries());
    }
}
