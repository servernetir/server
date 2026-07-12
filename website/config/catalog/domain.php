<?php

/*
|--------------------------------------------------------------------------
| کاتالوگ دامنه — ۶ صفحه (fa / en / tr)
|--------------------------------------------------------------------------
| billing=yearly یعنی سوییچ ماهانه/سالانه ندارد و قیمت‌ها سالانه‌اند.
| plan['url'] لینک خرید WHMCS را جایگزین buy_url(pid) می‌کند.
*/

$regUrl = 'cart.php?a=add&domain=register';
$dns = ['fa' => 'مدیریت DNS رایگان', 'en' => 'Free DNS management', 'tr' => 'Ücretsiz DNS yönetimi'];
$lock = ['fa' => 'قفل انتقال + تمدید خودکار', 'en' => 'Transfer lock + auto-renew', 'tr' => 'Transfer kilidi + otomatik yenileme'];

$mkTld = fn (string $name, int $irt, float $eur, array $specs, bool $pop = false) =>
    array_filter(['name' => $name, 'pid' => 0, 'url' => 'cart.php?a=add&domain=register', 'irt' => $irt, 'eur' => $eur, 'specs' => $specs, 'popular' => $pop]);

$domainFeatures = [
    ['icon' => 'layout',
        'fa' => ['t' => 'پنل DNS پیشرفته رایگان', 'd' => 'رکوردهای A، CNAME، MX، TXT و SRV با اعمال آنی — به‌همراه DNSSEC برای امنیت بیشتر.'],
        'en' => ['t' => 'Free Advanced DNS Panel', 'd' => 'A, CNAME, MX, TXT and SRV records with instant propagation — plus DNSSEC for extra security.'],
        'tr' => ['t' => 'Ücretsiz Gelişmiş DNS Paneli', 'd' => 'Anında yayılan A, CNAME, MX, TXT ve SRV kayıtları — ek güvenlik için DNSSEC.']],
    ['icon' => 'lock',
        'fa' => ['t' => 'حریم خصوصی WHOIS', 'd' => 'اطلاعات تماس شما در WHOIS عمومی مخفی می‌ماند — خداحافظ اسپم و تماس‌های مزاحم.'],
        'en' => ['t' => 'WHOIS Privacy', 'd' => 'Your contact details stay hidden from public WHOIS — goodbye spam and cold calls.'],
        'tr' => ['t' => 'WHOIS Gizliliği', 'd' => 'İletişim bilgileriniz genel WHOIS\'te gizli kalır.']],
    ['icon' => 'shield',
        'fa' => ['t' => 'قفل امنیتی انتقال', 'd' => 'دامنه بدون تایید دومرحله‌ای شما هرگز منتقل نمی‌شود — حتی اگر حساب ایمیلتان لو برود.'],
        'en' => ['t' => 'Transfer Security Lock', 'd' => 'Your domain never transfers without your two-step confirmation — even if your email is compromised.'],
        'tr' => ['t' => 'Transfer Güvenlik Kilidi', 'd' => 'İki adımlı onayınız olmadan alan adınız asla transfer edilmez.']],
];

$renewFaq = [
    'fa' => ['q' => 'هزینه تمدید سال‌های بعد چقدر است؟', 'a' => 'قیمت تمدید همان قیمت ثبت است و قبل از سررسید با ایمیل و پیامک خبر می‌دهیم. تمدید خودکار را هم می‌توانید فعال کنید تا دامنه هرگز منقضی نشود.'],
    'en' => ['q' => 'What does renewal cost in later years?', 'a' => 'Renewal costs the same as registration, and we notify you by email and SMS before expiry. Enable auto-renew and your domain will never lapse.'],
    'tr' => ['q' => 'Sonraki yıllarda yenileme ne kadar?', 'a' => 'Yenileme, kayıtla aynı fiyattadır; süresi dolmadan e-posta ve SMS ile bildiririz.'],
];

$transferFaq = [
    'fa' => ['q' => 'انتقال دامنه از شرکت دیگر چطور است؟', 'a' => 'کد انتقال (EPP) را از شرکت فعلی بگیرید و در سفارش وارد کنید؛ انتقال معمولاً ۵ تا ۷ روز طول می‌کشد و یک سال تمدید رایگان به دامنه اضافه می‌شود.'],
    'en' => ['q' => 'How do I transfer a domain from another registrar?', 'a' => 'Get the EPP code from your current registrar and enter it at checkout; transfers usually take 5–7 days and add a free year of renewal.'],
    'tr' => ['q' => 'Başka kayıt şirketinden nasıl transfer ederim?', 'a' => 'Mevcut şirketten EPP kodunu alın ve siparişte girin; transfer 5–7 gün sürer ve bir yıl ücretsiz yenileme ekler.'],
];

return [

    'popular-tlds' => [
        'icon' => 'db', 'group' => 'register', 'billing' => 'yearly',
        'fa' => ['t' => 'دامنه عمومی', 'tag' => 'com. · net. · org. و ۱۰۰+ پسوند',
            'hero_t' => 'نام کسب‌وکارتان را', 'hero_g' => 'همین امروز ثبت کنید.',
            'hero_d' => 'ثبت آنی بیش از ۱۰۰ پسوند بین‌المللی با پنل DNS حرفه‌ای، WHOIS Privacy و قیمت شفاف — دامنه‌ای که حق شماست، قبل از بقیه بردارید.'],
        'en' => ['t' => 'Popular TLDs', 'tag' => '.com · .net · .org & 100+ more',
            'hero_t' => 'Register your business name', 'hero_g' => 'today, before someone else.',
            'hero_d' => 'Instant registration of 100+ international TLDs with a pro DNS panel, WHOIS privacy and transparent pricing.'],
        'tr' => ['t' => 'Popüler Uzantılar', 'tag' => '.com · .net · .org ve 100+ uzantı',
            'hero_t' => 'İşletme adınızı', 'hero_g' => 'bugün kaydedin.',
            'hero_d' => 'Profesyonel DNS paneli, WHOIS gizliliği ve şeffaf fiyatlarla 100\'den fazla uluslararası uzantının anında kaydı.'],
        'chips' => ['Instant Registration', 'DNSSEC', 'WHOIS Privacy', 'Free DNS', 'EPP Transfer'],
        'signature' => ['type' => 'domainsearch',
            'fa' => ['t' => 'همین حالا امتحان کنید', 'd' => 'استعلام زنده از رجیسترار — نام دلخواهتان آزاد است؟'],
            'en' => ['t' => 'Try it right now', 'd' => 'Live registrar lookup — is your name still available?'],
            'tr' => ['t' => 'Hemen deneyin', 'd' => 'Canlı sorgu — istediğiniz ad hâlâ boşta mı?']],
        'plans' => [
            $mkTld('.com', 1290000, 12.90, [['fa' => 'محبوب‌ترین پسوند دنیا', 'en' => 'The world\'s favorite TLD', 'tr' => 'Dünyanın en popüler uzantısı'], $dns, $lock], true),
            $mkTld('.net', 1590000, 14.90, [['fa' => 'انتخاب دوم حرفه‌ای‌ها', 'en' => 'The pros\' second choice', 'tr' => 'Profesyonellerin ikinci tercihi'], $dns, $lock]),
            $mkTld('.org', 1490000, 13.90, [['fa' => 'اعتماد سازمان‌ها و NGOها', 'en' => 'Trusted by orgs & NGOs', 'tr' => 'Kurumların güvendiği uzantı'], $dns, $lock]),
            $mkTld('.shop', 490000, 4.90, [['fa' => 'مخصوص فروشگاه‌های آنلاین', 'en' => 'Made for online stores', 'tr' => 'Online mağazalar için'], $dns, $lock]),
        ],
        'features' => $domainFeatures,
        'faqs' => [$renewFaq, $transferFaq, 'activation'],
    ],

    'ir' => [
        'icon' => 'pin', 'group' => 'register', 'billing' => 'yearly',
        'fa' => ['t' => 'دامنه IR', 'tag' => 'نماینده ثبت ایرنیک',
            'hero_t' => 'هویت ایرانی کسب‌وکار شما،', 'hero_g' => 'با دامنه ir.',
            'hero_d' => 'ثبت مستقیم و آنی دامنه‌های ir. زیر نظر ایرنیک — ارزان‌ترین راه حضور رسمی آنلاین در ایران، با پشتیبانی کامل فرایند شناسه ایرنیک.'],
        'en' => ['t' => '.ir Domains', 'tag' => 'IRNIC accredited',
            'hero_t' => 'Your Iranian identity', 'hero_g' => 'with a .ir domain.',
            'hero_d' => 'Direct, instant .ir registration under IRNIC — the most affordable official web presence in Iran, with full IRNIC-handle support.'],
        'tr' => ['t' => '.ir Alan Adları', 'tag' => 'IRNIC akredite',
            'hero_t' => '.ir alan adıyla', 'hero_g' => 'İran kimliğiniz.',
            'hero_d' => 'IRNIC altında doğrudan ve anında .ir kaydı — İran\'da resmi web varlığının en uygun yolu.'],
        'chips' => ['IRNIC Accredited', 'Instant Setup', 'Free DNS', '1–5 Year Terms', 'Local Support'],
        'signature' => ['type' => 'domainsearch',
            'fa' => ['t' => 'دامنه ir دلخواهتان آزاد است؟', 'd' => 'استعلام زنده — همین الان چک کنید'],
            'en' => ['t' => 'Is your .ir available?', 'd' => 'Live lookup — check right now'],
            'tr' => ['t' => '.ir adresiniz boşta mı?', 'd' => 'Canlı sorgu — hemen kontrol edin']],
        'plans' => [
            $mkTld('.ir', 165000, 3.90, [['fa' => 'ثبت یک‌ساله', 'en' => '1-year registration', 'tr' => '1 yıllık kayıt'], $dns, $lock], true),
            $mkTld('.ir ×5', 490000, 11.90, [['fa' => 'ثبت پنج‌ساله — صرفه ۴۰٪', 'en' => '5-year term — save 40%', 'tr' => '5 yıllık — %40 tasarruf'], $dns, $lock]),
            $mkTld('.co.ir', 120000, 2.90, [['fa' => 'مخصوص شرکت‌های ثبت‌شده', 'en' => 'For registered companies', 'tr' => 'Tescilli şirketler için'], $dns, $lock]),
        ],
        'features' => $domainFeatures,
        'faqs' => [
            ['fa' => ['q' => 'شناسه ایرنیک ندارم؛ مشکلی است؟', 'a' => 'خیر — هنگام سفارش، ساخت شناسه ایرنیک را مرحله‌به‌مرحله راهنمایی می‌کنیم و در صورت تمایل تیم ما کل فرایند را برایتان انجام می‌دهد.'],
             'en' => ['q' => 'I don\'t have an IRNIC handle — is that a problem?', 'a' => 'No — during checkout we guide you through creating one step by step, or our team can handle the whole process for you.'],
             'tr' => ['q' => 'IRNIC kimliğim yok — sorun olur mu?', 'a' => 'Hayır — sipariş sırasında adım adım yönlendiririz veya ekibimiz tüm süreci sizin için halleder.']],
            $renewFaq, $transferFaq,
        ],
    ],

    'persian' => [
        'icon' => 'globe', 'group' => 'register', 'billing' => 'yearly',
        'fa' => ['t' => 'دامنه فارسی', 'tag' => 'IDN · دامنه به خط فارسی',
            'hero_t' => 'آدرس سایت شما،', 'hero_g' => 'به زبان مادری.',
            'hero_d' => 'دامنه‌هایی که به خط فارسی نوشته می‌شوند — مثل «فروشگاه‌من.ایران». برای برندهایی که می‌خواهند مشتری فارسی‌زبان آدرس را همان‌طور که می‌خواند، تایپ کند.'],
        'en' => ['t' => 'Persian IDN Domains', 'tag' => 'IDN · Domains in Persian script',
            'hero_t' => 'Your web address,', 'hero_g' => 'in your mother tongue.',
            'hero_d' => 'Domains written in Persian script — like «فروشگاه‌من.ایران». For brands whose customers should type the address exactly as they read it.'],
        'tr' => ['t' => 'Farsça (IDN) Alan Adları', 'tag' => 'IDN · Fars alfabesiyle',
            'hero_t' => 'Web adresiniz,', 'hero_g' => 'ana dilinizde.',
            'hero_d' => 'Fars alfabesiyle yazılan alan adları — müşterinin okuduğu gibi yazacağı adresler.'],
        'chips' => ['Persian Script', '.ایران TLD', 'Punycode Auto', 'Free DNS', 'IRNIC Accredited'],
        'signature' => ['type' => 'domainsearch',
            'fa' => ['t' => 'نام فارسی دلخواهتان آزاد است؟', 'd' => 'فارسی تایپ کنید — استعلام زنده'],
            'en' => ['t' => 'Is your Persian name available?', 'd' => 'Type in Persian — live lookup'],
            'tr' => ['t' => 'Farsça adınız boşta mı?', 'd' => 'Farsça yazın — canlı sorgu']],
        'plans' => [
            $mkTld('.ایران', 190000, 4.50, [['fa' => 'پسوند ملی به خط فارسی', 'en' => 'National TLD in Persian script', 'tr' => 'Fars alfabesiyle ulusal uzantı'], $dns, $lock], true),
            $mkTld('IDN .com', 1290000, 12.90, [['fa' => 'دامنه فارسی با پسوند com.', 'en' => 'Persian name on .com', 'tr' => '.com üzerinde Farsça ad'], $dns, $lock]),
        ],
        'features' => $domainFeatures,
        'faqs' => [
            ['fa' => ['q' => 'دامنه فارسی در مرورگرها درست کار می‌کند؟', 'a' => 'بله — همه مرورگرهای مدرن IDN را پشتیبانی می‌کنند و آدرس فارسی را نمایش می‌دهند. در پس‌زمینه دامنه به Punycode تبدیل می‌شود و ما این تبدیل را خودکار انجام می‌دهیم.'],
             'en' => ['q' => 'Do Persian domains work properly in browsers?', 'a' => 'Yes — all modern browsers support IDN and display the Persian address. Behind the scenes it converts to Punycode, which we handle automatically.'],
             'tr' => ['q' => 'Farsça alan adları tarayıcılarda düzgün çalışır mı?', 'a' => 'Evet — tüm modern tarayıcılar IDN destekler. Arka planda Punycode\'a dönüşür; bunu otomatik hallederiz.']],
            $renewFaq, 'activation',
        ],
    ],

    'premium' => [
        'icon' => 'sparkles', 'group' => 'register', 'billing' => 'yearly',
        'fa' => ['t' => 'دامنه‌های خاص', 'tag' => 'ai. · io. · dev. · پرمیوم',
            'hero_t' => 'پسوندهایی که', 'hero_g' => 'برند شما را متمایز می‌کنند.',
            'hero_d' => 'از ai. برای استارتاپ هوش مصنوعی تا dev. برای تیم توسعه — پسوندهای مدرن و دامنه‌های پرمیوم که کوتاه‌ترند، ماندگارترند و اعتبار می‌آورند.'],
        'en' => ['t' => 'Premium TLDs', 'tag' => '.ai · .io · .dev · premium',
            'hero_t' => 'Extensions that make', 'hero_g' => 'your brand unmistakable.',
            'hero_d' => 'From .ai for your AI startup to .dev for your team — modern TLDs and premium names that are shorter, more memorable and instantly credible.'],
        'tr' => ['t' => 'Premium Uzantılar', 'tag' => '.ai · .io · .dev · premium',
            'hero_t' => 'Markanızı benzersiz kılan', 'hero_g' => 'uzantılar.',
            'hero_d' => 'AI girişiminiz için .ai\'den ekibiniz için .dev\'e — daha kısa, daha akılda kalıcı modern uzantılar.'],
        'chips' => ['.ai / .io / .dev', 'Premium Names', 'Brand Protection', 'Free DNS', 'WHOIS Privacy'],
        'signature' => ['type' => 'domainsearch',
            'fa' => ['t' => 'برند خاصتان را چک کنید', 'd' => 'استعلام زنده پسوندهای خاص و پرمیوم'],
            'en' => ['t' => 'Check your special brand', 'd' => 'Live lookup for premium TLDs'],
            'tr' => ['t' => 'Özel markanızı kontrol edin', 'd' => 'Premium uzantılar için canlı sorgu']],
        'plans' => [
            $mkTld('.ai', 9800000, 95.00, [['fa' => 'هویت استارتاپ‌های AI', 'en' => 'The identity of AI startups', 'tr' => 'AI girişimlerinin kimliği'], $dns, $lock], true),
            $mkTld('.io', 4900000, 49.00, [['fa' => 'محبوب دنیای تک و SaaS', 'en' => 'Loved by tech & SaaS', 'tr' => 'Teknoloji ve SaaS dünyasının gözdesi'], $dns, $lock]),
            $mkTld('.dev', 1690000, 16.90, [['fa' => 'برای تیم‌ها و ابزارهای توسعه', 'en' => 'For dev teams & tools', 'tr' => 'Geliştirici ekipleri için'], $dns, $lock]),
            $mkTld('.cloud', 990000, 9.90, [['fa' => 'سرویس‌های ابری و آنلاین', 'en' => 'Cloud & online services', 'tr' => 'Bulut ve online hizmetler'], $dns, $lock]),
        ],
        'features' => $domainFeatures,
        'faqs' => [$renewFaq, $transferFaq,
            ['fa' => ['q' => 'دامنه پرمیوم چیست و چرا گران‌تر است؟', 'a' => 'بعضی نام‌های کوتاه یا پرتقاضا توسط رجیستری «پرمیوم» قیمت‌گذاری شده‌اند. قیمت دقیق را هنگام استعلام زنده می‌بینید — بدون هزینه پنهان.'],
             'en' => ['q' => 'What is a premium domain and why does it cost more?', 'a' => 'Registries price certain short or high-demand names as “premium”. You see the exact price during live lookup — no hidden fees.'],
             'tr' => ['q' => 'Premium alan adı nedir, neden daha pahalı?', 'a' => 'Bazı kısa veya talep gören adlar kayıt kuruluşunca "premium" fiyatlanır. Kesin fiyatı canlı sorguda görürsünüz.']],
        ],
    ],

    'reseller' => [
        'icon' => 'user', 'group' => 'services', 'billing' => 'yearly',
        'unit' => ['fa' => '/ شارژ اعتبار', 'en' => '/ credit top-up', 'tr' => '/ kredi yükleme'],
        'fa' => ['t' => 'نمایندگی دامنه', 'tag' => 'API کامل · قیمت رجیستری',
            'hero_t' => 'دامنه بفروشید،', 'hero_g' => 'با قیمت رجیستری.',
            'hero_d' => 'پنل نمایندگی با API کامل ثبت/تمدید/انتقال و قیمت پلکانی — هرچه بیشتر بفروشید، تمام‌شده ارزان‌تر. مناسب وب‌مسترها و شرکت‌های طراحی.'],
        'en' => ['t' => 'Domain Reseller', 'tag' => 'Full API · Registry pricing',
            'hero_t' => 'Sell domains', 'hero_g' => 'at registry prices.',
            'hero_d' => 'A reseller panel with a full register/renew/transfer API and tiered pricing — the more you sell, the cheaper your cost. Built for webmasters and agencies.'],
        'tr' => ['t' => 'Alan Adı Bayiliği', 'tag' => 'Tam API · Kayıt fiyatları',
            'hero_t' => 'Kayıt fiyatlarına', 'hero_g' => 'alan adı satın.',
            'hero_d' => 'Tam kayıt/yenileme/transfer API\'li bayi paneli ve kademeli fiyatlandırma.'],
        'chips' => ['REST API', 'Tiered Pricing', 'WHMCS Module', '500+ TLDs', 'White-Label'],
        'plans' => [
            array_merge($mkTld('Starter', 5000000, 49.00, [['fa' => 'اعتبار اولیه ۵ میلیون تومانی', 'en' => '€49 starting credit', 'tr' => '€49 başlangıç kredisi'], ['fa' => 'تخفیف پلکانی سطح ۱', 'en' => 'Tier-1 discount', 'tr' => 'Seviye-1 indirim'], ['fa' => 'API + ماژول WHMCS', 'en' => 'API + WHMCS module', 'tr' => 'API + WHMCS modülü']]), ['url' => 'cart.php']),
            array_merge($mkTld('Business', 20000000, 199.00, [['fa' => 'اعتبار اولیه ۲۰ میلیون تومانی', 'en' => '€199 starting credit', 'tr' => '€199 başlangıç kredisi'], ['fa' => 'تخفیف پلکانی سطح ۲ (تا ۱۲٪)', 'en' => 'Tier-2 discount (up to 12%)', 'tr' => 'Seviye-2 indirim (%12\'ye kadar)'], ['fa' => 'پشتیبانی اولویت‌دار نماینده', 'en' => 'Priority reseller support', 'tr' => 'Öncelikli bayi desteği']], true), ['url' => 'cart.php']),
            array_merge($mkTld('Enterprise', 0, 0, [['fa' => 'قیمت رجیستری + قرارداد', 'en' => 'Registry pricing + contract', 'tr' => 'Kayıt fiyatı + sözleşme'], ['fa' => 'مدیر اکانت اختصاصی', 'en' => 'Dedicated account manager', 'tr' => 'Özel hesap yöneticisi'], ['fa' => 'SLA اختصاصی API', 'en' => 'Custom API SLA', 'tr' => 'Özel API SLA']]), ['contact' => true]),
        ],
        'features' => ['whitelabel', 'support', 'instant',
            ['icon' => 'code',
                'fa' => ['t' => 'API کامل و مستند', 'd' => 'ثبت، تمدید، انتقال و مدیریت DNS با REST API — به‌همراه ماژول آماده WHMCS و WordPress.'],
                'en' => ['t' => 'Complete Documented API', 'd' => 'Register, renew, transfer and manage DNS via REST — with ready WHMCS and WordPress modules.'],
                'tr' => ['t' => 'Eksiksiz Belgeli API', 'd' => 'REST ile kayıt, yenileme, transfer ve DNS yönetimi — hazır WHMCS modülüyle.']],
            ['icon' => 'coins',
                'fa' => ['t' => 'قیمت پلکانی شفاف', 'd' => 'با افزایش حجم، خودکار به سطح تخفیف بالاتر می‌روید — جدول قیمت هر ۱۰۰+ پسوند در پنل شفاف است.'],
                'en' => ['t' => 'Transparent Tiered Pricing', 'd' => 'Higher volume moves you to better tiers automatically — full price tables for 100+ TLDs in your panel.'],
                'tr' => ['t' => 'Şeffaf Kademeli Fiyat', 'd' => 'Hacim arttıkça otomatik olarak daha iyi seviyeye geçersiniz.']],
        ],
        'faqs' => [
            ['fa' => ['q' => 'اعتبار شارژشده منقضی می‌شود؟', 'a' => 'خیر — اعتبار پنل نمایندگی هیچ تاریخ انقضایی ندارد و هر زمان می‌توانید شارژ بیشتری اضافه کنید.'],
             'en' => ['q' => 'Does the credit expire?', 'a' => 'No — reseller credit never expires, and you can top up anytime.'],
             'tr' => ['q' => 'Kredi süresi dolar mı?', 'a' => 'Hayır — bayi kredisinin süresi asla dolmaz.']],
            ['fa' => ['q' => 'مشتریانم نام سرورنت را می‌بینند؟', 'a' => 'خیر — پنل، ایمیل‌ها و WHOIS با برند شما تنظیم می‌شود؛ سرورنت فقط در پس‌زمینه است.'],
             'en' => ['q' => 'Will my clients see ServerNet\'s name?', 'a' => 'No — the panel, emails and WHOIS carry your brand; ServerNet stays behind the scenes.'],
             'tr' => ['q' => 'Müşterilerim ServerNet adını görür mü?', 'a' => 'Hayır — panel, e-postalar ve WHOIS sizin markanızı taşır.']],
            'activation',
        ],
    ],

    'backorder' => [
        'icon' => 'clock', 'group' => 'services', 'billing' => 'yearly',
        'fa' => ['t' => 'رزرو دامنه', 'tag' => 'Backorder · شکار دامنه‌های رو به انقضا',
            'hero_t' => 'دامنه‌ای که می‌خواهید گرفته شده؟', 'hero_g' => 'ما برایتان شکارش می‌کنیم.',
            'hero_d' => 'دامنه‌های منقضی‌شده را در همان ثانیه‌ی آزاد شدن ثبت می‌کنیم — با مانیتورینگ دائمی، اطلاع‌رسانی لحظه‌ای و بالاترین نرخ موفقیت.'],
        'en' => ['t' => 'Domain Backorder', 'tag' => 'Backorder · Catch expiring domains',
            'hero_t' => 'Your dream domain is taken?', 'hero_g' => 'We\'ll hunt it for you.',
            'hero_d' => 'We register expiring domains the very second they drop — constant monitoring, instant alerts and the highest catch rate.'],
        'tr' => ['t' => 'Alan Adı Rezervasyonu', 'tag' => 'Backorder · Süresi dolanları yakalayın',
            'hero_t' => 'İstediğiniz alan adı alınmış mı?', 'hero_g' => 'Sizin için avlarız.',
            'hero_d' => 'Süresi dolan alan adlarını düştüğü saniyede kaydederiz — sürekli izleme ve anlık bildirim.'],
        'chips' => ['Drop-Catching', '24/7 Monitoring', 'Instant Alerts', 'No Catch, No Fee', 'WHOIS History'],
        'plans' => [
            $mkTld('Single', 490000, 4.90, [['fa' => 'رزرو ۱ دامنه', 'en' => 'Backorder 1 domain', 'tr' => '1 alan adı rezervi'], ['fa' => 'مانیتورینگ تا آزاد شدن', 'en' => 'Monitored until it drops', 'tr' => 'Düşene kadar izleme'], ['fa' => 'عدم موفقیت = بازگشت وجه', 'en' => 'No catch = full refund', 'tr' => 'Yakalanamazsa iade']], true),
            $mkTld('Pack ×5', 1990000, 19.90, [['fa' => 'رزرو ۵ دامنه', 'en' => 'Backorder 5 domains', 'tr' => '5 alan adı rezervi'], ['fa' => 'اولویت بالاتر در صف شکار', 'en' => 'Higher catch priority', 'tr' => 'Daha yüksek yakalama önceliği'], ['fa' => 'گزارش WHOIS History', 'en' => 'WHOIS history reports', 'tr' => 'WHOIS geçmişi raporları']]),
            $mkTld('Monitor', 990000, 9.90, [['fa' => 'مانیتورینگ ۵۰ دامنه', 'en' => 'Monitor 50 domains', 'tr' => '50 alan adı izleme'], ['fa' => 'هشدار تغییر وضعیت لحظه‌ای', 'en' => 'Instant status-change alerts', 'tr' => 'Anlık durum bildirimi'], ['fa' => 'بدون تعهد رزرو', 'en' => 'No backorder commitment', 'tr' => 'Rezerv zorunluluğu yok']]),
        ],
        'features' => ['support', 'instant',
            ['icon' => 'search',
                'fa' => ['t' => 'مانیتورینگ شبانه‌روزی', 'd' => 'وضعیت دامنه هدف هر دقیقه بررسی می‌شود — از Pending Delete تا لحظه Drop، همه‌چیز را لحظه‌ای می‌بینید.'],
                'en' => ['t' => 'Round-the-Clock Monitoring', 'd' => 'The target domain is checked every minute — from Pending Delete to the drop moment, you see everything live.'],
                'tr' => ['t' => 'Kesintisiz İzleme', 'd' => 'Hedef alan adı her dakika kontrol edilir.']],
            ['icon' => 'coins',
                'fa' => ['t' => 'نگرفتیم؟ پولتان برمی‌گردد', 'd' => 'اگر در لحظه آزاد شدن موفق به ثبت نشویم، کل مبلغ رزرو بدون قید و شرط برگشت داده می‌شود.'],
                'en' => ['t' => 'No Catch, Full Refund', 'd' => 'If we fail to register at drop time, your entire backorder fee is refunded unconditionally.'],
                'tr' => ['t' => 'Yakalayamazsak Tam İade', 'd' => 'Düşme anında kaydedemezsek ücretin tamamı koşulsuz iade edilir.']],
        ],
        'faqs' => [
            ['fa' => ['q' => 'احتمال موفقیت چقدر است؟', 'a' => 'بستگی به رقابت دارد — برای دامنه‌های معمولی بالای ۸۰٪ و برای دامنه‌های پرتقاضا که چند سرویس شکار رقیب دارند، شانس را صادقانه قبل از رزرو اعلام می‌کنیم.'],
             'en' => ['q' => 'What are the odds of success?', 'a' => 'It depends on competition — above 80% for ordinary domains; for hot names contested by rival services, we state your realistic odds honestly before you commit.'],
             'tr' => ['q' => 'Başarı olasılığı nedir?', 'a' => 'Rekabete bağlı — sıradan adlarda %80 üzeri; rekabetli adlarda gerçekçi şansı önceden dürüstçe söyleriz.']],
            ['fa' => ['q' => 'اگر دامنه هرگز آزاد نشود چه؟', 'a' => 'اگر مالک تمدید کند، رزرو شما فعال می‌ماند و می‌توانید آن را به دامنه دیگری منتقل کنید یا وجه را پس بگیرید.'],
             'en' => ['q' => 'What if the domain never drops?', 'a' => 'If the owner renews, your backorder stays active — move it to another domain or take a refund.'],
             'tr' => ['q' => 'Alan adı hiç düşmezse?', 'a' => 'Sahibi yenilerse rezerviniz aktif kalır — başka alana taşıyın veya iade alın.']],
            'activation',
        ],
    ],
];
