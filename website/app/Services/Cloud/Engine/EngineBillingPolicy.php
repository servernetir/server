<?php

namespace App\Services\Cloud\Engine;

use App\Models\CloudPlan;
use App\Models\Service;
use App\Services\Cloud\CloudAddons;

/** Existing cycle configuration, with exact integer IRT calculations. */
class EngineBillingPolicy
{
    public function cycles(): array
    {
        return array_keys(array_filter((array) config('billing.cycles', []),
            fn ($cycle) => is_array($cycle) && in_array((int) ($cycle['months'] ?? 0), [1, 3, 6, 12], true)));
    }

    public function accepts(Service $service): bool
    {
        return $service->billing_mode !== 'hourly' && in_array($service->cycle, $this->cycles(), true)
            && ! $service->hasUnverifiedBillingTerms();
    }

    public function quote(CloudPlan $plan, string $cycle, array $addons = []): int
    {
        if (! in_array($cycle, $this->cycles(), true)) return 0;
        $months = Service::monthsIn($cycle);
        $discount = max(0, min(90, (int) config('billing.cycles.'.$cycle.'.discount_pct', 0)));
        $base = $this->cycleAmount((int) $plan->price_irt, $months, $discount);
        $addons = app(CloudAddons::class)->sanitize($addons);
        $monthlyExtra = $addons['extra_ipv4'] > 0 ? app(CloudAddons::class)->monthlyToman($addons) : 0;
        $extra = $this->cycleAmount($monthlyExtra, $months, $discount);
        if ($base > PHP_INT_MAX - $extra) throw new \DomainException('cycle_price_out_of_range');
        return $base + $extra;
    }

    private function cycleAmount(int $monthly, int $months, int $discount): int
    {
        if ($monthly < 0 || $monthly > intdiv(PHP_INT_MAX, $months)) {
            throw new \DomainException('cycle_price_out_of_range');
        }
        $total = $monthly * $months;
        $factor = 100 - $discount;
        $amount = intdiv($total, 100) * $factor + intdiv(($total % 100) * $factor + 99, 100);
        $units = intdiv($amount, 10000) + (int) ($amount % 10000 !== 0);
        if ($units > intdiv(PHP_INT_MAX, 10000)) throw new \DomainException('cycle_price_out_of_range');
        return $units * 10000;
    }

    public function terms(CloudPlan $plan): array
    {
        return array_map(fn ($cycle) => ['cycle' => $cycle, 'months' => Service::monthsIn($cycle),
            'label' => Service::labelFor($cycle), 'currency' => 'IRT',
            'amount' => (string) $this->quote($plan, $cycle)], $this->cycles());
    }
}
