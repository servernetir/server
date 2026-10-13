# ServerNet Object Storage Gateway

این سرویس کلید اصلی Hetzner را در میزبان Gateway نگه می‌دارد و به هر سرویس
پرداخت‌شده یک حساب `user` و یک bucket مالکیت‌دار مستقل در VersityGW می‌دهد.
مشتری فقط endpoint، access key و secret خود ServerNet را می‌بیند. نقش `user`
نمی‌تواند bucket تازه بسازد، bucket دیگران را فهرست کند یا به آن دسترسی بگیرد.

سه نمونهٔ مستقل VersityGW برای `fsn1`، `nbg1` و `hel1` اجرا می‌شوند. هر نمونه
IAM و endpoint جدا دارد و درخواست‌ها را با یک credential داخلی به endpoint همان
منطقه در Hetzner دوباره امضا می‌کند. API کنترل روی loopback است و انتشارش فقط
با TLS، HMAC، timestamp و nonce یک‌بارمصرف انجام می‌شود. پورت‌های Versity و API
نباید در firewall عمومی باز شوند.

## چرخهٔ خودکار

- `PUT /v1/tenants/sn-svc-ID` حساب و bucket را idempotent می‌سازد؛ همان درخواست
  با منطقه یا مشتری متفاوت رد می‌شود. افزایش quota روی همان bucket انجام می‌شود.
- تعلیق، user را حذف می‌کند و داده می‌ماند؛ رفع تعلیق همان credential فعلی را
  دوباره می‌سازد. چرخش credential، secret نسخهٔ تازه را روی user اعمال می‌کند.
- لغو، دسترسی را فوری می‌بندد و bucket را ۳۰ روز در قرنطینه نگه می‌دارد؛ timer
  پس از مهلت، objectها و bucket را حذف می‌کند.
- مصرف هر ده دقیقه از خود bucket جمع می‌شود. رسیدن به سقف، دسترسی را خودکار
  می‌بندد. این quota اندازه‌گیری‌شده است و در یک بازهٔ ده‌دقیقه‌ای می‌تواند کمی
  از سقف عبور کند؛ در سایت و قرارداد نباید «hard quota لحظه‌ای» ادعا شود.

## نصب و پذیرش

VersityGW باید روی یک نسخهٔ release مشخص pin شود؛ از `latest` استفاده نشود.
Python venv فقط `boto3` نسخهٔ pin‌شده لازم دارد. فایل‌های env با mode `0600` و
دایرکتوری‌های IAM با مالک `servernet-object` نگهداری شوند. `control.env` شامل
JSON سه منطقه و credentialهای root محلی Versity است؛ credential اصلی Hetzner
فقط در envهای region می‌ماند و نباید وارد Git، log یا مستندات شود.

پیش از فعال‌سازی محصول، برای هر سه منطقه این آزمون‌ها الزامی است: Put/Get/Head/
List/Delete، multipart کامل و abort، presigned URL، تلاش ناموفق برای دیدن bucket
مشتری دوم، CreateBucket ناموفق، تعلیق/رفع تعلیق، چرخش secret، افزایش quota بدون
جابه‌جایی object، و پاک‌سازی یک tenant آزمایشی. سپس health امضاشده باید هر سه
منطقه را `true` برگرداند؛ فرمان کاتالوگ فقط در همین حالت ۲۱ محصول را فعال می‌کند.

Rollback: محصولات را غیرفعال و Server را `maintenance` کنید، اما سرویس‌های
Versity را تا پایان انتقال یا تحویل داده‌های مشتری خاموش نکنید. rollback کد پنل
نباید bucket یا IAM مشتری را حذف کند.
