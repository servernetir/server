<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ارائه‌دهندهٔ AI — ردیفِ رجیستری، نه درایور.
 *
 * درایورِ واقعی در `App\Services\AiProviders\` می‌نشیند و `driver` فقط
 * شناسه‌اش را نگه می‌دارد؛ رجیستری هرگز با دیتابِیس کلاس را ساخته نمی‌کند.
 *
 * 🔴 اعتبارنامه‌ها هرگز این‌جا نیستند و نباید باشند — قراردادِ ServerNet
 * برای رمزِ سرویس، `Setting::putSecret/getSecret` است (همان الگوی
 * `salad_api_key` در ارائه‌دهنده‌های ابری).
 */
class AiProvider extends Model
{
    public const STATUS_NONE = 'none';
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_SIGNED = 'signed';

    protected $fillable = [
        'slug', 'name', 'driver', 'enabled', 'commercial_enabled',
        'resale_allowed', 'agreement_status', 'priority',
        'live_calls_enabled', 'billing_currency_code', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'enabled'            => 'boolean',
            'commercial_enabled' => 'boolean',
            'resale_allowed'     => 'boolean',
            'live_calls_enabled' => 'boolean',
            'billing_currency_code' => 'string',
        ];
    }

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    public function unitPrices(): HasMany
    {
        return $this->hasMany(AiModelUnitPrice::class);
    }

    /** فنی فعال و دِسی‌پلین روشن — اولین گیتِ route و M4 */
    public function isLiveCapable(): bool
    {
        return $this->enabled && $this->live_calls_enabled;
    }

    /* فروش برای مشتری مجاز — M2 مدل‌هایش را از همین شرط فیلتر می‌کند */
    public function isCommerciallySellable(): bool
    {
        return $this->enabled && $this->commercial_enabled;
    }

    public function isAgreedTo(): bool
    {
        return $this->agreement_status === self::STATUS_SIGNED;
    }

    /** کلیدِ API از یادیدِ رمزِ سرورنت — هرگز دیتابیسِ این جدول */
    public function apiKey(): ?string
    {
        return Setting::getSecret('ai_provider_'.$this->slug.'_key');
    }

    public static function bySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }
}
