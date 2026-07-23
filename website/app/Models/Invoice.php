<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'customer_id', 'number', 'kind', 'currency_code',
        'subtotal', 'tax', 'total', 'paid', 'status', 'note',
        'issued_at', 'due_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal'  => 'integer',
            'tax'       => 'integer',
            'total'     => 'integer',
            'paid'      => 'integer',
            'issued_at' => 'datetime',
            'due_at'    => 'datetime',
            'paid_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $i) {
            if (blank($i->number)) {
                $i->number = self::nextNumber();
            }
        });
    }

    /**
     * شمارهٔ فاکتور: INV-<تاریخ>-<۴ رقم تصادفی>.
     *
     * تصادفی و نه پیاپی — شمارهٔ پیاپی به مشتری می‌گوید امروز چند فاکتور
     * صادر کرده‌ایم. برخورد با تلاش دوباره حل می‌شود، چون ستون unique است.
     */
    public static function nextNumber(): string
    {
        return 'INV-'.now()->format('ymd').'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['unpaid', 'draft'], true) && $this->due() > 0;
    }

    /** مانده — هرگز منفی */
    public function due(): int
    {
        return max(0, $this->total - $this->paid);
    }
}
