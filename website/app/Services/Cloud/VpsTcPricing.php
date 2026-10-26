<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use App\Services\ExchangeRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Account-specific procurement inputs; missing values never inherit another supplier's costs. */
class VpsTcPricing
{
    public const INPUT_KEYS = [
        'vpstc_currency', 'cloud_margin_pct_vpstc', 'vpstc_supplier_tax_pct',
        'pricing_fx_fee_pct_vpstc', 'vpstc_fx_rate_toman',
    ];

    public const SNAPSHOT_KEYS = [
        ...self::INPUT_KEYS,
        'cloud_vpstc_api_origin', 'cloud_vpstc_username', 'cloud_vpstc_password',
        'vpstc_catalog_manifest',
        VpsTcMoneyContract::KEY,
    ];

    public function values(): array
    {
        $values = [];
        foreach (self::INPUT_KEYS as $key) {
            $values[$key] = (string) Setting::get($key, '');
        }
        return $values;
    }

    public function configured(): bool
    {
        $currency = Setting::get('vpstc_currency', '');
        if (! in_array($currency, ['USD', 'EUR', 'USDT', 'TRY'], true)
            || $this->marginPct() === null || $this->taxPct() === null || $this->fxFeePct() === null) {
            return false;
        }
        $manual = Setting::get('vpstc_fx_rate_toman', '');
        if ($manual !== '') {
            return $this->number($manual, 0.000001, 1_000_000_000) !== null;
        }
        // TRY follows the same verified central Navasan snapshot shown in Settings.
        return $currency !== 'TRY' || app(ExchangeRate::class)->toToman('TRY') !== null;
    }

    public function marginPct(): ?float
    {
        return $this->number(Setting::get('cloud_margin_pct_vpstc'), 0, 500);
    }

    public function taxPct(): ?float
    {
        return $this->number(Setting::get('vpstc_supplier_tax_pct'), 0, 100);
    }

    public function fxFeePct(): ?float
    {
        return $this->number(Setting::get('pricing_fx_fee_pct_vpstc'), 0, 100);
    }

    /** Native major currency units to EUR cents, including supplier tax and funding costs once. */
    public function costEurCents(mixed $nativeAmount, string $currency, bool $allowZero = false): ?int
    {
        if (! $this->configured() || $currency !== Setting::get('vpstc_currency')) {
            return null;
        }
        $amount = $this->number($nativeAmount, $allowZero ? 0 : 0.000001, 1_000_000_000);
        if ($amount === null) {
            return null;
        }
        $fx = app(ExchangeRate::class);
        $eur = $fx->toToman('EUR');
        $manual = Setting::get('vpstc_fx_rate_toman', '');
        $native = $manual !== '' ? $manual : $fx->toToman($currency);
        if (! is_numeric($eur) || ! is_finite((float) $eur) || $eur <= 0
            || ! is_numeric($native) || ! is_finite((float) $native) || $native <= 0) {
            return null;
        }
        try {
            // Exact decimal products, with one upward rounding at the EUR-cent boundary.
            return BigDecimal::of((string) $nativeAmount)->multipliedBy((string) $native)
                ->multipliedBy(BigDecimal::of(Setting::get('vpstc_supplier_tax_pct'))->plus(100))
                ->multipliedBy(BigDecimal::of(Setting::get('pricing_fx_fee_pct_vpstc'))->plus(100))
                ->dividedBy(BigDecimal::of((string) $eur)->multipliedBy(100), 0, RoundingMode::Ceiling)
                ->toInt();
        } catch (\Throwable) {
            return null;
        }
    }

    public function sellEurCents(int $costEurCents): int
    {
        if (! $this->configured() || $costEurCents <= 0) {
            return 0;
        }
        try {
            return BigDecimal::of($costEurCents)
                ->multipliedBy(BigDecimal::of(Setting::get('cloud_margin_pct_vpstc'))->plus(100))
                ->dividedBy(1000, 0, RoundingMode::Ceiling)->multipliedBy(10)->toInt();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Only a digest is persisted in the public-price snapshot, never credential values. */
    public function fingerprint(): string
    {
        $values = [];
        foreach (self::SNAPSHOT_KEYS as $key) {
            $values[$key] = Setting::get($key);
        }
        if (Setting::get('vpstc_currency') === 'TRY' && Setting::get('vpstc_fx_rate_toman', '') === '') {
            $row = app(ExchangeRate::class)->current('TRY');
            // The global cloud signature keeps its original three currencies;
            // a TRY-only change/expiry invalidates this supplier independently.
            $central = null;
            if (is_array($row)) {
                $central = [];
                foreach (['currency', 'rate_toman', 'unit', 'source', 'source_item', 'source_at', 'at'] as $key) {
                    $central[$key] = $row[$key] ?? null;
                }
            }
            $values['central_try'] = $central;
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function number(mixed $value, float $min, float $max): ?float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }
        $number = (float) $value;
        return $number >= $min && $number <= $max ? $number : null;
    }
}
