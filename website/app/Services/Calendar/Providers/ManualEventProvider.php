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

        $tz = $this->timezone();

        /*
         * ⚠️ `relevantTo` و نه `between`: ردیفِ «اجاره از فروردین ۱۴۰۴» تاریخِ
         * شروعش خارجِ بازه است ولی تکرارش داخلِ آن می‌افتد. با فیلترِ ساده،
         * هر سریِ تکرارشونده‌ای که قبلاً شروع شده بی‌صدا از تقویم غیب می‌شد.
         */
        return CalendarEvent::query()
            ->relevantTo($from, $to)
            ->orderBy('event_date')
            ->limit($this->rowCap())
            ->get()
            ->flatMap(function (CalendarEvent $row) use ($from, $to, $tz) {
                return array_map(
                    fn (string $day) => new CalendarItem(
                        type: $row->type,
                        source: 'manual',
                        /*
                         * شناسهٔ یک **تکرارِ مشخص**: `12@2026-08-27`. بی‌این،
                         * دوازده اجارهٔ سال همه یک شناسه داشتند و «انجام شد»ِ
                         * مرداد، شهریور را هم تیک می‌زد.
                         */
                        sourceId: $row->isRecurring() ? $row->id.'@'.$day : $row->id,
                        title: $row->title,
                        description: $this->describe($row),
                        /*
                         * `event_date` یک روزِ تقویمی است و Carbon آن را نیمه‌شبِ
                         * UTC می‌خوانَد؛ صریح می‌گوییم نیمه‌شبِ **تهران** است تا
                         * در شبکهٔ شمسی سرِ روزِ درست بنشیند.
                         */
                        at: Carbon::parse($day, $tz),
                        status: $row->statusOn($day),
                        meta: [
                            'event_id'  => $row->id,
                            'repeat'    => $row->repeat,
                            'occurrence' => $row->isRecurring() ? $day : null,
                            'amount'    => $row->amount,
                            'currency'  => $row->currency_code,
                        ] + (array) ($row->meta ?? []),
                        url: null,
                        editable: true,
                    ),
                    $row->occurrencesBetween($from, $to),
                );
            });
    }

    /**
     * توضیحِ نمایشی: متنِ خودِ کاربر + مبلغ + نشانِ تکرار.
     *
     * مبلغ با `invoice_money()` قالب می‌گیرد تا واحدش با بقیهٔ پنل یکی باشد و
     * دستی « تومان» چسبانده نشود.
     */
    private function describe(CalendarEvent $row): string
    {
        $parts = array_filter([
            $row->description,
            $row->amount ? invoice_money((int) $row->amount, (string) ($row->currency_code ?: 'IRT')) : null,
            $row->isRecurring() ? (config('calendar.repeats.'.$row->repeat) ?: null) : null,
        ]);

        return implode(' — ', $parts);
    }

    private function timezone(): string
    {
        return (string) config('calendar.display_timezone', 'Asia/Tehran');
    }
}
