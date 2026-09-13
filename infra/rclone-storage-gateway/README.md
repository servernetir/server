# ServerNet Backup Gateway

این پوشه لایهٔ سفیدبرچسب بین پنل ServerNet و backend ذخیره‌سازی است. مشتری
SFTP، WebDAV و S3-compatible سرورنت را می‌بیند؛ OAuth، نام حساب Google و
پیکربندی rclone روی Gateway می‌ماند. تغییر backend نباید حساب مشتری را تغییر دهد.

## مرزهای ایمنی

- API مدیریت باید پشت TLS و rate limit باشد؛ پورت 9080 عمومی نشود. وابستگی به
  allowlist ثابت IP وجود ندارد تا جابه‌جایی پنل یا Gateway ارتباط را قطع نکند.
- هر درخواست API با HMAC، زمان پنج‌دقیقه‌ای و nonce یک‌بارمصرف محافظت می‌شود.
- credential مشتری از seed مستقل و نسخهٔ قابل‌چرخش با HMAC مشتق می‌شود؛ مقدار
  خام آن در دیتابیس Gateway نیست و پنل آن را در ستون رمزنگاری‌شده نگه می‌دارد.
- حذف سرویس، داده را فوری پاک نمی‌کند؛ tenant تا پایان مهلت بازیابی `retired` می‌شود.
- ظرفیت فروش از `SN_CAPACITY_BYTES - SN_RESERVE_BYTES` بیشتر نمی‌شود.
- سهمیه در شروع نشست تازه اعمال می‌شود. اسکن `reconcile.py` باید دوره‌ای اجرا شود؛
  بنابراین این نسخه «سهمیهٔ نرم» است و نباید به‌عنوان quota لحظه‌ای تبلیغ شود.
- S3 ارائه‌شده توسط `rclone serve s3` هنوز در مستند رسمی rclone Experimental
  است؛ تا پایان آزمون سازگاری باید با برچسب beta ارائه شود، نه S3 سازمانی.
- این مسیر هاست دانلود عمومی و لینک ناشناس نیست؛ همه پروتکل‌ها احراز هویت دارند.

## استقرار خلاصه

1. روی Gateway لینوکسی Python 3 و rclone جاری آماده کنید. سرویس‌ها با
   `--vfs-cache-mode off` اجرا می‌شوند تا فایل کامل روی دیسک کوچک Gateway نماند.
2. برای هر حساب، rclone را با **Client ID اختصاصی خود ServerNet** احراز کنید.
3. remote تجمیعی و سپس remote رمزنگاری‌شدهٔ `poolcrypt` بسازید. کلید crypt باید
   خارج از میزبان نیز به‌صورت امن پشتیبان‌گیری شود؛ گم‌شدن آن یعنی از دست رفتن داده.
4. فایل‌های این پوشه را در `/opt/servernet-gateway` و env را با مجوز 0600 در
   `/etc/servernet-gateway.env` قرار دهید. secret همین env در فیلد API Token سرور
   `rclone_storage` پنل ثبت می‌شود.
5. سرویس‌های API، SFTP، WebDAV و S3 را نصب کنید. WebDAV/S3 فقط روی localhost
   می‌نشینند و از Nginx/TLS منتشر می‌شوند؛ API نیز loopback-only می‌ماند و از
   Nginx با TLS و rate limit منتشر می‌شود. احراز درخواست‌های آن با HMAC، timestamp
   و nonce یک‌بارمصرف انجام می‌شود و به IP ثابت پنل وابسته نیست.
6. `servernet-reconcile.timer` را فعال کنید تا مصرف هر ۲۰ دقیقه سنجیده شود.
7. ابتدا یک tenant آزمایشی بسازید، آپلود/دانلود متقاطع هر سه پروتکل، تعلیق،
   چرخش credential، بازیابی و پرشدن سهمیه را بسنجید.

`auth_proxy.py` از پروتکل رسمی `rclone serve sftp --auth-proxy` استفاده می‌کند و
پیکربندی remote را در لحظه از `rclone rc --loopback config/get` می‌گیرد. هیچ secret
واقعی نباید به Git یا مستندات اضافه شود.

SFTP و WebDAV یک username/password مشترک دارند. در S3، access key جداست ولی
secret همان credential رمزنگاری‌شدهٔ سرویس است. SFTP/WebDAV مستقیماً محتوای
پوشهٔ `backup` را نشان می‌دهند و همان محتوا در S3 داخل bucket به نام `backup`
دیده می‌شود.

فعال‌سازی تولیدی به دامنه، TLS، snapshot، اتصال دو حساب Google و تأیید نوع دقیق
اشتراک ذخیره‌سازی بستگی دارد. تا آن زمان محصول نباید به این Server متصل شود.
