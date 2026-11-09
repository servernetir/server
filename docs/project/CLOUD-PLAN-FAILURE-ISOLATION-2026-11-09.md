# جداسازی خطای پلن در همهٔ زیرساخت‌های ابری — ۹ نوامبر ۲۰۲۶

## قرارداد و دامنه

تغییر قیمت/مشخصات، حذف محصول، ابهام یک quote، خطای ثبت یک محصول یا رد تحویل یک پلن نباید فروش پلن‌های سالم همان تأمین‌کننده را متوقف کند. پوشش مشترک هر ۹ درایور CloudManager: Hetzner، Aeza، Arvan، OVH، Proxmox، Salad، Hetzner Robot، LightNode و VPS.TC.

- Repricer مغایرت را با شناسهٔ دقیق پلن قرنطینه و quoteهای مستقل سالم را حفظ می‌کند؛ قیمت/مشخصات حدسی منتشر نمی‌شود.
- Sync هر محصول را مستقل ثبت می‌کند. محصول ناموفق `is_active=false` می‌ماند تا sync موفق همان محصول آن را دوباره تأیید کند؛ تغییر نرخ ارز با هزینهٔ قدیمی آن را باز نمی‌کند. تصمیم‌های دستی دست‌نخورده‌اند.
- Provisioner فقط پلن همان سفارش را می‌بندد؛ شمارندهٔ خطای مبهم gateway نیز provider+plan است. رد RAM یک سفارش کل فروش آروان را نمی‌بندد.
- LightNode محصول دارای هویت/مشخصات متناقض را کنار می‌گذارد؛ VPS.TC با شکست quote یک محصول، quoteهای سالم را دور نمی‌ریزد.
- خطای واقعاً سراسری خواندن کاتالوگ، ورودی مشترک نرخ ارز/مالی نامعتبر و تصمیم صریح مدیر همچنان محافظ مستقل دارند. این تغییر تضمین ظرفیت/دسترسی همیشگی تأمین‌کننده نیست.

## Git و فایل‌ها

پایه: `83ba61a390d7643754b3c357afeb710efee493b2`؛ کاندید تأییدشده: `4aca2291cffb66f763c91b062c680efb0a063fe1`؛ ادغام develop: `928f130bdc5e4ceeca192e84a85ded70506af077`، [PR306](https://github.com/servernetir/server/pull/306).

شش فایل اجرایی زیر `website/app/Services/Cloud/`: `CloudPricingSnapshot.php`، `CloudCatalogRepricer.php`، `CloudCatalogSync.php`، `CloudProvisioner.php`، `LightNodeClient.php` و `VpsTcCatalog.php`.

شش فایل آزمون: `CloudPlanFailureIsolationTest.php`، `CloudCatalogRepricingTest.php`، `CloudDeliveryPromiseTest.php`، `ArvanRamQuotaTest.php`، `CloudUnsupportedLocationTest.php` و `LightNodeClientTest.php`. فیلتر دائمی CI در `.github/workflows/cloud-account-quota.yml` و سیاست مرتبط `website/CLAUDE.md` به‌روز شدند.

## اعتبارسنجی واقعاً اجراشده

- PHP8.4.27، checkout ایزوله بدون env تولید و vendor مطابق lock: ۲۶۲ آزمون/۱۸۰۱ بررسی موفق. همان مجموعه روی فایل تحویل ترکیبی که تغییرات پیشین تولید را حفظ می‌کند نیز ۲۶۲/۱۸۰۱ موفق شد.
- ۷۵ حالت جدید: خطاهای quote برای پنج تأمین‌کنندهٔ نیازمند هزینهٔ تازه، رد تحویل/شمارندهٔ gateway/خطای ثبت برای هر ۹ درایور، دو آزمون آداپتر واقعی LightNode و VPS.TC با دادهٔ ساختگی، و شکست sync یورویی→repricing→تأیید مجدد موفق.
- هفت گروه CI روی SHA کاندید موفق: Cloud account quota، Incident reliability، Arvan traffic، VPS.TC، Existing hosting، Domain supplier و Birthday. دو گروه مرتبط quota/incident روی SQLite و MariaDB اجرا می‌شوند.
- یافتهٔ بازبینی دربارهٔ بازشدن خودکار محصول sync ناموفق رفع و آزمون شد؛ بازبینی نهایی کاندید یافتهٔ باز نداشت.
- آزمون قدیمی LightNode قرارداد توقف کل کاتالوگ داشت و به حذف همان محصول تغییر کرد. fixture قدیمی unsupported-location فاقد نرخ تأییدشده بود و اصلاح شد.
- اجرای اکتشافی اولیهٔ مجموعهٔ وسیع provider با محدودیت حافظهٔ پیش‌فرض ۱۲۸MB شکست‌های fixture و توقف حافظه داشت؛ موفق گزارش نمی‌شود. دامنهٔ منتخب نهایی و CIهای بالا مستقل و موفق‌اند.
- هیچ خرید، ساخت/حذف ماشین، پرداخت، تغییر مالی، پیام مشتری، migration یا اجرای دستی scheduler برای آزمون انجام نشد.

## نصب محدود و حفظ تغییرات زنده

بستهٔ خصوصی `cloud-plan-isolation-release-20261109` شامل backup/stage/manifest با SHA-256 پیش/پس هر شش فایل است. قفل‌ها در ترتیب `.servernet-web-release.lock`، `servernet-release.lock`، `.codex-web-deploy.lock` گرفته شدند. مقصدها زیر `servernet_app/app/Services/Cloud/`، بدون asset/config/dependency تازه‌اند.

چهار فایل دقیقاً با پایه برابر بودند. Sync فقط اختلاف comment داشت. CloudProvisioner تغییرات زندهٔ قبلی در تحویل داشت؛ فقط بدنهٔ `quarantineProvider` با نسخهٔ کاندید جایگزین شد و تمام بایت‌های خارج آن حفظ شدند. این تفاوت عمدیِ تولید با فایل کامل Git در manifest ثبت است و آزمون ترکیبی بالا روی همین فایل اجرا شد.

نصب با پیش‌شرط تازهٔ hash، پشتیبانِ هم‌هش، lint شش فایل، rename تک‌فایلی با حفظ permission و بررسی hash نهایی هر شش مقصد موفق شد. نصب چندفایلی یک تراکنش اتمی واحد معرفی نمی‌شود؛ ترتیب افزایشی و سازگار است.

خواندن فقط‌خواندنی برنامه پیش و پس از نصب دقیقاً یکسان بود: snapshot جاری، ۲۶۴ ردیف قابل‌فروش آروان؛ پلن‌های سالم سایر زیرساخت‌ها و تصمیم‌های دستی حفظ شدند. `excluded_plan_count=11` موجود و exclusion مستقل VPS.TC تغییر نکردند؛ توقف‌های دستی Proxmox/OVH باز نشدند.

فعال‌سازی هدفمند هر شش مسیر در دو pool متعلق به همین حساب و بارگذاری متد جدید snapshot و بدنهٔ محدودشدهٔ quarantine تأیید شد؛ `opcache.validate_timestamps=1` و `revalidate_freq=2` در هر دو مشاهده شد. cache مشترک/reset عمومی/FPM restart اجرا نشد. pool سومِ مشاهده‌شده primary را نداشت و فعال‌سازی موفق برای آن ادعا نمی‌شود؛ نگاشت دامنه به socket از نام socket حدس زده نشد. ابزار موقت با منع HTTP و گارد پارامتر داخلی FastCGI، پس از پذیرش و کنترل بایت‌هایش جمع شد.

## بازگشت و حدود پذیرش

بازگشت در صورت نیاز فقط همین شش فایل از backup خصوصی و تنها وقتی hash فعلی برابر after همان manifest باشد؛ در تغییر جدیدتر توقف و تطبیق لازم است. قفل‌ها و ترتیب وابستگی رعایت شوند؛ فایل‌های وابسته پیش از حذف متد snapshot برگردند. هیچ بازگردانی داده/مالی لازم نیست. بازگشت اجرا نشده است.

پذیرش بدون سفارش هزینه‌دار انجام شد؛ تحویل واقعی مشتری و ایجاد خطای عمدی روی تولید آزموده نشدند.
