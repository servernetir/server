<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransferReceipt extends Model
{
    protected $fillable = [
        'customer_id', 'invoice_id', 'amount', 'reference', 'paid_from',
        'note', 'status', 'reject_reason', 'reviewed_by', 'reviewed_at',
        'payment_account_id', 'sent_amount', 'sent_currency',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'sent_amount' => 'integer', 'reviewed_at' => 'datetime'];
    }

    /** مقصدِ ارزی/رمزارزی — برای رسیدهای ریالیِ قدیمی null است */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class, 'payment_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
