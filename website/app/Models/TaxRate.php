<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * قاعدهٔ مالیات. ایران ۱۰٪ · خارج ۰٪ — مستقل از روش پرداخت.
 * نرخ در صدم درصد: ۱۰٪ = 1000
 */
class TaxRate extends Model
{
    protected $fillable = ['name', 'country', 'customer_type', 'product_kind', 'rate_bp', 'priority', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** خاص‌ترین قاعدهٔ منطبق را برمی‌گرداند */
    public static function resolve(?string $country, ?string $customerType = null, ?string $productKind = null): ?self
    {
        return static::where('is_active', true)
            ->where(fn ($q) => $q->where('country', $country)->orWhereNull('country'))
            ->where(fn ($q) => $q->where('customer_type', $customerType)->orWhereNull('customer_type'))
            ->where(fn ($q) => $q->where('product_kind', $productKind)->orWhereNull('product_kind'))
            // قاعدهٔ کشورِ مشخص بر قاعدهٔ عمومی مقدم است
            ->orderByRaw('CASE WHEN country IS NULL THEN 0 ELSE 1 END DESC')
            ->orderByDesc('priority')
            ->first();
    }

    /** مالیات یک مبلغ در واحد فرعی */
    public function taxOn(int $minor): int
    {
        return intdiv($minor * $this->rate_bp, 10000);
    }
}
