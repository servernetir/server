<?php

/*
|--------------------------------------------------------------------------
| ServerNet Cloud — site data
|--------------------------------------------------------------------------
| اطلاعات تماس، لینک‌های WHMCS و داده‌های صفحه اصلی (fa / en / tr).
| قیمت‌های TLD زنده از WHMCS خوانده می‌شوند؛ بقیه فعلاً ثابت‌اند.
*/

return [

    'contact' => [
        'phone'         => env('SITE_SUPPORT_PHONE', '+1 (716) 666 0425'),
        'phone_link'    => env('SITE_SUPPORT_PHONE_LINK', '+17166660425'),
        'email'         => env('SITE_SUPPORT_EMAIL', 'support@servernet.cloud'),
        'sales_email'   => env('SITE_SALES_EMAIL', 'sales@servernet.cloud'),
        'whatsapp'      => env('SITE_WHATSAPP', '17166660425'),
    ],

    'whmcs' => [
        'fa' => env('WHMCS_URL_FA', 'https://my.servernet.ir'),
        'en' => env('WHMCS_URL_EN', 'https://my.servernet.cloud'),
        'tr' => env('WHMCS_URL_TR', 'https://my.servernet.cloud'),
    ],

    /*
    |----------------------------------------------------------------------
    | دسترسی API خود WHMCS — ترکی از همان WHMCS بین‌المللی (cloud) می‌خواند
    |----------------------------------------------------------------------
    */
    'whmcs_api' => [
        'fa' => [
            'url'        => env('WHMCS_FA_API_URL', 'https://my.servernet.ir/includes/api.php'),
            'identifier' => env('WHMCS_FA_API_IDENTIFIER'),
            'secret'     => env('WHMCS_FA_API_SECRET'),
            'currency'   => env('WHMCS_FA_CURRENCY_ID'),
        ],
        'en' => [
            'url'        => env('WHMCS_EN_API_URL', 'https://my.servernet.cloud/includes/api.php'),
            'identifier' => env('WHMCS_EN_API_IDENTIFIER'),
            'secret'     => env('WHMCS_EN_API_SECRET'),
            'currency'   => env('WHMCS_EN_CURRENCY_ID'),
        ],
        'tr' => [
            'url'        => env('WHMCS_EN_API_URL', 'https://my.servernet.cloud/includes/api.php'),
            'identifier' => env('WHMCS_EN_API_IDENTIFIER'),
            'secret'     => env('WHMCS_EN_API_SECRET'),
            'currency'   => env('WHMCS_EN_CURRENCY_ID'),
        ],
    ],

    'social' => [
        'linkedin'  => 'https://www.linkedin.com/company/servernet-co/',
        'instagram' => 'https://www.instagram.com/servernet.ir/',
    ],

    'products' => [
        ['icon' => 'globe',  'pid' => 1, 'eur' => 1.99, 'irt' => 149000,
         'en' => ['t' => 'Linux Hosting', 'd' => 'Fast NVMe shared hosting with cPanel/DirectAdmin, free SSL and daily backups.'],
         'fa' => ['t' => 'هاست لینوکس', 'd' => 'هاست اشتراکی پرسرعت NVMe با سی‌پنل/دایرکت‌ادمین، SSL رایگان و بکاپ روزانه.'],
         'tr' => ['t' => 'Linux Hosting', 'd' => 'cPanel/DirectAdmin ile hızlı NVMe hosting, ücretsiz SSL ve günlük yedekleme.']],
        ['icon' => 'zap',    'pid' => 2, 'eur' => 2.49, 'irt' => 250000,
         'en' => ['t' => 'WordPress Hosting', 'd' => 'Optimized stack for WordPress & WooCommerce with staging and caching built in.'],
         'fa' => ['t' => 'هاست وردپرس', 'd' => 'بهینه‌شده برای وردپرس و ووکامرس همراه با کش اختصاصی و محیط استیجینگ.'],
         'tr' => ['t' => 'WordPress Hosting', 'd' => 'WordPress ve WooCommerce için optimize altyapı; staging ve önbellek dahili.']],
        ['icon' => 'cpu',    'pid' => 3, 'eur' => 4.90, 'irt' => 490000,
         'en' => ['t' => 'VPS Servers', 'd' => 'Instant-deploy virtual servers with dedicated resources in Iran & Europe.'],
         'fa' => ['t' => 'سرور مجازی', 'd' => 'تحویل آنی با منابع اختصاصی در لوکیشن‌های ایران و اروپا.'],
         'tr' => ['t' => 'VPS Sunucular', 'd' => 'İran ve Avrupa\'da anında kurulan, kaynakları özel sanal sunucular.']],
        ['icon' => 'server', 'pid' => 4, 'eur' => 39,   'irt' => 4900000,
         'en' => ['t' => 'Dedicated Servers', 'd' => 'Bare-metal power in Iran, Germany, France, Canada & the Netherlands.'],
         'fa' => ['t' => 'سرور اختصاصی', 'd' => 'قدرت سخت‌افزار اختصاصی در ایران، آلمان، فرانسه، کانادا و هلند.'],
         'tr' => ['t' => 'Fiziksel Sunucular', 'd' => 'İran, Almanya, Fransa, Kanada ve Hollanda\'da bare-metal güç.']],
        ['icon' => 'cloud',  'pid' => 5, 'eur' => 5.90, 'irt' => 590000,
         'en' => ['t' => 'Cloud Services', 'd' => 'Scalable cloud servers, object storage and load balancers — pay as you grow.'],
         'fa' => ['t' => 'خدمات ابری', 'd' => 'سرور ابری مقیاس‌پذیر، فضای ذخیره‌سازی و لودبالانسر — پرداخت به‌اندازه مصرف.'],
         'tr' => ['t' => 'Bulut Hizmetleri', 'd' => 'Ölçeklenebilir bulut sunucular, depolama ve yük dengeleyici — kullandıkça öde.']],
        ['icon' => 'db',     'pid' => 6, 'eur' => 9.99, 'irt' => 250000,
         'en' => ['t' => 'Domains', 'd' => 'Register .com, .net, .io, .ir and 100+ TLDs with free DNS management.'],
         'fa' => ['t' => 'ثبت دامنه', 'd' => 'ثبت .com و .ir و بیش از ۱۰۰ پسوند دیگر با مدیریت DNS رایگان.'],
         'tr' => ['t' => 'Alan Adları', 'd' => '.com, .net, .io ve 100+ uzantıyı ücretsiz DNS yönetimiyle kaydedin.']],
    ],

    'plans' => [
        ['pid' => 11, 'eur' => 4.90,  'irt' => 490000,  'cpu' => '1 vCPU', 'ram' => '2 GB RAM',  'ssd' => '25 GB NVMe',  'tb' => '1 TB', 'en' => 'Starter',  'fa' => 'اقتصادی', 'tr' => 'Başlangıç'],
        ['pid' => 12, 'eur' => 9.90,  'irt' => 990000,  'cpu' => '2 vCPU', 'ram' => '4 GB RAM',  'ssd' => '60 GB NVMe',  'tb' => '3 TB', 'en' => 'Business', 'fa' => 'تجاری', 'tr' => 'İşletme', 'popular' => true],
        ['pid' => 13, 'eur' => 18.90, 'irt' => 1890000, 'cpu' => '4 vCPU', 'ram' => '8 GB RAM',  'ssd' => '120 GB NVMe', 'tb' => '5 TB', 'en' => 'Pro',      'fa' => 'حرفه‌ای', 'tr' => 'Pro'],
        ['pid' => 14, 'eur' => 34.90, 'irt' => 3490000, 'cpu' => '8 vCPU', 'ram' => '16 GB RAM', 'ssd' => '240 GB NVMe', 'tb' => '8 TB', 'en' => 'Elite',    'fa' => 'سازمانی', 'tr' => 'Elite'],
    ],

    'enterprise' => [
        ['icon' => 'factory', 'wide' => true, 'tag' => 'Core', 'slug' => 'infrastructure',
         'en' => ['t' => 'Enterprise Infrastructure', 'd' => 'Design, supply and support of IT infrastructure for factories and large organizations — including physical server sales and datacenter solutions.'],
         'fa' => ['t' => 'زیرساخت سازمانی', 'd' => 'طراحی، تامین و پشتیبانی زیرساخت IT برای کارخانجات و سازمان‌های بزرگ — شامل فروش فیزیکی سرور و راهکارهای دیتاسنتر.'],
         'tr' => ['t' => 'Kurumsal Altyapı', 'd' => 'Fabrikalar ve büyük kuruluşlar için BT altyapısı tasarımı, tedariki ve desteği — fiziksel sunucu satışı ve veri merkezi çözümleri dahil.']],
        ['icon' => 'mail', 'tag' => 'Amazon SES', 'hosting' => 'email',
         'en' => ['t' => 'Business & Transactional Email', 'd' => 'Business mailboxes plus bulk/transactional email on Amazon SES — SPF/DKIM/DMARC, SMTP & API, full setup.'],
         'fa' => ['t' => 'ایمیل سازمانی و تراکنشی', 'd' => 'صندوق‌های ایمیل سازمانی به‌همراه ارسال انبوه و تراکنشی روی Amazon SES — SPF/DKIM/DMARC، SMTP و API و راه‌اندازی کامل.'],
         'tr' => ['t' => 'Kurumsal ve İşlemsel E-posta', 'd' => 'Kurumsal posta kutuları ve Amazon SES üzerinde toplu/işlemsel e-posta — SPF/DKIM/DMARC, SMTP ve API, tam kurulum.']],
        ['icon' => 'bot', 'tag' => 'AI', 'slug' => 'ai-agents',
         'en' => ['t' => 'AI Agents', 'd' => 'Custom AI agents that automate support, sales and internal processes.'],
         'fa' => ['t' => 'طراحی AI Agent', 'd' => 'ایجنت‌های هوش مصنوعی اختصاصی برای اتوماسیون پشتیبانی، فروش و فرایندها.'],
         'tr' => ['t' => 'Yapay Zeka Ajanları', 'd' => 'Destek, satış ve iç süreçleri otomatikleştiren özel AI ajanları.']],
        ['icon' => 'flow', 'slug' => 'bpmn-erp',
         'en' => ['t' => 'BPMN & ERP', 'd' => 'Process design and implementation of BPMN workflows and ERP systems.'],
         'fa' => ['t' => 'BPMN و ERP', 'd' => 'طراحی فرایند و پیاده‌سازی BPMN و سیستم‌های ERP سازمانی.'],
         'tr' => ['t' => 'BPMN & ERP', 'd' => 'BPMN iş akışları ve ERP sistemlerinin tasarımı ve uygulanması.']],
        ['icon' => 'layout', 'slug' => 'web-design',
         'en' => ['t' => 'Web Design & App Builder', 'd' => 'Professional website design plus ready-made apps and no-code builder.'],
         'fa' => ['t' => 'طراحی سایت و اپ‌ساز', 'd' => 'طراحی حرفه‌ای وب‌سایت به‌همراه برنامه‌های آماده و اپلیکیشن‌ساز.'],
         'tr' => ['t' => 'Web Tasarım & Uygulama Oluşturucu', 'd' => 'Profesyonel web tasarımı, hazır uygulamalar ve kodsuz oluşturucu.']],
        ['icon' => 'trend', 'slug' => 'seo-services',
         'en' => ['t' => 'SEO Services', 'd' => 'Technical SEO, content strategy and measurable organic growth.'],
         'fa' => ['t' => 'خدمات سئو', 'd' => 'سئوی تکنیکال، استراتژی محتوا و رشد ارگانیک قابل اندازه‌گیری.'],
         'tr' => ['t' => 'SEO Hizmetleri', 'd' => 'Teknik SEO, içerik stratejisi ve ölçülebilir organik büyüme.']],
        ['icon' => 'wrench', 'wide' => true, 'tag' => '24/7', 'slug' => 'managed',
         'en' => ['t' => 'Managed Services', 'd' => 'Server management, monitoring, security hardening and disaster recovery — handled end-to-end by our experts so your team focuses on the business.'],
         'fa' => ['t' => 'مدیریت سرور', 'd' => 'مدیریت، مانیتورینگ، امن‌سازی و بازیابی بحران — به‌صورت کامل توسط متخصصان ما، تا تیم شما روی کسب‌وکار تمرکز کند.'],
         'tr' => ['t' => 'Yönetilen Hizmetler', 'd' => 'Sunucu yönetimi, izleme, güvenlik sıkılaştırma ve felaket kurtarma — uçtan uca uzmanlarımızda; siz işinize odaklanın.']],
    ],

    'why' => [
        ['icon' => 'headset', 'en' => ['t' => '24/7 Support', 'd' => 'Real experts, every hour of every day.'],          'fa' => ['t' => 'پشتیبانی ۲۴/۷', 'd' => 'متخصصان واقعی، در تمام ساعات شبانه‌روز.'],   'tr' => ['t' => '7/24 Destek', 'd' => 'Gerçek uzmanlar, günün her saati.']],
        ['icon' => 'lock',    'en' => ['t' => 'Free SSL', 'd' => 'Every plan ships with SSL certificates.'],             'fa' => ['t' => 'SSL رایگان', 'd' => 'همه پلن‌ها همراه با گواهی SSL.'],                'tr' => ['t' => 'Ücretsiz SSL', 'd' => 'Tüm paketlerde SSL sertifikası dahil.']],
        ['icon' => 'restore', 'en' => ['t' => 'Backup & Restore', 'd' => 'Automated daily backups, one-click restore.'], 'fa' => ['t' => 'بکاپ و بازیابی', 'd' => 'بکاپ خودکار روزانه با بازیابی یک‌کلیکی.'],   'tr' => ['t' => 'Yedekleme & Geri Yükleme', 'd' => 'Otomatik günlük yedek, tek tıkla geri yükleme.']],
        ['icon' => 'coins',   'en' => ['t' => 'Money-back guarantee', 'd' => 'Not satisfied? Get your money back.'],     'fa' => ['t' => 'ضمانت بازگشت وجه', 'd' => 'راضی نبودید؟ وجه شما بازگردانده می‌شود.'], 'tr' => ['t' => 'Para İade Garantisi', 'd' => 'Memnun kalmazsanız ücretiniz iade edilir.']],
        ['icon' => 'hdd',     'en' => ['t' => 'NVMe Performance', 'd' => 'No speed limits — latest-gen hardware.'],      'fa' => ['t' => 'سرعت NVMe', 'd' => 'بدون محدودیت سرعت — سخت‌افزار نسل جدید.'],        'tr' => ['t' => 'NVMe Performansı', 'd' => 'Hız sınırı yok — son nesil donanım.']],
        ['icon' => 'gift',    'en' => ['t' => 'Free Domain', 'd' => 'Free domain with annual hosting plans.'],           'fa' => ['t' => 'دامنه رایگان', 'd' => 'دامنه رایگان همراه پلن‌های سالانه.'],           'tr' => ['t' => 'Ücretsiz Alan Adı', 'd' => 'Yıllık hosting paketlerinde ücretsiz alan adı.']],
    ],

    'locations' => [
        ['en' => 'Tehran, Iran',           'fa' => 'تهران، ایران',      'tr' => 'Tahran, İran'],
        ['en' => 'Frankfurt, Germany',     'fa' => 'فرانکفورت، آلمان',  'tr' => 'Frankfurt, Almanya'],
        ['en' => 'Paris, France',          'fa' => 'پاریس، فرانسه',     'tr' => 'Paris, Fransa'],
        ['en' => 'Amsterdam, Netherlands', 'fa' => 'آمستردام، هلند',    'tr' => 'Amsterdam, Hollanda'],
        ['en' => 'Toronto, Canada',        'fa' => 'تورنتو، کانادا',    'tr' => 'Toronto, Kanada'],
    ],

    'faqs' => [
        ['en' => ['q' => 'How fast is my service activated?', 'a' => 'Hosting, VPS and domains are activated instantly after payment through our automated system. Dedicated servers are typically delivered within 24 hours.'],
         'fa' => ['q' => 'سرویس من چقدر سریع فعال می‌شود؟', 'a' => 'هاست، سرور مجازی و دامنه بلافاصله پس از پرداخت به‌صورت خودکار فعال می‌شوند. سرورهای اختصاصی معمولاً ظرف ۲۴ ساعت تحویل داده می‌شوند.'],
         'tr' => ['q' => 'Hizmetim ne kadar sürede aktifleşir?', 'a' => 'Hosting, VPS ve alan adları ödeme sonrası otomatik olarak anında aktifleşir. Fiziksel sunucular genellikle 24 saat içinde teslim edilir.']],
        ['en' => ['q' => 'Can I pay in Euros or Iranian Toman?', 'a' => 'Yes — international customers pay in EUR via my.servernet.cloud, and Iranian customers pay in Toman via my.servernet.ir. Switch language above to see local pricing.'],
         'fa' => ['q' => 'پرداخت به تومان یا یورو ممکن است؟', 'a' => 'بله — کاربران ایرانی از طریق my.servernet.ir به تومان و کاربران بین‌المللی از طریق my.servernet.cloud به یورو پرداخت می‌کنند.'],
         'tr' => ['q' => 'Euro ile ödeme yapabilir miyim?', 'a' => 'Evet — uluslararası müşteriler my.servernet.cloud üzerinden EUR ile öder. Tüm uluslararası ödeme yöntemleri desteklenir.']],
        ['en' => ['q' => 'Do you help with server migration?', 'a' => 'Absolutely. Our team migrates your websites and servers from any provider, free of charge and with zero-downtime planning.'],
         'fa' => ['q' => 'در انتقال سرور کمک می‌کنید؟', 'a' => 'بله. تیم ما وب‌سایت‌ها و سرورهای شما را از هر شرکت دیگری، رایگان و با برنامه‌ریزی بدون قطعی منتقل می‌کند.'],
         'tr' => ['q' => 'Sunucu taşımada yardımcı oluyor musunuz?', 'a' => 'Kesinlikle. Ekibimiz web sitelerinizi ve sunucularınızı herhangi bir sağlayıcıdan ücretsiz ve kesintisiz taşır.']],
        ['en' => ['q' => 'Do you offer custom enterprise solutions?', 'a' => 'Yes — infrastructure design, physical server supply, AI agents, BPMN/ERP implementation and more. Contact our solutions team for a free consultation.'],
         'fa' => ['q' => 'راهکار اختصاصی سازمانی هم دارید؟', 'a' => 'بله — طراحی زیرساخت، تامین فیزیکی سرور، ایجنت‌های هوش مصنوعی، پیاده‌سازی BPMN/ERP و بیشتر. برای مشاوره رایگان با تیم راهکارها تماس بگیرید.'],
         'tr' => ['q' => 'Kuruma özel çözümler sunuyor musunuz?', 'a' => 'Evet — altyapı tasarımı, fiziksel sunucu tedariki, AI ajanları, BPMN/ERP uygulamaları ve daha fazlası. Ücretsiz danışmanlık için ekibimizle iletişime geçin.']],
    ],

    'brands' => ['SNAPP', 'SHAWL', 'THUNDER', 'ZOOMIT', 'DIGIKALA', 'QUERA', 'EVAND', 'PORSLINE'],

    /*
    |----------------------------------------------------------------------
    | تخفیف پرداخت سالانه (درصد)
    |----------------------------------------------------------------------
    */
    'yearly_discount' => 20,

    /*
    |----------------------------------------------------------------------
    | پسوندهای منتخب برای نمایش در صفحه اصلی (قیمت زنده از WHMCS)
    |----------------------------------------------------------------------
    */
    'featured_tlds' => ['.com', '.ir', '.net', '.org', '.io', '.co', '.shop', '.site', '.online', '.dev', '.cloud', '.ai'],

    /*
    |----------------------------------------------------------------------
    | پسوندهای نمونه — فقط fallback وقتی WHMCS API در دسترس نیست
    |----------------------------------------------------------------------
    */
    'tlds' => [
        ['tld' => '.com',   'irt' => 1290000, 'eur' => 12.90],
        ['tld' => '.ir',    'irt' => 165000,  'eur' => 3.90],
        ['tld' => '.net',   'irt' => 1590000, 'eur' => 14.90],
        ['tld' => '.org',   'irt' => 1490000, 'eur' => 13.90],
        ['tld' => '.io',    'irt' => 4900000, 'eur' => 49.00],
        ['tld' => '.co',    'irt' => 3400000, 'eur' => 33.00],
        ['tld' => '.dev',   'irt' => 1690000, 'eur' => 16.90],
        ['tld' => '.shop',  'irt' => 490000,  'eur' => 4.90],
        ['tld' => '.cloud', 'irt' => 990000,  'eur' => 9.90],
        ['tld' => '.ai',    'irt' => 9800000, 'eur' => 95.00],
    ],

    /*
    |----------------------------------------------------------------------
    | مگامنوی «محصولات» — تب‌ها و گروه‌های هر تب
    |----------------------------------------------------------------------
    */
    'mega' => [
        'hosting' => [
            'icon' => 'globe',
            'fa' => ['t' => 'هاست', 'd' => 'میزبانی وب پرسرعت NVMe'],
            'en' => ['t' => 'Hosting', 'd' => 'Blazing-fast NVMe web hosting'],
            'tr' => ['t' => 'Hosting', 'd' => 'Yüksek hızlı NVMe web hosting'],
            'groups' => [
                ['fa' => 'بر اساس کاربرد', 'en' => 'By use case', 'tr' => 'Kullanım amacına göre', 'items' => [
                    ['slug' => 'wordpress',   'fa' => 'هاست وردپرس', 'en' => 'WordPress Hosting', 'tr' => 'WordPress Hosting'],
                    ['slug' => 'woocommerce', 'fa' => 'هاست ووکامرس', 'en' => 'WooCommerce Hosting', 'tr' => 'WooCommerce Hosting'],
                    ['slug' => 'python',      'fa' => 'هاست پایتون', 'en' => 'Python Hosting', 'tr' => 'Python Hosting'],
                    ['slug' => 'download',    'fa' => 'هاست دانلود', 'en' => 'Download Hosting', 'tr' => 'İndirme Hosting'],
                    ['slug' => 'email',       'fa' => 'هاست ایمیل', 'en' => 'Email Hosting', 'tr' => 'E-posta Hosting'],
                    ['slug' => 'backup',      'fa' => 'هاست بکاپ', 'en' => 'Backup Hosting', 'tr' => 'Yedek Hosting'],
                ]],
                ['fa' => 'بر اساس سیستم‌عامل', 'en' => 'By operating system', 'tr' => 'İşletim sistemine göre', 'items' => [
                    ['slug' => 'linux',   'fa' => 'هاست لینوکس', 'en' => 'Linux Hosting', 'tr' => 'Linux Hosting'],
                    ['slug' => 'windows', 'fa' => 'هاست ویندوز', 'en' => 'Windows Hosting', 'tr' => 'Windows Hosting'],
                ]],
                ['fa' => 'نمایندگی هاست', 'en' => 'Reseller hosting', 'tr' => 'Bayilik (Reseller)', 'items' => [
                    ['slug' => 'reseller-wordpress', 'fa' => 'نمایندگی هاست وردپرس', 'en' => 'WordPress Reseller', 'tr' => 'WordPress Reseller'],
                    ['slug' => 'reseller-linux',     'fa' => 'نمایندگی هاست لینوکس', 'en' => 'Linux Reseller', 'tr' => 'Linux Reseller'],
                    ['slug' => 'reseller-windows',   'fa' => 'نمایندگی هاست ویندوز', 'en' => 'Windows Reseller', 'tr' => 'Windows Reseller'],
                ]],
            ],
        ],
        'domain' => [
            'icon' => 'db',
            'fa' => ['t' => 'دامنه', 'd' => 'ثبت، انتقال و مدیریت دامنه'],
            'en' => ['t' => 'Domains', 'd' => 'Register, transfer & manage'],
            'tr' => ['t' => 'Alan Adları', 'd' => 'Kayıt, transfer ve yönetim'],
            'groups' => [
                ['fa' => 'ثبت و انتقال دامنه', 'en' => 'Register & transfer', 'tr' => 'Kayıt ve transfer', 'items' => [
                    ['slug' => 'popular-tlds', 'fa' => 'دامنه عمومی', 'en' => 'Popular TLDs', 'tr' => 'Popüler Uzantılar'],
                    ['slug' => 'ir',           'fa' => 'دامنه IR', 'en' => '.ir Domains', 'tr' => '.ir Alan Adları'],
                    ['slug' => 'persian',      'fa' => 'دامنه فارسی', 'en' => 'Persian IDN Domains', 'tr' => 'Farsça (IDN) Alan Adları'],
                    ['slug' => 'premium',      'fa' => 'دامنه‌های خاص', 'en' => 'Premium TLDs', 'tr' => 'Premium Uzantılar'],
                ]],
                ['fa' => 'سایر خدمات', 'en' => 'Other services', 'tr' => 'Diğer hizmetler', 'items' => [
                    ['slug' => 'reseller',  'fa' => 'نمایندگی دامنه', 'en' => 'Domain Reseller', 'tr' => 'Alan Adı Bayiliği'],
                    ['slug' => 'backorder', 'fa' => 'رزرو دامنه', 'en' => 'Domain Backorder', 'tr' => 'Alan Adı Rezervasyonu'],
                ]],
            ],
        ],
        'vps' => [
            'icon' => 'cpu',
            'fa' => ['t' => 'سرور مجازی', 'd' => 'تحویل آنی، منابع اختصاصی'],
            'en' => ['t' => 'VPS', 'd' => 'Instant deploy, dedicated resources'],
            'tr' => ['t' => 'VPS', 'd' => 'Anında kurulum, özel kaynaklar'],
            'groups' => [
                ['fa' => 'موقعیت مکانی', 'en' => 'Locations', 'tr' => 'Lokasyonlar', 'items' => [
                    ['slug' => 'iran',          'fa' => 'سرور مجازی ایران', 'en' => 'Iran VPS', 'tr' => 'İran VPS'],
                    ['slug' => 'international', 'fa' => 'سرور مجازی خارج', 'en' => 'International VPS', 'tr' => 'Yurt Dışı VPS'],
                    ['slug' => 'france',        'fa' => 'سرور مجازی فرانسه', 'en' => 'France VPS', 'tr' => 'Fransa VPS'],
                    ['slug' => 'germany',       'fa' => 'سرور مجازی آلمان', 'en' => 'Germany VPS', 'tr' => 'Almanya VPS'],
                    ['slug' => 'finland',       'fa' => 'سرور مجازی فنلاند', 'en' => 'Finland VPS', 'tr' => 'Finlandiya VPS'],
                    ['slug' => 'usa',           'fa' => 'سرور مجازی آمریکا', 'en' => 'USA VPS', 'tr' => 'ABD VPS'],
                ]],
                ['fa' => 'بر اساس کاربرد', 'en' => 'By use case', 'tr' => 'Kullanım amacına göre', 'items' => [
                    ['slug' => 'trading', 'fa' => 'سرور مجازی ترید', 'en' => 'Trading VPS', 'tr' => 'Trade VPS'],
                    ['slug' => 'gpu',     'fa' => 'سرور مجازی گرافیکی', 'en' => 'GPU VPS', 'tr' => 'GPU VPS'],
                    ['slug' => 'cloud',   'fa' => 'سرور مجازی ابری', 'en' => 'Cloud VPS', 'tr' => 'Bulut VPS'],
                ]],
                ['fa' => 'سیستم‌عامل', 'en' => 'Operating system', 'tr' => 'İşletim sistemi', 'items' => [
                    ['slug' => 'linux',   'fa' => 'سرور مجازی لینوکس', 'en' => 'Linux VPS', 'tr' => 'Linux VPS'],
                    ['slug' => 'windows', 'fa' => 'سرور مجازی ویندوز', 'en' => 'Windows VPS', 'tr' => 'Windows VPS'],
                ]],
            ],
        ],
        'dedicated' => [
            'icon' => 'server',
            'fa' => ['t' => 'سرور اختصاصی', 'd' => 'قدرت کامل سخت‌افزار'],
            'en' => ['t' => 'Dedicated', 'd' => 'Full bare-metal power'],
            'tr' => ['t' => 'Fiziksel Sunucu', 'd' => 'Tam bare-metal güç'],
            'groups' => [
                ['fa' => 'موقعیت مکانی', 'en' => 'Locations', 'tr' => 'Lokasyonlar', 'items' => [
                    ['slug' => 'iran',    'fa' => 'سرور اختصاصی ایران', 'en' => 'Iran Dedicated', 'tr' => 'İran Fiziksel Sunucu'],
                    ['slug' => 'germany', 'fa' => 'سرور اختصاصی آلمان', 'en' => 'Germany Dedicated', 'tr' => 'Almanya Fiziksel Sunucu'],
                    ['slug' => 'finland', 'fa' => 'سرور اختصاصی فنلاند', 'en' => 'Finland Dedicated', 'tr' => 'Finlandiya Fiziksel Sunucu'],
                    ['slug' => 'france',  'fa' => 'سرور اختصاصی فرانسه', 'en' => 'France Dedicated', 'tr' => 'Fransa Fiziksel Sunucu'],
                    ['slug' => 'canada',  'fa' => 'سرور اختصاصی کانادا', 'en' => 'Canada Dedicated', 'tr' => 'Kanada Fiziksel Sunucu'],
                    ['slug' => 'usa',     'fa' => 'سرور اختصاصی آمریکا', 'en' => 'USA Dedicated', 'tr' => 'ABD Fiziksel Sunucu'],
                ]],
                ['fa' => 'دیتاسنتر', 'en' => 'Datacenters', 'tr' => 'Veri merkezleri', 'items' => [
                    ['slug' => 'iran-infrastructure', 'fa' => 'زیرساخت ایران', 'en' => 'Iran Infrastructure', 'tr' => 'İran Altyapısı'],
                    ['slug' => 'ovh',     'fa' => 'OVH', 'en' => 'OVH', 'tr' => 'OVH'],
                    ['slug' => 'hetzner', 'fa' => 'Hetzner', 'en' => 'Hetzner', 'tr' => 'Hetzner'],
                ]],
                ['fa' => 'سایر سرورها', 'en' => 'More servers', 'tr' => 'Diğer sunucular', 'items' => [
                    ['slug' => 'cloud',     'fa' => 'سرور اختصاصی ابری', 'en' => 'Cloud Dedicated', 'tr' => 'Bulut Dedicated'],
                    ['slug' => 'custom',    'fa' => 'سرور اختصاصی سفارشی', 'en' => 'Custom Build', 'tr' => 'Özel Yapılandırma'],
                    ['slug' => 'ecommerce', 'fa' => 'سرور اختصاصی فروشگاهی', 'en' => 'E-commerce Servers', 'tr' => 'E-ticaret Sunucuları'],
                    ['slug' => 'budget',    'fa' => 'سرور اختصاصی اقتصادی', 'en' => 'Budget Servers', 'tr' => 'Ekonomik Sunucular'],
                    ['slug' => 'managed',   'fa' => 'سرور اختصاصی مدیریت‌شده', 'en' => 'Managed Servers', 'tr' => 'Yönetilen Sunucular'],
                ]],
            ],
        ],
        'cloud' => [
            'icon' => 'cloud',
            'fa' => ['t' => 'خدمات ابری', 'd' => 'زیرساخت مقیاس‌پذیر ابری'],
            'en' => ['t' => 'Cloud', 'd' => 'Scalable cloud infrastructure'],
            'tr' => ['t' => 'Bulut', 'd' => 'Ölçeklenebilir bulut altyapısı'],
            'groups' => [
                ['fa' => 'پردازش ابری', 'en' => 'Cloud compute', 'tr' => 'Bulut bilişim', 'items' => [
                    ['slug' => 'iaas',       'fa' => 'زیرساخت ابری (IaaS)', 'en' => 'Cloud Infrastructure (IaaS)', 'tr' => 'Bulut Altyapı (IaaS)'],
                    ['slug' => 'gpuaas',     'fa' => 'زیرساخت گرافیکی (GPUaaS)', 'en' => 'GPU Cloud (GPUaaS)', 'tr' => 'GPU Bulut (GPUaaS)'],
                    ['slug' => 'bmaas',      'fa' => 'زیرساخت اختصاصی (BMaaS)', 'en' => 'Bare-metal Cloud (BMaaS)', 'tr' => 'Bare-metal Bulut (BMaaS)'],
                    ['slug' => 'kubernetes', 'fa' => 'کلاستر کوبرنتیز (KaaS)', 'en' => 'Kubernetes (KaaS)', 'tr' => 'Kubernetes (KaaS)'],
                ]],
                ['fa' => 'شبکه ابری', 'en' => 'Cloud network', 'tr' => 'Bulut ağ', 'items' => [
                    ['slug' => 'cdn',             'fa' => 'شبکه توزیع محتوا (CDN)', 'en' => 'Content Delivery (CDN)', 'tr' => 'İçerik Dağıtım Ağı (CDN)'],
                    ['slug' => 'ddos-protection', 'fa' => 'محافظت از حملات DDoS', 'en' => 'DDoS Protection', 'tr' => 'DDoS Koruması'],
                ]],
                ['fa' => 'فضای ابری', 'en' => 'Cloud storage', 'tr' => 'Bulut depolama', 'items' => [
                    ['slug' => 'block-storage',  'fa' => 'بلاک‌استوریج', 'en' => 'Block Storage', 'tr' => 'Block Storage'],
                    ['slug' => 'object-storage', 'fa' => 'آبجکت‌استوریج', 'en' => 'Object Storage', 'tr' => 'Object Storage'],
                ]],
                ['fa' => 'هوش مصنوعی', 'en' => 'AI', 'tr' => 'Yapay zeka', 'items' => [
                    ['slug' => 'gpu-platform',      'fa' => 'پلتفرم پردازش گرافیکی', 'en' => 'GPU Platform', 'tr' => 'GPU Platformu'],
                    ['slug' => 'ai-infrastructure', 'fa' => 'زیرساخت هوش مصنوعی', 'en' => 'AI Infrastructure', 'tr' => 'AI Altyapısı'],
                ]],
                ['fa' => 'برنامه‌های آماده', 'en' => 'One-click apps', 'tr' => 'Hazır uygulamalar', 'items' => [
                    ['slug' => 'n8n', 'fa' => 'پلتفرم اتوماسیون n8n', 'en' => 'n8n Automation', 'tr' => 'n8n Otomasyon'],
                ]],
            ],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | منوی «خدمات»
    |----------------------------------------------------------------------
    */
    'services_menu' => [
        ['icon' => 'rocket',   'slug' => 'site-builder', 'fa' => ['t' => 'سایت‌ساز', 'd' => 'سریع‌ترین راه برای راه‌اندازی کسب‌وکار آنلاین'],      'en' => ['t' => 'Site Builder', 'd' => 'The fastest way to launch your business online'],   'tr' => ['t' => 'Site Oluşturucu', 'd' => 'Online işinizi başlatmanın en hızlı yolu']],
        ['icon' => 'umbrella', 'slug' => 'premium-support', 'fa' => ['t' => 'پشتیبانی چتر آبی', 'd' => 'خدمات پشتیبانی باکیفیت و حرفه‌ای ۲۴ ساعته'],   'en' => ['t' => 'Premium Support', 'd' => 'Professional 24/7 support services'],            'tr' => ['t' => 'Premium Destek', 'd' => '7/24 profesyonel ve kaliteli destek']],
        ['icon' => 'flow',     'slug' => 'devops', 'fa' => ['t' => 'خدمات دواپس', 'd' => 'مدیریت چالش‌های فناوری با رویکردی چابک و هوشمند'],  'en' => ['t' => 'DevOps Services', 'd' => 'Agile, intelligent technology management'],      'tr' => ['t' => 'DevOps Hizmetleri', 'd' => 'Teknoloji süreçlerinin çevik ve akıllı yönetimi']],
        ['icon' => 'shield',   'slug' => 'security', 'fa' => ['t' => 'امنیت', 'd' => 'حفاظت از سرویس‌های شما در برابر تهدیدات سایبری'],          'en' => ['t' => 'Security', 'd' => 'Protect your services against cyber threats'],          'tr' => ['t' => 'Güvenlik', 'd' => 'Hizmetlerinizi siber tehditlere karşı koruyun']],
        ['icon' => 'lifebuoy', 'slug' => 'wordpress-care', 'fa' => ['t' => 'پشتیبانی وردپرس', 'd' => 'حل مشکلات وب‌سایت وردپرسی شما'],                'en' => ['t' => 'WordPress Care', 'd' => 'We fix your WordPress site issues'],              'tr' => ['t' => 'WordPress Bakımı', 'd' => 'WordPress sitenizin sorunlarını çözüyoruz']],
        ['icon' => 'lock',     'slug' => 'ssl', 'fa' => ['t' => 'گواهینامه SSL', 'd' => 'برای کاربران وب‌سایتتان محیطی امن‌تر بسازید'],     'en' => ['t' => 'SSL Certificates', 'd' => 'Build a safer environment for your users'],     'tr' => ['t' => 'SSL Sertifikaları', 'd' => 'Kullanıcılarınız için daha güvenli bir ortam']],
        ['icon' => 'key',      'slug' => 'licenses', 'fa' => ['t' => 'لایسنس‌ها', 'd' => 'خرید لایسنس اورجینال نرم‌افزارها'],                    'en' => ['t' => 'Licenses', 'd' => 'Original software license store'],                      'tr' => ['t' => 'Lisanslar', 'd' => 'Orijinal yazılım lisansları']],
        ['icon' => 'restore',  'cat' => 'hosting', 'slug' => 'backup', 'fa' => ['t' => 'هاست بکاپ', 'd' => 'در صورت بروز مشکل، اطلاعات شما حفظ خواهد شد'],        'en' => ['t' => 'Backup Hosting', 'd' => 'Your data stays safe, whatever happens'],         'tr' => ['t' => 'Yedek Hosting', 'd' => 'Ne olursa olsun verileriniz güvende']],
        ['icon' => 'box',      'slug' => 'more', 'fa' => ['t' => 'خدمات بیشتر', 'd' => 'سایر خدمات تکمیلی سرورنت'],                          'en' => ['t' => 'More Services', 'd' => 'Other complementary services'],                    'tr' => ['t' => 'Diğer Hizmetler', 'd' => 'ServerNet\'in tamamlayıcı hizmetleri']],
    ],

    /*
    |----------------------------------------------------------------------
    | منوی «ابزارهای رایگان»
    |----------------------------------------------------------------------
    */
    'tools_menu' => [
        ['icon' => 'gauge',      'slug' => 'seo',         'fa' => ['t' => 'بررسی سئو و سلامت سایت', 'd' => 'تحلیل سئو، سرعت، امنیت و موبایل'], 'en' => ['t' => 'SEO & Site Checker', 'd' => 'SEO, speed, security & mobile analysis'], 'tr' => ['t' => 'SEO ve Site Denetleyici', 'd' => 'SEO, hız ve güvenlik analizi']],
        ['icon' => 'search',     'slug' => 'whois',       'fa' => ['t' => 'استعلام Whois', 'd' => 'اطلاعات کامل ثبت هر دامنه'],                       'en' => ['t' => 'Whois Lookup', 'd' => 'Full registration data for any domain'],          'tr' => ['t' => 'Whois Sorgu', 'd' => 'Her alan adının tam kayıt verisi']],
        ['icon' => 'globe',      'slug' => 'ip',          'fa' => ['t' => 'بررسی IP', 'd' => 'موقعیت، ISP و اطلاعات کامل هر IP'],                     'en' => ['t' => 'IP Checker', 'd' => 'Location, ISP and full details of any IP'],          'tr' => ['t' => 'IP Sorgu', 'd' => 'Her IP\'nin konumu ve detayları']],
        ['icon' => 'video',      'slug' => 'meet',        'fa' => ['t' => 'جلسات آنلاین', 'd' => 'ویدیوکنفرانس امن بدون نصب نرم‌افزار'],              'en' => ['t' => 'Online Meetings', 'd' => 'Secure video meetings, zero installation'],      'tr' => ['t' => 'Online Toplantı', 'd' => 'Kurulumsuz güvenli görüntülü toplantı']],
        ['icon' => 'smartphone', 'slug' => 'app-builder', 'fa' => ['t' => 'اپلیکیشن‌ساز', 'd' => 'ساخت اپ اندروید و iOS بدون کدنویسی'],               'en' => ['t' => 'App Builder', 'd' => 'Build Android & iOS apps without code'],             'tr' => ['t' => 'Uygulama Oluşturucu', 'd' => 'Kodsuz Android ve iOS uygulaması']],
    ],

    /*
    |----------------------------------------------------------------------
    | منوی «پایگاه دانش»
    |----------------------------------------------------------------------
    */
    'knowledge_menu' => [
        ['icon' => 'book',   'anchor' => 'blog', 'fa' => ['t' => 'بلاگ', 'd' => 'داستان‌های دنیای تکنولوژی و دیجیتال'],                 'en' => ['t' => 'Blog', 'd' => 'Stories from the tech & digital world'],       'tr' => ['t' => 'Blog', 'd' => 'Teknoloji ve dijital dünyadan hikayeler']],
        ['icon' => 'mic',    'anchor' => 'webinars', 'fa' => ['t' => 'وبینارها', 'd' => 'آخرین وبینارهای سرورنت'],                           'en' => ['t' => 'Webinars', 'd' => 'Latest ServerNet webinars'],               'tr' => ['t' => 'Webinarlar', 'd' => 'ServerNet\'in son webinarları']],
        ['icon' => 'layout', 'anchor' => 'docs', 'fa' => ['t' => 'مستندات', 'd' => 'راهنمای استفاده از محصولات سرورنت'],                'en' => ['t' => 'Documentation', 'd' => 'Product guides & how-tos'],           'tr' => ['t' => 'Dokümantasyon', 'd' => 'Ürün kullanım kılavuzları']],
        ['icon' => 'cap',    'anchor' => 'learning', 'fa' => ['t' => 'سایر موارد آموزشی', 'd' => 'رویداد، دوره آموزشی، پادکست و…'],          'en' => ['t' => 'Learning & Events', 'd' => 'Events, courses, podcasts & more'], 'tr' => ['t' => 'Eğitim & Etkinlikler', 'd' => 'Etkinlik, kurs, podcast ve daha fazlası']],
        ['icon' => 'code',   'anchor' => 'developers', 'fa' => ['t' => 'ابزار توسعه‌دهندگان', 'd' => 'مستندات فنی برای آشنایی با API محصولات'], 'en' => ['t' => 'Developer API', 'd' => 'Technical docs for our product APIs'], 'tr' => ['t' => 'Geliştirici API', 'd' => 'Ürün API\'leri için teknik dokümanlar']],
    ],
];
