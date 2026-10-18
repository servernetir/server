<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Cloud\CloudPublicQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class CloudPublicQuoteConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        NavasanRates::cloudSnapshot(['USD' => 250000, 'EUR' => 302000, 'USDT' => 250000]);
        config(['billing.cycles.yearly.discount_pct' => 20]);
    }

    private function plan(int $cost = 701, array $attributes = []): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-frankfurt'], [
            'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true,
        ]);

        return CloudPlan::create($attributes + [
            'provider' => 'hetzner', 'provider_ref' => 'public-quote-fixture',
            'location_code' => 'de-frankfurt', 'slug' => 'cv-2c-4g-40d-de-frankfurt',
            'public_name' => 'CV-2-4', 'vcpu' => 2, 'ram_mb' => 4096,
            'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 1024,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => $cost,
            'price_eur_cents' => 750, 'price_irt' => 2240000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ]);
    }

    public static function annualCases(): array
    {
        return [
            'supplier floor limits discount' => [701, 25410000, 2117500, 5],
            'unbound discount keeps whole-cycle rounding' => [400, 21510000, 1792500, 19],
        ];
    }

    #[DataProvider('annualCases')]
    public function test_home_and_country_quotes_match_the_complete_checkout_cycle(
        int $cost, int $annual, int $monthlyEquivalent, int $saving
    ): void {
        $plan = $this->plan($cost);
        $this->assertSame($annual, CloudStoreController::priceForCycle($plan, 'yearly'));

        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $locale) {
            $home = $this->get($prefix ?: '/')->assertOk();
            foreach ([$home->viewData('plans')[0], $home->viewData('products')[2]] as $quote) {
                $this->assertSame(2240000, $quote['irt']);
                $this->assertSame($monthlyEquivalent, $quote['yearly_irt']);
                $this->assertSame($saving, $quote['yearly_savings_pct']);
                $this->assertTrue($quote['final_irt']);
            }
            $html = $home->getContent();
            $this->assertMatchesRegularExpression('~class="pr pr-y"[^>]*>.*?<b>'.preg_quote(cloud_price($monthlyEquivalent), '~').'</b>~s', $html);
            $badge = __('ui.bill_save', ['percent' => $locale === 'fa' ? fa_num($saving) : $saving]);
            $this->assertStringContainsString('<span class="save">'.$badge.'</span>', $html);
            $falseBadge = __('ui.bill_save', ['percent' => $locale === 'fa' ? fa_num(20) : 20]);
            $this->assertStringNotContainsString('<span class="save">'.$falseBadge.'</span>', $html);

            $country = $this->get($prefix.'/vps/germany')->assertOk();
            $quote = $country->viewData('product')['plans'][0];
            $this->assertSame($monthlyEquivalent, $quote['yearly_irt']);
            $this->assertSame($saving, $quote['yearly_savings_pct']);
            $this->assertSame(CloudStoreController::priceForCycle($plan, 'monthly'), $quote['irt']);
        }
        Http::assertNothingSent();
    }

    public function test_fully_bound_quotes_have_no_discount_badge_or_false_strikethrough(): void
    {
        $this->plan(800);
        $response = $this->get('/en')->assertOk();
        $quote = $response->viewData('plans')[0];
        $this->assertSame(0, $quote['yearly_savings_pct']);
        $this->assertSame(CloudStoreController::monthlyEquivalent(CloudPlan::firstOrFail(), 'yearly'), $quote['yearly_irt']);
        preg_match('~<section[^>]+id="pricing".*?</section>~s', $response->getContent(), $pricing);
        $this->assertNotEmpty($pricing[0]);
        $this->assertStringNotContainsString('class="save"', $pricing[0]);
        $this->assertStringNotContainsString('<s>', $pricing[0]);
        Http::assertNothingSent();
    }

    public function test_monthly_country_and_location_minima_include_a_binding_procurement_floor(): void
    {
        $plan = $this->plan(800);
        $monthly = CloudStoreController::priceForCycle($plan, 'monthly');
        $this->assertSame(2420000, $monthly);
        foreach (['', '/en', '/tr'] as $prefix) {
            $home = $this->get($prefix ?: '/')->assertOk();
            $this->assertSame($monthly, $home->viewData('products')[2]['irt']);
            $country = $this->get($prefix.'/vps/germany')->assertOk()->viewData('product')['plans'][0];
            $this->assertSame($monthly, $country['irt']);
            $this->assertSame($monthly, $country['row']['price_n']);
            $this->assertSame($monthly, $country['row']['picker'][0]['irt']);
            $catalog = $this->get($prefix.'/cloud')->assertOk();
            $this->assertSame($monthly, $catalog->viewData('rows')[0]['price_n']);
            $this->assertSame(cloud_price($monthly), $catalog->viewData('fromLabel'));
            $tree = $catalog->viewData('tree')[0];
            $this->assertSame($monthly, $tree['min']);
            $this->assertSame($monthly, $tree['countries'][0]['min']);
            $location = $this->get($prefix.'/cloud/de-frankfurt')->assertOk();
            $this->assertSame(cloud_price($monthly), $location->viewData('rows')[0]['price']);
            $this->assertSame(cloud_price($monthly), $location->viewData('fromLabel'));
        }
        Http::assertNothingSent();
    }

    public function test_provider_specific_hourly_reserves_and_no_discount_contracts_remain_in_public_quotes(): void
    {
        config(['lightnode.sales_enabled' => true, 'lightnode.allow_fixture_rates' => true]);
        $plan = $this->plan(100, ['provider' => 'lightnode', 'cost_hour_eur_micro' => 20000]);
        $quote = app(CloudPublicQuote::class)->quote($plan);
        $this->assertSame(CloudStoreController::priceForCycle($plan, 'monthly'), $quote['irt']);
        $this->assertSame(CloudStoreController::monthlyEquivalent($plan, 'yearly'), $quote['yearly_irt']);
        $this->assertSame(0, $quote['yearly_savings_pct']);
        $this->assertGreaterThan(2240000, $quote['irt']);
        Http::assertNothingSent();
    }

    public function test_starting_prices_choose_the_lowest_effective_quote_after_a_monthly_floor(): void
    {
        $this->plan(800);
        $this->plan(600, [
            'provider_ref' => 'lower-effective-fixture', 'slug' => 'cv-4c-8g-80d-de-frankfurt',
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80, 'price_irt' => 2300000,
        ]);
        $home = $this->get('/')->assertOk();
        $this->assertSame(2300000, $home->viewData('products')[2]['irt']);
        $catalog = $this->get('/cloud')->assertOk();
        $this->assertSame(2300000, $catalog->viewData('rows')[0]['price_n']);
        $this->assertSame('۲,۳۰۰,۰۰۰ تومان', $catalog->viewData('fromLabel'));
        $this->assertSame(2300000, $catalog->viewData('tree')[0]['countries'][0]['min']);
        $this->assertSame('۲,۳۰۰,۰۰۰ تومان', $this->get('/cloud/de-frankfurt')->assertOk()->viewData('fromLabel'));
        Http::assertNothingSent();
    }
}
