<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مدلِ AI — یک ردیف = «یک مدلِ نزدِ یک ارائه‌دهنده».
 *
 * مثلِ `cloud_plans`: ردیف‌های هم‌مشخصات نزد ارائه‌دهنده‌های مختلف عمداً
 * اسلاگِ متفاوتِ خودشان را دارند و رِوتینگِ M4 در لحظهٔ ارسال *بهترین*
 * منبع را انتخاب می‌کند. اسلاگ این‌جا سراسری‌یکتا است، چون در فاز
 * «فهرستِ ورودیِ مشتری» — تنها مسیری که مشتری می‌سازد — کلیدِ دستِ
 * ارائه‌دهنده نیست و مدلِ عمومی فهرستِ `/v1/models` همین‌جا می‌شناسد.
 *
 * 🔴 قیمت‌ها این‌جا ذخیره نمی‌شوند، لینک می‌شوند — `AiModelUnitPrice`
 * هم برای قیمتِ مدل و هم برای پیش‌فرضِ ارائه‌دهنده/سراسری
 * *جایگزین‌-لینک* دارند.
 */
class AiModel extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_DEPRECATED = 'deprecated';

    public const CATEGORY_CHAT = 'chat';
    public const CATEGORY_EMBEDDING = 'embedding';
    public const CATEGORY_IMAGE = 'image';
    public const CATEGORY_AUDIO = 'audio';
    public const CATEGORY_RERANK = 'rerank';

    public const CATEGORIES = [
        self::CATEGORY_CHAT, self::CATEGORY_EMBEDDING, self::CATEGORY_IMAGE,
        self::CATEGORY_AUDIO, self::CATEGORY_RERANK,
    ];

    public const STATUSES = [
        self::STATUS_ACTIVE, self::STATUS_DISABLED, self::STATUS_DEPRECATED,
    ];

    protected $fillable = [
        'ai_provider_id', 'slug', 'upstream_model', 'name', 'vendor',
        'description', 'category', 'status', 'replacement_model_id',
        'context_tokens', 'max_output_tokens', 'capabilities',
        'provider_priority', 'docs_url', 'claude_code_compatible', 'margin_bp',
    ];

    protected function casts(): array
    {
        return [
            'capabilities'           => 'array',
            'claude_code_compatible' => 'boolean',
            'margin_bp'              => 'integer',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_model_id');
    }

    public function unitPrices(): HasMany
    {
        return $this->hasMany(AiModelUnitPrice::class);
    }

    public function activeUnitPrices(): HasMany
    {
        return $this->unitPrices()->where('active', true);
    }

    /**
     * در هش‌های رزو‌مشری: فعالِ فنی + فروش روشنِ ارائه‌دهنده + استاندِرِ فوق
     * استاندِ فروش — رِبرند‌ز به فیلترهای تقاضا.
     */
    public function isSellable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->provider !== null
            && $this->provider->isCommerciallySellable();
    }

    public function capability(string $key): bool
    {
        return (bool) ($this->capabilities[$key] ?? false);
    }

    /** قیمتِ واحدِ فعال برای این مدل — آخرین نسخهٔ مؤثر بدونِ سرویسِ خارجی */
    public function activePrice(string $unit): ?AiModelUnitPrice
    {
        return $this->activeUnitPrices()->where('unit', $unit)->first();
    }
}
