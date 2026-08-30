<?php

/**
 * «چطور درستش کنم؟» — راهکارِ هر چکِ بررسی سایت.
 *
 * ═══ چرا این فایل جدا از `config/tools.php` است ═══
 *
 * آن فایل می‌گوید هر چک **چیست**؛ این یکی می‌گوید **چه کار کنم**. جداکردنشان
 * عمدی است: راهکار متنِ بلند و گاهی چندخطی با نمونه‌کد است، و چپاندنش در همان
 * آرایه، فایلِ متادیتا را غیرقابلِ خواندن می‌کرد.
 *
 * ساختار:  key => [ locale => ['fix' => string, 'code' => ?string] ]
 *
 * - `fix`  : کارِ مشخص، به زبانِ آدمیزاد. نه تعریفِ دوبارهٔ مشکل.
 * - `code` : اگر یک خط کد مسئله را حل می‌کند، همان‌جا بیاید تا کاربر کپی کند.
 *            جایی که راهکار به سرور/CMS بستگی دارد، `code` عمداً نال است —
 *            نمونه‌کدِ نامربوط بدتر از نبودِ نمونه است.
 *
 * ⚠️ کلیدی که این‌جا نیاید، بخشِ راهکارش اصلاً رندر نمی‌شود (نه جای خالی، نه
 * خطا). پس افزودنِ چکِ تازه بی‌راهکار امن است — ولی چکی که می‌تواند fail شود و
 * راهکار ندارد، نصفِ کار است.
 *
 * نکته: رشته‌ها دو-نقل‌قولی‌اند چون متنِ انگلیسی آپاستروف دارد؛ پس داخلشان از
 * نویسه‌های  "  و  $  استفاده نکنید.
 */

return [

    // ═══════════════════════ SEO ═══════════════════════

    "title" => [
        "fa" => ["fix" => "یک عنوانِ یکتا بین ۳۰ تا ۶۵ نویسه بنویسید که کلیدواژهٔ اصلی در ابتدایش باشد و نامِ برند آخرش. عنوانِ تکراری در چند صفحه، همان‌قدر بد است که عنوانِ نداشتن.", "code" => "<title>هاست ابری پرسرعت ایران — سرورنت</title>"],
        "en" => ["fix" => "Write a unique 30–65 character title with the main keyword first and the brand last. A title repeated across pages is as bad as no title.", "code" => "<title>Fast cloud hosting in Iran — ServerNet</title>"],
        "tr" => ["fix" => "Ana anahtar kelime basta, marka sonda olacak sekilde 30-65 karakterlik benzersiz bir baslik yazin.", "code" => "<title>Hizli bulut hosting — ServerNet</title>"],
    ],
    "description" => [
        "fa" => ["fix" => "یک خلاصهٔ ۷۰ تا ۱۶۰ نویسه‌ای بنویسید که به سؤالِ کاربر جواب دهد و یک دعوت به اقدام داشته باشد. گوگل همیشه عیناً نشانش نمی‌دهد، ولی نرخِ کلیک را همین تعیین می‌کند.", "code" => "<meta name=\"description\" content=\"…\">"],
        "en" => ["fix" => "Write a 70–160 character summary that answers the searcher question and ends with a call to action. Google may rewrite it, but this is what drives click-through.", "code" => "<meta name=\"description\" content=\"…\">"],
        "tr" => ["fix" => "Arama yapanin sorusunu yanitlayan 70-160 karakterlik bir ozet yazin.", "code" => "<meta name=\"description\" content=\"…\">"],
    ],
    "h1" => [
        "fa" => ["fix" => "دقیقاً یک H1 بگذارید که موضوعِ صفحه را بگوید. اگر لوگو داخلِ H1 است، بیرونش بیاورید و H1 را به تیترِ واقعیِ صفحه بدهید.", "code" => null],
        "en" => ["fix" => "Use exactly one H1 that states the page topic. If your logo sits inside the H1, move it out and give the H1 to the real page heading.", "code" => null],
        "tr" => ["fix" => "Sayfa konusunu belirten tam olarak bir H1 kullanin.", "code" => null],
    ],
    "headings" => [
        "fa" => ["fix" => "متن را با H2 به بخش‌ها و با H3 به زیربخش‌ها بشکنید. تیتر را برای بزرگ‌کردنِ فونت استفاده نکنید؛ آن کارِ CSS است.", "code" => null],
        "en" => ["fix" => "Break the copy into H2 sections and H3 subsections. Never use a heading just to make text bigger — that is CSS work.", "code" => null],
        "tr" => ["fix" => "Metni H2 bolumlerine ve H3 alt bolumlerine ayirin.", "code" => null],
    ],
    "img_alt" => [
        "fa" => ["fix" => "برای هر تصویرِ محتوایی، alt بنویسید که همان چیزی را بگوید که تصویر می‌گوید. تصویرِ صرفاً تزئینی باید alt خالی بگیرد تا صفحه‌خوان ردش کند.", "code" => "<img src=\"server.webp\" alt=\"رک سرور در دیتاسنتر تهران\">\n<img src=\"pattern.svg\" alt=\"\">"],
        "en" => ["fix" => "Give every meaningful image an alt that says what the image says. Purely decorative images need an EMPTY alt so screen readers skip them.", "code" => "<img src=\"server.webp\" alt=\"Server rack in the Tehran datacenter\">\n<img src=\"pattern.svg\" alt=\"\">"],
        "tr" => ["fix" => "Her anlamli gorsele alt metni verin; dekoratif gorsellere BOS alt birakin.", "code" => "<img src=\"a.webp\" alt=\"…\">\n<img src=\"p.svg\" alt=\"\">"],
    ],
    "canonical" => [
        "fa" => ["fix" => "روی هر صفحه آدرسِ اصلیِ همان صفحه را canonical کنید — با https، بدونِ پارامترهای ردیابی. آدرسِ مطلق بدهید نه نسبی.", "code" => "<link rel=\"canonical\" href=\"https://example.com/page\">"],
        "en" => ["fix" => "Point each page canonical at its own primary URL — https, no tracking parameters. Use an absolute URL, never a relative one.", "code" => "<link rel=\"canonical\" href=\"https://example.com/page\">"],
        "tr" => ["fix" => "Her sayfayi kendi birincil adresine canonical yapin (mutlak URL).", "code" => "<link rel=\"canonical\" href=\"https://example.com/page\">"],
    ],
    "open_graph" => [
        "fa" => ["fix" => "دستِ‌کم og:title و og:description و og:image را بگذارید. تصویر ۱۲۰۰×۶۳۰ باشد وگرنه در تلگرام و لینکدین بریده نشان داده می‌شود.", "code" => "<meta property=\"og:title\" content=\"…\">\n<meta property=\"og:description\" content=\"…\">\n<meta property=\"og:image\" content=\"https://example.com/og.jpg\">"],
        "en" => ["fix" => "Add at least og:title, og:description and og:image. Use a 1200x630 image or it will be cropped on LinkedIn and Telegram.", "code" => "<meta property=\"og:title\" content=\"…\">\n<meta property=\"og:image\" content=\"https://example.com/og.jpg\">"],
        "tr" => ["fix" => "En az og:title, og:description ve 1200x630 og:image ekleyin.", "code" => "<meta property=\"og:image\" content=\"https://example.com/og.jpg\">"],
    ],
    "twitter_card" => [
        "fa" => ["fix" => "دو تگ کافی است؛ اگر Open Graph دارید، بقیه از آن خوانده می‌شود.", "code" => "<meta name=\"twitter:card\" content=\"summary_large_image\">\n<meta name=\"twitter:title\" content=\"…\">"],
        "en" => ["fix" => "Two tags are enough; the rest is inherited from Open Graph if present.", "code" => "<meta name=\"twitter:card\" content=\"summary_large_image\">"],
        "tr" => ["fix" => "Iki etiket yeterli; gerisi Open Graph verisinden alinir.", "code" => "<meta name=\"twitter:card\" content=\"summary_large_image\">"],
    ],
    "structured_data" => [
        "fa" => ["fix" => "متناسب با نوعِ صفحه JSON-LD اضافه کنید: Organization برای صفحهٔ اصلی، Product و Offer برای صفحهٔ محصول، FAQPage برای پرسش‌های متداول. برای فروشگاه، priceValidUntil را هم بگذارید — در بازارِ تورمی بی‌آن، مدل‌های زبانی قیمتِ کهنه را نقل می‌کنند.", "code" => "<script type=\"application/ld+json\">{\"@context\":\"https://schema.org\",\"@type\":\"Organization\",\"name\":\"…\",\"url\":\"…\"}</script>"],
        "en" => ["fix" => "Add JSON-LD matching the page type: Organization for the home page, Product plus Offer for product pages, FAQPage for FAQs. On shops include priceValidUntil, or language models will quote a stale price for months.", "code" => "<script type=\"application/ld+json\">{\"@context\":\"https://schema.org\",\"@type\":\"Organization\"}</script>"],
        "tr" => ["fix" => "Sayfa turune uygun JSON-LD ekleyin (Organization, Product, FAQPage).", "code" => "<script type=\"application/ld+json\">{…}</script>"],
    ],
    "robots_meta" => [
        "fa" => ["fix" => "🔴 این صفحه به گوگل می‌گوید ایندکسم نکن. اگر عمدی نیست، تگ noindex را بردارید — معمولاً از تنظیماتِ «حریمِ خصوصیِ» وردپرس یا از محیطِ استیجینگ جا مانده است.", "code" => "<meta name=\"robots\" content=\"index, follow\">"],
        "en" => ["fix" => "This page tells Google not to index it. If that is not intentional, remove the noindex — it usually survives from a WordPress privacy setting or a staging environment.", "code" => "<meta name=\"robots\" content=\"index, follow\">"],
        "tr" => ["fix" => "Bu sayfa dizine eklenmeyi engelliyor. Istenmiyorsa noindex etiketini kaldirin.", "code" => "<meta name=\"robots\" content=\"index, follow\">"],
    ],
    "robots_txt" => [
        "fa" => ["fix" => "یک robots.txt در ریشه بگذارید و نشانیِ نقشهٔ سایت را داخلش بنویسید. نبودش خطای مرگبار نیست، ولی خزندهٔ گوگل هر بار یک ۴۰۴ می‌گیرد.", "code" => "User-agent: *\nAllow: /\nSitemap: https://example.com/sitemap.xml"],
        "en" => ["fix" => "Put a robots.txt at the root and name your sitemap in it. Its absence is not fatal, but every crawl starts with a 404.", "code" => "User-agent: *\nAllow: /\nSitemap: https://example.com/sitemap.xml"],
        "tr" => ["fix" => "Koke bir robots.txt koyun ve site haritasini icinde belirtin.", "code" => "User-agent: *\nAllow: /\nSitemap: https://example.com/sitemap.xml"],
    ],
    "sitemap" => [
        "fa" => ["fix" => "نقشهٔ سایتِ XML بسازید، در robots.txt معرفی‌اش کنید و در سرچ‌کنسول ثبتش کنید. صفحه‌ای که در نقشه نباشد دیرتر — و گاهی هرگز — ایندکس می‌شود.", "code" => "https://example.com/sitemap.xml"],
        "en" => ["fix" => "Generate an XML sitemap, declare it in robots.txt and submit it in Search Console. Pages missing from it get indexed late, sometimes never.", "code" => "https://example.com/sitemap.xml"],
        "tr" => ["fix" => "XML site haritasi olusturun ve Search Console uzerinden gonderin.", "code" => null],
    ],
    "lang" => [
        "fa" => ["fix" => "زبان و جهتِ صفحه را روی تگِ html اعلام کنید. برای فارسی هر دو لازم است.", "code" => "<html lang=\"fa\" dir=\"rtl\">"],
        "en" => ["fix" => "Declare the page language on the html tag; add dir for right-to-left languages.", "code" => "<html lang=\"en\">"],
        "tr" => ["fix" => "html etiketinde sayfa dilini belirtin.", "code" => "<html lang=\"tr\">"],
    ],
    "links" => [
        "fa" => ["fix" => "از هر صفحه به صفحاتِ مرتبطِ خودتان لینک بدهید. متنِ لینک باید مقصد را بگوید — «اینجا کلیک کنید» نه به کاربر کمک می‌کند نه به گوگل.", "code" => null],
        "en" => ["fix" => "Link out to your own related pages. Anchor text should describe the destination — “click here” helps neither users nor Google.", "code" => null],
        "tr" => ["fix" => "Ilgili kendi sayfalariniza baglanti verin; baglanti metni hedefi anlatsin.", "code" => null],
    ],

    // ═══════════════════════ Performance ═══════════════════════

    "ttfb" => [
        "fa" => ["fix" => "زمانِ اولین بایت مالِ سرور است نه مرورگر. به‌ترتیب: کشِ صفحه را روشن کنید، کوئری‌های کند را پیدا کنید، و اگر مخاطبتان ایران است سرور را به ایران بیاورید. TTFB بالای یک ثانیه معمولاً یعنی هر درخواست دارد کلِ صفحه را از نو می‌سازد.", "code" => null],
        "en" => ["fix" => "TTFB is server time, not browser time. In order: enable page caching, find slow queries, and host near your audience. Over one second usually means every request rebuilds the whole page.", "code" => null],
        "tr" => ["fix" => "TTFB sunucu suresidir: onbellek acin, yavas sorgulari bulun, hedef kitleye yakin barindirin.", "code" => null],
    ],
    "load_time" => [
        "fa" => ["fix" => "تصاویر را به WebP تبدیل و اندازه‌شان را به اندازهٔ واقعیِ نمایش کوچک کنید؛ در بیشترِ سایت‌ها بزرگ‌ترین برد همین‌جاست. بعد اسکریپت‌های غیرضروری را حذف و بقیه را defer کنید.", "code" => "<script src=\"app.js\" defer></script>"],
        "en" => ["fix" => "Convert images to WebP and resize them to their real display size — on most sites this is the single biggest win. Then drop unused scripts and defer the rest.", "code" => "<script src=\"app.js\" defer></script>"],
        "tr" => ["fix" => "Gorselleri WebP yapin ve gercek goruntuleme boyutuna kucultun; scriptleri defer edin.", "code" => "<script src=\"app.js\" defer></script>"],
    ],
    "page_size" => [
        "fa" => ["fix" => "سنگین‌ترین منابع را در تبِ Network مرورگر پیدا کنید. معمولاً یکی دو تصویرِ بهینه‌نشده یا یک فونتِ کاملِ چندوزنه، بیشتر از کلِ بقیهٔ صفحه حجم دارند.", "code" => null],
        "en" => ["fix" => "Find the heaviest resources in the browser Network tab. It is usually one or two unoptimised images, or a full multi-weight font family, outweighing the entire rest of the page.", "code" => null],
        "tr" => ["fix" => "Network sekmesinde en agir kaynaklari bulun; genelde optimize edilmemis gorseller.", "code" => null],
    ],
    "compression" => [
        "fa" => ["fix" => "فشرده‌سازی را روی وب‌سرور روشن کنید. Brotli از gzip بهتر است و روی متن معمولاً ۷۰ تا ۸۰ درصد حجم را کم می‌کند. اگر پشتِ کلادفلر هستید، از همان‌جا هم قابلِ فعال‌سازی است.", "code" => "# Apache (.htaccess)\nAddOutputFilterByType DEFLATE text/html text/css application/javascript application/json"],
        "en" => ["fix" => "Turn on compression at the web server. Brotli beats gzip and typically removes 70–80% of text weight. Behind Cloudflare you can switch it on there instead.", "code" => "# Apache (.htaccess)\nAddOutputFilterByType DEFLATE text/html text/css application/javascript"],
        "tr" => ["fix" => "Web sunucusunda sikistirmayi acin (Brotli tercih edilir).", "code" => "AddOutputFilterByType DEFLATE text/html text/css application/javascript"],
    ],
    "js_requests" => [
        "fa" => ["fix" => "فایل‌ها را ادغام کنید و هر اسکریپتی که برای نمایشِ اولیه لازم نیست defer یا async بگیرد. افزونه‌هایی که فقط در یک صفحه لازم‌اند نباید در همهٔ صفحات بارگذاری شوند.", "code" => "<script src=\"bundle.js\" defer></script>"],
        "en" => ["fix" => "Bundle files and defer or async anything not needed for first paint. Plugins used on one page should not load on every page.", "code" => "<script src=\"bundle.js\" defer></script>"],
        "tr" => ["fix" => "Dosyalari birlestirin, ilk boyama icin gereksiz scriptleri defer edin.", "code" => "<script src=\"bundle.js\" defer></script>"],
    ],
    "css_requests" => [
        "fa" => ["fix" => "استایل‌ها را در یک فایل ادغام کنید. CSSِ بالای صفحه را می‌توانید inline کنید و بقیه را با media بارگذاریِ تأخیری بدهید.", "code" => null],
        "en" => ["fix" => "Merge stylesheets into one file. Inline the above-the-fold CSS and load the rest lazily.", "code" => null],
        "tr" => ["fix" => "Stil dosyalarini tek dosyada birlestirin.", "code" => null],
    ],
    "image_count" => [
        "fa" => ["fix" => "تصاویرِ پایینِ صفحه را lazy کنید تا فقط وقتی به آنها می‌رسیم دانلود شوند.", "code" => "<img src=\"a.webp\" loading=\"lazy\" width=\"800\" height=\"600\" alt=\"…\">"],
        "en" => ["fix" => "Lazy-load below-the-fold images so they download only when reached.", "code" => "<img src=\"a.webp\" loading=\"lazy\" width=\"800\" height=\"600\" alt=\"…\">"],
        "tr" => ["fix" => "Ekran altindaki gorselleri lazy yukleyin.", "code" => "<img loading=\"lazy\" …>"],
    ],
    "caching" => [
        "fa" => ["fix" => "برای فایل‌های ثابت (CSS، JS، تصویر) کشِ طولانی بگذارید و نامِ فایل را نسخه‌دار کنید تا با هر تغییر آدرس عوض شود. بی‌نسخه‌گذاری، کشِ طولانی یعنی کاربر ماه‌ها نسخهٔ قدیمی را می‌بیند.", "code" => "Cache-Control: public, max-age=31536000, immutable"],
        "en" => ["fix" => "Cache static assets for a long time and version their filenames so the URL changes on every edit. Without versioning, long caching means users see stale files for months.", "code" => "Cache-Control: public, max-age=31536000, immutable"],
        "tr" => ["fix" => "Statik dosyalari uzun sureli onbellekleyin ve dosya adini surumleyin.", "code" => "Cache-Control: public, max-age=31536000, immutable"],
    ],
    "http_version" => [
        "fa" => ["fix" => "HTTP/2 یا HTTP/3 را روی وب‌سرور یا CDN فعال کنید؛ روی اتصالِ پرتأخیر تفاوتش محسوس است.", "code" => null],
        "en" => ["fix" => "Enable HTTP/2 or HTTP/3 at the web server or CDN — the gain is largest on high-latency connections.", "code" => null],
        "tr" => ["fix" => "Sunucuda veya CDN uzerinde HTTP/2 veya HTTP/3 etkinlestirin.", "code" => null],
    ],
    "inline_styles" => [
        "fa" => ["fix" => "استایلِ درون‌خطی را به فایلِ CSS ببرید؛ هم کش می‌شود هم بعداً قابلِ نگهداری است.", "code" => null],
        "en" => ["fix" => "Move inline styles into your stylesheet so they can be cached and maintained.", "code" => null],
        "tr" => ["fix" => "Satir ici stilleri CSS dosyasina tasiyin.", "code" => null],
    ],

    // ═══════════════════════ Security ═══════════════════════

    "https" => [
        "fa" => ["fix" => "🔴 گواهی رایگانِ Let's Encrypt بگیرید و کلِ ترافیک http را با ۳۰۱ به https ببرید. بی‌این، مرورگر سایت را «ناامن» برچسب می‌زند و فرمِ ورود و پرداخت عملاً غیرقابلِ اعتماد است.", "code" => "RewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]"],
        "en" => ["fix" => "Get a free Let's Encrypt certificate and 301 all http traffic to https. Without it the browser labels the site Not secure and no one should trust a login or payment form on it.", "code" => "RewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]"],
        "tr" => ["fix" => "Ucretsiz Let's Encrypt sertifikasi alin ve tum http trafigini 301 ile https adresine yonlendirin.", "code" => "RewriteCond %{HTTPS} off\nRewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]"],
    ],
    "hsts" => [
        "fa" => ["fix" => "هدرِ HSTS را اضافه کنید تا مرورگر حتی اولین درخواست را هم https بزند. ⚠️ اول مطمئن شوید همهٔ زیردامنه‌ها https دارند؛ includeSubDomains برگشت‌پذیر نیست و می‌تواند یک زیردامنهٔ بی‌گواهی را از دسترس خارج کند.", "code" => "Strict-Transport-Security: max-age=31536000; includeSubDomains"],
        "en" => ["fix" => "Add the HSTS header so browsers use https even on the first request. WARNING: make sure every subdomain has a certificate first — includeSubDomains is hard to undo and can take an unsecured subdomain offline.", "code" => "Strict-Transport-Security: max-age=31536000; includeSubDomains"],
        "tr" => ["fix" => "HSTS basligini ekleyin; once tum alt alan adlarinin sertifikasi oldugundan emin olun.", "code" => "Strict-Transport-Security: max-age=31536000; includeSubDomains"],
    ],
    "x_content_type" => [
        "fa" => ["fix" => "یک هدر، جلوی حدسِ نوعِ فایل توسط مرورگر را می‌گیرد.", "code" => "X-Content-Type-Options: nosniff"],
        "en" => ["fix" => "One header stops the browser from guessing file types.", "code" => "X-Content-Type-Options: nosniff"],
        "tr" => ["fix" => "Tek basliktan ibaret; tarayicinin tur tahminini engeller.", "code" => "X-Content-Type-Options: nosniff"],
    ],
    "x_frame" => [
        "fa" => ["fix" => "جلوی قرارگرفتنِ سایت در iframe سایتِ دیگر را بگیرید تا کلیک‌دزدی ممکن نباشد.", "code" => "Content-Security-Policy: frame-ancestors 'self'"],
        "en" => ["fix" => "Stop other sites from framing yours, which is how clickjacking works.", "code" => "Content-Security-Policy: frame-ancestors 'self'"],
        "tr" => ["fix" => "Sitenizin baska sitede iframe icine alinmasini engelleyin.", "code" => "Content-Security-Policy: frame-ancestors 'self'"],
    ],
    "csp" => [
        "fa" => ["fix" => "با CSP تعیین کنید اسکریپت و استایل از کجا اجازهٔ اجرا دارند. ⚠️ اول در حالتِ Report-Only بگذارید و گزارش‌ها را ببینید؛ CSPِ سخت‌گیرانه بی‌آزمایش، بی‌صدا فونت و آنالیتیکس و iframe را بلاک می‌کند.", "code" => "Content-Security-Policy-Report-Only: default-src 'self'; img-src 'self' data:"],
        "en" => ["fix" => "Use CSP to declare where scripts and styles may load from. Start in Report-Only mode and read the reports first — an untested strict policy silently blocks fonts, analytics and iframes.", "code" => "Content-Security-Policy-Report-Only: default-src 'self'; img-src 'self' data:"],
        "tr" => ["fix" => "CSP ekleyin; once Report-Only modda deneyin.", "code" => "Content-Security-Policy-Report-Only: default-src 'self'"],
    ],
    "referrer_policy" => [
        "fa" => ["fix" => "یک هدر تا آدرسِ کاملِ صفحه هنگام کلیکِ خروجی به سایتِ مقصد نرود.", "code" => "Referrer-Policy: strict-origin-when-cross-origin"],
        "en" => ["fix" => "One header keeps your full URLs from leaking to sites your users click through to.", "code" => "Referrer-Policy: strict-origin-when-cross-origin"],
        "tr" => ["fix" => "Tam URL sizintisini onleyen tek baslik.", "code" => "Referrer-Policy: strict-origin-when-cross-origin"],
    ],
    "permissions_policy" => [
        "fa" => ["fix" => "دسترسی‌هایی که سایتتان لازم ندارد را صریح ببندید.", "code" => "Permissions-Policy: camera=(), microphone=(), geolocation=()"],
        "en" => ["fix" => "Explicitly switch off the device APIs your site does not use.", "code" => "Permissions-Policy: camera=(), microphone=(), geolocation=()"],
        "tr" => ["fix" => "Kullanmadiginiz cihaz izinlerini kapatin.", "code" => "Permissions-Policy: camera=(), microphone=(), geolocation=()"],
    ],
    "server_disclosure" => [
        "fa" => ["fix" => "نسخهٔ دقیقِ وب‌سرور را از هدر بردارید. این به‌تنهایی نفوذ را ممکن نمی‌کند، ولی کارِ اسکنرِ خودکار را برای پیداکردنِ آسیب‌پذیریِ همان نسخه آسان می‌کند.", "code" => "# Apache\nServerTokens Prod\nServerSignature Off"],
        "en" => ["fix" => "Strip the exact server version from the header. It is not an exploit by itself, but it hands automated scanners the version to match a known CVE against.", "code" => "# Apache\nServerTokens Prod\nServerSignature Off"],
        "tr" => ["fix" => "Sunucu surum bilgisini basliktan kaldirin.", "code" => "ServerTokens Prod\nServerSignature Off"],
    ],
    "powered_by" => [
        "fa" => ["fix" => "هدرِ X-Powered-By را حذف کنید؛ هیچ کاربردی ندارد و فقط نسخهٔ زبانِ بک‌اند را لو می‌دهد.", "code" => "# PHP (php.ini)\nexpose_php = Off"],
        "en" => ["fix" => "Remove the X-Powered-By header; it serves no purpose and leaks your backend version.", "code" => "# PHP (php.ini)\nexpose_php = Off"],
        "tr" => ["fix" => "X-Powered-By basligini kaldirin.", "code" => "expose_php = Off"],
    ],
    "mixed_content" => [
        "fa" => ["fix" => "🔴 هر منبعِ http روی صفحهٔ https را به https ببرید. مرورگر یا بلاکش می‌کند یا قفلِ امنیت را برمی‌دارد — و در هر دو حالت کاربر یا تصویر را نمی‌بیند یا هشدار می‌گیرد.", "code" => "<!-- به‌جای http:// از // یا https:// استفاده کنید -->\n<img src=\"https://example.com/a.jpg\">"],
        "en" => ["fix" => "Switch every http resource on an https page to https. The browser either blocks it or drops the padlock — either way the visitor loses the asset or gets a warning.", "code" => "<img src=\"https://example.com/a.jpg\">"],
        "tr" => ["fix" => "https sayfadaki tum http kaynaklari https yapin.", "code" => "<img src=\"https://example.com/a.jpg\">"],
    ],

    // ═══════════════════════ Accessibility ═══════════════════════

    "a11y_lang" => [
        "fa" => ["fix" => "زبان را روی تگِ html بگذارید. صفحه‌خوان بی‌این، متنِ فارسی را با آواشناسیِ انگلیسی می‌خواند و عملاً نامفهوم می‌شود.", "code" => "<html lang=\"fa\" dir=\"rtl\">"],
        "en" => ["fix" => "Set the language on the html tag. Without it a screen reader may read your text with the wrong phonetics, making it unintelligible.", "code" => "<html lang=\"en\">"],
        "tr" => ["fix" => "html etiketine dil ekleyin.", "code" => "<html lang=\"tr\">"],
    ],
    "a11y_labels" => [
        "fa" => ["fix" => "هر ورودی را با label به id خودش وصل کنید. اگر طراحی جا برای برچسبِ دیداری ندارد، aria-label بگذارید — placeholder برچسب نیست و به‌محضِ تایپ ناپدید می‌شود.", "code" => "<label for=\"email\">ایمیل</label>\n<input id=\"email\" type=\"email\">\n\n<!-- بدونِ برچسبِ دیداری -->\n<input type=\"search\" aria-label=\"جستجو\">"],
        "en" => ["fix" => "Tie every input to a label via its id. Where the design has no room for a visible label use aria-label — a placeholder is not a label and disappears the moment the user types.", "code" => "<label for=\"email\">Email</label>\n<input id=\"email\" type=\"email\">\n\n<input type=\"search\" aria-label=\"Search\">"],
        "tr" => ["fix" => "Her girdiyi label ile id uzerinden baglayin; placeholder etiket degildir.", "code" => "<label for=\"email\">E-posta</label>\n<input id=\"email\">"],
    ],
    "a11y_names" => [
        "fa" => ["fix" => "دکمه یا لینکی که فقط آیکون دارد باید aria-label بگیرد، وگرنه صفحه‌خوان فقط «دکمه» می‌گوید و کاربر نمی‌داند کجا می‌رود.", "code" => "<button aria-label=\"بستن\"><svg …></svg></button>\n<a href=\"/cart\" aria-label=\"سبد خرید\"><svg …></svg></a>"],
        "en" => ["fix" => "Any icon-only button or link needs an aria-label, otherwise a screen reader announces only “button” and the user has no idea where it goes.", "code" => "<button aria-label=\"Close\"><svg …></svg></button>"],
        "tr" => ["fix" => "Sadece ikonlu buton ve baglantilara aria-label ekleyin.", "code" => "<button aria-label=\"Kapat\"><svg …></svg></button>"],
    ],
    "a11y_heading_order" => [
        "fa" => ["fix" => "سطحِ تیترها را پشتِ سرِ هم پیش ببرید: بعد از H2 یا H2 بیاید یا H3، نه H4. اگر تیتر را برای اندازهٔ فونت انتخاب کرده‌اید، سطح را درست کنید و اندازه را با CSS بدهید.", "code" => null],
        "en" => ["fix" => "Step heading levels one at a time: after an H2 comes another H2 or an H3, never an H4. If you picked a level for its font size, fix the level and set the size in CSS.", "code" => null],
        "tr" => ["fix" => "Baslik seviyelerini birer birer ilerletin; boyut icin CSS kullanin.", "code" => null],
    ],
    "a11y_landmarks" => [
        "fa" => ["fix" => "اسکلتِ صفحه را با تگ‌های معنایی بسازید تا کاربرِ صفحه‌خوان بتواند مستقیم به محتوا بپرد و منو را هر بار نشنود.", "code" => "<header>…</header>\n<nav>…</nav>\n<main>…</main>\n<footer>…</footer>"],
        "en" => ["fix" => "Build the page skeleton from semantic elements so screen-reader users can jump straight to the content instead of hearing the menu every time.", "code" => "<header>…</header>\n<nav>…</nav>\n<main>…</main>\n<footer>…</footer>"],
        "tr" => ["fix" => "Sayfa iskeletini anlamsal etiketlerle kurun.", "code" => "<header>…</header>\n<nav>…</nav>\n<main>…</main>"],
    ],
    "a11y_tabindex" => [
        "fa" => ["fix" => "tabindex مثبت را بردارید. ترتیبِ درستِ فوکوس از ترتیبِ خودِ DOM می‌آید؛ اگر ترتیب غلط است، مارک‌آپ را جابه‌جا کنید نه tabindex را. برای عنصرِ غیرتعاملی که باید فوکوس بگیرد، فقط 0 یا -1 معنی دارد.", "code" => "<!-- بد --> <div tabindex=\"3\">\n<!-- خوب --> <div tabindex=\"0\">"],
        "en" => ["fix" => "Remove positive tabindex. Correct focus order comes from DOM order — if the order is wrong, move the markup, not the tabindex. Only 0 and -1 are meaningful.", "code" => "<!-- bad --> <div tabindex=\"3\">\n<!-- good --> <div tabindex=\"0\">"],
        "tr" => ["fix" => "Pozitif tabindex kaldirin; yalnizca 0 ve -1 anlamlidir.", "code" => "<div tabindex=\"0\">"],
    ],
    "a11y_zoom" => [
        "fa" => ["fix" => "🔴 user-scalable=no و maximum-scale=1 را از متا viewport بردارید. قفل‌کردنِ زوم برای کاربرِ کم‌بینا یعنی سایت شما اصلاً قابلِ استفاده نیست، و هیچ سودی هم ندارد.", "code" => "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"],
        "en" => ["fix" => "Drop user-scalable=no and maximum-scale=1 from the viewport meta. Locking zoom makes your site unusable for low-vision visitors and buys you nothing.", "code" => "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"],
        "tr" => ["fix" => "viewport meta etiketinden user-scalable=no ifadesini kaldirin.", "code" => "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"],
    ],

    // ═══════════════════════ Network ═══════════════════════

    "cert_expiry" => [
        "fa" => ["fix" => "گواهی را تمدید کنید و تمدیدِ خودکار را روشن. اگر Let's Encrypt دارید، certbot باید هر ۶۰ روز خودش تمدید کند؛ نبودنِ آن یعنی هر ۹۰ روز سایت برای همه قرمز می‌شود. یک یادآورِ تقویمی هم بگذارید.", "code" => "certbot renew --dry-run"],
        "en" => ["fix" => "Renew the certificate and enable auto-renewal. With Let's Encrypt, certbot should renew every 60 days — without it the site goes red for everyone every 90 days. Add a calendar reminder as a backstop.", "code" => "certbot renew --dry-run"],
        "tr" => ["fix" => "Sertifikayi yenileyin ve otomatik yenilemeyi acin.", "code" => "certbot renew --dry-run"],
    ],
    "cert_hostname" => [
        "fa" => ["fix" => "🔴 گواهی این دامنه را پوشش نمی‌دهد، پس هر بازدیدکننده صفحهٔ اخطارِ امنیتی می‌بیند. گواهی را برای همین نام (یا wildcard) دوباره صادر کنید و مطمئن شوید روی همان vhost نشسته.", "code" => null],
        "en" => ["fix" => "The certificate does not cover this hostname, so every visitor gets a security warning. Reissue it for this exact name (or a wildcard) and check it is bound to the right vhost.", "code" => null],
        "tr" => ["fix" => "Sertifika bu alan adini kapsamiyor; dogru ad icin yeniden olusturun.", "code" => null],
    ],
    "ipv6" => [
        "fa" => ["fix" => "یک رکورد AAAA به DNS اضافه کنید. اگر سرورتان IPv6 ندارد، ساده‌ترین راه قرارگرفتن پشتِ یک CDN است که خودش IPv6 می‌دهد.", "code" => "example.com.  AAAA  2a01:4f8::1"],
        "en" => ["fix" => "Add an AAAA record. If your server has no IPv6, the simplest route is to sit behind a CDN that terminates IPv6 for you.", "code" => "example.com.  AAAA  2a01:4f8::1"],
        "tr" => ["fix" => "DNS kaydina AAAA ekleyin veya IPv6 destekleyen bir CDN kullanin.", "code" => "example.com.  AAAA  2a01:4f8::1"],
    ],
    "spf" => [
        "fa" => ["fix" => "یک رکورد TXT برای SPF بسازید و همهٔ فرستنده‌های مجازتان را در آن بیاورید (سرورِ خودتان، سرویسِ ایمیلِ تبلیغاتی، فرمِ تماس). ⚠️ فقط **یک** رکورد SPF مجاز است؛ دوتا یعنی هر دو نامعتبر.", "code" => "example.com.  TXT  \"v=spf1 mx include:_spf.google.com ~all\""],
        "en" => ["fix" => "Publish one SPF TXT record listing every legitimate sender (your server, your marketing platform, the contact form). WARNING: only ONE SPF record is allowed — two makes both invalid.", "code" => "example.com.  TXT  \"v=spf1 mx include:_spf.google.com ~all\""],
        "tr" => ["fix" => "Tek bir SPF TXT kaydi yayinlayin; iki kayit ikisini de gecersiz kilar.", "code" => "example.com.  TXT  \"v=spf1 mx ~all\""],
    ],
    "dmarc" => [
        "fa" => ["fix" => "با p=none شروع کنید و گزارش‌ها را چند هفته بخوانید تا بفهمید چه کسانی از طرفِ دامنهٔ شما ایمیل می‌فرستند؛ بعد به quarantine و در نهایت reject بروید. مستقیم رفتن به reject، ایمیلِ واقعیِ خودتان را هم می‌اندازد.", "code" => "_dmarc.example.com.  TXT  \"v=DMARC1; p=none; rua=mailto:dmarc@example.com\""],
        "en" => ["fix" => "Start at p=none and read the reports for a few weeks to learn who sends as your domain, then move to quarantine and finally reject. Jumping straight to reject will drop your own legitimate mail.", "code" => "_dmarc.example.com.  TXT  \"v=DMARC1; p=none; rua=mailto:dmarc@example.com\""],
        "tr" => ["fix" => "p=none ile baslayin, raporlari okuyun, sonra quarantine ve reject asamasina gecin.", "code" => "_dmarc.example.com.  TXT  \"v=DMARC1; p=none\""],
    ],
    "redirects" => [
        "fa" => ["fix" => "زنجیره را به یک پرش کوتاه کنید. الگوی رایجِ اشتباه این است: http → https → www → مسیرِ نهایی. همه را در یک قاعده ادغام کنید تا مستقیم به مقصدِ نهایی برود.", "code" => "# یک پرش، نه سه تا\nRewriteCond %{HTTPS} off [OR]\nRewriteCond %{HTTP_HOST} ^www\\. [NC]\nRewriteRule ^(.*)$ https://example.com/$1 [L,R=301]"],
        "en" => ["fix" => "Collapse the chain to a single hop. The classic mistake is http → https → www → final. Merge them into one rule that jumps straight to the final destination.", "code" => "RewriteCond %{HTTPS} off [OR]\nRewriteCond %{HTTP_HOST} ^www\\. [NC]\nRewriteRule ^(.*)$ https://example.com/$1 [L,R=301]"],
        "tr" => ["fix" => "Zinciri tek adima indirin; http, https ve www kurallarini birlestirin.", "code" => "RewriteRule ^(.*)$ https://example.com/$1 [L,R=301]"],
    ],
    "www_canonical" => [
        "fa" => ["fix" => "یکی را انتخاب کنید (با www یا بدونِ آن) و دیگری را با ۳۰۱ به همان بفرستید. هر دو باید در سرچ‌کنسول هم ثبت باشند تا داده‌ها گم نشود.", "code" => "RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]\nRewriteRule ^(.*)$ https://%1/$1 [L,R=301]"],
        "en" => ["fix" => "Pick one (www or apex) and 301 the other to it. Register both in Search Console so you do not lose historical data.", "code" => "RewriteCond %{HTTP_HOST} ^www\\.(.+)$ [NC]\nRewriteRule ^(.*)$ https://%1/$1 [L,R=301]"],
        "tr" => ["fix" => "Birini secin ve digerini 301 ile ona yonlendirin.", "code" => "RewriteRule ^(.*)$ https://%1/$1 [L,R=301]"],
    ],

    // ═══════════════════════ Mobile ═══════════════════════

    "viewport" => [
        "fa" => ["fix" => "🔴 بی‌این تگ، موبایل صفحه را مثلِ دسکتاپ کوچک می‌کند و متن خوانده نمی‌شود. یک خط، و مهم‌ترین خطِ موبایلِ صفحه.", "code" => "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"],
        "en" => ["fix" => "Without this tag phones render the page as a shrunken desktop and the text is unreadable. One line, and the single most important one for mobile.", "code" => "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"],
        "tr" => ["fix" => "Bu etiket olmadan mobilde sayfa kucultulmus masaustu gibi gorunur.", "code" => "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"],
    ],
    "charset" => [
        "fa" => ["fix" => "charset را در همان ۱۰۲۴ بایتِ اولِ صفحه اعلام کنید، وگرنه متنِ فارسی به‌هم‌ریخته نشان داده می‌شود.", "code" => "<meta charset=\"UTF-8\">"],
        "en" => ["fix" => "Declare the charset within the first 1024 bytes of the document, or non-Latin text renders as garbage.", "code" => "<meta charset=\"UTF-8\">"],
        "tr" => ["fix" => "charset bildirimini belgenin ilk 1024 baytinda yapin.", "code" => "<meta charset=\"UTF-8\">"],
    ],
    "font_size" => [
        "fa" => ["fix" => "متنِ اصلی را دستِ‌کم ۱۶px بگذارید. اندازهٔ کوچک‌تر روی موبایل باعث می‌شود کاربر زوم کند و بعد صفحه از قاب بزند بیرون.", "code" => "body { font-size: 16px; line-height: 1.7 }"],
        "en" => ["fix" => "Set body text to at least 16px. Smaller forces mobile users to pinch-zoom, after which the layout no longer fits the screen.", "code" => "body { font-size: 16px; line-height: 1.7 }"],
        "tr" => ["fix" => "Govde metnini en az 16px yapin.", "code" => "body { font-size: 16px }"],
    ],
    "fixed_width" => [
        "fa" => ["fix" => "عرضِ ثابتِ پیکسلی را با max-width و درصد جایگزین کنید تا صفحه روی هر عرضی جا شود.", "code" => ".container { max-width: 1200px; width: 100% }\nimg { max-width: 100% }"],
        "en" => ["fix" => "Replace fixed pixel widths with max-width plus a percentage so the layout fits any screen.", "code" => ".container { max-width: 1200px; width: 100% }\nimg { max-width: 100% }"],
        "tr" => ["fix" => "Sabit piksel genisliklerini max-width ile degistirin.", "code" => ".container { max-width: 1200px; width: 100% }"],
    ],
    "lazy_images" => [
        "fa" => ["fix" => "به تصاویرِ پایینِ صفحه loading=\"lazy\" بدهید و width و height را هم بنویسید تا هنگام بارگذاری صفحه نپرد. ⚠️ تصویرِ بالای صفحه را lazy نکنید؛ کندترش می‌کند.", "code" => "<img src=\"a.webp\" loading=\"lazy\" width=\"800\" height=\"600\" alt=\"…\">"],
        "en" => ["fix" => "Add loading=\"lazy\" to below-the-fold images and always set width and height so the layout does not jump. Do NOT lazy-load your hero image — it makes it slower.", "code" => "<img src=\"a.webp\" loading=\"lazy\" width=\"800\" height=\"600\" alt=\"…\">"],
        "tr" => ["fix" => "Ekran altindaki gorsellere loading=lazy ve width/height ekleyin.", "code" => "<img loading=\"lazy\" width=\"800\" height=\"600\">"],
    ],
    "apple_touch" => [
        "fa" => ["fix" => "یک PNG مربعِ ۱۸۰×۱۸۰ بگذارید تا وقتی کاربر سایت را به صفحهٔ اصلیِ آیفون اضافه می‌کند، آیکونِ برند شما را ببیند نه یک عکسِ بی‌کیفیت از صفحه.", "code" => "<link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"/apple-touch-icon.png\">"],
        "en" => ["fix" => "Provide a square 180x180 PNG so adding your site to an iPhone home screen shows your brand icon instead of a blurry screenshot.", "code" => "<link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"/apple-touch-icon.png\">"],
        "tr" => ["fix" => "180x180 kare PNG ekleyin.", "code" => "<link rel=\"apple-touch-icon\" sizes=\"180x180\" href=\"/apple-touch-icon.png\">"],
    ],
    "theme_color" => [
        "fa" => ["fix" => "رنگِ نوارِ مرورگرِ موبایل را با رنگِ برند هماهنگ کنید.", "code" => "<meta name=\"theme-color\" content=\"#0B1220\">"],
        "en" => ["fix" => "Match the mobile browser chrome to your brand colour.", "code" => "<meta name=\"theme-color\" content=\"#0B1220\">"],
        "tr" => ["fix" => "Mobil tarayici cubugunu marka renginize uydurun.", "code" => "<meta name=\"theme-color\" content=\"#0B1220\">"],
    ],

    // ═══════════════════════ Best practices ═══════════════════════

    "doctype" => [
        "fa" => ["fix" => "اولین خطِ فایل باید doctype باشد؛ بی‌آن مرورگر به حالتِ سازگاریِ قدیمی می‌رود و چیدمان غیرقابلِ پیش‌بینی می‌شود.", "code" => "<!doctype html>"],
        "en" => ["fix" => "The first line of the document must be the doctype; without it the browser falls back to quirks mode and layout becomes unpredictable.", "code" => "<!doctype html>"],
        "tr" => ["fix" => "Belgenin ilk satiri doctype olmali.", "code" => "<!doctype html>"],
    ],
    "favicon" => [
        "fa" => ["fix" => "یک SVG یا PNG کوچک در ریشه بگذارید و معرفی‌اش کنید.", "code" => "<link rel=\"icon\" href=\"/favicon.svg\" type=\"image/svg+xml\">"],
        "en" => ["fix" => "Put a small SVG or PNG at the root and declare it.", "code" => "<link rel=\"icon\" href=\"/favicon.svg\" type=\"image/svg+xml\">"],
        "tr" => ["fix" => "Koke kucuk bir SVG veya PNG koyup tanimlayin.", "code" => "<link rel=\"icon\" href=\"/favicon.svg\">"],
    ],
    "deprecated_tags" => [
        "fa" => ["fix" => "center و font و marquee را با CSS جایگزین کنید. مرورگرها هنوز تحملشان می‌کنند ولی رفتارشان تضمین‌شده نیست.", "code" => ".center { text-align: center }"],
        "en" => ["fix" => "Replace center, font and marquee with CSS. Browsers still tolerate them but their behaviour is not guaranteed.", "code" => ".center { text-align: center }"],
        "tr" => ["fix" => "center, font, marquee etiketlerini CSS ile degistirin.", "code" => ".center { text-align: center }"],
    ],
    "console_logs" => [
        "fa" => ["fix" => "لاگ‌های دیباگ را از نسخهٔ نهایی بردارید. گاهی داده‌ای که در کنسول چاپ می‌شود، همان چیزی است که نباید کسی ببیند.", "code" => null],
        "en" => ["fix" => "Strip debug logs from the production build. Sometimes what gets printed to the console is exactly what should not be visible.", "code" => null],
        "tr" => ["fix" => "Debug loglarini yayin surumunden cikarin.", "code" => null],
    ],
    "hreflang" => [
        "fa" => ["fix" => "اگر سایت چندزبانه است، هر نسخه باید به **همهٔ** نسخه‌ها (از جمله خودش) hreflang بدهد و یک x-default هم داشته باشد. یک‌طرفه بودنِ لینک‌ها باعث می‌شود گوگل نادیده‌شان بگیرد.", "code" => "<link rel=\"alternate\" hreflang=\"fa\" href=\"https://example.com/\">\n<link rel=\"alternate\" hreflang=\"en\" href=\"https://example.com/en/\">\n<link rel=\"alternate\" hreflang=\"x-default\" href=\"https://example.com/\">"],
        "en" => ["fix" => "On a multilingual site every version must link to ALL versions including itself, plus an x-default. One-way links are ignored by Google.", "code" => "<link rel=\"alternate\" hreflang=\"en\" href=\"https://example.com/en/\">\n<link rel=\"alternate\" hreflang=\"x-default\" href=\"https://example.com/\">"],
        "tr" => ["fix" => "Her dil surumu tum surumlere ve x-default adresine baglanti vermeli.", "code" => "<link rel=\"alternate\" hreflang=\"x-default\" href=\"https://example.com/\">"],
    ],
];
