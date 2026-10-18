<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\{CloudImage, CloudInstance, CloudLocation, CloudPlan, Customer, Invoice, Product, Service, Setting};
use App\Services\Cloud\CloudAddons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class CloudStoreCycleCostFloorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        NavasanRates::cloudSnapshot(['USD' => 280000, 'EUR' => 302350, 'USDT' => 281000]);
        Setting::put('cloud_margin_pct', '5');
    }

    private function catalogue(): array
    {
        CloudLocation::create(['code' => 'de-falkenstein', 'country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);
        $plan = CloudPlan::create(['provider' => 'hetzner', 'provider_ref' => 'synthetic-small', 'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme', 'arch' => 'x86',
            'cost_eur_cents' => 701, 'price_eur_cents' => 740, 'price_irt' => 2240000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false]);
        $larger = $plan->replicate()->fill(['provider_ref' => 'synthetic-large', 'public_name' => 'CV-4-8',
            'slug' => 'cv-4c-8g-80d-de-falkenstein', 'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80,
            'cost_eur_cents' => 800, 'price_eur_cents' => 1330, 'price_irt' => 4000000]);
        $larger->save();
        CloudImage::create(['provider' => 'hetzner', 'provider_ref' => 'ubuntu24', 'key' => 'ubuntu-24.04',
            'family' => 'ubuntu', 'kind' => 'os', 'arch' => 'x86', 'label' => 'Ubuntu 24.04', 'is_active' => true]);
        $customer = Customer::create(['email' => 'cost-floor@example.test', 'phone' => '09120000000',
            'password' => 'synthetic', 'status' => 'active', 'locale' => 'fa']);

        return [$plan, $larger, $customer];
    }

    public function test_low_margin_discount_stops_at_real_cost_and_preserves_the_profitable_cycle(): void
    {
        [$plan] = $this->catalogue();
        $plan->refresh();
        $before = $plan->getAttributes();
        $this->assertSame(2240000, CloudStoreController::priceForCycle($plan, 'monthly'));
        // Five percent quarterly still covers procurement; keep it unchanged.
        $this->assertSame(6390000, CloudStoreController::priceForCycle($plan, 'quarterly'));
        // The old 10/20 percent discounts undercut six/twelve months of cost.
        $this->assertSame(12720000, CloudStoreController::priceForCycle($plan, 'semiannual'));
        $this->assertSame(25440000, CloudStoreController::priceForCycle($plan, 'yearly'));
        $this->assertSame($before, $plan->fresh()->getAttributes());
        $this->assertSame('5', Setting::get('cloud_margin_pct'));
        Http::assertNothingSent();
    }

    public function test_a_profitable_plan_keeps_its_configured_discounts_without_an_added_profit_floor(): void
    {
        [, $plan] = $this->catalogue();
        foreach (CloudStoreController::cycles() as $cycle) {
            $months = Service::monthsIn($cycle);
            $discount = (int) config('billing.cycles.'.$cycle.'.discount_pct');
            $expected = Product::roundUpToman(4000000 * $months * (100 - $discount) / 100);
            $this->assertSame($expected, CloudStoreController::priceForCycle($plan, $cycle));
        }
        Http::assertNothingSent();
    }

    public function test_an_unavailable_zero_or_negative_sale_price_is_never_reopened_by_the_cost_floor(): void
    {
        foreach ([0, -10000] as $price) {
            $plan = new CloudPlan(['provider' => 'hetzner', 'cost_eur_cents' => 701, 'price_irt' => $price]);
            $this->assertSame(0, CloudStoreController::priceForCycle($plan, 'monthly'));
            $this->assertSame(0, CloudStoreController::priceForCycle($plan, 'yearly', ['extra_ipv4' => 2]));
        }
        Http::assertNothingSent();
    }

    public function test_rendered_actual_savings_and_addon_discount_are_independent_per_plan(): void
    {
        [$low, $normal, $customer] = $this->catalogue();
        $html = $this->actingAs($customer, 'customer')->get('/account/cloud-store?'.http_build_query([
            'location' => 'de-falkenstein', 'plan' => $low->slug, 'cycle' => 'semiannual',
        ]))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/\bvar D = (\{[^\r\n]*\});/', $html, $match));
        $data = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(12720000, $data['prices'][$low->slug]['semiannual']['cycle']);
        $this->assertSame(5, $data['prices'][$low->slug]['semiannual']['save']);
        $this->assertSame(10, $data['prices'][$low->slug]['semiannual']['addonDiscount']);
        $this->assertSame(10, $data['prices'][$normal->slug]['semiannual']['save']);
        $this->assertSame(20, $data['prices'][$normal->slug]['yearly']['save']);
        $this->assertStringContainsString('<em data-cycle-saving="semiannual" >'.fa_num(5).__('ui.cvb_save_suf').'</em>', $html);
        $this->assertStringNotContainsString('<em data-cycle-saving="semiannual" >'.fa_num(10).__('ui.cvb_save_suf').'</em>', $html);
        Http::assertNothingSent();
    }

    public function test_cost_capped_server_plus_discounted_ips_matches_rendered_total_and_unpaid_invoice(): void
    {
        [$plan, , $customer] = $this->catalogue();
        $payload = ['location' => 'de-falkenstein', 'plan' => $plan->slug, 'image' => 'ubuntu-24.04',
            'cycle' => 'semiannual', 'extra_ipv4' => 2];
        // Repopulate the real Blade from a harmless validation failure.
        $this->actingAs($customer, 'customer')->post('/account/cloud-store', $payload + ['label' => str_repeat('x', 65)])
            ->assertSessionHasErrors('label');
        $html = $this->get('/account/cloud-store?location=de-falkenstein')->assertOk()->getContent();
        $addon = app(CloudAddons::class)->forCycle(['extra_ipv4' => 2], 'semiannual');
        $this->assertSame(4320000, $addon, 'IP keeps its configured ten percent discount');
        $price = 12720000 + $addon;
        $first = $price + (int) round($price * CloudStoreController::taxPercent() / 100);
        $this->assertStringContainsString('<div class="cvb-tot pnl-num" id="cvb-s-first">'.cloud_price($first).'</div>', $html);
        $this->post('/account/cloud-store', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $service = Service::where('customer_id', $customer->id)->firstOrFail();
        $invoice = Invoice::where('service_id', $service->id)->firstOrFail();
        $this->assertSame($price, (int) $service->price);
        $this->assertSame($first, (int) $invoice->total);
        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame(0, (int) $invoice->paid);
        $this->assertNotSame('pending', $service->provision_status);
        $this->assertSame(0, CloudInstance::count());
        $this->assertDatabaseCount('credit_ledger', 0);
        Http::assertNothingSent();
    }
}
