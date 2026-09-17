<?php

/*
|--------------------------------------------------------------------------
| نقشهٔ آدرس‌های قدیمی — وردپرسِ servernet.ir و ۴۰۴های جامانده
|--------------------------------------------------------------------------
|
| `LegacyUrlResolver` فقط وقتی سراغِ این فایل می‌آید که پاسخ **از قبل ۴۰۴**
| باشد (از داخلِ `TrackNotFound`). یعنی هیچ‌چیز این‌جا نمی‌تواند یک صفحهٔ زنده
| را بپوشاند؛ بدترین حالتِ یک قاعدهٔ غلط، ریدایرکتِ یک آدرسِ مرده به صفحهٔ
| نه‌چندان مرتبط است — نه خراب‌شدنِ صفحهٔ سالم.
|
| ═══ منبعِ داده (شهریور ۱۴۰۵) ═══
|
|   · آرشیوِ Wayback برای servernet.ir: ۵٬۸۵۸ آدرس — ۴۶۳ نامکِ فارسی، ۱۸۷
|     نامکِ لاتین، دسته/برچسب/نویسنده/تاریخ، و ۳٬۵۰۰ فایلِ قالب و افزونه.
|   · ردیابِ ۴۰۴ِ خودِ سایت (۴۴۲ ردیف).
|   · عنوانِ ۲۴۰ پستِ منتشرشده؛ تطبیقِ نامکِ قدیمی با عنوان، و بعد بازبینیِ
|     دستیِ هر تطبیق (امتیازِ عددی «درباره-ما» را به مقالهٔ WHOIS می‌فرستاد).
|
| 🔴 چرا فقط ~۴۰ پست نقشهٔ دقیق دارند: موقعِ ایمپورتِ وردپرس هر نامکِ فارسی با
|    `post-{id}` جایگزین شد و بیشترِ نوشته‌های قدیمی (نمونه‌کارها، صفحه‌های
|    طراحیِ سایت در ارومیه، آموزش‌های اپلیکیشن‌ساز) اصلاً منتقل نشدند. برای آن‌ها
|    نزدیک‌ترین صفحهٔ **موضوعی** مقصد است، نه خانه.
|
| 🔴 کلیدِ `exact` نرمال‌شده است — `LegacyUrlResolver::normalize()` (حروفِ
|    عربی→فارسی، حذفِ نیم‌فاصله و ـ و variation selector، ارقامِ فارسی→لاتین،
|    حروفِ کوچک). کلیدِ تازه را دستی اضافه می‌کنی؟ همان‌طور بنویس، وگرنه هرگز
|    تطبیق نمی‌خورد.
*/

return [

    /*
    | نامکِ قدیمی (نرمال‌شده، بی‌اسلشِ ابتدا/انتها) => مسیرِ فارسیِ مقصد.
    | برای درخواستِ /en/… یا /tr/… مقصد خودش پیشوندِ زبان می‌گیرد.
    */
    'exact' => [
        '10-must-to-do-rules-in-a-successful-plan' => '/blog',
        '10-must-to-do-rules-in-a-successful-plan-2' => '/blog',
        '11112-2' => '/blog',
        '19332-2' => '/blog',
        '251' => '/blog',
        '254' => '/blog',
        '257' => '/blog',
        '264' => '/blog',
        '271' => '/blog',
        '274' => '/blog',
        '278' => '/blog',
        '282' => '/blog',
        '4357-2' => '/blog',
        '5-essential-steps-to-win-your-competitors' => '/blog',
        '555' => '/blog',
        '6-important-methods-to-keep-servers-safe' => '/blog',
        '8-ویژگی-منحصر-به-فرد-گوشی-های-هوشمند-گوش' => '/blog/smartphone-hidden-features',
        'about-us' => '/about',
        'account-number' => '/contact',
        'announcements' => '/blog',
        'app' => '/urmia/app-development',
        'apples-deal-with-cisco-will-lay-out-a-red-carpet' => '/blog',
        'blog-2' => '/blog',
        'blog-3' => '/blog',
        'blog1-both-sidebar' => '/blog',
        'blog1-full-width' => '/blog',
        'blog1-left-sidebar' => '/blog',
        'blog1-left-sidebar-2' => '/blog',
        'blog1-right-sidebar' => '/blog',
        'blog2-both-sidebar' => '/blog',
        'blog2-full-width' => '/blog',
        'blog2-full-width-2' => '/blog',
        'blog2-left-sidebar' => '/blog',
        'blog2-left-sidebar-2' => '/blog',
        'blog2-right-sidebar' => '/blog',
        'blog3-both-sidebar' => '/blog',
        'blog3-full-width' => '/blog',
        'blog3-full-width-2' => '/blog',
        'blog3-left-sidebar' => '/blog',
        'blog3-left-sidebar-2' => '/blog',
        'blog3-right-sidebar' => '/blog',
        'blog4-full-width' => '/blog',
        'blog4-full-width-2' => '/blog',
        'blog4-left-sidebar' => '/blog',
        'blog4-right-sidebar' => '/blog',
        'blog4-right-sidebar-2' => '/blog',
        'blog5-both-sidebar' => '/blog',
        'blog5-full-width' => '/blog',
        'blog5-full-width-2' => '/blog',
        'blog5-left-sidebar' => '/blog',
        'blog5-left-sidebar-2' => '/blog',
        'blog5-right-sidebar' => '/blog',
        'brain-storm-is-primary-key-for-new-project' => '/blog',
        'brain-storm-is-primary-key-for-new-project-2' => '/blog',
        'cdn' => '/cloud',
        'checkout' => '/cloud',
        'cms-hosting-drupal' => '/hosting/linux',
        'cms-hosting-joomla' => '/hosting/linux',
        'cms-hosting-single' => '/hosting/linux',
        'cms-hosting-single-2' => '/hosting/linux',
        'cms-hosting-wordpress' => '/hosting/wordpress',
        'computer-science-degrees-from-uw-bothell' => '/blog',
        'computer-science-degrees-from-uw-bothell-2' => '/blog',
        'contact-us' => '/contact',
        'contactus' => '/contact',
        'corrupt-registry-repair' => '/blog',
        'domain' => '/domains',
        'domainchecker' => '/domains',
        'ehsan-ebrahimi' => '/about',
        'everything-a-tech-man-need-on-a-breakfast' => '/blog',
        'free-linux-hosting' => '/hosting/linux',
        'google-tasks-چیست-سرورنت' => '/blog/google-tasks-guide',
        'graphics' => '/urmia/portfolio',
        'home-2' => '/',
        'home-domain' => '/domains',
        'home-whmpress' => '/',
        'host' => '/hosting/linux',
        'how-to-get-higher-in-your-carrier' => '/blog',
        'how-to-make-a-statement-in-a-session' => '/blog',
        'how-to-turn-off-bluetooth-on-windows-10-2' => '/blog',
        'jobs' => '/careers',
        'jobs-form' => '/careers',
        'jobs-m' => '/careers',
        'jobs-r' => '/careers',
        'jobs-s' => '/careers',
        'jobs-z' => '/careers',
        'latest-from-blog' => '/blog',
        'linux-hosting' => '/hosting/linux',
        'live-support-key-of-an-endless-satisfaction' => '/blog',
        'login' => '/account',
        'logo' => '/urmia/portfolio',
        'marketing' => '/solutions',
        'masonry' => '/blog',
        'media' => '/blog',
        'meeting-most-successful-women-in-tech' => '/blog',
        'meetings-which-will-improve-your-results' => '/blog',
        'mega_menu_contact' => '/contact',
        'mega_menu_content-hosting-menu' => '/hosting/linux',
        'mega_menu_features' => '/',
        'my-account' => '/account',
        'new-management-method-which-rocks' => '/blog',
        'new-management-method-which-rocks-2' => '/blog',
        'our-team' => '/about',
        'payamak' => '/solutions',
        'portfolio' => '/urmia/portfolio',
        'portfolio-type-1' => '/urmia/portfolio',
        'portfolio-type-2' => '/urmia/portfolio',
        'portfolio-type-3' => '/urmia/portfolio',
        'portfolio-type-3-2' => '/urmia/portfolio',
        'portfolios' => '/urmia/portfolio',
        'pricing-table' => '/cloud',
        'processes' => '/blog',
        'profilito-webdesign' => '/urmia/portfolio',
        'reseller' => '/hosting/reseller-linux',
        'seo' => '/solutions/seo-services',
        'server' => '/cloud',
        'shared-hosting-2' => '/hosting/linux',
        'shop' => '/cloud',
        'sms' => '/solutions',
        'strong-servers-customer-friendly-services' => '/blog',
        'submitticket' => '/docs',
        'technology-and-the-consistency-society' => '/blog',
        'terms-all' => '/terms',
        'terms-domain' => '/terms',
        'terms-freehosting' => '/terms',
        'terms-irdomain' => '/terms',
        'terms-prohosting' => '/terms',
        'terms-smspanel' => '/terms',
        'urmia-seo-service' => '/urmia/seo',
        'urmia-web-design-good-successful' => '/urmia/web-design',
        'webdesign' => '/solutions/web-design',
        'website-design-portfolio' => '/urmia/portfolio',
        'weekly-meeting-in-companies-think-room' => '/blog',
        'whois' => '/domains',
        'whos-really-in-charge-at-cisco' => '/blog',
        'آموزش-اتصال-درگاه-زرین-پال-در-اپلیکیشن' => '/blog/zarinpal-app-integration',
        'آموزش-اضافهکردن-دکمه-خروج-به-اپلیکیش' => '/blog/app-exit-button-tutorial',
        'آموزش-انتشار-اپلیکیشن-در-ایران-اپس-ق' => '/blog/publish-app-iranapps',
        'آموزش-انتشار-اپلیکیشن-در-ایران-اپس-ق-2' => '/blog/publish-app-iranapps',
        'آموزش-انتشار-اپلیکیشن-در-کافه-بازار' => '/blog/publish-app-cafebazaar',
        'آموزش-انتشار-اپلیکیشن-در-کافه-بازار-2' => '/blog/publish-app-cafebazaar',
        'آموزش-تصویری-انتشار-اپلیکیشن-در-کافه-ب' => '/blog/publish-app-cafebazaar',
        'آموزش-فعال-سازی-گواهینامه-ssl-برای-سایت' => '/docs/installing-ssl-certificate',
        'آموزش-و-پشتیبانی' => '/docs',
        'آموزش-پرداخت-درونبرنامهای-مایکت-د' => '/blog/myket-in-app-payment',
        'آموزش-پرداخت-درونبرنامهای-کافه-با' => '/blog/cafebazaar-in-app-payment',
        'ابزار-گوگل-آنالیتیکس-چیست' => '/blog/setting-up-google-analytics',
        'اتصال-درگاه-آیدی-پی-در-اپلیکیشن-ساز-س' => '/blog/zarinpal-app-integration',
        'اصول-سئو-چیست-راهنمای-کامل-آموزش-سئو' => '/solutions/seo-services',
        'اضافه-کردن-دکمه-به-اپلیکیشن-ساز-سرورنت' => '/blog/app-exit-button-tutorial',
        'امنیت-وردپرس-راز-هایی-برای-جلوگیری-از' => '/blog/wordpress-security-checklist',
        'ایجاد-حساب-توسعه-دهنده-در-گوگل-پلی' => '/blog/google-play-developer-account',
        'تبلیغات-تپسل-راهاندازی-تبلیغات-درون' => '/blog/tapsell-ads-setup',
        'تجربه-کاربری-سایت' => '/solutions/web-design',
        'تفاوت-میان-پروکسی-و-فیلترشکن-چیست؟' => '/blog/proxy-vs-vpn',
        'تیکتهای-من/ارسال-تیکت' => '/docs',
        'دامنه-سایت-چیست؟' => '/domains',
        'درباره-ما' => '/about',
        'دعوت-به-همکاری' => '/careers',
        'دفتر-احسان-ابراهیمی' => '/about',
        'راهاندازی-فروشگاه-اینترنتی-فروشگاه' => '/blog/ecommerce-payment-gateway',
        'راهنمای-خرید-هاست-ابری' => '/docs/choosing-between-services',
        'رایانش-ابری-چیست-و-چه-کاربردی-دارد؟' => '/blog/cloud-hosting-vs-shared-hosting',
        'سرور-اختصاصی' => '/dedicated/iran',
        'سرور-اختصاصی-و-خرید-آن' => '/dedicated/iran',
        'سرور-دیسکورد-انرژی-چیست؟' => '/blog/iranian-discord-servers',
        'سرور-و-هاست-ترید' => '/vps/trading',
        'سرور-وی-پی-ان' => '/blog/vpn-server',
        'طراحی-سایت' => '/solutions/web-design',
        'طراحی-گرافیک' => '/blog/graphic-design-software',
        'لیست-سرورهای-ایرانی-و-فارسی-دیسکورد' => '/blog/iranian-discord-servers',
        'معرفی-6-نرمافزار-طراحی-گرافیک-نرم-افز' => '/blog/graphic-design-software',
        'معرفی-کلاینت-و-انواع-آن' => '/blog/what-is-a-client',
        'میزبانی-وب' => '/hosting/linux',
        'میزبانی-وب-سایت' => '/hosting/linux',
        'نحوه-انتشار-اپلیکیشن-در-کافه-بازار-ق' => '/blog/publish-app-cafebazaar',
        'نصب-وردپرس-روی-لوکال-هاست-با-استفاده-از' => '/blog/installing-wordpress-manually',
        'نصب-وردپرس-روی-هاست-نصب-آسان-و-سریع' => '/blog/installing-wordpress-manually',
        'نگاهی-به-سرور-گوشی' => '/blog/phone-servers-explained',
        'هاست-ایمیل-چیست؟هاست-ایمیل-چیست؟' => '/blog/email-hosting-vs-web-hosting',
        'هاست-مدیریت-شده-چیست؟' => '/solutions/managed',
        'هاست-وردپرس' => '/hosting/wordpress',
        'هاست-ویندوز' => '/hosting/windows',
        'هاست-پایتون' => '/hosting/python',
        'وبلاگ-مجموعه-سرورنت' => '/blog',
        'وی-پی-ان-یا-فیلترشکن-چیست؛-6-کاربرد-وی-پی' => '/blog/what-is-vpn',
        'وی-پی-ان-یا-فیلترشکن-چیست؛-6-کاربرد-وی-پی-ان' => '/blog/what-is-vpn',
        'پیشرفت-تکنولوژی-از-گذشته-تا-امروز-پیشر' => '/blog/technology-progress-history',
        'کسب-درآمد-از-رشته-برق' => '/blog/earn-money-electrical-engineering',
        'کسب-درآمد-از-رشته-کامپیوتر' => '/blog/earn-money-computer-science',
        'کسب-درآمد-از-طریق-اینترنت' => '/blog/make-money-with-computer',
        'کسب-درآمد-از-طریق-کامپیوتر-با-چند-ایده-ی' => '/blog/make-money-with-computer',
        'کسب-درآمد-از-معماری-روشهای-کسب-درآمد' => '/blog/earn-money-architecture',
        '✔-istanbulda-web-sitesi-tasarimi' => '/tr/solutions/web-design',
        '✔-website-design-in-urmia' => '/urmia/web-design',
    ],

    /*
    | قاعده‌های موضوعی — به ترتیب؛ اولین تطبیق برنده است. الگو روی متنِ
    | نرمال‌شده‌ای اجرا می‌شود که خط‌تیره‌هایش فاصله شده‌اند.
    |
    | ⚠️ ترتیب عمدی است: شهر پیش از «ارومیه»، ارومیه پیش از «طراحی سایت»،
    |    وردپرس پیش از «هاست»، سرورِ مجازی پیش از «سرور».
    */
    'rules' => [
        // شهرهای آذربایجان غربی — صفحهٔ خودِ همان شهر
        ['~(^| )خوی( |$)~u', '/urmia/cities/khoy'],
        ['~(^| )سلماس( |$)~u', '/urmia/cities/salmas'],
        ['~(^| )مهاباد( |$)~u', '/urmia/cities/mahabad'],
        ['~(^| )بوکان( |$)~u', '/urmia/cities/bukan'],
        ['~میاندوآب|میاندواب~u', '/urmia/cities/miandoab'],
        ['~(^| )پیرانشهر( |$)~u', '/urmia/cities/piranshahr'],
        ['~(^| )نقده( |$)~u', '/urmia/cities/naqadeh'],
        ['~(^| )تکاب( |$)~u', '/urmia/cities/takab'],
        ['~شاهین ?دژ~u', '/urmia/cities/shahin-dej'],
        ['~(^| )ماکو( |$)~u', '/urmia/cities/maku'],
        ['~(^| )بازرگان( |$)~u', '/urmia/cities/bazargan'],
        ['~(^| )سردشت( |$)~u', '/urmia/cities/sardasht'],
        ['~(^| )اشنویه( |$)~u', '/urmia/cities/oshnavieh'],
        ['~(^| )پلدشت( |$)~u', '/urmia/cities/poldasht'],
        ['~(^| )شوط( |$)~u', '/urmia/cities/showt'],
        ['~(^| )چالدران( |$)~u', '/urmia/cities/chaldoran'],
        ['~قره ?ضیاءالدین|قره ?ضیاالدین|چایپاره~u', '/urmia/cities/qarah-ziaeddin'],

        // ارومیه + موضوع
        ['~(ارومیه|urmia).*(سئو|seo)|(سئو|seo).*(ارومیه|urmia)~u', '/urmia/seo'],
        ['~(ارومیه|urmia).*(فروشگاه|ecommerce)|(فروشگاه).*(ارومیه)~u', '/urmia/ecommerce-website'],
        ['~(ارومیه|urmia).*(شرکتی|corporate)|(شرکتی).*(ارومیه)~u', '/urmia/corporate-website'],
        ['~(ارومیه|urmia).*(قیمت|هزینه|price)|(قیمت|هزینه).*(ارومیه)~u', '/urmia/web-design-price'],
        ['~(ارومیه|urmia).*(اپلیکیشن|app)~u', '/urmia/app-development'],
        ['~(ارومیه|urmia).*(نمونه کار|portfolio)|(نمونه کار).*(ارومیه)~u', '/urmia/portfolio'],
        ['~ارومیه|urmia|آذربایجان غربی~u', '/urmia'],

        // نمونه‌کار و گرافیک و مشتریانِ قدیمی
        ['~لوگو|logo|کارت ویزیت|پوستر|بروشور|سفارش طراحی گرافیک|آثار عکس|نمونه کار|portfolio|فلورا|طوطیا|کشتیبان|زعفران|بهتا|آینده شهری|econnect|profilito~u', '/urmia/portfolio'],

        // وب‌سایت‌های ترکی/استانبول
        ['~istanbul|استانبول|web sitesi|tasarim~u', '/tr/solutions/web-design'],

        // اپلیکیشن‌سازِ قدیمی و انتشارِ اپ
        ['~اپلیکیشن|اپلیکیشن ساز|pwa|کافه بازار|مایکت|گوگل پلی|ایران اپس|اول مارکت|نوتیفیکیشن~u', '/urmia/app-development'],

        // سئو
        ['~سئو|seo|بک لینک|backlink|رپورتاژ|ریپورتاژ|کلمه کلیدی|کلمات کلیدی|سرچ کنسول|search console|گوگل ترندز|آنالیتیکس|analytics|الکسا|بانس ریت|وبمستر~u', '/solutions/seo-services'],

        // بازاریابی و کسب‌وکار
        ['~مارکتینگ|marketing|بازاریابی|تبلیغات|اینستاگرام|instagram|پینترست|لینکدین|linkedin|کسب درآمد|درآمد|کارآفرین|افیلیت|کسبوکار|کسب و کار|پادکست|اینفلوئنسر|واتساپ|کپیرایت|کپی رایت|فروش~u', '/blog?cat=business'],

        // هاست‌های مشخص — پیش از «هاست»ِ عمومی
        ['~وردپرس|wordpress|ووکامرس|woocommerce~u', '/hosting/wordpress'],
        ['~هاست ویندوز|windows host~u', '/hosting/windows'],
        ['~پایتون|python|جنگو|django~u', '/hosting/python'],
        ['~هاست ایمیل|ایمیل|email~u', '/hosting/email'],
        ['~نمایندگی|reseller~u', '/hosting/reseller-linux'],
        ['~هاست دانلود~u', '/hosting/download'],
        ['~بکاپ|پشتیبان گیری|backup~u', '/hosting/backup'],

        // سرورها — مشخص پیش از عمومی
        ['~ترید|trading|فارکس|forex~u', '/vps/trading'],
        ['~gpu|جی پی یو|کارت گرافیک~u', '/gpu'],
        ['~سرور اختصاصی|dedicated~u', '/dedicated/iran'],
        ['~سرور مجازی|vps|رایانش ابری|ابری|cloud~u', '/vps/iran'],
        ['~سرور|server|cdn~u', '/cloud'],
        ['~هاست|میزبانی|hosting|host|cpanel|سی پنل~u', '/hosting/linux'],
        ['~دامنه|domain|whois~u', '/domains'],

        // موضوع‌های وبلاگ
        ['~وی پی ان|فیلترشکن|vpn|پروکسی|proxy~u', '/blog/vpn-server'],
        ['~ssl|https|امنیت|هک|حمله|ویروس|فایروال|security~u', '/blog?cat=security'],
        ['~طراحی سایت|وب سایت|website|webdesign|web design|تجربه کاربری~u', '/solutions/web-design'],

        // صفحه‌های عمومی
        ['~تماس|contact~u', '/contact'],
        ['~درباره|about|تیم ما|our team~u', '/about'],
        ['~همکاری|استخدام|jobs|careers~u', '/careers'],
        ['~قوانین|شرایط|terms~u', '/terms'],
        ['~پشتیبانی|تیکت|support~u', '/docs'],
        ['~پیامک|پیام کوتاه|sms~u', '/solutions'],

        ['~آموزش|راهنما|tutorial~u', '/blog?cat=tutorial'],
        ['~تکنولوژی|فناوری|گوشی|لپتاپ|لپ تاپ|کامپیوتر|هارد|روتر|شبکه|ویندوز|اندروید|آیفون~u', '/blog?cat=tech'],
    ],

    /*
    | دسته‌های وردپرس → دستهٔ وبلاگِ ما (`/blog?cat=`). روی **همهٔ** بخش‌های
    | مسیر جست‌وجو می‌شود، عمیق‌ترین اول: /category/آموزش/seo ⇒ seo.
    */
    'categories' => [
        'seo' => 'seo', 'سئو' => 'seo',
        'web-design' => 'tutorial', 'application-design' => 'tutorial', 'learn-plugin' => 'tutorial',
        'portal-servernet' => 'tutorial', 'آموزش' => 'tutorial',
        'تکنولوژی' => 'tech', 'فناوری' => 'tech', 'technology' => 'tech',
        'بازاریابی' => 'business', 'کسب-و-کار' => 'business', 'marketing' => 'business', 'business' => 'business',
        'امنیت' => 'security', 'security' => 'security',
        'هاست' => 'hosting', 'سرور' => 'hosting', 'میزبانی' => 'hosting', 'hosting' => 'hosting',
        'ابر' => 'cloud', 'cloud' => 'cloud',
    ],

    /*
    | 🔴 هرزنامه‌ای که روی وردپرسِ قدیمی تزریق شده بود (کازینو، وام، دوست‌یابی،
    | مقاله‌نویسی). ریدایرکتشان یعنی اعتبارِ صفحه‌های اسپم را به صفحه‌های خودمان
    | بدهیم — پس ۴۱۰: «برای همیشه رفته».
    */
    //   ⚠️ فقط **واژهٔ کامل** میانِ خط‌تیره‌ها — وگرنه `dating` نامکِ سالمِ
    //   `updating-wordpress` را هم ۴۱۰ می‌کرد.
    'spam' => '~(^|[-/])(casino|kazino|payday|loans?|dating|sugar-(daddy|baby)|brides?|mail-?order|essays?|steroid|sustanon|lottery\d*|igry|thesis|biographical|sneakers|monogamous|meet-women|ukessaynow|bitcoin-price|oyaz|n?mfghat|مرگ-بر-آمریکا)([-/]|$)~iu',

    /*
    | فایل‌های سیستمی و داراییِ قالبِ وردپرس — معادلی ندارند؛ ۴۱۰ به موتورِ
    | جست‌وجو می‌گوید سریع‌تر از فهرست حذفشان کند.
    |
    | ⚠️ مسیرهای `.php` (wp-login، xmlrpc، …) عمداً این‌جا **نیستند**: آن‌ها
    |    کاوشِ اسکنرند و `TrackNotFound` آن‌ها را ۴۰۴ِ بی‌لاگ نگه می‌دارد.
    */
    'gone' => [
        '~^/wp-(content|includes)(/|$)~i',
        '~^/(css|fonts|js|images|img)/.+\.(css|js|woff2?|ttf|eot|otf|svg|png|jpe?g|gif|webp|ico)$~i',
        '~^/(files|images|uploads|sites/default/files)$~i',
        '~^/(type|portfolio-category|project-type)/~i',
        '~^/cgi-sys/~i',
        // ابزارِ قدیمیِ «سامانه» روی دامنهٔ قبلی — هیچ معادلی ندارد
        '~^/samane(/|$)~i',
    ],
];
