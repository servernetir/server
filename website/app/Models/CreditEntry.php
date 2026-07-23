<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک سطر دفتر اعتبار. موجودی جمعِ سطرهاست، نه یک ستون قابل‌تغییر.
 */
class CreditEntry extends Model
{
    protected $table = 'credit_ledger';

    protected $fillable = [
        'customer_id', 'currency_code', 'amount', 'balance_after',
        'reason', 'source_type', 'source_id', 'note',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
