<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\CloudLocation;
use App\Models\CloudImage;
use App\Models\Customer;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\LightNodeClient;
use App\Services\Cloud\LightNodeOrderReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LightNodeOrderReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lightnode.sales_enabled' => true, 'lightnode.allow_fixture_rates' => true]);
        Setting::put('pricing_rate_override', '100000');
        Setting::put('cloud_margin_pct', '5');
        Setting::put('pricing_fx_fee_pct_lightnode', '0');
    }

    private function plan(string $provider = 'lightnode'): CloudPlan
    {
        return CloudPlan::create([
            'provider' => $provider, 'provider_ref' => 'ph-test|ph-test-a|package',
            'provider_location' => 'ph-test|ph-test-a', 'location_code' => 'ph-manila',
            'slug' => 'test-'.$provider, 'public_name' => 'CV-2-4', 'vcpu' => 2,
            'ram_mb' => 4096, 'disk_gb' => 50, 'boot_disk_gb' => 50, 'data_disk_gb' => 0,
            'cpu_kind' => 'shared', 'cost_eur_cents' => 1000, 'cost_hour_eur_micro' => 10000,
            'price_eur_cents' => 1050, 'price_irt' => 1050000, 'is_active' => true,
            'in_stock' => true, 'admin_disabled' => false,
        ])->refresh();
    }

    public function test_valid_fresh_quote_updates_only_this_offer_and_requires_price_reconfirmation(): void
    {
        $plan = $this->plan();
        $other = $this->plan('hetzner');
        $before = $other->getRawOriginal();
        $this->mock(LightNodeClient::class)->shouldReceive('reviewPurchase')->once()->andReturn([
            'ok' => true, 'cost' => ['cost_eur_cents' => 2000, 'cost_hour_eur_micro' => 30000],
        ]);
        $review = app(LightNodeOrderReview::class)->review($plan, 'windows-2022');
        $this->assertSame(['ok' => true, 'changed' => true], $review);
        $this->assertSame(2100000, $plan->price_irt);
        $this->assertSame(3200, $plan->hourlyIrt());
        $this->assertTrue($plan->is_active && $plan->in_stock && ! $plan->admin_disabled);
        $this->assertSame($before, $other->fresh()->getRawOriginal());
        $this->assertSame(0, Service::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, CreditEntry::count());
    }

    public function test_unverified_quote_cannot_change_prices_or_create_a_bill(): void
    {
        $plan = $this->plan();
        $before = $plan->getRawOriginal();
        $this->mock(LightNodeClient::class)->shouldReceive('reviewPurchase')->once()->andReturn(['ok' => false]);
        $this->assertSame(['ok' => false, 'changed' => false], app(LightNodeOrderReview::class)->review($plan, 'windows-2022'));
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, CreditEntry::count());
    }

    public function test_settings_change_during_quote_rejects_it_without_a_price_write(): void
    {
        $plan = $this->plan();
        $before = $plan->getRawOriginal();
        $this->mock(LightNodeClient::class)->shouldReceive('reviewPurchase')->once()->andReturnUsing(function () {
            Setting::put('cloud_margin_pct', '12');
            return ['ok' => true, 'cost' => ['cost_eur_cents' => 2000, 'cost_hour_eur_micro' => 30000]];
        });
        $this->assertFalse(app(LightNodeOrderReview::class)->review($plan, 'ubuntu-24.04')['ok']);
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
    }

    public function test_an_unchanged_quote_does_not_require_reconfirmation(): void
    {
        $plan = $this->plan();
        $this->mock(LightNodeClient::class)->shouldReceive('reviewPurchase')->once()->andReturn([
            'ok' => true, 'cost' => ['cost_eur_cents' => 1000, 'cost_hour_eur_micro' => 10000],
        ]);
        $this->assertSame(['ok' => true, 'changed' => false], app(LightNodeOrderReview::class)->review($plan, 'ubuntu-24.04'));
    }

    private function checkoutRejected(array $review): void
    {
        Http::fake();
        Route::post('/account/qa/lightnode-order', [\App\Http\Controllers\Account\CloudStoreController::class, 'order'])->middleware('web');
        $plan = $this->plan();
        CloudLocation::create(['code' => 'ph-manila', 'country' => 'PH', 'city' => 'Manila', 'is_active' => true]);
        CloudImage::create(['provider' => 'lightnode', 'provider_ref' => 'ph-test|win-test',
            'key' => 'windows-2022', 'kind' => 'os', 'family' => 'windows', 'label' => 'Windows',
            'arch' => 'x86', 'min_disk_gb' => 50, 'min_ram_mb' => 4096, 'is_active' => true]);
        $customer = Customer::create(['email' => 'ln-review@example.test', 'password' => 'test-password', 'status' => 'active', 'locale' => 'fa']);
        $this->mock(LightNodeOrderReview::class)->shouldReceive('review')->times(3)->andReturn($review);
        foreach (['fa', 'en', 'tr'] as $locale) {
            $this->app->setLocale($locale);
            $this->actingAs($customer, 'customer')->from('/account/cloud-store')->post('/account/qa/lightnode-order', [
                'location' => 'ph-manila', 'plan' => $plan->slug, 'image' => 'windows-2022',
                'cycle' => 'monthly', 'billing_mode' => 'hourly',
            ])->assertRedirect('/account/cloud-store')->assertSessionHasErrors('plan');
        }
        $this->assertSame(0, Service::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, CreditEntry::count());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_checkout_rejects_unverified_quotes_before_any_payment_in_all_languages(): void
    {
        $this->checkoutRejected(['ok' => false, 'changed' => false]);
    }

    public function test_checkout_requires_reconfirmation_after_price_change_before_any_payment_in_all_languages(): void
    {
        $this->checkoutRejected(['ok' => true, 'changed' => true]);
    }
}
