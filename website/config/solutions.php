<?php

/*
|--------------------------------------------------------------------------
| ServerNet — راهکارهای سازمانی (/solutions/{slug})
|--------------------------------------------------------------------------
| هر راهکار یک صفحه‌ی فروش سئوشده و سه‌زبانه است. ساختار بخش‌ها اختیاری‌اند؛
| هر بخشی که در محتوای زبان موجود باشد رندر می‌شود (قالب: pages/solution).
|
| accent: cyan | violet | green | amber
*/

return [

    /* ================================================= ایمیل تراکنشی و سازمانی */
    'email' => [
        'icon' => 'mail', 'accent' => 'cyan',

        'fa' => [
            'meta_t' => 'سرویس ایمیل تراکنشی و سازمانی — مبتنی بر Amazon SES',
            'meta_d' => 'ارسال ایمیل انبوه و تراکنشی با بالاترین نرخ تحویل به صندوق ورودی، روی زیرساخت Amazon SES. راه‌اندازی SPF/DKIM/DMARC، SMTP و API، داشبورد و گزارش کامل — با پشتیبانی فارسی سرورنت.',
            'badge' => 'مبتنی بر زیرساخت Amazon SES',
            'h1a' => 'ایمیل‌هایتان به صندوق ورودی می‌رسند،', 'h1b' => 'نه پوشه‌ی اسپم.',
            'lead' => 'سرویس ایمیل تراکنشی و سازمانی سرورنت روی زیرساخت جهانی Amazon SES بنا شده است؛ همان زیرساختی که غول‌های فناوری برای ارسال میلیون‌ها ایمیل روزانه به آن اعتماد می‌کنند. با افتخار نماینده‌ی آمازون هستیم و این قدرت را با راه‌اندازی کامل و پشتیبانی فارسی در اختیار شما می‌گذاریم.',
            'cta1' => ['label' => 'مشاهده‌ی پکیج‌ها', 'href' => '#packages'],
            'cta2' => ['label' => 'مشاوره‌ی رایگان', 'href' => 'contact'],
            'stats' => [
                ['n' => '۹۹٪+', 'l' => 'نرخ تحویل به صندوق ورودی'],
                ['n' => 'Amazon', 'l' => 'زیرساخت جهانی SES'],
                ['n' => 'SMTP + API', 'l' => 'اتصال به هر سیستمی'],
                ['n' => '۲۴/۷', 'l' => 'پشتیبانی فارسی'],
            ],
            'trust' => [
                ['icon' => 'shield', 't' => 'SPF · DKIM · DMARC کامل'],
                ['icon' => 'gauge', 't' => 'داشبورد و گزارش زنده'],
                ['icon' => 'zap', 't' => 'ارسال پرسرعت انبوه'],
                ['icon' => 'headset', 't' => 'راه‌اندازی توسط تیم سرورنت'],
            ],

            'features_badge' => 'چرا ایمیل سرورنت',
            'features_t' => 'هر چیزی که یک سازمان برای ایمیل حرفه‌ای لازم دارد',
            'features_d' => 'از فروشگاه اینترنتی تا بانک و اپلیکیشن؛ ایمیل‌های تراکنشی و خبرنامه‌ی شما با بالاترین اعتبار ارسال می‌شوند.',
            'features' => [
                ['icon' => 'trend', 't' => 'نرخ تحویل بالا', 'd' => 'زیرساخت معتبر Amazon و تنظیم صحیح احراز هویت دامنه یعنی ایمیل‌های شما به‌جای اسپم، در صندوق ورودی می‌نشینند.'],
                ['icon' => 'code', 't' => 'SMTP و API', 'd' => 'به وردپرس، ووکامرس، اپلیکیشن یا هر سیستمی با SMTP استاندارد یا REST API متصل شوید؛ ارسال از همان روز اول.'],
                ['icon' => 'shield', 't' => 'احراز هویت دامنه', 'd' => 'رکوردهای SPF، DKIM و DMARC را کامل برایتان تنظیم می‌کنیم تا اعتبار فرستنده‌ی شما نزد گوگل و مایکروسافت تثبیت شود.'],
                ['icon' => 'gauge', 't' => 'گزارش و آنالیتیکس', 'd' => 'نرخ باز شدن، کلیک، بازگشت (bounce) و شکایت را زنده ببینید و سلامت ارسال خود را لحظه‌به‌لحظه پایش کنید.'],
                ['icon' => 'server', 't' => 'IP اختصاصی', 'd' => 'برای حجم بالا، IP اختصاصی با اعتبار گرم‌شده ارائه می‌دهیم تا کنترل کامل روی شهرت ارسال خود داشته باشید.'],
                ['icon' => 'lock', 't' => 'امنیت و انطباق', 'd' => 'اتصال رمزنگاری‌شده TLS، مدیریت کلیدهای دسترسی و زیرساخت منطبق با استانداردهای جهانی آمازون.'],
            ],

            'steps_badge' => 'راه‌اندازی',
            'steps_t' => 'در سه قدم ارسال را شروع کنید',
            'steps_d' => 'تیم سرورنت کل فرایند فنی را انجام می‌دهد؛ شما فقط ارسال می‌کنید.',
            'steps' => [
                ['t' => 'دامنه را تأیید می‌کنیم', 'd' => 'دامنه‌ی شما را در سرویس ثبت و رکوردهای احراز هویت (SPF/DKIM/DMARC) را کامل تنظیم می‌کنیم.'],
                ['t' => 'اتصال برقرار می‌شود', 'd' => 'اطلاعات SMTP یا کلید API را تحویل می‌گیرید و به سیستم خود (سایت، اپ، CRM) وصل می‌کنید.'],
                ['t' => 'ارسال و پایش', 'd' => 'ایمیل‌ها را ارسال کنید و از داشبورد، تحویل و عملکرد هر کمپین را دنبال کنید.'],
            ],

            'packages_badge' => 'پکیج‌ها',
            'packages_t' => 'پکیج مناسب هر مقیاسی',
            'packages_d' => 'از استارتاپ تا سازمان‌های بزرگ؛ متناسب با حجم ارسال ماهانه‌ی خود انتخاب کنید.',
            'popular' => 'پیشنهاد سرورنت',
            'packages' => [
                ['name' => 'استارتر', 'tagline' => 'کسب‌وکارهای نوپا و فروشگاه‌های کوچک', 'price' => '۵۰ هزار', 'unit' => 'ایمیل در ماه',
                 'features' => ['اتصال SMTP و API', 'راه‌اندازی SPF/DKIM/DMARC', 'داشبورد گزارش', 'پشتیبانی تیکتی'], 'cta' => 'شروع کنید'],
                ['name' => 'بیزنس', 'tagline' => 'فروشگاه‌ها و اپلیکیشن‌های در حال رشد', 'price' => '۲۵۰ هزار', 'unit' => 'ایمیل در ماه', 'featured' => true,
                 'features' => ['همه‌ی امکانات استارتر', 'اولویت در تحویل', 'گزارش پیشرفته و وبهوک', 'پشتیبانی چت و تلفن', 'مشاوره‌ی بهبود تحویل'], 'cta' => 'انتخاب بیزنس'],
                ['name' => 'سازمانی', 'tagline' => 'حجم بالا و ماموریت‌بحرانی', 'price' => 'IP اختصاصی', 'unit' => 'حجم نامحدود',
                 'features' => ['همه‌ی امکانات بیزنس', 'IP اختصاصی گرم‌شده', 'SLA و پشتیبانی اختصاصی', 'مدیر حساب اختصاصی', 'راه‌اندازی و مهاجرت کامل'], 'cta' => 'گفتگو با کارشناس'],
            ],
            'packages_note' => 'حجم ارسال دقیق و قیمت نهایی متناسب با نیاز شما تنظیم می‌شود — برای پیشنهاد اختصاصی تماس بگیرید.',

            'faq_t' => 'پرسش‌های پرتکرار',
            'faq' => [
                ['q' => 'ایمیل تراکنشی با خبرنامه چه فرقی دارد؟', 'a' => 'ایمیل تراکنشی مثل تأیید سفارش، بازیابی رمز و فاکتور، به‌صورت خودکار در پاسخ به عمل کاربر ارسال می‌شود. خبرنامه ارسال انبوه بازاریابی است. سرویس ما هر دو را با بالاترین نرخ تحویل پشتیبانی می‌کند.'],
                ['q' => 'چرا Amazon SES؟', 'a' => 'Amazon SES یکی از معتبرترین و مقیاس‌پذیرترین زیرساخت‌های ارسال ایمیل دنیاست با شهرت IP عالی نزد ارائه‌دهندگان بزرگ. ما به‌عنوان نماینده، این قدرت را با راه‌اندازی کامل فارسی و پشتیبانی محلی ارائه می‌دهیم.'],
                ['q' => 'ایمیل‌هایم به اسپم نمی‌روند؟', 'a' => 'با تنظیم صحیح SPF، DKIM و DMARC و استفاده از IPهای با شهرت خوب، نرخ رسیدن به صندوق ورودی به‌شدت بالا می‌رود. برای حجم بالا هم IP اختصاصی گرم‌شده ارائه می‌کنیم.'],
                ['q' => 'به سیستم فعلی‌ام وصل می‌شود؟', 'a' => 'بله. هر سیستمی که SMTP یا API استاندارد داشته باشد — وردپرس، ووکامرس، لاراول، CRM یا اپلیکیشن اختصاصی — در چند دقیقه متصل می‌شود.'],
            ],

            'cta_t' => 'آماده‌اید ایمیل‌هایتان دیده شوند؟',
            'cta_d' => 'همین حالا با کارشناسان سرورنت صحبت کنید تا بهترین پکیج ایمیل تراکنشی را برای کسب‌وکار شما تنظیم کنیم.',
            'cta_btn' => 'درخواست مشاوره',
            'cta_btn2' => 'تماس با ما',
        ],

        'en' => [
            'meta_t' => 'Transactional & Enterprise Email Service — Powered by Amazon SES',
            'meta_d' => 'Send bulk and transactional email with the highest inbox-delivery rate on Amazon SES infrastructure. Full SPF/DKIM/DMARC setup, SMTP & API, dashboard and analytics — with ServerNet support.',
            'badge' => 'Built on Amazon SES infrastructure',
            'h1a' => 'Your emails land in the inbox,', 'h1b' => 'not the spam folder.',
            'lead' => 'ServerNet\'s transactional and enterprise email service is built on Amazon SES — the same global infrastructure tech giants trust to send millions of emails a day. As a proud Amazon partner, we hand you that power with full setup and dedicated support.',
            'cta1' => ['label' => 'View packages', 'href' => '#packages'],
            'cta2' => ['label' => 'Free consultation', 'href' => 'contact'],
            'stats' => [
                ['n' => '99%+', 'l' => 'Inbox delivery rate'],
                ['n' => 'Amazon', 'l' => 'Global SES infrastructure'],
                ['n' => 'SMTP + API', 'l' => 'Connect any system'],
                ['n' => '24/7', 'l' => 'Dedicated support'],
            ],
            'trust' => [
                ['icon' => 'shield', 't' => 'Full SPF · DKIM · DMARC'],
                ['icon' => 'gauge', 't' => 'Live dashboard & reports'],
                ['icon' => 'zap', 't' => 'High-speed bulk sending'],
                ['icon' => 'headset', 't' => 'Setup by the ServerNet team'],
            ],

            'features_badge' => 'Why ServerNet email',
            'features_t' => 'Everything an organization needs for professional email',
            'features_d' => 'From online stores to banks and apps — your transactional emails and newsletters send with the highest reputation.',
            'features' => [
                ['icon' => 'trend', 't' => 'High deliverability', 'd' => 'Amazon\'s trusted infrastructure plus correct domain authentication means your emails land in the inbox, not in spam.'],
                ['icon' => 'code', 't' => 'SMTP & API', 'd' => 'Connect WordPress, WooCommerce, your app or any system via standard SMTP or a REST API — sending from day one.'],
                ['icon' => 'shield', 't' => 'Domain authentication', 'd' => 'We configure SPF, DKIM and DMARC in full so your sender reputation is established with Google and Microsoft.'],
                ['icon' => 'gauge', 't' => 'Reporting & analytics', 'd' => 'See opens, clicks, bounces and complaints live, and monitor your sending health moment to moment.'],
                ['icon' => 'server', 't' => 'Dedicated IP', 'd' => 'For high volume we provide a warmed-up dedicated IP so you keep full control of your sending reputation.'],
                ['icon' => 'lock', 't' => 'Security & compliance', 'd' => 'Encrypted TLS connections, managed access keys, and infrastructure aligned with Amazon\'s global standards.'],
            ],

            'steps_badge' => 'Onboarding',
            'steps_t' => 'Start sending in three steps',
            'steps_d' => 'The ServerNet team handles the whole technical process; you just send.',
            'steps' => [
                ['t' => 'We verify your domain', 'd' => 'We register your domain and configure the authentication records (SPF/DKIM/DMARC) in full.'],
                ['t' => 'You connect', 'd' => 'Receive your SMTP details or API key and connect your site, app or CRM.'],
                ['t' => 'Send & monitor', 'd' => 'Send your emails and track delivery and campaign performance from the dashboard.'],
            ],

            'packages_badge' => 'Packages',
            'packages_t' => 'A package for every scale',
            'packages_d' => 'From startup to large enterprise — choose by your monthly sending volume.',
            'popular' => 'Recommended',
            'packages' => [
                ['name' => 'Starter', 'tagline' => 'New businesses and small stores', 'price' => '50K', 'unit' => 'emails / month',
                 'features' => ['SMTP & API access', 'SPF/DKIM/DMARC setup', 'Reporting dashboard', 'Ticket support'], 'cta' => 'Get started'],
                ['name' => 'Business', 'tagline' => 'Growing stores and apps', 'price' => '250K', 'unit' => 'emails / month', 'featured' => true,
                 'features' => ['Everything in Starter', 'Priority delivery', 'Advanced reports & webhooks', 'Chat & phone support', 'Deliverability consulting'], 'cta' => 'Choose Business'],
                ['name' => 'Enterprise', 'tagline' => 'High volume, mission-critical', 'price' => 'Dedicated IP', 'unit' => 'unlimited volume',
                 'features' => ['Everything in Business', 'Warmed-up dedicated IP', 'SLA & dedicated support', 'Dedicated account manager', 'Full setup & migration'], 'cta' => 'Talk to an expert'],
            ],
            'packages_note' => 'Exact volume and final pricing are tailored to your needs — contact us for a custom proposal.',

            'faq_t' => 'Frequently asked questions',
            'faq' => [
                ['q' => 'Transactional vs. newsletter email?', 'a' => 'Transactional emails — order confirmations, password resets, invoices — are sent automatically in response to a user action. Newsletters are bulk marketing sends. Our service supports both at the highest delivery rate.'],
                ['q' => 'Why Amazon SES?', 'a' => 'Amazon SES is one of the most trusted, scalable email infrastructures in the world, with excellent IP reputation at the major providers. As a partner, we deliver that power with full setup and local support.'],
                ['q' => 'Will my emails avoid spam?', 'a' => 'With correct SPF, DKIM and DMARC and good-reputation IPs, inbox placement is very high. For high volume we also provide a warmed-up dedicated IP.'],
                ['q' => 'Does it work with my current system?', 'a' => 'Yes. Anything with standard SMTP or an API — WordPress, WooCommerce, Laravel, a CRM or a custom app — connects in minutes.'],
            ],

            'cta_t' => 'Ready to get your emails seen?',
            'cta_d' => 'Talk to ServerNet\'s specialists now and we\'ll tailor the right transactional-email package for your business.',
            'cta_btn' => 'Request a consultation',
            'cta_btn2' => 'Contact us',
        ],

        'tr' => [
            'meta_t' => 'İşlemsel ve Kurumsal E-posta Hizmeti — Amazon SES Altyapısı',
            'meta_d' => 'Amazon SES altyapısında en yüksek gelen kutusu teslim oranıyla toplu ve işlemsel e-posta gönderin. Tam SPF/DKIM/DMARC kurulumu, SMTP ve API, panel ve analitik.',
            'badge' => 'Amazon SES altyapısı üzerine kurulu',
            'h1a' => 'E-postalarınız spam\'e değil,', 'h1b' => 'gelen kutusuna ulaşır.',
            'lead' => 'ServerNet\'in işlemsel ve kurumsal e-posta hizmeti, teknoloji devlerinin günde milyonlarca e-posta göndermek için güvendiği Amazon SES üzerine kuruludur. Gururlu bir Amazon iş ortağı olarak bu gücü tam kurulum ve özel destekle sunuyoruz.',
            'cta1' => ['label' => 'Paketleri gör', 'href' => '#packages'],
            'cta2' => ['label' => 'Ücretsiz danışmanlık', 'href' => 'contact'],
            'stats' => [
                ['n' => '%99+', 'l' => 'Gelen kutusu teslim oranı'],
                ['n' => 'Amazon', 'l' => 'Küresel SES altyapısı'],
                ['n' => 'SMTP + API', 'l' => 'Her sisteme bağlanın'],
                ['n' => '7/24', 'l' => 'Özel destek'],
            ],
            'trust' => [
                ['icon' => 'shield', 't' => 'Tam SPF · DKIM · DMARC'],
                ['icon' => 'gauge', 't' => 'Canlı panel ve raporlar'],
                ['icon' => 'zap', 't' => 'Yüksek hızlı toplu gönderim'],
                ['icon' => 'headset', 't' => 'ServerNet ekibi kurulumu'],
            ],

            'features_badge' => 'Neden ServerNet e-posta',
            'features_t' => 'Profesyonel e-posta için kurumların ihtiyacı olan her şey',
            'features_d' => 'Online mağazalardan bankalara ve uygulamalara — işlemsel e-postalarınız en yüksek itibarla gönderilir.',
            'features' => [
                ['icon' => 'trend', 't' => 'Yüksek teslim edilebilirlik', 'd' => 'Amazon\'un güvenilir altyapısı ve doğru alan adı kimlik doğrulaması, e-postalarınızın spam yerine gelen kutusuna ulaşması demektir.'],
                ['icon' => 'code', 't' => 'SMTP ve API', 'd' => 'WordPress, WooCommerce, uygulamanız veya herhangi bir sistemi standart SMTP veya REST API ile bağlayın.'],
                ['icon' => 'shield', 't' => 'Alan adı kimlik doğrulama', 'd' => 'SPF, DKIM ve DMARC kayıtlarını tam olarak yapılandırıp gönderen itibarınızı Google ve Microsoft nezdinde sağlamlaştırırız.'],
                ['icon' => 'gauge', 't' => 'Raporlama ve analitik', 'd' => 'Açılma, tıklama, geri dönme ve şikayetleri canlı görün, gönderim sağlığınızı anlık izleyin.'],
                ['icon' => 'server', 't' => 'Özel IP', 'd' => 'Yüksek hacim için, gönderim itibarınız üzerinde tam kontrol sağlayan ısıtılmış bir özel IP sunuyoruz.'],
                ['icon' => 'lock', 't' => 'Güvenlik ve uyumluluk', 'd' => 'Şifreli TLS bağlantıları, yönetilen erişim anahtarları ve Amazon\'un küresel standartlarına uygun altyapı.'],
            ],

            'steps_badge' => 'Kurulum',
            'steps_t' => 'Üç adımda göndermeye başlayın',
            'steps_d' => 'Tüm teknik süreci ServerNet ekibi halleder; siz sadece gönderirsiniz.',
            'steps' => [
                ['t' => 'Alan adınızı doğrularız', 'd' => 'Alan adınızı kaydeder ve kimlik doğrulama kayıtlarını (SPF/DKIM/DMARC) tam olarak yapılandırırız.'],
                ['t' => 'Bağlanırsınız', 'd' => 'SMTP bilgilerinizi veya API anahtarınızı alıp sitenizi, uygulamanızı veya CRM\'inizi bağlarsınız.'],
                ['t' => 'Gönder ve izle', 'd' => 'E-postalarınızı gönderin ve teslimatı, kampanya performansını panelden takip edin.'],
            ],

            'packages_badge' => 'Paketler',
            'packages_t' => 'Her ölçek için bir paket',
            'packages_d' => 'Startup\'tan büyük kuruluşlara — aylık gönderim hacminize göre seçin.',
            'popular' => 'Önerilen',
            'packages' => [
                ['name' => 'Başlangıç', 'tagline' => 'Yeni işletmeler ve küçük mağazalar', 'price' => '50K', 'unit' => 'e-posta / ay',
                 'features' => ['SMTP ve API erişimi', 'SPF/DKIM/DMARC kurulumu', 'Raporlama paneli', 'Ticket desteği'], 'cta' => 'Başla'],
                ['name' => 'Business', 'tagline' => 'Büyüyen mağazalar ve uygulamalar', 'price' => '250K', 'unit' => 'e-posta / ay', 'featured' => true,
                 'features' => ['Başlangıçtaki her şey', 'Öncelikli teslimat', 'Gelişmiş rapor ve webhook', 'Sohbet ve telefon desteği', 'Teslim edilebilirlik danışmanlığı'], 'cta' => 'Business seç'],
                ['name' => 'Kurumsal', 'tagline' => 'Yüksek hacim, kritik görev', 'price' => 'Özel IP', 'unit' => 'sınırsız hacim',
                 'features' => ['Business\'taki her şey', 'Isıtılmış özel IP', 'SLA ve özel destek', 'Özel hesap yöneticisi', 'Tam kurulum ve taşıma'], 'cta' => 'Uzmanla görüşün'],
            ],
            'packages_note' => 'Kesin hacim ve fiyat ihtiyacınıza göre belirlenir — özel teklif için bize ulaşın.',

            'faq_t' => 'Sıkça sorulan sorular',
            'faq' => [
                ['q' => 'İşlemsel ve bülten e-postası farkı?', 'a' => 'İşlemsel e-postalar (sipariş onayı, şifre sıfırlama, fatura) bir kullanıcı eylemine yanıt olarak otomatik gönderilir. Bültenler toplu pazarlama gönderimleridir. Hizmetimiz her ikisini de en yüksek teslim oranıyla destekler.'],
                ['q' => 'Neden Amazon SES?', 'a' => 'Amazon SES, büyük sağlayıcılar nezdinde mükemmel IP itibarına sahip, dünyanın en güvenilir ve ölçeklenebilir e-posta altyapılarından biridir. İş ortağı olarak bu gücü tam kurulum ve yerel destekle sunuyoruz.'],
                ['q' => 'E-postalarım spam\'den kaçınır mı?', 'a' => 'Doğru SPF, DKIM ve DMARC ile iyi itibarlı IP\'ler sayesinde gelen kutusu yerleşimi çok yüksektir. Yüksek hacim için ısıtılmış özel IP de sağlıyoruz.'],
                ['q' => 'Mevcut sistemimle çalışır mı?', 'a' => 'Evet. Standart SMTP veya API\'si olan her şey — WordPress, WooCommerce, Laravel, CRM veya özel uygulama — dakikalar içinde bağlanır.'],
            ],

            'cta_t' => 'E-postalarınızın görülmesine hazır mısınız?',
            'cta_d' => 'Şimdi ServerNet uzmanlarıyla konuşun; işletmeniz için doğru işlemsel e-posta paketini birlikte belirleyelim.',
            'cta_btn' => 'Danışmanlık isteyin',
            'cta_btn2' => 'Bize ulaşın',
        ],
    ],

    /* ================================================= سرورنت ریموت (RustDesk) */
    'remote' => [
        'icon' => 'monitor', 'accent' => 'violet',

        'fa' => [
            'meta_t' => 'سرورنت ریموت — کنترل کامپیوتر از راه دور، سریع و امن',
            'meta_d' => 'ابزار حرفه‌ای دسترسی از راه دور سرورنت؛ سریع‌تر و امن‌تر از تیم‌ویور و انی‌دسک، با سرور رله داخل ایران، پینگ پایین، رمزنگاری سرتاسری و رایگان برای استفاده‌ی شخصی. ویندوز، اندروید، مک و آیفون.',
            'badge' => 'سرور رله داخل ایران · بدون فیلترشکن',
            'h1a' => 'کنترل کامپیوتر از راه دور،', 'h1b' => 'در حد بهترین دیتاسنترهای دنیا.',
            'lead' => 'سرورنت ریموت ابزار دسترسی از راه دوری است که تیم سرورنت توسعه داده؛ با رمزنگاری سرتاسری، تأخیری که فقط با سرور داخل کشور ممکن است و تجربه‌ای روان‌تر و امن‌تر از ابزارهای خارجی. به هر دستگاهی، از هر کجا، بدون تنظیمات پیچیده وصل شوید.',
            'cta1' => ['label' => 'دریافت برنامه', 'href' => 'https://remote.servernet.cloud'],
            'cta2' => ['label' => 'چطور کار می‌کند', 'href' => '#steps'],
            'stats' => [
                ['n' => '۱۸ms', 'l' => 'تأخیر اتصال زنده'],
                ['n' => 'E2E', 'l' => 'رمزنگاری سرتاسری'],
                ['n' => 'رایگان', 'l' => 'برای استفاده‌ی شخصی'],
                ['n' => '۴', 'l' => 'پلتفرم پشتیبانی‌شده'],
            ],
            'trust' => [
                ['icon' => 'pin', 't' => 'سرور داخل ایران'],
                ['icon' => 'lock', 't' => 'رمزنگاری سرتاسری'],
                ['icon' => 'zap', 't' => 'بدون پورت‌فورواردینگ'],
                ['icon' => 'smartphone', 't' => 'ویندوز، اندروید، مک، آیفون'],
            ],

            'features_badge' => 'چرا سرورنت ریموت',
            'features_t' => 'هر چیزی که برای دسترسی از راه دور لازم دارید',
            'features_d' => 'ساده برای کاربر عادی، قدرتمند برای تیم پشتیبانی و مدیران سیستم.',
            'features' => [
                ['icon' => 'lock', 't' => 'امنیت واقعی', 'd' => 'تمام نشست‌ها با رمزنگاری سرتاسری محافظت می‌شوند؛ تصویر، کلیدها و فایل‌های شما بین دو دستگاه رمز می‌مانند و هیچ‌کس وسط راه نمی‌بیند.'],
                ['icon' => 'zap', 't' => 'تأخیر پایین', 'd' => 'سرور رله در داخل کشور یعنی تصویر روان و بدون لگ، حتی روی اینترنت خانگی — چیزی که ابزارهای خارجی هرگز نمی‌دهند.'],
                ['icon' => 'check', 't' => 'بدون تنظیمات', 'd' => 'برنامه را باز کنید، یک شناسه و رمز می‌گیرید و وصل می‌شوید. نه IP ثابت لازم است، نه پورت‌فورواردینگ و نه دانش فنی.'],
                ['icon' => 'smartphone', 't' => 'چندپلتفرمه', 'd' => 'از ویندوز به اندروید، از مک به آیفون؛ هر ترکیبی از دستگاه‌ها به هم وصل می‌شوند.'],
                ['icon' => 'restore', 't' => 'انتقال فایل', 'd' => 'فایل‌ها را بین دو دستگاه بکشید و رها کنید؛ چاپ از راه دور و کلیپ‌بورد مشترک هم پشتیبانی می‌شود.'],
                ['icon' => 'headset', 't' => 'پشتیبانی از راه دور', 'd' => 'برای تیم‌های IT و پشتیبانی مشتری: به‌سرعت به دستگاه کاربر وصل شوید و مشکل را همان‌جا حل کنید.'],
            ],

            'steps_badge' => 'در سه قدم',
            'steps_t' => 'از نصب تا اولین اتصال، کمتر از یک دقیقه',
            'steps' => [
                ['t' => 'برنامه را نصب کنید', 'd' => 'نسخه‌ی متناسب با دستگاهتان را دانلود و اجرا کنید؛ نیازی به ثبت‌نام نیست.'],
                ['t' => 'شناسه و رمز را بگیرید', 'd' => 'هر دستگاه یک شناسه‌ی یکتا و رمز موقت نشان می‌دهد. آن را با طرف مقابل به اشتراک بگذارید.'],
                ['t' => 'وصل شوید', 'd' => 'شناسه را وارد کنید، رمز را بزنید و کنترل کامل دستگاه مقصد در اختیار شماست.'],
            ],

            'downloads_badge' => 'دانلود',
            'downloads_t' => 'روی همه‌ی دستگاه‌های خود نصب کنید',
            'downloads_d' => 'نسخه‌ی متناسب با سیستم‌عامل خود را از پورتال ریموت دریافت کنید.',
            'downloads_btn' => 'دانلود',
            'downloads' => [
                ['icon' => 'monitor', 't' => 'ویندوز', 'meta' => '۶۴ بیت · قابل‌حمل', 'href' => 'https://remote.servernet.cloud'],
                ['icon' => 'smartphone', 't' => 'اندروید', 'meta' => 'arm64 · APK', 'href' => 'https://remote.servernet.cloud'],
                ['icon' => 'monitor', 't' => 'مک', 'meta' => 'Apple Silicon · DMG', 'href' => 'https://remote.servernet.cloud'],
                ['icon' => 'smartphone', 't' => 'آیفون', 'meta' => 'IPA · Sideload', 'href' => 'https://remote.servernet.cloud'],
            ],

            'compare_t' => 'چرا سرورنت ریموت، نه ابزار خارجی؟',
            'compare_col0' => 'ویژگی', 'compare_us' => 'سرورنت ریموت', 'compare_them' => 'ابزارهای خارجی',
            'compare' => [
                ['f' => 'سرور داخل ایران و پینگ پایین', 'us' => 'بله', 'them' => 'خیر'],
                ['f' => 'بدون نیاز به فیلترشکن', 'us' => 'بله', 'them' => 'اغلب لازم'],
                ['f' => 'رمزنگاری سرتاسری', 'us' => 'بله', 'them' => 'بله'],
                ['f' => 'رایگان برای استفاده‌ی شخصی', 'us' => 'بله', 'them' => 'محدود'],
                ['f' => 'پشتیبانی فارسی', 'us' => 'کامل', 'them' => 'خیر'],
                ['f' => 'سرور اختصاصی سازمانی', 'us' => 'بله', 'them' => 'بسیار گران'],
            ],

            'faq_t' => 'پرسش‌های پرتکرار',
            'faq' => [
                ['q' => 'سرورنت ریموت رایگان است؟', 'a' => 'بله، برای استفاده‌ی شخصی کاملاً رایگان است. برای سازمان‌ها و تیم‌های پشتیبانی، نسخه‌ی سرور اختصاصی با امکانات بیشتر ارائه می‌شود.'],
                ['q' => 'به فیلترشکن نیاز دارم؟', 'a' => 'خیر. سرور رله داخل ایران است، پس بدون هیچ ابزار جانبی و با تأخیر پایین وصل می‌شوید.'],
                ['q' => 'امن است؟', 'a' => 'تمام نشست‌ها با رمزنگاری سرتاسری محافظت می‌شوند؛ داده‌ها بین دو دستگاه رمز می‌مانند و روی سرور رله قابل خواندن نیستند.'],
                ['q' => 'روی چه دستگاه‌هایی کار می‌کند؟', 'a' => 'ویندوز، اندروید، مک و آیفون. هر ترکیبی از این دستگاه‌ها می‌توانند به هم وصل شوند.'],
            ],

            'cta_t' => 'دسترسی از راه دور را ساده کنید',
            'cta_d' => 'همین حالا برنامه را دریافت کنید و اولین اتصال امن خود را برقرار کنید — یا برای نسخه‌ی سازمانی با ما تماس بگیرید.',
            'cta_btn' => 'دریافت برنامه', 'cta_href' => 'https://remote.servernet.cloud',
            'cta_btn2' => 'نسخه‌ی سازمانی',
        ],

        'en' => [
            'meta_t' => 'ServerNet Remote — Fast, Secure Remote Desktop',
            'meta_d' => 'ServerNet\'s professional remote-access tool — faster and more secure than TeamViewer and AnyDesk, with an in-region relay, low latency, end-to-end encryption and free personal use. Windows, Android, Mac and iPhone.',
            'badge' => 'In-region relay server · no VPN needed',
            'h1a' => 'Remote desktop control,', 'h1b' => 'at the level of the world\'s best datacenters.',
            'lead' => 'ServerNet Remote is a remote-access tool built by the ServerNet team — with end-to-end encryption, latency only an in-region server can deliver, and an experience smoother and safer than foreign tools. Connect to any device, from anywhere, with zero complex setup.',
            'cta1' => ['label' => 'Get the app', 'href' => 'https://remote.servernet.cloud'],
            'cta2' => ['label' => 'How it works', 'href' => '#steps'],
            'stats' => [
                ['n' => '18ms', 'l' => 'Live connection latency'],
                ['n' => 'E2E', 'l' => 'End-to-end encryption'],
                ['n' => 'Free', 'l' => 'For personal use'],
                ['n' => '4', 'l' => 'Supported platforms'],
            ],
            'trust' => [
                ['icon' => 'pin', 't' => 'In-region server'],
                ['icon' => 'lock', 't' => 'End-to-end encryption'],
                ['icon' => 'zap', 't' => 'No port forwarding'],
                ['icon' => 'smartphone', 't' => 'Windows, Android, Mac, iPhone'],
            ],

            'features_badge' => 'Why ServerNet Remote',
            'features_t' => 'Everything you need for remote access',
            'features_d' => 'Simple for everyday users, powerful for support teams and sysadmins.',
            'features' => [
                ['icon' => 'lock', 't' => 'Real security', 'd' => 'Every session is protected with end-to-end encryption; your screen, keystrokes and files stay encrypted between the two devices — no one in the middle can see them.'],
                ['icon' => 'zap', 't' => 'Low latency', 'd' => 'An in-region relay means a smooth, lag-free picture even on home internet — something foreign tools simply can\'t match.'],
                ['icon' => 'check', 't' => 'Zero setup', 'd' => 'Open the app, get an ID and password, and connect. No static IP, no port forwarding, no technical knowledge required.'],
                ['icon' => 'smartphone', 't' => 'Cross-platform', 'd' => 'From Windows to Android, from Mac to iPhone — any combination of devices connects to any other.'],
                ['icon' => 'restore', 't' => 'File transfer', 'd' => 'Drag and drop files between devices; remote printing and shared clipboard are supported too.'],
                ['icon' => 'headset', 't' => 'Remote support', 'd' => 'For IT and customer-support teams: connect to a user\'s device fast and solve the problem right there.'],
            ],

            'steps_badge' => 'In three steps',
            'steps_t' => 'From install to first connection in under a minute',
            'steps' => [
                ['t' => 'Install the app', 'd' => 'Download and run the version for your device; no sign-up needed.'],
                ['t' => 'Get ID & password', 'd' => 'Each device shows a unique ID and a temporary password. Share it with the other side.'],
                ['t' => 'Connect', 'd' => 'Enter the ID, type the password, and you have full control of the target device.'],
            ],

            'downloads_badge' => 'Download',
            'downloads_t' => 'Install on all your devices',
            'downloads_d' => 'Get the build for your operating system from the Remote portal.',
            'downloads_btn' => 'Download',
            'downloads' => [
                ['icon' => 'monitor', 't' => 'Windows', 'meta' => '64-bit · portable', 'href' => 'https://remote.servernet.cloud'],
                ['icon' => 'smartphone', 't' => 'Android', 'meta' => 'arm64 · APK', 'href' => 'https://remote.servernet.cloud'],
                ['icon' => 'monitor', 't' => 'Mac', 'meta' => 'Apple Silicon · DMG', 'href' => 'https://remote.servernet.cloud'],
                ['icon' => 'smartphone', 't' => 'iPhone', 'meta' => 'IPA · Sideload', 'href' => 'https://remote.servernet.cloud'],
            ],

            'compare_t' => 'Why ServerNet Remote, not a foreign tool?',
            'compare_col0' => 'Feature', 'compare_us' => 'ServerNet Remote', 'compare_them' => 'Foreign tools',
            'compare' => [
                ['f' => 'In-region server & low ping', 'us' => 'Yes', 'them' => 'No'],
                ['f' => 'No VPN required', 'us' => 'Yes', 'them' => 'Often needed'],
                ['f' => 'End-to-end encryption', 'us' => 'Yes', 'them' => 'Yes'],
                ['f' => 'Free for personal use', 'us' => 'Yes', 'them' => 'Limited'],
                ['f' => 'Local-language support', 'us' => 'Full', 'them' => 'No'],
                ['f' => 'Dedicated enterprise server', 'us' => 'Yes', 'them' => 'Very expensive'],
            ],

            'faq_t' => 'Frequently asked questions',
            'faq' => [
                ['q' => 'Is ServerNet Remote free?', 'a' => 'Yes, it is completely free for personal use. For organizations and support teams we offer a dedicated-server edition with more capabilities.'],
                ['q' => 'Do I need a VPN?', 'a' => 'No. The relay server is in-region, so you connect with low latency and no extra tools.'],
                ['q' => 'Is it secure?', 'a' => 'Every session is protected with end-to-end encryption; data stays encrypted between the two devices and is not readable on the relay server.'],
                ['q' => 'Which devices does it work on?', 'a' => 'Windows, Android, Mac and iPhone. Any combination of these devices can connect to each other.'],
            ],

            'cta_t' => 'Make remote access simple',
            'cta_d' => 'Get the app now and make your first secure connection — or contact us for the enterprise edition.',
            'cta_btn' => 'Get the app', 'cta_href' => 'https://remote.servernet.cloud',
            'cta_btn2' => 'Enterprise edition',
        ],

        'tr' => [
            'meta_t' => 'ServerNet Remote — Hızlı, Güvenli Uzak Masaüstü',
            'meta_d' => 'ServerNet\'in profesyonel uzaktan erişim aracı — TeamViewer ve AnyDesk\'ten daha hızlı ve güvenli; bölge içi röle, düşük gecikme, uçtan uca şifreleme ve kişisel kullanım için ücretsiz. Windows, Android, Mac ve iPhone.',
            'badge' => 'Bölge içi röle sunucusu · VPN gerekmez',
            'h1a' => 'Uzak masaüstü kontrolü,', 'h1b' => 'dünyanın en iyi veri merkezleri seviyesinde.',
            'lead' => 'ServerNet Remote, ServerNet ekibinin geliştirdiği bir uzaktan erişim aracıdır — uçtan uca şifreleme, yalnızca bölge içi sunucunun sağlayabileceği gecikme ve yabancı araçlardan daha akıcı, daha güvenli bir deneyim. Her cihaza, her yerden, karmaşık kurulum olmadan bağlanın.',
            'cta1' => ['label' => 'Uygulamayı al', 'href' => 'https://remote.servernet.cloud'],
            'cta2' => ['label' => 'Nasıl çalışır', 'href' => '#steps'],
            'stats' => [
                ['n' => '18ms', 'l' => 'Canlı bağlantı gecikmesi'],
                ['n' => 'E2E', 'l' => 'Uçtan uca şifreleme'],
                ['n' => 'Ücretsiz', 'l' => 'Kişisel kullanım için'],
                ['n' => '4', 'l' => 'Desteklenen platform'],
            ],
            'trust' => [
                ['icon' => 'pin', 't' => 'Bölge içi sunucu'],
                ['icon' => 'lock', 't' => 'Uçtan uca şifreleme'],
                ['icon' => 'zap', 't' => 'Port yönlendirme yok'],
                ['icon' => 'smartphone', 't' => 'Windows, Android, Mac, iPhone'],
            ],

            'features_badge' => 'Neden ServerNet Remote',
            'features_t' => 'Uzaktan erişim için ihtiyacınız olan her şey',
            'features_d' => 'Günlük kullanıcı için basit, destek ekipleri ve sistem yöneticileri için güçlü.',
            'features' => [
                ['icon' => 'lock', 't' => 'Gerçek güvenlik', 'd' => 'Her oturum uçtan uca şifreleme ile korunur; ekranınız, tuş vuruşlarınız ve dosyalarınız iki cihaz arasında şifreli kalır.'],
                ['icon' => 'zap', 't' => 'Düşük gecikme', 'd' => 'Bölge içi röle, ev internetinde bile akıcı, gecikmesiz bir görüntü demektir — yabancı araçların sağlayamadığı bir şey.'],
                ['icon' => 'check', 't' => 'Sıfır kurulum', 'd' => 'Uygulamayı açın, bir kimlik ve şifre alın ve bağlanın. Statik IP, port yönlendirme veya teknik bilgi gerekmez.'],
                ['icon' => 'smartphone', 't' => 'Çok platformlu', 'd' => 'Windows\'tan Android\'e, Mac\'ten iPhone\'a — cihazların her kombinasyonu birbirine bağlanır.'],
                ['icon' => 'restore', 't' => 'Dosya aktarımı', 'd' => 'Dosyaları cihazlar arasında sürükleyip bırakın; uzaktan yazdırma ve paylaşılan pano da desteklenir.'],
                ['icon' => 'headset', 't' => 'Uzaktan destek', 'd' => 'BT ve müşteri destek ekipleri için: kullanıcının cihazına hızla bağlanın ve sorunu orada çözün.'],
            ],

            'steps_badge' => 'Üç adımda',
            'steps_t' => 'Kurulumdan ilk bağlantıya bir dakikadan az',
            'steps' => [
                ['t' => 'Uygulamayı kurun', 'd' => 'Cihazınıza uygun sürümü indirip çalıştırın; kayıt gerekmez.'],
                ['t' => 'Kimlik ve şifre alın', 'd' => 'Her cihaz benzersiz bir kimlik ve geçici şifre gösterir. Karşı tarafla paylaşın.'],
                ['t' => 'Bağlanın', 'd' => 'Kimliği girin, şifreyi yazın ve hedef cihazın tam kontrolü sizde.'],
            ],

            'downloads_badge' => 'İndir',
            'downloads_t' => 'Tüm cihazlarınıza kurun',
            'downloads_d' => 'İşletim sisteminize uygun sürümü Remote portalından alın.',
            'downloads_btn' => 'İndir',
            'downloads' => [
                ['icon' => 'monitor', 't' => 'Windows', 'meta' => '64-bit · taşınabilir', 'href' => 'https://remote.servernet.cloud'],
                ['icon' => 'smartphone', 't' => 'Android', 'meta' => 'arm64 · APK', 'href' => 'https://remote.servernet.cloud'],
                ['icon' => 'monitor', 't' => 'Mac', 'meta' => 'Apple Silicon · DMG', 'href' => 'https://remote.servernet.cloud'],
                ['icon' => 'smartphone', 't' => 'iPhone', 'meta' => 'IPA · Sideload', 'href' => 'https://remote.servernet.cloud'],
            ],

            'compare_t' => 'Neden yabancı araç değil, ServerNet Remote?',
            'compare_col0' => 'Özellik', 'compare_us' => 'ServerNet Remote', 'compare_them' => 'Yabancı araçlar',
            'compare' => [
                ['f' => 'Bölge içi sunucu ve düşük ping', 'us' => 'Evet', 'them' => 'Hayır'],
                ['f' => 'VPN gerekmez', 'us' => 'Evet', 'them' => 'Sıklıkla gerekir'],
                ['f' => 'Uçtan uca şifreleme', 'us' => 'Evet', 'them' => 'Evet'],
                ['f' => 'Kişisel kullanım ücretsiz', 'us' => 'Evet', 'them' => 'Sınırlı'],
                ['f' => 'Yerel dil desteği', 'us' => 'Tam', 'them' => 'Hayır'],
                ['f' => 'Kuruma özel sunucu', 'us' => 'Evet', 'them' => 'Çok pahalı'],
            ],

            'faq_t' => 'Sıkça sorulan sorular',
            'faq' => [
                ['q' => 'ServerNet Remote ücretsiz mi?', 'a' => 'Evet, kişisel kullanım için tamamen ücretsizdir. Kuruluşlar ve destek ekipleri için daha fazla özelliğe sahip özel sunucu sürümü sunuyoruz.'],
                ['q' => 'VPN gerekir mi?', 'a' => 'Hayır. Röle sunucusu bölge içindedir, böylece düşük gecikmeyle ve ek araç olmadan bağlanırsınız.'],
                ['q' => 'Güvenli mi?', 'a' => 'Her oturum uçtan uca şifreleme ile korunur; veriler iki cihaz arasında şifreli kalır ve röle sunucusunda okunamaz.'],
                ['q' => 'Hangi cihazlarda çalışır?', 'a' => 'Windows, Android, Mac ve iPhone. Bu cihazların her kombinasyonu birbirine bağlanabilir.'],
            ],

            'cta_t' => 'Uzaktan erişimi basitleştirin',
            'cta_d' => 'Uygulamayı şimdi alın ve ilk güvenli bağlantınızı kurun — veya kurumsal sürüm için bizimle iletişime geçin.',
            'cta_btn' => 'Uygulamayı al', 'cta_href' => 'https://remote.servernet.cloud',
            'cta_btn2' => 'Kurumsal sürüm',
        ],
    ],

];
