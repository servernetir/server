<?php

namespace App\Services\Birthday;

use App\Models\BirthdayReward;
use App\Models\CloudPlan;
use App\Models\Service;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\ExchangeRate;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class BirthdayHourly
{
    /** Regular service rates/reserves stay unchanged. Only actual eligible usage seconds receive the gift. */
    public function rates(Service $service, Carbon $from, int $hours, int $regular): array
    {
        $hours = max(0, min(49, $hours));
        $rates = array_fill(0, $hours, $regular);
        if ($regular < 1 || ! $hours || ! Schema::hasTable('birthday_rewards') || ! $service->cloud_plan_id
            || array_filter((array) $service->cloud_addons)) {
            return $rates;
        }
        $rewards = BirthdayReward::where('customer_id', $service->customer_id)
            ->where('starts_at', '<', $from->copy()->addHours($hours))->where('expires_at', '>', $from)->orderBy('starts_at')->get();
        if ($rewards->isEmpty()) {
            return $rates;
        }
        $plan = CloudPlan::find($service->cloud_plan_id);
        if (! $plan || ! app(CloudPricingSnapshot::class)->current((string) $plan->provider)
            || ($plan->isTermBased() && (int) $plan->cost_hour_eur_micro < 1)) {
            return $rates;
        }
        $rate = app(ExchangeRate::class)->current('EUR')['rate_toman'] ?? null;
        $micro = $plan->hourlyCostEurMicro();
        if (! is_numeric($rate) || $rate < 1 || $rate > 5000000 || $micro < 1 || $micro > 1000000000000) {
            return $rates;
        }
        $cost = BigInteger::of($micro)->multipliedBy((int) $rate)->dividedBy(1000000, RoundingMode::Up)->toInt();
        if ($cost >= $regular) {
            return $rates;
        }
        $windows = [];
        foreach ($rewards as $reward) {
            $start = $reward->starts_at->getTimestamp();
            $end = min($reward->expires_at->getTimestamp(), $reward->revoked_at?->getTimestamp() ?? PHP_INT_MAX);
            if ($end <= $start) continue;
            $last = count($windows) - 1;
            if ($last >= 0 && $start <= $windows[$last][1]) $windows[$last][1] = max($windows[$last][1], $end);
            else $windows[] = [$start, $end];
        }
        for ($h = 0; $h < $hours; $h++) {
            $start = $from->getTimestamp() + $h * 3600;
            $seconds = 0;
            foreach ($windows as [$a, $b]) $seconds += max(0, min($start + 3600, $b) - max($start, $a));
            $seconds = min(3600, $seconds);
            $rates[$h] = BigInteger::of($regular)->multipliedBy(3600 - $seconds)
                ->plus(BigInteger::of($cost)->multipliedBy($seconds))->dividedBy(3600, RoundingMode::Up)->toInt();
        }

        return $rates;
    }

    public function affordable(Service $service, Carbon $from, int $maxHours, int $regular, int $available): array
    {
        if ($regular < 1) {
            return ['hours' => 0, 'amount' => 0, 'credit_bound' => true, 'discount' => 0];
        }
        $maxHours = max(0, min(48, $maxHours));
        $rates = $this->rates($service, $from, $maxHours + 1, $regular);
        $hours = 0; $amount = 0;
        for ($i = 0; $i < $maxHours; $i++) {
            if ($amount + $rates[$i] > max(0, $available)) break;
            $amount += $rates[$i];
            $hours++;
        }
        return ['hours' => $hours, 'amount' => $amount,
            'credit_bound' => $amount + ($rates[$hours] ?? $regular) > max(0, $available),
            'discount' => $regular * $hours - $amount];
    }
}
