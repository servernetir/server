<?php

namespace App\Services\Domain\Providers;

/** Decimal boundary conversions; round half up exactly once to currency units. */
final class ProviderMoney
{
    public static function exponent(string $currency): int
    {
        return match (strtoupper($currency)) {
            'USD', 'EUR', 'GBP', 'TRY', 'AED', 'CAD', 'AUD', 'CHF', 'SEK', 'NOK', 'DKK', 'CNY', 'INR', 'MXN', 'PLN' => 2,
            'IRT', 'IRR', 'JPY', 'KRW' => 0,
            'KWD', 'BHD', 'OMR' => 3,
            default => throw new DomainProviderException('unsupported_currency'),
        };
    }

    public static function minor(mixed $amount, string $currency): int
    {
        $value = (string) $amount;
        if (! preg_match('/^(\d{1,13})(?:\.(\d{1,12}))?$/D', $value, $m)) {
            throw new DomainProviderException('invalid_supplier_amount');
        }
        $exponent = self::exponent($currency);
        $fraction = str_pad($m[2] ?? '', $exponent + 1, '0');
        $minor = (int) ($m[1].substr($fraction, 0, $exponent));
        return $minor + ((int) $fraction[$exponent] >= 5 ? 1 : 0);
    }

    public static function decimal(int $minor, string $currency): string
    {
        if ($minor < 0) { throw new DomainProviderException('invalid_supplier_amount'); }
        $exponent = self::exponent($currency);
        if ($exponent === 0) { return (string) $minor; }
        $digits = str_pad((string) $minor, $exponent + 1, '0', STR_PAD_LEFT);
        return substr($digits, 0, -$exponent).'.'.substr($digits, -$exponent);
    }

    public static function basisPoints(mixed $percentage): int
    {
        $bps = self::minor($percentage, 'USD');
        if ($bps > 10000) { throw new DomainProviderException('invalid_supplier_tax'); }
        return $bps;
    }

    /** Read a configured minor-unit fee without silently truncating a decimal. */
    public static function integerMinor(mixed $amount): int
    {
        if (! is_int($amount) && (! is_string($amount) || ! preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $amount))) {
            throw new DomainProviderException('invalid_supplier_amount');
        }
        $value = filter_var($amount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($value === false) { throw new DomainProviderException('invalid_supplier_amount'); }
        return $value;
    }

    public static function add(int ...$amounts): int
    {
        $sum = 0;
        foreach ($amounts as $amount) {
            if ($amount < 0 || $amount > PHP_INT_MAX - $sum) { throw new DomainProviderException('invalid_supplier_amount'); }
            $sum += $amount;
        }
        return $sum;
    }

    /** Mandatory cost adjustments round upwards, so procurement is never understated. */
    public static function surcharge(int $minor, int $bps): int
    {
        if ($minor < 0 || $bps < 0 || $bps > 10000 || $minor > intdiv(PHP_INT_MAX - 9999, max(1, $bps))) {
            throw new DomainProviderException('invalid_supplier_amount');
        }
        return intdiv($minor * $bps + 9999, 10000);
    }

    public static function months(int $months): int
    {
        if ($months < 12 || $months > 120 || $months % 12 !== 0) {
            throw new DomainProviderException('unsupported_period');
        }
        return $months;
    }

    public static function fqdn(string $fqdn): string
    {
        $ascii = idn_to_ascii(strtolower(rtrim(trim($fqdn), '.')), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (! is_string($ascii) || strlen($ascii) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $ascii)) {
            throw new DomainProviderException('invalid_domain');
        }
        return $ascii;
    }
}
