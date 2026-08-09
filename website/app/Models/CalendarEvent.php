<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * رویدادِ دستیِ تقویمِ کسب‌وکار — چیزی که مدیر خودش نوشته.
 *
 * رویدادهای خودکار (تمدید، فاکتور، انتشار) ردیفی در این جدول **ندارند**؛
 * دلیلش در مهاجرت توضیح داده شده. نتیجهٔ عملی و مهمِ این تصمیم:
 * «فقط رویدادِ دستی حذف می‌شود» خودبه‌خود برقرار است، چون رویدادِ خودکار اصلاً
 * شناسهٔ مدلی ندارد که بشود حذفش کرد.
 */
class CalendarEvent extends Model
{
    protected $fillable = ['type', 'title', 'description', 'event_date', 'meta', 'status', 'user_id'];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'meta'       => 'array',
        ];
    }

    /**
     * نقشهٔ پشتیبان — اگر `config/calendar.php` نرسید (کشِ کانفیگِ کهنه روی
     * سرور، همان تلهٔ §۳ در CLAUDE.md)، اعتبارسنجی نباید هر نوعی را بپذیرد و
     * نه اینکه همه را رد کند.
     *
     * @var list<string>
     */
    public const TYPES_FALLBACK = ['domain_renewal', 'hosting_renewal', 'payment_due', 'social_post', 'task'];

    /** @var list<string> */
    public const STATUSES_FALLBACK = ['pending', 'done', 'cancelled'];

    /**
     * نوع‌های مجاز — منبعِ یگانه `config/calendar.php`.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        $keys = array_keys((array) config('calendar.layers', []));

        return $keys !== [] ? $keys : self::TYPES_FALLBACK;
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        $keys = array_keys((array) config('calendar.statuses', []));

        return $keys !== [] ? $keys : self::STATUSES_FALLBACK;
    }

    /** برچسبِ فارسیِ یک وضعیت؛ کدِ ناشناخته **خودش** برمی‌گردد نه رشتهٔ خالی */
    public static function statusLabel(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return config('calendar.statuses.'.$code) ?? $code;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * رویدادهای یک بازه — مرزها **شامل** هستند.
     *
     * `event_date` از نوعِ date است، پس مقایسه با `toDateString()` انجام
     * می‌شود نه با یک لحظه؛ وگرنه روی MariaDB مقایسهٔ date با datetime
     * روزِ آخرِ بازه را بی‌صدا می‌انداخت.
     */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('event_date', [$from->toDateString(), $to->toDateString()]);
    }

    /** فقط لایه‌های خواسته‌شده؛ فهرستِ خالی یعنی «همه» */
    public function scopeOfTypes(Builder $query, array $types): Builder
    {
        return $types === [] ? $query : $query->whereIn('type', $types);
    }
}
