<?php

namespace App\Services\Calendar\Providers;

use App\Models\GoogleCalendarToken;
use App\Services\Calendar\CalendarEventProvider;
use App\Services\Calendar\CalendarItem;
use App\Services\Calendar\Google\GoogleCalendarClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * تقویمِ شخصیِ گوگلِ **کاربرِ جاری**.
 *
 * 🔴 این تنها لایه‌ای است که per-user است. بقیه دادهٔ شرکت را نشان می‌دهند و
 * برای همه یکی‌اند؛ این یکی جلسهٔ دکتر و قرارِ خانوادگیِ یک نفر است. اگر
 * اتصالِ مشترک بود، رویدادهای شخصیِ مدیر روی میزِ هر کاربرِ نقشِ `admin`
 * می‌افتاد. کاربرِ بی‌اتصال چیزی نمی‌بیند — نه خطا، نه لایهٔ خالیِ گیج‌کننده.
 *
 * 🔴 و تنها لایه‌ای که **کش می‌شود**. قاعدهٔ «هیچ‌چیز کپی نمی‌شود» برای
 * جدول‌های خودمان است چون خواندنشان مجانی است؛ این‌جا هر رندرِ صفحه یک تماسِ
 * شبکه‌ایِ چندصد‌میلی‌ثانیه‌ای و یک خطِ سهمیه است. ناوبریِ ماه‌به‌ماه بی‌کش
 * یعنی صفحه‌ای که کند حس می‌شود و سهمیه‌ای که ظهر تمام می‌شود.
 */
class GoogleCalendarProvider implements CalendarEventProvider
{
    /**
     * عمرِ کش (ثانیه).
     *
     * ⚠️ کوتاه عمدی است: کارفرما گفت «سرِ راه یه جلسه ست می‌کنم، می‌خوام این‌جا
     * ببینمش». پنج دقیقه یعنی رویدادی که روی گوشی ساخته شده تقریباً بلافاصله
     * این‌جا هست، بی‌آنکه هر بار به گوگل زنگ بزنیم.
     */
    private const TTL = 300;

    public function __construct(private readonly GoogleCalendarClient $google) {}

    public function getEvents(Carbon $from, Carbon $to): Collection
    {
        $userId = auth()->id();
        $token = GoogleCalendarToken::forUser($userId);

        if ($token === null || ! GoogleCalendarClient::configured()) {
            return collect();
        }

        $items = $this->cached($userId, $from, $to, $token);

        return collect($items)
            ->map(fn (array $ev) => $this->toItem($ev))
            ->filter()
            ->values();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function cached(int $userId, Carbon $from, Carbon $to, GoogleCalendarToken $token): array
    {
        $key = 'gcal:'.$userId.':'.$from->toDateString().':'.$to->toDateString();

        $fetch = function () use ($token, $from, $to): array {
            $res = $this->google->listEvents($token, $from, $to);

            return $res['ok'] ? $res['items'] : [];
        };

        /*
         * ⚠️ خرابیِ **کش** نباید لایه را بکشد.
         *
         * کشِ پیش‌فرضِ این پروژه روی دیتابیس است و همان دیتابیس سابقهٔ قطعیِ
         * گذرا دارد (CLAUDE.md §۳). اگر `Cache::remember` بترکد، به تماسِ
         * مستقیم برمی‌گردیم — کندتر، ولی کار می‌کند.
         */
        try {
            return Cache::remember($key, self::TTL, $fetch);
        } catch (\Throwable) {
            return $fetch();
        }
    }

    /**
     * رویدادِ گوگل → `CalendarItem`. نال یعنی قابلِ نمایش نیست.
     */
    private function toItem(array $ev): ?CalendarItem
    {
        $tz = (string) config('calendar.display_timezone', 'Asia/Tehran');

        /*
         * گوگل دو شکلِ زمان دارد: `dateTime` (لحظه‌دار، با منطقهٔ زمانی) و
         * `date` (تمام‌روز). اگر فقط اولی خوانده می‌شد، هر رویدادِ تمام‌روزِ
         * کاربر — که دقیقاً همان تولد و مرخصی و سفر است — بی‌صدا غیب می‌شد.
         */
        $start = $ev['start']['dateTime'] ?? $ev['start']['date'] ?? null;

        if (blank($start)) {
            return null;
        }

        $allDay = ! isset($ev['start']['dateTime']);

        try {
            $at = $allDay
                ? Carbon::parse($start, $tz)          // روزِ تقویمی، به وقتِ نمایش
                : Carbon::parse($start);              // لحظه‌ی واقعی، با tzِ خودش
        } catch (\Throwable) {
            return null;
        }

        // رویدادِ لغوشده در گوگل نباید در تقویمِ ما زنده بماند
        if (($ev['status'] ?? '') === 'cancelled') {
            return null;
        }

        return new CalendarItem(
            type: 'google',
            source: 'google',
            sourceId: (string) ($ev['id'] ?? ''),
            title: (string) ($ev['summary'] ?? 'بدون عنوان'),
            description: $this->describe($ev),
            at: $at,
            status: 'pending',
            meta: [
                'all_day'  => $allDay,
                'location' => $ev['location'] ?? null,
            ],
            // لینک به خودِ رویداد در گوگل — ویرایش آن‌جا انجام می‌شود
            url: $ev['htmlLink'] ?? null,
            editable: false,
        );
    }

    private function describe(array $ev): string
    {
        $parts = array_filter([
            $ev['location'] ?? null,
            isset($ev['start']['dateTime']) ? null : 'تمام‌روز',
        ]);

        return implode(' — ', $parts) ?: 'گوگل‌کلندر';
    }
}
