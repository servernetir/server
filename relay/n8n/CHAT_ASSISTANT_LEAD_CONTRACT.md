# قرارداد Lead دستیار گفتگو با Laravel

ورک‌فلوی production دستیار گفتگو در این repository نسخه‌بندی نشده است؛ فایل‌های
موجود در `relay/n8n/` متعلق به رله تلفن/SMS و Iran Probe هستند. بنابراین این سند
change specification لازم برای workflow خارجی `flow.servernet.cloud` است.

## تغییر لازم در workflow

1. خروجی Agent همچنان متن فعلی `reply` را تولید کند.
2. گره تشخیص Lead یک object با فیلدهای واقعیِ استخراج‌شده بسازد. دادهٔ ناموجود
   باید حذف یا `null` باشد؛ مقدار جعلی ممنوع است.
3. گره Email فعلی به `ehsanserver76@gmail.com` حفظ شود.
4. برای جلوگیری از Email تکراری، پیش از Send Email یک Data Store با کلید
   `assistant-lead-email:<session>` بررسی و به صورت اتمیک ثبت شود. retry همان
   session نباید دوباره Email بفرستد.
5. گره `Respond to Webhook` همیشه `reply` را حفظ کند و فقط هنگام تشخیص Lead،
   object استاندارد `lead` را نیز اضافه کند.

پاسخ Lead باید مطابق `chat-assistant-lead-response.example.json` باشد. پاسخ
بدون Lead باید همان قرارداد قبلی را نگه دارد:

```json
{"reply":"متن پاسخ فعلی"}
```

Laravel با `session` یک unique idempotency key می‌سازد؛ بنابراین retry producer
یک ردیف CRM یا اعلان Bale دوم ایجاد نمی‌کند. Email همچنان مسئولیت n8n است و
Laravel برای این event فقط کانال Bale را اجرا می‌کند.

## وضعیت production

تا زمانی که workflow فعال n8n طبق مراحل بالا ویرایش و با هر دو fixture آزموده
نشده باشد، مسیر Lead از نظر end-to-end production تأییدشده نیست.
