# تغییرات اجراشده و شواهد آزمون

آخرین ادامه: [۸ نوامبر ۲۰۲۶](continuation-2026-11-08.md). اصلاح تعارض sitemap با noindex و متن سه‌زبانهٔ کاتالوگ در بستهٔ بازبینی تازه است؛ گزارش آزمون ۳ نوامبرِ پایین، آزمون امروز محسوب نمی‌شود. پیش‌نویس تک‌تگ GTM ذخیره شده و هنوز منتشر نشده است.

وضعیت: **در Git ثبت شد؛ در تولید نصب نشده است.** مبنای مطالعه 296d9fc2 بود. پیش از ثبت، develop دوباره خوانده شد؛ تغییرات هم‌زمان Plesk، ابزار Arvan و سپس CloudOperations حفظ شدند. هیچ force-push یا بازنویسی commit دیگران انجام نشد.

## commitها

| SHA | محدوده | نتیجه |
|---|---|---|
| 9b31f79a516970773d4ed9b3c6136c792317e8ab | Service schema، راهنمای هاست/کشور، CSS محدود، تحلیل آفلاین، CI | ثبت در develop |
| baf0a69cd8dbb100833379426d7be686170f2e78 | پوشش تمام هاست‌ها/کشورها، اصلاح الگوی ترکی در تحلیل | ثبت در develop |
| commit مستندات همین پوشه | audit، evidence، map، backlog و طرح پذیرش | مستندات؛ مجوز انتشار نیست |

## فایل‌های runtime

- `website/app/Services/PublicPageStyles.php`
- `website/app/Services/Cloud/CountryLandingSeo.php`
- `website/lang/fa/country-seo.php`
- `website/lang/fa/hosting-seo.php`
- `website/lang/en/country-seo.php`
- `website/lang/en/hosting-seo.php`
- `website/lang/tr/country-seo.php`
- `website/lang/tr/hosting-seo.php`
- `website/resources/views/pages/vps-hourly.blade.php`
- `website/resources/views/pages/vps-country.blade.php`
- `website/resources/views/pages/gpu.blade.php`
- `website/resources/views/pages/hosting.blade.php`
- `website/resources/views/partials/country-selection-guide.blade.php`
- `website/resources/views/partials/hosting-stack-guide.blade.php`

تغییرات مشخص:

1. schema ساعتی به Service تبدیل شد؛ Offer همان قیمت، ارز و واحد HUR را دارد و به buy_url همان پلن می‌رود. shippingDetails و تاریخ تضمین‌شده ساختگی حذف شدند. Service خالی، Offer صفر/ظرفیت جعلی نمی‌گیرد.
2. قالب hosting برای دسته hosting/vps/dedicated هویت Service می‌دهد؛ محصولات بیرون این دسته‌ها مسیر قبلی Product را حفظ می‌کنند. هویت عمومی به Organization واحد اشاره می‌کند.
3. GPU یک Service بدون ادعای رزرو ظرفیت می‌گیرد. فهرست کارت، اپ‌ها، نرخ، هشدار قطع‌شدنی و فرم سفارش تغییر نکردند.
4. metadata و راهنمای انتخاب لینوکس/ویندوز در هر سه زبان اضافه شد؛ PHP/WordPress، IIS/.NET/MSSQL و فرق SSMS با RDP روشن شدند.
5. راهنمای ساعتی کشور فقط با hourly_url واقعی در ردیف‌های همان صفحه رندر می‌شود. جدول، محاسبات قیمت و فروش فعال/غیرفعال حفظ شدند.
6. CSS کوچک hourly explorer با محافظ موجود برای مسیر سه‌زبانه ساعتی inline می‌شود؛ نبود فایل، import یا URL نسبی ناشناخته، fallback خارجی دارد.

فایل‌های ابزار و پذیرش: `scripts/seo-growth-cycle/opportunities.py`، `test_opportunities.py`، `website/tests/Feature/CommercialSeoGrowthTest.php` و `.github/workflows/seo-commercial-growth.yml`.

## آزمون‌های واقعاً اجراشده

| محیط | دستور/دامنه | نتیجه |
|---|---|---|
| محیط محلی Python | unittest برای test_opportunities.py | 7 تست موفق |
| محیط محلی Python | unittest برای test_check_public.py موجود | 10 تست موفق |
| محیط محلی | py_compile و git diff --check | موفق |
| GitHub، PHP 8.4.27 و SQLite ساختگی | lint سرویس‌ها/ترجمه‌های جدید و package requirements | موفق |
| GitHub، commit 9b31f79 | suite مربوط | 63 تست، 2,251 assertion موفق |
| GitHub، commit baf0a69 | suite گسترش‌یافته | 65 تست، 3,495 assertion موفق |

[اجرای نهایی](https://github.com/servernetir/server/actions/runs/41595309272). در نصب Composer هشدارهای قدیمی PSR-4 برای fixtureهای standalone دیده شد؛ PHPUnit با fail-on-warning موفق بود. این هشدارهای autoload به‌عنوان اصلاح‌شده معرفی نمی‌شوند.

آزمون نهایی هر 12 محصول هاست در سه زبان و هر 51 قالب کشور در سه زبان را شامل می‌شود. همچنین قیمت/ارز/لینک ساعتی، fallback CSS، فقدان Offer در کاتالوگ خالی، محافظ فروش، metadata، country Service و قراردادهای analytics موجود بررسی شدند. داده‌ها ساختگی‌اند؛ provider، پرداخت، پیامک یا سفارش واقعی اجرا نشد.

میزبان محلی PHP نداشت؛ اجرای Laravel به CI ایزوله سپرده شد و انجام‌نشده به‌عنوان اجرای محلی گزارش نشد. کل مجموعه تاریخی پروژه اجرا نشده است؛ تنها suite مرتبط ثبت‌شده بالا مبنای پذیرش است.

## مشاهده قبل و اندازه‌گیری بعد

پیش از انتشار: GSC/GA4، نمونه‌های زنده ساعتی/GPU/آلمان/ویندوز، robots، sitemap، GSC index/sitemap/Merchant/CWV و PSI ثبت شدند. رندر تصویری و چند اجرای پس از تغییر روی محیط staging/تولید هنوز انجام نشده‌اند. اثر رتبه/کلیک/سرعت تولید اثبات نمی‌شود.

قیمت، inventory، provider، پرداخت، دیتابیس، DNS، میزبانی و قوانین خصوصی تغییر نکرده‌اند. برنامه مرحله بعد در [deployment plan](deployment-plan.md) و [measurement plan](measurement-plan.md) است.
