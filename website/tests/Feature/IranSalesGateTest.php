<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Customer\IranSalesGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

/**
 * دروازهٔ فروشِ محصولاتِ ایران به مشتریِ احرازنشده.
 *
 * ═══ چرا (۶ شهریور ۱۴۰۵) ═══
 *
 * کارفرما: «فعلاً هاست/سرورِ ایران فقط به ایرانی‌ها — خارجی‌ها KYC ندارند.»
 * معیار خرید، تأیید شاهکار است؛ نمایش عمومی کاتالوگ از زبان پیروی می‌کند.
 */
class IranSalesGateTest extends TestCase
{
    use RefreshDatabase, SeedsCatalogPricing;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seedCatalogPricing();
    }

    private function customer(bool $verified): Customer
    {
        $c = Customer::create([
            'email' => 'g'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'is_default' => true, 'type' => 'individual',
            'status' => $verified ? 'verified' : 'draft',
            'email' => $c->email, 'mobile' => $c->phone,
        ]);
        if ($verified) {
            $c->identityVerification()->create([
                'national_id_enc' => 'synthetic', 'national_id_hash' => hash('sha256', $c->email),
                'birth_date' => '1990-01-01', 'mobile' => $c->phone,
                'status' => 'verified', 'shahkar_matched' => true,
            ]);
        }

        return $c;
    }

    private function iranProduct(): Product
    {
        Server::create(['name' => 'WHM-IR', 'type' => 'whm', 'hostname' => 'ir.test', 'username' => 'root',
            'api_token' => 't', 'verify_tls' => false, 'status' => 'active', 'country' => 'IR']);
        Server::create(['name' => 'WHM-DE', 'type' => 'whm', 'hostname' => 'de.test', 'username' => 'root',
            'api_token' => 't', 'verify_tls' => false, 'status' => 'active', 'country' => 'DE']);

        return Product::create([
            'name' => 'هاست لینوکس L-1', 'slug' => 'linux-1', 'category' => 'shared',
            'price' => 200000, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
        ]);
    }

    private function gpuFreeCloud(): void
    {
        CloudLocation::create(['code' => 'ir-tehran', 'country' => 'IR', 'city' => 'Tehran', 'is_active' => true]);
        CloudLocation::create(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);

        CloudImage::create(['provider' => 'hetzner', 'provider_ref' => '1', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'label' => 'Ubuntu', 'arch' => 'x86', 'is_active' => true]);

        foreach ([['ir-tehran', 'ir1'], ['de-frankfurt', 'de1']] as [$loc, $ref]) {
            CloudPlan::create([
                'provider' => 'hetzner', 'provider_ref' => $ref, 'provider_location' => $loc,
                'location_code' => $loc, 'public_name' => 'CV-2-4',
                'slug' => 'cv-2c-4g-40d-'.$loc, 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
                'disk_type' => 'nvme', 'traffic_gb' => 1024, 'cpu_kind' => 'shared', 'arch' => 'x86',
                'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570_000,
                'is_active' => true, 'in_stock' => true,
            ]);
        }
    }

    // ═══════════ خودِ دروازه ═══════════

    /** 🔴 پیش‌فرض بسته است — نبودِ تنظیم هرگز یعنی باز */
    public function test_the_gate_is_closed_by_default(): void
    {
        $this->assertFalse(IranSalesGate::openToUnverified());
        $this->assertTrue(IranSalesGate::blocks($this->customer(false), 'IR'));
        $this->assertTrue(IranSalesGate::blocks($this->customer(false), 'ir-tehran'));
    }

    /** مشتریِ احرازشده همیشه آزاد است — معیار احراز است نه زبان */
    public function test_a_verified_customer_is_never_blocked(): void
    {
        $this->assertFalse(IranSalesGate::blocks($this->customer(true), 'IR'));
    }

    /** مقصدِ غیرایرانی هیچ‌وقت گیر نمی‌کند */
    public function test_non_iran_targets_pass_freely(): void
    {
        $this->assertFalse(IranSalesGate::blocks($this->customer(false), 'DE'));
        $this->assertFalse(IranSalesGate::blocks($this->customer(false), 'de-frankfurt'));
        // «ir» فقط به‌عنوانِ کشور/پیشوندِ مکان — نه هر رشته‌ای که ir دارد
        $this->assertFalse(IranSalesGate::blocks($this->customer(false), 'gb-birmingham'));
    }

    /** تیکِ صریحِ مدیر بازش می‌کند */
    public function test_legacy_admin_toggle_cannot_bypass_shahkar(): void
    {
        Setting::put(IranSalesGate::SETTING, '1');

        $this->assertTrue(IranSalesGate::blocks($this->customer(false), 'IR'));
    }

    // ═══════════ سیم‌کشیِ فروشگاه‌ها ═══════════

    /** 🔴 سفارشِ هاستِ ایران از مشتریِ احرازنشده رد می‌شود — با پیامِ روشن */
    public function test_an_unverified_customer_cannot_order_iran_hosting(): void
    {
        $product = $this->iranProduct();

        $res = $this->actingAs($this->customer(false), 'customer')
            ->post('/account/order/'.$product->slug, [
                'country' => 'IR', 'cycle' => 'monthly', 'domain_mode' => 'subdomain',
                'subdomain' => 'testsub'.random_int(100, 999),
            ]);

        $res->assertSessionHasErrors('country');
        $this->assertDatabaseCount('services', 0);
    }

    /** همان مشتری، آلمان را آزادانه می‌خرد — دروازه فقط ایران را می‌بندد */
    public function test_the_same_customer_can_still_order_germany(): void
    {
        $product = $this->iranProduct();

        $res = $this->actingAs($this->customer(false), 'customer')
            ->post('/account/order/'.$product->slug, [
                'country' => 'DE', 'cycle' => 'monthly', 'domain_mode' => 'subdomain',
                'subdomain' => 'testsub'.random_int(100, 999),
            ]);

        $res->assertSessionDoesntHaveErrors('country');
    }

    public function test_fixed_delivery_server_is_also_subject_to_shahkar_and_locale_policy(): void
    {
        $server = Server::create(['name' => 'VPS-IR', 'type' => 'vps', 'country' => 'IR', 'status' => 'active']);
        $product = Product::create([
            'name' => 'Fixed VPS', 'slug' => 'fixed-vps', 'category' => 'vps', 'server_id' => $server->id,
            'base_price_eur' => '2', 'cycle' => 'monthly', 'is_active' => true,
        ]);
        $this->assertSame([], $product->availableCountries());
        $customer = $this->customer(false);
        $this->actingAs($customer, 'customer')
            ->post('/account/order/'.$product->slug, ['cycle' => 'monthly'])
            ->assertSessionHasErrors('country');
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);

        $verified = $this->customer(true);
        $this->actingAs($verified, 'customer')->get('/account/order/'.$product->slug)->assertOk();
        foreach (['/en', '/tr'] as $prefix) {
            $this->get($prefix.'/account/order/'.$product->slug)->assertNotFound();
            $this->post($prefix.'/account/order/'.$product->slug, ['cycle' => 'monthly'])->assertSessionHasErrors('country');
        }
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->post('/account/order/'.$product->slug, ['cycle' => 'monthly'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('services', ['server_id' => $server->id, 'customer_id' => $verified->id]);
    }

    public function test_the_same_fixed_non_iran_delivery_is_not_restricted_by_shahkar(): void
    {
        $server = Server::create(['name' => 'VPS-DE', 'type' => 'vps', 'country' => 'DE', 'status' => 'active']);
        $product = Product::create([
            'name' => 'Fixed VPS DE', 'slug' => 'fixed-vps-de', 'category' => 'vps', 'server_id' => $server->id,
            'base_price_eur' => '2', 'cycle' => 'monthly', 'is_active' => true,
        ]);
        $this->assertSame([], $product->availableCountries());
        $this->actingAs($this->customer(false), 'customer');
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->get($prefix.'/account/order/'.$product->slug)->assertOk();
        }
        $this->post('/account/order/'.$product->slug, ['cycle' => 'monthly'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('services', ['server_id' => $server->id]);
    }

    /** فروشگاهِ ابری: مکانِ ir-* برای احرازنشده نه دیده می‌شود نه سفارش‌پذیر است */
    public function test_iran_cloud_locations_are_hidden_and_unorderable_for_unverified(): void
    {
        $this->gpuFreeCloud();
        $c = $this->customer(false);

        $html = (string) $this->actingAs($c, 'customer')
            ->get('/account/cloud-store')->assertOk()->getContent();
        $this->assertStringNotContainsString('ir-tehran', $html, 'مکانِ ایران نباید دیده شود.');
        $this->assertStringContainsString('de-frankfurt', $html);

        $res = $this->actingAs($c, 'customer')->post('/account/cloud-store', [
            'location' => 'ir-tehran', 'plan' => 'cv-2c-4g-40d-ir-tehran',
            'image' => 'ubuntu-24.04', 'cycle' => 'monthly',
        ]);
        $res->assertSessionHasErrors('location');
    }

    /** مشتریِ احرازشده مکانِ ایران را می‌بیند */
    public function test_a_verified_customer_sees_iran_cloud_locations(): void
    {
        $this->gpuFreeCloud();

        $html = (string) $this->actingAs($this->customer(true), 'customer')
            ->get('/account/cloud-store')->assertOk()->getContent();

        $this->assertStringContainsString('ir-tehran', $html);
    }
}
