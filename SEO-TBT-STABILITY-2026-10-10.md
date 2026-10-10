# پایداری اجرای اسکریپت‌های موبایل ساعتی و GPU — ۱۰ اکتبر ۲۰۲۶

**تکمیل بعدی همان روز:** اصلاح CSS ساعتی پس از snapshot تازه منتشر شد؛ پنج سنجش جدید TBT/CLS صفر و LCP=1.654–1.664s، کنترل GPU TBT=1ms. وضعیت «منتظر نشست» در شرح تاریخی پایین برطرف شده است. نتیجهٔ تازه، محدودیت آزمون و بازگشت مجموعه با guard ۱۴ فایل در [گزارش تکمیلی](SEO-HOURLY-HEAD-2026-10-10.md) مقدم است.

اصلاح روی وب زنده `servernet.cloud` منتشر شد. در نسخهٔ نهایی، پنج سنجش مستقل و متوالی برای هر صفحه انجام شد؛ هیچ نتیجهٔ کامل نسخهٔ نهایی از جدول حذف نشده است. هدف TBT کمتر از ۲۰۰ms در هر ده سنجش محقق شد. این نتیجهٔ آزمایش موبایل است؛ تضمین همهٔ دستگاه‌ها/شبکه‌ها یا رتبهٔ گوگل نیست. مقایسه با مرحلهٔ قبلی در [گزارش محصولات موبایل](SEO-PRODUCT-MOBILE-2026-10-10.md) است.

## تغییر منتشرشده

- فقط `/vps/hourly` و `/gpu` و معادل‌های EN/TR در عرض حداکثر ۱۰۲۰px از اجرای پس‌زمینهٔ GTM/GA4 استفاده می‌کنند. کتابخانهٔ رسمی `@qwik.dev/partytown@0.15.0` از npm، با lock و MIT License، روی دامنهٔ خود سایت قرار گرفت؛ آمار حذف یا مسدود نشد. کد رابط، قیمت، اعتبارسنجی، حساب و پرداخت روی مسیر عادی باقی است.
- صف `dataLayer`، API `ServerNetAnalytics`، consent موجود، payload و تبدیل تومان به IRR حفظ شدند. `dataLayer.push` همراه رفتار اصلی به worker منتقل می‌شود. در دسکتاپ، حساب، سفارش و مرورگر بدون worker، همان بارگذاری عادی قبلی اجرا می‌شود.
- در خرابی فایل bootstrap یا راه‌اندازی worker، بازگشت به GTM عادی با حفظ همان صف پیش‌بینی شده است. fallback کتابخانهٔ اصلی صف forwardشده را پاک می‌کرد؛ bootstrap محدوداً برای بازیابی صف اصلاح شد. timeout برابر ۴ ثانیه است؛ مخفی‌کردن هزینه با تأخیر طولانی معیار پذیرش نبود.
- ثبت سلامت فقط نام عمومی رویداد، نوع درخواست، وضعیت پاسخ و مدت آن را در حافظهٔ مرورگر، حداکثر ۲۰ مورد، نگه می‌دارد. URL کامل، شناسهٔ کاربر، قیمت/اقلام خرید، کوکی یا بدنهٔ درخواست در آن نگهداری نمی‌شود. worker پیام‌رسان خود را در زمان init حذف می‌کند؛ نسخهٔ نهایی تابع اولیهٔ پیام‌رسان را از قبل حفظ کرده و خطای رصد نمی‌تواند پاسخ fetch را خراب کند.
- مسیر فعال assetها `/assets/js/snet-analytics-worker-0.15.0-snet3/` است. امنیت CSP، cookies، OAuth، دامنه، DNS و فایروال برای این تغییر ضعیف یا گسترده نشدند.

مبنای فنی: [GTM در Partytown](https://partytown.qwik.dev/google-tag-manager/)، [حفظ صف و preserveBehavior](https://partytown.qwik.dev/forwarding-events/)، [fallback و تنظیمات](https://partytown.qwik.dev/configuration/) و [قرارداد dataLayer گوگل](https://developers.google.com/tag-platform/tag-manager/datalayer).

## نتایج نسخهٔ نهایی

Moto G Power، Slow 4G، Lighthouse 13.5.0، نشست اولیهٔ مستقل، اجراهای سریالی بدون آزمون هم‌زمان. اعداد دقیق TBT/LCP از لینک calculator گزارش خوانده شدند؛ CLS در آن گرد شده است؛ در یک اجرای ساعتی ۰٫۲۴۰ و در سایر اجراهای نهایی صفر دیده شد.

| صفحه | تکرار | TBT (ms) | LCP (s) | CLS | گزارش مستقل |
| --- | --- | ---: | ---: | ---: | --- |
| GPU | 1 | 0 | 1.511 | 0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-gpu/i4y5jtcxe4?form_factor=mobile) |
| GPU | 2 | 3 | 1.514 | 0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-gpu/1pin6cotu2?form_factor=mobile) |
| GPU | 3 | 0 | 1.504 | 0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-gpu/eeuzcxzxuy?form_factor=mobile) |
| GPU | 4 | 0 | 1.507 | 0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-gpu/ob5ihyxdrt?form_factor=mobile) |
| GPU | 5 | 0 | 1.503 | 0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-gpu/q66b261qgy?form_factor=mobile) |
| ساعتی | 1 | 25 | 1.687 | 0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-vps-hourly/ej7v4nrll0?form_factor=mobile) |
| ساعتی | 2 | 0 | 1.693 | 0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-vps-hourly/iu84uosxea?form_factor=mobile) |
| ساعتی | 3 | 0 | 1.658 | 0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-vps-hourly/lps90b9w0f?form_factor=mobile) |
| ساعتی | 4 | 0 | 1.655 | 0.24 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-vps-hourly/q5wkuawgpa?form_factor=mobile) |
| ساعتی | 5 | 0 | 1.660 | 0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-vps-hourly/7toxb9kb94?form_factor=mobile) |

خط پایهٔ تازهٔ این نوبت: ساعتی TBT=109ms، LCP=1.651s و CLS=0.240؛ GPU TBT=168ms، LCP=1.504s و CLS=0. در بررسی قبلی TBT ساعتی تا 487ms و GPU حدود 290–407ms نوسان داشت؛ بنابراین یک اجرای خوب برای ادعای پایداری کافی نبود. در baseline ساعتی عنصر `.hv-head` عامل جابه‌جایی گزارش شد؛ علت نهایی آن جابه‌جایی از این یک نمونه اثبات نشده است. CSS/فونت در این مرحله دوباره تغییر نکردند؛ CLS در نه نتیجهٔ نهایی صفر و در یک تکرار ساعتی ۰٫۲۴۰ بود؛ بنابراین جابه‌جایی ساعتی هنوز کاملاً پایدار نیست. استایل اختصاصی ساعتی در انتهای content قرار دارد و می‌تواند بعد از paint ظاهر صفحه را تغییر دهد. انتقال همان stylesheet به stack موجود head در پیشنهاد محلی آماده و ۱۲ تست/۴۸ assertion صفحهٔ ساعتی در محیط ایزوله موفق شد. این اصلاح هنوز منتشر نشده: نشست cPanel در پایان با Invalid Security Token/صفحهٔ ورود قطع شد و ورود دوباره از مالک درخواست شده است. قبل از انتشار، snapshot زندهٔ همان view، hash guard و backup لازم است؛ سپس CLS و TBT/LCP دوباره اندازه‌گیری شوند. خود فونت در این پیشنهاد تغییر نمی‌کند.

دو درخواست سنجش ساعتی با پیام «Unable to resolve» سرویس گوگل بدون نتیجهٔ Lighthouse پایان یافت؛ فایل‌های خطا حفظ شدند. دسترسی HTTP عمومی شش URL و شش asset هم‌زمان موفق بود. پس از تغییر صفحهٔ سنجش و بازگشت، آزمون ساعتی کامل شد. خطای بدون سنجش به‌عنوان صفر یا نتیجهٔ موفق شمرده نشده است.

## تأیید ثبت آمار و خرید

- تست Node نسخهٔ نهایی ده مسیر را اجرا کرد: GPU، EN/TR ساعتی، دسکتاپ، سفارش، حساب، محصول دیگر، مرورگر بدون worker، خطای bootstrap و fallback واقعی کد Partytown. صف قبل از init و بعد از fallback حفظ شد؛ `begin_checkout` با ۱۹۵۰۰ تومان به ۱۹۵۰۰۰ IRR تبدیل شد و رویداد بعدی نیز به همان صف اضافه شد.
- در checkout ایزوله، بدون `.env`، config cache، دیتابیس زنده یا اطلاعات مشتری، PHP 8.4.26 و SQLite حافظه‌ای: `DataLayerServiceTest` و `AnalyticsDataLayerCatalogTest`، پنج تست و ۳۶ assertion موفق. مبلغ/مالیات خرید، transaction_id، نبود PII در payload، قیف فاکتور، signup/login، رندر helper و غیرفعال‌کردن analytics/CSP بررسی شدند. محیط آزمایشی ابتدا به کلید و نام درست تنظیمات آزمایش نیاز داشت؛ این تنظیمات فقط در پوشهٔ ایزوله اصلاح شدند. سوئیت کامل یا آزمون قدیمی کوکی consent مبنای این تأیید نیست.
- ابزار viewport مرورگر در این جلسه عرض درخواست‌شده را اعمال نکرد؛ Runtime همچنان 1920px نشان داد. در نتیجه آن مشاهده به‌عنوان تست واقعی موبایل گزارش نشد. برای کنترل transport، صفحهٔ موقت و noindex از همان head رندرشدهٔ عمومی GPU ساخته شد، worker فقط در آن fixture فعال و debug_mode تنظیم شد. فایل active worker و GTM همان نسخهٔ تولید بود. GTM/GA4 با پاسخ CORS 200 بار شدند؛ POSTهای `page_view` و `scroll` با fetch تکمیل‌شدهٔ `opaque` و بدون خطای runtime مشاهده شدند. status صفرِ opaque، کد HTTP صفر یا توقف آمار نیست؛ کد پاسخِ no-cors قابل خواندن نیست. ورود این رویدادها در رابط GA4 جداگانه مشاهده نشده است.
- صفحهٔ موقت پس از ثبت شاهد با hash guard جمع‌آوری شد؛ سفارش، پرداخت یا رویداد purchase ساختگی ایجاد نشد. ثبت یک خرید واقعی در GA4 ادعا نمی‌شود؛ مسیر سفارش و قرارداد رویداد خرید با تست و حفظ کد تأیید شدند.
- شش صفحهٔ عمومی FA/EN/TR و شش فایل فعال، status/MIME/SHA256 صحیح داشتند. local فقط head و dependency جدید با کنترل هش همگام شد؛ تغییرات layout و سایر کارهای هم‌زمان حفظ شدند.

## انتشار، ماندگاری و بازگشت

میزبان `core`، برنامهٔ `/home/servernetcloud/servernet_app` و webroot `/home/servernetcloud/public_html`. تنها template `resources/views/partials/gtm-head.blade.php` و dependencyهای public تغییر کردند؛ view cache پاک شد. نسخهٔ فعال v3 است. v1 اولیه سه سنجش TBT=0 داشت؛ v2 رصد آزمایشی با چرخهٔ worker سازگار نبود و یک سنجش ساعتی 239ms داشت، پس با پیام `SEO_ROLLBACK_OK` بازگشت داده شد. نتیجهٔ آن در `rejected-v2-hourly.txt` باقی است و در جدول نسخهٔ نهایی شمرده نشده است. نسخهٔ v2 را منتشر نکنید.

پشتیبان اولیهٔ head: `/home/servernetcloud/seo-tbt-20261010-v1/backup/resources/views/partials/gtm-head.blade.php`. بسته‌ها، manifestها و سابقهٔ private در پوشه‌های `seo-tbt-20261010-v1/v2/v3` زیر home میزبان هستند؛ staging دوباره نصب نشود. assetهای v1 نیز برای بازگشت v3 محفوظ‌اند؛ در صفحات از مسیر v3 استفاده می‌شود. صفحهٔ verification بازگشت داده شده و در public باقی نیست.

بازگشت مستقیم همین مرحله: `/home/servernetcloud/seo-tbt-20261010-v3/rollback-final.py --check` سپس فقط در صورت نیاز `--apply`. helper هش ۱۳ فایل جاری و backup اولیه را کنترل می‌کند، head پیش از worker را بازمی‌گرداند، view cache را پاک می‌کند و dependencyهای مخصوص همین مرحله را از public جمع می‌کند؛ نسخه‌ها در بستهٔ خصوصی محفوظ‌اند. `--check` با پیام `TBT_DIRECT_ROLLBACK_GUARDS_OK 13` موفق شد، بدون اعمال بازگشت. اگر drift باشد، توقف و snapshot تازه لازم است.

برای بازگشت مرحلهٔ قدیمی چهار فایل محصولات، اول همین بازگشت مستقیم worker لازم است؛ سپس helper مستقیم محصولات از گزارش قبلی قابل بررسی است. بازگشت فقط v3، head را به v1 worker برمی‌گرداند و لغو کامل worker نیست. Service Worker فقط محدودهٔ مسیر asset کتابخانه را دارد و کنترل مسیر محصول، حساب یا پرداخت را نمی‌گیرد؛ ثبت محدود آن ممکن است در مرورگر قبلاً بازدیدکرده باقی بماند، ولی head بازگشته آن را راه‌اندازی نمی‌کند.

هیچ ماشین/مهمان، route شبکه، NAT/firewall، عامل country-routing/port-forward، cron یا schema/دادهٔ مشتری تغییر نکرد. هیچ token، password، UUID احراز هویت، URL کامل VPN یا کلید خصوصی در این گزارش نگهداری نشده است.

## حدود نتیجه و پایش

پنج تکرار هر صفحه نشان‌دهندهٔ پایداری در این نوبت و دستگاه شبیه‌سازی‌شده است. مرورگر فاقد worker یا fallback واقعی می‌تواند هزینهٔ اصلی GTM را دوباره داشته باشد. هر tag جدید در GTM، تغییر UI، دستگاه کندتر یا شرایط شبکه باید دوباره با تکرار و کنترل آمار سنجیده شود؛ نتایج بد حذف نشوند. TBT شاخص آزمایش است؛ INP و LCP کاربران واقعی و گروه GSC نیز باید در دورهٔ بعد بررسی شوند. دادهٔ ۲۸روزهٔ origin در این نوبت هنوز LCP=2.6s و INP=185ms بود؛ با یک انتشار فوراً جایگزین نمی‌شود.

پایش روزانهٔ موجود `servernet` از `seo/WORK-QUEUE.md` و این گزارش استفاده می‌کند؛ اتوماسیون تکراری ساخته نشد. در نوبت بعد دو سنجش مستقل از هر صفحه، حفظ دریافت آمار و آمار سفارش واقعی با دسترسی مجاز بررسی شود؛ تغییر آمار/خرید یا TBT≥200 به بررسی علت و اقدام منجر شود. در نبود تغییر معنادار، دستکاری یا پیام روزانه لازم نیست.

شواهد: `output/seo-tbt-20261010/` شامل snapshot، lock رسمی، manifestها، تمام نتایج، خطاها، `final-summary.json`، `public-verification.json`، `analytics-final-junit.xml`، `analytics-worker-health.txt` و تصاویر proof است. نسخهٔ نهایی تست‌های Node در `scripts/seo-tbt-worker-test.cjs` و تست حفظ سلامت worker در `output/seo-tbt-20261010/test-health.cjs` است.
