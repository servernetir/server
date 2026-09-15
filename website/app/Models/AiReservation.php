<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رزروِ اعتبار برای یک درخواستِ AI — «نگه‌داشته»، نه پول.
 *
 * موجودیِ در دسترس = جمعِ دفتر − جمعِ رزروهایِ pendingِ زنده. خودِ رزرو
 * هیچ سطری در دفتر نمی‌نویسد؛ فقط تسویه است که یک سطرِ منفی می‌نویسد و
 * `ledger_entry_id` ردش را همین‌جا نگه می‌دارد (درزِ ممیزیِ M5).
 */
class AiReservation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_RELEASED = 'released';
    public const STATUS_EXPIRED = 'expired';

    /** حالت‌های پایانی — از این پس هیچ گذاری برنمی‌گردد */
    public const STATUS_TERMINAL = [self::STATUS_SETTLED, self::STATUS_RELEASED, self::STATUS_EXPIRED];

    protected $fillable = [
        'customer_id', 'currency_code', 'amount_irt', 'status',
        'idempotency_key', 'purpose', 'reference',
        'expires_at', 'settled_at', 'released_at', 'expired_at',
        'ledger_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_irt'    => 'integer',
            'expires_at'    => 'datetime',
            'settled_at'    => 'datetime',
            'released_at'   => 'datetime',
            'expired_at'    => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** سطرِ دفتری که تسویه نوشت — درزِ ممیزی */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(CreditEntry::class, 'ledger_entry_id');
    }

    /** رزروهای نگه‌دارندهٔ پول: pending و هنوز مهلت‌دار (نال = بی‌مهلت) */
    public function scopeHolding(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING)
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
