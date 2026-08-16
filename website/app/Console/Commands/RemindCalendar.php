<?php

namespace App\Console\Commands;

use App\Services\Calendar\CalendarItem;
use App\Services\Calendar\CalendarService;
use App\Services\Notify\AdminNotifier;
use App\Support\Jalali;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * یادآوریِ روزانهٔ تقویم به مدیر — از راهِ بله و ایمیل.
 *
 * چرا لازم است: تقویم فقط وقتی کار می‌کند که کسی بازش کند. سررسیدی که در
 * صفحه‌ای نشسته که آن روز باز نشده، عملاً وجود ندارد. این فرمان همان چند خط
 * را می‌بَرد جایی که مدیر هر روز نگاه می‌کند.
 */
class RemindCalendar extends Command
{
    protected $signature = 'calendar:remind
        {--days= : چند روزِ آینده (پیش‌فرض از config)}
        {--force : حتی اگر امروز فرستاده شده، دوباره بفرست}';

    protected $description = 'یادآوریِ سررسیدهای پیشِ‌رو به مدیر (بله + ایمیل)';

    public function handle(CalendarService $calendar, AdminNotifier $admin): int
    {
        $tz = (string) config('calendar.display_timezone', 'Asia/Tehran');
        $days = (int) ($this->option('days') ?: config('calendar.remind_days', 2));

        /*
         * 🔴 لایهٔ گوگل عمداً بیرون است.
         *
         * اتصالِ گوگل per-user است و `GoogleCalendarProvider` حسابِ **کاربرِ
         * واردشده** را می‌خواند. در کرون هیچ کاربری وارد نیست، پس آن لایه
         * خودبه‌خود خالی برمی‌گردد. این را صریح می‌نویسم تا اگر روزی کسی
         * دنبالِ «چرا جلسهٔ گوگلم در یادآوری نبود» گشت، جوابش این‌جا باشد —
         * نه اینکه فکر کند خراب است.
         */
        $layers = array_values(array_diff(
            array_keys((array) config('calendar.layers', [])),
            ['google'],
        ));

        $upcoming = $calendar->upcoming($layers, max(1, $days))
            ->reject(fn (CalendarItem $i) => $i->status === 'done')
            ->values();

        if ($upcoming->isEmpty()) {
            /*
             * ⚠️ «چیزی نیست» فرستاده نمی‌شود.
             *
             * درسِ ثبت‌شدهٔ همین پروژه: مدیری که هر روز پیامِ بی‌محتوا بگیرد از
             * هفتهٔ دوم همه را نادیده می‌گیرد — و آن‌وقت پیامِ روزی که واقعاً
             * مهم است هم گم می‌شود. سکوت یعنی «کاری نیست».
             */
            $this->line('چیزی برای یادآوری نیست.');

            return self::SUCCESS;
        }

        /*
         * ⚠️ گلوگاهِ **فایلی** و روزانه — نه کش.
         *
         * کشِ پروداکشن روی همان دیتابیسی است که سابقهٔ قطعیِ گذرا دارد، و
         * یادآوری‌ای که با مرگِ آن وابستگی خفه شود بی‌فایده است (CLAUDE.md §۳).
         * امضا شاملِ **فهرستِ رویدادها**ست، پس اگر وسطِ روز موردِ تازه‌ای اضافه
         * شود، پیامِ تازه می‌رود؛ ولی تکرارِ همان فهرست نه.
         */
        $signature = md5($upcoming->map(fn (CalendarItem $i) => $i->id().$i->dateKey())->implode('|'));
        $key = 'calendar-remind-'.Carbon::now($tz)->toDateString();

        if (! $this->option('force')
            && ! \App\Support\ErrorTracker::throttlePassed($key, 86400, $signature)) {
            $this->line('امروز همین فهرست فرستاده شده.');

            return self::SUCCESS;
        }

        /*
         * 🔴 تاریخ **سرگروه** است، نه دنبالهٔ هر ردیف.
         *
         * نسخهٔ اول به هر خط «۲۵ مرداد (امروز)» می‌چسباند و در پیامی با چهار
         * مورد، همان عبارت چهار بار تکرار می‌شد. در پیامِ کوتاهِ بله این تکرار
         * چشم را پر می‌کند و عنوانِ خودِ کار — که تنها چیزِ مهم است — گم
         * می‌شود. حالا هر روز یک سرگروه دارد و زیرش فقط کارها.
         *
         * ⚠️ `upcoming` از قبل زمانی مرتب است، پس گروه‌ها هم به ترتیب درمی‌آیند
         * و مرتب‌سازیِ دوباره لازم نیست.
         */
        $groups = [];

        foreach ($upcoming as $item) {
            $key = $item->dateKey();

            if (! isset($groups[$key])) {
                [, $jm, $jd] = Jalali::ofMoment($item->at, $tz);
                $away = $item->daysFromToday();

                $when = match (true) {
                    $away <= 0 => 'امروز',
                    $away === 1 => 'فردا',
                    default => fa_num($away).' روز دیگر',
                };

                $groups[$key] = [
                    'head'  => fa_num($jd).' '.Jalali::monthName($jm).' — '.$when,
                    'lines' => [],
                ];
            }

            $layer = (string) (config('calendar.layers.'.$item->type.'.label') ?? $item->type);
            $line = '• '.$item->title.' — '.$layer;

            /*
             * مبلغ وقتی هست می‌آید — اجاره، فاکتور، تمدید. در بله رنگ و ستون
             * نداریم، پس عددی که تصمیمِ صبح را عوض می‌کند باید در خودِ خط باشد.
             */
            if ($money = $item->money()) {
                $line .= ' · '.$money;
            }

            $groups[$key]['lines'][] = $line;
        }

        $blocks = array_map(
            static fn (array $g) => $g['head']."\n".implode("\n", $g['lines']),
            $groups,
        );

        /*
         * ⚠️ کلِ بدنه **یک ردیف** است، نه چند ردیف.
         *
         * `AdminNotifier::event()` ردیف‌ها را با یک `\n` می‌چسباند و ردیفِ
         * خالی را حذف می‌کند، پس از راهِ آن نمی‌شود بینِ گروه‌ها خطِ خالی
         * گذاشت. با یک رشتهٔ چندخطی، فاصله‌گذاری کاملاً در اختیارِ ماست.
         */
        /*
         * ⚠️ خطِ خالیِ ابتدا و انتها **داخلِ** همین رشته است.
         *
         * `AdminNotifier` تیتر، ردیف‌ها و لینک را با یک `\n` می‌چسباند، پس
         * بی‌این، سرگروهِ اول به تیتر و لینک به آخرین ردیف می‌چسبید و کلِ پیام
         * یک تودهٔ به‌هم‌فشرده می‌شد.
         */
        $admin->event(
            'یادآوری تقویم — '.fa_num($upcoming->count()).' مورد پیشِ رو',
            ["\n".implode("\n\n", $blocks)."\n"],
            url('/admin/calendar'),
            '📅',
        );

        $this->info('یادآوری فرستاده شد: '.$upcoming->count().' مورد');

        return self::SUCCESS;
    }
}
