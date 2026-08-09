<?php

namespace App\Models;

use App\Support\Jalali;
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
    protected $fillable = [
        'type', 'title', 'description', 'event_date', 'meta', 'status', 'user_id',
        'repeat', 'repeat_until', 'amount', 'currency_code',
    ];

    protected function casts(): array
    {
        return [
            'event_date'   => 'date',
            'repeat_until' => 'date',
            'amount'       => 'integer',
            'meta'         => 'array',
        ];
    }

    /** @var list<string> */
    public const REPEATS_FALLBACK = ['none', 'weekly', 'monthly', 'yearly'];

    /**
     * سقفِ تکرارِ باز‌شده در یک بازه.
     *
     * ⚠️ محافظ در برابرِ حلقهٔ بی‌پایان: اگر روزی قاعده‌ای اضافه شود که تاریخ را
     * جلو نبرد، حلقهٔ باز‌کردن تا ابد می‌چرخد و درخواست را می‌کشد. بازهٔ مجاز
     * حداکثر ۶۲ روز است، پس حتی روزانه هم به این سقف نمی‌رسد.
     */
    private const MAX_OCCURRENCES = 400;

    /** @return list<string> */
    public static function repeats(): array
    {
        $keys = array_keys((array) config('calendar.repeats', []));

        return $keys !== [] ? $keys : self::REPEATS_FALLBACK;
    }

    public function isRecurring(): bool
    {
        return ($this->repeat ?? 'none') !== 'none';
    }

    /**
     * تاریخ‌های میلادیِ این رویداد که داخلِ بازهٔ [from, to] می‌افتند.
     *
     * 🔴 برای «ماهانه» و «سالانه» گام در **تقویمِ شمسی** برداشته می‌شود، نه
     * میلادی. «پنجمِ هر ماه» یعنی ۵ مرداد، ۵ شهریور، ۵ مهر — و چون ماه‌های
     * شمسی ۳۱/۳۰/۲۹ روزه‌اند، جلوبردنِ ماهِ میلادی بعد از چند ماه یکی‌دو روز
     * جابه‌جا می‌شد و یادآوریِ اجاره آرام‌آرام از روزش می‌افتاد.
     *
     * «هفتگی» عمداً میلادی است — هفته در هر دو تقویم دقیقاً ۷ روز است.
     *
     * @return list<string> تاریخ‌های `Y-m-d` میلادی، مرتب
     */
    public function occurrencesBetween(Carbon $from, Carbon $to): array
    {
        $start = $this->event_date;

        if ($start === null) {
            return [];
        }

        $fromDay = $from->toDateString();
        $toDay = $to->toDateString();

        // پایانِ سری زودتر از شروعِ بازه ⇒ هیچ تکراری در دید نیست
        $until = $this->repeat_until?->toDateString();
        if ($until !== null && $until < $fromDay) {
            return [];
        }

        if (! $this->isRecurring()) {
            $day = $start->toDateString();

            return ($day >= $fromDay && $day <= $toDay) ? [$day] : [];
        }

        $tz = (string) config('calendar.display_timezone', 'Asia/Tehran');
        [$jy, $jm, $jd] = Jalali::ofMoment(Carbon::parse($start->toDateString(), $tz), $tz);

        $out = [];
        $step = 0;

        while ($step < self::MAX_OCCURRENCES) {
            $day = match ($this->repeat) {
                'weekly' => $start->copy()->addWeeks($step)->toDateString(),
                'yearly' => $this->gregorianOf(Jalali::addYears($jy, $jm, $jd, $step)),
                default  => $this->gregorianOf(Jalali::addMonths($jy, $jm, $jd, $step)),
            };

            $step++;

            if ($day > $toDay || ($until !== null && $day > $until)) {
                break;
            }

            if ($day >= $fromDay) {
                $out[] = $day;
            }
        }

        return $out;
    }

    /**
     * آیا این **تکرارِ مشخص** انجام شده؟
     *
     * ⚠️ وضعیت به‌ازای هر تکرار است نه کلِ سری: اجارهٔ مرداد پرداخت شده،
     * شهریور هنوز نه. برای ردیفِ غیرتکرارشونده همان `status` خودش ملاک است.
     */
    public function statusOn(string $gregorianDay): string
    {
        if (! $this->isRecurring()) {
            return (string) $this->status;
        }

        $done = (array) (($this->meta['done'] ?? []));

        return in_array($gregorianDay, $done, true) ? 'done' : 'pending';
    }

    /**
     * ثبتِ وضعیتِ یک تکرارِ مشخص.
     *
     * `done` تاریخ را اضافه می‌کند و هر چیزِ دیگری آن را برمی‌دارد — پس
     * «بازگرداندن» همان حذف از فهرست است و حالتِ سومی لازم نیست.
     */
    public function markOccurrence(string $gregorianDay, string $status): void
    {
        $meta = (array) ($this->meta ?? []);
        $done = array_values(array_unique(array_filter((array) ($meta['done'] ?? []), 'is_string')));

        $done = $status === 'done'
            ? array_values(array_unique([...$done, $gregorianDay]))
            : array_values(array_diff($done, [$gregorianDay]));

        sort($done);
        $meta['done'] = $done;

        $this->update(['meta' => $meta]);
    }

    /**
     * ردیف‌هایی که ممکن است در بازه تکرار داشته باشند.
     *
     * 🔴 نمی‌شود فقط `whereBetween('event_date', …)` زد: ردیفِ «اجاره از فروردین
     * ۱۴۰۴» تاریخِ شروعش خارجِ بازه است ولی تکرارش داخلِ آن می‌افتد. آن شرط،
     * **هر رویدادِ تکرارشونده‌ای را که قبلاً شروع شده بی‌صدا حذف می‌کرد** —
     * یعنی اجاره هیچ‌وقت در تقویم دیده نمی‌شد.
     */
    public function scopeRelevantTo(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where(function (Builder $q) use ($from, $to) {
            $q->where(fn (Builder $w) => $w
                ->where('repeat', 'none')
                ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()]))
                ->orWhere(fn (Builder $w) => $w
                    ->where('repeat', '!=', 'none')
                    ->where('event_date', '<=', $to->toDateString())
                    ->where(fn (Builder $u) => $u
                        ->whereNull('repeat_until')
                        ->orWhere('repeat_until', '>=', $from->toDateString())));
        });
    }

    /** @param array{0:int,1:int,2:int} $jalali */
    private function gregorianOf(array $jalali): string
    {
        [$gy, $gm, $gd] = Jalali::toGregorian($jalali[0], $jalali[1], $jalali[2]);

        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
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
