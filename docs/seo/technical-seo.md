# ممیزی فنی، schema و سرعت

شواهد مشاهده 2026-11-03 در [public observation](evidence/public-observation-2026-11-03.json). کد جدید هنوز منتشر نشده است.

## خزش، canonical و چندزبانگی

layout موجود canonical، Open Graph و hreflang را از هویت صفحه می‌سازد؛ سازوکار موازی اضافه نشد. صفحات noindex از canonical/alternate عمومی مستثنا هستند. لینک‌های جدید فقط با lroute و متن‌های fa/en/tr ساخته می‌شوند؛ محتوای متغیر با خروجی escaped Blade چاپ می‌شود.

robots.txt زنده برای Googlebot، Bingbot، OAI-SearchBot و PerplexityBot دسترسی عمومی دارد؛ مسیرهای حساب، ورود، API، مدیریت، پرداخت و webhook همچنان محدودند. سیاست training جدا در config موجود است؛ robots در این کار تغییر نکرد. دسترسی bot به معنی ایندکس یا توصیه تضمینی نیست.

sitemap زنده 5,451 URL داشت. GSC اصلی را با وضعیت Success و خوانش 2 November، 5,454 URL و hardware را با Success و خوانش 26 October، 2,115 URL نشان داد. اختلاف تاریخ و تعداد، نشانه قطعی خرابی نیست. هیچ sitemap دوباره ارسال، noindex خصوصی حذف یا validation تکراری آغاز نشد.

GSC Pages هنوز snapshot 23 October است. 404ها، duplicate canonical و crawled-not-indexed باید با نمونه زنده دسته‌بندی شوند. مسیرهای قدیمیِ بدون معادل به صفحه اصلی redirect نمی‌شوند. این کار URL عمومی، DNS، robots یا سیاست فروش را تغییر نمی‌دهد.

## داده ساختاریافته

| مسیر | وضعیت قبل | تغییر |
|---|---|---|
| /vps/hourly | Product همراه shippingDetails، تاریخ rolling قیمت و URL کلی صفحه | Service با provider مشترک؛ Offer از featured واقعی؛ قیمت/ارز/واحد HUR حفظ؛ لینک همان سفارش |
| صفحات hosting/vps/dedicated با قالب hosting | Product حتی برای اجاره خدمات | Service؛ حذف اطلاعات ارسال و تاریخ تضمین ساختگی فقط برای این دسته‌ها |
| /gpu | Organization/WebSite/Breadcrumb، بدون هویت مستقل خدمت | Service بدون Offer یا InStock ساختگی برای ظرفیت قطع‌شدنی |
| صفحه کشور | ItemList از Service، پیش‌تر موجود | حفظ؛ راهنمای ساعتی مشروط به hourly_url واقعی |

schema کالای فیزیکی، پرداخت، قیمت، موجودی، سبد و سیاست صورتحساب بازطراحی نشدند. FAQ و HowTo توضیح محتوای موجودند؛ از آن‌ها وعده rich result گوگل ساخته نمی‌شود. Service نشانه‌گذاری معنایی است و تضمین قابلیت Merchant نیست.

Google اعلام می‌کند داده ساختاریافته باید محتوای واقعی را توصیف کند و نمایش غنی تضمینی نیست. [راهنمای رسمی](https://developers.google.com/search/docs/appearance/structured-data/sd-policies)، [نوع Service](https://schema.org/Service)، [فهرست امکانات فعلی گوگل](https://developers.google.com/search/docs/appearance).

Merchant snapshot 1 November: 251 نامعتبر، 108 معتبر؛ Missing image در حالت Started. تفسیر پیش از نمونه‌برداری مجدد محدود است؛ تغییرات امروز در تولید نیستند و کاهش عدد تاریخی به آن‌ها نسبت داده نمی‌شود.

## اندازه‌گیری سرعت قبل از تغییر

[PSI واقعی موبایل](https://pagespeed.web.dev/analysis/https-servernet-cloud-vps-hourly/2xezv7b3dl?form_factor=mobile)، 3 November، 10:28 تهران؛ یک اجرا، Lighthouse 13.6.0، شبکه Slow 4G.

| سنجه آزمایشگاهیِ صفحه ساعتی | مقدار |
|---|---:|
| Performance | 87 |
| Accessibility | 99 |
| Best Practices | 96 |
| SEO پایه | 100 |
| LCP | حدود 1.9s |
| TBT | حدود 500ms |
| CLS | 0 |
| Speed Index | حدود 1.8s |

در بخش فیلد، This URL غیرفعال بود و داده **origin** نمایش داده شد: LCP=3.1s، TTFB=1.9s، INP=163ms، CLS=0، بازه 28روزه رابط. این اعداد به یک صفحه خاص نسبت داده نمی‌شوند. GSC snapshot 1 November، 148 URL موبایل نیازمند بهبود و 148 URL خوب دسکتاپ دارد.

PSI فایل hourly-explorer.css را با انتقال 1,935 بایت و duration حدود 590ms در زنجیره مسدودکننده دید. کد جدید همان فایل خوانده‌شده از webroot واقعی را پس از محافظ‌های موجودِ PublicPageStyles داخل head قرار می‌دهد. CSS مفقود، خالی، دارای import یا URL نسبی بررسی‌نشده به فایل خارجی بازمی‌گردد. متن کامل CSS محلی 6,924 بایت است؛ اندازه HTML به‌جای آن افزایش می‌یابد. این تغییر، یک درخواست مستقل را در مسیر موفق حذف می‌کند؛ 590ms، وعده کاهش LCP نیست.

JS، فونت، آمار، consent و جلوه‌های اصلی دست‌نخورده‌اند. علت fallback مجموعه کامل CSS/font در تولید و منشأ تمام long taskها هنوز مشخص نشده است. فایل اصلی site.min.css، TTFB و JS باید با trace واقعی بررسی شوند؛ CSS استفاده‌نشده به‌صورت کور حذف نشد و تصویر LCP lazy-load نشد.

## پذیرش فنی

65 تست/3,495 assertion موفق شامل SSR همه 12 هاست در سه زبان، همه 51 کشور در سه زبان با کاتالوگ خالی، schema/currency/link/availability و بازگشت CSS است. این‌ها تست کامل HTTP زنده، mobile interaction، خرید واقعی یا اندازه‌گیری پس از انتشار نیستند. پذیرش بصری و چند اجرای یکسان PSI در staging/انتشار مصوب در [برنامه انتشار](deployment-plan.md) آمده است.
