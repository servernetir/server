# تکمیل مسیر رویدادهای رشد در GTM — ۲۵ اکتبر ۲۰۲۶

## وضعیت و علت

**بستهٔ اصلاح محلی آماده است؛ وارد حساب Google نشده، منتشر نشده و دریافت GA4 تأیید نشده است.** مبنای کد `develop` در `f3c74b4c93da6cf5fb020696dee5c8e85e6be1f7` است. این گزارش فقط مسیر سنجش ردیف۱۸ برنامهٔ سئو را پوشش می‌دهد.

خواندن عمومی تازهٔ `https://www.googletagmanager.com/gtm.js?id=GTM-MRSC7BF7` در `2026-10-25T15:08:52Z` با درخواست `Cache-Control: no-cache` پاسخ۲۰۰ و resource نسخه۸ داد. SHA-256 بایت‌های همین پاسخ `b11e342fde19e03fe983026a8584196d63845a7435340211f58bc6e32ac885b3` بود؛ این hash به همان پاسخ محدود است و Google ممکن است بستهٔ runtime را مستقل از نسخه تغییر دهد. هیچ predicate دقیق یا regex این نسخه دو رویداد زیر را نمی‌پذیرفت. این مشاهده دربارهٔ کانتینر منتشرشده است؛ workspace منتشرنشده و دریافت سمت GA4 از آن معلوم نمی‌شود.

| رویداد موجود در برنامه | منبع | پارامترهای قراردادی | وضعیت در GTM عمومی نسخه۸ |
|---|---|---|---|
| `product_cta_click` | [growth-cta.js](../../website/public/assets/js/growth-cta.js) | `product_line`, `cta_action`, `cta_placement`, `source_page`, `content_language` | مسیر ارسال اختصاصی وجود ندارد |
| `hourly_vps_interaction` | [hourly-explorer.js](../../website/public/assets/js/hourly-explorer.js) | `interaction_type`, `content_language`, `product_line` | مسیر ارسال اختصاصی وجود ندارد |

`product_cta_click` برای کلیک سفارش، رفتن به تنظیمات GPU، درخواست معتبر GPU و CTA مشخص مشاوره استفاده می‌شود؛ این عمل‌ها معادل پرداخت یا تحویل نیستند. `hourly_vps_interaction` عمل‌های `plan_select`, `duration_change`, `duration_preset`, `use_case`, `country_filter`, `resource_filter`, `plan_compare` را گزارش می‌کند. مقدار آزاد جست‌وجوی کشور، تلفن، ایمیل، query string، مبلغ یا شناسهٔ مشتری جزو این دو قرارداد نیست.

تغییر وب‌سایت برای دورزدن GTM لازم نیست. helper فعلی نام و پارامترها را در یک `dataLayer.push` قرار می‌دهد و listener رویداد CTA از اتصال دوباره جلوگیری می‌کند. همین رفتار و worker موجود حفظ شده‌اند. اصلاح در تنظیم GTM است.

## تغییر دقیق قابل بازبینی

مقصد همان Google tag موجود برای `G-W90N0G8Y2M` است. [قرارداد ماشین‌خوان](../../seo/analytics/growth-events.json) تنها دو رویداد و whitelist پارامترهایشان را تعریف می‌کند.

| جزء افزوده‌شونده | نام/تنظیم |
|---|---|
| ۲ Trigger | `SEO Growth - Event - product_cta_click` و `SEO Growth - Event - hourly_vps_interaction`؛ نوع Custom Event، نام رویداد دقیقاً همان پسوند، regex خاموش، All Custom Events برای همان نام |
| ۲ GA4 Event tag | `SEO Growth - GA4 - product_cta_click` و `SEO Growth - GA4 - hourly_vps_interaction`؛ Event Name ثابت، Measurement ID برابر `G-W90N0G8Y2M`، فقط Trigger متناظر |
| ۶ Data Layer Variable | پیشوند `SEO Growth - DLV - ` به‌اضافهٔ هر نام جدول زیر؛ Data Layer Version برابر۲ |
| نحوهٔ ارسال tag | Once per event؛ ecommerce خاموش؛ Event Parameters فقط فهرست قراردادی همان رویداد |

| نام پارامتر GA4 | نام متغیر GTM | Data Layer Variable Name | رویداد استفاده‌کننده |
|---|---|---|---|
| `product_line` | `SEO Growth - DLV - product_line` | `product_line` | هر دو |
| `content_language` | `SEO Growth - DLV - content_language` | `content_language` | هر دو |
| `cta_action` | `SEO Growth - DLV - cta_action` | `cta_action` | `product_cta_click` |
| `cta_placement` | `SEO Growth - DLV - cta_placement` | `cta_placement` | `product_cta_click` |
| `source_page` | `SEO Growth - DLV - source_page` | `source_page` | `product_cta_click` |
| `interaction_type` | `SEO Growth - DLV - interaction_type` | `interaction_type` | `hourly_vps_interaction` |

هر Event Parameter به متغیر متناظر با نگارش `{{SEO Growth - DLV - ...}}` متصل می‌شود. فهرست صریح هر tag مانع انتقال پارامترهای CTA باقی‌مانده در data layer به رویداد ماشین‌حساب می‌شود. regex عمومی نسخه۸ به همین صورت باقی می‌ماند؛ اضافه‌کردن هم‌زمان این دو نام به آن، دو مسیر ارسال ایجاد می‌کند. Google tag، page_view، purchase، sign_up، service_provisioned، واحد پول و `transaction_id` موجود تغییری ندارند.

## ساخت فایل Merge از export واقعی

شناسه‌های عددی account/container از runtime عمومی قابل استخراج مطمئن نیستند؛ در فایل تنظیمات حدس زده نشده‌اند. [سازندهٔ آفلاین](../../scripts/prepare-growth-gtm-import.cjs) یک export تازه از حساب مجاز را می‌خواند و فایل import محدود می‌سازد. این برنامه هیچ شبکه، API حساب، انتشار یا تغییر در export ورودی ندارد.

1. در حساب صحیح، کانتینر `GTM-MRSC7BF7` را انتخاب و یک workspace جدا برای این دو رویداد باز کنید. پیش از import، اختلاف‌های سایر همکاران و آخرین نسخهٔ منتشرشده را بررسی کنید.
2. همان نسخه/workspace را از Admin → Export Container به مسیر خصوصی خارج از مخزن صادر کنید. export کامل ممکن است تنظیمات دیگری داشته باشد؛ آن را به Git عمومی اضافه نکنید.
3. از ریشهٔ مخزن اجرا کنید:

```bash
node scripts/prepare-growth-gtm-import.cjs /private/current-gtm-export.json /private/growth-events-merge.json
```

مسیرهای بالا نمونه‌اند؛ با مسیر واقعی خصوصی جایگزین شوند. فایل خروجی موجود بازنویسی نمی‌شود. سازنده، public ID و شناسه‌های واقعی export، Google tag فعال با مقصد صحیح، قالب پارامترهای GA4 موجود و عدم تداخل نام/Trigger را بررسی می‌کند. در برابر رویداد موجود، regex فراگیر یا شرط ناشناخته متوقف می‌شود تا مسیر دوم ساخته نشود. تنظیم اضافی consent از tag مرجع همان property حفظ می‌شود. این کنترل‌ها جای بررسی موارد سفارشی یا Tag Assistant را نمی‌گیرند.

4. Admin → Import Container، فایل خروجی و همان workspace جدا را انتخاب کنید؛ گزینهٔ **Merge** را بزنید. در Detailed Changes باید دقیقاً **۲ tag + ۲ trigger + ۶ variable افزوده، صفر تغییر و صفر حذف** دیده شود. هر اختلاف یا conflict مستلزم توقف و بررسی export تازه است؛ Rename برای عبور از conflict مناسب این بسته نیست.
5. پیش از انتشار، پیش‌نمایش زیر را کامل کنید. سازنده از قالب `gaawe/eventSettingsTable` مشاهده‌شده در runtime و export مرجع استفاده می‌کند؛ **پذیرفته‌شدن قالب توسط رابط import گوگل هنوز در این نشست آزموده نشده است.** در صورت تغییر قالب، همین جدول تنظیمات دستی مرجع است؛ خطای import به‌تنهایی مجوز تغییر tagهای دیگر نیست.

## پذیرش پیش از انتشار و پس از آن

این سناریوها خرید، پرداخت، تماس تلفنی، ارسال ایمیل یا ثبت درخواست GPU لازم ندارند. برای آزمایش از Preview/Tag Assistant و حساب/دستگاه آزمایشی مجاز استفاده شود و debug traffic مطابق تنظیم حساب مدیریت شود.

| کنترل | معیار پذیرش |
|---|---|
| کلیک سفارش عادی از ساعتی | یک `product_cta_click` و یک اجرای tag متناظر؛ `product_line=hourly_vps`, `cta_action=order` و مسیر بدون query؛ tag عمومی نباید همان event را دوباره بفرستد |
| کلیک به `#gpu-configure` | `product_line=hourly_gpu`, `cta_action=configure`, `cta_placement=hero`؛ صرفاً رفتن به بخش تنظیمات، بدون ارسال فرم |
| تغییر مدت/انتخاب پلن در ماشین‌حساب ساعتی | یک `hourly_vps_interaction` برای هر تغییر کامل کاربر با `interaction_type` صحیح؛ پارامترهای CTA روی این tag ارسال نشوند |
| زبان | حداقل یک تعامل در `/vps/hourly`, `/en/vps/hourly`, `/tr/vps/hourly` و تطبیق fa/en/tr |
| repeat و عدم اثر جانبی | تکرار دو تعامل واقعی، دو event مستقل بدهد؛ یک تعامل دو tag ارسال‌کننده نداشته باشد؛ کلیک CTA رویداد purchase یا service_provisioned نسازد |
| GA4 DebugView | هر دو نام و پارامترها در property صحیح دیده شوند؛ نبودشان باز بماند حتی اگر dataLayer، Tag Assistant یا پاسخ collect۲۰۴ سالم باشد |
| مسیر موبایل | کارکرد ارسال روی mobile worker ساعتی/GPU نیز جدا بررسی شود؛ قبولی desktop به worker تعمیم داده نشود و worker برای گذراندن تست حذف نشود |
| انتشار | نسخه، زمان UTC و نتیجهٔ Preview ثبت؛ پس از انتشار runtime عمومی دوباره خوانده و همان دو مسیر/پارامترها با رویداد واقعی بی‌اثر تأیید شوند |

برای گزارش‌گیری، در GA4 وضعیت custom dimensionهای **event-scoped** برای `product_line`, `cta_action`, `cta_placement`, `content_language`, `interaction_type` بررسی و فقط موارد موردنیاز و غایب ایجاد شوند. برای مسیر صفحه ابتدا dimension استاندارد Page path به‌کار رود؛ ثبت `source_page` به‌عنوان dimension جدا فقط اگر گزارش مشخص به آن نیاز دارد. DebugView به ثبت dimension وابسته نیست؛ آماده‌شدن گزارش پس از تعریف dimension زمان می‌برد. این دو رویداد معیار تشخیصی تعامل‌اند؛ به‌تنهایی first payment یا service-created شمرده نشوند.

اتصال مسیر `servernet.cloud → console.servernet.cloud` از نظر client/session و انتساب باید در یک سناریوی مستقل بررسی شود؛ حفظ query در redirect به‌تنهایی آن را ثابت نمی‌کند. مشاهدهٔ کم‌حجم و مجاز این شناسه‌ها فقط برای مقایسه باشد و شناسهٔ واقعی در گزارش عمومی ذخیره نشود. برای پذیرش درآمد، رویداد purchase با `transaction_id=payment:{id}` باید با یک پرداخت یکتای واقعی دفتر مالی و service_provisioned با تحویل واقعی تطبیق داده شود. در این کار پرداخت یا سرویس آزمایشی ایجاد نشده است.

## دسترسی و آزمون‌های این تغییر

بازبینی اتصال Windsor در این نشست فقط property Search Console را متصل یافت؛ حساب Google Analytics/GA4 متصل نبود، action نوشتنی GA4 ارائه نشد و Google Tag Manager در فهرست connectorهای قابل استفاده نبود. جست‌وجوی دایرکتوری plugin نیز ابزار GTM مرتبط برنگرداند. این نتیجه دربارهٔ قابلیت‌های در دسترس همین نشست است، نه نبودن حساب Google مالک. مرورگر در این نشست به صفحهٔ ورود Google هدایت شد؛ حسابی برای GTM وارد نشده بود. درخواست ورود امن برای ادامهٔ همین تنظیمات لازم است.

اجرای محلی بدون شبکه، پرداخت یا رویداد GA4 واقعی:

| دستور از ریشهٔ مخزن | نتیجه |
|---|---|
| `node scripts/prepare-growth-gtm-import.test.cjs` | ۵ تست موفق؛ metadata اشتباه، route تکراری/regex فراگیر، نام متداخل، قالب و sequencing ناشناخته رد می‌شوند؛ ورودی تغییر نمی‌کند، فقط۱۰جزء تازه ساخته می‌شود |
| `node website/tests/js/growth-cta.test.cjs` | ۷ تست موفق؛ یک event برای هر کلیک، محدودهٔ محصول، سه زبان، عدم ارسال query/contact و جداسازی configure/request |
| `node website/tests/js/hourly-explorer.test.cjs` | ۸ تست موفق؛ محاسبات/فیلترهای explorer؛ این suite دریافت GA4 یا ارسال تعاملی مرورگر را اثبات نمی‌کند |
| `git diff --check` | بدون خطای whitespace هنگام بررسی این بسته |

آزمون سازنده از fixture ساختگی واضح استفاده می‌کند؛ این آزمون به‌معنی export حساب واقعی یا پذیرش import گوگل نیست. runtime، consent، worker، منطق خرید، پایگاه داده و سرویس‌های تولید در این بسته دست‌نخورده‌اند. این بسته همراه PR130 و commit پیاده‌سازی `0e5676a947d27df9b163153c8496430f6170caa1` در develop ثبت شد. ورود به Google و انتشار خود کانتینر هنوز انجام نشده است؛ وضعیت نصب سایت در گزارش تکمیل اصلی آمده است.

## بازگشت

تا قبل از انتشار، حذف/برگشت همین۱۰جزء از workspace جدا کافی است. پس از انتشار، ابتدا مطمئن شوید نسخهٔ پیشین تغییر مستقل دیگری را از بین نمی‌برد؛ در غیر این صورت فقط دو tag تازه را در نسخهٔ اصلاحی pause کنید و علت/زمان را ثبت کنید. بازگشت کامل کانتینر تنها پس از تطبیق تغییرهای همکاران انجام شود. هیچ فایل سایت یا خریدی برای برگشت این اصلاح نیاز به تغییر ندارد.

## منابع رسمی تصمیم‌ها

- [Data layer: رویداد و پارامترها، حفظ صف](https://developers.google.com/tag-platform/tag-manager/datalayer)
- [Custom Event trigger و محدودکردن regex](https://support.google.com/tagmanager/answer/7679219?hl=en)
- [GA4 Event tag، پارامترها و Preview](https://support.google.com/tagmanager/answer/13034206?hl=en)
- [Export/Import، تفاوت Merge و Overwrite و Detailed Changes](https://support.google.com/tagmanager/answer/6106997?hl=en)
- [GTM workspace و تفکیک تغییرهای تیم](https://support.google.com/tagmanager/answer/7059647?hl=en)
- [ساختار رسمی Tag و محدودیت once per event](https://developers.google.com/tag-platform/tag-manager/api/reference/rest/v2/accounts.containers.workspaces.tags)
- [ساختار رسمی Trigger و customEventFilter](https://developers.google.com/tag-platform/tag-manager/api/reference/rest/v2/accounts.containers.workspaces.triggers)
- [GA4 DebugView](https://support.google.com/analytics/answer/7201382?hl=en)
- [GA4 event-scoped custom dimensions](https://support.google.com/analytics/answer/14239696?hl=en)
