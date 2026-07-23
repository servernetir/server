<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حساب بانکی تأییدشده.
 *
 * شمارهٔ کارت کامل ذخیره نمی‌شود — فقط BIN و چهار رقم آخر برای نمایش.
 * آنچه واقعاً نگه می‌داریم شبا و شماره حساب است.
 */
class BankAccount extends Model
{
    protected $fillable = [
        'customer_id', 'card_bin', 'card_last4', 'bank_name', 'account_number',
        'iban', 'owner_name', 'name_matched', 'status', 'reject_reason',
        'is_default', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'name_matched' => 'boolean',
            'is_default'   => 'boolean',
            'verified_at'  => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    /** ۶۰۳۷۹۹ **** **** ۱۲۳۴ */
    public function maskedCard(): string
    {
        return $this->card_bin.' **** **** '.$this->card_last4;
    }

    /** شبا با فاصله برای خوانایی */
    public function formattedIban(): ?string
    {
        return $this->iban ? trim(chunk_split($this->iban, 4, ' ')) : null;
    }
}
