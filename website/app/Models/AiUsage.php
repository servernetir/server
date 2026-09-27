<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * یک ردیف به ازای هر تلاشِ /v1 که از سدها گذشت — مصرف، قیمتِ منجمد و پول (m5-spec §2.1).
 *
 * ═══ ماشینِ حالت ═══
 *
 *   reserved ─▶ sending ─▶ settle_pending ─▶ settled
 *      │           │                           ▲
 *      │           └─▶ unknown_pending ─▶ unknown_charged
 *      └─▶ released                          (سقفِ رزرو، بازپرداختِ خودکار تا ۷ روز)
 *
 * هر گذار یک UPDATE ِ شرطی روی وضعیتِ قبلی است؛ پس درخواست و آشتی‌دهنده
 * (`ai:reconcile`) هرگز یک ردیف را دو بار تسویه نمی‌کنند.
 *
 * همهٔ ورودی‌های قیمت روی ردیف منجمدند (`quote()`) تا `ai:explain` هر شارژ را
 * بیت‌به‌بیت از روی خودش بازسازی کند.
 */
class AiUsage extends Model
{
    protected $table = 'ai_usage';

    public const STATUS_RESERVED = 'reserved';
    public const STATUS_SENDING = 'sending';
    public const STATUS_STREAMING = 'streaming';
    public const STATUS_SETTLE_PENDING = 'settle_pending';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_UNKNOWN_PENDING = 'unknown_pending';
    public const STATUS_UNKNOWN_CHARGED = 'unknown_charged';
    public const STATUS_RELEASED = 'released';

    /** حالت‌هایی که هنوز تصمیمِ پولی نگرفته‌اند */
    public const OPEN = [
        self::STATUS_RESERVED, self::STATUS_SENDING, self::STATUS_STREAMING,
        self::STATUS_SETTLE_PENDING, self::STATUS_UNKNOWN_PENDING,
    ];

    /** حالت‌هایی که پول برداشته‌اند */
    public const CHARGED = [self::STATUS_SETTLED, self::STATUS_UNKNOWN_CHARGED];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stream'       => 'boolean',
            'needs_review' => 'boolean',
            'fx_at'        => 'datetime',
            'decide_by'    => 'datetime',
            'sent_at'      => 'datetime',
            'settled_at'   => 'datetime',
            'day'          => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiUsage $u) {
            $u->public_id ??= strtolower((string) Str::ulid());
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(AiReservation::class, 'ai_reservation_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /** قیمتِ منجمدِ همین ردیف — همان ورودیِ `AiPricing::charge` در لحظهٔ رزرو */
    public function quote(): \App\Services\Ai\AiPriceQuote
    {
        return new \App\Services\Ai\AiPriceQuote(
            modelId: (int) $this->ai_model_id,
            providerId: (int) $this->ai_provider_id,
            currency: (string) $this->price_currency,
            fx: new \App\Services\Ai\AiFxQuote((string) $this->price_currency, (int) $this->fx_rate_toman,
                (string) $this->fx_source, $this->fx_at?->toIso8601String()),
            feeBp: (int) $this->fee_bp,
            marginBp: (int) $this->margin_bp,
            marginSource: 'snapshot',
            rIn: (int) $this->input_rate_micro,
            rCached: (int) ($this->cached_rate_micro ?? $this->input_rate_micro),
            rOut: (int) $this->output_rate_micro,
            inputPriceId: (int) $this->input_price_id,
            cachedPriceId: $this->cached_price_id === null ? null : (int) $this->cached_price_id,
            outputPriceId: (int) $this->output_price_id,
            pIn: (int) $this->p_input_irt_m,
            pCached: (int) $this->p_cached_irt_m,
            pOut: (int) $this->p_output_irt_m,
        );
    }
}
