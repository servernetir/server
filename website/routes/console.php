<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| زمان‌بندی محتوا
|--------------------------------------------------------------------------
| با یک خط کران روی سرور همه‌ی این‌ها اجرا می‌شوند:
|
|   * * * * * cd /home/servernetcloud/servernet_app \
|     && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >/dev/null 2>&1
|
| withoutOverlapping جلوی هم‌پوشانی را می‌گیرد (تولید هر مقاله چند دقیقه طول می‌کشد).
*/

// انتشار پیش‌نویس‌هایی که زمانشان رسیده — ساعتی، تا انتشار در طول روز پخش شود
Schedule::command('content:publish-due')
    ->hourly()
    ->withoutOverlapping();

// تولید مقاله‌های تازه از برنامه — روزی ۳ مقاله
Schedule::command('content:generate --limit=3 --days=2')
    ->dailyAt('10:00')
    ->withoutOverlapping(30);

// نرخ دلار آزاد — هر ساعت، مبنای قیمت‌گذاری دامنه
Schedule::command('fx:dollar')
    ->hourly()
    ->withoutOverlapping();

// تولید مطالب پایگاه دانش — روزی ۲ مطلب از docs-plan.
// بدون این، ۱۰۱ موضوع پایگاه دانش هرگز ساخته نمی‌شدند: زمان‌بندی بالا فقط
// برنامه‌ی پیش‌فرض (بلاگ) را می‌سازد. --daily یعنی هر مطلب در یک روز جدا منتشر شود.
Schedule::command('content:generate --limit=2 --plan=docs-plan --daily')
    ->dailyAt('14:00')
    ->withoutOverlapping(30);

// تکمیل ترجمه‌هایی که در تولید جا مانده‌اند
Schedule::command('content:translate-missing --limit=2')
    ->dailyAt('12:30')
    ->withoutOverlapping(30);

// صدور فاکتور تمدید برای سرویس‌های دوره‌ای سررسیدشده — روزی یک‌بار
Schedule::command('services:renew-due')
    ->dailyAt('07:00')
    ->withoutOverlapping();

// چرخهٔ تمدید: یادآوریِ ۷/۳/۱ روز، تعلیقِ خودکارِ فاکتورِ پرداخت‌نشده و اعلانِ
// پایانِ مهلتِ ۳۰ روزه به مدیر. بعد از صدورِ فاکتورها اجرا می‌شود تا یادآوریِ
// «۷ روز مانده» مبلغِ همان فاکتورِ تازه را داشته باشد.
// ⚠️ ساعت به وقت UTC است (config/app.php ثابت روی UTC) → ۰۷:۳۰ UTC ≈ ۱۱:۰۰ تهران.
Schedule::command('services:lifecycle')
    ->dailyAt('07:30')
    ->withoutOverlapping();

// صفِ تحویلِ سرویس — سرویس‌هایِ پرداخت‌شده که منتظرِ ساختِ خودکار روی سرورند.
// هر دقیقه، جدا از درخواستِ پرداخت (تماسِ WHM نباید وب‌هوکِ درگاه را کند کند).
Schedule::command('provision:run')
    ->everyMinute()
    ->withoutOverlapping();
