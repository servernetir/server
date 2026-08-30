<?php

/*
|--------------------------------------------------------------------------
| برنامه‌ی محتوای پایگاه دانش (مستندات)
|--------------------------------------------------------------------------
| ۱۰۰ راهنمای عملیاتی برای سرویس‌های سرورنت. عنوان‌ها در هر سه زبان تعریف
| شده‌اند تا دسته‌بندی و تیتر در fa/en/tr همیشه یکدست بماند.
|
|   php artisan content:generate --plan=docs-plan --limit=3 --daily
|
| بخش‌ها (config/docs.php):
|   getting-started | hosting | servers | domains | email | cloud | security | tools | billing
*/

$rows = [

/* ==================== شروع کار (۸) ==================== */
['client-area-tour', 'getting-started', 'layout', 'راهنمای کامل ناحیه کاربری سرورنت', 'A complete tour of the ServerNet client area', 'ServerNet müşteri paneli tam rehberi', 'ناحیه کاربری', 'بخش‌های پنل: سرویس‌ها، فاکتورها، تیکت، دامنه‌ها و پروفایل — و کار هرکدام.'],
['activating-new-service', 'getting-started', 'rocket', 'فعال‌سازی سرویس تازه و اولین ورود', 'Activating a new service and your first login', 'Yeni hizmeti etkinleştirme ve ilk giriş', 'فعال سازی سرویس', 'پیدا کردن اطلاعات دسترسی، اولین ورود امن و تغییر رمز پیش‌فرض.'],
['finding-service-credentials', 'getting-started', 'key', 'پیدا کردن اطلاعات دسترسی سرویس', 'Finding your service credentials', 'Hizmet erişim bilgilerinizi bulma', 'اطلاعات دسترسی', 'کجای پنل دنبال نام کاربری، رمز، آی‌پی و آدرس کنترل‌پنل بگردیم.'],
['choosing-between-services', 'getting-started', 'flow', 'کدام سرویس سرورنت مناسب شماست', 'Which ServerNet service is right for you', 'Hangi ServerNet hizmeti size uygun', 'انتخاب سرویس', 'درخت تصمیم بین هاست، سرور مجازی، اختصاصی و ابری بر اساس نیاز واقعی.'],
['account-security-setup', 'getting-started', 'shield', 'ایمن‌سازی حساب کاربری در سرورنت', 'Securing your ServerNet account', 'ServerNet hesabınızı güvence altına alma', 'امنیت حساب کاربری', 'رمز قوی، ایمیل بازیابی، و اینکه پشتیبانی هرگز رمز شما را نمی‌پرسد.'],
['contacting-support-effectively', 'getting-started', 'headset', 'ثبت تیکت مؤثر و گرفتن پاسخ سریع', 'Writing an effective support ticket', 'Etkili destek talebi yazma', 'تیکت پشتیبانی', 'چه اطلاعاتی بدهیم تا مشکل در همان پاسخ اول حل شود.'],
['understanding-your-invoice', 'getting-started', 'coins', 'خواندن فاکتور و دوره‌های پرداخت', 'Understanding your invoice and billing cycles', 'Faturanızı ve ödeme döngülerini anlama', 'فاکتور', 'اجزای فاکتور، تاریخ سررسید، و تفاوت پرداخت ماهانه و سالانه.'],
['migrating-to-servernet', 'getting-started', 'restore', 'انتقال سایت از ارائه‌دهنده‌ی قبلی به سرورنت', 'Migrating to ServerNet from another provider', 'Başka bir sağlayıcıdan ServerNet\'e taşınma', 'انتقال به سرورنت', 'چک‌لیست کامل انتقال فایل، دیتابیس، ایمیل و DNS بدون قطعی.'],

/* ==================== هاست وب (۱۶) ==================== */
['cpanel-first-steps', 'hosting', 'layout', 'اولین قدم‌ها در کنترل‌پنل هاست', 'First steps in your hosting control panel', 'Hosting kontrol panelinde ilk adımlar', 'کنترل پنل هاست', 'نقشه‌ی کلی پنل و کارهایی که همان روز اول باید انجام دهید.'],
['creating-mysql-database-guide', 'hosting', 'db', 'ساخت دیتابیس MySQL و اتصال به سایت', 'Creating a MySQL database and connecting your site', 'MySQL veritabanı oluşturma ve siteye bağlama', 'ساخت دیتابیس MySQL', 'ساخت دیتابیس و کاربر، تخصیص دسترسی و مقادیر اتصال.'],
['installing-wordpress-on-hosting', 'hosting', 'cap', 'نصب وردپرس روی هاست سرورنت', 'Installing WordPress on ServerNet hosting', 'ServerNet hosting üzerine WordPress kurma', 'نصب وردپرس', 'نصب دستی و نصب سریع، و تنظیمات ضروری بعد از نصب.'],
['managing-addon-domains', 'hosting', 'globe', 'مدیریت دامنه‌های اضافه و پارک‌شده', 'Managing addon and parked domains', 'Ek ve park edilmiş alan adlarını yönetme', 'دامنه اضافه', 'تفاوت addon، parked و subdomain و ساخت درست هرکدام.'],
['setting-up-redirects', 'hosting', 'link', 'ساخت ریدایرکت در کنترل‌پنل', 'Setting up redirects in your control panel', 'Kontrol panelinde yönlendirme oluşturma', 'ریدایرکت', 'تفاوت ۳۰۱ و ۳۰۲، ریدایرکت دامنه و مسیر، و اثر سئویی.'],
['managing-php-settings', 'hosting', 'code', 'تغییر نسخه و تنظیمات PHP', 'Changing PHP version and settings', 'PHP sürümü ve ayarlarını değiştirme', 'تنظیمات PHP', 'انتخاب نسخه، افزایش memory_limit و upload_max_filesize، فعال‌کردن اکستنشن.'],
['using-file-manager', 'hosting', 'restore', 'کار با فایل‌منیجر کنترل‌پنل', 'Using the control panel File Manager', 'Kontrol paneli Dosya Yöneticisi kullanımı', 'فایل منیجر', 'آپلود، استخراج ZIP، ویرایش فایل، نمایش فایل‌های مخفی و تغییر سطح دسترسی.'],
['hosting-backup-and-restore', 'hosting', 'restore', 'بکاپ‌گیری و بازگردانی از کنترل‌پنل', 'Backing up and restoring from the control panel', 'Kontrol panelinden yedekleme ve geri yükleme', 'بکاپ هاست', 'بکاپ کامل و جزئی، دانلود نسخه‌ی محلی، و بازگردانی امن.'],
['reading-error-logs', 'hosting', 'wrench', 'خواندن لاگ خطا و پیدا کردن علت مشکل', 'Reading error logs to find the real cause', 'Gerçek nedeni bulmak için hata günlüklerini okuma', 'لاگ خطا', 'کجا لاگ را ببینیم و چطور پیام خطا را به علت واقعی ترجمه کنیم.'],
['fixing-database-connection-error', 'hosting', 'db', 'رفع خطای اتصال به دیتابیس', 'Fixing the database connection error', 'Veritabanı bağlantı hatasını çözme', 'خطای اتصال دیتابیس', 'علت‌های رایج در وردپرس و اپ‌های PHP و ترتیب بررسی.'],
['managing-disk-space', 'hosting', 'hdd', 'مدیریت فضای دیسک و پاک‌سازی هاست', 'Managing disk space and cleaning up your hosting', 'Disk alanı yönetimi ve hosting temizliği', 'فضای دیسک', 'پیدا کردن پوشه‌های حجیم، لاگ‌های قدیمی، بکاپ‌های اضافه و کش.'],
['setting-up-cron-in-cpanel', 'hosting', 'clock', 'ساخت کرون‌جاب در کنترل‌پنل', 'Creating cron jobs in your control panel', 'Kontrol panelinde cron job oluşturma', 'کرون جاب', 'ساختار زمان‌بندی، مسیر درست PHP، و گرفتن خروجی برای عیب‌یابی.'],
['password-protecting-directories', 'hosting', 'lock', 'رمزگذاری روی پوشه‌های سایت', 'Password-protecting site directories', 'Site dizinlerini parola ile koruma', 'رمزگذاری پوشه', 'محافظت از محیط تست یا پنل مدیریت با احراز هویت سطح وب‌سرور.'],
['managing-ftp-accounts', 'hosting', 'user', 'ساخت و مدیریت اکانت‌های FTP', 'Creating and managing FTP accounts', 'FTP hesapları oluşturma ve yönetme', 'اکانت FTP', 'اکانت محدود به یک پوشه برای همکاران، و حذف امن دسترسی‌ها.'],
['optimizing-wordpress-on-shared-hosting', 'hosting', 'zap', 'بهینه‌سازی وردپرس روی هاست اشتراکی', 'Optimising WordPress on shared hosting', 'Paylaşımlı hosting\'de WordPress optimizasyonu', 'بهینه سازی وردپرس', 'کش، فشرده‌سازی، پاک‌سازی دیتابیس و افزونه‌های پرمصرف.'],
['moving-site-to-new-domain', 'hosting', 'globe', 'انتقال سایت به دامنه‌ی جدید بدون افت سئو', 'Moving a site to a new domain without losing SEO', 'SEO kaybetmeden siteyi yeni alan adına taşıma', 'تغییر دامنه', 'ریدایرکت ۳۰۱ کامل، به‌روزرسانی آدرس‌های داخلی و اطلاع به گوگل.'],
['troubleshooting-white-screen', 'hosting', 'wrench', 'رفع صفحه‌ی سفید در وردپرس و PHP', 'Fixing the white screen of death', 'Beyaz ekran hatasını giderme', 'صفحه سفید', 'فعال‌کردن نمایش خطا، غیرفعال‌سازی افزونه‌ها و افزایش حافظه.'],

/* ==================== سرور مجازی و اختصاصی (۱۶) ==================== */
['connecting-windows-server-rdp-guide', 'servers', 'monitor', 'اتصال به سرور ویندوز با ریموت دسکتاپ', 'Connecting to a Windows server via Remote Desktop', 'Uzak Masaüstü ile Windows sunucuya bağlanma', 'ریموت دسکتاپ ویندوز', 'اتصال از ویندوز، مک و موبایل، و امن‌سازی پورت RDP.'],
['first-hour-server-hardening', 'servers', 'shield', 'ساعت اول یک سرور تازه: چک‌لیست امن‌سازی', 'The first hour on a new server: a hardening checklist', 'Yeni sunucuda ilk saat: sıkılaştırma listesi', 'امن سازی سرور', 'کاربر غیر root، کلید SSH، فایروال، به‌روزرسانی و fail2ban.'],
['setting-up-ssh-keys', 'servers', 'key', 'ساخت و استفاده از کلید SSH', 'Creating and using SSH keys', 'SSH anahtarı oluşturma ve kullanma', 'کلید SSH', 'ساخت جفت کلید، انتقال به سرور، و غیرفعال‌کردن امن ورود با رمز.'],
['configuring-ufw-firewall', 'servers', 'shield', 'پیکربندی فایروال UFW روی اوبونتو', 'Configuring the UFW firewall on Ubuntu', "Ubuntu'da UFW güvenlik duvarı yapılandırma", 'فایروال UFW', 'باز کردن پورت‌های لازم و جلوگیری از قفل‌شدن خودتان بیرون سرور.'],
['installing-lemp-stack', 'servers', 'server', 'نصب استک LEMP روی سرور مجازی', 'Installing a LEMP stack on your VPS', "VPS'inize LEMP yığını kurma", 'نصب LEMP', 'Nginx، MySQL و PHP-FPM از صفر با تنظیمات اولیه‌ی امن.'],
['nginx-server-blocks', 'servers', 'flow', 'تنظیم Server Block در Nginx برای چند سایت', 'Configuring Nginx server blocks for multiple sites', 'Birden çok site için Nginx server block ayarı', 'Nginx سرور بلاک', 'میزبانی چند دامنه روی یک سرور با پیکربندی جدا.'],
['managing-systemd-services', 'servers', 'cpu', 'مدیریت سرویس‌ها با systemd', 'Managing services with systemd', 'systemd ile servis yönetimi', 'systemd', 'start، stop، enable، status و خواندن لاگ با journalctl.'],
['monitoring-server-resources', 'servers', 'gauge', 'پایش مصرف CPU، RAM و دیسک سرور', 'Monitoring server CPU, RAM and disk usage', 'Sunucu CPU, RAM ve disk kullanımını izleme', 'پایش منابع سرور', 'ابزارهای top، htop، df و iostat و تفسیر اعداد.'],
['setting-up-swap-space', 'servers', 'hdd', 'ساخت فضای Swap روی سرور لینوکس', 'Setting up swap space on a Linux server', 'Linux sunucuda swap alanı oluşturma', 'swap لینوکس', 'چه زمانی لازم است، اندازه‌ی مناسب و تنظیم swappiness.'],
['automating-server-backups', 'servers', 'restore', 'خودکارسازی بکاپ سرور با اسکریپت و کرون', 'Automating server backups with scripts and cron', 'Betik ve cron ile sunucu yedeklemeyi otomatikleştirme', 'بکاپ خودکار سرور', 'بکاپ فایل و دیتابیس، چرخش نسخه‌ها و انتقال به فضای بیرونی.'],
['securing-mysql-server', 'servers', 'lock', 'امن‌سازی سرویس MySQL روی سرور', 'Securing MySQL on your server', 'Sunucunuzda MySQL\'i güvenli hale getirme', 'امنیت MySQL', 'حذف کاربر ناشناس، محدودکردن اتصال راه دور و کاربر با حداقل دسترسی.'],
['installing-lets-encrypt-certbot', 'servers', 'lock', 'نصب گواهینامه رایگان با Certbot', 'Installing a free certificate with Certbot', "Certbot ile ücretsiz sertifika kurma", 'Certbot', 'صدور، تمدید خودکار و رفع خطاهای رایج اعتبارسنجی.'],
['docker-on-vps', 'servers', 'box', 'راه‌اندازی داکر روی سرور مجازی', 'Running Docker on your VPS', "VPS'inizde Docker çalıştırma", 'داکر روی VPS', 'نصب، اجرای اولین کانتینر، ولوم و docker compose.'],
['transferring-files-with-scp-rsync', 'servers', 'restore', 'انتقال فایل با scp و rsync', 'Transferring files with scp and rsync', 'scp ve rsync ile dosya aktarımı', 'scp و rsync', 'انتقال امن، همگام‌سازی افزایشی و ادامه‌ی انتقال قطع‌شده.'],
['diagnosing-high-server-load', 'servers', 'gauge', 'تشخیص علت لود بالای سرور', 'Diagnosing high server load', 'Yüksek sunucu yükünü teşhis etme', 'لود بالای سرور', 'خواندن load average، پیدا کردن فرایند مقصر و تفکیک CPU از I/O.'],
['rebuilding-and-rescue-mode', 'servers', 'restore', 'بازنصب سرور و کار با حالت Rescue', 'Rebuilding a server and using rescue mode', 'Sunucuyu yeniden kurma ve kurtarma modu', 'حالت rescue', 'وقتی دسترسی را از دست دادید: بوت اضطراری و نجات داده‌ها.'],

/* ==================== دامنه و DNS (۱۲) ==================== */
['registering-a-domain', 'domains', 'globe', 'ثبت دامنه در سرورنت گام‌به‌گام', 'Registering a domain with ServerNet step by step', 'ServerNet ile adım adım alan adı kaydı', 'ثبت دامنه', 'انتخاب پسوند، بررسی آزاد بودن، و تکمیل اطلاعات مالک.'],
['changing-nameservers', 'domains', 'flow', 'تغییر نیم‌سرور دامنه', 'Changing your domain nameservers', 'Alan adı nameserver değiştirme', 'تغییر نیم سرور', 'کجا تغییر دهیم، چقدر طول می‌کشد و چطور اعمال شدنش را بررسی کنیم.'],
['creating-dns-records-guide', 'domains', 'code', 'ساخت رکورد DNS در پنل مدیریت', 'Creating DNS records in the management panel', 'Yönetim panelinde DNS kaydı oluşturma', 'ساخت رکورد DNS', 'افزودن A، CNAME، MX و TXT با مثال عملی برای هرکدام.'],
['domain-transfer-to-servernet', 'domains', 'restore', 'انتقال دامنه به سرورنت', 'Transferring a domain to ServerNet', "Alan adını ServerNet'e aktarma", 'انتقال دامنه', 'کد EPP، باز کردن قفل، تأیید ایمیل و مدت زمان انتقال.'],
['setting-up-subdomains', 'domains', 'flow', 'ساخت زیردامنه و اتصال آن به پوشه', 'Creating subdomains and pointing them at folders', 'Alt alan adı oluşturma ve klasöre yönlendirme', 'زیردامنه', 'ساخت زیردامنه برای بلاگ، فروشگاه یا محیط تست.'],
['domain-privacy-and-whois', 'domains', 'shield', 'حریم خصوصی دامنه و اطلاعات WHOIS', 'Domain privacy and WHOIS information', 'Alan adı gizliliği ve WHOIS bilgileri', 'حریم خصوصی دامنه', 'چه اطلاعاتی عمومی است و چطور از انتشار آن جلوگیری کنیم.'],
['renewing-and-expiring-domains', 'domains', 'clock', 'تمدید دامنه و مراحل انقضا', 'Renewing domains and the expiry stages', 'Alan adı yenileme ve sona erme aşamaları', 'تمدید دامنه', 'دوره‌ی مهلت، دوره‌ی بازیابی پرهزینه، و آزادسازی نهایی.'],
['ir-domain-specifics', 'domains', 'pin', 'نکات اختصاصی ثبت و مدیریت دامنه‌ی .ir', 'Specifics of registering and managing .ir domains', '.ir alan adı kaydı ve yönetimi özellikleri', 'دامنه ir', 'شناسه‌ی ایرنیک، تفاوت‌های فرایند و مدیریت مالکیت.'],
['dns-propagation-explained', 'domains', 'clock', 'انتشار DNS چیست و چرا زمان می‌برد', 'What DNS propagation is and why it takes time', 'DNS yayılımı nedir ve neden zaman alır', 'انتشار DNS', 'نقش TTL و کش، و کاهش TTL قبل از مهاجرت.'],
['wildcard-and-catchall-dns', 'domains', 'globe', 'رکورد Wildcard و کاربردهای آن', 'Wildcard DNS records and when to use them', 'Wildcard DNS kayıtları ve kullanım alanları', 'رکورد wildcard', 'زیردامنه‌ی پویا برای سرویس‌های چندمستأجری و ریسک‌های آن.'],
['pointing-domain-to-vps', 'domains', 'server', 'اتصال دامنه به سرور مجازی', 'Pointing a domain at your VPS', 'Alan adını VPS\'e yönlendirme', 'اتصال دامنه به سرور', 'رکورد A، تنظیم وب‌سرور و صدور گواهینامه بعد از اتصال.'],
['fixing-dns-misconfiguration', 'domains', 'wrench', 'عیب‌یابی تنظیمات اشتباه DNS', 'Troubleshooting DNS misconfiguration', 'Hatalı DNS yapılandırmasını giderme', 'عیب یابی DNS', 'نشانه‌های هر خطا و ترتیب بررسی رکوردها برای رسیدن به علت.'],

/* ==================== ایمیل (۱۰) ==================== */
['creating-email-accounts', 'email', 'mail', 'ساخت ایمیل سازمانی روی دامنه‌ی خودتان', 'Creating business email accounts on your own domain', 'Kendi alan adınızda kurumsal e-posta oluşturma', 'ساخت ایمیل سازمانی', 'ساخت صندوق، تعیین سهمیه و رمز قوی.'],
['email-client-configuration', 'email', 'monitor', 'تنظیم ایمیل در اوت‌لوک، موبایل و Thunderbird', 'Configuring email in Outlook, mobile and Thunderbird', 'Outlook, mobil ve Thunderbird e-posta ayarları', 'تنظیم کلاینت ایمیل', 'تفاوت IMAP و POP3، پورت‌های امن، و خطاهای رایج اتصال.'],
['email-forwarding-and-aliases', 'email', 'flow', 'فوروارد ایمیل و ساخت آدرس مستعار', 'Email forwarding and aliases', 'E-posta yönlendirme ve takma adlar', 'فوروارد ایمیل', 'ارسال خودکار به آدرس دیگر و ساخت آدرس‌های بخشی مثل info و sales.'],
['fighting-email-spam', 'email', 'shield', 'کاهش اسپم دریافتی روی ایمیل سازمانی', 'Reducing incoming spam on business email', 'Kurumsal e-postada gelen spam\'i azaltma', 'ضد اسپم', 'فیلترها، لیست سیاه و سفید، و تنظیم حساسیت بدون از دست دادن ایمیل واقعی.'],
['why-emails-go-to-spam', 'email', 'wrench', 'چرا ایمیل‌های ارسالی ما به اسپم می‌رود', 'Why your outgoing email lands in spam', 'Giden e-postanız neden spam\'e düşüyor', 'ایمیل به اسپم', 'احراز فرستنده، شهرت آی‌پی، محتوای پیام و لیست سیاه.'],
['setting-up-autoresponder', 'email', 'clock', 'تنظیم پاسخ خودکار ایمیل', 'Setting up an email autoresponder', 'E-posta otomatik yanıtlayıcı ayarlama', 'پاسخ خودکار', 'پیام غیبت و پاسخ خودکار بخش فروش با بازه‌ی زمانی.'],
['email-backup-and-migration', 'email', 'restore', 'بکاپ و انتقال صندوق‌های ایمیل', 'Backing up and migrating mailboxes', 'Posta kutularını yedekleme ve taşıma', 'انتقال ایمیل', 'انتقال IMAP بدون از دست رفتن پوشه‌ها و پیام‌ها.'],
['webmail-guide', 'email', 'globe', 'کار با وبمیل و امکانات آن', 'Using webmail and its features', 'Webmail kullanımı ve özellikleri', 'وبمیل', 'دسترسی از مرورگر، پوشه‌بندی، جستجو و تنظیمات شخصی.'],
['transactional-email-setup', 'email', 'send', 'راه‌اندازی ایمیل تراکنشی برای سایت', 'Setting up transactional email for your site', 'Siteniz için işlemsel e-posta kurulumu', 'ایمیل تراکنشی', 'چرا ایمیل سیستمی سایت را از هاست نفرستیم و جایگزین درست چیست.'],
['email-storage-quota-management', 'email', 'hdd', 'مدیریت سهمیه و فضای صندوق ایمیل', 'Managing mailbox quota and storage', 'Posta kutusu kotası ve depolama yönetimi', 'سهمیه ایمیل', 'وقتی صندوق پر می‌شود چه اتفاقی می‌افتد و چطور فضا آزاد کنیم.'],

/* ==================== سرویس‌های ابری (۱۲) ==================== */
['getting-started-with-cloud-servers', 'cloud', 'cloud', 'شروع کار با سرور ابری سرورنت', 'Getting started with ServerNet cloud servers', 'ServerNet bulut sunucularıyla başlangıç', 'سرور ابری', 'ساخت اولین اینستنس، انتخاب ایمیج و اتصال اولیه.'],
['object-storage-setup', 'cloud', 'db', 'راه‌اندازی فضای ذخیره‌سازی آبجکت', 'Setting up object storage', 'Nesne depolama kurulumu', 'استوریج آبجکت', 'ساخت باکت، کلید دسترسی و اتصال از اپلیکیشن.'],
['using-block-storage-volumes', 'cloud', 'hdd', 'افزودن و مدیریت دیسک بلاک', 'Adding and managing block storage volumes', 'Blok depolama birimleri ekleme ve yönetme', 'دیسک بلاک', 'اتصال، فرمت، مانت دائمی و افزایش حجم بدون از دست رفتن داده.'],
['deploying-with-kubernetes', 'cloud', 'box', 'استقرار اپلیکیشن روی کوبرنتیز', 'Deploying an application on Kubernetes', "Kubernetes'te uygulama dağıtma", 'استقرار کوبرنتیز', 'اولین deployment، سرویس و اکسپوز کردن به بیرون.'],
['configuring-cdn', 'cloud', 'globe', 'راه‌اندازی CDN برای فایل‌های استاتیک', 'Configuring a CDN for static assets', 'Statik dosyalar için CDN yapılandırma', 'راه اندازی CDN', 'اتصال دامنه، تنظیم کش و باطل‌سازی محتوای قدیمی.'],
['enabling-ddos-protection', 'cloud', 'shield', 'فعال‌سازی محافظت DDoS', 'Enabling DDoS protection', 'DDoS korumasını etkinleştirme', 'محافظت DDoS', 'سطوح محافظت، اثر روی ترافیک عادی و پایش حمله.'],
['cloud-snapshots-and-images', 'cloud', 'restore', 'اسنپ‌شات و ایمیج سفارشی در فضای ابری', 'Snapshots and custom images in the cloud', 'Bulutta anlık görüntüler ve özel imajlar', 'اسنپ شات', 'گرفتن اسنپ‌شات قبل از تغییر پرخطر و بازگردانی سریع.'],
['setting-up-load-balancer', 'cloud', 'flow', 'راه‌اندازی لود بالانسر', 'Setting up a load balancer', 'Load balancer kurulumu', 'لود بالانسر', 'افزودن سرورها، health check و ختم SSL روی بالانسر.'],
['private-networking-setup', 'cloud', 'lock', 'شبکه‌ی خصوصی بین سرورها', 'Private networking between servers', 'Sunucular arası özel ağ', 'شبکه خصوصی', 'ارتباط امن بین وب و دیتابیس بدون عبور از اینترنت عمومی.'],
['gpu-server-setup-for-ai', 'cloud', 'cpu', 'راه‌اندازی سرور GPU برای مدل‌های هوش مصنوعی', 'Setting up a GPU server for AI models', 'AI modelleri için GPU sunucu kurulumu', 'سرور GPU', 'نصب درایور، CUDA و اجرای اولین بار کاری.'],
['cloud-monitoring-and-alerts', 'cloud', 'gauge', 'پایش و هشدار در زیرساخت ابری', 'Monitoring and alerting for cloud infrastructure', 'Bulut altyapısı için izleme ve uyarı', 'مانیتورینگ ابری', 'شاخص‌های کلیدی، آستانه‌ها و کانال اطلاع‌رسانی.'],
['scaling-cloud-resources', 'cloud', 'trend', 'مقیاس‌دهی منابع ابری بدون قطعی', 'Scaling cloud resources without downtime', 'Kesintisiz bulut kaynak ölçeklendirme', 'مقیاس دهی', 'ارتقای عمودی، افزودن نود و آماده‌سازی اپ برای مقیاس افقی.'],

/* ==================== امنیت و SSL (۱۲) ==================== */
['ssl-certificate-types-guide', 'security', 'lock', 'انتخاب نوع گواهینامه SSL مناسب', 'Choosing the right type of SSL certificate', 'Doğru SSL sertifika türünü seçme', 'انواع SSL', 'تفاوت DV، OV، EV و Wildcard و انتخاب بر اساس نوع سایت.'],
['fixing-mixed-content', 'security', 'wrench', 'رفع خطای محتوای ترکیبی بعد از فعال‌سازی SSL', 'Fixing mixed content errors after enabling SSL', 'SSL sonrası karışık içerik hatalarını düzeltme', 'محتوای ترکیبی', 'پیدا کردن منابع http با کنسول مرورگر و اصلاح آدرس‌ها در وردپرس.'],
['renewing-ssl-certificates', 'security', 'clock', 'تمدید گواهینامه SSL و رفع انقضا', 'Renewing SSL certificates and handling expiry', 'SSL sertifikası yenileme ve süre dolumu', 'تمدید SSL', 'تمدید خودکار، هشدار انقضا و اقدام وقتی گواهی منقضی شده.'],
['securing-wordpress-site', 'security', 'shield', 'امن‌سازی سایت وردپرسی گام‌به‌گام', 'Securing a WordPress site step by step', 'WordPress sitesini adım adım güvenceye alma', 'امنیت وردپرس', 'به‌روزرسانی، محدودکردن ورود، سطح دسترسی فایل و پنهان‌کردن نسخه.'],
['detecting-hacked-website', 'security', 'search', 'تشخیص هک شدن سایت', 'Detecting a hacked website', 'Hacklenmiş web sitesini tespit etme', 'تشخیص هک', 'نشانه‌ها: فایل ناشناس، ریدایرکت عجیب، افت رتبه و هشدار گوگل.'],
['cleaning-hacked-site', 'security', 'wrench', 'پاک‌سازی سایت هک‌شده و بستن راه نفوذ', 'Cleaning a hacked site and closing the entry point', 'Hacklenmiş siteyi temizleme ve giriş noktasını kapatma', 'پاکسازی سایت هک شده', 'ترتیب درست: ایزوله، بکاپ، پاک‌سازی، تغییر رمزها، بستن آسیب‌پذیری.'],
['setting-up-2fa', 'security', 'key', 'فعال‌سازی احراز هویت دو مرحله‌ای', 'Enabling two-factor authentication', 'İki faktörlü kimlik doğrulamayı etkinleştirme', 'احراز هویت دو مرحله ای', 'روی حساب کاربری، کنترل‌پنل و پنل مدیریت سایت.'],
['configuring-security-headers', 'security', 'shield', 'تنظیم هدرهای امنیتی سایت', 'Configuring website security headers', 'Web sitesi güvenlik başlıklarını yapılandırma', 'هدر امنیتی', 'CSP، HSTS و X-Frame-Options بدون شکستن قابلیت‌های سایت.'],
['blocking-malicious-ips', 'security', 'x', 'مسدودکردن آی‌پی‌های مخرب', 'Blocking malicious IP addresses', 'Kötü niyetli IP adreslerini engelleme', 'مسدودسازی آی پی', 'مسدودسازی در کنترل‌پنل، htaccess و فایروال سرور.'],
['secure-file-permissions', 'security', 'lock', 'تنظیم امن سطح دسترسی فایل‌ها', 'Setting secure file permissions', 'Güvenli dosya izinleri ayarlama', 'سطح دسترسی امن', 'مقادیر درست، خطر ۷۷۷ و اصلاح گروهی با find.'],
['ssl-troubleshooting', 'security', 'wrench', 'عیب‌یابی خطاهای SSL در مرورگر', 'Troubleshooting SSL errors in the browser', 'Tarayıcıdaki SSL hatalarını giderme', 'خطای SSL', 'زنجیره‌ی ناقص، عدم تطابق نام دامنه و گواهی منقضی.'],
['gdpr-cookie-compliance', 'security', 'book', 'صفحات قانونی و اطلاع‌رسانی کوکی در سایت', 'Legal pages and cookie notices for your site', 'Siteniz için yasal sayfalar ve çerez bildirimleri', 'حریم خصوصی سایت', 'حریم خصوصی، شرایط استفاده و اطلاع‌رسانی شفاف کوکی.'],

/* ==================== ابزارهای سرورنت (۸) ==================== */
['using-network-security-checker', 'tools', 'shield', 'کار با ابزار بررسی شبکه و امنیت', 'Using the network and security checker', 'Ağ ve güvenlik denetleyicisini kullanma', 'بررسی شبکه', 'بررسی SSL، پورت‌های باز، پینگ و DNSSEC و تفسیر نتایج.'],
['using-whois-lookup', 'tools', 'search', 'استعلام WHOIS دامنه', 'Looking up domain WHOIS records', 'Alan adı WHOIS sorgulama', 'استعلام whois', 'مالک، تاریخ انقضا، نیم‌سرور و وضعیت قفل دامنه.'],
['using-site-builder', 'tools', 'layout', 'ساخت سایت با سایت‌ساز هوشمند سرورنت', 'Building a site with the ServerNet AI site builder', 'ServerNet yapay zeka site kurucu ile site oluşturma', 'سایت ساز', 'از توضیح کسب‌وکار تا خروجی، و ویرایش نتیجه.'],
['using-rustdesk-remote', 'tools', 'monitor', 'اتصال از راه دور با RustDesk', 'Remote access with RustDesk', 'RustDesk ile uzaktan erişim', 'دسکتاپ ریموت', 'نصب، شناسه و رمز، و اتصال امن به کامپیوتر یا سرور.'],
['using-cloud-phone', 'tools', 'phone', 'راه‌اندازی تلفن ابری برای کسب‌وکار', 'Setting up cloud telephony for your business', 'İşletmeniz için bulut telefon kurulumu', 'تلفن ابری', 'داخلی‌ها، منوی صوتی و انتقال تماس به موبایل.'],
['using-bpmn-designer', 'tools', 'flow', 'مدل‌سازی فرایند با طراح BPMN', 'Modelling processes with the BPMN designer', 'BPMN tasarımcısı ile süreç modelleme', 'طراح BPMN', 'ساخت نمودار فرایند و خروجی استاندارد برای تیم.'],
['checking-website-speed', 'tools', 'gauge', 'سنجش سرعت سایت و تفسیر نتیجه', 'Measuring website speed and reading the results', 'Site hızını ölçme ve sonuçları okuma', 'تست سرعت سایت', 'تفاوت داده‌ی آزمایشگاهی و واقعی و اولویت‌بندی اصلاحات.'],
['using-seo-audit-tool', 'tools', 'trend', 'کار با ابزار بررسی سئوی سایت', 'Using the site SEO audit tool', 'Site SEO denetim aracını kullanma', 'بررسی سئو', 'گزارش عنوان، متا، هدینگ و لینک‌ها و رفع مشکلات یافته‌شده.'],

/* ==================== حساب و مالی (۶) ==================== */
['payment-methods-guide', 'billing', 'coins', 'روش‌های پرداخت و تسویه در سرورنت', 'Payment methods at ServerNet', "ServerNet'te ödeme yöntemleri", 'روش پرداخت', 'درگاه‌های موجود، تأیید پرداخت و پیگیری تراکنش ناموفق.'],
['upgrading-service-plan', 'billing', 'trend', 'ارتقای پلن سرویس بدون از دست دادن داده', 'Upgrading your service plan without losing data', 'Veri kaybetmeden hizmet paketini yükseltme', 'ارتقای پلن', 'محاسبه‌ی تناسبی هزینه، زمان اعمال و نیاز یا عدم نیاز به قطعی.'],
['cancelling-a-service', 'billing', 'x', 'لغو سرویس و نکات پیش از آن', 'Cancelling a service and what to do first', 'Hizmeti iptal etme ve öncesinde yapılacaklar', 'لغو سرویس', 'گرفتن بکاپ کامل، انتقال دامنه و زمان‌بندی درست لغو.'],
['refund-policy-explained', 'billing', 'book', 'شرایط بازگشت وجه و ضمانت', 'Refund policy and guarantee explained', 'İade politikası ve garanti açıklaması', 'بازگشت وجه', 'چه سرویس‌هایی مشمول‌اند، بازه‌ی زمانی و روند درخواست.'],
['managing-multiple-services', 'billing', 'layout', 'مدیریت چند سرویس در یک حساب کاربری', 'Managing multiple services in one account', 'Tek hesapta birden fazla hizmeti yönetme', 'مدیریت سرویس ها', 'سازمان‌دهی، هماهنگ‌کردن تاریخ تمدیدها و جلوگیری از فراموشی.'],
['transferring-service-ownership', 'billing', 'user', 'انتقال مالکیت سرویس به شخص دیگر', 'Transferring service ownership to someone else', 'Hizmet sahipliğini başkasına devretme', 'انتقال مالکیت', 'مدارک لازم، مراحل تأیید و نکات امنیتی انتقال.'],

];

return array_map(fn ($r) => [
    'slug'     => $r[0],
    'type'     => 'kb',              // پایگاه دانش
    'category' => $r[1],             // کلید بخش در config/docs.php
    'icon'     => $r[2],
    'fa'       => $r[3],
    'en'       => $r[4],
    'tr'       => $r[5],
    'keyword'  => $r[6],
    'brief'    => $r[7],
], $rows);
