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

// ضربانِ کرون — هر دقیقه یک مهرِ زمانی می‌نویسد. بدونِ این، وقتی سفارشی تحویل
// نمی‌شود هیچ راهی نبود بفهمیم «کرونِ سرور اصلاً اجرا می‌شود یا نه» و ساعت‌ها
// دنبالِ باگِ کد می‌گشتیم در حالی که مشکل نبودنِ کرون بود.
Schedule::call(fn () => cache()->put('sn.schedule.last', now()->toDateTimeString(), 86400))
    ->everyMinute()
    ->name('cron-heartbeat')
    ->withoutOverlapping();

// صفِ تحویلِ سرویس — سرویس‌هایِ پرداخت‌شده که منتظرِ ساختِ خودکار روی سرورند.
// هر دقیقه، جدا از درخواستِ پرداخت (تماسِ WHM نباید وب‌هوکِ درگاه را کند کند).
Schedule::command('provision:run')
    ->everyMinute()
    ->withoutOverlapping();

// پی‌گیریِ سرورهای ابریِ در حالِ آماده‌سازی + بستنِ سفارش‌های نیمه‌کاره.
// ⚠️ بی‌این، سفارشِ دومرحله‌ایِ زیرساختِ دوم برای همیشه روی `order:…` می‌مانَد و
// مشتری سرورِ پول‌داده‌اش را نه IP دارد نه می‌تواند مدیریت کند.
Schedule::command('cloud:sync-instances')
    ->everyMinute()
    ->withoutOverlapping();

// متر کردنِ سرورهای ابریِ **ساعتی** — هر ساعت از کیفِ پولِ مشتری کم می‌کند.
// idempotent است (claimِ اتمی روی last_metered_at)، پس اجرای دوباره در یک ساعت
// دوبار کسر نمی‌کند. withoutOverlapping هم لایهٔ دوم.
Schedule::command('cloud:meter')
    ->hourly()
    ->withoutOverlapping();

// کاتالوگ و قیمتِ سرورِ ابری — کارفرما: «هر دو روز یک‌بار، ۲ شب، هم قیمت هم
// پکیج‌ها».
//
// ⚠️ ساعت به وقتِ **UTC** است (config/app.php ثابت روی UTC). تهران +۳:۳۰، پس
// «۲ بامدادِ تهران» = ۲۲:۳۰ UTC روزِ قبل. اگر ۰۲:۰۰ UTC می‌گذاشتیم، در تهران
// ۵:۳۰ صبح می‌شد — نه آن چیزی که خواسته شده.
//
// این یک اجرا **هم پکیج‌ها را می‌آورد هم قیمت‌ها را بازمحاسبه می‌کند**:
// `cloud:sync` مشخصات و بهایِ تمام‌شده را تازه می‌کند و در همان گذر قیمتِ فروش
// را با نرخِ روزِ یورو می‌سازد. پس یک کرون کافی است، نه دو.
Schedule::command('cloud:sync')
    ->cron('30 22 */2 * *')
    ->withoutOverlapping(60);

// بازمحاسبهٔ قیمتِ تومانی با نرخِ روزِ یورو — روزانه و بی‌تماسِ API.
// جدا از سینکِ کامل است چون نرخِ ارز روزانه می‌جنبد ولی مشخصاتِ پلن‌ها ماه‌ها
// ثابت می‌مانند؛ بی‌این، قیمتِ تومانی تا سینکِ بعدی کهنه می‌ماند.
// fx:dollar ساعتی می‌دود، پس نرخ همیشه تازه است.
Schedule::command('cloud:sync --prices')
    ->dailyAt('05:40')
    ->withoutOverlapping();
