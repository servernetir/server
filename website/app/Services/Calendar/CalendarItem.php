<?php

namespace App\Services\Calendar;

use App\Support\Jalali;
use Illuminate\Support\Carbon;

/**
 * یک رویدادِ نرمال‌شده در تقویم — مستقل از این‌که از کدام جدول آمده.
 *
 * چرا DTO و نه آرایه: پنج منبعِ متفاوت به این شکل ترجمه می‌شوند و یکی‌شان
 * (`ManualEventProvider`) مدلِ Eloquent دارد و بقیه ندارند. با آرایه، هر
 * providerی می‌توانست کلیدی را جا بیندازد و رابط کاربری بی‌هیچ خطایی خالی
 * رندر کند — همان «خرابیِ خاموش»ی که این پروژه بارها خورده.
 *
 * ⚠️ نامش عمداً `CalendarItem` است نه `CalendarEvent`: آن نام مالِ مدلِ
 * Eloquentِ رویدادهای دستی است و هم‌نامی این دو، `use` ها را در هر فایلی که
 * هر دو را لازم دارد به `as` ی مبهم می‌کشاند.
 */
final class CalendarItem
{
    /**
     * @param  string  $type  یکی از کلیدهای `config('calendar.layers')`
     * @param  string  $source  جدولِ مبدأ: manual | domain | service | invoice | post
     * @param  int|string  $sourceId  شناسه در جدولِ مبدأ
     * @param  Carbon  $at  لحظهٔ رویداد (UTC، همان چیزی که در دیتابیس است)
     * @param  array<string,mixed>  $meta
     * @param  bool  $editable  آیا وضعیتش از خودِ تقویم قابلِ تغییر است؟
     */
    public function __construct(
        public readonly string $type,
        public readonly string $source,
        public readonly int|string $sourceId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly Carbon $at,
        public readonly string $status = 'pending',
        public readonly array $meta = [],
        public readonly ?string $url = null,
        public readonly bool $editable = false,
    ) {}

    /**
     * کلیدِ یکتاسازی.
     *
     * ⚠️ **تاریخ در کلید هست.** بی‌آن، یک دامنه که در بازهٔ درخواستی هم منقضی
     * می‌شود و هم فاکتورِ تمدیدش سررسید دارد، یکی از دو ردیف را از دست می‌داد.
     * با تاریخ، فقط ردیف‌های واقعاً یکسان یکی می‌شوند.
     */
    public function uniqueKey(): string
    {
        return $this->type.'|'.$this->source.'|'.$this->sourceId.'|'.$this->dateKey();
    }

    /** شناسه‌ای که رابط کاربری با آن رویداد را صدا می‌زند */
    public function id(): string
    {
        return $this->source.':'.$this->sourceId;
    }

    /** `1405-05-12` — روزِ شمسیِ رویداد به وقتِ نمایش */
    public function jalaliDate(): string
    {
        [$jy, $jm, $jd] = Jalali::ofMoment($this->at, $this->timezone());

        return Jalali::format($jy, $jm, $jd);
    }

    /** `2026-08-03` — روزِ میلادیِ رویداد به وقتِ نمایش (کلیدِ مرتب‌سازی) */
    public function dateKey(): string
    {
        return $this->at->copy()->setTimezone($this->timezone())->toDateString();
    }

    /** `14:30` — ساعتِ رویداد به وقتِ نمایش، یا نال برای رویدادِ تمام‌روز */
    public function timeLabel(): ?string
    {
        $local = $this->at->copy()->setTimezone($this->timezone());

        return $local->format('H:i') === '00:00' ? null : $local->format('H:i');
    }

    /**
     * چند روز تا رویداد — منفی یعنی گذشته.
     *
     * مقایسه روی **روزِ تقویمی** است نه اختلافِ ساعت، وگرنه رویدادِ امشب
     * ساعت ۲۳ می‌شد «۰ روز» و رویدادِ فردا ساعت ۱ می‌شد هم «۰ روز».
     */
    public function daysFromToday(): int
    {
        $tz = $this->timezone();
        $today = Carbon::now($tz)->startOfDay();
        $day = $this->at->copy()->setTimezone($tz)->startOfDay();

        return (int) $today->diffInDays($day, false);
    }

    /**
     * توضیحِ کاملِ رویداد برای صفحه‌خوان.
     *
     * چرا جدا از `title`: عنوانِ روی چیپ کوتاه است («servernet.cloud») و بدونِ
     * زمینه، صفحه‌خوان فقط یک نامِ دامنه می‌خوانَد. این رشته می‌گوید **چه
     * اتفاقی** در **چه روزی** می‌افتد.
     */
    public function screenReaderLabel(): string
    {
        $layer = (string) (config('calendar.layers.'.$this->type.'.label') ?? $this->type);
        [$jy, $jm, $jd] = Jalali::ofMoment($this->at, $this->timezone());

        $parts = [
            $layer,
            $this->title,
            fa_num($jd).' '.Jalali::monthName($jm).' '.fa_num($jy),
        ];

        if ($time = $this->timeLabel()) {
            $parts[] = 'ساعت '.fa_num($time);
        }

        if ($this->status !== 'pending') {
            $parts[] = (string) config('calendar.statuses.'.$this->status, $this->status);
        }

        return implode('، ', array_filter($parts));
    }

    /** @return array<string,mixed> شکلی که به JSON می‌رود */
    public function toArray(): array
    {
        return [
            'id'          => $this->id(),
            'type'        => $this->type,
            'source'      => $this->source,
            'source_id'   => $this->sourceId,
            'title'       => $this->title,
            'description' => $this->description,
            'date'        => $this->jalaliDate(),
            'date_gregorian' => $this->dateKey(),
            'time'        => $this->timeLabel(),
            'status'      => $this->status,
            'status_label' => config('calendar.statuses.'.$this->status, $this->status),
            'meta'        => $this->meta,
            'url'         => $this->url,
            'editable'    => $this->editable,
            'days_away'   => $this->daysFromToday(),
            'sr_label'    => $this->screenReaderLabel(),
        ];
    }

    private function timezone(): string
    {
        return (string) config('calendar.display_timezone', 'Asia/Tehran');
    }
}
