<?php

namespace Tests\Support;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Finance\ArvanTrafficMeter;
use App\Services\Finance\ArvanTrafficSettlement;

trait ArvanTrafficMeterFixture
{
    protected function fixture(bool $initialize = true, bool $exclude = false): array
    {
        $this->travelTo(now()->setDate(2026, 10, 25)->setTime(12, 0));
        Setting::put('cloud_margin_pct', '5');
        config(['billing.tax_percent' => 10]);
        $customer = Customer::create(['email' => 'meter@example.com', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Synthetic traffic', 'status' => 'active',
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'price' => 1000]);
        $native = '00000000-0000-0000-0000-000000000001';
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'arvan', 'provider_ref' => 'ir-test:'.$native, 'status' => 'running']);
        $item = ['itemId' => 'synthetic-item', 'metric' => 'cc_internet', 'amount' => 1, 'price' => '18000', 'discount' => '0', 'usedGift' => '0',
            'usageFrom' => '2026-10-25T06:00:00Z', 'usageTo' => '2026-10-25T08:00:00Z'];
        $parent = ['instanceId' => $native, 'scope' => 'instance', 'region' => 'ir-test', 'productName' => 'iaas', 'items' => [$item],
            'totalPrice' => '18000', 'totalDiscount' => '0', 'totalUsedGift' => '0', 'netPrice' => '18000'];
        $cycle = ['id' => '12345678-1234-1234-1234-123456789abc', 'accountId' => 'synthetic-account', 'currency' => 'irr',
            'startedAt' => '2026-10-23T00:00:00Z', 'expiredAt' => '2026-11-22T00:00:00Z',
            'totalItemsPrice' => '18000', 'totalItemsDiscount' => '0', 'totalUsedGift' => '0', 'totalVatPrice' => '0', 'totalPrice' => '18000'];
        $legacy = ['observed_at' => '2026-10-25T10:00:00Z', 'billing' => ['cycle_status' => 200, 'usage_status' => 200,
            'cycle' => ['data' => $cycle], 'usage' => ['data' => [$parent], 'meta' => ['pagination' => ['totalPages' => 1, 'totalItems' => 1]]]],
            'traffic_attribution' => [['service_id' => $service->id, 'customer_id' => $customer->id, 'provider_instance_id' => $native,
                'matched' => true, 'price_irr' => '18000', 'discount_irr' => '0', 'usage_from' => $item['usageFrom'], 'usage_to' => $item['usageTo']]]];
        $json = json_encode($legacy, JSON_THROW_ON_ERROR);
        $plan = app(ArvanTrafficSettlement::class)->plan($json, hash('sha256', $json), 500, 1000);
        $snapshot = ['observed_at' => '2026-10-25T12:00:00Z', 'current_cycle' => $cycle['id'],
            'reports' => [['cycle' => $cycle, 'rows' => [$parent], 'total_items' => 1]]];
        if ($initialize) {
            app(ArvanTrafficSettlement::class)->apply($plan, $plan['hash'], 'Synthetic explicit owner authorization');
            app(ArvanTrafficMeter::class)->initialize($plan, $snapshot, $exclude ? [$customer->id] : [], 'Synthetic recurring owner authorization');
        }

        return [$customer, $service, $plan, $snapshot];
    }

    protected function growth(array $snapshot, int $cost = 36000, float $quantity = 2, string $to = '2026-10-25T10:00:00Z'): array
    {
        $snapshot['reports'][0]['rows'][0]['items'][0]['price'] = (string) $cost;
        $snapshot['reports'][0]['rows'][0]['items'][0]['amount'] = $quantity;
        $snapshot['reports'][0]['rows'][0]['items'][0]['usageTo'] = $to;
        $snapshot['reports'][0]['rows'][0]['totalPrice'] = (string) $cost;
        $snapshot['reports'][0]['rows'][0]['netPrice'] = (string) $cost;
        $snapshot['reports'][0]['cycle']['totalItemsPrice'] = (string) $cost;
        $snapshot['reports'][0]['cycle']['totalPrice'] = (string) $cost;

        return $snapshot;
    }
}
