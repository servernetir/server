<?php

namespace App\Models;

use App\Services\Finance\BusinessLedger;
use App\Support\ErrorTracker;
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

    protected static function booted(): void
    {
        static::created(function (CreditEntry $entry) {
            if ($entry->amount <= 0 || ! in_array($entry->reason, BusinessLedger::CREDIT_REFUND_REASONS, true)) {
                return;
            }
            try {
                app(BusinessLedger::class)->recordCreditSpend($entry);
            } catch (\Throwable $e) {
                ErrorTracker::note('finance', $e, ['area' => 'wallet-refund-report', 'entry' => $entry->id]);
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
