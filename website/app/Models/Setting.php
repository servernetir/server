<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * تنظیم کلید-مقدار. get/set با کش سبک تا هر صفحه یک پرس‌وجوی جدا نزند.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (! Schema::hasTable('settings')) {
            return $default;
        }

        $all = Cache::remember('settings.all', 300, fn () => static::pluck('value', 'key')->toArray());

        return $all[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings.all');
    }

    /** آیا مشخصات بانکی برای «واریز به حساب» کامل است؟ */
    public static function bankReady(): bool
    {
        return filled(static::get('bank_sheba')) || filled(static::get('bank_account'));
    }
}
