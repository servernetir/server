# انتشار ServerNet Atlas — ۱۳ اکتبر ۲۰۲۶

وضعیت جاری خانه، قالب مشترک و صفحات محصولات در [گزارش گسترش Atlas](UI-ATLAS-EXPANSION-2026-10-13.md) ثبت است. این گزارش، انتشار اولیهٔ خانه/تماس و زنجیرهٔ بازگشت تاریخی آن را نگه می‌دارد.

طراحی تأییدشدهٔ صفحهٔ اول و تماس روی [servernet.cloud](https://servernet.cloud/) منتشر شد. این گزارش بر وضعیت «محلی و منتشرنشده» در [گزارش پیاده‌سازی](UI-ATLAS-IMPLEMENTATION-2026-10-13.md) مقدم است.

## محدودهٔ انتشار و تداوم

فقط ۱۹ فایل اجرایی منتخب روی `core.servernet.cloud` نصب شدند: دو صفحه، partialهای Atlas، سه فایل ترجمه، هشت فایل تصویر/CSS/JavaScript و partial موجود آمار. اپ در `/home/servernetcloud/servernet_app` و فایل‌های عمومی در `/home/servernetcloud/public_html` هستند. نصب با کنترل SHA256 مبنا و مقصد، نوشتن اتمیک و پشتیبان خصوصی انجام شد. اجرای دوبارهٔ نصب متوقف می‌شود؛ فایل جدیدتر نیز با rollback بازنویسی نمی‌شود.

نسخهٔ مبنای تولید با سورس محلی تفاوت داشت. تغییر تازهٔ نمایش قیمت دامنه (`display`) پیش از نصب پیدا و عیناً حفظ شد؛ بستهٔ v2 با تشخیص این تفاوت متوقف شد و هیچ فایل برنامه‌ای نصب نکرد. v1/v2/v7 منتشر نشدند. نسخه‌های نصب‌شده v3، v4، v5، v6 و v8 هستند؛ آخرین بسته `release-v8` است.

کد نهایی در شاخهٔ `develop` مخزن ثبت و push شد؛ آخرین تغییر اجرایی: `ffe5886b`، پیش از آن `a6d4b8bc`، `3a6986e9`، `dcbc21d9` و `8435975a`. checkout تمیز انتشار در `C:/Users/admin/.codex/worktrees/servernet-atlas-release/servernet` است. تغییرات هم‌زمان سایر کارها حفظ و به‌صورت fast-forward/rebase ادغام شدند. partial آمارِ ریشهٔ workspace هنوز از نسخهٔ تولید عقب‌تر است؛ نسخهٔ ادغام‌شده در checkout انتشار، Git و `output/ui-atlas-live-20261013/gtm-head-atlas.blade.php` قرار دارد. whole-file upload از سورس قدیمی انجام نشود.

پس از هر نصب، Blade/PageCache تازه و OPcache وب از مسیر احراز‌شدهٔ موجود پاک شد؛ پاسخ HTTP۲۰۰ و `opcache_reset=true` تأیید شد. credential فقط در حافظه و بدنهٔ HTTPS استفاده شد و در خروجی/مستندات/URL ثبت نشد. migration، seeder، `.env`، تنظیم امنیتی، DNS، route/NAT/firewall، ماشین/guest، cron و state عامل‌های کشور/پورت تغییر نکردند. کش HTML خصوصی و Vary مربوط به session حفظ شده است.

## طراحی، سئو و سرعت

- کرهٔ جغرافیایی صفحهٔ اول و لینک واقعی پنج منطقه، تصویرسازی سرویس‌ها، AI/GPU و بخش‌های سازمانی؛ فارسی/انگلیسی/ترکی، روشن/تیره و چیدمان موبایل/دسکتاپ.
- در موبایل، SVG اولیه بدون بارگیری دادهٔ Canvas نمایش داده می‌شود؛ انتخاب کشور، متن/لینک را فوری تغییر می‌دهد و Canvas را راه‌اندازی می‌کند. در دسکتاپ راه‌اندازی به دیده‌شدن کره وابسته است؛ نقاط در شش دسته رسم می‌شوند تا تعداد عملیات رسم کم شود. خروج از دید/تب، کاهش حرکت و بازگشت صفحات کنترل شده‌اند.
- کرهٔ تماس SVG حدود۳۱KB با حرکت CSS ملایم است و Canvas یا دادهٔ جغرافیایی JavaScript ندارد. فرم در موبایل پیش از کره است؛ میانبر شناور چت هنگام دیده‌شدن فرم پنهان می‌شود.
- CSS کوچک‌شدهٔ مختص صفحات در head قرار دارد؛ فایل JS کوچک‌شده defer است. داده‌ها و تصاویر محلی‌اند؛ وابستگی اجرایی یا CDN تازه اضافه نشده است. اسکریپت‌های بازسازی دارایی‌ها در `website/scripts/build-atlas-assets.cjs` و `build-atlas-wire.py` هستند.
- یک H1، title/description موجود، canonical و hreflang سه زبان/x-default، محتوای سروررندر و لینک‌های قابل خزش حفظ شدند. WebPage و ContactPage/BreadcrumbList معتبر به موجودیت‌های اصلی سایت وصل شدند؛ امتیاز یا Offer ساختگی اضافه نشد. robots.txt و sitemap.xml پاسخ۲۰۰ داشتند.
- افت متغیر سرعت در گزارش‌ها بررسی شد. در نمونهٔ کند تماس، GTM/GA مجموعاً بیش از۲ثانیه task طولانی داشتند؛ Atlas JS در جدول عامل اصلی نبود. دو مسیر خانه/تماس در هر دو اندازه به worker موجود و آزمودهٔ سایت منتقل شدند. صف فوری رویدادها، consent، ارسال استاندارد و fallback موجود تغییر نکرد؛ شرایط سایر محصولات و صفحات سفارش/پرداخت حفظ شد. فایل‌های worker، CSP و تنظیم حساب‌های آمار تغییر نکردند.

## شواهد پذیرش

۴۹ آزمون و۴۰۹ بررسی روی snapshot تولید با PHP۸.۴.۲۶/Laravel۱۳.۱۹ اجرا شد:۴۷ آزمون موفق و دو شکست پیشینی. اجرای همان مجموعه روی صفحات اصلی بدون Atlas، همان شکست‌های `AnalyticsConsentTest::test_enabled_analytics_waits_for_consent_and_has_narrow_csp_hosts` و `FixedHeaderOffsetTest::test_no_public_page_hides_its_top_under_the_header` را داشت؛ دومی مربوط به storage-catalog است. همهٔ آزمون‌های تازهٔ Atlas، شش پاسخ صفحه، شماره‌های پشتیبانی، دامنه، فرم و سئو موفق شدند. این مجموعه به‌عنوان «همهٔ تست‌ها سبز» گزارش نمی‌شود. لاگ نهایی `output/ui-atlas-live-20261013/final-qa-v8.log` و JUnit `qa.xml` است.

سایت اصلی در عرض‌های۳۲۰/۳۶۰/۳۹۰/۷۶۸/۱۰۲۴/۱۴۴۰ کنترل شد؛ سرریز افقی یا تصویر خراب دیده نشد. هر شش صفحهٔ زبان/مسیر در۳۹۰ و۱۴۴۰ نیز مرور شد. انتخاب کشور، تغییر ماهانه/سالانه، FAQ، منوی موبایل، باز/بسته‌شدن چت و رد فرم خالی کار کردند. استعلام واقعی `example.com` نتیجهٔ «قبلاً ثبت شده» داد. پیام، تیکت، سفارش یا پرداخت واقعی برای آزمایش ثبت نشد؛ ارسال واقعی درخواست تماس و ثبت نهایی خرید در این کار اثبات نشده است.

قطع آزمایشی بارگیری `atlas-land.js` در مرورگر اختصاصی، SVG و لینک صحیح آمریکا را حفظ کرد؛ سپس محدودیت پاک شد. کاهش حرکت، animation کرهٔ تماس را `none` کرد. worker و sandbox آمار روی سایت اصلی دیده شدند؛ اثبات وصول تمام رویدادها در داشبورد حساب GA/GTM خارج از این بررسی است.

شواهد عمومی: [صحت سئو و هش هشت دارایی](output/ui-atlas-live-20261013/live-seo-assets-proof.json)، [بررسی زبان‌ها در مرورگر](output/ui-atlas-live-20261013/browser-final.json)، [کنترل۱۵مسیر عمومی](output/ui-atlas-live-20261013/public-after.json). [سند۱۹هش و کش](output/ui-atlas-live-20261013/live-proof.json) در۱۲:۱۰:۲۷UTC/۱۵:۴۰:۲۷تهران ثبت شد؛ پاسخ کش۲۰۰، OPcache=true و cache/config/route/view clear موفق بود. دکمهٔ انتخاب پلن به صفحهٔ login کنسول رسید. پنهان‌شدن چت کنار فرم موبایل و fallback آمار با قطع آزمایشی فایل bootstrap، سپس پاک‌کردن محدودیت و بازگشت worker نیز تأیید شدند. تصاویر واقعی: [خانه دسکتاپ](output/ui-atlas-live-20261013/live-home-desktop.png)، [خانه موبایل](output/ui-atlas-live-20261013/live-home-mobile.png)، [تماس دسکتاپ](output/ui-atlas-live-20261013/live-contact-desktop.png)، [تماس موبایل](output/ui-atlas-live-20261013/live-contact-mobile.png)، [تماس روشن](output/ui-atlas-live-20261013/live-contact-desktop-light-final.png).

## بازگشت

بسته‌ها، پشتیبان‌ها و receipt در `/home/servernetcloud/ui-atlas-20261013/` و خارج webroot هستند. برای بازگشت فقط worker آمار، `python3 /home/servernetcloud/ui-atlas-20261013/release-v8/deploy.py --rollback` اجرا شود؛ به نسخهٔ v6 طراحی برمی‌گردد. برای بازگشت کامل طراحی، پس از همان دستور، `--rollback` نسخه‌های v6، v5، v4 و v3 به همین ترتیب اجرا شود. هر مرحله قبل از تغییر تمام هش‌های همان مرحله را بررسی و کش را تازه می‌کند. اگر فایل جدیدتر باشد، توقف و ادغام دستی لازم است. helpers نصب با `--apply` دوباره اجرا نشوند و کش/session عمومی نشود. این بازگشت در تولید اجرا نشده است؛ پشتیبان و guards موجودند.

## حدود مقایسه

دو اجرای مستقل نهایی v8 روی هر دو صفحه و هر دو اندازه، امتیاز سرعت و سئوی یکسان داشتند:

| صفحه/اندازه | سرعت قبل | سرعت نهایی، هر دو اجرا | SEO نهایی | TBT قبل → نهایی | LCP نهایی |
|---|---:|---:|---:|---|---|
| خانه موبایل | ۹۹ | ۹۹ | ۱۰۰ | ۸۶ → ۰ms | ۱٫۹۵۴s در هر دو |
| خانه دسکتاپ | ۹۷ | ۱۰۰ | ۱۰۰ | ۱۳۹ → ۱۵/۲ms | ۰٫۴۷۷/۰٫۵۱۰s |
| تماس موبایل | ۹۹ | ۹۹ | ۱۰۰ | ۷۴ → ۰ms | ۲٫۱۰۴/۱٫۸۷۸s |
| تماس دسکتاپ | ۱۰۰ | ۱۰۰ | ۱۰۰ | ۳۵ → ۰ms | ۰٫۴۲۲/۰٫۵۳۳s |

CLS موبایل هر چهار اجرای نهایی صفر بود؛ دسکتاپ۰ تا۰٫۰۱. Accessibility خانه۹۵/تماس۹۳ و Best Practices هر دو۱۰۰ ماند. LCP موبایلِ قبلی خانه۱٫۸۷۶ و تماس۱٫۷۲۶s بود؛ بنابراین بهبود همهٔ متریک‌های بارگذاری ادعا نمی‌شود. بهبود قابل اثبات این نمونه‌ها، TBT و سرعت دسکتاپ خانه، همراه با حفظ امتیاز بالای موبایل و طراحی جدید است. امتیاز۱۰۰ سئو، آزمون پایهٔ Lighthouse است و تمام عوامل رتبه‌بندی را پوشش نمی‌دهد.

گزارش‌های [خانه قبل](https://pagespeed.web.dev/analysis/https-servernet-cloud/g4ht0w5rxc?form_factor=mobile) و [تماس قبل](https://pagespeed.web.dev/analysis/https-servernet-cloud-contact/swmik08zg4?form_factor=mobile)؛ نهایی [خانه۱](https://pagespeed.web.dev/analysis/https-servernet-cloud/lkl6o3xgms?form_factor=mobile)، [خانه۲](https://pagespeed.web.dev/analysis/https-servernet-cloud/vrc807vw2o?form_factor=mobile)، [تماس۱](https://pagespeed.web.dev/analysis/https-servernet-cloud-contact/8p6wl61z7g?form_factor=mobile)، [تماس۲](https://pagespeed.web.dev/analysis/https-servernet-cloud-contact/hegji0emu2?form_factor=mobile). دادهٔ دقیق calculator و خروجی DOM در `pagespeed-final.json`/`pagespeed-repeat.json` و `v8-*.txt` ذخیره‌اند.

PageSpeed Insights/Lighthouse۱۳.۵ برای قبل/بعد و دو نمونهٔ بازار استفاده شد؛ API بدون کلید۴۲۹ داد، گزارش رسمی رابط PageSpeed قابل استفاده بود. امتیازها آزمایشگاهی و متغیرند. trace محلی ناقص معیار نهایی نیست. قبل از تغییر، خانه موبایل۹۹/دسکتاپ۹۷ و تماس موبایل۹۹/دسکتاپ۱۰۰ بود؛ SEO هر چهار۱۰۰. نسخه‌های میانی افت داشتند و به‌عنوان نتیجهٔ نهایی انتخاب نمی‌شوند.

[پارس‌پک](https://pagespeed.web.dev/analysis/https-parspack-com/1rd17sfw4g?form_factor=mobile): سرعت موبایل۴۷/دسکتاپ۵۹، SEO۹۲؛ [لیارا](https://pagespeed.web.dev/analysis/https-liara-ir/ldddw10j9s?form_factor=mobile): سرعت موبایل۵۱/دسکتاپ۸۲، SEO۱۰۰. این دو نمونه نمایندهٔ کل رقبا نیستند. دادهٔ واقعی۲۸روزهٔ موبایل ServerNet پیش از انتشار، origin و Failed با LCP۲٫۵s/INP۱۸۰ms/TTFB۱٫۲s بود؛ دادهٔ واقعی لیارا و پارس‌پک Passed بود. بنابراین برتری قطعی همهٔ تجربه‌های واقعی یا رتبه‌های جستجو از این آزمایش‌ها نتیجه نمی‌شود و دادهٔ۲۸روزه فوراً پس از انتشار عوض نمی‌شود.
