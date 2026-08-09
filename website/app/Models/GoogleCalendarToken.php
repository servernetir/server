<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * اتصالِ حسابِ گوگلِ یک کاربرِ پنل.
 *
 * ⚠️ هر دو توکن با cast `encrypted` ذخیره می‌شوند و در `$hidden` هستند —
 * همان قاعدهٔ `Service::$casts['password']` و `CloudPlan::$hidden` در این
 * پروژه: چیزی که نباید در هیچ JSONی ظاهر شود، نباید به شانس واگذار شود.
 */
class GoogleCalendarToken extends Model
{
    protected $fillable = [
        'user_id', 'google_email', 'calendar_id',
        'access_token', 'refresh_token', 'expires_at', 'synced_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'access_token'  => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at'    => 'datetime',
            'synced_at'     => 'datetime',
        ];
    }

    /** @var list<string> */
    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * چند ثانیه **پیش از** انقضا، توکن را کهنه حساب کن.
     *
     * 🔴 بدونِ این حاشیه، توکنی که ۲ ثانیه دیگر منقضی می‌شود «معتبر» خوانده
     * می‌شود و درست وسطِ درخواست می‌میرد — یک ۴۰۱ِ تصادفی که فقط گاهی رخ
     * می‌دهد و بازتولیدش تقریباً ناممکن است.
     */
    public const EXPIRY_SKEW_SECONDS = 120;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return true;   // نمی‌دانیم ⇒ فرض بر کهنه‌بودن، نه بر سالم‌بودن
        }

        return $this->expires_at->subSeconds(self::EXPIRY_SKEW_SECONDS)->isPast();
    }

    /** اتصالِ کاربرِ جاری، یا نال اگر وصل نکرده / جدول هنوز ساخته نشده */
    public static function forUser(?int $userId): ?self
    {
        if ($userId === null || ! Schema::hasTable('google_calendar_tokens')) {
            return null;
        }

        return static::query()->where('user_id', $userId)->first();
    }

    /** ثبتِ خطای آخر بدونِ دست‌زدن به توکن‌ها */
    public function noteError(?string $message): void
    {
        $this->forceFill([
            'last_error' => $message === null ? null : mb_substr($message, 0, 250),
        ])->save();
    }

    public function markSynced(): void
    {
        $this->forceFill(['synced_at' => Carbon::now(), 'last_error' => null])->save();
    }
}
