<?php

namespace App\Services\Calendar\Providers;

use App\Models\CalendarEvent;
use App\Services\Calendar\CalendarEventProvider;
use App\Services\Calendar\CalendarItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * رویدادهای دستی — یادآوری و کاری که مدیر خودش نوشته.
 *
 * ⚠️ این provider **همهٔ** نوع‌ها را برمی‌گرداند، نه فقط `task`. دلیلش این است
 * که مدیر می‌تواند یادآوریِ دستی با نوعِ «سررسید پرداخت» بسازد (مثلاً بدهیِ
 * یک تأمین‌کننده که در هیچ فاکتوری نیست) و آن رویداد باید در همان لایه دیده
 * شود، نه در لایهٔ «کار».
 *
 * در `config/calendar.php` فقط زیرِ کلیدِ `task` ثبت شده، پس یک بار صدا زده
 * می‌شود. اگر لایهٔ `task` را کاربر خاموش کند، رویدادهای دستیِ **همهٔ** نوع‌ها
 * پنهان می‌شوند — که همان رفتارِ درست است: «یادداشت‌های من» یک لایه است.
 */
class ManualEventProvider implements CalendarEventProvider
{
    use CapsLayerRows;

    public function getEvents(Carbon $from, Carbon $to): Collection
    {
        if (! Schema::hasTable('calendar_events')) {
            return collect();
        }

        return CalendarEvent::query()
            ->between($from, $to)
            ->orderBy('event_date')
            ->limit($this->rowCap())
            ->get()
            ->map(fn (CalendarEvent $row) => new CalendarItem(
                type: $row->type,
                source: 'manual',
                sourceId: $row->id,
                title: $row->title,
                description: $row->description,
                /*
                 * `event_date` یک روزِ تقویمی است و Carbon آن را نیمه‌شبِ UTC
                 * می‌خوانَد. اگر همان را بدهیم، `CalendarItem` با تبدیل به وقتِ
                 * تهران آن را ۰۳:۳۰ **همان روز** نشان می‌دهد — درست — ولی
                 * رویدادِ نیمه‌شبِ تهران را یک روز عقب می‌بُرد. پس صریح می‌گوییم
                 * این لحظه، نیمه‌شبِ **تهران** است.
                 */
                at: Carbon::parse($row->event_date->toDateString(), $this->timezone()),
                status: $row->status,
                meta: (array) ($row->meta ?? []),
                url: null,
                editable: true,
            ));
    }

    private function timezone(): string
    {
        return (string) config('calendar.display_timezone', 'Asia/Tehran');
    }
}
