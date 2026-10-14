# Hetzner Object Storage Gateway — 2026-10-13

## وضعیت نهایی فروش زنده — 2026-10-14

این بخش بر گزارش تاریخی پایین مقدم است. انتشار production با commit `422f3c90`
تکمیل شد و ۲۱ محصول ۱/۲/۵/۱۰/۲۰/۵۰/۱۰۰ ترابایتی در سه منطقه فعال‌اند. همهٔ
محصول‌ها ماهانه‌اند و `requires_domain=0` دارند. از ۲۰۲۶-۱۰-۱۴ قیمت هر سفارش
کل هزینهٔ ماهانهٔ زندهٔ `CAX11` و IPv4 را نیز در بهای تمام‌شده دارد و سود پس از
آن محاسبه می‌شود؛ در نتیجه فروش اول هم زیان‌ده نیست. قیمت همگام‌شده با نرخ فعلی
از ۵٬۰۳۰٬۰۰۰ تا ۲۳۴٬۸۱۰٬۰۰۰ تومان است. Server منطقی شمارهٔ ۵ در حالت active و
`cold_start=true` است؛ اکنون هیچ Gateway ساعتی وجود ندارد و هزینهٔ میزبان از
زمان اولین سفارش پرداخت‌شده آغاز می‌شود.

### ماشین‌ها، شبکه و DNS

- پذیرش روی `CAX11` موقت با شناسهٔ `170260231` انجام شد و پس از پاک‌سازی حذف شد.
  Gateway بکاپ و سایر مهمان‌ها و سرویس‌های موجود دست‌نخورده ماندند.
- در اولین فروش، lifecycle یک Gateway ساعتی می‌سازد، Cloud-init سه VersityGW،
  control API، Nginx و timer تطبیق را بالا می‌آورد و سپس سه meta bucket خصوصی و
  DNSهای `object-api.servernet.cloud` و endpointهای منطقه‌ای را ایجاد می‌کند.
- data/control داخلی روی loopback هستند و تنها HTTPS از Nginx عمومی است. Host
  اصلی درخواست برای اعتبار SigV4 حفظ می‌شود. هیچ route، NAT، port-forward یا
  firewall روی ماشین‌های موجود تغییر نکرد.
- وقتی هیچ سرویس فعال یا tenant نگهداری‌شده وجود نداشته باشد، lifecycle پس از
  خواندن وضعیت امضاشده Gateway را حذف می‌کند. DNSهای آزمایش نیز حذف شدند و API
  هتزنر در پایان `object_gateways=0` گزارش کرد.

### ماندگاری و چرخهٔ خودکار

- کلید S3 اصلی فقط به‌صورت رمز‌شده در تنظیمات تولید و env با mode 0600 نگهداری
  می‌شود. هر مشتری IAM، Access/Secret و bucket مالکیت‌دار مستقل دارد؛ ساخت bucket
  مستقیم با Hetzner S3 و واگذاری مالکیت با Admin API انجام می‌شود.
- تمدید همان bucket و credential را نگه می‌دارد. افزایش ظرفیت فقط quota را عوض
  می‌کند و فایل‌ها جابه‌جا نمی‌شوند. تعلیق ابتدا secret را به مقدار تصادفی دورریز
  تغییر می‌دهد و سپس user را حذف می‌کند؛ رفع تعلیق user را با secret فعلی می‌سازد.
- لغو فوراً دسترسی را می‌بندد و bucket را ۳۰ روز برای بازیابی نگه می‌دارد. timer
  تطبیق پس از پایان مهلت objectها، bucket و tenant را پاک می‌کند. محافظ وضعیت مانع
  حذف Gateway در حضور tenant فعال یا نگهداری‌شده می‌شود.
- سهمیه هر ده دقیقه اندازه‌گیری و در عبور از سقف تعلیق می‌شود. این hard quota
  لحظه‌ای نیست. Hetzner API ترافیک خروجی را در سطح tenant نمی‌دهد؛ قیمت محصول یک
  ترابایت خروجی ماهانه را بودجه‌بندی می‌کند و اضافه‌ترافیک فعلاً خودکار محاسبه نمی‌شود.

### پذیرش و پاک‌سازی

دو tenant در `fsn1` و `nbg1` از مسیر واقعی کنترل ساخته شدند. Put/Get/Head/List،
presigned URL، Multipart complete برای فایل ۱۰ MiB، Multipart abort، Delete، منع
CreateBucket و منع دسترسی tenant دوم همگی موفق بودند. suspend کلید قبلی را باطل
کرد، unsuspend دسترسی را برگرداند، rotate کلید قبلی را بست و resize سهمیهٔ همان
bucket را از ۱ به ۳ GiB افزایش داد. retire هر دو tenant و اجرای reconcile پس از
انقضای مصنوعی نگهداری، `tenant_count=0` برگرداند.

سه meta bucket، دو bucket مشتری، میزبان پذیرش و DNSهای آزمایش حذف شدند. چهار تست
Laravel cold start، دو تست provisioning و شش تست عامل Python موفق بودند؛ همهٔ
فایل‌های Python نیز compile شدند. اطلاعات محرمانه و credential در این سند یا Git
ثبت نشده‌اند.

### محدودیت و بازگشت

ساخت اولین سفارش شامل زمان boot و آماده‌شدن TLS است و تحویل آنی نیست. سهمیه ممکن
است تا اسکن ده‌دقیقه‌ای overrun کند و صورتحساب اضافه‌ترافیک به منبع اندازه‌گیری
جدا نیاز دارد. برای توقف سفارش تازه، گروه `object-storage` یا Server شمارهٔ ۵ را
maintenance کنید. Gateway دارای tenant فعال/نگهداری‌شده و bucket مشتری در rollback
حذف نشوند. کد را از backup انتشار یا commit قبلی برگردانید، credential اصلی را حفظ
کنید و DNS را تا پایان انتقال مشتریان نگه دارید.

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

## آزمون ساعتی و پاک‌سازی — 2026-10-13

- پلن `CX33` در مکان‌های اروپایی unavailable بود. پلن ARM موجود `CAX11`
  با 2 vCPU، 4 GB RAM، دیسک 40 GB و Ubuntu 24.04 در Falkenstein انتخاب
  شد. نرخ نمایش‌داده‌شده €5.99/ماه به‌علاوه €0.50/ماه IPv4، با محاسبه
  ساعتی بود.
- سه نمونهٔ کوتاه‌عمر ساخته شد: یک نمونه با SSH key تازه، یک نمونه با
  Cloud-init و یک نمونه با SSH key قبلی پروژه. پورت 22 پس از boot پاسخ
  می‌داد، اما هر سه تلاش `Permission denied (publickey)` دادند؛ بنابراین نصب
  Gateway و آزمون S3 انجام نشد و این اجرا نباید به‌عنوان پذیرش موفق ثبت شود.
- همهٔ نمونه‌ها و Primary IPها حذف شدند. کلید SSH آزمایشی از Hetzner پاک شد؛
  فایل عمومی موقت، کلید cPanel و `~/object-test-6f46299f` هم حذف و با
  بازبینی زنده تأیید شد. فایل کلید محلی نیز به طول صفر بازنویسی شد.
- هیچ DNS، TLS، route، NAT، Cloud Firewall، UFW، credential S3، meta bucket یا سرویس
  پایداری ساخته نشد. Gateway بکاپ موجود و سرویس‌هایش دست‌نخورده ماندند.
- ۲۱ محصول Object همچنان inactive هستند. راه بازگشت این آزمون همان پاک‌سازی
  بود و اکنون هیچ منبع Object Gateway آزمایشی باقی نمانده است.

### تکرار آزمون API و مسیر جایگزین نصب

- یک `CAX11` دیگر مستقیماً با API و SSH key ثبت‌شده ساخته شد؛ وضعیت API
  `running` و پاسخ TCP/SSH تأیید شد، اما نشست پس از تبادل کلید متوقف ماند و
  امکان نصب معتبر از راه SSH فراهم نشد. این نمونه با ID `170082252` حذف شد.
- دو SSH key آزمایشی پروژه حذف، کلید cPanel پاک و کلید محلی صفر شد. موجودی
  پایداری از این آزمون باقی نماند.
- قالب `cloud-init.sh.tpl` برای نصب release پین‌شدهٔ VersityGW، سه فرایند
  منطقه‌ای، API کنترل HMAC، timer تطبیق و Nginx آماده شد. هیچ secret در Git
  نیست و placeholderها فقط هنگام ساخت ماشین از محیط خصوصی پر می‌شوند.
- این قالب هنوز با credential واقعی S3 اجرا نشده و پذیرش فروش محسوب نمی‌شود.

## فهرست تاریخی پیش از فروش زنده — تکمیل‌شده در 2026-10-14

موارد این فهرست با مدل cold start تکمیل شدند: credential اصلی ساخته و رمز‌شده
نگهداری شد؛ نصب Gateway و meta bucketها خودکار شد؛ پذیرش واقعی data plane، جداسازی،
چرخهٔ دسترسی و پاک‌سازی موفق بود؛ Server فعال و کاتالوگ ۲۱ محصول همگام شد. جزئیات
نهایی در ابتدای همین سند ثبت شده است.

## Rollback

Server تحویل را `maintenance` و محصولات را inactive کنید تا پرداخت تازه بسته شود.
Gateway و IAM را تا انتقال یا تحویل دادهٔ مشتریان روشن نگه دارید. rollback کد پنل
یا DNS نباید tenant، bucket یا credential مشتری را حذف کند. برای بازگشت نرم‌افزار،
commit قبلی پنل قابل استقرار است؛ پاک‌سازی داده فقط پس از مهلت و تأیید جدا انجام شود.
