<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ارز — تفسیر مبالغ BIGINT.
 * IRT exponent 0 (تومان، نه ریال) · EUR exponent 2
 */
class Currency extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['code', 'exponent', 'rounding_step', 'symbol', 'symbol_before', 'is_base', 'is_active'];

    protected function casts(): array
    {
        return ['symbol_before' => 'boolean', 'is_base' => 'boolean', 'is_active' => 'boolean'];
    }

    /** واحد فرعی به عدد قابل نمایش: 490000 با exponent 0 → 490000 */
    public function toDecimal(int $minor): string
    {
        return $this->exponent === 0
            ? (string) $minor
            : bcdiv((string) $minor, bcpow('10', (string) $this->exponent), $this->exponent);
    }

    /** گرد کردن به rounding_step — قیمت‌های محاسبه‌شده باید تمیز باشند */
    public function round(int $minor): int
    {
        $step = max(1, (int) $this->rounding_step);

        return (int) (round($minor / $step) * $step);
    }
}
