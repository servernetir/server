# Hetzner Object Storage Gateway — 2026-10-13

## تصمیم و دلیل

کلید S3 بومی Hetzner در یک پروژهٔ مشترک می‌تواند policy باکت را تغییر دهد؛ آزمون
زندهٔ قبلی نشان داد policy به‌تنهایی مرز امن بین مشتریان نیست. Hetzner نیز API
اختصاصی برای ساخت credential یا خواندن مصرف storage/traffic ارائه نمی‌کند. بنابراین
محصول مستقیم `hetzner_object` بسته می‌ماند و فروش فقط از نوع
`hetzner_object_gateway` مجاز است.

درگاه پیشنهادی VersityGW است، چون backend مستقیم S3، امضای SigV4، Multipart و
IAM داخلی با نقش `user` دارد. نقش `user` فقط bucketهای مالکیت‌داده‌شده به خودش را
می‌بیند و اجازهٔ ساخت bucket تازه ندارد. `rclone serve s3` برای این محصول کنار
گذاشته شد؛ مستند رسمی آن هنوز سرویس را Experimental می‌نامد، عملیات محدودی دارد
و multipart copy بزرگ‌تر از 5 GiB را از محدودیت‌های شناخته‌شده می‌داند.

منابع رسمی بررسی‌شده:

- https://docs.hetzner.com/storage/object-storage/faq/buckets-objects/
- https://rclone.org/commands/rclone_serve_s3/
- https://github.com/versity/versitygw/wiki/S3-Backend
- https://github.com/versity/versitygw/wiki/Multi-Tenant
- https://github.com/versity/versitygw/wiki/Admin-APIs

## وضعیت ماشین‌ها و شبکه

- Gateway بکاپ موجود: Hetzner server `Gateway` با ID `158228073` و IPv4
  `91.107.173.226` در پروژهٔ ServerNet. سرویس‌های فعلی
  `gateway-backup.servernet.cloud`، `dav-backup.servernet.cloud` و
  `s3-backup.servernet.cloud` پاسخ می‌دهند و در این مرحله هیچ تغییری نکردند.
- SSH ریشه از cPanel به این ماشین با public key و password موجود قابل ورود نبود.
  هیچ reset رمز، reboot، rescue، firewall یا service change انجام نشد.
- طراحی آماده‌شده سه data endpoint مستقل دارد:
  `fsn1.s3.servernet.cloud`، `nbg1.s3.servernet.cloud` و
  `hel1.s3.servernet.cloud`؛ control endpoint برابر
  `object-api.servernet.cloud` است. این نام‌ها هنوز DNS یا TLS فعال ندارند.
- Versity و control API فقط روی loopback می‌نشینند. ingress عمومی فقط 443 از
  Nginx است؛ data plane بدون buffering و با timeout یک‌ساعته عبور می‌کند. هیچ
  route، NAT یا port-forward تازه در وضعیت زنده ساخته نشده است.

## ماندگاری و چرخهٔ سرویس

- IAM هر منطقه در دایرکتوری جدا و tenant state در SQLite با WAL ذخیره می‌شود.
- هر سرویس یک access key قطعی عمومی، secret نسخه‌دار مشتق‌شده از seed مستقل و
  bucket با نام قطعی دارد. secret اصلی Hetzner و seedها فقط در env با mode 0600
  می‌مانند؛ مقدارشان در Git یا این سند نیست.
- ساخت idempotent است؛ تغییر region/customer روی tenant موجود رد می‌شود. افزایش
  quota روی همان bucket انجام می‌شود و جابه‌جایی object ندارد.
- تعلیق user را حذف می‌کند؛ رفع تعلیق آن را با secret فعلی برمی‌گرداند. چرخش
  credential secret قبلی را باطل می‌کند.
- لغو دسترسی را فوری می‌بندد و bucket را ۳۰ روز نگه می‌دارد. timer پس از مهلت
  objectها و bucket را پاک می‌کند.
- مصرف هر ده دقیقه جمع می‌شود و رسیدن به سقف دسترسی را می‌بندد. این hard quota
  لحظه‌ای نیست و ممکن است تا اسکن بعدی کمی overrun رخ دهد. ترافیک خروجی Hetzner
  API قابل خواندن نیست؛ قیمت فروش بر بودجهٔ پلن و 1 TB خروجی لحاظ‌شده است و
  ادعای صورتحساب خودکار ترافیک اضافه تا وجود منبع اندازه‌گیری مستقل مجاز نیست.

## اعتبارسنجی انجام‌شده

- ۶ تست تازهٔ فروش/تحویل Object Storage با ۲۳ assertion پاس شد.
- ۴۹ تست مرتبط تحویل Storage Box و تمدید با ۲۶۱ assertion پاس شد.
- دو تست state/credential/nonce عامل Python پاس شد و همهٔ فایل‌های Python با
  `py_compile` معتبر بودند.
- health کاتالوگ fail-closed است: فقط اگر سه منطقه سالم باشند ۲۱ محصول فعال
  می‌شوند؛ خرابی یک منطقه همهٔ محصول‌های Object را بسته نگه می‌دارد.

## کارهای الزامی پیش از فروش زنده

1. ساخت یک S3 credential اصلی در پروژهٔ ServerNet و نگهداری فقط روی میزبان.
2. فراهم‌کردن میزبان مستقل یا دسترسی مدیریتی امن به Gateway، نصب release pinشده،
   پشتیبان IAM/SQLite و snapshot میزبان.
3. ساخت meta bucket خصوصی هر منطقه، DNS/TLS و محدودیت firewall.
4. پذیرش زنده در هر سه منطقه: Put/Get/Head/List/Delete، Multipart complete/abort،
   presigned URL، منع CreateBucket، منع دیدن bucket مشتری دوم، تعلیق، چرخش، resize
   و حذف tenant آزمایشی.
5. فقط پس از همهٔ موارد بالا، Server فعال و همگام‌سازی کاتالوگ اجرا شود.

## Rollback

Server تحویل را `maintenance` و محصولات را inactive کنید تا پرداخت تازه بسته شود.
Gateway و IAM را تا انتقال یا تحویل دادهٔ مشتریان روشن نگه دارید. rollback کد پنل
یا DNS نباید tenant، bucket یا credential مشتری را حذف کند. برای بازگشت نرم‌افزار،
commit قبلی پنل قابل استقرار است؛ پاک‌سازی داده فقط پس از مهلت و تأیید جدا انجام شود.
