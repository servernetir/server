# تکمیل اصلاح جابه‌جایی ساعتی — ۱۰ اکتبر ۲۰۲۶

این مرحله پس از گزارش `SEO-TBT-STABILITY-2026-10-10.md` و ورود دوبارهٔ مالک انجام شد؛ وضعیت «آماده/منتظر نشست» در گزارش قبلی با انتشار این مرحله برطرف شده است. worker نهایی v3 همان نسخهٔ فعال قبلی است.

## تغییر منتشرشده و حفظ تغییرهای هم‌زمان

روی `core` / `65.109.176.14`، تنها `resources/views/pages/vps-hourly.blade.php` در `/home/servernetcloud/servernet_app` تغییر کرد: همان stylesheet پایین content بدون تغییر حتی یک rule، با `@push('head')` / `@endpush` به stack موجودِ پیش از `</head>` منتقل شد. خطر paint پیش از دریافت سبک‌ها کاهش یافت؛ از این نتیجه علت انحصاریِ تمام CLSهای قبلی نتیجه نمی‌شود. فونت، محتوا، مبلغ، خرید و آمار در این مرحله تغییر نکردند.

snapshot تازهٔ تولید با نسخهٔ محلی متفاوت بود؛ تغییر فقط روی فایل تازه اعمال شد و featureهای جاری از جمله credit-lifecycle حفظ شدند. در local فقط همین دو directive روی نسخهٔ موجود افزوده شد؛ فایل کامل تولید یا layout دیگری روی local/تولید جایگزین نشد.

هش قبل `3eed8d1466232b9b370f7021e26af38254067b27772d3c3c72ac49d92c5464c0` و بعد `6a73fae2f7645bb2fd60b8dc7442b66444fec14c9c09c74be9d17dcea1f6feec` است. بستهٔ `seo-hourly-head-20261010-v4.zip` با SHA256 `cdbb57f0854668883d96fddb40841a36571dda1fca154cd98653112e3cfab6a4` بررسی شد؛ Blade compile/PHP lint و hash guard فایل و layout، پشتیبان و نصب اتمی، `SEO_DEPLOY_OK 1` و پاک‌سازی view cache موفق بودند.

## سنجش‌های پس از انتشار

پنج اجرای مستقل و سریالی ساعتی و یک کنترل تازهٔ GPU؛ Moto G Power / Slow 4G / Lighthouse 13.5.0. تمام نتیجه‌ها نگهداری شدند. TBT ساعتی در هر پنج صفر، LCP بین ۱٫۶۵۴–۱٫۶۶۴ ثانیه و CLS همه صفر است. نتیجهٔ پنج GPU مرحلهٔ v3 همچنان ۰–۳ms بود؛ کنترل تازه نیز در جدول آمده است.

| صفحه | تکرار | TBT ms | LCP s | CLS | شاهد |
| --- | --- | ---: | ---: | ---: | --- |
| GPU کنترل | 1 | 1 | 1.503 | 0.0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-gpu/emza4vh382?form_factor=mobile) |
| ساعتی | 1 | 0 | 1.654 | 0.0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-vps-hourly/tdi1nsrkaa?form_factor=mobile) |
| ساعتی | 2 | 0 | 1.657 | 0.0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-vps-hourly/7pf21tn6f6?form_factor=mobile) |
| ساعتی | 3 | 0 | 1.664 | 0.0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-vps-hourly/3pscawcylu?form_factor=mobile) |
| ساعتی | 4 | 0 | 1.664 | 0.0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-vps-hourly/epxgx27x4y?form_factor=mobile) |
| ساعتی | 5 | 0 | 1.659 | 0.0 | [PageSpeed](https://pagespeed.web.dev/analysis/https-servernet-cloud-vps-hourly/cr511s0ivw?form_factor=mobile) |

این ثبات محدود به این سنجش‌هاست؛ دادهٔ واقعی ۲۸روزه فوراً عوض نمی‌شود و تضمین دائمیِ همهٔ دستگاه‌ها/شبکه‌ها نیست. نمونهٔ بد ۰٫۲۴۰ مرحلهٔ قبل حذف نشد.

## تأیید و محدودیت آزمون

- در هر سه زبان FA/EN/TR پاسخ عمومی ۲۰۰، همان CSS دقیقاً یک بار داخل head و پیش از body، helper ثبت آمار و لینک‌های خرید موجود تأیید شدند. شش URL ساعتی/GPU و شش asset فعال worker نیز مجدداً status/MIME/hash صحیح داشتند (`PUBLIC_VERIFY_OK 12`).
- صف رویدادها/تبدیل مبلغ خرید و transport آمار، شواهد مرحلهٔ v3 را دارند؛ این مرحله هیچ اسکریپت آمار یا خریدی تغییر نداد. خرید واقعی/پرداخت یا purchase ساختگی در این نوبت ارسال نشد.
- پیشنهاد قبلی روی snapshot قدیمی، ۱۲ تست/۴۸ assertion موفق داشت. تست‌های Feature قدیمی با snapshot تازه در محیط ایزوله ۸ شکست/۴ موفق داشتند؛ اجرای همان ۱۲ تست روی فایل تازهٔ **بدون اصلاح** دقیقاً همان ۸ شکست را داشت. علت‌های مشاهده‌شده شامل partial جدید credit-lifecycle غایب در isolate و قراردادهای قدیمی نمایش/خرید بود. آن سوئیت مبنای ادعای قبولی نسخهٔ تازه نیست؛ پذیرش این تغییر بر حفظ دقیق فایل خارج از دو directive، compile/lint تولید، رندر عمومی سه زبان و سنجش پس از نصب است. جزئیات مقایسه در `hourly-head-test-limitations.json` و JUnitهای baseline/final محفوظ است.

## ماندگاری و بازگشت

پشتیبان همین view در `/home/servernetcloud/seo-hourly-head-20261010-v4/backup/resources/views/pages/vps-hourly.blade.php` است. بازگشت فقط این تغییر: `python3 /home/servernetcloud/seo-hourly-head-20261010-v4/deploy.py --rollback`؛ همهٔ هش‌ها باید مطابق و فایل بدون تغییر هم‌زمان باشد. این دستور worker را تغییر نمی‌دهد.

بازگشت مستقیم مجموعهٔ worker + این view: `python3 /home/servernetcloud/seo-hourly-head-20261010-v4/rollback-final.py --check` و فقط برای لغو واقعی `--apply`. کنترل ۱۴ فایل/پشتیبان با `HOURLY_TBT_COMBINED_ROLLBACK_GUARDS_OK_14` موفق بود. اول view ساعتی به snapshot تازهٔ پیش از همین تغییر برمی‌گردد، سپس worker به head اولیه و assetهای جدید با guard جمع می‌شوند. برای بازگشت قدیمی محصولات، ابتدا همین بازگشت مجموعه لازم است. helperهای انتشار را کورکورانه تکرار نکنید.

هیچ VM/مهمان، route، NAT/firewall، DNS، cron، schema/رکورد مشتری، عامل country-routing/port-forward یا state آن تغییر نکرد. ماندگاری فقط در یک Blade و view cache است؛ rollback عامل‌های زیرساخت موضوعیت ندارد. رمز، توکن و URL نشست در مستندات ذخیره نشد.

پایش روزانهٔ موجود `servernet` ادامه دارد؛ نمونه‌های بعدی و LCP/INP واقعی، دریافت آمار و سفارش با دسترسی مجاز بررسی شوند. اتوماسیون تکراری ساخته نشد. شواهد و تصویر خروجی در `output/seo-tbt-20261010/` هستند.
