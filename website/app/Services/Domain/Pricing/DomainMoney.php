<?php

namespace App\Services\Domain\Pricing;

/** Integer minor-unit arithmetic. Every procurement/selling round is upwards. */
final class DomainMoney
{
    public static function multiply(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || ($a !== 0 && $b > intdiv(PHP_INT_MAX, $a))) {
            throw new DomainProcurementException('money_overflow');
        }
        return $a * $b;
    }

    public static function ceilRatio(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator < 1) {
            throw new DomainProcurementException('invalid_money');
        }
        return intdiv($numerator, $denominator) + ($numerator % $denominator === 0 ? 0 : 1);
    }

    public static function roundUp(int $amount, int $step): int
    {
        return self::multiply(self::ceilRatio($amount, $step), $step);
    }

    /** One unit = 0.0001 percent; retain configured precision without float math. */
    public static function percentUnits(mixed $percentage): int
    {
        $value = is_float($percentage) ? rtrim(rtrim(sprintf('%.4F', $percentage), '0'), '.') : (string) $percentage;
        if (! preg_match('/\A([0-9]{1,4})(?:\.([0-9]{1,4}))?\z/D', $value, $matches)) {
            throw new DomainProcurementException('invalid_margin');
        }
        return (int) $matches[1] * 10000 + (int) str_pad($matches[2] ?? '', 4, '0');
    }

    public static function addPercent(int $amount, int $units): int
    {
        return self::ceilRatio(self::multiply($amount, 1000000 + $units), 1000000);
    }
}
