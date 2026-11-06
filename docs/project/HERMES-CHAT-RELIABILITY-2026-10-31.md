# اصلاح خطای گفتگوی مینی‌اپ بله و گزارش منابع هرمس

درخواست مالک،۳۱اکتبر۲۰۲۶: پیام ساده با `405 GET ... v1/chat/completions` رد می‌شد و گزارش عملیات هشت بار «منبع بدون سرویس متصل» را بدون شناسه تکرار می‌کرد.

## علت و اصلاح

POST بدون کلید به `console.servernet.cloud/v1/chat/completions` با۳۰۱ به دامنه اصلی منتقل شد. دنبال‌کردن ریدایرکت،متد را GET کرد و دقیقاً همان۴۰۵ را بازتولید کرد. درخواست مستقیم به دامنه اصلی۴۰۱ `invalid_token` می‌گرفت. `ConsoleHost::CONSOLE_EXTRA` مسیر `api/*` را مستثنا کرده بود ولی `v1/*` را نداشت.

استثنای `v1/*` اضافه شد؛ متد،JSON،query و Bearer روی همان میزبان حفظ می‌شوند. پذیرش زنده پس از نصب،POST روی هر دو میزبان و GET مدل‌ها روی کنسول را بدون Location با۴۰۱ رد کرد. احراز،بودجه،مصرف،CSRF و قرارداد مالی تغییر نکردند؛ ریدایرکت‌های بازاریابی/پنل حفظ شدند.

`HermesAlertSummary` گزارش خصوصی مدیر را بر اساس code/provider گروه‌بندی می‌کند: تعداد،نام زیرساخت،حداکثرسه شناسه منبع/سرویس،کران متن و شمار گروه‌های باقی‌مانده. توضیح صریح اضافه شد که منبع تطبیق‌نخورده ممکن است داخلی/آزمایشی یا نیازمند اصلاح اتصال باشد و این هشدار هزینه قطعی یا اختیار حذف نمی‌سازد. classification،ack،fingerprint و deduplication تغییر نکردند.

## نسخه و دامنه

SHA مبنا: `fc68971351fd17f2f5e18a3852f000108e56531a`، شاخه `develop`. سه مقصد runtime:

| منبع | مقصد در اپ |
|---|---|
| `website/app/Http/Middleware/ConsoleHost.php` | `app/Http/Middleware/ConsoleHost.php` |
| `website/app/Services/Hermes/HermesAlertSummary.php` | `app/Services/Hermes/HermesAlertSummary.php` |
| `infra/hermes/chat-reliability-20261031/HermesOperations.php` | `app/Services/Hermes/HermesOperations.php` |

overlay از نسخه مهرشده۳۰اکتبر است و فقط بلوک نمایش digest را عوض می‌کند؛ بسته قبلی تغییر نکرد. [manifest](../../infra/hermes/chat-reliability-20261031/release-manifest.json) هش‌های پیش/پس و [راهنما](../../infra/hermes/chat-reliability-20261031/README.md) نصب/بازگشت را ثبت می‌کنند. اسکریپت‌ها endpoint عمومی یا cron نیستند.

## آزمون‌های اجراشده

- PHP8.4.27/PHPUnit12.5.31،فایل صریح `ConsoleHostTest.php` در checkout جدا:۲۱آزمون/۷۲assertion موفق؛ حفظ متد/بدنه/Bearer،رد واقعی بدون کلید و ریدایرکت‌های قبلی. تماس پولی/پیام واقعی در این آزمون‌ها صفر بود.
- هارنس Composer-only `website/tests/standalone/hermes-chat-reliability.php`:۳۲بررسی موفق؛ کلاس واقعی middleware/digest با store/sender ساختگی،بدون bootstrap تولید،داده زنده یا مدل؛ شامل grouping،کران،شناسه،inactive/ack و deduplication.
- `python3 infra/hermes/chat-reliability-20261031/test_deploy.py`:۱۲آزمون موفق؛ drift،symlink،cron،شکست lint،نصب ناقص،بازگشت و حفظ تغییر دیگران.
- lint فایل‌های انتشار/caller و `git diff --check` موفق.

اجرای اولیه با کشف گسترده `--filter ConsoleHostTest` پیش از نتیجه با `Premature end of PHP process` تمام شد؛ اجرای فایل صریح بالا موفق بود. suite کامل اپ تأیید نشده است.

## پذیرش تولید و بازگشت

انتشار `hermes-chat-reliability-20261031` با قفل native review/operations و دو قفل مشترک انتشار،پشتیبان خصوصی،hash و lint نصب و verify شد؛ cron ثابت ماند. رسید CLI در `storage/app/private/hermes-chat-reliability-20261031/acceptance.json` است. محتوا و شناسه موجودی خصوصی وارد Git نشدند.

گزارش طبیعی۰۹:۰۰تهران در بله واقعاً قالب جدید را داشت؛ گزارش دستی/زمان‌بند دوم ساخته نشد. در۰۹:۰۳،از دکمه واقعی بازکردن مینی‌اپ بله،در همان تاریخچه یک پیام تشخیصی حداقلی ارسال و پاسخ واقعی «اتصال برقرار است» دریافت شد. حافظه/تاریخچه پاک نشد؛ `/new`،تغییر مدل،کلید یا ری‌استارت VM لازم نشد. بازکردن مستقیم خارج ظرف بله با SDK آن خطا داشت؛ پذیرش از ظرف واقعی،بدون جعل initData یا دورزدن احراز انجام شد.

وضعیت: **نصب و در تولید تأیید شد**. پذیرش،ارتباط مدل و نمایش گزارش را اثبات می‌کند؛ طبقه‌بندی تک‌تک منابع،هزینه واقعی یا سلامت همه زیرساخت‌ها بررسی نشده‌اند. یک پیام آزمون به هرمس مدیر ارسال شد؛ پیام مشتری،خرید/حذف،تغییر کیف‌پول/فاکتور،شبکه،cron یا دسترسی تازه اجرا نشد.

بازگشت: caller خصوصی با `rollback` کل مجموعه را پیش از نوشتن کنترل می‌کند،دو فایل قبلی را برمی‌گرداند و فقط formatter تازه همین انتشار را با hash دقیق حذف می‌کند. ویرایش جدیدتر باعث توقف است؛ state،تاریخچه،حافظه و رسید ارسال برنمی‌گردند.
