<?php

namespace App\Services;

use App\Models\Setting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** Convert native product amounts with the validated, stored Navasan EUR rate. */
class ProductPricing
{
    public function rate(): ?int
    {
        $rate = app(ExchangeRate::class)->current('EUR')['rate_toman'] ?? null;

        return is_numeric($rate) && $rate >= 20_000 && $rate <= 5_000_000 ? (int) $rate : null;
    }

    public function available(): bool
    {
        return $this->rate() !== null;
    }

    public function assertAvailable(): void
    {
        if (! $this->available()) {
            throw ValidationException::withMessages(['product' => __('ui.cvb_e_price')]);
        }
    }

    /** Missing or invalid FX is unavailable; callers must never sell the zero result. */
    public function toman(float|string $eur): int
    {
        $rate = $this->rate();
        if ($rate === null || ! is_numeric($eur) || ! is_finite((float) $eur) || (float) $eur <= 0) {
            return 0;
        }

        $margin = Setting::get('price_margin_pct', '0');
        if (! is_numeric($margin) || ! is_finite((float) $margin) || $margin < -50 || $margin > 500) {
            return 0;
        }

        $amount = BigDecimal::of((string) $eur)->multipliedBy($rate)
            ->multipliedBy(BigDecimal::of((string) $margin)->plus(100))
            ->dividedBy(100, 16, RoundingMode::HalfUp);
        $rounded = $amount->dividedBy(10_000, 0, RoundingMode::HalfUp)->multipliedBy(10_000)->toInt();

        return $rounded > 0 ? $rounded : max(1, $amount->toScale(0, RoundingMode::Ceiling)->toInt());
    }
}
