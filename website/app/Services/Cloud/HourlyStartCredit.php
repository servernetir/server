<?php

namespace App\Services\Cloud;

use App\Models\CloudPlan;
use App\Models\Service;

/** The 24-hour wallet requirement for adding or restarting an hourly server. */
class HourlyStartCredit
{
    /**
     * Existing hourly commitments use their locked service rate. An interruptible
     * instance confirmed off costs no running hours; an unknown or provisioning
     * instance remains in the total until its state is known.
     */
    public function existingRate(int $customerId, ?int $exceptServiceId = null): int
    {
        return (int) Service::query()
            ->where('customer_id', $customerId)
            ->where('billing_mode', 'hourly')
            ->whereNotIn('status', Service::DEAD_STATUSES)
            ->where('hourly_rate_irt', '>', 0)
            ->when($exceptServiceId !== null, fn ($q) => $q->where('id', '!=', $exceptServiceId))
            ->with(['cloudPlan', 'cloudInstance'])
            ->get()
            ->sum(fn (Service $service) => $this->countsTowardMinimum($service)
                ? (int) $service->hourly_rate_irt : 0);
    }

    public function minimum(int $customerId, int $newRate, ?int $exceptServiceId = null): int
    {
        return ($newRate + $this->existingRate($customerId, $exceptServiceId))
            * CloudPlan::HOURLY_START_MIN_HOURS;
    }

    private function countsTowardMinimum(Service $service): bool
    {
        return ! ((bool) $service->cloudPlan?->is_interruptible
            && $service->cloudInstance?->status === 'off');
    }
}
