<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک اعلانِ ارسال‌شده به مشتریان — ردِ ماندگارِ آنچه فرستاده شد.
 */
class Broadcast extends Model
{
    protected $fillable = [
        'audience', 'customer_id', 'title', 'body', 'recipients', 'sent_by',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** برچسب فارسیِ مخاطب */
    public function audienceLabel(): string
    {
        return match ($this->audience) {
            'all'      => 'همهٔ مشتریان',
            'verified' => 'مشتریان احرازشده',
            'active'   => 'مشتریان فعال',
            'one'      => 'یک مشتری',
            default    => $this->audience,
        };
    }
}
