# رفع ریشه‌ای خطای همگام‌سازی کاتالوگ — ۲۸ اکتبر ۲۰۲۶

## نتیجه و دامنه

خطای مشاهده‌شده هنگام همگام‌سازی کاتالوگ یک خرابی منفرد نبود. سه وضعیت هم‌زمان وجود داشت:

1. اجرای قبلی همگام‌سازی در همان درخواست `POST` چند API تأمین‌کننده را می‌خواند و در سقف ۳۰ ثانیهٔ PHP می‌مرد؛ نمونهٔ واقعی `POST /admin/cloud/sync` با `Maximum execution time of 30 seconds exceeded` در ردیاب موجود بود.
2. تولید فقط بخشی از پیاده‌سازی صف/رسید را داشت: کلاس‌های رسید موجود بودند، اما کنترلر هنوز کار سنگین را هم‌زمان اجرا می‌کرد، فرمان `cloud:refresh-requested` و زمان‌بندی آن نصب نبودند.
3. اگر مرورگر، تاریخچه یا لینک قدیمی با `GET` به URL عملیاتی می‌رسید، لاراول صفحهٔ عمومی ۴۰۵ نشان می‌داد. برگشت `back()` بعد از POST نیز به history/referrer وابسته بود.

نسخهٔ اصلاحی در `2026-10-28T13:15:07Z` با guard هش روی تولید نصب شد. همگام‌سازی اکنون فقط یک درخواست پایدار ثبت می‌کند و با پاسخ صریح ۳۰۳ به پنل برمی‌گردد؛ worker دقیقه‌ای روی زمان‌بند موجود APIهای متفاوت را خارج از بودجهٔ درخواست وب اجرا می‌کند. `GET /admin/cloud/sync` هرگز کار provider یا تغییر قیمت آغاز نمی‌کند و فقط با ۳۰۳ و پیام امن به `/admin/cloud` برمی‌گردد.

این انتشار migration، تغییر cron میزبان، cache flush عمومی، خرید/حذف ماشین، اجرای دستی provider یا تغییر قیمت نداشت.

## جداسازی زیرساخت‌ها و جلوگیری از تکرار

- قفل و رسید مشترک اجازه نمی‌دهد دو worker یا دو کلیک هم‌زمان کاتالوگ را موازی تغییر دهند.
- queued، running، partial، failed، interrupted، expired و completed نتیجه‌های مستقل‌اند؛ شکست یک provider به‌عنوان موفقیت کامل ثبت نمی‌شود.
- timeout، نرخ معتبر و هشدار هزینه برای هر provider داخل همان نتیجه حفظ می‌شود؛ رفتار یک زیرساخت به‌صورت کور به بقیه تعمیم داده نمی‌شود.
- POST فقط enqueue می‌کند؛ بنابراین کندی یا صفحه‌بندی API یک provider دیگر نمی‌تواند پاسخ پنل را به سقف ۳۰ ثانیه برساند.
- GET بازیابی فقط redirect است و فایل رسید `storage/app/cloud-catalog-refresh/state.json` نمی‌سازد.
- ردیاب خطا اکنون exceptionهای فرمان/زمان‌بند را از خطاهای وب جدا می‌کند؛ آرگومان فرمان و URL ساختگی HTTP برای CLI ثبت نمی‌شود.
- append/trim ردیاب زیر قفل مشترک انجام می‌شود تا workerها خطای همدیگر را پاک نکنند.
- توکن webhook بله در URL ردیاب به `[redacted]` تبدیل می‌شود.

## تطبیق با تغییرات هم‌زمان تولید

قبل از نصب، نسخهٔ فعال دانلود و با Git مقایسه شد. بستهٔ نهایی به‌جای بازنویسی کامل فایل‌ها، اختلاف‌های معتبر تولید را نگه داشت:

- `InfrastructureAuxiliaryHealth::testConnections()` در کنترلر ابری؛
- زمان‌بندی‌های Hermes و اعمال ترافیک Arvan در `routes/console.php`؛
- routeها و requireهای Hermes و webhook بله در `routes/web.php`؛
- redaction توکن webhook بله در `ErrorTracker`.

نصب با هش دقیق نسخهٔ قبل و بعد، lint هر فایل، بکاپ خصوصی و rollback خودکار محافظت شد. دو اجرای آماده‌سازی پیش از هر نوشتن متوقف شدند چون مقدار هش مورد انتظار اشتباه رونویسی شده بود؛ guard درست عمل کرد و تولید در آن دو تلاش تغییری نکرد.

## فایل‌های runtime نصب‌شده

- `app/Console/Commands/ProcessCatalogRefresh.php`
- `app/Console/Commands/SyncCloudCatalog.php`
- `app/Http/Controllers/Admin/CloudController.php`
- `app/Http/Controllers/Admin/ErrorLogController.php`
- `app/Services/Cloud/CloudCatalogSync.php`
- `app/Services/SystemHealth.php`
- `app/Support/ErrorTracker.php`
- `bootstrap/app.php`
- `resources/views/admin/cloud.blade.php`
- `resources/views/admin/errors.blade.php`
- `routes/console.php`
- `routes/web.php`

کلاس‌های `CatalogRefreshRequests` و `CatalogRefreshBusy` از قبل روی تولید وجود داشتند و دوباره بازنویسی نشدند.

## پذیرش زنده

- `route:list` هر دو مسیر را نشان داد: `GET|HEAD admin/cloud/sync` برای بازیابی امن و `POST admin/cloud/sync` برای ثبت درخواست.
- مراجعهٔ مستقیم به GET با ۳۰۳ به پنل برگشت، پیام فارسی بازیابی امن نمایش داده شد و ۴۰۵ رخ نداد.
- فایل رسید پس از این آزمون غایب ماند؛ بنابراین هیچ تماس provider یا تغییر کاتالوگ/قیمت آغاز نشد.
- فرمان و زمان‌بندی `cloud:refresh-requested` در نسخهٔ فعال دیده شدند.
- پنل خطا بخش‌های وب، فرمان/زمان‌بند و incident را جدا نشان داد. اعداد بزرگ پنل تاریخچهٔ نگه‌داری‌شده‌اند و به معنی خطاهای تازه نیستند.
- از زمان نصب تا `2026-10-28T13:16:45Z` شمار خطاهای واقعی تازهٔ ردیاب صفر بود. رکوردهای QA با timestamp آینده از این سنجش کنار گذاشته شدند.

## آزمون‌ها

پیش از مستندسازی نهایی، مجموعهٔ مرتبط با PHP 8.4.26 و SQLite ایزوله اجرا شد:

- `CatalogRefreshRequestsTest`
- `ErrorTrackerTest`
- `CloudAdminControlsTest`
- `CloudGuardsTest`
- `SystemHealthTest`

آزمون اختصاصی GET ثابت می‌کند هیچ سرویس provider، receipt یا درخواست HTTP ایجاد نمی‌شود؛ آزمون‌های POST پاسخ ۳۰۳ و idempotency صف را می‌سنجند؛ آزمون redaction نیز نبود توکن بله در رکورد ذخیره‌شده را کنترل می‌کند. نتیجهٔ نهایی: **۱۰۷ آزمون، ۳۸۵ assertion، صفر failure/error**.

## بازگشت و محدودیت

بکاپ خصوصی نصب در مسیر زیر نگه‌داری شد:

`/home/servernetcloud/.catalog-sync-rootfix-20261028/backup-20261028T131507Z.tgz`

بازگشت باید فقط با تطبیق هش نسخهٔ فعلی و از همان بکاپ انجام شود. پیش از برگرداندن route/worker، رسید queued یا running بررسی شود تا کار بی‌صاحب نماند. rollback فایل، اثر یک همگام‌سازی واقعی احتمالی را در داده/قیمت برنمی‌گرداند؛ این انتشار برای پذیرش هیچ همگام‌سازی واقعی اجرا نکرد، بنابراین چنین اثر داده‌ای ایجاد نشده است.

آزمون GET و worker خالی ایمنی مسیر را ثابت می‌کند، اما موفقیت API هر provider در همگام‌سازی آینده باید از receipt همان اجرا خوانده شود؛ یک provider خراب نباید با اجرای کور کل کاتالوگ یا دستکاری قیمت جبران شود.
