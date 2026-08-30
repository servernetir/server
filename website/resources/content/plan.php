<?php

/*
|--------------------------------------------------------------------------
| برنامه‌ی محتوای بلاگ سرورنت
|--------------------------------------------------------------------------
| هر آیتم یک مقاله است. عنوان‌ها در هر سه زبان اینجا تعریف شده‌اند تا
| دسته‌بندی و عنوان در fa/en/tr همیشه یکدست بماند.
|
|   php artisan content:generate --limit=3      تولید ۳ مقاله‌ی بعدی
|   php artisan content:generate --dry          فقط نمایش صف
|   php artisan content:publish-due             انتشار موارد سررسیده
|
| دسته‌ها: hosting | cloud | security | seo | tutorial | tech | business
*/

$t = fn (string $slug, string $cat, string $icon, string $fa, string $en, string $tr, string $kw, string $brief) => compact('slug', 'cat', 'icon', 'fa', 'en', 'tr', 'kw', 'brief');

$rows = [

/* ============================ هاست و سرور ============================ */
['choosing-web-hosting-for-business', 'hosting', 'server', 'چطور هاست مناسب کسب‌وکارمان را انتخاب کنیم', 'How to choose the right web hosting for your business', 'İşletmeniz için doğru web hosting nasıl seçilir', 'انتخاب هاست', 'معیارهای واقعی انتخاب: منابع، لوکیشن، پشتیبانی، آپتایم، محدودیت‌ها. با یک جدول مقایسه‌ی سناریوهای مختلف کسب‌وکار.'],
['shared-vs-vps-vs-dedicated', 'hosting', 'cpu', 'تفاوت هاست اشتراکی، سرور مجازی و اختصاصی', 'Shared hosting vs VPS vs dedicated server', 'Paylaşımlı hosting, VPS ve fiziksel sunucu farkı', 'هاست اشتراکی یا سرور مجازی', 'مقایسه‌ی فنی و اقتصادی سه گزینه، با نشانه‌های عملی اینکه کِی وقت مهاجرت به سطح بالاتر است.'],
['when-to-upgrade-hosting-plan', 'hosting', 'trend', 'چه زمانی باید پلن هاست را ارتقا دهیم', 'When it is time to upgrade your hosting plan', 'Hosting paketinizi ne zaman yükseltmelisiniz', 'ارتقای هاست', 'نشانه‌های واقعی کمبود منابع، تشخیص اینکه کندی از هاست است یا از کد سایت، و روش سنجش.'],
['reduce-server-response-time', 'hosting', 'zap', 'کاهش زمان پاسخ سرور (TTFB) — راهنمای عملی', 'Reducing server response time (TTFB) — a practical guide', 'Sunucu yanıt süresini (TTFB) azaltma — pratik kılavuz', 'کاهش TTFB', 'علت‌های رایج TTFB بالا، اندازه‌گیری درست، و راهکارهای کش، دیتابیس و PHP.'],
['website-migration-without-downtime', 'hosting', 'restore', 'انتقال سایت بین دو هاست بدون قطعی', 'Migrating a website between hosts with zero downtime', 'Siteyi kesintisiz olarak hostlar arasında taşıma', 'انتقال سایت', 'ترتیب درست: کپی، تست با فایل hosts، کاهش TTL، سوییچ، و بازگشت اضطراری.'],
['understanding-hosting-resource-limits', 'hosting', 'gauge', 'محدودیت‌های منابع هاست: CPU، RAM، Entry Process و I/O', 'Hosting resource limits explained: CPU, RAM, entry processes and I/O', 'Hosting kaynak limitleri: CPU, RAM, entry process ve I/O', 'محدودیت منابع هاست', 'هر محدودیت یعنی چه، چه خطایی تولید می‌کند و چطور مصرف را کم کنیم.'],
['fixing-500-internal-server-error', 'hosting', 'wrench', 'رفع خطای 500 Internal Server Error', 'Fixing the 500 Internal Server Error', '500 Internal Server Error hatasını çözme', 'خطای 500', 'مسیر عیب‌یابی گام‌به‌گام: لاگ خطا، htaccess، سطح دسترسی، حافظه‌ی PHP و افزونه‌ها.'],
['mysql-database-backup-restore', 'hosting', 'db', 'بکاپ‌گیری و بازیابی دیتابیس MySQL', 'Backing up and restoring a MySQL database', 'MySQL veritabanı yedekleme ve geri yükleme', 'بکاپ دیتابیس', 'روش کنترل‌پنل و روش دستور خطی، بازیابی دیتابیس بزرگ، و خطاهای رایج import.'],
['choosing-server-location', 'hosting', 'pin', 'انتخاب لوکیشن سرور و تأثیرش بر سرعت و سئو', 'Choosing a server location and its effect on speed and SEO', 'Sunucu lokasyonu seçimi: hız ve SEO etkisi', 'لوکیشن سرور', 'رابطه‌ی لوکیشن با پینگ، تجربه‌ی کاربر و سیگنال‌های سئوی محلی.'],
['cpanel-vs-directadmin', 'hosting', 'layout', 'مقایسه‌ی کنترل‌پنل سی‌پنل و دایرکت‌ادمین', 'cPanel vs DirectAdmin compared', 'cPanel ve DirectAdmin karşılaştırması', 'سی‌پنل یا دایرکت ادمین', 'تفاوت امکانات، منابع مصرفی، و اینکه برای چه کسی کدام مناسب‌تر است.'],
['wordpress-hosting-optimization', 'hosting', 'zap', 'بهینه‌سازی هاست برای وردپرس', 'Optimising hosting for WordPress', 'WordPress için hosting optimizasyonu', 'بهینه سازی وردپرس', 'نسخه‌ی PHP، OPcache، کش آبجکت، محدودیت حافظه و افزونه‌های سنگین.'],
['understanding-uptime-sla', 'hosting', 'shield', 'آپتایم و SLA — اعداد واقعاً یعنی چه', 'Uptime and SLA — what the numbers really mean', 'Uptime ve SLA — sayılar gerçekte ne anlama gelir', 'آپتایم', 'محاسبه‌ی دقیقه‌های قطعی مجاز در هر سطح آپتایم و اینکه SLA چه چیزی را پوشش نمی‌دهد.'],
['website-backup-strategy', 'hosting', 'restore', 'استراتژی بکاپ سایت: قانون ۳-۲-۱', 'A website backup strategy: the 3-2-1 rule', 'Web sitesi yedekleme stratejisi: 3-2-1 kuralı', 'بکاپ سایت', 'چند نسخه، کجا، چند وقت یکبار، و مهم‌تر از همه: تست بازیابی.'],
['php-version-upgrade-guide', 'hosting', 'code', 'ارتقای نسخه‌ی PHP بدون شکستن سایت', 'Upgrading your PHP version without breaking the site', 'Siteyi bozmadan PHP sürümünü yükseltme', 'ارتقای PHP', 'بررسی سازگاری، محیط تست، و برگرداندن سریع در صورت مشکل.'],
['file-permissions-explained', 'hosting', 'lock', 'سطح دسترسی فایل‌ها (۷۵۵، ۶۴۴) و چرا ۷۷۷ خطرناک است', 'File permissions explained: 755, 644 and why 777 is dangerous', 'Dosya izinleri: 755, 644 ve 777 neden tehlikeli', 'سطح دسترسی فایل', 'معنای هر رقم، مقادیر درست برای فایل و پوشه، و پیامد امنیتی ۷۷۷.'],
['reseller-hosting-business', 'hosting', 'coins', 'راه‌اندازی کسب‌وکار هاست نمایندگی', 'Starting a reseller hosting business', 'Bayi hosting işine başlamak', 'هاست نمایندگی', 'مدل درآمدی، انتخاب پلن، قیمت‌گذاری و چالش‌های پشتیبانی.'],
['email-hosting-vs-web-hosting', 'hosting', 'mail', 'تفاوت هاست ایمیل و هاست وب', 'Email hosting vs web hosting', 'E-posta hosting ve web hosting farkı', 'هاست ایمیل', 'چرا جداکردن ایمیل از هاست سایت معمولاً تصمیم بهتری است.'],
['nvme-vs-ssd-hosting', 'hosting', 'hdd', 'تفاوت NVMe و SSD در هاستینگ', 'NVMe vs SSD in hosting', "Hosting'de NVMe ve SSD farkı", 'NVMe یا SSD', 'تفاوت واقعی در IOPS و تأخیر، و اینکه چه زمانی این تفاوت به چشم می‌آید.'],
['staging-environment-setup', 'hosting', 'flow', 'ساخت محیط تست (Staging) برای سایت', 'Setting up a staging environment for your site', 'Siteniz için staging ortamı kurma', 'محیط استیجینگ', 'چرا مستقیم روی سایت زنده تغییر ندهیم و چطور محیط تست بسازیم.'],
['cron-jobs-guide', 'hosting', 'clock', 'راهنمای کرون‌جاب: زمان‌بندی وظایف روی سرور', 'Cron jobs guide: scheduling tasks on your server', 'Cron job kılavuzu: sunucuda görev zamanlama', 'کرون جاب', 'ساختار زمان‌بندی، خطاهای رایج مسیر، و لاگ‌گرفتن از خروجی.'],
['htaccess-essential-rules', 'hosting', 'code', 'قواعد کاربردی htaccess برای هر سایت', 'Essential .htaccess rules for every site', 'Her site için temel .htaccess kuralları', 'htaccess', 'ریدایرکت، فشرده‌سازی، کش مرورگر، محافظت از فایل‌های حساس.'],
['diagnosing-slow-website', 'hosting', 'gauge', 'چرا سایتم کند است؟ راهنمای عیب‌یابی', 'Why is my website slow? A diagnostic guide', 'Sitem neden yavaş? Teşhis kılavuzu', 'سایت کند', 'جداکردن مشکل شبکه از سرور از کد، با ابزارهای اندازه‌گیری مشخص.'],
['domain-transfer-guide', 'hosting', 'globe', 'انتقال دامنه بین ثبت‌کننده‌ها', 'Transferring a domain between registrars', 'Alan adını kayıt firmaları arasında taşıma', 'انتقال دامنه', 'کد EPP، قفل انتقال، محدودیت ۶۰ روز و نکته‌های زمان‌بندی.'],
['subdomain-vs-subdirectory', 'hosting', 'flow', 'زیردامنه یا زیرپوشه؟ کدام برای سئو بهتر است', 'Subdomain or subdirectory? Which is better for SEO', 'Alt alan adı mı alt dizin mi? SEO için hangisi daha iyi', 'زیردامنه یا زیرپوشه', 'تفاوت از دید گوگل و از دید معماری فنی، با سناریوهای واقعی.'],

/* ============================ ابر و زیرساخت ============================ */
['what-is-iaas-paas-saas', 'cloud', 'cloud', 'تفاوت IaaS، PaaS و SaaS به زبان ساده', 'IaaS, PaaS and SaaS explained simply', 'IaaS, PaaS ve SaaS basitçe açıklandı', 'IaaS چیست', 'مرز مسئولیت‌ها در هر مدل و اینکه سازمان شما کدام را لازم دارد.'],
['kubernetes-for-beginners', 'cloud', 'box', 'کوبرنتیز برای شروع: چه مشکلی را حل می‌کند', 'Kubernetes for beginners: what problem it solves', 'Yeni başlayanlar için Kubernetes: hangi sorunu çözer', 'کوبرنتیز', 'مفاهیم پاد، سرویس و دیپلویمنت — و اینکه کِی کوبرنتیز بیش از حد است.'],
['docker-basics-for-web-developers', 'cloud', 'box', 'داکر برای توسعه‌دهندگان وب — از صفر', 'Docker basics for web developers', 'Web geliştiricileri için Docker temelleri', 'داکر', 'ایمیج، کانتینر، ولوم و Dockerfile با مثال یک اپ PHP.'],
['object-storage-vs-block-storage', 'cloud', 'db', 'تفاوت فضای ذخیره‌سازی آبجکت و بلاک', 'Object storage vs block storage', 'Nesne depolama ve blok depolama farkı', 'استوریج آبجکت', 'کاربرد هرکدام، مدل هزینه و اینکه فایل‌های سایت را کجا نگه داریم.'],
['what-is-cdn-and-when-to-use', 'cloud', 'globe', 'CDN چیست و چه زمانی واقعاً به آن نیاز داریم', 'What a CDN is and when you actually need one', 'CDN nedir ve gerçekten ne zaman gerekir', 'CDN چیست', 'مکانیزم کش لبه، تأثیر واقعی بر سرعت، و مواردی که CDN کمکی نمی‌کند.'],
['ddos-protection-explained', 'cloud', 'shield', 'حملات DDoS و روش‌های محافظت', 'DDoS attacks and how protection works', 'DDoS saldırıları ve korunma yöntemleri', 'حمله DDoS', 'انواع حمله در لایه‌های مختلف و اینکه چه چیزی واقعاً جلوی آن را می‌گیرد.'],
['autoscaling-concepts', 'cloud', 'trend', 'مقیاس‌پذیری خودکار: افقی یا عمودی', 'Autoscaling: horizontal vs vertical', 'Otomatik ölçeklendirme: yatay mı dikey mi', 'مقیاس پذیری', 'تفاوت دو رویکرد، پیش‌نیازهای معماری و دام‌های رایج.'],
['load-balancer-basics', 'cloud', 'flow', 'لود بالانسر چیست و چطور کار می‌کند', 'What a load balancer is and how it works', 'Load balancer nedir ve nasıl çalışır', 'لود بالانسر', 'الگوریتم‌های توزیع بار، health check و مدیریت نشست.'],
['gpu-servers-for-ai', 'cloud', 'cpu', 'سرور GPU برای هوش مصنوعی — چه زمانی لازم است', 'GPU servers for AI — when you actually need one', 'AI için GPU sunucular — gerçekten ne zaman gerekir', 'سرور GPU', 'تفاوت آموزش و استنتاج مدل، و معیار انتخاب کارت گرافیک.'],
['cloud-cost-optimization', 'cloud', 'coins', 'کاهش هزینه‌های زیرساخت ابری', 'Cutting cloud infrastructure costs', 'Bulut altyapı maliyetlerini düşürme', 'کاهش هزینه ابری', 'منابع بلااستفاده، اندازه‌گذاری درست، و پایش مصرف.'],
['hybrid-cloud-for-enterprises', 'cloud', 'factory', 'ابر ترکیبی برای سازمان‌ها', 'Hybrid cloud for enterprises', 'Kurumlar için hibrit bulut', 'ابر ترکیبی', 'چرا سازمان‌ها بخشی از بار را داخلی نگه می‌دارند و معماری اتصال.'],
['disaster-recovery-planning', 'cloud', 'restore', 'برنامه‌ی بازیابی از فاجعه: RTO و RPO', 'Disaster recovery planning: RTO and RPO', 'Felaket kurtarma planı: RTO ve RPO', 'بازیابی از فاجعه', 'تعریف دو شاخص کلیدی و طراحی برنامه بر اساس آن‌ها.'],
['infrastructure-as-code-intro', 'cloud', 'code', 'زیرساخت به‌عنوان کد (IaC) — شروع کار', 'Infrastructure as code: getting started', 'Kod olarak altyapı (IaC): başlangıç', 'زیرساخت به عنوان کد', 'چرا پیکربندی دستی سرور مشکل‌ساز است و IaC چه چیزی را حل می‌کند.'],
['monitoring-and-alerting-setup', 'cloud', 'gauge', 'مانیتورینگ سرور و هشداردهی مؤثر', 'Server monitoring and effective alerting', 'Sunucu izleme ve etkili uyarı sistemi', 'مانیتورینگ سرور', 'چه چیزی را پایش کنیم، آستانه‌ها، و پرهیز از خستگی هشدار.'],

/* ============================ امنیت ============================ */
['securing-a-new-linux-server', 'security', 'shield', 'سخت‌سازی سرور لینوکس تازه — چک‌لیست اولیه', 'Hardening a new Linux server: the first-hour checklist', 'Yeni Linux sunucusunu sıkılaştırma: ilk saat kontrol listesi', 'امنیت سرور لینوکس', 'کاربر غیر روت، کلید SSH، فایروال، به‌روزرسانی خودکار و fail2ban.'],
['wordpress-security-checklist', 'security', 'lock', 'چک‌لیست امنیت وردپرس', 'The WordPress security checklist', 'WordPress güvenlik kontrol listesi', 'امنیت وردپرس', 'مسیرهای رایج نفوذ و اقدام‌های مؤثر به‌ترتیب اولویت.'],
['two-factor-authentication-guide', 'security', 'key', 'احراز هویت دو مرحله‌ای — چرا و چگونه', 'Two-factor authentication: why and how', 'İki faktörlü kimlik doğrulama: neden ve nasıl', 'احراز هویت دو مرحله ای', 'تفاوت روش‌های پیامکی، اپلیکیشنی و کلید سخت‌افزاری.'],
['recognising-phishing-attacks', 'security', 'shield', 'شناسایی حملات فیشینگ', 'Recognising phishing attacks', 'Kimlik avı saldırılarını tanıma', 'فیشینگ', 'نشانه‌های عملی در ایمیل و دامنه، و اینکه هیچ ارائه‌دهنده‌ای رمز شما را نمی‌پرسد.'],
['firewall-rules-basics', 'security', 'shield', 'قواعد فایروال: باز و بسته کردن پورت‌ها', 'Firewall rules: opening and closing ports', 'Güvenlik duvarı kuralları: port açma ve kapatma', 'فایروال', 'اصل کمترین دسترسی، پورت‌های ضروری و خطای قفل‌شدن از سرور.'],
['sql-injection-prevention', 'security', 'code', 'جلوگیری از تزریق SQL', 'Preventing SQL injection', "SQL enjeksiyonunu önleme", 'تزریق SQL', 'چرا کوئری پارامتری تنها راه‌حل واقعی است، با مثال کد.'],
['malware-cleanup-website', 'security', 'wrench', 'پاک‌سازی سایت آلوده به بدافزار', 'Cleaning up a malware-infected website', 'Kötü amaçlı yazılım bulaşmış siteyi temizleme', 'پاکسازی بدافزار', 'ترتیب درست: ایزوله، شناسایی، پاک‌سازی، بستن راه ورود.'],
['password-management-for-teams', 'security', 'key', 'مدیریت رمز عبور در تیم‌ها', 'Password management for teams', 'Ekipler için parola yönetimi', 'مدیریت رمز عبور', 'چرا اشتراک رمز در چت خطرناک است و جایگزین‌های درست.'],
['understanding-ssl-certificate-types', 'security', 'lock', 'انواع گواهینامه SSL: DV، OV و EV', 'SSL certificate types: DV, OV and EV', 'SSL sertifika türleri: DV, OV ve EV', 'انواع گواهینامه SSL', 'تفاوت سطح اعتبارسنجی و اینکه کدام برای چه سایتی مناسب است.'],
['securing-ssh-access', 'security', 'key', 'امن‌سازی دسترسی SSH', 'Securing SSH access', 'SSH erişimini güvenli hale getirme', 'امنیت SSH', 'تغییر پورت، کلید به‌جای رمز، محدودکردن کاربر و fail2ban.'],
['gdpr-and-data-privacy-basics', 'security', 'shield', 'حریم خصوصی داده‌ها برای سایت‌های ایرانی', 'Data privacy basics for website owners', 'Site sahipleri için veri gizliliği temelleri', 'حریم خصوصی داده', 'چه داده‌هایی جمع می‌کنیم، چقدر نگه داریم و چطور شفاف باشیم.'],
['website-security-headers', 'security', 'shield', 'هدرهای امنیتی سایت: CSP، HSTS و بقیه', 'Website security headers: CSP, HSTS and the rest', 'Web güvenlik başlıkları: CSP, HSTS ve diğerleri', 'هدر امنیتی', 'هر هدر چه حمله‌ای را می‌بندد و پیکربندی امن بدون شکستن سایت.'],
['brute-force-protection', 'security', 'lock', 'محافظت در برابر حملات جستجوی فراگیر', 'Protecting against brute-force attacks', 'Kaba kuvvet saldırılarına karşı koruma', 'حمله بروت فورس', 'محدودیت نرخ، قفل موقت، و پایش لاگ ورود.'],
['ransomware-prevention-business', 'security', 'shield', 'پیشگیری از باج‌افزار در کسب‌وکار', 'Ransomware prevention for businesses', 'İşletmeler için fidye yazılımı önleme', 'باج افزار', 'بکاپ آفلاین، حداقل دسترسی، و آموزش کارکنان.'],
['audit-logs-importance', 'security', 'book', 'اهمیت لاگ‌های ممیزی و نگهداری آن‌ها', 'Why audit logs matter and how to keep them', 'Denetim günlükleri neden önemli ve nasıl saklanır', 'لاگ ممیزی', 'چه رویدادهایی را ثبت کنیم و چطور لاگ را دست‌نخورده نگه داریم.'],
['vpn-for-business-security', 'security', 'lock', 'VPN سازمانی برای دسترسی امن کارکنان', 'Business VPN for secure employee access', 'Güvenli çalışan erişimi için kurumsal VPN', 'VPN سازمانی', 'تفاوت با VPN مصرفی، مدل دسترسی و جایگزین‌های مدرن.'],

/* ============================ سئو و مارکتینگ ============================ */
['core-web-vitals-guide', 'seo', 'gauge', 'Core Web Vitals — بهبود LCP، INP و CLS', 'Core Web Vitals: improving LCP, INP and CLS', 'Core Web Vitals: LCP, INP ve CLS iyileştirme', 'Core Web Vitals', 'هر شاخص چه می‌سنجد، آستانه‌ی قبولی، و راهکارهای عملی بهبود.'],
['keyword-research-for-beginners', 'seo', 'search', 'تحقیق کلمات کلیدی برای شروع', 'Keyword research for beginners', 'Yeni başlayanlar için anahtar kelime araştırması', 'تحقیق کلمات کلیدی', 'یافتن عبارت‌های با قصد خرید، سنجش رقابت، و ساخت خوشه‌ی محتوایی.'],
['on-page-seo-checklist', 'seo', 'check', 'چک‌لیست سئوی داخلی صفحه', 'The on-page SEO checklist', 'Sayfa içi SEO kontrol listesi', 'سئو داخلی', 'تیتر، متا، ساختار هدینگ، لینک داخلی، تصاویر و داده‌ی ساختاریافته.'],
['schema-markup-guide', 'seo', 'code', 'داده‌ی ساختاریافته (Schema) و نتایج غنی گوگل', 'Schema markup and rich results in Google', 'Schema işaretlemesi ve Google zengin sonuçlar', 'اسکیما مارکاپ', 'انواع پرکاربرد، پیاده‌سازی JSON-LD و تست صحت.'],
['internal-linking-strategy', 'seo', 'link', 'استراتژی لینک‌سازی داخلی', 'An internal linking strategy that works', 'İşe yarayan iç bağlantı stratejisi', 'لینک سازی داخلی', 'ساختار سیلو، توزیع اعتبار صفحه و انتخاب انکرتکست.'],
['fixing-duplicate-content', 'seo', 'x', 'رفع محتوای تکراری و کاربرد تگ Canonical', 'Fixing duplicate content and using canonical tags', 'Yinelenen içeriği düzeltme ve canonical etiketi', 'محتوای تکراری', 'منابع رایج تکرار در فروشگاه‌ها و راه‌حل canonical و noindex.'],
['multilingual-seo-hreflang', 'seo', 'globe', 'سئوی چندزبانه و تگ hreflang', 'Multilingual SEO and hreflang', 'Çok dilli SEO ve hreflang', 'سئو چندزبانه', 'ساختار URL، پیاده‌سازی hreflang و اشتباهات رایج.'],
['google-search-console-guide', 'seo', 'gauge', 'کار با Google Search Console', 'Getting the most from Google Search Console', "Google Search Console'dan en iyi şekilde yararlanma", 'سرچ کنسول', 'گزارش‌های کلیدی، تشخیص افت رتبه و رفع خطاهای ایندکس.'],
['image-optimization-for-web', 'seo', 'zap', 'بهینه‌سازی تصاویر برای وب', 'Image optimisation for the web', 'Web için görsel optimizasyonu', 'بهینه سازی تصاویر', 'فرمت WebP، اندازه‌ی درست، lazy loading و تأثیر بر LCP.'],
['content-strategy-for-b2b', 'seo', 'book', 'استراتژی محتوا برای کسب‌وکارهای B2B', 'A content strategy for B2B businesses', 'B2B işletmeler için içerik stratejisi', 'استراتژی محتوا', 'نقشه‌ی محتوا بر اساس مراحل تصمیم خرید مشتری سازمانی.'],
['local-seo-for-businesses', 'seo', 'pin', 'سئوی محلی برای کسب‌وکارهای خدماتی', 'Local SEO for service businesses', 'Hizmet işletmeleri için yerel SEO', 'سئو محلی', 'پروفایل کسب‌وکار، نشانی یکدست و نظرات مشتریان.'],
['seo-for-ecommerce-sites', 'seo', 'coins', 'سئوی فروشگاه‌های اینترنتی', 'SEO for e-commerce sites', 'E-ticaret siteleri için SEO', 'سئو فروشگاه اینترنتی', 'صفحه‌ی دسته، فیلترها، محصولات ناموجود و داده‌ی ساختاریافته‌ی محصول.'],
['recovering-from-traffic-drop', 'seo', 'trend', 'بازیابی افت ناگهانی ترافیک سایت', 'Recovering from a sudden traffic drop', 'Ani trafik düşüşünden kurtulma', 'افت ترافیک', 'تشخیص علت: آپدیت الگوریتم، مشکل فنی یا جریمه — و مسیر بازیابی.'],
['seo-friendly-url-structure', 'seo', 'link', 'ساختار URL مناسب سئو', 'An SEO-friendly URL structure', 'SEO dostu URL yapısı', 'ساختار URL', 'طول، کلمات کلیدی، حروف فارسی در URL و تغییر بدون از دست دادن رتبه.'],

/* ============================ آموزش ============================ */
['installing-wordpress-manually', 'tutorial', 'cap', 'نصب دستی وردپرس روی هاست', 'Installing WordPress manually on your hosting', "Hosting'e WordPress'i elle kurma", 'نصب وردپرس', 'دانلود، دیتابیس، wp-config و رفع خطاهای رایج نصب.'],
['setting-up-email-client', 'tutorial', 'mail', 'تنظیم ایمیل سازمانی در اوت‌لوک و موبایل', 'Setting up business email in Outlook and on mobile', "Outlook ve mobilde kurumsal e-posta kurulumu", 'تنظیم ایمیل', 'تفاوت IMAP و POP3، پورت‌های امن، و خطاهای رایج اتصال.'],
['connecting-windows-server-rdp', 'tutorial', 'monitor', 'اتصال به سرور ویندوز با ریموت دسکتاپ', 'Connecting to a Windows server with Remote Desktop', 'Uzak Masaüstü ile Windows sunucuya bağlanma', 'ریموت دسکتاپ', 'اتصال از ویندوز، مک و موبایل، و امن‌سازی دسترسی RDP.'],
['using-ftp-client-filezilla', 'tutorial', 'restore', 'کار با FileZilla برای انتقال فایل', 'Using FileZilla to transfer files', 'Dosya aktarımı için FileZilla kullanımı', 'فایل زیلا', 'اتصال، تفاوت FTP و SFTP، و انتقال حجم بالا.'],
['creating-mysql-user-database', 'tutorial', 'db', 'ساخت دیتابیس و کاربر MySQL', 'Creating a MySQL database and user', 'MySQL veritabanı ve kullanıcı oluşturma', 'ساخت دیتابیس', 'مراحل در کنترل‌پنل، تخصیص دسترسی و اتصال از سایت.'],
['redirect-http-to-https', 'tutorial', 'lock', 'هدایت خودکار HTTP به HTTPS', 'Redirecting HTTP to HTTPS automatically', "HTTP'yi otomatik olarak HTTPS'e yönlendirme", 'ریدایرکت HTTPS', 'قواعد htaccess و nginx، و رفع مشکل حلقه‌ی ریدایرکت.'],
['setting-up-cloudflare', 'tutorial', 'cloud', 'راه‌اندازی کلادفلر برای سایت', 'Setting up Cloudflare for your website', 'Siteniz için Cloudflare kurulumu', 'کلادفلر', 'تغییر نیم‌سرور، حالت SSL درست، و تنظیماتی که سایت را می‌شکنند.'],
['nginx-vs-apache', 'tutorial', 'server', 'مقایسه‌ی Nginx و Apache', 'Nginx vs Apache compared', 'Nginx ve Apache karşılaştırması', 'Nginx یا Apache', 'تفاوت معماری، کارایی در بار بالا و ترکیب هر دو.'],
['linux-commands-for-beginners', 'tutorial', 'code', '۲۵ دستور لینوکس که هر مدیر سایت باید بداند', '25 Linux commands every site owner should know', 'Her site sahibinin bilmesi gereken 25 Linux komutu', 'دستورات لینوکس', 'دستورهای فایل، فرایند، شبکه و دیسک با مثال کاربردی.'],
['git-basics-for-deployment', 'tutorial', 'flow', 'مقدمات Git برای استقرار سایت', 'Git basics for deploying your site', 'Site dağıtımı için Git temelleri', 'گیت', 'مخزن، کامیت، برنچ و استقرار با git pull روی سرور.'],
['website-speed-test-tools', 'tutorial', 'gauge', 'ابزارهای تست سرعت سایت و تفسیر نتایج', 'Website speed test tools and how to read the results', 'Site hız testi araçları ve sonuçları okuma', 'تست سرعت سایت', 'تفاوت داده‌ی آزمایشگاهی و میدانی، و اولویت‌بندی اصلاحات.'],
['restoring-website-from-backup', 'tutorial', 'restore', 'بازگردانی سایت از بکاپ', 'Restoring a website from a backup', 'Siteyi yedekten geri yükleme', 'بازگردانی بکاپ', 'بازیابی فایل و دیتابیس، و بررسی سلامت پس از بازگردانی.'],
['configuring-dns-for-new-site', 'tutorial', 'globe', 'پیکربندی DNS برای سایت تازه', 'Configuring DNS for a brand-new site', 'Yeni bir site için DNS yapılandırma', 'تنظیم DNS', 'رکوردهای لازم برای سایت و ایمیل، به‌ترتیب درست.'],
['using-phpmyadmin', 'tutorial', 'db', 'کار با phpMyAdmin برای مدیریت دیتابیس', 'Using phpMyAdmin to manage your database', 'Veritabanı yönetimi için phpMyAdmin kullanımı', 'phpMyAdmin', 'اجرای کوئری، ویرایش جدول، ایمپورت و اکسپورت امن.'],
['monitoring-website-uptime', 'tutorial', 'gauge', 'پایش آپتایم سایت و هشدار قطعی', 'Monitoring website uptime and downtime alerts', 'Site uptime izleme ve kesinti uyarıları', 'پایش آپتایم', 'ابزارها، فاصله‌ی بررسی و پرهیز از هشدار کاذب.'],
['setting-up-google-analytics', 'tutorial', 'trend', 'راه‌اندازی گوگل آنالیتیکس ۴', 'Setting up Google Analytics 4', 'Google Analytics 4 kurulumu', 'گوگل آنالیتیکس', 'نصب، رویدادهای کلیدی و گزارش‌هایی که واقعاً به کار می‌آیند.'],

/* ============================ تکنولوژی ============================ */
['what-is-edge-computing', 'tech', 'cpu', 'رایانش لبه (Edge Computing) چیست', 'What is edge computing', 'Edge computing nedir', 'رایانش لبه', 'تفاوت با ابر متمرکز و کاربردهای واقعی در ایران.'],
['http3-and-quic', 'tech', 'zap', 'HTTP/3 و QUIC — چه چیزی عوض می‌شود', 'HTTP/3 and QUIC: what changes', 'HTTP/3 ve QUIC: ne değişiyor', 'HTTP/3', 'مشکلاتی که حل می‌کند و تأثیر واقعی روی سرعت سایت.'],
['ipv6-adoption-guide', 'tech', 'globe', 'IPv6 — چرا هنوز مهم است', 'IPv6: why it still matters', 'IPv6: neden hâlâ önemli', 'IPv6', 'تفاوت با IPv4، وضعیت پذیرش و آماده‌سازی زیرساخت.'],
['virtualization-technologies', 'tech', 'box', 'فناوری‌های مجازی‌سازی: KVM، Xen و کانتینر', 'Virtualisation technologies: KVM, Xen and containers', 'Sanallaştırma teknolojileri: KVM, Xen ve konteynerler', 'مجازی سازی', 'تفاوت مجازی‌سازی کامل و کانتینر، و تأثیر بر کارایی VPS.'],
['raid-levels-explained', 'tech', 'hdd', 'سطوح RAID و انتخاب درست', 'RAID levels explained and how to choose', 'RAID seviyeleri ve doğru seçim', 'RAID', 'RAID 0/1/5/10، تعادل سرعت و افزونگی، و اینکه RAID جای بکاپ نیست.'],
['ai-agents-for-business', 'tech', 'bot', 'ایجنت‌های هوش مصنوعی در کسب‌وکار', 'AI agents in business', 'İşletmelerde yapay zeka ajanları', 'ایجنت هوش مصنوعی', 'کاربردهای واقعی در پشتیبانی و فروش، و مرز انتظارات.'],
['voip-technology-explained', 'tech', 'phone', 'فناوری VoIP و تلفن ابری', 'VoIP technology and cloud telephony', 'VoIP teknolojisi ve bulut telefonu', 'تلفن ابری', 'مزیت نسبت به خط سنتی، کیفیت تماس و پیش‌نیاز پهنای باند.'],
['bpmn-process-modeling', 'tech', 'flow', 'مدل‌سازی فرایند با BPMN', 'Process modelling with BPMN', 'BPMN ile süreç modelleme', 'BPMN', 'نمادهای اصلی و اینکه چطور فرایند سازمانی را مستند کنیم.'],
['api-basics-rest-vs-graphql', 'tech', 'code', 'مقدمات API: REST در برابر GraphQL', 'API basics: REST vs GraphQL', 'API temelleri: REST ve GraphQL', 'REST یا GraphQL', 'تفاوت مدل درخواست، مزایا و انتخاب مناسب هر پروژه.'],
['green-hosting-sustainability', 'tech', 'umbrella', 'هاستینگ سبز و مصرف انرژی دیتاسنتر', 'Green hosting and datacenter energy use', 'Yeşil hosting ve veri merkezi enerji tüketimi', 'هاستینگ سبز', 'شاخص PUE، منابع تجدیدپذیر و اینکه بهینه‌سازی کد هم سبز است.'],

/* ============================ کسب‌وکار ============================ */
['starting-online-business-checklist', 'business', 'rocket', 'چک‌لیست راه‌اندازی کسب‌وکار آنلاین', 'The checklist for starting an online business', 'Çevrimiçi işe başlama kontrol listesi', 'راه اندازی کسب و کار آنلاین', 'از دامنه و هاست تا درگاه پرداخت و الزامات قانونی.'],
['choosing-domain-name-brand', 'business', 'globe', 'انتخاب نام دامنه‌ی مناسب برند', 'Choosing a domain name that fits your brand', 'Markanıza uygun alan adı seçimi', 'انتخاب نام دامنه', 'معیارهای به‌یادماندنی بودن، پسوند مناسب و بررسی تعارض برند.'],
['ecommerce-payment-gateway', 'business', 'coins', 'انتخاب درگاه پرداخت برای فروشگاه اینترنتی', 'Choosing a payment gateway for your online store', 'Online mağazanız için ödeme geçidi seçimi', 'درگاه پرداخت', 'کارمزد، تسویه، تجربه‌ی پرداخت و الزامات فنی اتصال.'],
['digital-transformation-smes', 'business', 'factory', 'تحول دیجیتال در شرکت‌های کوچک و متوسط', 'Digital transformation for small and medium businesses', 'KOBİ\'ler için dijital dönüşüm', 'تحول دیجیتال', 'از کجا شروع کنیم، اولویت‌بندی و اشتباه رایج خرید نرم‌افزار بدون فرایند.'],
['remote-work-infrastructure', 'business', 'monitor', 'زیرساخت دورکاری برای سازمان‌ها', 'Remote work infrastructure for organisations', 'Kuruluşlar için uzaktan çalışma altyapısı', 'زیرساخت دورکاری', 'دسترسی امن، ابزار همکاری و مدیریت دستگاه‌ها.'],
['saas-vs-custom-software', 'business', 'box', 'نرم‌افزار آماده یا سفارشی؟ کدام را انتخاب کنیم', 'SaaS or custom software: which to choose', 'Hazır yazılım mı özel yazılım mı', 'نرم افزار سفارشی', 'هزینه‌ی کل مالکیت، سرعت راه‌اندازی و ریسک وابستگی.'],
['customer-support-best-practices', 'business', 'headset', 'اصول پشتیبانی مشتری در کسب‌وکار آنلاین', 'Customer support best practices for online business', 'Online işletmeler için müşteri desteği ilkeleri', 'پشتیبانی مشتری', 'زمان پاسخ، مدیریت انتظار و تبدیل شکایت به فرصت.'],
['website-legal-requirements-iran', 'business', 'book', 'الزامات قانونی راه‌اندازی سایت در ایران', 'Legal requirements for launching a website in Iran', "İran'da web sitesi açmanın yasal gereklilikleri", 'الزامات قانونی سایت', 'نماد اعتماد، ثبت دامنه، و صفحات قانونی لازم.'],

];

/* تبدیل به ساختار نهایی */
return array_map(fn ($r) => [
    'slug'     => $r[0],
    'category' => $r[1],
    'icon'     => $r[2],
    'fa'       => $r[3],
    'en'       => $r[4],
    'tr'       => $r[5],
    'keyword'  => $r[6],
    'brief'    => $r[7],
], $rows);
