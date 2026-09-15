<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک نسخه از واحدِ قیمت — سطرِ **جدا‌نشدنی**ِ تغییرنا‌پذیر.
 *
 * 🔴 قیمت، ویرایش نمی‌شود؛ جایگزین می‌شود. `supersede()` (در
 * `App\Services\Ai\PriceBook`) همیشه سطرِ **تازه** می‌سازد و قدیمی را
 * فقط بسته می‌کند، تا هر نتیجهٔ مالیِ گذشته از همین سطرها دقیقاً
 * بازتولید شود. `active` + `version` جفت‌اند: نسخهٔ تازه فعال و
 * قبلی بسته، و هیچ دو نسخهٔ فعالِ هم‌زمان او پَی نمی‌شوند.
 */
class AiModelUnitPrice extends Model
{
    /** واحدهای مصرف — مقدارِ مصرف‌شده روی چه چیزی اندازه می‌گیرد */
    public const UNIT_INPUT = 'input';
    public const UNIT_OUTPUT = 'output';
    public const UNIT_CACHED_INPUT = 'cached_input';
    public const UNIT_REASONING = 'reasoning_input';
    public const UNIT_IMAGE = 'image';
    public const UNIT_AUDIO = 'audio';
    public const UNIT_REQUEST = 'request';
    public const UNIT_EMBEDDING = 'embedding';
    public const UNIT_RERANK = 'rerank';

    public const UNITS = [
        self::UNIT_INPUT, self::UNIT_OUTPUT, self::UNIT_CACHED_INPUT,
        self::UNIT_REASONING, self::UNIT_IMAGE, self::UNIT_AUDIO,
        self::UNIT_REQUEST, self::UNIT_EMBEDDING, self::UNIT_RERANK,
    ];

    /** یکایِ صورت‌حساب که قیمت به آن چشنده — اعلامِ صریحِ نه‌ه‌ا-توکتِ */
    public const BASIS_1M_TOKENS = '1m_tokens';
    public const BASIS_IMAGE = 'image';
    public const BASIS_AUDIO_MINUTE = 'audio_minute';
    public const BASIS_REQUEST = 'request';

    protected $fillable = [
        'ai_provider_id', 'ai_model_id', 'unit', 'billing_unit',
        'price_micro_units', 'currency_code', 'version', 'active',
        'effective_from', 'superseded_at', 'created_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'price_micro_units' => 'integer',
            'currency_code'   => 'string',
            'active'          => 'boolean',
            'effective_from'  => 'datetime',
            'superseded_at'   => 'datetime',
        ];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** سطری که رِزولوشنیِ تاریخِ گذشته باید از همین روست بازتولیدْ کند */
    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }
}
