<?php

namespace App\Services\Finance;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Dated investment cash flows in one currency; analytical floating point is never posted as money. */
final class InvestmentAnalysis
{
    public function calculate(array $flows, float $annualDiscountRate): array
    {
        if (! is_finite($annualDiscountRate) || $annualDiscountRate <= -1 || $annualDiscountRate > 100) {
            throw ValidationException::withMessages(['discount_rate' => __('finance.invalid_rate')]);
        }
        if (count($flows) < 2 || count($flows) > 500) {
            throw ValidationException::withMessages(['cash_flows' => __('finance.invalid_flows')]);
        }
        $byDate = [];
        foreach ($flows as $f) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $f['date']) || ! Carbon::canBeCreatedFromFormat($f['date'], 'Y-m-d') || Carbon::createFromFormat('!Y-m-d', $f['date'])->format('Y-m-d') !== $f['date'] || ! is_int($f['amount']) || abs($f['amount']) > AccountingMoney::MAX) {
                throw ValidationException::withMessages(['cash_flows' => __('finance.invalid_flows')]);
            }
            $byDate[$f['date']] = ($byDate[$f['date']] ?? 0) + $f['amount'];
        }
        ksort($byDate);
        $origin = Carbon::parse(array_key_first($byDate), 'UTC');
        $points = [];
        $invested = 0;
        $returns = 0;
        foreach ($byDate as $date => $amount) {
            if ($amount === 0) {
                continue;
            }
            $points[] = [(float) $amount, $origin->diffInDays(Carbon::parse($date, 'UTC')) / 365.0];
            if ($amount < 0) {
                $invested -= $amount;
            } else {
                $returns += $amount;
            }
        }
        $npv = function (float $r) use ($points): float {
            $sum = 0.0;
            foreach ($points as [$amount,$years]) {
                $denominator = pow(1 + $r, $years);
                if ($denominator === 0.0) {
                    return $amount > 0 ? INF : -INF;
                }
                $sum += $amount / $denominator;
            }

            return $sum;
        };
        $value = $npv($annualDiscountRate);
        $irr = null;
        $signs = [];
        foreach ($points as [$amount]) {
            $sign = $amount > 0 ? 1 : -1;
            if (! $signs || end($signs) !== $sign) {
                $signs[] = $sign;
            }
        }
        // Nonconventional flows may have several or no roots. Do not invent a unique IRR.
        if (count($signs) === 2 && $signs[0] === -1 && count($points) > 1 && end($points)[1] > 0) {
            $lo = -0.999999;
            $hi = 1.0;
            for ($i = 0; $i < 24 && $npv($hi) > 0; $i++) {
                $hi = $hi * 2 + 1;
            }
            if ($npv($lo) > 0 && $npv($hi) < 0) {
                for ($i = 0; $i < 200; $i++) {
                    $mid = ($lo + $hi) / 2;
                    if ($npv($mid) > 0) {
                        $lo = $mid;
                    } else {
                        $hi = $mid;
                    }
                }
                $irr = ($lo + $hi) / 2 * 100;
            }
        }

        return ['npv' => is_finite($value) ? round($value, 2) : null, 'irr_percent' => $irr === null ? null : round($irr, 6),
            'roi_percent' => $invested > 0 ? round(($returns - $invested) / $invested * 100, 4) : null,
            'invested' => $invested, 'returns' => $returns, 'net' => $returns - $invested, 'day_basis' => 365,
            'irr_status' => $irr === null ? 'undefined_or_nonconventional' : 'unique_conventional'];
    }
}
