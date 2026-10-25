<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Finance\ArvanTrafficMeter;
use App\Services\Finance\ArvanTrafficSource;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\ArvanTrafficMeterFixture;
use Tests\TestCase;

class ArvanTrafficMeterTest extends TestCase
{
    use ArvanTrafficMeterFixture;
    use RefreshDatabase;

    public function test_initialization_requires_verified_paid_baseline(): void
    {
        [, , $plan, $snapshot] = $this->fixture(false);
        $this->expectExceptionMessage('Paid baseline proof missing');
        app(ArvanTrafficMeter::class)->initialize($plan, $snapshot, [], 'Synthetic owner authorization');
    }

    public function test_baseline_replay_new_consumption_and_retry_only_charge_delta(): void
    {
        [$customer, $service, , $snapshot] = $this->fixture();
        $before = $service->fresh()->getRawOriginal();
        $meter = app(ArvanTrafficMeter::class);
        $this->assertSame(0, $meter->run($snapshot, true)['total_irt']);
        $this->assertSame(1, Invoice::count());
        $next = $this->growth($snapshot);
        $this->assertSame(2079, $meter->run($next, false)['total_irt']);
        $this->assertSame(1, CreditEntry::count());
        $this->assertSame(2079, $meter->run($next, true)['total_irt']);
        $this->assertSame(-4158, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(0, $meter->run($next, true)['total_irt']);
        $this->assertSame(2, Invoice::count());
        $this->assertSame(2, Payment::count());
        $this->assertSame(2, CreditEntry::count());
        $this->assertSame(4, BusinessEntry::count());
        $this->assertSame($before, $service->fresh()->getRawOriginal());
        $this->assertSame(1, DB::table('finance_audit_events')->where('action', 'traffic_meter_settlement')->count());
        $this->assertStringNotContainsString('arvan', Invoice::latest('id')->first()->items->toJson());
    }

    public function test_cumulative_rounding_does_not_charge_one_toman_per_tiny_poll(): void
    {
        [$customer, , , $snapshot] = $this->fixture();
        $meter = app(ArvanTrafficMeter::class);
        foreach (range(18001, 18020) as $n => $cost) {
            $next = $this->growth($snapshot, $cost, 1 + ($n + 1) / 100, sprintf('2026-10-25T10:%02d:00Z', $n));
            $meter->run($next, true);
        }
        $expectedSell = (int) ceil(18020 * 1.05 / 10);
        $expectedTax = (int) round($expectedSell * .1);
        $this->assertSame(-($expectedSell + $expectedTax), app(Wallet::class)->balanceOf($customer->id));
    }

    public function test_counter_regression_and_same_window_repricing_are_held_without_lowering_baseline(): void
    {
        [, , , $snapshot] = $this->fixture();
        $meter = app(ArvanTrafficMeter::class);
        $before = $meter->state()['checkpoints'];
        foreach ([$this->growth($snapshot, 10000, .5), $this->growth($snapshot, 36000, 2, '2026-10-25T08:00:00Z')] as $next) {
            $result = $meter->run($next, true);
            $this->assertSame(0, $result['total_irt']);
            $this->assertCount(1, $result['held']);
        }
        $this->assertSame($before, $meter->state()['checkpoints']);
        $this->assertSame(1, CreditEntry::count());
    }

    public function test_excluded_customer_is_never_charged_again(): void
    {
        [, , , $snapshot] = $this->fixture(true, true);
        $result = app(ArvanTrafficMeter::class)->run($this->growth($snapshot), true);
        $this->assertSame(0, $result['total_irt']);
        $this->assertSame('owner_exclusion', $result['held'][0]['reason']);
        $this->assertSame(1, CreditEntry::count());
    }

    public function test_manual_charge_and_changed_owner_are_not_double_billed(): void
    {
        [$customer, $service, , $snapshot] = $this->fixture();
        app(Wallet::class)->debit($customer->id, 'IRT', 100, 'manual_traffic', $customer, requireAvailable: false);
        $meter = app(ArvanTrafficMeter::class);
        $result = $meter->run($this->growth($snapshot), true);
        $this->assertSame('manual_traffic_charge_requires_reconciliation', $result['held'][0]['reason']);
        $this->assertSame(2, CreditEntry::count());
        $other = Customer::create(['email' => 'changed@example.com', 'status' => 'active']);
        $service->forceFill(['customer_id' => $other->id])->save();
        $result = $meter->run($this->growth($snapshot), true);
        $this->assertSame(0, $result['total_irt']);
        $this->assertCount(1, $result['held']);
    }

    public function test_accounting_failure_rolls_back_checkpoint_invoice_and_wallet_together(): void
    {
        [, , , $snapshot] = $this->fixture();
        $meter = app(ArvanTrafficMeter::class);
        $before = $meter->state();
        $ledger = \Mockery::mock(BusinessLedger::class)->makePartial();
        $ledger->shouldReceive('recordPayment')->once()->andThrow(new RuntimeException('synthetic ledger failure'));
        $this->app->instance(BusinessLedger::class, $ledger);
        try {
            $meter->run($this->growth($snapshot), true);
            $this->fail('Ledger failure swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('synthetic ledger failure', $e->getMessage());
        }
        $this->assertSame($before, $meter->state());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, CreditEntry::count());
    }

    public function test_partial_or_duplicate_source_is_rejected_before_any_debit(): void
    {
        [, , , $snapshot] = $this->fixture();
        $next = $this->growth($snapshot);
        $next['reports'][0]['rows'][] = $next['reports'][0]['rows'][0];
        $next['reports'][0]['total_items'] = 2;
        $this->expectExceptionMessage('Duplicate');
        app(ArvanTrafficMeter::class)->run($next, true);
    }

    public function test_tenant_rows_have_no_customer_charge_and_invalid_totals_fail(): void
    {
        [, , , $snapshot] = $this->fixture();
        $snapshot = $this->growth($snapshot);
        $snapshot['reports'][0]['rows'][0]['scope'] = 'tenant';
        $result = app(ArvanTrafficMeter::class)->run($snapshot, true);
        $this->assertSame(0, $result['total_irt']);
        $this->assertSame(1, $result['unallocated_count']);
        $snapshot['reports'][0]['cycle']['totalPrice'] = '1';
        $this->expectExceptionMessage('cycle totals');
        app(ArvanTrafficMeter::class)->run($snapshot, true);
    }

    public function test_pricing_change_stops_before_writing_money(): void
    {
        [, , , $snapshot] = $this->fixture();
        Setting::put('cloud_margin_pct', '25');
        $this->expectExceptionMessage('pricing changed');
        app(ArvanTrafficMeter::class)->run($this->growth($snapshot), true);
    }

    public function test_finance_fetch_fails_on_second_page_instead_of_returning_partial_bill(): void
    {
        [, , , $snapshot] = $this->fixture();
        Setting::putSecret('arvan_api_token', 'synthetic-token');
        Http::fake(function ($request) use ($snapshot) {
            if (str_contains($request->url(), 'usage-items')) {
                if ($request['page'] === 2) {
                    return Http::response([], 503);
                }

                return Http::response(['data' => $snapshot['reports'][0]['rows'],
                    'meta' => ['pagination' => ['current' => 1, 'totalPages' => 2, 'totalItems' => 2]]]);
            }

            return Http::response(['data' => $snapshot['reports'][0]['cycle']]);
        });
        $this->expectExceptionMessage('HTTP 503');
        app(ArvanTrafficSource::class)->fetch();
    }

    public function test_changed_discount_and_gift_are_held_and_never_lower_checkpoint(): void
    {
        [, , , $snapshot] = $this->fixture();
        $next = $this->growth($snapshot);
        $next['reports'][0]['rows'][0]['items'][0]['discount'] = '100';
        $next['reports'][0]['rows'][0]['totalDiscount'] = '100';
        $next['reports'][0]['rows'][0]['netPrice'] = '35900';
        $next['reports'][0]['cycle']['totalItemsDiscount'] = '100';
        $next['reports'][0]['cycle']['totalPrice'] = '35900';
        $meter = app(ArvanTrafficMeter::class);
        $before = $meter->state()['checkpoints'];
        $result = $meter->run($next, true);
        $this->assertSame(0, $result['total_irt']);
        $this->assertCount(1, $result['held']);
        $this->assertSame($before, $meter->state()['checkpoints']);
    }

    public function test_future_resource_can_start_but_historic_unbaselined_resource_is_held(): void
    {
        [$customer, , , $snapshot] = $this->fixture();
        $native = '00000000-0000-0000-0000-000000000002';
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'New synthetic server', 'status' => 'active',
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'price' => 1000]);
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'arvan', 'provider_ref' => 'ir-test:'.$native]);
        $snapshot['reports'][0]['rows'][0]['instanceId'] = $native;
        $meter = app(ArvanTrafficMeter::class);
        $result = $meter->run($snapshot, true);
        $this->assertSame(0, $result['total_irt']);
        $this->assertSame('historic_resource_requires_baseline', $result['held'][0]['reason']);
        $snapshot['observed_at'] = '2026-10-25T15:00:00Z';
        $snapshot['reports'][0]['rows'][0]['items'][0]['usageFrom'] = '2026-10-25T12:01:00Z';
        $snapshot['reports'][0]['rows'][0]['items'][0]['usageTo'] = '2026-10-25T14:00:00Z';
        $result = $meter->run($snapshot, true);
        $this->assertSame(2079, $result['total_irt']);
        $this->assertSame(0, $meter->run($snapshot, true)['total_irt']);
    }

    public function test_next_cycle_starts_its_own_checkpoint_without_rebilling_previous_cycle(): void
    {
        [, , , $snapshot] = $this->fixture();
        $new = $snapshot;
        $new['current_cycle'] = $new['reports'][0]['cycle']['id'] = '12345678-1234-1234-1234-123456789abd';
        $new['observed_at'] = '2026-11-23T12:00:00Z';
        $new['reports'][0]['cycle']['startedAt'] = '2026-11-22T00:00:00Z';
        $new['reports'][0]['cycle']['expiredAt'] = '2026-12-22T00:00:00Z';
        $new['reports'][0]['rows'][0]['items'][0]['usageFrom'] = '2026-11-22T00:00:00Z';
        $new['reports'][0]['rows'][0]['items'][0]['usageTo'] = '2026-11-23T00:00:00Z';
        $meter = app(ArvanTrafficMeter::class);
        $this->assertSame(2079, $meter->run($new, true)['total_irt']);
        $this->assertSame(0, $meter->run($snapshot, true)['total_irt']);
        $this->assertCount(2, $meter->state()['checkpoints']);
    }
}
