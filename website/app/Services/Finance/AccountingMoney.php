<?php

namespace App\Services\Finance;

use Illuminate\Validation\ValidationException;

final class AccountingMoney
{
    public const EXPONENTS = ['IRT' => 0, 'IRR' => 0, 'USD' => 2, 'EUR' => 2, 'TRY' => 2, 'USDT' => 6, 'GBP' => 2];

    public const MAX = 1_000_000_000_000_000;

    public static function minor(string|int $value, string $currency, string $field = 'amount'): int
    {
        $value = strtr(trim((string) $value), array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $exp = self::EXPONENTS[$currency] ?? null;
        if ($exp === null || ! preg_match('/^\d+(?:\.\d{1,'.max(1, $exp).'})?$/D', $value) || ($exp === 0 && str_contains($value, '.'))) {
            throw ValidationException::withMessages([$field => __('finance.invalid_amount')]);
        }
        $minor = bcmul($value, bcpow('10', (string) $exp, 0), 0);
        if (bccomp($minor, (string) self::MAX) > 0) {
            throw ValidationException::withMessages([$field => __('finance.invalid_amount')]);
        }

        return (int) $minor;
    }

    public static function display(int $minor, string $currency): string
    {
        $exp = self::EXPONENTS[$currency] ?? 0;
        $value = $exp ? bcdiv((string) $minor, bcpow('10', (string) $exp, 0), $exp) : (string) $minor;

        return $value.' '.$currency;
    }

    public static function percent(int $amount, int $bp): int
    {
        return (int) bcdiv(bcmul((string) $amount, (string) $bp, 0), '10000', 0);
    }
}
