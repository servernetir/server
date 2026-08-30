<?php

/*
|--------------------------------------------------------------------------
| ترجمه‌های en/tr بخشِ ارومیه — روی config/urmia.php سوار می‌شود
|--------------------------------------------------------------------------
|
| urmia.php فارسی و دست‌نخورده می‌ماند (منبعِ محتوای اصلی)؛ این فایل فقط
| لایهٔ ترجمه است و UrmiaController در لحظهٔ رندر آن را overlay می‌کند.
| چرا جدا: ادغامِ سه‌زبانه در خودِ urmia.php آن فایلِ ۸۰KB را سه‌برابر و
| هر ویرایشِ فارسیِ بعدی را پرریسک می‌کرد.
|
| en/tr عمداً فشرده‌تر از فارسی‌اند: مخاطبِ خارجی (شریکِ ترک/عراقی، مشتریِ
| صادراتی) پیامِ اصلی + CTA می‌خواهد، نه ۸۰۰ کلمه سئوی محلی فارسی.
|
| قراردادها:
|  - هر مقدار: ['fa' => …, 'en' => …, 'tr' => …] — fa باید بایت‌به‌بایت
|    همان رشتهٔ قبلاً هاردکد در view باشد (تست‌ها متن فارسی را قفل کرده‌اند).
|  - %s = sprintf در view · %CITY%/%BRAND%/%COMPANY%/%REG%/%SINCE% =
|    جایگزینیِ str_replace در کنترلر/ویو.
*/

return [

    /* ---- رشته‌های رابط (هر سه view) ---- */
    'ui' => [
        'consult_short'  => ['fa' => 'مشاوره رایگان', 'en' => 'Free consultation', 'tr' => 'Ücretsiz danışmanlık'],
        'free_consult'   => ['fa' => 'درخواست مشاوره رایگان', 'en' => 'Request a free consultation', 'tr' => 'Ücretsiz danışmanlık isteyin'],
        'portfolio_btn'  => ['fa' => 'نمونه‌کارها', 'en' => 'Portfolio', 'tr' => 'Referanslar'],
        'call_office'    => ['fa' => 'تماس با دفتر ارومیه', 'en' => 'Call our Urmia office', 'tr' => 'Urmiye ofisimizi arayın'],
        'all_services'   => ['fa' => 'همه خدمات ما در ارومیه', 'en' => 'All our services in Urmia', 'tr' => 'Urmiye’deki tüm hizmetlerimiz'],
        'all_services_s' => ['fa' => 'همه خدمات ما', 'en' => 'All our services', 'tr' => 'Tüm hizmetlerimiz'],
        'services_kicker' => ['fa' => 'خدمات', 'en' => 'Services', 'tr' => 'Hizmetler'],
        'services_h2'    => ['fa' => 'چه کاری برایتان انجام می‌دهیم؟', 'en' => 'What we can build for you', 'tr' => 'Sizin için neler yapabiliriz?'],
        'services_p'     => ['fa' => 'هر خدمت یک صفحهٔ کامل دارد — با شرح روش کار، محدودهٔ قیمت و پاسخ سؤال‌های رایج.', 'en' => 'Every service has its own page with process, pricing guidance and answers to common questions.', 'tr' => 'Her hizmetin süreç, fiyat aralığı ve SSS içeren kendi sayfası vardır.'],
        'cities_kicker'  => ['fa' => 'آذربایجان غربی', 'en' => 'West Azerbaijan', 'tr' => 'Batı Azerbaycan'],
        'cities_h2'      => ['fa' => 'خدمات ما در شهرستان‌های استان', 'en' => 'Our services across the province', 'tr' => 'İldeki diğer şehirlerde hizmetlerimiz'],
        'cities_p'       => ['fa' => 'از خوی تا مهاباد، پروژه‌ها از دفتر ارومیه مدیریت و در صورت نیاز حضوری مستقر می‌شوند.', 'en' => 'From Khoy to Mahabad, projects are managed from our Urmia office, with on-site visits when needed.', 'tr' => 'Hoy’dan Mehabad’a tüm projeler Urmiye ofisimizden yönetilir; gerekirse yerinde kurulum yapılır.'],
        'webdesign_in'   => ['fa' => 'طراحی سایت در', 'en' => 'Web design in', 'tr' => 'Web tasarım —'],
        'faq_kicker'     => ['fa' => 'پرسش‌های پرتکرار', 'en' => 'FAQ', 'tr' => 'SSS'],
        'faq_h2'         => ['fa' => 'سؤال‌هایی که پیش از شروع می‌پرسند', 'en' => 'Questions we hear before every project', 'tr' => 'Projeden önce en çok sorulanlar'],
        'identity_h2'    => ['fa' => 'یک شرکت واقعی در %s', 'en' => 'A real, registered company in %s', 'tr' => '%s’de gerçek ve kayıtlı bir şirket'],
        'office_line'    => ['fa' => 'دفتر ما: %s.', 'en' => 'Our office: %s.', 'tr' => 'Ofisimiz: %s.'],
        'phone_line'     => ['fa' => 'تلفن ثابت دفتر:', 'en' => 'Office phone:', 'tr' => 'Ofis telefonu:'],
        'first_free'     => ['fa' => 'جلسهٔ اول شناخت، حضوری و رایگان است.', 'en' => 'The first consultation is free and without obligation.', 'tr' => 'İlk tanışma görüşmesi ücretsiz ve taahhütsüzdür.'],
        'related_kicker' => ['fa' => 'مرتبط', 'en' => 'Related', 'tr' => 'İlgili'],
        'related_h2'     => ['fa' => 'خدمات مرتبط در ارومیه', 'en' => 'Related services in Urmia', 'tr' => 'Urmiye’de ilgili hizmetler'],
        'hub_link'       => ['fa' => 'هاب ارومیه', 'en' => 'Urmia hub', 'tr' => 'Urmiye merkezi'],
        'hub_urmia'      => ['fa' => 'ارومیه (هاب)', 'en' => 'Urmia (hub)', 'tr' => 'Urmiye (merkez)'],
        'other_cities'   => ['fa' => 'شهرهای دیگر استان', 'en' => 'Other cities in the province', 'tr' => 'İldeki diğer şehirler'],
        'cta_h2'         => ['fa' => 'پروژه‌تان را با یک جلسهٔ بی‌تعهد شروع کنید', 'en' => 'Start your project with a no-obligation meeting', 'tr' => 'Projenize bağlayıcı olmayan bir görüşmeyle başlayın'],
        'cta_p'          => ['fa' => 'در یک جلسهٔ حضوری در ارومیه — یا تماس تصویری — نیازتان را می‌شنویم و پیشنهاد شفاف فنی و مالی می‌دهیم.', 'en' => 'Tell us what you need — in person in Urmia or on a video call — and we will come back with a clear technical and financial proposal.', 'tr' => 'İhtiyacınızı Urmiye’de yüz yüze veya görüntülü görüşmede dinleyelim; net bir teknik ve mali teklif sunalım.'],
        'cta_btn'        => ['fa' => 'درخواست جلسه', 'en' => 'Book a meeting', 'tr' => 'Görüşme planla'],
        'start_talk'     => ['fa' => 'شروع گفتگو', 'en' => 'Start the conversation', 'tr' => 'Görüşmeye başla'],
        'crumb_home'     => ['fa' => 'خانه', 'en' => 'Home', 'tr' => 'Ana sayfa'],
        'crumb_hub'      => ['fa' => 'خدمات ارومیه', 'en' => 'Urmia services', 'tr' => 'Urmiye hizmetleri'],
        'badge_hub'      => ['fa' => '%s · شماره ثبت %s · از سال %s', 'en' => '%s · reg. no. %s · since %s', 'tr' => '%s · sicil no. %s · %s’den beri'],
        'badge_page'     => ['fa' => '%s · %s · از سال %s', 'en' => '%s · %s · since %s', 'tr' => '%s · %s · %s’den beri'],
        'city_why_h2'    => ['fa' => 'چرا کسب‌وکار %s به سایت حرفه‌ای نیاز دارد؟', 'en' => 'Why businesses in %s need a professional website', 'tr' => '%s işletmeleri neden profesyonel bir web sitesine ihtiyaç duyar?'],
        'city_srv_h2'    => ['fa' => 'چه خدماتی در %s ارائه می‌دهیم؟', 'en' => 'What we offer in %s', 'tr' => '%s’de sunduğumuz hizmetler'],
        'city_flow_h2'   => ['fa' => 'روال همکاری با شهرستان‌ها', 'en' => 'How we work with businesses outside Urmia', 'tr' => 'Urmiye dışındaki işletmelerle çalışma şeklimiz'],
        'city_cta_h2'    => ['fa' => 'کسب‌وکار شما در %s، سایت شما آماده‌ی کار', 'en' => 'Your business in %s — your website, ready to work', 'tr' => '%s’deki işiniz için çalışmaya hazır bir web sitesi'],
        'city_cta_p'     => ['fa' => 'در یک تماس کوتاه بگویید چه می‌خواهید؛ پیشنهاد شفاف فنی و مالی می‌گیرید — بدون تعهد.', 'en' => 'Tell us what you need in a short call and get a clear technical and financial proposal — no strings attached.', 'tr' => 'Kısa bir görüşmede ihtiyacınızı anlatın; net bir teklif alın — taahhüt yok.'],
        'city_title'     => ['fa' => 'طراحی سایت در %s | سرورنت — شرکت ثبت‌شده در آذربایجان غربی', 'en' => 'Web Design in %s | ServerNet — registered in West Azerbaijan', 'tr' => '%s Web Tasarım | ServerNet — Batı Azerbaycan’da kayıtlı şirket'],
        'city_desc'      => ['fa' => 'طراحی سایت در %s توسط سرورنت: سایت شرکتی، فروشگاهی و خدماتی با میزبانی روی زیرساخت خودمان، سئوی محلی و پشتیبانی واقعی از مرکز استان. از سال ۱۳۸۸.', 'en' => 'Web design in %s by ServerNet: corporate, e-commerce and service websites hosted on our own infrastructure, with local SEO and real support from the provincial capital. Since 2009.', 'tr' => 'ServerNet ile %s’de web tasarım: kendi altyapımızda barındırılan kurumsal, e-ticaret ve hizmet siteleri; yerel SEO ve il merkezinden gerçek destek. 2009’dan beri.'],
        'city_lead'      => ['fa' => 'طراحی سایت، فروشگاه اینترنتی و نرم‌افزار برای کسب‌وکارهای %s — توسط شرکتی که از سال %SINCE% در همین استان ثبت شده و زیرساخت میزبانی‌اش را خودش مدیریت می‌کند.', 'en' => 'Websites, online stores and software for businesses in %s — by a company registered in this province since 2009, running its own hosting infrastructure.', 'tr' => '%s’deki işletmeler için web sitesi, e-ticaret ve yazılım — 2009’dan beri bu ilde kayıtlı, kendi hosting altyapısını işleten bir şirketten.'],
        'city_ld_desc'   => ['fa' => 'طراحی سایت و خدمات نرم‌افزاری برای کسب‌وکارهای %s در آذربایجان غربی.', 'en' => 'Web design and software services for businesses in %s, West Azerbaijan.', 'tr' => '%s (Batı Azerbaycan) işletmeleri için web tasarım ve yazılım hizmetleri.'],
    ],

    /* ---- هویت (فقط فیلدهای متنی؛ تلفن/نشانی/geo زبان ندارند) ---- */
    'identity' => [
        'brand'    => ['fa' => 'سرورنت', 'en' => 'ServerNet', 'tr' => 'ServerNet'],
        'company'  => ['fa' => 'اطمینان داده پردازان دانش', 'en' => 'Etminan Dadeh Pardazan Danesh Co.', 'tr' => 'Etminan Dadeh Pardazan Danesh Ltd.'],
        'reg_no'   => ['fa' => '۱۹۵۲۳', 'en' => '19523', 'tr' => '19523'],
        'city'     => ['fa' => 'ارومیه', 'en' => 'Urmia', 'tr' => 'Urmiye'],
        'province' => ['fa' => 'آذربایجان غربی', 'en' => 'West Azerbaijan', 'tr' => 'Batı Azerbaycan'],
        'since'    => ['fa' => '۱۳۸۸', 'en' => '2009', 'tr' => '2009'],
    ],

    /* ---- بندِ هویتی مشترک (صفحهٔ خدمت) — placeholder محور ---- */
    'identity_body' => [
        'fa' => '«%BRAND%» برند تجاری شرکت <b>%COMPANY%</b> است؛ ثبت‌شده در %CITY% به شمارهٔ ثبت %REG%، فعال از سال %SINCE% در حوزهٔ طراحی سایت، نرم‌افزار و زیرساخت میزبانی.',
        'en' => '“%BRAND%” is the trade brand of <b>%COMPANY%</b>, registered in %CITY% (reg. no. %REG%) and active since %SINCE% in web design, software and hosting infrastructure. We run our own server clusters in Iran and Germany.',
        'tr' => '“%BRAND%”, %CITY%’de %REG% sicil numarasıyla kayıtlı <b>%COMPANY%</b> şirketinin ticari markasıdır; %SINCE%’dan beri web tasarım, yazılım ve hosting altyapısı alanında faaliyet göstermektedir. İran ve Almanya’da kendi sunucu kümelerimizi işletiyoruz.',
    ],

    /* ---- هاب /urmia ---- */
    'hub' => [
        'title' => ['fa' => 'خدمات طراحی سایت و نرم‌افزار در ارومیه | سرورنت — از سال ۱۳۸۸', 'en' => 'Web Design & Software Services in Urmia | ServerNet — since 2009', 'tr' => 'Urmiye’de Web Tasarım ve Yazılım Hizmetleri | ServerNet — 2009’dan beri'],
        'desc'  => ['fa' => 'هاب خدمات سرورنت در ارومیه: طراحی سایت، فروشگاه اینترنتی، اپلیکیشن، سئو، اتوماسیون اداری و ERP — توسط شرکت ثبت‌شده در ارومیه با زیرساخت میزبانی خودش و پشتیبانی حضوری.', 'en' => 'ServerNet’s services in Urmia: web design, e-commerce, mobile apps, SEO, office automation and ERP — by a registered local company with its own hosting infrastructure and in-person support.', 'tr' => 'ServerNet’in Urmiye hizmetleri: web tasarım, e-ticaret, mobil uygulama, SEO, ofis otomasyonu ve ERP — kendi hosting altyapısına ve yüz yüze desteğe sahip kayıtlı bir yerel şirketten.'],
        'h1'    => ['fa' => 'طراحی سایت و خدمات نرم‌افزاری در ارومیه', 'en' => 'Web Design & Software Services in Urmia', 'tr' => 'Urmiye’de Web Tasarım ve Yazılım Hizmetleri'],
        'lead'  => ['fa' => 'سرورنت از سال %SINCE% در ارومیه سایت، نرم‌افزار و زیرساخت ساخته است — شرکتی ثبت‌شده در همین شهر که سایت شما را روی سرورهای خودش میزبانی می‌کند و پشتیبانی‌اش حضوری است، نه تیکتی.', 'en' => 'ServerNet has been building websites, software and infrastructure in Urmia since 2009 — a company registered in this city that hosts your site on its own servers and supports it in person, not through a distant ticket queue.', 'tr' => 'ServerNet, 2009’dan beri Urmiye’de web sitesi, yazılım ve altyapı geliştiriyor — bu şehirde kayıtlı, sitenizi kendi sunucularında barındıran ve uzak bir destek kuyruğu yerine yüz yüze destek veren bir şirket.'],
        'infra_h2' => ['fa' => 'سایت شما روی زیرساخت خودمان میزبانی می‌شود، نه هاست اجاره‌ای', 'en' => 'Your site runs on our own infrastructure, not rented hosting', 'tr' => 'Siteniz kiralık hosting’de değil, kendi altyapımızda çalışır'],
        'infra_p1' => ['fa' => 'تقریباً همهٔ آژانس‌های طراحی سایت، میزبانی را از یک شرکت دیگر اجاره می‌کنند؛ اگر مشکلی پیش بیاید فقط می‌توانند تیکت بزنند و منتظر بمانند. سرورنت خودش شرکت میزبانی است: کلاستر سرورهای ما در دیتاسنترهای ایران و آلمان زیر مدیریت مستقیم تیم فنی خودمان است — از سخت‌افزار تا شبکه.', 'en' => 'Almost every web agency rents hosting from someone else; when something breaks, all they can do is open a ticket and wait. ServerNet is itself a hosting company: our server clusters in Iranian and German datacenters are managed directly by our own technical team — from hardware to network.', 'tr' => 'Neredeyse her web ajansı hosting’i başkasından kiralar; bir sorun çıktığında yapabilecekleri tek şey destek talebi açıp beklemektir. ServerNet’in kendisi bir hosting şirketidir: İran ve Almanya’daki sunucu kümelerimiz, donanımdan ağa kadar kendi teknik ekibimizce yönetilir.'],
        'infra_p2' => ['fa' => 'برای شما یعنی: یک مسئول مشخص برای همهٔ لایه‌ها، سرعت و پایداری‌ای که خودمان ضمانتش را می‌دهیم، و <b>تداوم کسب‌وکار در روزهای اختلال اینترنت</b> — سایت‌هایی که در ایران میزبانی می‌شوند، در قطعی اینترنت بین‌الملل برای مشتری داخلی همچنان باز می‌مانند. برای کسب‌وکار محلی، این تفاوت بقاست نه تجمل.', 'en' => 'For you that means one accountable owner for every layer, speed and stability we guarantee ourselves, and <b>business continuity during connectivity disruptions</b> — sites hosted in Iran stay reachable for local customers even when international links fail.', 'tr' => 'Sizin için anlamı: tüm katmanlardan sorumlu tek bir muhatap, kendimizin garanti ettiği hız ve kararlılık, ve <b>bağlantı kesintilerinde iş sürekliliği</b> — İran’da barındırılan siteler, uluslararası hatlar kesildiğinde bile yerel müşteriler için açık kalır.'],
        'years_h2' => ['fa' => 'پانزده سال در یک شهر', 'en' => 'Fifteen years in one city', 'tr' => 'Aynı şehirde on beş yıl'],
        'years_p'  => ['fa' => '«%BRAND%» برند شرکت <b>%COMPANY%</b> است — ثبت‌شده در %CITY% به شمارهٔ %REG%، فعال از سال %SINCE%. مشتریان ما در همین شهرند، همدیگر را می‌شناسند و می‌توانید پیش از هر قراردادی با چندتایشان حرف بزنید. اعتباری که در یک شهر کوچک ساخته می‌شود، شکننده‌تر و در نتیجه واقعی‌تر از هر تبلیغی است.', 'en' => '“%BRAND%” is the brand of <b>%COMPANY%</b> — registered in %CITY% under no. %REG%, active since %SINCE%. Our clients are in this city, they know each other, and you can talk to several of them before signing anything. A reputation built in a small city is more fragile — and therefore more real — than any advertising.', 'tr' => '“%BRAND%”, <b>%COMPANY%</b> şirketinin markasıdır — %CITY%’de %REG% numarasıyla kayıtlı, %SINCE%’dan beri faal. Müşterilerimiz bu şehirde ve birbirlerini tanıyor; imza atmadan önce birkaçıyla konuşabilirsiniz. Küçük bir şehirde inşa edilen itibar daha kırılgandır — bu yüzden her reklamdan daha gerçektir.'],
        'cta_h2' => ['fa' => 'از یک جلسهٔ بی‌تعهد شروع کنید', 'en' => 'Start with a no-obligation meeting', 'tr' => 'Bağlayıcı olmayan bir görüşmeyle başlayın'],
        'cta_p'  => ['fa' => 'کارتان را برایمان تعریف کنید؛ صادقانه می‌گوییم چه چیزی لازم دارید، چقدر هزینه دارد و چقدر طول می‌کشد.', 'en' => 'Describe your business to us; we will tell you honestly what you need, what it costs and how long it takes.', 'tr' => 'İşinizi bize anlatın; neye ihtiyacınız olduğunu, maliyetini ve süresini dürüstçe söyleyelim.'],
        'ld_name' => ['fa' => 'خدمات طراحی سایت و نرم‌افزار در ارومیه', 'en' => 'Web design & software services in Urmia', 'tr' => 'Urmiye’de web tasarım ve yazılım hizmetleri'],
        'ld_desc' => ['fa' => 'طراحی سایت، فروشگاه اینترنتی، اپلیکیشن، سئو، اتوماسیون اداری و ERP در ارومیه و آذربایجان غربی.', 'en' => 'Web design, e-commerce, mobile apps, SEO, office automation and ERP in Urmia and West Azerbaijan.', 'tr' => 'Urmiye ve Batı Azerbaycan’da web tasarım, e-ticaret, mobil uygulama, SEO, ofis otomasyonu ve ERP.'],
    ],

    /* ---- صفحات شهری: نام لاتین + قالبِ مشترکِ en/tr ---- */
    'city_names' => [
        'khoy'           => ['en' => 'Khoy',          'tr' => 'Hoy'],
        'salmas'         => ['en' => 'Salmas',        'tr' => 'Selmas'],
        'mahabad'        => ['en' => 'Mahabad',       'tr' => 'Mehabad'],
        'bukan'          => ['en' => 'Bukan',         'tr' => 'Bukan'],
        'miandoab'       => ['en' => 'Miandoab',      'tr' => 'Miyandoab'],
        'piranshahr'     => ['en' => 'Piranshahr',    'tr' => 'Piranşehr'],
        'naqadeh'        => ['en' => 'Naqadeh',       'tr' => 'Nakade'],
        'takab'          => ['en' => 'Takab',         'tr' => 'Tekab'],
        'shahin-dej'     => ['en' => 'Shahin Dezh',   'tr' => 'Şahindej'],
        'maku'           => ['en' => 'Maku',          'tr' => 'Makü'],
        'bazargan'       => ['en' => 'Bazargan',      'tr' => 'Bazergan'],
        'sardasht'       => ['en' => 'Sardasht',      'tr' => 'Serdeşt'],
        'oshnavieh'      => ['en' => 'Oshnavieh',     'tr' => 'Uşnaviye'],
        'poldasht'       => ['en' => 'Poldasht',      'tr' => 'Poldeşt'],
        'showt'          => ['en' => 'Showt',         'tr' => 'Şot'],
        'chaldoran'      => ['en' => 'Chaldoran',     'tr' => 'Çaldıran'],
        'qarah-ziaeddin' => ['en' => 'Qarah Ziaeddin', 'tr' => 'Karaziyaeddin'],
    ],

    /*
    | متنِ معرفیِ en/tr شهرها قالبِ مشترک است (٪CITY٪ جایگزین می‌شود) — عمداً:
    | متنِ یکتای ۱۷ شهر یک سرمایهٔ سئوی «فارسی» است؛ نسخهٔ خارجی این صفحات
    | برای شریکِ تجاری است، نه رقابتِ کلیدواژه‌ای، و قالبِ تمیز کافی است.
    */
    'city_body' => [
        'en' => [
            'Businesses in %CITY% compete far beyond their own street: customers check you on Google before they ever call. A professional website — fast, secure and properly indexed — is how a local company in %CITY% wins that first impression.',
            'ServerNet is a registered software and hosting company based in Urmia, working across West Azerbaijan since 2009. We design corporate and e-commerce websites, run them on our own infrastructure in Iran and Germany, and support them with a real local team. For businesses in %CITY% we handle everything remotely, with on-site meetings arranged when needed.',
        ],
        'tr' => [
            '%CITY%’deki işletmeler artık yalnızca kendi caddeleriyle rekabet etmiyor: müşteriler aramadan önce sizi Google’da kontrol ediyor. Hızlı, güvenli ve doğru indekslenmiş profesyonel bir web sitesi, %CITY%’deki bir işletmenin ilk izlenimi kazanma yoludur.',
            'ServerNet, Urmiye merkezli, 2009’dan beri Batı Azerbaycan genelinde çalışan kayıtlı bir yazılım ve hosting şirketidir. Kurumsal ve e-ticaret siteleri tasarlıyor, bunları İran ve Almanya’daki kendi altyapımızda barındırıyor ve gerçek bir yerel ekiple destekliyoruz. %CITY%’deki işletmeler için süreci uzaktan yönetiyor, gerektiğinde yerinde toplantı düzenliyoruz.',
        ],
    ],

    /* فهرست خدمات صفحهٔ شهری — fa بایت‌به‌بایت همان متنِ قبلیِ view */
    'city_services' => [
        'web-design'        => ['t' => ['fa' => 'طراحی سایت', 'en' => 'Web design', 'tr' => 'Web tasarım'],
                                'd' => ['fa' => 'از سایت معرفی تا پرتال سازمانی، با طراحی اختصاصی و سئوی محلی', 'en' => 'from brochure sites to organisational portals, custom-designed with local SEO', 'tr' => 'tanıtım sitesinden kurum portalına, özel tasarım ve yerel SEO ile']],
        'ecommerce-website' => ['t' => ['fa' => 'فروشگاه اینترنتی', 'en' => 'Online stores', 'tr' => 'E-ticaret'],
                                'd' => ['fa' => 'درگاه پرداخت، مدیریت موجودی و تعرفه‌ی ارسال تنظیم‌شده برای %CITY%', 'en' => 'payment gateway, inventory and shipping rates configured for %CITY%', 'tr' => 'ödeme entegrasyonu, stok yönetimi ve %CITY% için ayarlanmış kargo tarifeleri']],
        'corporate-website' => ['t' => ['fa' => 'سایت شرکتی', 'en' => 'Corporate websites', 'tr' => 'Kurumsal siteler'],
                                'd' => ['fa' => 'برای شرکت‌هایی که طرف قرارداد سازمانی و مناقصه‌اند', 'en' => 'for companies dealing with enterprise contracts and tenders', 'tr' => 'kurumsal sözleşme ve ihalelerle çalışan şirketler için']],
        'seo'               => ['t' => ['fa' => 'سئوی محلی', 'en' => 'Local SEO', 'tr' => 'Yerel SEO'],
                                'd' => ['fa' => 'دیده‌شدن در جستجوهای «در %CITY%» که هنوز رقابتشان کم است', 'en' => 'visibility in “in %CITY%” searches, where competition is still low', 'tr' => 'rekabetin hâlâ düşük olduğu “%CITY%” aramalarında görünürlük']],
        'app-development'   => ['t' => ['fa' => 'اپلیکیشن موبایل و نرم‌افزار سفارشی', 'en' => 'Mobile apps & custom software', 'tr' => 'Mobil uygulama ve özel yazılım'],
                                'd' => ['fa' => 'برای کسب‌وکارهایی که ابزار اختصاصی می‌خواهند', 'en' => 'for businesses that need their own tools', 'tr' => 'kendi araçlarına ihtiyaç duyan işletmeler için']],
        'support'           => ['t' => ['fa' => 'پشتیبانی و نگهداری سایت', 'en' => 'Website maintenance', 'tr' => 'Site bakımı'],
                                'd' => ['fa' => 'سایت فعلی‌تان را هر کسی ساخته باشد، تحویل می‌گیریم', 'en' => 'we take over your current site, whoever built it', 'tr' => 'mevcut sitenizi kim yapmış olursa olsun devralırız']],
    ],

    /* روال همکاری (صفحهٔ شهری) — fa همان دو پاراگرافِ قبلی */
    'city_flow' => [
        'fa' => ['جلسهٔ شناخت اول تلفنی یا تصویری برگزار می‌شود و در صورت نیاز، حضوری در %CITY% هماهنگ می‌کنیم. قرارداد و پرداخت مرحله‌ای است، آموزش پنل مدیریت از راه دور و با ویدیوی اختصاصی انجام می‌شود، و پشتیبانی بعد از تحویل با شماره‌ی مستقیم تیم فنی در مرکز استان است — نه صف تیکت یک شرکت آن‌سر کشور.',
                 'میزبانی سایت روی زیرساخت خود سرورنت است؛ یعنی سرعت، بکاپ روزانه و امنیت یک مسئول مشخص دارد و در روزهای اختلال اینترنت، گزینه‌ی میزبانی داخل ایران، سایت شما را برای مشتری داخلی باز نگه می‌دارد.'],
        'en' => ['The first meeting happens by phone or video, and on-site visits in %CITY% can be arranged. Contracts and payments are staged, admin-panel training is delivered remotely, and after delivery you have a direct line to the technical team in the provincial capital — not a ticket queue on the other side of the country.',
                 'Your site is hosted on ServerNet’s own infrastructure: speed, daily backups and security have one accountable owner, and Iranian hosting keeps your site open for local customers even during international connectivity disruptions.'],
        'tr' => ['İlk görüşme telefon veya video ile yapılır; gerekirse %CITY%’de yerinde ziyaret planlanır. Sözleşme ve ödemeler aşamalıdır, yönetim paneli eğitimi uzaktan verilir; teslimden sonra il merkezindeki teknik ekibe doğrudan hattınız olur — ülkenin öbür ucundaki bir destek kuyruğu değil.',
                 'Siteniz ServerNet’in kendi altyapısında barındırılır: hız, günlük yedekleme ve güvenlikten tek bir ekip sorumludur; İran içi hosting seçeneği, uluslararası kesintilerde bile sitenizi yerel müşterilere açık tutar.'],
    ],

    /*
    | ترجمهٔ ۱۱ صفحهٔ خدمت. هر صفحه: title/desc/h1/lead (نگاشت en/tr) +
    | sections و faq (آرایهٔ کامل به‌ازای هر زبان — جایگزینِ کلِ آرایهٔ fa).
    */
    'pages' => [

        'web-design' => [
            'title' => ['en' => 'Web Design in Urmia | ServerNet — since 2009', 'tr' => 'Urmiye’de Web Tasarım | ServerNet — 2009’dan beri'],
            'desc'  => ['en' => 'Professional web design in Urmia by ServerNet: custom design, hosting on our own infrastructure, local SEO and in-person support. A registered company with 15 years of experience.', 'tr' => 'ServerNet ile Urmiye’de profesyonel web tasarım: özel tasarım, kendi altyapımızda hosting, yerel SEO ve yüz yüze destek. 15 yıllık kayıtlı şirket.'],
            'h1'    => ['en' => 'Web Design in Urmia', 'tr' => 'Urmiye’de Web Tasarım'],
            'lead'  => ['en' => 'We have been building websites in Urmia since 2009 — not as a reseller agency, but as a company that owns its hosting infrastructure. Your site runs on our servers, is supported by a team in this city, and is optimised for this market.', 'tr' => '2009’dan beri Urmiye’de web sitesi yapıyoruz — aracı bir ajans olarak değil, kendi hosting altyapısına sahip bir şirket olarak. Siteniz bizim sunucularımızda çalışır ve bu şehirdeki ekip tarafından desteklenir.'],
            'sections' => [
                'en' => [
                    ['h' => 'A local partner, not a remote ticket queue',
                     'p' => ['When your web designer is in another city, every change is a support ticket. ServerNet is registered in Urmia (reg. no. 19523) and has served businesses across West Azerbaijan since 2009 — you can meet us in person, see live projects, and know exactly who is responsible for your site.']],
                    ['h' => 'The difference: our own infrastructure',
                     'p' => ['Almost every agency rents hosting from someone else. ServerNet is itself a hosting company, with server clusters in Iran and Germany under our own management. If your site slows down, we fix the cause — we don’t open a ticket with a third party. And Iranian hosting keeps your site reachable for local customers even during international connectivity disruptions.']],
                    ['h' => 'What we build',
                     'p' => ['Corporate websites, online stores with payment integration, booking and service sites, multi-department portals — and bilingual or trilingual sites (Persian/Turkish/English) for exporters and cross-border businesses working with Türkiye and Iraq.'],
                     'list' => ['A fixed written contract with staged payments', 'Responsive design and technical SEO from day one', 'First-year hosting on our own infrastructure included', 'Hands-on training on the admin panel']],
                ],
                'tr' => [
                    ['h' => 'Uzak bir destek kuyruğu değil, yerel bir ortak',
                     'p' => ['Web tasarımcınız başka bir şehirdeyse her değişiklik bir destek talebine dönüşür. ServerNet, Urmiye’de kayıtlıdır (sicil no. 19523) ve 2009’dan beri Batı Azerbaycan genelindeki işletmelere hizmet vermektedir — bizimle yüz yüze görüşebilir, canlı projeleri görebilir ve sitenizden tam olarak kimin sorumlu olduğunu bilirsiniz.']],
                    ['h' => 'Fark: kendi altyapımız',
                     'p' => ['Neredeyse her ajans hosting’i başkasından kiralar. ServerNet ise İran ve Almanya’da kendi yönetiminde sunucu kümeleri olan bir hosting şirketidir. Siteniz yavaşlarsa nedenini kendimiz bulur ve çözeriz; üçüncü bir tarafa destek talebi açmayız.']],
                    ['h' => 'Neler yapıyoruz',
                     'p' => ['Kurumsal siteler, ödeme entegrasyonlu e-ticaret siteleri, randevu ve hizmet siteleri, kurum portalları — ve Türkiye ve Irak ile çalışan ihracatçılar için Farsça/Türkçe/İngilizce çok dilli siteler.'],
                     'list' => ['Yazılı sözleşme ve aşamalı ödeme', 'İlk günden responsive tasarım ve teknik SEO', 'İlk yıl kendi altyapımızda hosting dahil', 'Yönetim paneli eğitimi']],
                ],
            ],
            'faq' => [
                'en' => [
                    ['q' => 'How long does a website take?', 'a' => 'A corporate site typically takes 2–4 weeks and an online store 4–8 weeks. The exact schedule is fixed in the contract.'],
                    ['q' => 'Do I own the site afterwards?', 'a' => 'Yes — the domain is registered in your name and you get full admin access plus training. There is no lock-in to ServerNet.'],
                    ['q' => 'Can you redesign my existing site?', 'a' => 'Yes. We audit the current site first; if its structure is sound we redesign on top of it and preserve your existing content and SEO. Migration to our hosting is free.'],
                ],
                'tr' => [
                    ['q' => 'Bir web sitesi ne kadar sürede hazır olur?', 'a' => 'Kurumsal site genelde 2–4 hafta, e-ticaret sitesi 4–8 hafta sürer. Kesin takvim sözleşmede yazılıdır.'],
                    ['q' => 'Site sonrasında bana mı ait oluyor?', 'a' => 'Evet — alan adı sizin adınıza kaydedilir, tam yönetici erişimi ve eğitim verilir. ServerNet’e bağımlılık yoktur.'],
                    ['q' => 'Mevcut sitemi yenileyebilir misiniz?', 'a' => 'Evet. Önce mevcut siteyi inceleriz; yapısı sağlamsa üzerine yeniden tasarım yapar, içerik ve SEO’nuzu koruruz. Hosting taşıma ücretsizdir.'],
                ],
            ],
        ],

        'web-design-price' => [
            'title' => ['en' => 'Website Cost in Urmia | Transparent Pricing — ServerNet', 'tr' => 'Urmiye’de Web Sitesi Fiyatları | Şeffaf Fiyat — ServerNet'],
            'desc'  => ['en' => 'Website design prices in Urmia: corporate, e-commerce and organisational sites. The price is fixed before we start and includes first-year hosting on ServerNet infrastructure.', 'tr' => 'Urmiye’de web tasarım fiyatları: kurumsal, e-ticaret ve kurum siteleri. Fiyat işe başlamadan kesinleşir ve ilk yıl hosting dahildir.'],
            'h1'    => ['en' => 'Website Pricing in Urmia', 'tr' => 'Urmiye’de Web Sitesi Fiyatları'],
            'lead'  => ['en' => 'After fifteen years of projects in Urmia we know exactly what each type of website costs to build — so we fix the price before we start, and it does not change mid-project.', 'tr' => 'Urmiye’de on beş yıllık projeden sonra her site türünün maliyetini tam olarak biliyoruz — fiyatı işe başlamadan sabitliyoruz ve proje ortasında değişmiyor.'],
            'sections' => [
                'en' => [
                    ['h' => 'What actually drives the price',
                     'p' => ['Three factors: the number and complexity of pages, custom features (payment gateway, booking, multilingual, accounting integration), and the content that must be produced. What should never inflate the price is a middleman — and because design and hosting are both in-house, that layer simply is not there.',
                             'Typical ranges: brochure sites from about 15M IRT, full corporate sites from about 25M, and online stores from about 40M. The exact figure is fixed in the first meeting — before any contract is signed, not after.']],
                    ['h' => 'Included at no extra cost',
                     'list' => ['First-year hosting on our own infrastructure', 'SSL certificate, installation and renewals', 'Mobile-responsive design', 'Technical SEO: speed, schema, sitemap, clean URLs', 'Admin-panel training', 'Regular backups and monitoring']],
                    ['h' => 'Why very cheap quotes end up costing more',
                     'p' => ['A rock-bottom quote usually means a ready-made template on rented shared hosting, with no SEO and no support. Six months later, rescuing it costs more than building it right would have. If your budget is tight, we shrink the scope — not the quality — and design an upgrade path so nothing is thrown away.']],
                ],
                'tr' => [
                    ['h' => 'Fiyatı gerçekte ne belirler',
                     'p' => ['Üç etken: sayfa sayısı ve karmaşıklığı, özel özellikler (ödeme, randevu, çok dillilik, muhasebe entegrasyonu) ve üretilecek içerik. Fiyatı asla şişirmemesi gereken şey aracıdır — tasarım ve hosting aynı şirkette olduğu için o katman zaten yoktur.',
                             'Tipik aralıklar: tanıtım siteleri yaklaşık 15M IRT’den, kurumsal siteler 25M’den, e-ticaret siteleri 40M’den başlar. Kesin rakam ilk görüşmede — sözleşmeden önce — sabitlenir.']],
                    ['h' => 'Ek ücret olmadan dahil olanlar',
                     'list' => ['İlk yıl kendi altyapımızda hosting', 'SSL sertifikası, kurulumu ve yenilemeleri', 'Mobil uyumlu tasarım', 'Teknik SEO: hız, schema, site haritası', 'Yönetim paneli eğitimi', 'Düzenli yedekleme ve izleme']],
                    ['h' => 'Çok ucuz teklifler neden pahalıya mal olur',
                     'p' => ['Çok düşük bir teklif genelde kiralık paylaşımlı hosting üzerinde hazır şablon, SEO’suz ve desteksiz demektir. Altı ay sonra onu kurtarmak, baştan doğru yapmaktan pahalıdır. Bütçeniz darsa kaliteyi değil kapsamı küçültür, hiçbir şeyin çöpe gitmeyeceği bir yükseltme yolu tasarlarız.']],
                ],
            ],
            'faq' => [
                'en' => [
                    ['q' => 'What is the cheapest site you build?', 'a' => 'A multi-page brochure site from about 15M IRT. We do not go below that — the result would not be something we could stand behind.'],
                    ['q' => 'What are the ongoing costs?', 'a' => 'Only hosting and domain renewals at our public rates. There is no mandatory support fee.'],
                    ['q' => 'Should I compare quotes?', 'a' => 'Absolutely — but compare three things: whose hosting the site runs on, what SEO is included, and who supports it after delivery. That is where quotes really differ.'],
                ],
                'tr' => [
                    ['q' => 'En uygun fiyatlı site ne kadar?', 'a' => 'Çok sayfalı tanıtım sitesi yaklaşık 15M IRT’den başlar. Bunun altına inmiyoruz — arkasında duramayacağımız iş yapmayız.'],
                    ['q' => 'Yıllık maliyetler neler?', 'a' => 'Sadece hosting ve alan adı yenilemesi, herkese açık tarifemizle. Zorunlu destek ücreti yoktur.'],
                    ['q' => 'Teklifleri karşılaştırmalı mıyım?', 'a' => 'Kesinlikle — ama üç şeyi karşılaştırın: hosting kimin, SEO neleri kapsıyor ve teslim sonrası desteği kim veriyor.'],
                ],
            ],
        ],

        'software-company' => [
            'title' => ['en' => 'Software Company in Urmia | ServerNet', 'tr' => 'Urmiye’de Yazılım Şirketi | ServerNet'],
            'desc'  => ['en' => 'Registered software company in Urmia (reg. no. 19523): custom web software, office automation, ERP and network infrastructure for organisations in West Azerbaijan since 2009.', 'tr' => 'Urmiye’de kayıtlı yazılım şirketi: kurumlara özel web yazılımı, ofis otomasyonu, ERP ve ağ altyapısı — 2009’dan beri.'],
            'h1'    => ['en' => 'A Software Company in Urmia', 'tr' => 'Urmiye’de Bir Yazılım Şirketi'],
            'lead'  => ['en' => 'An organisation that needs custom software is not looking for a freelancer — it needs a company that will still answer for that software five years from now. ServerNet has been registered in Urmia since 2009, and it stayed.', 'tr' => 'Özel yazılıma ihtiyaç duyan bir kurum serbest çalışan aramaz; beş yıl sonra da o yazılımın arkasında duracak bir şirket arar. ServerNet 2009’dan beri Urmiye’de kayıtlıdır ve buradadır.'],
            'sections' => [
                'en' => [
                    ['h' => 'A legal entity, real contracts',
                     'p' => ['We sign formal contracts, issue official invoices and take part in provincial tenders. Fifteen years in one city means our reputation is tied to our past projects — our clients in Urmia know each other, and that is the best quality guarantee we can offer.']],
                    ['h' => 'What we build',
                     'list' => ['Custom web-based software matched to your workflow', 'Office automation — paperless correspondence and approvals', 'Lightweight ERP for mid-size industry: sales, inventory, finance', 'Organisational portals with access control', 'Mobile apps connected to the same core systems', 'Network infrastructure and virtualisation — ServerNet’s home turf']],
                    ['h' => 'Software and infrastructure under one roof',
                     'p' => ['Enterprise software has to run somewhere. When the developer and the host are different companies, each blames the other. At ServerNet both layers have one owner: your system can run on a dedicated server in Iran, with backups, or entirely inside your own local network.']],
                ],
                'tr' => [
                    ['h' => 'Tüzel kişilik, gerçek sözleşmeler',
                     'p' => ['Resmî sözleşme imzalar, fatura keser ve il ihalelerine katılırız. Aynı şehirde on beş yıl demek, itibarımızın geçmiş projelerimize bağlı olması demektir — Urmiye’deki müşterilerimiz birbirini tanır ve sunabileceğimiz en iyi kalite güvencesi budur.']],
                    ['h' => 'Neler geliştiriyoruz',
                     'list' => ['İş akışınıza özel web tabanlı yazılım', 'Ofis otomasyonu — kâğıtsız yazışma ve onay', 'Orta ölçekli sanayi için hafif ERP: satış, stok, finans', 'Erişim kontrollü kurum portalları', 'Aynı çekirdeğe bağlı mobil uygulamalar', 'Ağ altyapısı ve sanallaştırma']],
                    ['h' => 'Yazılım ve altyapı tek çatı altında',
                     'p' => ['Kurumsal yazılım bir yerde çalışmak zorundadır. Geliştirici ve host ayrı şirketlerse herkes suçu diğerine atar. ServerNet’te iki katmanın da tek sahibi vardır: sisteminiz İran’daki özel bir sunucuda, yedekli olarak ya da tamamen kendi yerel ağınızın içinde çalışabilir.']],
                ],
            ],
            'faq' => [
                'en' => [
                    ['q' => 'Do you work with government tenders?', 'a' => 'Yes — we have the registered legal entity, contract history and documentation required for provincial tenders.'],
                    ['q' => 'Can you maintain or rewrite our legacy software?', 'a' => 'We audit it first; if maintenance makes sense we maintain it, otherwise we rewrite it in stages with data migration and no downtime.'],
                    ['q' => 'Do you only serve Urmia?', 'a' => 'Urmia is our base, but we deliver and install across West Azerbaijan and nationally.'],
                ],
                'tr' => [
                    ['q' => 'Kamu ihaleleriyle çalışıyor musunuz?', 'a' => 'Evet — il ihaleleri için gereken tüzel kişilik, sözleşme geçmişi ve belgelere sahibiz.'],
                    ['q' => 'Eski yazılımımızın bakımını üstlenir misiniz?', 'a' => 'Önce inceleriz; bakım mantıklıysa bakımını yaparız, değilse veriyi taşıyarak aşamalı olarak yeniden yazarız.'],
                    ['q' => 'Sadece Urmiye’ye mi hizmet veriyorsunuz?', 'a' => 'Merkezimiz Urmiye; ancak tüm Batı Azerbaycan’a ve ülke geneline teslimat ve kurulum yapıyoruz.'],
                ],
            ],
        ],

        'office-automation' => [
            'title' => ['en' => 'Office Automation in Urmia | Paperless Workflows — ServerNet', 'tr' => 'Urmiye’de Ofis Otomasyonu | Kâğıtsız Süreçler — ServerNet'],
            'desc'  => ['en' => 'Office automation deployment in Urmia: correspondence workflow, e-forms and document archive, with on-site training by a local team.', 'tr' => 'Urmiye’de ofis otomasyonu kurulumu: yazışma akışı, e-formlar ve belge arşivi; yerinde eğitimle.'],
            'h1'    => ['en' => 'Office Automation in Urmia', 'tr' => 'Urmiye’de Ofis Otomasyonu'],
            'lead'  => ['en' => 'Every letter that moves through your organisation gets lost once, waits for a signature, and needs a copy filed. Office automation makes that cycle electronic — and we deploy it in Urmia, with hands-on staff training.', 'tr' => 'Kurumunuzda dolaşan her evrak bir kez kaybolur, bir imza bekler, bir kopyası dosyalanır. Ofis otomasyonu bu döngüyü elektronik hale getirir — biz de bunu Urmiye’de, birebir personel eğitimiyle kurarız.'],
            'sections' => [
                'en' => [
                    ['h' => 'What actually changes',
                     'p' => ['Incoming mail is scanned, numbered and routed to the right desk automatically; approvals happen with a click and leave a full audit trail. Internal forms — leave, procurement, expenses — flow through predefined paths. Management sees, on one dashboard, what is waiting where and for how long.']],
                    ['h' => 'Why local deployment matters',
                     'p' => ['Automation is an organisational change, not a software install. Staff who have used paper for twenty years need real training and someone nearby during the first weeks. Our team is in Urmia — setup, training and troubleshooting happen in person. For sensitive organisations, the system can run entirely on servers inside your own building.']],
                ],
                'tr' => [
                    ['h' => 'Gerçekte ne değişir',
                     'p' => ['Gelen evrak taranır, numaralanır ve doğru masaya otomatik yönlendirilir; onaylar tek tıkla verilir ve tam bir denetim izi bırakır. İzin, satın alma ve masraf formları tanımlı yollardan akar. Yönetim tek panelden neyin nerede ve ne kadar süredir beklediğini görür.']],
                    ['h' => 'Yerinde kurulum neden önemli',
                     'p' => ['Otomasyon bir yazılım kurulumu değil, kurumsal bir değişimdir. Yirmi yıl kâğıtla çalışmış personel gerçek eğitim ve ilk haftalarda yakında birini ister. Ekibimiz Urmiye’dedir — kurulum, eğitim ve sorun giderme yüz yüze yapılır. Hassas kurumlar için sistem tamamen kendi binanızdaki sunucularda çalışabilir.']],
                ],
            ],
            'faq' => [
                'en' => [
                    ['q' => 'How long does deployment take?', 'a' => 'For small and mid-size organisations, 2–6 weeks including configuration, archive migration and training.'],
                    ['q' => 'Where is our data stored?', 'a' => 'Your choice: on a server inside your organisation, or on a dedicated ServerNet server in Iran with daily backups. Either way the data is yours, with full export.'],
                    ['q' => 'Does it integrate with our accounting software?', 'a' => 'We assess that in the initial analysis; common Iranian packages have known integration paths.'],
                ],
                'tr' => [
                    ['q' => 'Kurulum ne kadar sürer?', 'a' => 'Küçük ve orta ölçekli kurumlarda yapılandırma, arşiv taşıma ve eğitim dahil 2–6 hafta.'],
                    ['q' => 'Verilerimiz nerede saklanır?', 'a' => 'Tercihinize göre: kurum içi bir sunucuda ya da İran’daki özel ServerNet sunucusunda, günlük yedekli. Veri her durumda sizindir ve tam dışa aktarım vardır.'],
                    ['q' => 'Muhasebe yazılımımızla entegre olur mu?', 'a' => 'İlk analizde değerlendiririz; yaygın paketler için bilinen entegrasyon yolları vardır.'],
                ],
            ],
        ],

        'app-development' => [
            'title' => ['en' => 'Mobile App Development in Urmia | Android & iOS — ServerNet', 'tr' => 'Urmiye’de Mobil Uygulama Geliştirme | Android & iOS — ServerNet'],
            'desc'  => ['en' => 'Mobile app design and development in Urmia: store, service and enterprise apps for Android and iOS, with an admin panel and local support.', 'tr' => 'Urmiye’de mobil uygulama tasarımı ve geliştirme: Android ve iOS için mağaza, hizmet ve kurumsal uygulamalar.'],
            'h1'    => ['en' => 'Mobile App Development in Urmia', 'tr' => 'Urmiye’de Mobil Uygulama Geliştirme'],
            'lead'  => ['en' => 'A good app starts with a clear idea, not with code. We first check honestly whether an app is the right tool for your business — and if it is, we build it with the team that has shipped software in Urmia for fifteen years.', 'tr' => 'İyi bir uygulama kodla değil, net bir fikirle başlar. Önce uygulamanın işiniz için doğru araç olup olmadığına dürüstçe bakarız — öyleyse, on beş yıldır Urmiye’de yazılım teslim eden ekiple geliştiririz.'],
            'sections' => [
                'en' => [
                    ['h' => 'App or web app? We tell you honestly',
                     'p' => ['Many businesses pay for a native app when a fast installable web app would deliver the same result at a fraction of the cost. An app is worth it when you need push notifications, offline use, device hardware — or daily-returning customers who should have your icon on their home screen.']],
                    ['h' => 'What we build',
                     'list' => ['Store apps — catalogue, cart, payment, promotions', 'Service and booking apps with order tracking', 'Enterprise apps: the mobile side of your internal systems', 'Distribution apps for field sales teams'],
                     'p' => ['Every app ships with a web admin panel, and Android/iOS come from a single codebase — you do not pay for two apps.']],
                    ['h' => 'The backend is the real product',
                     'p' => ['An app without a live backend is just a shell. Yours runs on ServerNet infrastructure with monitoring and backups — and if your user base grows tenfold, scaling is one phone call, not a crisis. We also handle publishing on Myket, Café Bazaar, Google Play and the App Store.']],
                ],
                'tr' => [
                    ['h' => 'Uygulama mı, web uygulaması mı? Dürüstçe söyleriz',
                     'p' => ['Birçok işletme, kurulabilir hızlı bir web uygulaması aynı işi çok daha ucuza görecekken native uygulamaya para öder. Uygulama; bildirim, çevrimdışı kullanım, cihaz donanımı veya her gün geri dönen müşteriler gerektiğinde anlamlıdır.']],
                    ['h' => 'Neler geliştiriyoruz',
                     'list' => ['Mağaza uygulamaları — katalog, sepet, ödeme, kampanyalar', 'Sipariş takipli hizmet ve randevu uygulamaları', 'Kurumsal uygulamalar: iç sistemlerinizin mobil yüzü', 'Saha satış ekipleri için dağıtım uygulamaları'],
                     'p' => ['Her uygulama web yönetim paneliyle teslim edilir; Android/iOS tek kod tabanından çıkar — iki uygulama parası ödemezsiniz.']],
                    ['h' => 'Asıl ürün backend’dir',
                     'p' => ['Backend’i olmayan uygulama sadece bir kabuktur. Sizinki ServerNet altyapısında, izleme ve yedeklerle çalışır — kullanıcılarınız on katına çıkarsa ölçekleme bir telefon kadar yakındır, kriz değildir.']],
                ],
            ],
            'faq' => [
                'en' => [
                    ['q' => 'What do I need to get started?', 'a' => 'Just a clear description of your business and what users should do in the app. Everything else moves step by step with us.'],
                    ['q' => 'Do you build for iOS too?', 'a' => 'Yes — cross-platform development produces Android and iOS from one codebase.'],
                    ['q' => 'Who handles updates after launch?', 'a' => 'An annual support contract covers bug fixes and OS compatibility; new features are quoted separately.'],
                ],
                'tr' => [
                    ['q' => 'Başlamak için ne gerekir?', 'a' => 'İşinizin ve kullanıcının uygulamada ne yapacağının net bir tarifi yeter. Gerisi birlikte adım adım ilerler.'],
                    ['q' => 'iOS için de geliştiriyor musunuz?', 'a' => 'Evet — çapraz platform geliştirmeyle Android ve iOS tek kod tabanından çıkar.'],
                    ['q' => 'Yayın sonrası güncellemeler kimin sorumluluğunda?', 'a' => 'Yıllık destek sözleşmesi hata düzeltme ve işletim sistemi uyumluluğunu kapsar; yeni özellikler ayrıca fiyatlanır.'],
                ],
            ],
        ],

        'erp' => [
            'title' => ['en' => 'ERP in Urmia | Sales, Inventory & Finance in One System — ServerNet', 'tr' => 'Urmiye’de ERP | Satış, Stok ve Finans Tek Sistemde — ServerNet'],
            'desc'  => ['en' => 'ERP deployment in Urmia for industry and trade: unify sales, inventory, production and finance. Analysis, deployment and training on site.', 'tr' => 'Urmiye’de sanayi ve ticaret için ERP kurulumu: satış, stok, üretim ve finansı tek sistemde birleştirin.'],
            'h1'    => ['en' => 'ERP in Urmia — when spreadsheets stop working', 'tr' => 'Urmiye’de ERP — tablolar artık yetmediğinde'],
            'lead'  => ['en' => 'The sign you need ERP is simple: sales in one file, stock in another, accounts in a third — and none of them agree. ERP turns those islands into one continent, sized for real businesses in West Azerbaijan.', 'tr' => 'ERP ihtiyacının işareti basittir: satış bir dosyada, stok başka dosyada, hesaplar üçüncüsünde — ve hiçbiri birbirini tutmaz. ERP bu adaları tek bir kıtaya dönüştürür.'],
            'sections' => [
                'en' => [
                    ['h' => 'ERP for mid-size companies, not just giants',
                     'p' => ['A fifty-person factory or a trading company with three warehouses has the same pains as a corporation, at a smaller scale — and deserves a solution at that scale. We deploy modularly: start with the most painful area (usually sales and inventory), then add modules once each one settles in. Your organisation never has to change everything overnight.']],
                    ['h' => 'Modules',
                     'list' => ['Sales & customers — quotes, invoices, collections, full history', 'Inventory — live multi-warehouse stock, reorder points, stocktaking', 'Production — bill of materials, planning, cost price', 'Finance — accounting integration and per-product profit', 'Dashboards — today’s sales, critical stock, overdue receivables']],
                    ['h' => 'On-site deployment, your data under your control',
                     'p' => ['We document your real processes at your site in Urmia, adapt the system to them, and stay beside your staff until it sticks. ERP data — cost prices, margins, customer lists — is your most sensitive asset; it runs on a dedicated server in Iran or on your own hardware.']],
                ],
                'tr' => [
                    ['h' => 'Sadece devler için değil, orta ölçek için ERP',
                     'p' => ['Elli kişilik bir fabrika veya üç depolu bir ticaret firması, bir holdingin sancılarını küçük ölçekte yaşar — ve o ölçekte bir çözümü hak eder. Modüler kurarız: en sancılı alandan (genelde satış ve stok) başlar, her modül oturdukça yenisini ekleriz. Kurumunuz hiçbir zaman her şeyi bir gecede değiştirmek zorunda kalmaz.']],
                    ['h' => 'Modüller',
                     'list' => ['Satış ve müşteriler — teklif, fatura, tahsilat, tam geçmiş', 'Stok — çok depolu canlı stok, sipariş noktaları, sayım', 'Üretim — reçete (BOM), planlama, maliyet', 'Finans — muhasebe entegrasyonu, ürün bazlı kâr', 'Paneller — günün satışı, kritik stok, geciken alacaklar']],
                    ['h' => 'Yerinde kurulum, veri sizin kontrolünüzde',
                     'p' => ['Gerçek süreçlerinizi Urmiye’de yerinde belgeler, sistemi onlara uyarlar ve oturana kadar personelinizin yanında kalırız. ERP verisi — maliyetler, marjlar, müşteri listeleri — en hassas varlığınızdır; İran’daki özel bir sunucuda veya kendi donanımınızda çalışır.']],
                ],
            ],
            'faq' => [
                'en' => [
                    ['q' => 'How long does ERP deployment take?', 'a' => 'The first module (usually sales + inventory) takes 1–3 months; later modules go much faster.'],
                    ['q' => 'What happens to our current accounting software?', 'a' => 'It usually stays: the ERP exchanges data with it, so your accountant keeps their tools and double entry disappears.'],
                    ['q' => 'At what size does ERP make sense?', 'a' => 'A practical rule: more than one warehouse, or more than two people entering sales, and month-end numbers that never reconcile.'],
                ],
                'tr' => [
                    ['q' => 'ERP kurulumu ne kadar sürer?', 'a' => 'İlk modül (genelde satış + stok) 1–3 ay sürer; sonraki modüller çok daha hızlıdır.'],
                    ['q' => 'Mevcut muhasebe yazılımımız ne olacak?', 'a' => 'Genelde kalır: ERP onunla veri alışverişi yapar; muhasebeciniz aracını değiştirmez ve çift kayıt ortadan kalkar.'],
                    ['q' => 'ERP hangi ölçekte mantıklı?', 'a' => 'Pratik kural: birden fazla depo veya satışa veri giren ikiden fazla kişi varsa ve ay sonu rakamları bir türlü tutmuyorsa.'],
                ],
            ],
        ],

        'seo' => [
            'title' => ['en' => 'SEO in Urmia | First Page of Google for the Local Market — ServerNet', 'tr' => 'Urmiye’de SEO | Yerel Pazar için Google’da İlk Sayfa — ServerNet'],
            'desc'  => ['en' => 'SEO services in Urmia: local SEO for West Azerbaijan businesses, technical SEO, content and transparent monthly reporting — by a team that ranks with its own methods.', 'tr' => 'Urmiye’de SEO hizmetleri: yerel SEO, teknik SEO, içerik ve şeffaf aylık raporlama.'],
            'h1'    => ['en' => 'SEO in Urmia', 'tr' => 'Urmiye’de SEO'],
            'lead'  => ['en' => 'When a customer in Urmia searches for “price of X in Urmia”, they either find you or your competitor. SEO is the engineering of that moment — and we practise it with the same methods that rank our own site.', 'tr' => 'Urmiye’de bir müşteri “Urmiye’de X fiyatı” diye arattığında ya sizi bulur ya rakibinizi. SEO o anın mühendisliğidir — ve biz onu kendi sitemizi sıralayan yöntemlerle yaparız.'],
            'sections' => [
                'en' => [
                    ['h' => 'Local SEO is a different game',
                     'p' => ['Competing nationally for “web design” is brutal; competing for “web design in Urmia” is winnable — most competitors never built proper pages for it. Local SEO means finding those opportunities and building a real page for each service and each target city, not a hundred copies with the city name swapped.']],
                    ['h' => 'Our four pillars',
                     'list' => ['Technical SEO — speed, structured data, clean URLs, crawl health', 'Content that answers real customer questions', 'Local authority — credible profiles and natural links', 'Measurement — monthly rank/traffic/lead reports, with honest course changes']],
                    ['h' => 'Realistic expectations',
                     'p' => ['Anyone promising first-page results within a month is guessing or bluffing. SEO shows its first signals in months 2–3 and stable results by months 4–6. Our contract is month-to-month, with no long lock-in.']],
                ],
                'tr' => [
                    ['h' => 'Yerel SEO farklı bir oyundur',
                     'p' => ['Ülke çapında “web tasarım” için yarışmak acımasızdır; “Urmiye’de web tasarım” için yarışmak kazanılabilir — çoğu rakip bunun için doğru dürüst sayfa bile yapmamıştır. Yerel SEO bu fırsatları bulmak ve her hizmet ile hedef şehir için gerçek bir sayfa kurmaktır; şehir adı değiştirilmiş yüz kopya değil.']],
                    ['h' => 'Dört sütunumuz',
                     'list' => ['Teknik SEO — hız, yapılandırılmış veri, temiz URL’ler', 'Müşterinin gerçek sorularına cevap veren içerik', 'Yerel otorite — güvenilir profiller ve doğal bağlantılar', 'Ölçüm — aylık sıralama/trafik raporları ve dürüst rota değişiklikleri']],
                    ['h' => 'Gerçekçi beklentiler',
                     'p' => ['Bir ay içinde ilk sayfa sözü veren ya tahmin ediyordur ya blöf yapıyordur. SEO ilk sinyallerini 2–3. ayda, kalıcı sonuçlarını 4–6. ayda verir. Sözleşmemiz aydan ayadır; uzun bağlayıcılık yoktur.']],
                ],
            ],
            'faq' => [
                'en' => [
                    ['q' => 'How much does SEO cost in Urmia?', 'a' => 'Depending on competition and scope it starts from a few million IRT per month; after a free site review you get an exact figure and a three-month plan.'],
                    ['q' => 'Do you guarantee rankings?', 'a' => 'No honest agency can — Google is nobody’s to promise. What we guarantee is transparent work and real reporting, and we show our track record.'],
                    ['q' => 'My site was built elsewhere. Can you do its SEO?', 'a' => 'Yes, after a technical audit. If the structure blocks SEO, we tell you the fix cost honestly first.'],
                ],
                'tr' => [
                    ['q' => 'Urmiye’de SEO ne kadar?', 'a' => 'Rekabete ve kapsama göre ayda birkaç milyon IRT’den başlar; ücretsiz site incelemesinden sonra net rakam ve üç aylık plan verilir.'],
                    ['q' => 'Sıralama garantisi veriyor musunuz?', 'a' => 'Dürüst hiçbir ajans veremez — Google kimsenin sözü değildir. Garantimiz şeffaf çalışma ve gerçek raporlamadır.'],
                    ['q' => 'Sitemi başkası yaptı; SEO’sunu üstlenir misiniz?', 'a' => 'Evet, teknik denetimden sonra. Yapı SEO’ya engelse düzeltme maliyetini önce dürüstçe söyleriz.'],
                ],
            ],
        ],

        'corporate-website' => [
            'title' => ['en' => 'Corporate Website Design in Urmia | For Serious Companies — ServerNet', 'tr' => 'Urmiye’de Kurumsal Web Sitesi | Ciddi Şirketler İçin — ServerNet'],
            'desc'  => ['en' => 'Corporate website design in Urmia for manufacturers, traders and service firms: professional structure, bilingual for export, credentials and reliable hosting.', 'tr' => 'Urmiye’de üretici, tüccar ve hizmet firmaları için kurumsal web sitesi: profesyonel yapı, ihracat için çok dillilik ve güvenilir hosting.'],
            'h1'    => ['en' => 'Corporate Website Design in Urmia', 'tr' => 'Urmiye’de Kurumsal Web Sitesi Tasarımı'],
            'lead'  => ['en' => 'A corporate website is not a place for creative games — it is a place for building trust. A B2B customer, a bank, a foreign partner or a tender officer decides in thirty seconds whether to take you seriously. A good corporate site wins those thirty seconds.', 'tr' => 'Kurumsal site yaratıcılık oyunlarının değil, güven inşasının yeridir. Bir B2B müşteri, banka, yabancı ortak ya da ihale yetkilisi sizi otuz saniyede tartar. İyi bir kurumsal site o otuz saniyeyi kazanır.'],
            'sections' => [
                'en' => [
                    ['h' => 'What makes a corporate site “serious”',
                     'p' => ['A clear structure — who you are, what you do, how to reach you, in two clicks. A formal identity: registration numbers, licences, certificates, a real address. And considered design in your corporate colours, not a template seen on ten other local sites. We complete this with flawless mobile rendering, speed, and organisation schema for Google.']],
                    ['h' => 'For exporters: bilingual and trilingual sites',
                     'p' => ['West Azerbaijan is a border province; its serious companies trade with Türkiye and Iraq. Your corporate site can speak Persian, Turkish and English from day one — with proper business translation — and be hosted on our European servers so it loads fast for foreign partners. For a foreign partner, your website is your company’s first credential.']],
                    ['h' => 'We also write the content',
                     'p' => ['Most corporate site projects stall at “About us”. Our content team interviews you once and writes the official texts — history, production lines, certificates, flagship projects. You only approve.']],
                ],
                'tr' => [
                    ['h' => 'Bir kurumsal siteyi “ciddi” yapan nedir',
                     'p' => ['Net bir yapı — kim olduğunuz, ne yaptığınız ve size iki tıkta ulaşım. Resmî kimlik: sicil numaraları, lisanslar, sertifikalar, gerçek bir adres. Ve on başka yerel sitede görülen bir şablon değil, kurumsal renklerinizde özenli bir tasarım. Bunları kusursuz mobil görünüm, hız ve Google için organizasyon şemasıyla tamamlarız.']],
                    ['h' => 'İhracatçılar için: çok dilli siteler',
                     'p' => ['Batı Azerbaycan bir sınır ilidir; ciddi şirketleri Türkiye ve Irak ile ticaret yapar. Siteniz ilk günden Farsça, Türkçe ve İngilizce konuşabilir — düzgün ticari çeviriyle — ve Avrupa sunucularımızda barındırılarak yabancı ortaklarınız için hızlı açılır. Yabancı bir ortak için web siteniz şirketinizin ilk referansıdır.']],
                    ['h' => 'İçeriği de biz yazarız',
                     'p' => ['Kurumsal site projeleri çoğu zaman “Hakkımızda” metninde tıkanır. İçerik ekibimiz sizinle bir görüşme yapar ve resmî metinleri yazar — tarihçe, üretim hatları, sertifikalar, önemli projeler. Siz yalnızca onaylarsınız.']],
                ],
            ],
            'faq' => [
                'en' => [
                    ['q' => 'How many pages, and what does it cost?', 'a' => 'Typically 8–20 pages: home, about, products/services, projects, certificates, contact. From about 25M IRT; multilingual versions are quoted separately.'],
                    ['q' => 'Can you keep our current domain and rankings?', 'a' => 'Yes — we preserve your domain and set up proper redirects so existing Google rankings carry over. That kind of migration is our specialty.'],
                    ['q' => 'Do you do logos and photography too?', 'a' => 'Yes — logo and identity work with our designers, and industrial photography can be arranged for Urmia and nearby.'],
                ],
                'tr' => [
                    ['q' => 'Kaç sayfa olur, maliyeti nedir?', 'a' => 'Tipik olarak 8–20 sayfa: ana sayfa, hakkımızda, ürünler/hizmetler, projeler, sertifikalar, iletişim. Yaklaşık 25M IRT’den başlar; çok dilli sürümler ayrıca fiyatlanır.'],
                    ['q' => 'Mevcut alan adımız ve sıralamalarımız korunur mu?', 'a' => 'Evet — alan adınızı korur, doğru yönlendirmelerle mevcut Google sıralamalarınızı taşırız. Bu tür taşıma bizim uzmanlığımızdır.'],
                    ['q' => 'Logo ve fotoğraf çekimi de yapıyor musunuz?', 'a' => 'Evet; tasarımcılarımızla logo/kimlik çalışması ve Urmiye çevresi için endüstriyel fotoğraf çekimi ayarlanabilir.'],
                ],
            ],
        ],

        'ecommerce-website' => [
            'title' => ['en' => 'E-commerce Website in Urmia | A Real Online Store — ServerNet', 'tr' => 'Urmiye’de E-Ticaret Sitesi | Gerçek Bir Online Mağaza — ServerNet'],
            'desc'  => ['en' => 'Online store design in Urmia: payment gateway, inventory, shipping and trust seal — hosted on ServerNet’s own infrastructure so it survives your busiest campaign.', 'tr' => 'Urmiye’de online mağaza tasarımı: ödeme, stok, kargo — en yoğun kampanyanızda bile ayakta kalan altyapıda.'],
            'h1'    => ['en' => 'E-commerce Website Design in Urmia', 'tr' => 'Urmiye’de E-Ticaret Sitesi Tasarımı'],
            'lead'  => ['en' => 'An online store is not a website — it is a sales unit that must work every day: take orders, take payments, track stock, ship. We build it like a business unit, not a showcase.', 'tr' => 'Online mağaza bir web sitesi değil, her gün çalışması gereken bir satış birimidir: sipariş alır, tahsilat yapar, stok düşer, kargolar. Biz onu vitrin gibi değil, iş birimi gibi kurarız.'],
            'sections' => [
                'en' => [
                    ['h' => 'Everything a store needs, from day one',
                     'list' => ['Direct bank payment gateway with daily settlement', 'Automatic inventory with low-stock alerts and multi-warehouse support', 'Shipping: city courier rates for Urmia, post for the rest of Iran', 'Trust-seal (Enamad) guidance until approval', 'Discount codes, campaigns and a simple admin panel']],
                    ['h' => 'Hosting: where stores die or sell',
                     'p' => ['A store on cheap shared hosting does not survive its first serious campaign: you advertise, traffic arrives, the site crawls, and paying customers abandon their carts. Our stores run on ServerNet infrastructure and are load-tested before launch. Iranian hosting keeps the store fast and reachable for local customers even during international disruptions.']],
                    ['h' => 'Organic sales: customers who come by themselves',
                     'p' => ['Ads stop selling the moment you stop paying; SEO is an asset. We build product and category pages for search from the ground up — rich product schema and a clean structure that stays clean at a thousand products.']],
                ],
                'tr' => [
                    ['h' => 'Bir mağazanın ihtiyacı olan her şey, ilk günden',
                     'list' => ['Günlük mutabakatlı doğrudan banka ödeme entegrasyonu', 'Düşük stok uyarılı, çok depolu otomatik envanter', 'Kargo: Urmiye için kurye, İran geneli için posta', 'Güven damgası sürecinde onaya kadar rehberlik', 'İndirim kodları, kampanyalar ve sade bir yönetim paneli']],
                    ['h' => 'Hosting: mağazaların öldüğü ya da sattığı yer',
                     'p' => ['Ucuz paylaşımlı hosting’deki bir mağaza ilk ciddi kampanyasını atlatamaz: reklam verirsiniz, trafik gelir, site yavaşlar ve ödeme yapacak müşteri sepetini bırakır. Mağazalarımız ServerNet altyapısında çalışır ve yayına almadan önce yük testinden geçer.']],
                    ['h' => 'Organik satış: kendiliğinden gelen müşteri',
                     'p' => ['Reklam, ödemeyi kestiğiniz an satmayı bırakır; SEO ise bir varlıktır. Ürün ve kategori sayfalarını temelden arama için kurarız — zengin ürün şeması ve bin üründe bile temiz kalan bir yapı.']],
                ],
            ],
            'faq' => [
                'en' => [
                    ['q' => 'What does an online store cost?', 'a' => 'From about 40M IRT for a standard store; special integrations (accounting, warehouse, app) are quoted transparently before contract.'],
                    ['q' => 'Who enters the products?', 'a' => 'Initial entry is agreed either way; you get training, and bulk import from Excel is available for large catalogues.'],
                    ['q' => 'What about marketplaces?', 'a' => 'Marketplaces are a complementary channel: they take commission and own the customer. Your own store means full margin and customer ownership — smart sellers run both.'],
                ],
                'tr' => [
                    ['q' => 'Online mağaza ne kadara mal olur?', 'a' => 'Standart bir mağaza yaklaşık 40M IRT’den başlar; özel entegrasyonlar sözleşmeden önce şeffaf biçimde fiyatlanır.'],
                    ['q' => 'Ürünleri kim girer?', 'a' => 'İlk giriş anlaşmaya göre yapılır; eğitim verilir ve büyük kataloglar için Excel’den toplu aktarım vardır.'],
                    ['q' => 'Pazaryerleri yerine geçer mi?', 'a' => 'Pazaryerleri tamamlayıcı bir kanaldır: komisyon alır ve müşteriyi sahiplenir. Kendi mağazanız tam marj ve müşteri sahipliği demektir — akıllı satıcılar ikisini birden yürütür.'],
                ],
            ],
        ],

        'portfolio' => [
            'title' => ['en' => 'Web Design Portfolio in Urmia | ServerNet Projects', 'tr' => 'Urmiye Web Tasarım Referansları | ServerNet Projeleri'],
            'desc'  => ['en' => 'ServerNet’s web design portfolio in Urmia and West Azerbaijan: corporate, e-commerce and organisational projects — fifteen years of real work for real local businesses.', 'tr' => 'ServerNet’in Urmiye ve Batı Azerbaycan referansları: kurumsal, e-ticaret ve kurum projeleri — yerel işletmeler için on beş yıllık gerçek işler.'],
            'h1'    => ['en' => 'Our Web Design Portfolio in Urmia', 'tr' => 'Urmiye’deki Referans Projelerimiz'],
            'lead'  => ['en' => 'Before you compare prices, compare portfolios — and not just screenshots: open the live sites, feel the speed, and if you can, talk to the owners. Our portfolio is real projects for real businesses in this province.', 'tr' => 'Fiyat karşılaştırmadan önce referans karşılaştırın — ve sadece ekran görüntüsü değil: canlı siteleri açın, hızını hissedin, mümkünse sahipleriyle konuşun. Referanslarımız bu ildeki gerçek işletmeler için gerçek projelerdir.'],
            'sections' => [
                'en' => [
                    ['h' => 'Why a local portfolio means more',
                     'p' => ['Anyone can paste pretty screenshots. What cannot be faked is a local client: the shop owner or factory manager is in this city, and you can ask them directly how the collaboration went. In a face-to-face meeting we show live projects relevant to your field — with their owners’ permission, including real traffic numbers.']],
                    ['h' => 'Where we have delivered',
                     'list' => ['Corporate sites for manufacturers and traders across the province', 'Retail and distribution e-commerce', 'Organisational portals with internal users', 'Medical, education, legal and tourism services', 'Multilingual projects for exporters to Türkiye and Iraq']],
                    ['h' => 'Our real success metric',
                     'p' => ['A beautiful site that gets abandoned within six months is a failure. Our pride is projects that keep running and owners who are still our clients years later — client retention is the most honest portfolio we have.']],
                ],
                'tr' => [
                    ['h' => 'Yerel referans neden daha değerlidir',
                     'p' => ['Güzel ekran görüntülerini herkes yapıştırabilir. Taklit edilemeyen şey yerel müşteridir: o dükkânın sahibi veya fabrika müdürü bu şehirdedir ve iş birliğinin nasıl geçtiğini doğrudan sorabilirsiniz. Yüz yüze görüşmede, alanınızla ilgili canlı projeleri — sahiplerinin izniyle, gerçek trafik rakamlarıyla — gösteririz.']],
                    ['h' => 'Teslim ettiğimiz alanlar',
                     'list' => ['İl genelinde üreticiler ve tüccarlar için kurumsal siteler', 'Perakende ve dağıtım e-ticareti', 'İç kullanıcılı kurum portalları', 'Sağlık, eğitim, hukuk ve turizm hizmetleri', 'Türkiye ve Irak’a ihracat yapanlar için çok dilli projeler']],
                    ['h' => 'Gerçek başarı ölçütümüz',
                     'p' => ['Altı ay içinde terk edilen güzel bir site başarısızlıktır. Gururumuz, yıllardır çalışmaya devam eden projeler ve hâlâ müşterimiz olan sahipleridir — müşteri sürekliliği elimizdeki en dürüst referanstır.']],
                ],
            ],
            'faq' => [
                'en' => [
                    ['q' => 'Why isn’t the whole portfolio public?', 'a' => 'Some corporate clients prefer not to be shown publicly; we respect that and share relevant examples in meetings, with permission.'],
                    ['q' => 'Can I talk to your previous clients?', 'a' => 'Yes — for serious projects we provide references; a few short calls beat pages of advertising.'],
                    ['q' => 'You have no sample in my field. Is that a problem?', 'a' => 'Sales and trust patterns transfer across fields; what is specific is your business knowledge, and that starts in the first meeting.'],
                ],
                'tr' => [
                    ['q' => 'Neden tüm referanslar herkese açık değil?', 'a' => 'Bazı kurumsal müşteriler kamuya gösterilmek istemiyor; buna saygı duyuyor, ilgili örnekleri görüşmelerde izinle paylaşıyoruz.'],
                    ['q' => 'Eski müşterilerinizle konuşabilir miyim?', 'a' => 'Evet — ciddi projelerde referans veriyoruz; birkaç kısa telefon görüşmesi sayfalarca reklamdan iyidir.'],
                    ['q' => 'Benim alanımda örneğiniz yok. Bu sorun mu?', 'a' => 'Satış ve güven kalıpları alanlar arasında taşınır; işinize özgü bilgi ise ilk görüşmede başlar.'],
                ],
            ],
        ],

        'support' => [
            'title' => ['en' => 'Website Maintenance in Urmia | Security, Backups, Updates — ServerNet', 'tr' => 'Urmiye’de Web Sitesi Bakımı | Güvenlik, Yedek, Güncelleme — ServerNet'],
            'desc'  => ['en' => 'Website support and maintenance in Urmia: monitoring, backups, security and fixes with real response times — for sites built by ServerNet or anyone else.', 'tr' => 'Urmiye’de web sitesi destek ve bakımı: izleme, yedekleme, güvenlik ve gerçek yanıt süreleriyle onarım.'],
            'h1'    => ['en' => 'Website Support in Urmia', 'tr' => 'Urmiye’de Web Sitesi Desteği'],
            'lead'  => ['en' => 'A website is like a shop: building it happens once, keeping it open is daily work. Support means someone makes sure your site is up, secure and backed up — and answers, in this city, when something breaks.', 'tr' => 'Bir web sitesi dükkân gibidir: kurmak bir keredir, açık tutmak her günün işidir. Destek; sitenizin ayakta, güvenli ve yedekli olması ve bir şey bozulduğunda bu şehirde birinin cevap vermesi demektir.'],
            'sections' => [
                'en' => [
                    ['h' => 'What our support covers',
                     'list' => ['24/7 uptime monitoring — we usually know before you do', 'Daily off-site backups', 'Core and plugin security updates, SSL, intrusion watch', 'Fault fixing with clear priorities — from payment errors to white screens', 'A monthly quota of content changes', 'A monthly report: what happened, what we did']],
                    ['h' => 'We take over sites built by anyone',
                     'p' => ['Maybe your builder stopped answering. Our takeover routine is well-worn: collect access, run a technical and security audit, make a full backup, report the state — then you decide between ongoing support or a one-off rescue. Migration to ServerNet hosting is free and is usually the first visible improvement.']],
                    ['h' => 'Clear pricing, no lock-in',
                     'p' => ['Three simple monthly tiers based on how critical the site is and how many changes you need. No forced long-term contract: any month you are unhappy, you leave with all your access and backups. The customer who can leave and does not — that is our retention strategy.']],
                ],
                'tr' => [
                    ['h' => 'Desteğimiz neleri kapsar',
                     'list' => ['7/24 erişilebilirlik izleme — genelde sizden önce haberimiz olur', 'Günlük harici yedekleme', 'Çekirdek ve eklenti güvenlik güncellemeleri, SSL, saldırı izleme', 'Net önceliklerle arıza giderme — ödeme hatasından beyaz ekrana', 'Aylık içerik değişikliği kotası', 'Aylık rapor: ne oldu, ne yaptık']],
                    ['h' => 'Kim yapmış olursa olsun devralırız',
                     'p' => ['Belki sitenizi yapan artık cevap vermiyor. Devralma rutinimiz oturmuştur: erişimleri toplarız, teknik ve güvenlik denetimi yaparız, tam yedek alırız ve durumu raporlarız — sonra sürekli destek mi tek seferlik kurtarma mı, siz karar verirsiniz. ServerNet hosting’e taşıma ücretsizdir.']],
                    ['h' => 'Net fiyat, bağımlılık yok',
                     'p' => ['Sitenin kritikliğine ve değişiklik hacmine göre üç sade aylık paket. Zorunlu uzun vadeli sözleşme yok: memnun olmadığınız ay, tüm erişimleriniz ve yedeklerinizle ayrılırsınız. Ayrılabilecekken ayrılmayan müşteri — bizim kalıcılık stratejimiz budur.']],
                ],
            ],
            'faq' => [
                'en' => [
                    ['q' => 'Do you support WordPress sites?', 'a' => 'Yes — WordPress is the most common system we take over: secure updates, malware cleanup and speed optimisation.'],
                    ['q' => 'My site was hacked. Can you help?', 'a' => 'Yes, cleanup and recovery is routine work for us: we close the breach, clean the code and tell you how it got in.'],
                    ['q' => 'What is the minimum commitment?', 'a' => 'One month. Renewal is month-to-month and optional.'],
                ],
                'tr' => [
                    ['q' => 'WordPress sitelere destek veriyor musunuz?', 'a' => 'Evet — en çok devraldığımız sistem WordPress’tir: güvenli güncelleme, zararlı yazılım temizliği ve hız optimizasyonu.'],
                    ['q' => 'Sitem hacklendi. Yardım eder misiniz?', 'a' => 'Evet, temizlik ve kurtarma bizim için rutindir: açığı kapatır, kodu temizler ve içeri nereden girildiğini söyleriz.'],
                    ['q' => 'En kısa taahhüt süresi nedir?', 'a' => 'Bir ay. Yenileme aydan ayadır ve isteğe bağlıdır.'],
                ],
            ],
        ],

    ],
];
