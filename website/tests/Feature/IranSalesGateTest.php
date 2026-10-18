<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\IdentityVerification;
use App\Models\Product;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Customer\IranSalesGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Support\NavasanRates;
use Tests\Support\ShahkarFixture;

/**
 * دروازهٔ فروشِ محصولاتِ ایران به مشتریِ احرازنشده.
 *
 * ═══ چرا (۶ شهریور ۱۴۰۵) ═══
 *
 * خرید ایران فقط با نتیجهٔ verified و matched شاهکار؛ تأیید پروفایل یا
 * تنظیم قدیمی هیچ استثنایی ایجاد نمی‌کند. تابعیت و زبان معیار نیستند.
 */
class IranSalesGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        NavasanRates::cloudSnapshot();
        Http::fake();
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
            ShahkarFixture::verify($c);
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
            'price' => 200000, 'base_price_eur' => 2, 'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
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

    /** تنظیم قدیمی مجوز دورزدن الزام شاهکار نیست. */
    public function test_the_legacy_admin_toggle_cannot_bypass_shahkar(): void
    {
        Setting::put(IranSalesGate::SETTING, '1');

        $this->assertFalse(IranSalesGate::openToUnverified());
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

    public function test_profile_approval_alone_never_substitutes_for_verified_matching_shahkar(): void
    {
        $customer = $this->customer(false);
        $customer->profiles()->update(['status' => 'verified']);
        $this->assertTrue($customer->isVerified());
        $this->assertTrue(IranSalesGate::blocksIranFor($customer));

        foreach ([['pending', true], ['failed', true], ['verified', false]] as [$status, $matched]) {
            ShahkarFixture::verify($customer)->forceFill(['status' => $status, 'shahkar_matched' => $matched])->save();
            $this->assertTrue(IranSalesGate::blocksIranFor($customer), $status.' / '.(int) $matched);
        }
        ShahkarFixture::verify($customer);
        $customer->profiles()->update(['status' => 'draft']);
        $this->assertFalse($customer->isVerified());
        $this->assertFalse(IranSalesGate::blocksIranFor($customer));
        $this->assertTrue(IranSalesGate::blocksIranFor(null));
        Http::assertNothingSent();
    }

    public function test_eligibility_reads_current_evidence_after_a_cached_relation_is_revoked(): void
    {
        $customer = $this->customer(true);
        $customer->load('identityVerification');
        $this->assertFalse(IranSalesGate::blocksIranFor($customer));
        IdentityVerification::where('customer_id', $customer->id)->update(['shahkar_matched' => false]);
        $this->assertTrue($customer->identityVerification->shahkar_matched, 'Fixture intentionally retains a stale loaded relation');
        $this->assertTrue(IranSalesGate::blocksIranFor($customer));
        Http::assertNothingSent();
    }

    public function test_forged_iran_cloud_orders_and_country_metadata_aliases_have_no_financial_or_provider_effect(): void
    {
        $this->gpuFreeCloud();
        CloudLocation::create(['code' => 'custom-local', 'country' => 'IR', 'city' => 'Tehran', 'is_active' => true]);
        $alias = CloudPlan::where('location_code', 'ir-tehran')->firstOrFail()->replicate();
        $alias->forceFill(['provider_ref' => 'alias-ir', 'location_code' => 'custom-local', 'slug' => 'alias-iran-plan'])->save();
        $customer = $this->customer(false);
        $customer->profiles()->update(['status' => 'verified']);
        $this->credit($customer);

        $html = $this->actingAs($customer, 'customer')->get('/en/account/cloud-store?location=custom-local')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-city="custom-local"', $html);
        $this->assertStringNotContainsString('data-city="ir-tehran"', $html);
        foreach ([['ir-tehran', 'cv-2c-4g-40d-ir-tehran', 'cycle'], ['ir-tehran', 'cv-2c-4g-40d-ir-tehran', 'hourly'], ['custom-local', 'alias-iran-plan', 'hourly']] as [$location, $plan, $billing]) {
            $this->post('/en/account/cloud-store', [
                'location' => $location, 'plan' => $plan, 'image' => 'ubuntu-24.04',
                'cycle' => 'monthly', 'billing_mode' => $billing,
            ])->assertSessionHasErrors('location');
        }
        $this->assertNoPurchaseEffects($customer);
    }

    public function test_omitting_country_cannot_purchase_a_default_iran_server_in_any_product_category(): void
    {
        $server = Server::create(['name' => 'Private Iran delivery', 'type' => 'generic', 'country' => 'IR',
            'hostname' => 'local.test', 'status' => 'active']);
        $customer = $this->customer(false);
        $customer->profiles()->update(['status' => 'verified']);
        $this->credit($customer);
        $this->actingAs($customer, 'customer');

        foreach (['shared', 'reseller', 'vps', 'dedicated', 'plesk', 'directadmin', 'other'] as $category) {
            $product = Product::create(['name' => 'Synthetic '.$category, 'slug' => 'iran-'.$category,
                'category' => $category, 'server_id' => $server->id, 'price' => 200000,
                'cycle' => 'monthly', 'requires_domain' => false, 'is_active' => true]);
            $this->assertSame([], $product->availableCountries());
            $this->get('/account/order/'.$product->slug)->assertRedirect('/account/services');
            $this->post('/account/order/'.$product->slug, ['country' => '', 'cycle' => 'monthly'])
                ->assertSessionHasErrors('country');
        }
        $this->assertNoPurchaseEffects($customer);
    }

    public function test_iran_only_declared_locations_without_a_server_are_still_restricted(): void
    {
        $customer = $this->customer(false);
        $this->credit($customer);
        $product = Product::create(['name' => 'Manual domestic delivery', 'slug' => 'manual-domestic',
            'category' => 'dedicated', 'locations' => ['IR'], 'price' => 200000,
            'cycle' => 'monthly', 'requires_domain' => false, 'is_active' => true]);
        $this->actingAs($customer, 'customer')->post('/account/order/'.$product->slug, ['cycle' => 'monthly'])
            ->assertSessionHasErrors('country');
        $this->assertNoPurchaseEffects($customer);
    }

    public function test_builder_iran_default_is_rejected_before_domain_lookup_or_invoice_creation(): void
    {
        $server = Server::create(['name' => 'Iran builder', 'type' => 'generic', 'country' => 'IR',
            'hostname' => 'builder.test', 'status' => 'active']);
        $product = Product::create(['name' => 'Domestic builder', 'slug' => 'domestic-builder',
            'category' => 'shared', 'group' => 'site-builder', 'server_id' => $server->id,
            'price' => 200000, 'cycle' => 'monthly', 'requires_domain' => false, 'is_active' => true]);
        $customer = $this->customer(false);
        $this->credit($customer);
        $this->actingAs($customer, 'customer')->get('/account/builder-checkout?'.http_build_query([
            'ref' => 'SB-BLOCK1', 'plan' => $product->slug, 'domain' => 'synthetic.example.com',
        ]))->assertRedirect('/account/services');
        $this->post('/account/builder-checkout', ['ref' => 'SB-BLOCK1', 'plan' => $product->slug, 'quote_id' => 1])
            ->assertSessionHasErrors('country');
        $this->assertDatabaseCount('domains', 0);
        $this->assertDatabaseCount('domain_quotes', 0);
        $this->assertNoPurchaseEffects($customer);
    }

    public function test_foreign_default_delivery_stays_purchasable_without_shahkar(): void
    {
        $server = Server::create(['name' => 'Foreign manual delivery', 'type' => 'generic', 'country' => 'DE',
            'hostname' => 'foreign.test', 'status' => 'active']);
        $product = Product::create(['name' => 'Foreign service', 'slug' => 'foreign-service', 'category' => 'dedicated',
            'server_id' => $server->id, 'price' => 200000, 'base_price_eur' => 2, 'cycle' => 'monthly', 'requires_domain' => false, 'is_active' => true]);
        $customer = $this->customer(false);
        $this->actingAs($customer, 'customer')->post('/account/order/'.$product->slug, ['country' => '', 'cycle' => 'monthly'])
            ->assertSessionHasNoErrors();
        $this->assertSame($server->id, \App\Models\Service::firstOrFail()->server_id);
        $this->assertDatabaseCount('invoices', 1);
    }

    private function credit(Customer $customer): void
    {
        app(\App\Services\Finance\Wallet::class)->credit($customer->id, 'IRT', 5000000, 'synthetic-policy-test', $customer);
    }

    private function assertNoPurchaseEffects(Customer $customer): void
    {
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('cloud_instances', 0);
        $this->assertDatabaseCount('credit_ledger', 1);
        $this->assertSame(5000000, $customer->creditBalance('IRT'));
        Http::assertNothingSent();
    }
}
