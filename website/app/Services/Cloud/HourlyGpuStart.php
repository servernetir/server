<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Finance\Wallet;
use Illuminate\Support\Facades\DB;

/** Fund one restart hour without billing an unready GPU. Uses the existing wallet hold. */
class HourlyGpuStart
{
    public static function pending(Service $service): int
    {
        return max(0, (int) data_get($service->provision_meta, 'hourly_restart.amount_irt', 0));
    }

    public function claim(Service $service): array
    {
        return DB::transaction(function () use ($service): array {
            Customer::whereKey($service->customer_id)->lockForUpdate()->firstOrFail();
            $current = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            $instance = CloudInstance::where('service_id', $current->id)->lockForUpdate()->first();
            if ($current->customer_id !== $service->customer_id || ! $current->isHourly()
                || ! (bool) $current->cloudPlan?->is_interruptible
                || $current->status !== 'active' || $current->hourlyBillingHeld() || ! HourlyHold::enabled()) {
                return ['pending' => true];
            }
            if ($instance?->status === 'building' || self::pending($current) > 0) {
                return ['pending' => true];
            }
            if ($instance?->status !== 'off') {
                return [];
            }
            $minimum = max(1, (int) $current->hourly_rate_irt);
            $available = app(Wallet::class)->availableOf((int) $current->customer_id);
            if ($available < $minimum) {
                return compact('minimum', 'available');
            }
            $meta = (array) $current->provision_meta;
            $meta['hourly_restart'] = ['amount_irt' => $minimum, 'claimed_at' => now()->toISOString(), 'observation_version' => 1];
            $meta['hourly_power_at'] = $meta['hourly_restart']['claimed_at'];
            $current->update(['provision_meta' => $meta, 'hold_rate_irt' => 0, 'hold_reserve_irt' => $minimum]);
            $instance->update(['status' => 'building']);

            return ['claimed' => $instance->id];
        });
    }

    /** Called with customer -> service locks, only after verified Running. */
    public function settle(Service $service): void
    {
        $amount = self::pending($service);
        if ($amount <= 0) {
            return;
        }
        $meta = (array) $service->provision_meta;
        unset($meta['hourly_restart']);
        $service->update(['provision_meta' => $meta, 'hold_reserve_irt' => 0]);
        // The birthday module is not installed on every production release.
        // Keep the full-rate admission hold, then charge eligible usage only.
        $charge = class_exists(\App\Services\Birthday\BirthdayHourly::class)
            ? app(\App\Services\Birthday\BirthdayHourly::class)->rates($service, now(), 1, $amount)[0]
            : $amount;
        if ($charge > 0) {
            app(Wallet::class)->debit((int) $service->customer_id, 'IRT', $charge,
                'cloud_hourly', $service, 'ساعت اول پس از روشن‌شدن مجدد سرور گرافیکی');
        }
    }

    /** Rejected start or an explicit stop: release the unused start reservation. */
    public function release(Service $service): void
    {
        DB::transaction(function () use ($service): void {
            Customer::whereKey($service->customer_id)->lockForUpdate()->first();
            $current = Service::whereKey($service->id)->lockForUpdate()->first();
            if ($current === null || self::pending($current) <= 0) {
                return;
            }
            $meta = (array) $current->provision_meta;
            unset($meta['hourly_restart']);
            $current->update(['provision_meta' => $meta, 'hold_reserve_irt' => 0]);
        });
    }
}
