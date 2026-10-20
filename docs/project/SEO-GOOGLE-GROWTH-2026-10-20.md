# اجرای رشد ورودی گوگل ServerNet — ۲۰ اکتبر ۲۰۲۶

وضعیت: **تغییرات کد و محتوای بازبینی‌شده در Git آماده‌اند؛ روی تولید نصب نشده‌اند.**

- مرجع توسعه: develop؛ مبنای نهایی شاخه: facc59c6ca5b7600b8609b1796979af79ba5745f.
- SHA کد ثبت‌شده در شاخه PR: 17f06356ddffd4cc84833bd9c4beb870dceb2e56. tree آن با commit محلی آزموده‌شده b8c08a54a78097cc977475f2f27953a7b03d7ae3 برابر است.
- شاخه: codex/google-growth-20261020.
- PR: [#89](https://github.com/servernetir/server/pull/89) — پیش‌نویس، ادغام نشده.
- تاریخ گزارش ورودی: ۲۰ اکتبر ۲۰۲۶. زمان ثبت گزارش اجرا: 2026-10-20T13:09:35.204Z. این زمان، تاریخ انتشار نیست.
- دامنه تغییر: توضیحات محصول، سئو، راهنماهای CMS، لینک داخلی و مرحله کلیک CTA. منطق مالی، قیمت‌گذاری، پرداخت، حذف، استرداد، شبکه و زیرساخت تغییر نکرده‌اند.

## منبع داده و محدودیت اصلی

متن کامل مأموریت همراه پیوست خوانده شد. فایل servernet_google_growth_2026-10-20.xlsx با اندازه ۷۱۹٬۱۰۸ بایت شناسایی شد، اما خواندن متن آن محتوای قابل خواندن برنگرداند. مسیر لازم دریافت فایل نیز به دلیل در دسترس نبودن ابزار همراه مسدود ماند. **هیچ شیت یا ردیف این اکسل خوانده‌شده تلقی نمی‌شود.** بنابراین تاریخ دقیق دو بازه، عبارت‌های کامل، ردیف‌های افت و شناسه‌های ۱۲ اقدام اکسل مستقلاً تأیید نشده‌اند.

فایل action-register.csv شامل **۱۲ بسته اجرایی استخراج‌شده از متن مأموریت** با شناسه B است؛ این شناسه‌ها جای شناسه‌های اکسل معرفی نمی‌شوند. برای بستن پذیرش داده، باید همه شیت‌ها و به‌خصوص برنامه اقدام با این ثبت تطبیق داده شوند.

اولویت صفحات از متن مأموریت گرفته شد؛ آمار خصوصی Search Console و فایل خام در مخزن عمومی کپی نشده‌اند. تاریخ بازه، حجم جست‌وجوی بازار، KD، بک‌لینک یا علت افت حدس زده نشده است. نمایش با حجم جست‌وجوی بازار، کلیک با بازدیدکننده یکتا و ردیف غایب با صفر یکی نشده‌اند.

اتصال GSC Wizard نیز خطای subscription_required داد؛ URL Inspection و داده تاریخی از این مسیر دریافت نشد. برای این کار اشتراک خریداری یا تنظیم حساب تغییر داده نشد. داده Ahrefs در این مأموریت دریافت نشده است.

## تغییرات محصول و دلیل آن‌ها

### سرور ساعتی فارسی، انگلیسی و ترکی

خواندن کد واقعی نشان داد که نرخ فروش از CloudPlan می‌آید و همواره ماهانه تقسیم بر ۷۲۰ نیست. شرط اعتبار پایه، ذخیره نگهداری و تعهد سرویس‌های دیگر نیز جدا هستند. مسیر معمول VPS ساعت اول را در ثبت سفارش کسر می‌کند؛ متر ساعت‌های بعد، تحویل واقعی را شرط می‌گذارد و زمان انتظار تحویل را به مصرف اضافه نمی‌کند. GPU صف ظرفیت، ساعت اول را پس از آماده‌بودن برنامه کسر می‌کند. این دو مسیر در متن با هم یکی نشده‌اند.

ادعاهای صرفاً زمان روشن بودن، ارزان‌تر بودن قطعی ساعتی، تحویل قطعی در چند دقیقه و اعتبار شروع برابر با هزینه نهایی اصلاح شدند. خاموشی معمولی VPS پایان رزرو منابع نیست. حذف پس از دریافت داده‌ها، نگهداری محدود پس از پایان اعتبار و احتمال مصرف ذخیره نگهداری توضیح داده شدند. عنوان، توضیح متا و تیتر خرید در سه زبان هماهنگ‌اند.

مثال‌های ۶، ۲۴ و ۷۲ ساعت اکنون از همان نرخ نمایشی پلن در ارز زبان ساخته می‌شوند. تبدیل دوباره مبلغ تومانی به یورو می‌توانست با نرخ ساعتی گرد‌شده یورویی اختلاف داشته باشد؛ فقط محاسبه نمایشی اصلاح شده و مدل یا صورتحساب تغییر نکرده است. این اختلاف با یک fixture که دو عدد متفاوت تولید می‌کند آزموده شد.

### GPU و GPU VPS

/gpu محصول برنامه‌محورِ دارای دسترسی HTTPS و دیسک موقت است. /vps/gpu صفحه بررسی نیاز VM، سیستم‌عامل، دسترسی مدیریتی و ماندگاری داده باقی مانده است. موجودی GPU VM مستقل در این مأموریت تأیید نشده؛ متن آن به تأیید مشخصات و پیشنهاد همان سرویس متکی است. URLها، canonical و وضعیت ایندکس حفظ شده‌اند؛ هم‌پوشانی عبارت دلیل ریدایرکت گرفته نشده است.

در راهنمای موجود /docs/gpu-server-setup-for-ai، محدوده دستورهای نصب سیستم‌عامل/درایور روشن و مسیر برنامه‌های واقعی لینک شد. این مقاله عمومی و /blog/gpu-servers-for-ai از sitemap زنده پیدا و بررسی شدند؛ نیاز دو راهنمای جدید، عملیات مشخص ComfyUI و Jupyter است، نه تکرار نصب درایور یک VM.

### n8n، پایتون و ابزارها

برای /cloud/n8n، دمو ساخت سرویس با CLI تأییدنشده و وعده ۶۰ ثانیه، مهاجرت بدون قطعی و امکانات مشترک بی‌قید حذف/اصلاح شدند. مسئولیت نگهداری، بکاپ، بازیابی، worker و اجرای واقعی باید در پیشنهاد سرویس مشخص شود. قیمت‌گذاری و منطق سفارش حفظ شده‌اند. قرارداد دقیق managed n8n، SSO، دوره نگهداری بکاپ و محدودیت اجرا هنوز نیازمند تأیید محصول است؛ در این تغییر قابلیت تجاری تازه‌ای فعال نشده است.

برای /hosting/python، متن معرفی و متا با WSGI، نیاز پل FastAPI و محدودیت WebSocket/SSE/worker همسو شدند. محتوای انتخاب هاست/VPS قبلاً موجود بود و حفظ شد. صفحه محصول محل مقایسه و /order/python-1 تا /order/python-5 صفحات مستقل پلن‌اند. هر پنج URL زنده ۲۰۰ و self-canonical دارند و از صفحه محصول لینک می‌گیرند؛ دلیل فنی برای ادغام یا canonical کردن همه به صفحه محصول پیدا نشد.

عنوان ابزار برش به «برش عکس آنلاین» تغییر کرد؛ PNG/JPEG و پردازش محلی از کد تأیید شدند. ابزار clip-path نمونه CSS قابل استفاده و لینک مرتبط گرفت. توضیح نادرست دریافت pointer event در ناحیه بریده‌شده در هر سه زبان، مطابق استاندارد W3C اصلاح شد. ابزار اصلی پیش از محتوای تکمیلی در دسترس است.

## راهنماهای آماده CMS

| URL بدون پیشوند زبان | کارکرد | وضعیت |
|---|---|---|
| /blog/comfyui-hourly-gpu-guide | انتخاب منابع، سفارش، توکن، درخواست API، دریافت و ذخیره تصویر و خطاها | متن کامل fa/en/tr؛ نصب روی CMS تولید انجام نشده |
| /blog/jupyter-hourly-gpu-guide | ورود، CUDA، نمونه PyTorch، checkpoint، دریافت فایل و محدودیت دسترسی | متن کامل fa/en/tr؛ اجرای GPU زنده و انتقال خارجی آزموده نشده |
| /blog/n8n-backup-migration-guide | workflow، اعتبارنامه، کلید، بکاپ، مقصد آزمایشی و پذیرش انتقال | متن کامل fa/en/tr؛ مهاجرت واقعی مشتری انجام نشده |
| /blog/cpanel-vs-directadmin | مقایسه هزینه لایسنس، منابع، انتخاب محصول و انتقال با منابع رسمی | جایگزینی آماده در همان URL؛ به snapshot تازه CMS نیاز دارد |

منبع‌ها در website/resources/content/growth/ هستند و از مسیر عمومی سرو نمی‌شوند. سه راهنمای عملیاتی صفحه جدیدند؛ مقاله مقایسه به‌روزرسانی URL موجود است. هیچ زمان اجرا، هزینه تولید تصویر، مصرف حافظه یا نتیجه آزمایش GPU ساخته نشده است. مثالی که واقعاً روی GPU اجرا نشده، چنین ادعایی ندارد.

فرمان content:apply-growth فقط یک مقاله مشخص را پیش‌نمایش می‌دهد. اعمال، فلگ صریح و snapshot تازه می‌خواهد؛ تغییر هم‌زمان CMS آن را رد می‌کند. در مقاله موجود، نویسنده، تاریخ انتشار، وضعیت انتشار و پست‌های دیگر حفظ می‌شوند. cache فهرست ترجمه‌ها پاک می‌شود و observer فعلی، نسل page cache را به‌روز می‌کند. این فرمان، migration یا seeder عمومی نیست.

نمونه مراحل انتشار، فقط پس از پیش‌بررسی و هماهنگی همان محیط:

~~~sh
php artisan content:apply-growth jupyter-hourly-gpu-guide
# منبع و مقاله فعلی را بررسی کنید؛ snapshot خروجی را دقیق وارد کنید.
php artisan content:apply-growth jupyter-hourly-gpu-guide --apply --expect=SNAPSHOT_FROM_FRESH_PREVIEW
~~~

برای اسلاگ تازه، snapshot برابر absent است؛ در مقاله موجود SHA-256 خروجی پیش‌نمایش لازم است. هر چهار مقاله باید در همان بسته هماهنگ شوند تا لینک‌های متقابل راهنماها مقصد داشته باشند. استفاده از blog:seed-db --refresh عمومی برای این کار لازم یا مجاز نشده است.

## لینک داخلی و سئوی فنی

نقشه ۸۷ لینک در سه زبان، به ۳۶ مقصد در internal-links.csv ثبت شده است؛ مبدأ، مقصد، زبان، anchor، محل درج، دلیل و وابستگی انتشار هر لینک مشخص‌اند. مقصدهای جدید در محیط CMS ایزوله با GET بررسی شدند و همگی ۲۰۰ بودند. دو مقصد اصلی نمایندگی در تولید نیز جدا ۲۰۰ تأیید شدند. لینک مقاله‌ای که منتشر نشده یا ترجمه کامل آن موجود نیست در بلوک‌های پویا نمایش داده نمی‌شود.

canonical، hreflang و sitemap پایه از قبل وجود داشتند. صفحات اصلی زنده هدف، تک H1 و canonical بدون پارامتر داشتند. راهنماهای تازه از همان CMS و تولید sitemap فعلی استفاده می‌کنند؛ حضور سه نسخه، canonical و BlogPosting در آزمون تأیید شد. Offer با InStock برای دو صفحه صرفاً مبتنی بر پیشنهاد n8n/GPU VPS تا تأیید عرضه معتبر حذف شده است؛ تغییر robots، noindex یا ریدایرکت انجام نشده است.

دو صفحه /tr/blog/what-is-vpn و /blog/iranian-discord-servers هنگام بررسی ۲۰۰، self-canonical و تک H1 داشتند. این یافته علت افت تاریخی را اثبات نمی‌کند. بدون ردیف‌های مقایسه، تاریخچه تغییرات CMS و URL Inspection، علت افت اعلام نشده و صفحه حذف یا noindex نشده است.

## اندازه‌گیری

رویداد سبک product_cta_click از helper فعلی ServerNetAnalytics استفاده می‌کند: product_line، cta_action، cta_placement، source_page و content_language. فقط صفحات هدف و لینک سفارش واقعی یا فرم معتبر درخواست GPU را می‌سنجد. query string، توکن، متن ورودی، اطلاعات مشتری و مبلغ پرداخت اضافه نشده‌اند. نصب دوباره listener و کلیک دکمه submit دوباره‌شماری رویداد فرم ایجاد نمی‌کنند.

رویداد جدید خرید یا تحویل ساخته نشده است. sign_up و purchase فعلی و شناسه پایدار payment:id حفظ‌اند؛ service_provisioned در مستندات فهرست شده، اما مسیر ماندگار و قابل تأیید انتهای قیف در این مأموریت اثبات نشده است. تولید مصنوعی رویداد موفقیت مجاز نیست.

در GET عمومی سایت و صفحه ورود console، یک container مشترک GTM و helper موجود تأیید شد. این شاهد، تنظیم صحیح GA4، cookie domain، session stitching یا ثبت واقعی رویداد را ثابت نمی‌کند. پذیرش باقی‌مانده: بررسی stream و tag جاری، cookie domain خودکار، نبود self-referral، DebugView از landing تا ورود کنسول، و تطبیق ثبت‌نام، اولین پرداخت و تحویل با داده مرجع. برای این پذیرش دسترسی داشبورد و یک سناریوی مجاز بدون هزینه/با سقف مشخص لازم است. چیزی در تنظیم حساب‌های تحلیلی منتشر نشده است.

پس از انتشار واقعی، زمان UTC هر تغییر ثبت و دوره‌های هم‌طول و کامل قبل/بعد برای همان زبان، صفحه، عبارت، کشور، دستگاه و نوع جست‌وجو مقایسه شوند. مخرج CTR باید نمایش همان محدوده باشد. CTA click با checkout، پرداخت یا ساخت سرویس برابر نیست. آمار GA4 خرید با دفتر مالی تطبیق داده شود. فعلاً هیچ افزایش رتبه، ورودی یا فروش ناشی از این تغییر گزارش نمی‌شود.

## اعتبارسنجی انجام‌شده

| بررسی | نتیجه واقعی |
|---|---|
| PHP | PHP 8.4.26، اکستنشن‌های لازم تست؛ lint تمام فایل‌های PHP تغییرکرده موفق |
| تست‌های جدید CMS/راهنما/لینک/ارز | ۹ تست، ۱۲۶ assertion موفق |
| JavaScript CTA | ۴ تست موفق؛ رویداد یکتا، route واقعی cloud-store، لینک نامرتبط و PII/query بررسی شدند |
| مجموعه مرتبط | ۱۳۴ تست، ۱٬۴۱۷ assertion؛ ۱۹ failure، صفر error و صفر skip |
| مقایسه develop دست‌نخورده | ۱۲۵ تست، ۱٬۲۹۱ assertion و همان ۱۹ failure؛ هیچ نام تست تازه‌ای به شکست‌ها اضافه نشد |
| Blade | view:cache موفق؛ این کار فقط در checkout ایزوله اجرا شد |
| مرورگر | ۳۳ URL در سه زبان × موبایل 390×844 و دسکتاپ 1440×1000؛ بدون خطای JavaScript، overflow افقی یا JSON-LD نامعتبر؛ hookهای مقالات موجود جدا بررسی شدند |
| ابزارها | دانلود PNG با ابعاد واقعی و امضای JPEG؛ تولید ستاره/دایره clip-path و تطابق clipboard با خروجی موفق |
| CTA رندرشده | کلیک لینک واقعی سفارش EN دقیقاً یک product_cta_click داد؛ helper تست جای GA4 زنده بود |
| لینک‌ها | ۸۷ لینک ثبت‌شده، ۳۶ مقصد یکتا، همه مقصدها در محیط ایزوله ۲۰۰ |
| کیفیت Git | diff --check موفق؛ فایل خام گزارش، دیتابیس، راز، vendor یا خروجی آزمون وارد Git نشده است |

baseline آزمون از 70711ba3db2638417d6cd1df3eb4801e78e9ebd4 ساخته شد. سپس فقط چهار سند upstream با fast-forward به facc59c6 رسیدند؛ کد runtime مبنا یکسان است. شکست‌های موجود در baseline-failures.txt فهرست شده‌اند؛ بخشی از fixtureهای کاتالوگ بدون snapshot معتبر نرخ هستند. این تغییر، شکست‌های مستقل قدیمی را برای سبز کردن suite اصلاح نکرده است.

این تغییر Blade/PHP و asset مستقیم است؛ Vite bundle تغییر نکرد. build بازتولیدپذیر npm به علت نبود lockfile اپ در قرارداد جاری تأیید نشده و به‌عنوان اجراشده گزارش نمی‌شود. typecheck جدا برای این فایل‌ها در package.json موجود نیست. Lighthouse، Core Web Vitals میدانی، اجرای GPU هزینه‌دار، پرداخت واقعی و انتقال n8n روی تولید اجرا نشده‌اند.

## انتشار و بازگشت

این PR انتشار تولید نیست. پیش از نصب: هماهنگی مسئول انتشار، manifest محدود با hash قبل/بعد و backup خصوصی، تطبیق فایل زنده با SHAها، و توقف در برابر drift لازم‌اند. فقط فایل‌های runtime مرتبط زیر website به مقصد مستندشده منتقل شوند؛ docs، tests، JSONهای گزارش و داده خام عمومی نشوند. asset جدید به document root واقعی نیز نیاز دارد. OPcache/page cache صرفاً با روش مجاز و دامنه مرتبط فعال شوند. مقاله‌ها با فرمان محدود و snapshot تازه، همراه backup داده همان پست‌ها، اعمال و سپس لینک‌ها و sitemap بررسی شوند. هیچ helper قدیمی کورکورانه دوباره اجرا نشود.

rollback کد: بازگرداندن فقط فایل‌های این بسته با کنترل hash و حفظ تغییر همکار؛ برگشت عمومی کل اپ لازم نیست. برای CMS، ترجمه‌های قبلی همان پست از backup بازگردند؛ تغییرهای جدیدتر بازنویسی نشوند. مقاله جدید قبل از حذف/پیش‌نویس شدن، همراه تمام لینک‌های وابسته و sitemap بررسی شود. این مأموریت حذف محتوای تولید را اجرا یا مجاز نکرده است. منطق مالی و schema دیتابیس تغییر نکرده و rollback مالی/زیرساخت لازم نشده است.

## فایل‌های تحویل

- [ثبت اقدام‌ها](seo-growth-2026-10-20/action-register.csv): ۱۲ بسته متن مأموریت؛ تطبیق با اکسل هنوز مسدود.
- [نقشه عبارت و URL](seo-growth-2026-10-20/keyword-map.csv).
- [متای قبل و بعد](seo-growth-2026-10-20/metadata-before-after.csv): قبل از GET زنده؛ بعد قالب کد، با placeholder قیمت. اعداد fixture به‌عنوان قیمت تولید ارائه نشده‌اند.
- [نقشه لینک‌های اجراشده در کد](seo-growth-2026-10-20/internal-links.csv).
- [شکست‌های baseline](seo-growth-2026-10-20/baseline-failures.txt).

فایل‌های کد تغییرکرده:

- website/app/Console/Commands/ApplyGrowthArticle.php
- website/app/Http/Controllers/HourlyVpsController.php
- website/config/catalog/cloud.php
- website/config/catalog/vps.php
- website/config/growth.php
- website/config/hosting.php
- website/config/webtools.php
- website/lang/en/ui.php
- website/lang/fa/ui.php
- website/lang/tr/ui.php
- website/public/assets/js/growth-cta.js
- website/resources/content/growth/comfyui-hourly-gpu-guide.json
- website/resources/content/growth/cpanel-vs-directadmin.json
- website/resources/content/growth/jupyter-hourly-gpu-guide.json
- website/resources/content/growth/n8n-backup-migration-guide.json
- website/resources/content/webtools-seo.php
- website/resources/views/pages/blog-post.blade.php
- website/resources/views/pages/docs-article.blade.php
- website/resources/views/pages/gpu.blade.php
- website/resources/views/pages/hosting.blade.php
- website/resources/views/pages/vps-hourly.blade.php
- website/resources/views/partials/growth-cta.blade.php
- website/resources/views/partials/growth-guides.blade.php
- website/resources/views/partials/seo-hourly-estimate.blade.php
- website/resources/views/webtools/clip-path-generator.blade.php
- website/resources/views/webtools/image-cropper.blade.php
- website/tests/Feature/GrowthArticlePublishingTest.php
- website/tests/js/growth-cta.test.cjs

## منابع فنی بررسی‌شده

- [تعرفه رسمی cPanel](https://www.cpanel.net/pricing/) و [DirectAdmin](https://www.directadmin.com/pricing.php).
- [نیازمندی‌های cPanel](https://docs.cpanel.net/installation-guide/system-requirements/) و [DirectAdmin](https://docs.directadmin.com/getting-started/installation/system-requirements.html).
- [Transfer Tool](https://docs.cpanel.net/whm/transfers/transfer-tool/) و [انتقال cPanel به DirectAdmin](https://docs.directadmin.com/directadmin/backup-restore-migration/migration-to-da.html).
- [فایل‌ها در JupyterLab](https://jupyterlab.readthedocs.io/en/stable/user/files.html).
- [CLI n8n](https://docs.n8n.io/hosting/cli-commands/) و [کلید رمزنگاری](https://docs.n8n.io/hosting/configuration/configuration-examples/encryption-key/).
- [CSS Masking؛ محدوده clipping و pointer event](https://www.w3.org/TR/css-masking-1/#clipping-paths).

قیمت عددی فروشنده و بنچمارک عملکرد داخل مقاله تازه سخت‌کد نشده‌اند. مشخصات برنامه GPU و الگوی دسترسی از کد جاری خود پروژه و پنل مشتری استخراج شده‌اند.
