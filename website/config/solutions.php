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

];
