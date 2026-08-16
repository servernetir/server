<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * «کدام لایه‌های تقویم را می‌بینم» — ترجیحِ شخصیِ هر کاربرِ پنل.
 */
class CalendarLayerPreference extends Model
{
    protected $fillable = ['user_id', 'layer_type', 'visible'];

    protected function casts(): array
    {
        return ['visible' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ترجیحِ کاربر برای **همهٔ** لایه‌ها، با پیش‌فرضِ «دیده شود».
     *
     * ⚠️ خروجی همیشه هر لایهٔ config را دارد، حتی اگر ردیفی ذخیره نشده باشد.
     * بی‌این، لایه‌ای که بعداً به config اضافه شود برای کاربرانِ قدیمی
     * **کلید نداشت** و جاوااسکریپت آن را «خاموش» می‌خواند — یعنی قابلیتِ تازه
     * برای همه نامرئی می‌شد، بی‌هیچ خطایی.
     *
     * روی سروری که هنوز مهاجرت نخورده، `hasTable` جلوی ۵۰۰ شدنِ کلِ صفحه را
     * می‌گیرد و همه‌چیز روشن برمی‌گردد — همان الگوی نگهبانِ `admin/layout`.
     *
     * @return array<string,bool>
     */
    public static function forUser(?int $userId): array
    {
        /*
         * ⚠️ پیش‌فرضِ هر لایه از config می‌آید، نه «همه روشن».
         *
         * تقریباً همهٔ لایه‌ها باید پیش‌فرض روشن باشند و هستند؛ ولی
         * `social_post` عمداً خاموش است چون روی دادهٔ واقعی ۹۷٪ رویدادهای ماه
         * را می‌سازد و بقیه را خفه می‌کند (توضیحِ کامل در `config/calendar.php`).
         * نوشتنِ `true` ثابت این‌جا یعنی آن تصمیم بی‌صدا نادیده گرفته شود.
         */
        $defaults = [];

        foreach ((array) config('calendar.layers', []) as $key => $meta) {
            $defaults[$key] = (bool) ($meta['default'] ?? true);
        }

        if ($userId === null || ! Schema::hasTable('calendar_layer_preferences')) {
            return $defaults;
        }

        $saved = static::query()
            ->where('user_id', $userId)
            ->pluck('visible', 'layer_type')
            ->all();

        foreach ($saved as $layer => $visible) {
            // لایه‌ای که از config حذف شده نباید دوباره زنده شود
            if (array_key_exists($layer, $defaults)) {
                $defaults[$layer] = (bool) $visible;
            }
        }

        return $defaults;
    }

    /**
     * ذخیرهٔ ترجیح. فقط لایه‌های شناخته‌شده نوشته می‌شوند.
     *
     * @param  array<string,bool>  $layers
     * @return array<string,bool> وضعیتِ نهایی پس از ذخیره
     */
    public static function store(int $userId, array $layers): array
    {
        $known = array_keys((array) config('calendar.layers', []));

        foreach ($layers as $layer => $visible) {
            if (! in_array($layer, $known, true)) {
                continue;
            }

            static::updateOrCreate(
                ['user_id' => $userId, 'layer_type' => $layer],
                ['visible' => (bool) $visible],
            );
        }

        return static::forUser($userId);
    }
}
