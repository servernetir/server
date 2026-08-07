<?php

/*
|--------------------------------------------------------------------------
| صفحهٔ شخصیِ «طراحی سایت و زیرساخت» — /webdesign
|--------------------------------------------------------------------------
|
| ⚠️ این صفحه عمداً **در منوی اصلی نیست**. مقصدِ لینکِ پروفایلِ لینکدین و
| اینستاگرام است و برای جذبِ مشتریِ مستقیم نوشته شده، نه برای بازدیدکنندهٔ
| عمومیِ سایت. پس لحنش اول‌شخص و شخصی است، برخلافِ بقیهٔ سایت که شرکتی است.
|
| ⚠️ سئوی محلیِ **ارومیه** عمدی است: «طراحی سایت در ارومیه»، «سرور در ارومیه»
| و «طراحی فرایند در ارومیه» کلیدواژه‌های هدف‌اند. برای همین شهر هم در متنِ
| فارسی می‌آید، هم در `areaServed` دادهٔ ساختاریافته — نه فقط در تگِ عنوان،
| چون کلیدواژه‌ای که در متنِ صفحه نباشد رتبه نمی‌گیرد.
|
| ⚠️ در نقشهٔ سایت هست ولی در منو نه — دو چیزِ متفاوت. حذفش از نقشهٔ سایت
| یعنی گوگل دیرتر پیدایش می‌کند، در حالی که کلِ هدفِ صفحه ورودیِ ارگانیک است.
*/

return [
    'brand' => 'ServerNet Cloud',
    'person' => 'Ehsan Ebrahimi',
    'person_fa' => 'احسان ابراهیمی',
    'email' => 'ceo@servernet.cloud',
    'city' => ['fa' => 'ارومیه', 'en' => 'Urmia', 'tr' => 'Urmiye'],

    'meta' => [
        'title' => [
            'fa' => 'طراحی سایت در ارومیه و زیرساخت مدیریت‌شده — احسان ابراهیمی',
            'en' => 'Web Design & Managed Infrastructure — Ehsan Ebrahimi',
            'tr' => 'Web Tasarım ve Yönetilen Altyapı — Ehsan Ebrahimi',
        ],
        'desc' => [
            'fa' => 'طراحی سایت در ارومیه، سرور و میزبانی مدیریت‌شده، و طراحی فرایند سازمانی (BPMN و ERP). یک نفر مسئول سایت و زیرساختش — نه سه پیمانکار که تقصیر را گردن هم بیندازند.',
            'en' => 'Websites, managed cloud and business process design (BPMN, ERP) from one person who also runs the hosting company behind them.',
            'tr' => 'Web siteleri, yönetilen bulut ve süreç tasarımı (BPMN, ERP) — arkasındaki barındırma şirketini de işleten tek kişiden.',
        ],
    ],

    'hero' => [
        'badge' => ['fa' => 'پذیرای پروژهٔ جدید', 'en' => 'Available for new projects', 'tr' => 'Yeni projelere açığım'],
        'h1a' => ['fa' => 'سایت می‌سازم — و', 'en' => 'I build websites — and run the', 'tr' => 'Web siteleri kurarım — ve'],
        'h1b' => ['fa' => 'زیرساختش', 'en' => 'infrastructure', 'tr' => 'altyapısını'],
        'h1c' => ['fa' => 'را هم خودم می‌گردانم.', 'en' => 'behind them.', 'tr' => 'ben işletirim.'],
        'lead' => [
            'fa' => 'بیشترِ طراح‌ها یک صفحهٔ زیبا تحویل می‌دهند، رویِ ارزان‌ترین هاستِ اشتراکی می‌گذارند و می‌روند. من خودم شرکتِ هاستینگ را دارم؛ پس سرعت، پایداری، بکاپ و امنیت مسئلهٔ **من** است، نه پیمانکاری که شما را به او پاس بدهم.',
            'en' => 'Most web designers hand you a beautiful page, drop it on cheap shared hosting, and disappear. I own the hosting company. So speed, uptime, backups and security are my problem — not a vendor I hand you off to.',
            'tr' => 'Çoğu tasarımcı güzel bir sayfa verir, ucuz paylaşımlı hostinge koyar ve kaybolur. Hosting şirketini ben işletiyorum. Yani hız, çalışma süresi, yedekleme ve güvenlik benim sorunum.',
        ],
        'cta1' => ['fa' => 'بررسی رایگان سه‌نکته‌ای سایت', 'en' => 'Get a free 3-point site review', 'tr' => 'Ücretsiz 3 maddelik site incelemesi'],
        'cta2' => ['fa' => 'دیدن پکیج‌ها', 'en' => 'See packages', 'tr' => 'Paketleri gör'],
    ],

    'stats' => [
        ['n' => '15', 'fa' => 'سال گردانندهٔ شرکت هاستینگ', 'en' => 'years running a hosting company', 'tr' => 'yıl hosting şirketi yönetimi'],
        ['n' => '7', 'fa' => 'سال مدیر فناوری یک کارخانه', 'en' => 'years as CTO of a manufacturer', 'tr' => 'yıl üretici firmada CTO'],
        ['n' => '3', 'fa' => 'زبان: فارسی / انگلیسی / ترکی', 'en' => 'languages: EN / TR / FA', 'tr' => 'dil: TR / EN / FA'],
        ['n' => 'ISO', 'fa' => 'مستندسازی ۹۰۰۱ و ۲۷۰۰۱', 'en' => '9001 & 27001 documentation', 'tr' => '9001 ve 27001 dokümantasyonu'],
    ],

    'problem' => [
        'kicker' => ['fa' => 'مسئلهٔ واقعی', 'en' => 'The real problem', 'tr' => 'Asıl sorun'],
        'h2' => ['fa' => 'سایت‌ها به‌خاطر طراحی شکست نمی‌خورند', 'en' => 'Websites rarely fail because of design', 'tr' => 'Siteler tasarım yüzünden başarısız olmaz'],
        'lead' => [
            'fa' => 'به‌خاطر هر چیزی که **پشتِ** طراحی است شکست می‌خورند. چهار شکایتی که مدام می‌شنوم:',
            'en' => 'They fail because of everything behind the design. Four complaints I hear constantly:',
            'tr' => 'Tasarımın arkasındaki her şey yüzünden başarısız olurlar. Sürekli duyduğum dört şikâyet:',
        ],
        'items' => [
            [
                'q' => ['fa' => '«شش ثانیه طول می‌کشد تا باز شود»', 'en' => '"It takes six seconds to load"', 'tr' => '"Açılması altı saniye sürüyor"'],
                'a' => ['fa' => 'بازدیدکننده پیش از ظاهر شدنِ صفحه می‌رود. روی موبایل بدتر است.', 'en' => "Visitors leave before the page appears. On mobile it's worse.", 'tr' => 'Ziyaretçi sayfa görünmeden ayrılıyor. Mobilde daha kötü.'],
            ],
            [
                'q' => ['fa' => '«وسط کمپین از دسترس خارج شد»', 'en' => '"It went down during our campaign"', 'tr' => '"Kampanya sırasında çöktü"'],
                'a' => ['fa' => 'پولِ تبلیغ را دادید. ترافیک آمد. سایت نبود.', 'en' => "You paid for the ads. The traffic arrived. The site didn't.", 'tr' => 'Reklam parasını ödediniz. Trafik geldi. Site gelmedi.'],
            ],
            [
                'q' => ['fa' => '«کسی به سازندهٔ سایت دسترسی ندارد»', 'en' => '"Nobody can reach the person who built it"', 'tr' => '"Siteyi yapana kimse ulaşamıyor"'],
                'a' => ['fa' => 'فریلنسر رفته. حالا هیچ‌کس نمی‌داند این سایت چطور کار می‌کند.', 'en' => 'The freelancer moved on. Now nobody knows how it works.', 'tr' => 'Freelancer gitti. Artık kimse nasıl çalıştığını bilmiyor.'],
            ],
            [
                'q' => ['fa' => '«هک شدیم و بکاپی نبود»', 'en' => '"We got hacked and there was no backup"', 'tr' => '"Hacklendik ve yedek yoktu"'],
                'a' => ['fa' => 'بکاپ کارِ یک نفری بود. بعد معلوم شد کارِ هیچ‌کس بوده.', 'en' => "Backups were somebody's job. It turned out to be nobody's.", 'tr' => 'Yedekleme birinin işiydi. Meğer kimsenin işi değilmiş.'],
            ],
        ],
    ],

    'services' => [
        'kicker' => ['fa' => 'چه کاری می‌کنم', 'en' => 'What I do', 'tr' => 'Ne yapıyorum'],
        'h2' => ['fa' => 'یک نفر. یک شماره برای تماس.', 'en' => 'One person. One number to call.', 'tr' => 'Tek kişi. Aranacak tek numara.'],
        'lead' => [
            'fa' => 'نه سه پیمانکار که وقتِ خرابی تقصیر را گردنِ هم بیندازند.',
            'en' => 'No three vendors blaming each other when something breaks.',
            'tr' => 'Bir şey bozulduğunda birbirini suçlayan üç tedarikçi yok.',
        ],
        'items' => [
            [
                'icon' => 'i-code',
                't' => ['fa' => 'سایت و اپلیکیشن وب', 'en' => 'Websites & web apps', 'tr' => 'Web siteleri ve uygulamalar'],
                'd' => ['fa' => 'سایت شرکتی، سامانهٔ نوبت‌دهی، پورتال مشتری، پلتفرم اختصاصی. ساخته‌شده برای سرعت و جستجو. **طراحی سایت در ارومیه** و سراسر ایران.', 'en' => 'Corporate sites, booking and client portals, custom platforms. Built for speed and search.', 'tr' => 'Kurumsal siteler, randevu ve müşteri portalları, özel platformlar. Hız ve arama için kurulur.'],
            ],
            [
                'icon' => 'i-server',
                't' => ['fa' => 'سرور و فضای ابری', 'en' => 'Cloud & servers', 'tr' => 'Bulut ve sunucular'],
                'd' => ['fa' => 'ابر خصوصی، سرور مجازی، مهاجرت، سخت‌سازی، بکاپ خودکار و پایش پایداری. **سرور در ارومیه** یا هر مکانی که کسب‌وکارتان لازم دارد.', 'en' => 'Private cloud, VPS, migration, hardening, automated backup, uptime monitoring.', 'tr' => 'Özel bulut, VPS, taşıma, sıkılaştırma, otomatik yedekleme, çalışma süresi izleme.'],
            ],
            [
                'icon' => 'i-flow',
                't' => ['fa' => 'سامانه‌های سازمانی', 'en' => 'Business systems', 'tr' => 'İş sistemleri'],
                'd' => ['fa' => 'ERP، برنامه‌ریزی تولید، نگهداری و تعمیرات، داشبورد مدیریتی و **طراحی فرایند در ارومیه** با BPMN.', 'en' => 'ERP, production planning, maintenance, management dashboards, BPMN process automation.', 'tr' => 'ERP, üretim planlama, bakım, yönetim panelleri, BPMN süreç otomasyonu.'],
            ],
            [
                'icon' => 'i-message',
                't' => ['fa' => 'شبکه‌های اجتماعی', 'en' => 'Social media', 'tr' => 'Sosyal medya'],
                'd' => ['fa' => 'محتوا، مدیریت جامعه و کمپین تبلیغاتی — گره‌خورده به سایت، نه شناور و جدا از آن.', 'en' => 'Content, community management and paid campaigns — tied to the site, not floating on their own.', 'tr' => 'İçerik, topluluk yönetimi ve reklam kampanyaları — siteye bağlı, havada değil.'],
            ],
        ],
    ],

    'pricing' => [
        'kicker' => ['fa' => 'قیمت‌ها', 'en' => 'Pricing', 'tr' => 'Fiyatlar'],
        'h2' => ['fa' => 'قیمت روشن. بدون غافلگیری.', 'en' => 'Clear prices. No surprises.', 'tr' => 'Net fiyatlar. Sürpriz yok.'],
        'lead' => [
            'fa' => 'هر پکیجِ سایت **یک سال میزبانی مدیریت‌شده** دارد — چون ترجیح می‌دهم خودم درست میزبانی‌اش کنم تا اینکه جای دیگری خراب شدنش را تماشا کنم.',
            'en' => "Every website package includes one year of managed hosting — because I'd rather host it properly than watch it break somewhere else.",
            'tr' => 'Her web sitesi paketi bir yıl yönetilen hosting içerir — başka yerde bozulmasını izlemektense düzgün barındırmayı tercih ederim.',
        ],
        /*
        | 🔴 قیمتِ تومانی **تبدیلِ یورو نیست** — قیمتِ مستقلِ بازارِ ایران است.
        |
        | نرخِ زندهٔ سایت حدودِ ۲۱۸٬۰۰۰ تومان به ازای هر یوروست، یعنی €۱٬۴۰۰ می‌شود
        | ~۳۰۵ میلیون تومان. هیچ کسب‌وکارِ کوچکی در ارومیه آن را نمی‌پردازد، و
        | چون کلِ هدفِ این صفحه ورودیِ محلی است، تبدیلِ مستقیم دقیقاً همان هدف را
        | می‌کُشد. پس دو ستونِ قیمت داریم: `irt` برای مشتریِ ایرانی و `eur` برای
        | مشتریِ اروپا/ترکیه — هر کدام برای بازارِ خودش.
        |
        | ⚠️ عمداً از `site_price()` استفاده نمی‌شود: آن `price_factor()`ِ سراسریِ
        | هاست را ضرب می‌کند، و ضریبی که مدیر برای پکیج‌های میزبانی تنظیم می‌کند
        | نباید قیمتِ خدماتِ طراحی را جابه‌جا کند.
        |
        | ⚠️ به‌روزرسانی: این اعداد لنگرِ تومانی‌اند و با تورم کهنه می‌شوند.
        |    فقط همین چند خط را عوض کن — ویو خودش تخفیف را حساب می‌کند.
        */
        'discount_pct' => 20,
        'discount_badge' => ['fa' => '٪۲۰ تخفیف', 'en' => '20% off', 'tr' => '%20 indirim'],
        'discount_note' => [
            'fa' => 'تخفیفِ **راه‌اندازی** — برای پروژه‌هایی که تا پایانِ فصل ثبت شوند.',
            'en' => '**Launch discount** — for projects booked before the end of the quarter.',
            'tr' => '**Lansman indirimi** — çeyrek sonuna kadar rezerve edilen projeler için.',
        ],
        'plans' => [
            [
                'name' => ['fa' => 'شروع', 'en' => 'Starter', 'tr' => 'Başlangıç'],
                'price' => ['irt' => 48_000_000, 'eur' => 1400], 'popular' => false,
                'for' => ['fa' => 'کسب‌وکار کوچک، مطب یا کلینیک تک‌شعبه', 'en' => 'Small business, single-location clinic', 'tr' => 'Küçük işletme, tek şubeli klinik'],
                'features' => [
                    ['fa' => 'تا ۶ صفحه، یک زبان', 'en' => 'Up to 6 pages, one language', 'tr' => '6 sayfaya kadar, tek dil'],
                    ['fa' => 'طراحی اختصاصی و واکنش‌گرا', 'en' => 'Custom responsive design', 'tr' => 'Özel duyarlı tasarım'],
                    ['fa' => 'فرم تماس و سئوی پایه', 'en' => 'Contact form & basic SEO', 'tr' => 'İletişim formu ve temel SEO'],
                    ['fa' => 'Core Web Vitals سبز', 'en' => 'Green Core Web Vitals', 'tr' => 'Yeşil Core Web Vitals'],
                    ['fa' => 'یک سال میزبانی مدیریت‌شده', 'en' => '1 year managed hosting', 'tr' => '1 yıl yönetilen hosting'],
                ],
                'time' => ['fa' => '۲ تا ۳ هفته · ۳۰ روز پشتیبانی', 'en' => '2–3 weeks · 30 days support', 'tr' => '2–3 hafta · 30 gün destek'],
                'cta' => ['fa' => 'درخواست', 'en' => 'Enquire', 'tr' => 'Talep et'],
            ],
            [
                'name' => ['fa' => 'کسب‌وکار', 'en' => 'Business', 'tr' => 'İşletme'],
                'price' => ['irt' => 105_000_000, 'eur' => 2900], 'popular' => true,
                'for' => ['fa' => 'کلینیک چندشعبه، شرکت متوسط', 'en' => 'Multi-location clinic, mid-size company', 'tr' => 'Çok şubeli klinik, orta ölçekli şirket'],
                'features' => [
                    ['fa' => 'تا ۱۵ صفحه، ۲ تا ۳ زبان', 'en' => 'Up to 15 pages, 2–3 languages', 'tr' => '15 sayfaya kadar, 2–3 dil'],
                    ['fa' => 'سامانهٔ نوبت‌دهی یا فرم‌های پیشرفته', 'en' => 'Booking system or advanced forms', 'tr' => 'Randevu sistemi veya gelişmiş formlar'],
                    ['fa' => 'پنل مدیریت محتوا', 'en' => 'Content management panel', 'tr' => 'İçerik yönetim paneli'],
                    ['fa' => 'سئوی کامل و تحقیق کلیدواژه', 'en' => 'Full SEO & keyword research', 'tr' => 'Tam SEO ve anahtar kelime araştırması'],
                    ['fa' => 'آنالیتیکس و رصد نرخ تبدیل', 'en' => 'Analytics & conversion tracking', 'tr' => 'Analitik ve dönüşüm takibi'],
                    ['fa' => 'بکاپ خودکار روزانه', 'en' => 'Daily automated backups', 'tr' => 'Günlük otomatik yedekleme'],
                ],
                'time' => ['fa' => '۴ تا ۵ هفته · ۹۰ روز پشتیبانی', 'en' => '4–5 weeks · 90 days support', 'tr' => '4–5 hafta · 90 gün destek'],
                'cta' => ['fa' => 'درخواست', 'en' => 'Enquire', 'tr' => 'Talep et'],
            ],
            [
                'name' => ['fa' => 'اختصاصی', 'en' => 'Custom', 'tr' => 'Özel'],
                // `from` یعنی «شروع از» — سقفش بعد از جلسهٔ شناخت مشخص می‌شود
                'price' => ['irt' => 260_000_000, 'eur' => 6500, 'from' => true], 'popular' => false,
                'for' => ['fa' => 'پورتال، پلتفرم، ERP، زیرساخت', 'en' => 'Portals, platforms, ERP, infrastructure', 'tr' => 'Portallar, platformlar, ERP, altyapı'],
                'features' => [
                    ['fa' => 'پورتال مشتری و اپلیکیشن وب', 'en' => 'Client portals & web applications', 'tr' => 'Müşteri portalları ve web uygulamaları'],
                    ['fa' => 'پیاده‌سازی ERP و ProcessMaker', 'en' => 'ERP & ProcessMaker implementation', 'tr' => 'ERP ve ProcessMaker uygulaması'],
                    ['fa' => 'مستندسازی فرایند با BPMN', 'en' => 'BPMN process documentation', 'tr' => 'BPMN süreç dokümantasyonu'],
                    ['fa' => 'مهاجرت و سخت‌سازی سرور', 'en' => 'Server migration & hardening', 'tr' => 'Sunucu taşıma ve sıkılaştırma'],
                    ['fa' => 'همکاری به‌عنوان مدیر فناوری پاره‌وقت', 'en' => 'Fractional CTO engagements', 'tr' => 'Yarı zamanlı CTO iş birlikleri'],
                ],
                'time' => ['fa' => 'قیمت ثابت پس از جلسهٔ شناخت', 'en' => 'Fixed price after a discovery call', 'tr' => 'Keşif görüşmesinden sonra sabit fiyat'],
                'cta' => ['fa' => 'رزرو جلسهٔ شناخت', 'en' => 'Book a discovery call', 'tr' => 'Keşif görüşmesi ayarla'],
            ],
        ],
        /*
        | ⚠️ عددها این‌جا هم داده‌اند نه متن، تا **همان** تخفیفِ بالا رویشان بخورد.
        |    اگر در جمله سخت‌کد می‌شدند، روزی که `discount_pct` عوض شود این خط
        |    بی‌صدا قیمتِ قدیمی را نشان می‌داد — و مشتری قیمتِ کارت را با قیمتِ
        |    این خط مقایسه می‌کند.
        */
        'care' => [
            'hosting' => ['irt' => 3_500_000, 'eur' => 90],
            'social' => ['irt' => 18_000_000, 'eur' => 650],
            'text' => [
                'fa' => '**نگهداری ماهانه** از {a} در ماه — میزبانی، SSL، بکاپ روزانه، به‌روزرسانی امنیتی و پایش پایداری. **مدیریت شبکه‌های اجتماعی** از {b} در ماه.',
                'en' => '**Ongoing care** from {a}/month — hosting, SSL, daily backups, security updates and uptime monitoring. **Social media management** from {b}/month.',
                'tr' => '**Sürekli bakım** aylık {a}’dan — hosting, SSL, günlük yedekleme, güvenlik güncellemeleri ve izleme. **Sosyal medya yönetimi** aylık {b}’den.',
            ],
        ],
    ],

    'background' => [
        'kicker' => ['fa' => 'سابقه', 'en' => 'Background', 'tr' => 'Geçmiş'],
        'h2' => ['fa' => 'فناوریِ کارخانه را از کتاب یاد نگرفتم', 'en' => "I didn't learn factory IT from a book", 'tr' => 'Fabrika BT’sini kitaptan öğrenmedim'],
        'lead' => ['fa' => 'گردانده‌امش — کفِ تولید، وقتی خطِ تولید منتظر است.', 'en' => "I've run it — on the floor, with production waiting.", 'tr' => 'Sahada yönettim — üretim beklerken.'],
        'items' => [
            [
                'org' => 'ServerNet Cloud',
                'd' => ['fa' => 'بنیان‌گذار و مدیرعامل، ۱۵ سال. میزبانی، سرور، دامنه و زیرساخت مدیریت‌شده برای کسب‌وکارها در ایران و خارج.', 'en' => 'Founder & CEO, 15 years. Hosting, servers, domains and managed infrastructure for businesses in Iran and internationally.', 'tr' => 'Kurucu ve CEO, 15 yıl. İran ve dünyada işletmeler için hosting, sunucu, alan adı ve yönetilen altyapı.'],
            ],
            [
                'org' => 'Jahan Orum Oyaz',
                'd' => ['fa' => 'مدیر فناوری یک کارخانهٔ نساجی، ۷ سال. همهٔ فرایندهای کسب‌وکار با BPMN مستند و در ProcessMaker پیاده شد، هم‌راستا با ISO 9001 و 27001.', 'en' => 'CTO of a textile manufacturer, 7 years. Every business process documented in BPMN and implemented in ProcessMaker, aligned to ISO 9001 and 27001.', 'tr' => 'Tekstil üreticisinde CTO, 7 yıl. Tüm iş süreçleri BPMN ile belgelendi ve ProcessMaker’da uygulandı.'],
            ],
            [
                'org' => 'Mammut World Trailer',
                'd' => ['fa' => 'مدیر تحقیق و توسعه، ۳ سال. نقشهٔ فرایند، ERP و گزارش‌های مدیریتی برای سازندهٔ تجهیزات حمل‌ونقل سنگین — به‌علاوهٔ سایتشان.', 'en' => 'R&D Manager, 3 years. Process mapping, ERP and management reporting for a heavy transport equipment manufacturer — plus their website.', 'tr' => 'Ar-Ge Müdürü, 3 yıl. Ağır nakliye ekipmanı üreticisi için süreç haritalama, ERP ve yönetim raporlaması.'],
            ],
        ],
        'quote' => [
            'fa' => '«در مدیریت زیرساخت فناوری، امنیت سایبری، توسعهٔ نرم‌افزار و عملیات DevOps مهارت استثنایی نشان داده است… رهبری، توان حل مسئله و تعهدش به کیفیت، تعیین‌کننده بوده است.»',
            'en' => '"He has demonstrated exceptional proficiency in managing our IT infrastructure, cybersecurity, software development, and DevOps operations… His leadership, problem-solving capabilities, and dedication to excellence have been instrumental."',
            'tr' => '"BT altyapımızı, siber güvenliği, yazılım geliştirmeyi ve DevOps operasyonlarını yönetmede olağanüstü yetkinlik gösterdi… Liderliği ve mükemmelliğe bağlılığı belirleyici oldu."',
        ],
        'quote_by' => ['fa' => 'علیرضا نوری — مدیرعامل نساجی اویاز، توصیه‌نامهٔ لینکدین', 'en' => 'Alireza Nouri — CEO, Oyaz Textile · LinkedIn recommendation', 'tr' => 'Alireza Nouri — CEO, Oyaz Tekstil · LinkedIn tavsiyesi'],
    ],

    'process' => [
        'kicker' => ['fa' => 'چطور کار می‌کنیم', 'en' => 'How it works', 'tr' => 'Nasıl çalışır'],
        'h2' => ['fa' => 'چهار قدم، بدون ابهام', 'en' => 'Four steps, no mystery', 'tr' => 'Dört adım, gizem yok'],
        'items' => [
            [
                't' => ['fa' => 'بررسی رایگان سه‌نکته‌ای', 'en' => 'Free 3-point review', 'tr' => 'Ücretsiz 3 maddelik inceleme'],
                'd' => ['fa' => 'به آنچه دارید نگاه می‌کنم و سه چیزی را که اول درست می‌کردم برایتان می‌فرستم. دوتایش را خودتان می‌توانید انجام دهید. بدون تماس، بدون هزینه.', 'en' => "I look at what you have and send the three things I'd fix first. Two of them you can do yourself. No call required, no charge.", 'tr' => 'Elinizdekine bakıp ilk düzelteceğim üç şeyi gönderirim. İkisini kendiniz yapabilirsiniz. Görüşme yok, ücret yok.'],
            ],
            [
                't' => ['fa' => 'دامنهٔ کار و قیمت ثابت', 'en' => 'Scope & fixed price', 'tr' => 'Kapsam ve sabit fiyat'],
                'd' => ['fa' => '۱۵ دقیقه تا بفهمم واقعاً به چه چیزی نیاز دارید. بعد یک قیمت ثابت — نه بازه‌ای که بعداً بزرگ شود.', 'en' => '15 minutes to understand what you actually need. Then a fixed price — not a range that grows later.', 'tr' => 'Gerçekte neye ihtiyacınız olduğunu anlamak için 15 dakika. Sonra sabit fiyat — sonradan büyüyen bir aralık değil.'],
            ],
            [
                't' => ['fa' => 'ساخت، جلوی چشم شما', 'en' => 'Build, with you watching', 'tr' => 'Siz izlerken inşa'],
                'd' => ['fa' => 'از هفتهٔ اول یک لینک آزمایشی دارید. پیشرفت را همان‌طور که اتفاق می‌افتد می‌بینید، نه یک رونماییِ آخر کار.', 'en' => 'A staging link from week one. You see progress as it happens, not a reveal at the end.', 'tr' => 'İlk haftadan bir test bağlantısı. İlerlemeyi anında görürsünüz.'],
            ],
            [
                't' => ['fa' => 'انتشار — و من می‌مانم', 'en' => 'Launch — and I stay', 'tr' => 'Yayın — ve ben kalırım'],
                'd' => ['fa' => 'روی زیرساخت خودم میزبانی می‌شود، پایش می‌شود، بکاپ می‌گیرد. بعد از انتشار غیب نمی‌شوم. کلِ ماجرا همین است.', 'en' => "Hosted on my infrastructure, monitored, backed up. I don't disappear after launch. That's the whole point.", 'tr' => 'Kendi altyapımda barındırılır, izlenir, yedeklenir. Yayından sonra kaybolmam.'],
            ],
        ],
    ],

    'faq' => [
        'kicker' => ['fa' => 'پرسش‌های پرتکرار', 'en' => 'FAQ', 'tr' => 'SSS'],
        'h2' => ['fa' => 'جواب‌های رُک', 'en' => 'Straight answers', 'tr' => 'Net cevaplar'],
        'items' => [
            [
                'q' => ['fa' => 'کجا مستقر هستید؟', 'en' => 'Where are you based?', 'tr' => 'Nerede bulunuyorsunuz?'],
                'a' => ['fa' => 'پایگاهم **ارومیه** است و بین ارومیه و استانبول کار می‌کنم؛ با مشتریانی در سراسر ایران، ترکیه، حاشیهٔ خلیج فارس و اروپا دورکاری دارم. پیش از هر تعهدی، صریح می‌گویم کار کجا انجام می‌شود و صورت‌حساب چطور است.', 'en' => "I'm based in Urmia and work between Urmia and Istanbul, remotely with clients across Türkiye, the Gulf and Europe. I'll always be straight with you about where the work is done and how invoicing works before you commit to anything.", 'tr' => 'Urmiye merkezliyim, Urmiye ve İstanbul arasında çalışıyorum; Türkiye, Körfez ve Avrupa’daki müşterilerle uzaktan. Taahhütten önce işin nerede yapıldığını net söylerim.'],
            ],
            [
                'q' => ['fa' => 'پرداخت چطور است؟', 'en' => 'How do payments work?', 'tr' => 'Ödemeler nasıl işliyor?'],
                /*
                | ⚠️ پاسخِ فارسی عمداً ترجمهٔ پاسخِ انگلیسی نیست.
                |    مشتریِ ایرانی به تومان و از راهِ کارت/حواله پرداخت می‌کند؛
                |    «حواله بانکی به یورو یا تتر» برای او هم بی‌ربط است هم
                |    نگران‌کننده. زبان که عوض می‌شود، گاهی **واقعیتِ کسب‌وکار**
                |    هم عوض می‌شود، نه فقط کلمه‌ها.
                */
                'a' => ['fa' => 'کارت‌به‌کارت یا واریز به حساب، به تومان — فاکتور رسمی هم صادر می‌شود. پروژه تا ۱۰۰ میلیون تومان: ۵۰٪ شروع، ۵۰٪ تحویل. بالاتر از آن: ۴۰ / ۳۰ / ۳۰. طرح‌های ماهانه پیش‌پرداخت است.', 'en' => 'Bank transfer in EUR, or USDT if you prefer. Projects up to €3,000: 50% to start, 50% on delivery. Above that: 40 / 30 / 30. Monthly plans are paid in advance.', 'tr' => 'EUR banka havalesi veya isterseniz USDT. €3.000’a kadar: %50 başlangıç, %50 teslim. Üzeri: 40 / 30 / 30. Aylık planlar peşin.'],
            ],
            [
                'q' => ['fa' => 'اگر از قبل سایت داشته باشم چه؟', 'en' => 'What if I already have a website?', 'tr' => 'Zaten bir sitem varsa?'],
                'a' => ['fa' => 'با بررسی رایگان شروع کنید — اغلب جواب «سه چیز را درست کن» است نه «همه‌چیز را از نو بساز». اگر بازسازی نمی‌ارزد، همان را می‌گویم. ترجیح می‌دهم پروژه‌ای را از دست بدهم تا اینکه چیزی را به شما بفروشم که لازم ندارید.', 'en' => 'Then start with the free review — often the answer is "fix three things", not "rebuild everything". I\'ll tell you if a rebuild isn\'t worth it. I\'d rather lose a project than sell you one you don\'t need.', 'tr' => 'Ücretsiz incelemeyle başlayın — genelde cevap "üç şeyi düzelt" olur, "her şeyi yeniden yap" değil. Değmiyorsa söylerim.'],
            ],
            [
                'q' => ['fa' => 'با چه زبان‌هایی کار می‌کنید؟', 'en' => 'Which languages do you work in?', 'tr' => 'Hangi dillerde çalışıyorsunuz?'],
                'a' => ['fa' => 'فارسی، ترکی و انگلیسی — هم برای گفتگو و هم برای خودِ سایت‌ها، با پشتیبانی کامل راست‌به‌چپ.', 'en' => 'English, Turkish and Persian — for both the conversation and the websites themselves, including full right-to-left support.', 'tr' => 'Türkçe, İngilizce ve Farsça — hem görüşme hem de siteler için, tam sağdan-sola desteğiyle.'],
            ],
            [
                'q' => ['fa' => 'فقط سایت می‌سازید یا با کارخانه هم کار می‌کنید؟', 'en' => 'Do you work with factories, or only websites?', 'tr' => 'Fabrikalarla da çalışır mısınız?'],
                'a' => ['fa' => 'هر دو. سایت رایج‌ترین نقطهٔ شروع است، ولی مستندسازی فرایند، ERP، داشبورد و زیرساخت جایی است که بیشترِ یک دههٔ گذشته را گذرانده‌ام.', 'en' => "Both. Websites are the most common starting point, but process documentation, ERP, dashboards and infrastructure are where I've spent most of the last decade.", 'tr' => 'İkisi de. Siteler en yaygın başlangıç noktası, ama süreç dokümantasyonu, ERP ve altyapı son on yılımın çoğu.'],
            ],
        ],
    ],

    'cta' => [
        'h2' => ['fa' => 'با بررسی رایگان شروع کنید', 'en' => 'Start with the free review', 'tr' => 'Ücretsiz incelemeyle başlayın'],
        'lead' => [
            'fa' => 'نشانی سایتتان را برایم بفرستید. ظرف ۲۴ ساعت سه چیزی را که اول درست می‌کردم می‌نویسم — بدون تماس، بدون هزینه، بدون دنبالهٔ ایمیل‌های تبلیغاتی.',
            'en' => "Send me your website address. I'll reply within 24 hours with the three things I'd fix first — no call, no charge, no follow-up sequence.",
            'tr' => 'Site adresinizi gönderin. 24 saat içinde ilk düzelteceğim üç şeyi yazarım — görüşme yok, ücret yok, takip e-postası yok.',
        ],
        'btn' => ['fa' => 'نشانی سایتتان را ایمیل کنید', 'en' => 'Email me your site', 'tr' => 'Sitenizi e-posta ile gönderin'],
    ],
];
