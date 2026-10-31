<?php

namespace App\Services\Domain;

use App\Models\Domain;

/** Live renewal floor and recorded supplier cost for accounting. */
class DomainCostFloor
{
    public function __construct(private DomainSearch $search) {}

    /** Missing current supplier data blocks pricing. */
    public function renewPerYear(Domain $domain): int
    {
        return app(LiveDomainPricing::class)->quote($domain->sld, $domain->tld, 'renew', 1, $domain)['floor_toman'];
    }

    /**
     * بهای تمام‌شدهٔ یک رویدادِ رجیسترار به تومان — برای ثبتِ هزینه در دفتر.
     *
     * • register: بهای سالِ اول + بهای تمدید × (سال‌های اضافه). تا امروز
     *   گزارش‌ها فقط بهای سالِ اول را می‌دیدند و سودِ خریدِ چندساله
     *   بیش‌نمایی می‌شد.
     * • renew: بهای تمدید × سال.
     * • transfer: بهای ذخیره‌شده (شاملِ یک سال تمدید است).
     *
     * ⚠️ صفر یعنی «نمی‌دانیم» — داده یا نرخ نیست؛ فراخوان نباید عددِ ساختگی
     * ثبت کند.
     */
    public function wholesaleToman(Domain $domain, string $stage, int $years = 1): int
    {
        $live = (array) data_get($domain->meta, 'live_price', []);
        $operation = $stage === 'register' ? 'create' : $stage;
        if (($live['operation'] ?? null) === $operation && (int) ($live['years'] ?? 0) === $years) {
            return (int) ($live['wholesale_toman'] ?? 0);
        }
        $currency = (string) $domain->cost_currency;
        $create = (int) $domain->cost_amount;
        $renewC = (int) ($domain->cost_renew_amount ?: $domain->cost_amount);
        $years = max(1, $years);

        $minor = match ($stage) {
            'renew' => $renewC * $years,
            'transfer' => $create,
            default => $create + $renewC * ($years - 1),
        };

        if ($minor <= 0 || $currency === '') {
            return 0;
        }

        $rate = $this->search->rateFor($currency);

        if ($rate === null || $rate <= 0) {
            return 0;
        }

        return (int) round(($minor / 100) * $rate);
    }
}
