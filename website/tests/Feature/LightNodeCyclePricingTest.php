<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudPlan;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LightNodeCyclePricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_hourly_quote_does_not_inherit_the_calendar_month_reserve(): void
    {
        Http::preventStrayRequests();
        Setting::put('pricing_rate_override', '100000');
        Setting::put('cloud_margin_pct', '5');
        $plan = new CloudPlan(['provider' => 'lightnode', 'cost_hour_eur_micro' => 1_000_000,
            'price_irt' => 78_120_000, 'price_eur_cents' => 78_120]);
        $this->assertSame(105_000, $plan->hourlyIrt());
        $this->assertSame(105, $plan->hourlyEurCents());
    }

    public function test_period_discounts_and_a_low_saved_price_cannot_undercut_a_31_day_cost(): void
    {
        Http::preventStrayRequests();
        Setting::put('pricing_rate_override', '100000');
        Setting::put('cloud_margin_pct', '5');
        config(['billing.cycles.monthly.discount_pct' => 90,
            'billing.cycles.quarterly.discount_pct' => 90, 'billing.cycles.yearly.discount_pct' => 90]);
        $plan = new CloudPlan(['provider' => 'lightnode', 'cost_hour_eur_micro' => 1_000_000, 'price_irt' => 10000]);
        $this->assertSame(78_120_000, CloudStoreController::priceForCycle($plan, 'monthly'));
        $this->assertSame(234_360_000, CloudStoreController::priceForCycle($plan, 'quarterly'));
        $this->assertSame(937_440_000, CloudStoreController::priceForCycle($plan, 'yearly'));
    }
}
