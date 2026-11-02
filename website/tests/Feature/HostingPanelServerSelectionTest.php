<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Prevent Windows catalog purchases from being silently routed to Linux WHM. */
class HostingPanelServerSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['provisioning.plesk_auto' => false]);
    }

    private function server(string $type, string $country, array $attributes = []): Server
    {
        return Server::create(array_merge([
            'name' => 'Fixture '.$type.' '.$country, 'type' => $type,
            'country' => $country, 'status' => 'active', 'active_accounts' => 0,
        ], $attributes));
    }

    private function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Fixture Windows hosting', 'slug' => uniqid('windows-'),
            'group' => 'windows', 'category' => 'shared', 'plan' => 'fixture_windows',
            'price' => 100000, 'base_price_eur' => '1.000000000000', 'setup_fee' => 0,
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'tax_percent' => 0,
            'requires_domain' => true, 'is_active' => true,
        ], $attributes));
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => uniqid('hosting-panel-').'@example.test', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'fixture-only', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    public function test_legacy_windows_catalog_rows_offer_only_plesk_countries_and_never_pick_whm(): void
    {
        $whm = $this->server('whm', 'IR');
        $this->server('whm', 'DE');
        $plesk = $this->server('plesk', 'IR', ['active_accounts' => 3]);
        $product = $this->product(['server_id' => $whm->id]);

        $this->assertSame(['IR'], $product->availableCountries());
        $this->assertSame($plesk->id, $product->pickOrderServer('IR')?->id);
        $this->assertNull($product->pickOrderServer('DE'));
        $this->assertSame($whm->id, $product->fresh()->server_id); // selection is read-only
        $this->assertFalse($plesk->isAutoProvisioned()); // manual delivery is preserved
    }

    public function test_windows_reseller_catalog_rows_cannot_fall_back_to_linux_reseller_servers(): void
    {
        $this->server('whm', 'DE');
        $plesk = $this->server('plesk', 'DE');
        $product = $this->product(['group' => 'reseller-windows', 'category' => 'reseller']);

        $this->assertSame($plesk->id, $product->pickOrderServer('DE')?->id);
        $this->assertTrue($product->isReseller());
    }

    public function test_explicit_panel_categories_do_not_require_catalog_group_names(): void
    {
        $this->server('whm', 'DE');
        foreach (['plesk', 'directadmin'] as $type) {
            $server = $this->server($type, 'DE');
            $product = $this->product(['category' => $type, 'group' => null]);
            $this->assertSame($server->id, $product->pickOrderServer('DE')?->id);
        }
    }

    public function test_linux_and_linux_reseller_choices_remain_whm_only(): void
    {
        $whm = $this->server('whm', 'IR', ['active_accounts' => 3]);
        $this->server('plesk', 'IR');
        $this->server('directadmin', 'FI');
        foreach (['shared' => 'linux', 'reseller' => 'reseller-linux'] as $category => $group) {
            $product = $this->product(['category' => $category, 'group' => $group]);
            $this->assertSame(['IR'], $product->availableCountries());
            $this->assertSame($whm->id, $product->pickOrderServer('IR')?->id);
        }
    }

    public function test_full_and_inactive_compatible_servers_do_not_make_a_country_available(): void
    {
        $this->server('whm', 'IR');
        $this->server('plesk', 'IR', ['max_accounts' => 1, 'active_accounts' => 1]);
        $this->server('plesk', 'DE', ['status' => 'maintenance']);
        $product = $this->product();

        $this->assertSame([], $product->availableCountries());
        $this->assertNull($product->pickOrderServer('IR'));
        $this->assertNull($product->pickOrderServer('DE'));
    }

    public function test_selection_uses_the_least_loaded_compatible_server_in_the_selected_country(): void
    {
        $this->server('whm', 'DE');
        $this->server('plesk', 'IR');
        $this->server('plesk', 'DE', ['active_accounts' => 8]);
        $expected = $this->server('plesk', 'DE', ['active_accounts' => 2]);
        $this->assertSame($expected->id, $this->product()->pickOrderServer('de')?->id);
    }

    public function test_product_location_restrictions_still_apply_to_compatible_panels(): void
    {
        $this->server('plesk', 'IR');
        $this->server('whm', 'DE');
        $product = $this->product(['locations' => ['DE']]);
        $this->assertSame([], $product->availableCountries());
        $this->assertNull($product->pickOrderServer('IR'));
    }

    public function test_storage_and_nonhosting_products_do_not_gain_a_hosting_panel_requirement(): void
    {
        foreach (['storage-box', 'object-storage', 'block-storage'] as $group) {
            $this->assertNull((new Product(['category' => 'shared', 'group' => $group]))->hostingServerType());
        }
        foreach (['vps', 'dedicated', 'license', 'other'] as $category) {
            $this->assertNull((new Product(['category' => $category]))->hostingServerType());
        }
    }

    public function test_no_country_or_a_stale_country_cannot_bypass_missing_plesk_capacity(): void
    {
        $whm = $this->server('whm', 'IR');
        $product = $this->product(['server_id' => $whm->id]);
        $customer = $this->customer();
        foreach ([[], ['country' => 'IR']] as $location) {
            $this->actingAs($customer, 'customer')->post('/account/order/'.$product->slug, $location + [
                'cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'fixture.example.test',
            ])->assertSessionHasErrors('country');
        }
        $this->assertSame(0, Service::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_whm_only_country_cannot_be_submitted_for_a_windows_product(): void
    {
        $this->server('whm', 'DE');
        $this->server('plesk', 'IR');
        $product = $this->product();
        $this->actingAs($this->customer(), 'customer')->post('/account/order/'.$product->slug, [
            'country' => 'DE', 'cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'fixture.example.test',
        ])->assertSessionHasErrors('country');
        $this->assertSame(0, Service::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_an_unlocated_direct_hosting_server_cannot_bypass_country_validation(): void
    {
        $server = $this->server('whm', 'DE');
        $server->update(['country' => null]);
        $product = $this->product(['category' => 'shared', 'group' => 'linux', 'server_id' => $server->id]);

        $this->actingAs($this->customer(), 'customer')->post('/account/order/'.$product->slug, [
            'cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'fixture.example.test',
        ])->assertSessionHasErrors('country');

        $this->assertSame(0, Service::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_windows_order_records_plesk_even_if_the_old_product_server_was_whm(): void
    {
        Setting::put('pricing_rate_override', '300000');
        $whm = $this->server('whm', 'DE');
        $plesk = $this->server('plesk', 'DE');
        $product = $this->product(['server_id' => $whm->id]);
        $customer = $this->customer();
        $this->actingAs($customer, 'customer')->post('/account/order/'.$product->slug, [
            'country' => 'DE', 'cycle' => 'monthly', 'domain_mode' => 'have', 'domain' => 'fixture.example.test',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame($plesk->id, $service->server_id);
        $this->assertSame($product->plan, $service->plan);
        $this->assertSame(1, Invoice::where('service_id', $service->id)->count());
        $this->assertFalse($plesk->isAutoProvisioned());
    }

    public function test_unlocated_nonhosting_manual_orders_keep_their_existing_path(): void
    {
        Setting::put('pricing_rate_override', '300000');
        $server = $this->server('generic', 'DE');
        $product = $this->product(['category' => 'other', 'group' => null, 'server_id' => $server->id, 'requires_domain' => false]);
        $customer = $this->customer();
        $this->actingAs($customer, 'customer')->post('/account/order/'.$product->slug, ['cycle' => 'monthly'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($server->id, Service::where('customer_id', $customer->id)->firstOrFail()->server_id);
    }
}
