# تفکیک شارژ کیف پول و پذیرش درآمد GA4 — ۳۱ اکتبر ۲۰۲۶

درخواست مالک برای ادامه رشد مبتنی بر داده، پس از اتصال GA4. مبنا: develop در `efbef72a0d30b1fb52ac1a52378703fea3553a49`.
دامنه تغییر فقط analytics و ابزار آفلاین تنظیم GTM است؛ تسویه، callback، موجودی، مالیات یا تحویل سرویس تغییر نمی‌کنند.

## نتیجه توسعه

`DataLayerService::purchasePayload()` برای فاکتور `kind=topup` یک `wallet_topup` بدون `ecommerce` می‌سازد؛ رویداد شامل `funding_reference=payment:{id}`، `funding_amount` و `funding_currency` در واحد اصلی پرداخت است. شارژ کیف پول به بدهی قابل مصرف تبدیل می‌شود و فروش محصول نیست؛ پرداخت بعدی سرویس رویداد `purchase` خود را حفظ می‌کند. این تفکیک مانع شمردن هر دو مرحله به عنوان فروش در لایه داده می‌شود.

همان کلید session موجود، رویداد پرداخت را تا نخستین رندر نگه می‌دارد و سپس مصرف می‌کند. هیچ مسیر ارسال مستقیم `gtag` یا Google collection تازه‌ای ساخته نشده است. دریافت `wallet_topup` در GA4 هنوز پذیرفته نیست و نیاز به تنظیم مقصد مناسب دارد؛ وجود رویداد در dataLayer معادل دریافت نیست.

چهار سناریوی تازه به تست‌های موجود اضافه شدند: topup بدون commerce، شارژ سپس مصرف با یک purchase، fallback واحد اصلی و رندر فقط یک‌باره بدون تماس شبکه. قرارداد قبلی خرید سرویس عمداً حفظ شد.

## شاهد GTM عمومی و اصلاح تک‌تگ

در بررسی عمومی ۳۱اکتبر، کانتینر `GTM-MRSC7BF7` resource نسخه۸ خوانده شد؛ hash پاسخ `cf8c349fed31cea4eaf3644c4aa49d0c8329e4417c490e6de3c41658de49246f` است. بسته Google ممکن است مستقل از نسخه تغییر کند؛ این hash فقط همان پاسخ است.

مسیر دقیق purchase تنها tag۱۵ را فعال می‌کند. این tag مقدار `sendEcommerceData=true` دارد، اما `getEcommerceDataFrom=dataLayer` ندارد و value/currency/items را صریح هم map نمی‌کند. tag عمومی دیگری قالب Data Layer را دارد، ولی purchase را ارسال نمی‌کند. این شکاف با داده ناقص درآمد سازگار است؛ علت قطعی دریافت Google بدون Preview/DebugView اثبات نشده است.

`scripts/prepare-purchase-gtm-repair.cjs` یک export تازه و مجاز format۲ را می‌خواند، tag خرید موجود و مقصد درست را احراز می‌کند و فقط فیلد منبع ecommerce را از قالب موجود همان property اضافه می‌کند. IDها، consent، blocking و sequencing حفظ می‌شوند. خروجی شامل یک tag موجود، صفر route تازه و صفر حذف است. شرط محاسباتی/ناشناخته، هم‌پوشانی ارسال، source ازقبل‌تنظیم‌شده و override پارامترهای مالی موجب توقف می‌شوند. برنامه هیچ شبکه یا دسترسی حساب ندارد و export ورودی را تغییر نمی‌دهد.

```bash
node scripts/prepare-purchase-gtm-repair.cjs /private/fresh-export.json /private/purchase-repair-merge.json
```

شناسه عددی حساب/کانتینر حدس زده نمی‌شود. export و فایل خروجی خصوصی بمانند و در Git عمومی ثبت نشوند. import باید Merge و Detailed Changes دقیقاً یک تغییر tag، صفر افزودن و صفر حذف باشد. سپس Preview با fixture بی‌اثر و DebugView لازم است؛ پاسخ collect یا نبود خطا به‌تنهایی پذیرش نیست.

## وضعیت آزمون و انتشار

- ۱۵ تست Node ابزار آفلاین و بررسی نحو هر دو فایل موفق‌اند؛ fixtureها ساختگی هستند.
- بازبینی مستقل سه فایل PHP ایراد مسدودکننده‌ای در این دامنه محدود پیدا نکرد.
- PHP محلی موجود نیست؛ تلاش نصب runtime با محدودیت مجوز محیط متوقف شد و دور زده نشد.
- workflow محدود `analytics-purchase.yml` با PHP۸٫۴ و SQLite/MariaDB برای lint و تست‌های DataLayer/session/catalog اضافه شد؛ نتیجه CI باید از اجرای واقعی همین revision خوانده شود، نه از این تعریف.
- وضعیت اولیه این سند: تغییر آماده بررسی در Git؛ نصب تولید و انتشار GTM انجام نشده‌اند. نتیجه نهایی CI در رسید تحویل/PR ثبت می‌شود.
- مرورگر مجاز Tag Manager به صفحه ورود Google هدایت شد؛ اتصال خواندن GA4 از Windsor مجوز یا نشست ویرایش GTM نیست. هیچ ورود، دسترسی تازه یا تغییر تنظیمات Google انجام نشد.

## موارد باز پیش از پذیرش کامل درآمد

۱. Google در فهرست فعلی ارزهای پشتیبانی‌شده درآمد، IRR/IRT را فهرست نمی‌کند. تبدیل IRT→IRR از نظر واحد داخلی درست است، اما اثبات پشتیبانی monetization نیست. مبلغ تومانی نباید صرفاً با نام USD ارسال شود. اگر گزارش در ارز پشتیبانی‌شده لازم است، نرخ واقعی، منبع و snapshot معتبر برای هر رویداد لازم‌اند؛ تنظیم USD property به‌تنهایی این تبدیل را ایجاد نمی‌کند.

۲. payload قبلی purchase مقدار پرداخت شامل مالیات را در value می‌گذارد؛ قرارداد Google value بدون tax/shipping است. این تغییر محدود، محاسبه مبلغ قبلی را بازطراحی نکرده است. اصلاح آن باید با فاکتور، اقلام، تخفیف و پرداخت جزئی تطبیق داده و مستقل آزموده شود.

۳. purchase با پرداخت/تحویل `paid+provisioned` یکسان نیست. topup، خرید سرویس، تمدید، مالیات و تحویل باید در دفتر داخلی جدا تطبیق داده شوند. اصلاح منبع امروز، داده تاریخی GA4 را اصلاح نمی‌کند و درآمد صفر گزارش‌شده اثبات فروش صفر نیست.

۴. پیوستگی session و source از سایت به کنسول و بازگشت از درگاه نیز باید جدا پذیرفته شود؛ داده واقعی مشتری، transaction یا export حساب به مخزن منتقل نشود.

منابع رسمی بررسی‌شده:
- https://support.google.com/analytics/answer/13800978?hl=en
- https://developers.google.com/analytics/devguides/collection/ga4/ecommerce?client_type=gtm
- https://developers.google.com/analytics/devguides/collection/ga4/reference/events?client_type=gtm
- https://support.google.com/analytics/answer/12313109?hl=en
- https://support.google.com/analytics/answer/9796179?hl=en

بازگشت: فایل‌های analytics همین تغییر برگردند؛ هیچ داده مالی تغییر نکرده است. تا قبل از انتشار GTM، تنظیم فعال Google دست‌نخورده است. پس از انتشار احتمالی، فقط field همان tag با export قبلی و تغییرهای همکاران تطبیق داده شود؛ rollback کامل کانتینر مجوز ضمنی ندارد.
