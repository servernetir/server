# اندازه‌گیری از impression تا نتیجه تجاری

## مبنا و واحدها

GSC property: sc-domain:servernet.cloud؛ search type: Web. پنجره اصلی 2026-08-01–2026-10-31 در برابر 2026-05-01–2026-07-31 است. آخرین دو روزِ تقویمی نزدیک اجرا وارد دوره نشده‌اند. جمع clicks/impressions از daily property totals و position از میانگین وزن‌دار با impressions محاسبه می‌شود.

Windsor CTR و engagement_rate کسری‌اند؛ 0.1 یعنی 10%. داده GSC Wizard واحد متفاوت دارد، اما در این کار آن ابزار به‌علت طرح پاسخ آماری نداد. هیچ تبدیل واحدی بین این دو بدون توجه به منبع انجام نشود.

Query/page rows شامل همه پرس‌وجوهای ناشناس نیستند و با کل property تجمیع یکسان ندارند. نبود ردیف را صفر تقاضا یا نبود ایندکس تلقی نکنید. برای search appearance، خروجی جداگانه 2026-08-03–2026-10-31 است؛ به پنجره 92روزه تعمیم داده نشده است.

اجرای مجدد با JSON array یا Windsor data[] و بدون تماس شبکه:

```bash
python scripts/seo-growth-cycle/opportunities.py \
  --pairs /private/gsc-query-page.json --daily /private/gsc-property-daily.json \
  --start 2026-08-01 --end 2026-10-31 \
  --previous-start 2026-05-01 --previous-end 2026-07-31 \
  --output /private/opportunity-review.json
```

خروجی 238 نامزد تجاریِ این نوبت در evidence محفوظ است. review_score اولویت بررسی است، نه پیش‌بینی کلیک. داده خام با ایمیل/شماره/مسیر خصوصی منتشر نشود.

## قیف و محدودیت GA4

Property 300424142، ServerNet. خروجی event_name/event_count در دوره اصلی، purchase=275، sign_up=268، begin_checkout=28، view_item=2,802، configure_product=109 و payment_attempt=257 دارد. کمتر بودن begin_checkout از purchase به‌تنهایی اشکال علت‌دار را اثبات نمی‌کند؛ مسیرها و پوشش ابزارگذاری باید تطبیق داده شوند.

کد DataLayerService، transaction_id پایدار payment ID، value/currency، تفکیک wallet_topup از purchase و مصرف رویداد session را دارد. تست‌های مربوط در همین CI موفق‌اند. تست کد، ورود درست داده به GA4/GTM تولید را ثابت نمی‌کند. صفر بودن purchase_revenue باید قبل از گزارش ROI حل و با سفارش تسویه‌شده تطبیق شود.

| مرحله | شاهد موجود | نیاز باقی |
|---|---|---|
| صفحه فرود ارگانیک | landing_page + session_default_channel_group | حذف اثر مسیرهای تاریخی/ترافیک نامعتبر با روش مستند |
| دیدن محصول | view_item برگشته است | کنترل coverage محصول و عدم ارسال اطلاعات شخصی |
| انتخاب کشور/کارت | configure_product برگشته است | بررسی پارامترهای عمومی و اتصال به خانواده محصول |
| محاسبه‌گر | رویداد اختصاصی usage در خروجی دیده نشد | بررسی اسکریپت موجود؛ در صورت فقدان، event بدون داده شخصی و تحت consent |
| ثبت‌نام | sign_up برگشته است | تطبیق با ثبت‌نام موفق؛ بدون OTP/شماره/شناسه شخصی |
| شروع پرداخت | begin_checkout، payment_attempt | تعیین مسیرهای فاقد پوشش، عدم ساخت purchase در مرحله شروع |
| خرید/درآمد | purchase=275، درآمد برگشتی صفر | mapping value/currency/transaction_id، جلوگیری از تکرار و تطبیق با تسویه |

organic ساعتی فارسی: 377 session، 242 engaged، 22 ecommerce_purchases، درآمد 0؛ GPU: 363، 265، 4، درآمد 0. این‌ها گزارش رویدادند، نه اثبات تعداد مشتری پرداخت‌کننده یا درآمد واقعی. privacy و consent موجود تغییر نکرده‌اند.

## قبل/بعد و پیگیری

- تاریخ واقعی انتشار، SHA، cohort URLها و تغییرات عنوان/متا/محتوا/موجودی جدا ثبت شوند. امروز وضعیت فقط Git است.
- چهار هفته کامل بعد از انتشار، همان query/pageها و همان device/country با 28 روز قبل مقایسه شوند. CTR در باند رتبه یکسان ارزیابی شود؛ نوسان رتبه یا موجودی اثر عنوان نیست.
- cohort تغییریافته با صفحات مشابهِ بدون تغییر مقایسه شود؛ تفاوت intent و به‌روزرسانی گوگل confounder است. بدون آزمایش کنترل معتبر ادعای علیت نشود.
- PSI در staging/تولید مصوب، حداقل چند اجرای همسان موبایل/دسکتاپ داشته باشد؛ median و دامنه تغییرات نگه داشته شود. CrUX به پنجره میدانی خودش وابسته است و بلافاصله تغییر نمی‌کند.
- کاهش 404، canonical conflict و Merchant items با خزش و snapshot تازه سنجیده شود؛ Accepted/Started معادل Passed نیست.
- معیار تجاری نهایی، تعداد و درآمد سفارش تسویه‌شده غیرتکراریِ منتسب به ورودی ارگانیک است. تا پذیرش درآمد GA4، کلیک یا event را فروش قطعی گزارش نکنید.

خروجی‌های فعلی در evidence تاریخ و دامنه دارند. هیچ تضمین رتبه، حجم بازار یا درصد افزایش فروش ارائه نشده است.
