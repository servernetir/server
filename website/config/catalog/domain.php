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
            /* ⚠️ `tld` صریح لازم است: نامِ بازاریابی «IDN .com» است و استخراجِ
               خودکار از آن «idn» می‌ساخت — پسوندی که وجود ندارد و رجیسترار
               برایش `code 199` می‌داد. هر پلنی که نامش با پسوندش نمی‌خوانَد،
               باید همین کلید را داشته باشد. */
            array_merge($mkTld('IDN .com', 1290000, 12.90, [['fa' => 'دامنه فارسی با پسوند com.', 'en' => 'Persian name on .com', 'tr' => '.com üzerinde Farsça ad'], $dns, $lock]), ['tld' => 'com']),
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

    /*
    |--------------------------------------------------------------------------
    | نمایندگی دامنه — صفحهٔ فروشِ برنامه‌ای که واقعاً ساخته شده
    |--------------------------------------------------------------------------
    |
    | 🔴 این ورودی شهریور ۱۴۰۵ **بازنویسی شد** چون نسخهٔ قبلی‌اش چیزهایی وعده
    | می‌داد که وجود ندارند، و هر کدام یک شکایتِ قابلِ پیش‌بینی می‌ساخت:
    |
    |   • «مدیریت DNS با REST API»    → API رکوردِ DNS ندارد و عمداً هم ندارد
    |   • «ماژول WordPress»           → فقط ماژولِ WHMCS ساخته شده
    |   • «WHOIS با برند شما»         → مالکِ ثبت‌شده حسابِ نمایندگی است، نه
    |                                    برندِ او روی WHOISِ مشتریِ نهایی
    |   • «قیمت رجیستری»              → قیمتِ خرده‌فروشی منهای تخفیفِ سطح است
    |   • «تخفیف تا ۱۲٪»              → پله‌های واقعی ۵ / ۱۰ / ۱۵ درصدند
    |   • دکمه‌های خرید → `cart.php`  → WHMCSِ بیرونی؛ این محصول آن‌جا نیست
    |
    | صفحهٔ فروشی که چیزی را وعده دهد که محصول ندارد، سخت‌ترین نوع بدهی است:
    | هزینه‌اش را پشتیبانی و بازگشتِ وجه می‌دهد، ماه‌ها بعد، و کسی ردش را به
    | این فایل نمی‌زند.
    |
    | ⚠️ نردبانِ سطح‌ها این‌جا **تکرار نشده**. `partials/sig-tiers` مستقیماً از
    | `config/domain_reseller.levels` می‌خوانَد، پس صفحهٔ بازاریابی هرگز نمی‌تواند
    | با چیزی که کد واقعاً حساب می‌کند واگرا شود.
    */
    'reseller' => [
        'icon' => 'user', 'group' => 'services', 'billing' => 'yearly',
        'unit' => ['fa' => '/ شارژ اعتبار', 'en' => '/ credit top-up', 'tr' => '/ kredi yükleme'],
        'fa' => ['t' => 'نمایندگی دامنه', 'tag' => 'API + افزونهٔ WHMCS · تخفیف پلکانی',
            'seo_t' => 'نمایندگی دامنه — فروش دامنه با API و افزونهٔ WHMCS',
            'hero_t' => 'دامنه بفروشید،', 'hero_g' => 'از پنل خودتان.',
            'hero_d' => 'پنل نمایندگی دامنه با API کامل ثبت و تمدید، افزونهٔ آمادهٔ WHMCS و تخفیف پلکانی بر اساس فروش. دامنه را از سامانهٔ خودتان و با برند خودتان بفروشید — تحویل خودکار، بدون تماس با پشتیبانی.'],
        'en' => ['t' => 'Domain Reseller', 'tag' => 'API + WHMCS module · Volume tiers',
            'seo_t' => 'Domain Reseller Programme — API & WHMCS Module',
            'hero_t' => 'Sell domains', 'hero_g' => 'from your own panel.',
            'hero_d' => 'A domain reseller programme with a full register/renew API, a ready WHMCS registrar module and volume-based discount tiers. Sell under your own brand with automatic delivery.'],
        'tr' => ['t' => 'Alan Adı Bayiliği', 'tag' => 'API + WHMCS modülü · Hacim indirimi',
            'seo_t' => 'Alan Adı Bayilik Programı — API ve WHMCS Modülü',
            'hero_t' => 'Alan adlarını', 'hero_g' => 'kendi panelinizden satın.',
            'hero_d' => 'Tam kayıt/yenileme API\'si, hazır WHMCS modülü ve satış hacmine dayalı kademeli indirim.'],
        'chips' => ['REST API', 'WHMCS Module', 'WordPress + WooCommerce', 'تخفیف پلکانی', 'تحویل خودکار'],

        /*
        | «پلن» این‌جا یعنی **شارژِ اعتبار**، نه اشتراکِ ماهانه — و همان چیزی
        | است که واقعاً پرداخت می‌شود. عضویت در برنامه رایگان است؛ چیزی که
        | می‌خرید اعتبار است و چیزی که می‌سازید سطح.
        |
        | ⚠️ `route` (نه `url`): مقصد پنلِ خودمان است. `url` از `whmcs_url()`
        | رد می‌شود و به WHMCSِ بیرونی می‌رود — یعنی بن‌بست.
        */
        'plans' => [
            array_merge($mkTld('شروع', 5000000, 49.00, [
                ['fa' => 'شارژ اولیهٔ ۵ میلیون تومان', 'en' => '€49 starting credit', 'tr' => '€49 başlangıç kredisi'],
                ['fa' => 'API + افزونهٔ WHMCS', 'en' => 'API + WHMCS module', 'tr' => 'API + WHMCS modülü'],
                ['fa' => 'تحویل خودکار دامنه', 'en' => 'Automatic delivery', 'tr' => 'Otomatik teslim'],
            ]), ['route' => ['account.reseller'], 'url' => null]),
            array_merge($mkTld('حرفه‌ای', 20000000, 199.00, [
                ['fa' => 'شارژ اولیهٔ ۲۰ میلیون تومان', 'en' => '€199 starting credit', 'tr' => '€199 başlangıç kredisi'],
                ['fa' => 'رسیدن سریع‌تر به سطح برنز', 'en' => 'Reach Bronze sooner', 'tr' => 'Bronz seviyeye daha hızlı'],
                ['fa' => 'پشتیبانی اولویت‌دار نماینده', 'en' => 'Priority reseller support', 'tr' => 'Öncelikli bayi desteği'],
            ], true), ['route' => ['account.reseller'], 'url' => null]),
            array_merge($mkTld('سازمانی', 0, 0, [
                ['fa' => 'تخفیف توافقی روی سطح', 'en' => 'Negotiated discount on top of tier', 'tr' => 'Seviye üstü anlaşmalı indirim'],
                ['fa' => 'قرارداد و مدیر اکانت', 'en' => 'Contract + account manager', 'tr' => 'Sözleşme + hesap yöneticisi'],
                ['fa' => 'سقف اعتبار اختصاصی', 'en' => 'Custom credit limits', 'tr' => 'Özel kredi limitleri'],
            ]), ['contact' => true]),
        ],

        /*
        | چهار قدم تا اولین فروش.
        |
        | ⚠️ قدم‌ها با **واقعیتِ کد** یکی‌اند و ترتیبشان هم دلخواه نیست: توکن
        | پیش از افزونه می‌آید چون افزونه بی‌توکن اصلاً تست اتصالش رد نمی‌شود،
        | و شارژ پیش از فروش می‌آید چون ثبت از اعتبار کسر می‌شود و حسابِ خالی
        | یعنی اولین سفارشِ مشتریِ نماینده شکست می‌خورد.
        */
        'howto' => [
            'fa' => ['badge' => 'شروع کنید', 't' => 'چهار قدم تا اولین فروش',
                'd' => 'همه‌چیز خودسرویس است؛ برای شروع لازم نیست منتظر کسی بمانید.'],
            'en' => ['badge' => 'Get started', 't' => 'Four steps to your first sale',
                'd' => 'Everything is self-service — you do not have to wait for anyone.'],
            'tr' => ['badge' => 'Başlayın', 't' => 'İlk satışınıza dört adım',
                'd' => 'Her şey self servis.'],
            'steps' => [
                [
                    'fa' => ['t' => 'حساب نمایندگی را فعال کنید', 'd' => 'اگر هنوز حساب کاربری ندارید بسازید، و از پشتیبانی بخواهید حسابتان را به‌عنوان نمایندهٔ دامنه فعال کند. نمایندگی یک قرارداد است، پس این قدم دستی و آگاهانه انجام می‌شود.'],
                    'en' => ['t' => 'Activate your reseller account', 'd' => 'Create an account if you do not have one, then ask support to enable domain reselling. This is a contract, so the step is deliberate and manual.'],
                    'tr' => ['t' => 'Bayi hesabınızı etkinleştirin', 'd' => 'Hesap oluşturun ve destekten bayiliği etkinleştirmesini isteyin.'],
                    'route' => ['account.reseller'],
                    'cta' => ['fa' => 'پنل نمایندگی', 'en' => 'Reseller panel', 'tr' => 'Bayi paneli'],
                ],
                [
                    'fa' => ['t' => 'توکن API بسازید', 'd' => 'در بخش امنیت پنل، توکنی با دسترسی ثبت و مدیریت دامنه بسازید و حتماً IP سرور خودتان را در فهرست مجاز آن بگذارید. توکن فقط یک بار نمایش داده می‌شود.'],
                    'en' => ['t' => 'Create an API token', 'd' => 'In your panel security page, create a token with register and manage scopes and lock it to your own server IP. The token is shown only once.'],
                    'tr' => ['t' => 'API token oluşturun', 'd' => 'Panel güvenlik sayfasından kayıt ve yönetim izinli bir token oluşturun ve IP kısıtlayın.'],
                    'route' => ['account.security'],
                    'cta' => ['fa' => 'صفحهٔ امنیت', 'en' => 'Security page', 'tr' => 'Güvenlik sayfası'],
                ],
                [
                    'fa' => ['t' => 'افزونه را نصب کنید', 'd' => 'WHMCS دارید؟ افزونهٔ رجیسترار را نصب کنید. وردپرس یا ووکامرس دارید؟ افزونهٔ وردپرس، جعبهٔ جستجو و سبد خرید را روی سایت خودتان می‌آورد. هیچ‌کدام؟ مستقیم از API استفاده کنید.'],
                    'en' => ['t' => 'Install a plugin', 'd' => 'On WHMCS, install the registrar module. On WordPress or WooCommerce, the WordPress plugin adds search and cart to your own site. Neither? Call the API directly.'],
                    'tr' => ['t' => 'WHMCS modülünü kurun', 'd' => 'Bayi panelinden indirin, WHMCS registrar klasörüne koyun ve token girin.'],
                    'route' => ['developers'],
                    'cta' => ['fa' => 'مستندات API', 'en' => 'API documentation', 'tr' => 'API belgeleri'],
                ],
                [
                    'fa' => ['t' => 'حساب را شارژ و شروع به فروش کنید', 'd' => 'ثبت و تمدید از اعتبار حساب کسر می‌شود، پس پیش از اولین سفارش شارژ کنید. از همان لحظه، هر فروش روی سطح تخفیف سال آیندهٔ شما حساب می‌شود.'],
                    'en' => ['t' => 'Top up and start selling', 'd' => 'Registrations and renewals are deducted from your credit, so top up before your first order. From then on every sale counts toward your tier.'],
                    'tr' => ['t' => 'Kredi yükleyin ve satmaya başlayın', 'd' => 'Kayıt ve yenilemeler kredinizden düşülür.'],
                    'route' => ['account.topup'],
                    'cta' => ['fa' => 'شارژ حساب', 'en' => 'Top up', 'tr' => 'Kredi yükle'],
                ],
            ],
        ],

        // نردبانِ سطح‌ها — از `config/domain_reseller.php` خوانده می‌شود
        'signature' => ['type' => 'tiers',
            'fa' => ['t' => 'هرچه بیشتر بفروشید، ارزان‌تر می‌خرید',
                'd' => 'سطح شما از مجموع خرید ۱۲ ماه گذشته و تعداد دامنهٔ فعالتان ساخته می‌شود. ارتقا همان لحظه اعمال می‌شود.'],
            'en' => ['t' => 'The more you sell, the less you pay',
                'd' => 'Your tier comes from your last 12 months of purchases and your active domain count. Upgrades apply instantly.'],
            'tr' => ['t' => 'Ne kadar çok satarsanız o kadar ucuza alırsınız',
                'd' => 'Seviyeniz son 12 aylık alımınızdan ve aktif alan adı sayınızdan gelir.'],
        ],

        'features' => ['support', 'instant',
            ['icon' => 'code',
                'fa' => ['t' => 'API مستند + افزونهٔ WHMCS و وردپرس', 'd' => 'ثبت، تمدید، استعلام قیمت، تغییر نام‌سرور و همگام‌سازی وضعیت با REST API — به‌همراه دو افزونهٔ آماده: WHMCS، و وردپرس/ووکامرس برای فروش دامنه از سایت خودتان با درگاه خودتان.'],
                'en' => ['t' => 'Documented API + WHMCS and WordPress plugins', 'd' => 'Register, renew, price lookup, nameservers and status sync over REST — plus two ready plugins: WHMCS, and WordPress/WooCommerce so you can sell from your own site through your own gateway.'],
                'tr' => ['t' => 'Belgeli API + WHMCS modülü', 'd' => 'REST ile kayıt, yenileme, fiyat sorgu ve durum senkronizasyonu.']],
            ['icon' => 'coins',
                'fa' => ['t' => 'تخفیف پلکانی بر اساس فروش', 'd' => 'با بالا رفتن حجم خریدتان خودکار به سطح بالاتر می‌روید. ارتقا فوری است و افت حجم تا ۳۰ روز مهلت دارد — یک ماه کم‌فروش سطحتان را نمی‌سوزاند.'],
                'en' => ['t' => 'Volume-based discount tiers', 'd' => 'Higher purchase volume moves you up automatically. Upgrades are instant; a drop has a 30-day grace period.'],
                'tr' => ['t' => 'Hacme dayalı kademeli indirim', 'd' => 'Yükselme anında, düşüş için 30 gün süre tanınır.']],
            ['icon' => 'shield',
                'fa' => ['t' => 'توکن با محدودیت IP و سقف خرج', 'd' => 'توکن API را به IP سرور خودتان محدود کنید و سقف خرج روزانه بگذارید. اگر روزی سرورتان نفوذ شود، خسارت به همان سقف محدود می‌مانَد.'],
                'en' => ['t' => 'IP-locked tokens with spend caps', 'd' => 'Restrict the API token to your own server IP and set a daily spend ceiling, so a breach stays bounded.'],
                'tr' => ['t' => 'IP kısıtlı token ve harcama limiti', 'd' => 'Token\'ı kendi sunucu IP\'nize kilitleyin ve günlük harcama limiti koyun.']],
            ['icon' => 'coins',
                'fa' => ['t' => 'پیش‌پرداخت، بدون فاکتور معوق', 'd' => 'از اعتبار حسابتان کسر می‌شود؛ اعتبار تاریخ انقضا ندارد. هیچ صورت‌حساب ماهانه و هیچ بدهی انباشته‌ای در کار نیست.'],
                'en' => ['t' => 'Prepaid, no outstanding invoices', 'd' => 'Everything is deducted from your credit balance, which never expires. No monthly bill, no accumulating debt.'],
                'tr' => ['t' => 'Ön ödemeli, açık fatura yok', 'd' => 'Her şey süresi dolmayan kredi bakiyenizden düşülür.']],
        ],

        /*
        | FAQ عمداً بلند است — هم برای سئو (FAQPage JSON-LD) و هم چون هر
        | سؤالِ بی‌جوابِ این‌جا یک تیکتِ پشتیبانی می‌شود.
        |
        | ⚠️ سؤالِ `.ir` و سؤالِ WHOIS **باید** بمانند. هر دو محدودیتِ واقعی‌اند
        | و نگفتنشان فقط زمانِ کشفشان را به بعد از فروش موکول می‌کند.
        */
        'faqs' => [
            ['fa' => ['q' => 'برای شروع چقدر باید بپردازم؟', 'a' => 'عضویت در برنامهٔ نمایندگی رایگان است؛ فقط حسابتان را شارژ می‌کنید و همان اعتبار خرج ثبت و تمدید دامنه می‌شود. اعتبار هیچ تاریخ انقضایی ندارد و هر زمان می‌توانید بیشتر شارژ کنید.'],
             'en' => ['q' => 'What does it cost to start?', 'a' => 'Joining is free — you simply top up your account and that credit pays for registrations and renewals. Credit never expires.'],
             'tr' => ['q' => 'Başlamak ne kadar tutar?', 'a' => 'Katılım ücretsizdir; sadece hesabınıza kredi yüklersiniz ve süresi dolmaz.']],

            ['fa' => ['q' => 'سطح تخفیف چطور بالا می‌رود؟', 'a' => 'سطح از مجموع خرید ۱۲ ماه گذشته و تعداد دامنهٔ فعال شما ساخته می‌شود و روزانه بازبینی می‌شود. ارتقا همان لحظه‌ای که از آستانه رد شوید اعمال می‌شود؛ اگر حجمتان افت کند ۳۰ روز مهلت دارید و بعد حداکثر یک پله پایین می‌آیید. پیشرفت دقیقتان تا سطح بعد در پنل نمایندگی دیده می‌شود.'],
             'en' => ['q' => 'How do I move up a tier?', 'a' => 'Your tier comes from your last 12 months of purchases plus your active domain count, reviewed daily. Upgrades apply the moment you cross a threshold; a drop gets a 30-day grace period and then at most one step down.'],
             'tr' => ['q' => 'Seviye nasıl yükselir?', 'a' => 'Son 12 aylık alım hacminiz ve aktif alan adı sayınıza göre günlük olarak değerlendirilir.']],

            ['fa' => ['q' => 'آیا تخفیف سطح روی همهٔ پسوندها یکسان اعمال می‌شود؟', 'a' => 'نه همیشه — و این را صریح می‌گوییم. سود ما روی هر پسوند متفاوت است و روی پسوندهای کم‌حاشیه (که معمولاً پرفروش‌ترین‌ها هم هستند) تخفیف فقط تا جایی اعمال می‌شود که قیمت زیر بهای تمام‌شدهٔ ما نرود. هر جا این اتفاق بیفتد، هم در پاسخ API و هم در پنل با نشانهٔ روشن اعلام می‌شود؛ پنهانش نمی‌کنیم.'],
             'en' => ['q' => 'Does the tier discount apply equally to every TLD?', 'a' => 'Not always, and we say so plainly. Our margin differs per TLD; on thin-margin extensions the discount only applies down to a floor above our own cost. Whenever that floor is hit, both the API response and your panel flag it explicitly.'],
             'tr' => ['q' => 'İndirim her uzantıda aynı mı uygulanır?', 'a' => 'Her zaman değil — düşük marjlı uzantılarda indirim bir taban fiyata kadar uygulanır ve bu durum panelde açıkça belirtilir.']],

            ['fa' => ['q' => 'دامنه‌های .ir را هم می‌توانم بفروشم؟', 'a' => 'فعلاً نه. پسوندهای ایرانی از رجیسترار بین‌المللی ما چند ده برابر قیمت واقعی ایرنیک درمی‌آیند و فروختنشان با آن قیمت به نفع هیچ‌کس نیست. تا زمانی که اتصال مستقیم ایرنیک را بسازیم، این پنل برای پسوندهای بین‌المللی است. ترجیح دادیم این را همین‌جا بگوییم تا بعد از ثبت‌نام کشفش کنید.'],
             'en' => ['q' => 'Can I sell .ir domains?', 'a' => 'Not yet. Iranian extensions come from our international registrar at many times the real IRNIC price. Until we build a direct IRNIC path, this panel covers international TLDs. We would rather you know now than discover it after signing up.'],
             'tr' => ['q' => '.ir alan adı satabilir miyim?', 'a' => 'Henüz hayır — doğrudan IRNIC bağlantısı kurulana kadar bu panel uluslararası uzantılar içindir.']],

            ['fa' => ['q' => 'مالک ثبت‌شدهٔ دامنه (WHOIS) کیست؟', 'a' => 'در این نسخه، مالک ثبت‌شده حساب نمایندگی شماست، نه مشتری نهایی شما. یعنی مشتری شما در فرایند خرید با سرورنت روبه‌رو نمی‌شود و همه‌چیز از پنل خودتان انجام می‌شود، ولی مشخصات WHOIS برند شما را به‌عنوان مالک نشان می‌دهد نه برند مشتری را. اگر برای کارتان لازم است مالک، مشتری نهایی باشد، پیش از شروع با ما تماس بگیرید.'],
             'en' => ['q' => 'Who is the registered owner (WHOIS)?', 'a' => 'In this version the registrant is your reseller account, not your end customer. Your customers never deal with ServerNet, but WHOIS shows you as the owner rather than them. If your business needs the end customer as registrant, talk to us before you start.'],
             'tr' => ['q' => 'WHOIS sahibi kimdir?', 'a' => 'Bu sürümde kayıt sahibi sizin bayi hesabınızdır, son müşteriniz değil.']],

            ['fa' => ['q' => 'اگر WHMCS نداشته باشم چه؟', 'a' => 'اگر وردپرس یا ووکامرس دارید، افزونهٔ وردپرس ما جعبهٔ جستجو، سبد خرید و ثبت خودکار پس از پرداخت را روی سایت خودتان می‌آورد؛ مشتری از درگاه خودتان پرداخت می‌کند. اگر هیچ‌کدام را ندارید، API عمومی و مستند است و از هر سامانه‌ای می‌شود صدایش زد.'],
             'en' => ['q' => 'What if I do not use WHMCS?', 'a' => 'The API is public and documented, callable from any system — just create a token in your panel. Full docs with request samples and error codes live in the developers section.'],
             'tr' => ['q' => 'WHMCS kullanmıyorsam?', 'a' => 'API herkese açık ve belgelidir; panelinizden bir token oluşturmanız yeterlidir.']],

            ['fa' => ['q' => 'اگر ارتباط قطع شود، پولم دو بار کسر می‌شود؟', 'a' => 'خیر. هر سفارش یک کلید یکتا دارد و درخواست تکراری همان پاسخ قبلی را می‌گیرد، نه یک خرید تازه. افزونهٔ WHMCS این کلید را خودش می‌سازد و اگر ثبتی ناتمام بماند، صف خودکار ما دنبالش را می‌گیرد و در صورت شکست، مبلغ به اعتبارتان برمی‌گردد.'],
             'en' => ['q' => 'If the connection drops, am I charged twice?', 'a' => 'No. Every order carries a unique key, so a repeated request returns the original response rather than buying again. Our queue finishes any incomplete registration, and refunds credit if it truly fails.'],
             'tr' => ['q' => 'Bağlantı koparsa iki kez mi ücretlendirilirim?', 'a' => 'Hayır — her sipariş benzersiz bir anahtar taşır ve tekrarlanan istek yeni bir satın alma yapmaz.']],

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
