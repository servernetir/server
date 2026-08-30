<?php

namespace App\Console\Commands;

use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * `services:backfill-due` — سرویسِ زنده‌ای که `next_due_at` ندارد را وارد چرخهٔ
 * صورت‌حساب می‌کند.
 *
 * ═══ خرابی‌ای که این فرمان برای آن نوشته شد ═══
 *
 * `services:renew-due` شرطِ `whereNotNull('next_due_at')` دارد. یعنی هر سرویسی
 * که پیش از ساخته‌شدنِ سیستمِ سررسید فروخته شده — یا از هر مسیرِ دیگری بی‌سررسید
 * ثبت شده — **برای همیشه از دیدِ صورت‌حساب غایب است**:
 *
 *   نه فاکتورِ تمدید می‌گیرد · نه یادآوری می‌رود · نه تعلیق می‌شود
 *
 * یعنی سرویسِ رایگانِ ابدی، بی‌هیچ خطایی و بی‌هیچ ردی. کارفرما یک نمونه‌اش را
 * دستی پیدا کرد (مشتری SN-256199)؛ مسئله اما یک ردیف نیست، یک **دستهٔ کامل**
 * است و برای همین این فرمان عمومی است نه یک UPDATE دستی.
 *
 * ═══ 🔴 چرا سررسید هرگز در گذشته گذاشته نمی‌شود ═══
 *
 * این دقیقاً همان تلهٔ ثبت‌شدهٔ `/admin/cloud/attach` است. زنجیرهٔ کرون بی‌رحم
 * است:
 *
 *     ۰۷:۰۰  services:renew-due   → فاکتورِ تمدید برای سرویسِ سررسیدگذشته
 *     ۰۷:۳۰  services:lifecycle   → همان فاکتورِ پرداخت‌نشده → تعلیقِ واقعی
 *
 * پس اگر سررسیدِ محاسبه‌شده گذشته باشد، آن‌قدر یک‌دوره-یک‌دوره جلو می‌رود تا در
 * آینده بیفتد. نتیجه: مشتری فردا صبح فاکتور می‌گیرد، نه پیامکِ «سرویس شما
 * قطع شد» برای سرویسی که سال‌ها بی‌مشکل کار کرده.
 *
 * ⚠️ جلوبردنِ سررسید یعنی چند دورهٔ گذشته صورت‌حساب نمی‌شود. این **عمدی** است:
 * مطالبهٔ عقب‌افتادهٔ خودکار تصمیمِ مالیِ کارفراست، نه کارِ یک اسکریپتِ
 * پرکنندهٔ ستون.
 */
class BackfillServiceDueDates extends Command
{
    protected $signature = 'services:backfill-due
        {--dry : فقط گزارش بده، چیزی عوض نکن}
        {--id= : فقط همین سرویس}
        {--from= : لنگرِ تاریخ (میلادی، مثل 2026-08-07) به‌جای activated_at}';

    protected $description = 'پر کردنِ سررسیدِ سرویس‌های قدیمی تا وارد چرخهٔ تمدید شوند';

    public function handle(): int
    {
        if (! Schema::hasTable('services')) {
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry');

        $q = Service::query()
            ->whereNull('next_due_at')
            // فقط سرویسِ زنده. سرویسِ لغوشده سررسید نمی‌خواهد.
            ->whereNotIn('status', Service::DEAD_STATUSES)
            ->whereIn('cycle', array_keys((array) config('billing.cycles', [])));

        if ($id = $this->option('id')) {
            $q = Service::query()->whereKey($id);
        }

        $rows = $q->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $this->line('سرویسِ بی‌سررسیدی نیست.');

            return self::SUCCESS;
        }

        $anchorOpt = $this->option('from')
            ? Carbon::parse((string) $this->option('from'))
            : null;

        $fixed = 0;
        $skipped = 0;

        foreach ($rows as $service) {
            $months = Service::monthsIn((string) $service->cycle);

            if ($months < 1) {
                $skipped++;
                $this->warn('… دورهٔ ناشناخته: #'.$service->id.' ('.$service->cycle.')');

                continue;
            }

            /*
            | لنگر: آنچه کاربر داده، وگرنه تاریخِ فعال‌سازی، وگرنه تاریخِ ساخت.
            |
            | ⚠️ `created_at` آخرین چاره است نه اولین: برای سرویسی که ماه‌ها بعد
            | از ثبت فعال شده، تاریخِ ساخت دوره را از جای غلط شروع می‌کند.
            */
            $anchor = $anchorOpt
                ?? $service->activated_at
                ?? $service->created_at
                ?? now();

            $due = $anchor->copy();

            // 🔴 تا وقتی در گذشته است، یک دوره جلو برو
            $guard = 0;

            while ($due->isPast() && $guard++ < 600) {
                $due->addMonthsNoOverflow($months);
            }

            if ($dry) {
                $this->line('(آزمایشی) #'.$service->id.' → '.$due->toDateString()
                    .'  ['.$service->cycle.'، لنگر '.$anchor->toDateString().']');
                $fixed++;

                continue;
            }

            $service->forceFill(['next_due_at' => $due])->save();
            $fixed++;

            $this->info('✓ #'.$service->id.' '.$service->name.' → '.$due->toDateString());
        }

        $this->line("جمع: {$fixed} تنظیم‌شده · {$skipped} ردشده");

        return self::SUCCESS;
    }
}
