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
        'ledger_entry_id', 'pricing_version', 'ai_project_id', 'customer_api_token_id', 'charged_irt',
    ];

    /** رزروِ قدیمی (میکرو ثبت‌شده به‌جای تومان، باگِ B1) — هرگز تسویه نمی‌شود، فقط منقضی */
    public const PRICING_LEGACY = 0;

    /** رزروِ M5 — تومانِ واقعی، بی‌مهلت، با ردیفِ ai_usage */
    public const PRICING_M5 = 1;

    protected function casts(): array
    {
        return [
            'amount_irt'    => 'integer',
            'charged_irt'   => 'integer',
            'pricing_version' => 'integer',
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

    /**
     * رزروهای نگه‌دارندهٔ پول (m5-spec §2.9) — تنها ورودیِ `Wallet::reservedOf`.
     *
     * رزروِ M5 بی‌مهلت (`expires_at = NULL`) است و با `decide_by` ِ ردیفِ مصرف
     * تصمیم می‌گیرد، نه با ساعت؛ تا تصمیم پولش نگه داشته می‌شود تا هیچ مسیرِ دیگری
     * آن را خرج نکند و تسویه هرگز «منقضی» رد نشود. ولی **نه برای همیشه**: اگر
     * آشتی‌دهنده ۲۴ ساعت نمرده باشد هرگز به این پشتوانه نمی‌رسیم، و اگر مرده باشد
     * آزادکردنِ پولِ مشتری درست‌ترین شکستِ ممکن است. هشدارِ ردیفِ گیرکرده ۲۳
     * ساعت زودتر رفته (`ai:reconcile`).
     */
    public function scopeHolding(Builder $q): Builder
    {
        $backstop = now()->subHours((int) config('ai.hold_backstop_h', 24));

        return $q->where('status', self::STATUS_PENDING)
            ->where(fn ($w) => $w
                ->where(fn ($n) => $n->whereNull('expires_at')->where('created_at', '>', $backstop))
                ->orWhere('expires_at', '>', now()));
    }
}
