<?php

namespace App\Services;

class TransactionalEmailQuote
{
    public function usdCents(int $volume): int
    {
        $volume = max(1000, min((int) config('transactional_email.max_volume'), $volume));
        $plans = config('transactional_email.plans');
        foreach ($plans as $plan) {
            if ($volume <= $plan['volume']) {
                return $plan['usd_cents'];
            }
        }
        $last = end($plans);
        return $last['usd_cents'] + intdiv($volume - $last['volume'] + 999, 1000)
            * (int) config('transactional_email.extra_thousand_usd_cents');
    }

    /** Integer arithmetic, rounded up; no FX network calls on a page request. */
    public function displayAmount(int $usdCents, string $currency, ?int $usdRate, ?int $eurRate): int
    {
        return match ($currency) {
            'IRT' => intdiv($usdCents * $usdRate + 99_999, 100_000) * 1000,
            'EUR' => intdiv($usdCents * $usdRate + $eurRate - 1, $eurRate),
            default => $usdCents,
        };
    }
}
