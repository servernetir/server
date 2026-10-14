# انتشار انتخاب برنامهٔ GPU — ۱۴ اکتبر ۲۰۲۶

## دامنه و وضعیت زنده

- ماشین درگیر: `core` با حساب استقرار `servernetcloud`؛ مسیر برنامه `/home/servernetcloud/servernet_app` و document root واقعی `/home/servernetcloud/public_html`.
- مسیر مشتری: `https://console.servernet.cloud/account/cloud-store?location=global-gpu`. مرحلهٔ برنامه اکنون بدون زبانهٔ سیستم‌عامل، سه کارت سازگار ComfyUI، Jupyter PyTorch و Ollama را با نام، توضیح و وضعیت انتخاب نشان می‌دهد. عنوان صفحه «درخواست GPU»، برچسب رسید «برنامهٔ آماده» و توضیح تنظیمات پیشرفته «جزئیات پرداخت ساعتی» است. فرم، نام فیلد `image` و کلیدهای برنامه برای منطق موجود حفظ شده‌اند.
- فقط قالب خرید، CSS پنل و متن‌های مربوط در هر زبان فارسی/انگلیسی/ترکی تغییر کردند. مسیرهای API، منطق مالی/تخصیص، پایگاه داده و سرویس‌های فعال دست‌نخورده‌اند.

## روش انتشار و ماندگاری

Patch نخست با SHA-256 `DB719D2BC943225027F554294CB6C4724BBD81074D3973ABBBBB91A40932525A` پس از `git apply --check -p2` بر چهار فایل برنامه و CSS کپی برنامه نشست. پیش از آن پنج فایل در `/home/servernetcloud/gpu-app-picker-ui-before-20261014.tar.gz` ذخیره شدند. Patch تکمیلی عنوان‌ها با SHA-256 `66501069601B00A62EA5A61F3EDFB152A790DD84634E30B2896BB69B7C0E8773` پس از check روی قالب و سه زبان اعمال شد؛ پشتیبان آن `/home/servernetcloud/gpu-app-picker-labels-before-20261014.tar.gz` است.

بازبینی URL عمومی نشان داد CSS واقعاً از `/home/servernetcloud/public_html/assets/css/panel.css` خوانده می‌شود، درحالی‌که نسخهٔ دوم `servernet_app/public/assets/css/panel.css` هم روی دیسک است. فایل عمومی با پشتیبان مستقل `/home/servernetcloud/gpu-app-picker-public-css-before-20261014.css` و patch محدود ۲۷ خطی به‌روزرسانی شد؛ تفاوت‌های دیگر CSS عمومی حفظ شدند. `asset_ver()` مهر را از document root می‌خواند و پس از نصب از `1791707802` به `1791968728` تغییر کرد. نسخهٔ Git در شاخهٔ `develop` پس از ادغام PR65، مرجع ماندگار کد است؛ نصب آینده باید هر دو مسیر CSS را لحاظ کند.

PHP-FPM روی `opcache.validate_timestamps=0` است. پس از `view:cache`، مسیر احرازشدهٔ موجود برای reset کردن OPcache و پاک‌سازی config/route/view/application cache پاسخ HTTP 200 و `opcache_reset=true` داد. هیچ توکن یا دادهٔ احراز هویت ثبت نشده است.

## اعتبارسنجی و محدودیت

- مجموعهٔ مرتبط `CloudStore|GpuStoreSeparation|GpuCapacityOrders|GpuCapacitySchedule` با PHP 8.4: ۱۳۸ تست، ۱۴۱۰ assertion، صفر شکست.
- PHP lint هر سه فایل ترجمه و کامپایل Blade روی سرور موفق. `git apply --reverse --check` هر دو patch برنامه و patch مستقل CSS عمومی را تأیید کرد.
- صفحهٔ زنده پس از بارگذاری مجدد عنوان «درخواست GPU»، سه کارت، `display:grid`، ارتفاع ۱۵۸px و انتخاب `gpu-jupyter` در ورودی `image` را نشان داد. تصویر تأیید در `output/gpu-app-picker-live-2026-10-14.jpg` روی ماشین کار ذخیره شد.
- این انتشار رفتار ظرفیت و صف را تغییر نداد و نمونهٔ GPU هزینه‌دار نساخت. پذیرش صف و نبود کسر هزینه در گزارش‌های قبلی همین فهرست ثبت شده‌اند؛ این آزمون UI به‌تنهایی اثبات تازه‌ای دربارهٔ ظرفیت آیندهٔ تأمین‌کننده نیست.

## اثر شبکه و بازگشت

Route برنامهٔ خرید از نظر مسیر همان `/account/cloud-store` است؛ NAT، firewall، DNS، VPN، port-forward، hostcron، عامل کشور/پورت، ماشین‌ها و مهمان‌های موجود تغییری نکردند. فایل CSS عمومی در document root ماندگار است؛ پاک‌سازی کش فقط برای فعال‌شدن کد PHP انجام شد.

برای بازگشت، ابتدا نسخهٔ فعلی همان پنج فایل و CSS عمومی را جداگانه پشتیبان بگیرید و هش/اختلاف آن‌ها را با این انتشار بسنجید. قالب و ترجمه‌ها را از دو tar به ترتیب معکوس انتشار بازیابید، و CSS عمومی را از فایل `gpu-app-picker-public-css-before-20261014.css` برگردانید؛ CSS کپی برنامه را از tar نخست بازیابید. سپس Blade cache و OPcache را با مسیر احرازشدهٔ موجود تازه کنید و صفحهٔ GPU و VPS را بازبینی کنید. بازگردانی کورکورانهٔ CSS عمومی ممکن است تغییرهای بعدی دیگران را حذف کند، بنابراین فقط پس از تطبیق نسخهٔ زنده انجام شود.
