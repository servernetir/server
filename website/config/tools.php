<?php

/*
|--------------------------------------------------------------------------
| متادیتای ابزار بررسی سایت — عنوان و توضیح هر چک (fa / en / tr)
|--------------------------------------------------------------------------
| کلیدها با SiteAudit::check() یکی هستند. توضیح‌ها کوتاه و راهنما.
*/

return [

    'categories' => [
        'seo'         => ['icon' => 'gauge',   'fa' => 'سئو', 'en' => 'SEO', 'tr' => 'SEO'],
        'performance' => ['icon' => 'zap',     'fa' => 'سرعت و پرفورمنس', 'en' => 'Performance', 'tr' => 'Performans'],
        'security'    => ['icon' => 'shield',  'fa' => 'امنیت', 'en' => 'Security', 'tr' => 'Güvenlik'],
        'mobile'      => ['icon' => 'smartphone', 'fa' => 'موبایل و تجربه کاربری', 'en' => 'Mobile & UX', 'tr' => 'Mobil & UX'],
        'best'        => ['icon' => 'check',    'fa' => 'بهترین‌روش‌ها', 'en' => 'Best practices', 'tr' => 'En iyi uygulamalar'],
    ],

    'checks' => [
        // SEO
        'title'           => ['fa' => ['t' => 'تگ عنوان (Title)', 'd' => 'طول ایده‌آل ۳۰ تا ۶۵ کاراکتر — مهم‌ترین عامل سئوی صفحه.'], 'en' => ['t' => 'Title tag', 'd' => 'Ideal length 30–65 chars — the single most important on-page SEO signal.'], 'tr' => ['t' => 'Başlık etiketi', 'd' => 'İdeal uzunluk 30–65 karakter.']],
        'description'     => ['fa' => ['t' => 'توضیحات متا', 'd' => 'طول ایده‌آل ۷۰ تا ۱۶۰ کاراکتر — متن زیر عنوان در نتایج گوگل.'], 'en' => ['t' => 'Meta description', 'd' => 'Ideal length 70–160 chars — the snippet under your title in Google.'], 'tr' => ['t' => 'Meta açıklama', 'd' => 'İdeal uzunluk 70–160 karakter.']],
        'h1'              => ['fa' => ['t' => 'تگ H1', 'd' => 'هر صفحه باید دقیقاً یک تیتر اصلی H1 داشته باشد.'], 'en' => ['t' => 'H1 heading', 'd' => 'Each page should have exactly one main H1 heading.'], 'tr' => ['t' => 'H1 başlığı', 'd' => 'Her sayfada tam olarak bir H1 olmalı.']],
        'headings'        => ['fa' => ['t' => 'ساختار تیترها', 'd' => 'استفاده منظم از H2 تا H6 به گوگل و کاربر کمک می‌کند محتوا را بفهمند.'], 'en' => ['t' => 'Heading structure', 'd' => 'A clear H2–H6 hierarchy helps both Google and readers.'], 'tr' => ['t' => 'Başlık yapısı', 'd' => 'Net H2–H6 hiyerarşisi önemlidir.']],
        'img_alt'         => ['fa' => ['t' => 'متن جایگزین تصاویر (alt)', 'd' => 'همه تصاویر باید متن alt داشته باشند — برای سئوی تصویر و دسترس‌پذیری.'], 'en' => ['t' => 'Image alt text', 'd' => 'All images should have alt text — for image SEO and accessibility.'], 'tr' => ['t' => 'Görsel alt metni', 'd' => 'Tüm görsellerde alt metni olmalı.']],
        'canonical'       => ['fa' => ['t' => 'تگ Canonical', 'd' => 'از جریمه محتوای تکراری جلوگیری و نسخه اصلی صفحه را مشخص می‌کند.'], 'en' => ['t' => 'Canonical tag', 'd' => 'Prevents duplicate-content issues by naming the primary URL.'], 'tr' => ['t' => 'Canonical etiketi', 'd' => 'Tekrarlanan içerik sorununu önler.']],
        'open_graph'      => ['fa' => ['t' => 'تگ‌های Open Graph', 'd' => 'نمای اشتراک‌گذاری در شبکه‌های اجتماعی را کنترل می‌کند (og:title، og:image…).'], 'en' => ['t' => 'Open Graph tags', 'd' => 'Controls how your page looks when shared on social media.'], 'tr' => ['t' => 'Open Graph etiketleri', 'd' => 'Sosyal medya paylaşım görünümünü kontrol eder.']],
        'twitter_card'    => ['fa' => ['t' => 'کارت توییتر (X)', 'd' => 'نمای اشتراک‌گذاری در توییتر/ایکس.'], 'en' => ['t' => 'Twitter (X) Card', 'd' => 'How your page previews on X/Twitter.'], 'tr' => ['t' => 'Twitter (X) Kartı', 'd' => "X/Twitter önizleme görünümü."]],
        'structured_data' => ['fa' => ['t' => 'داده ساختاریافته (Schema)', 'd' => 'JSON-LD به گوگل کمک می‌کند نتایج غنی (Rich Snippet) نمایش دهد.'], 'en' => ['t' => 'Structured data (Schema)', 'd' => 'JSON-LD helps Google show rich snippets.'], 'tr' => ['t' => 'Yapılandırılmış veri', 'd' => 'JSON-LD zengin sonuçlara yardımcı olur.']],
        'robots_meta'     => ['fa' => ['t' => 'ایندکس‌پذیری (robots)', 'd' => 'مطمئن شوید صفحه noindex نیست وگرنه در گوگل نمایش داده نمی‌شود.'], 'en' => ['t' => 'Indexability (robots)', 'd' => 'Make sure the page is not noindex, or Google will skip it.'], 'tr' => ['t' => 'İndekslenebilirlik', 'd' => 'Sayfa noindex olmamalı.']],
        'robots_txt'      => ['fa' => ['t' => 'فایل robots.txt', 'd' => 'به موتورهای جستجو می‌گوید کدام بخش‌ها را بخزند.'], 'en' => ['t' => 'robots.txt file', 'd' => 'Tells search engines what to crawl.'], 'tr' => ['t' => 'robots.txt dosyası', 'd' => 'Arama motorlarına ne tarayacağını söyler.']],
        'sitemap'         => ['fa' => ['t' => 'نقشه سایت (sitemap.xml)', 'd' => 'ایندکس شدن کامل و سریع‌تر صفحات را تضمین می‌کند.'], 'en' => ['t' => 'Sitemap (sitemap.xml)', 'd' => 'Ensures faster, complete indexing of your pages.'], 'tr' => ['t' => 'Site haritası', 'd' => 'Daha hızlı indekslemeyi sağlar.']],
        'lang'            => ['fa' => ['t' => 'زبان صفحه (lang)', 'd' => 'اتریبیوت lang روی تگ html برای موتور جستجو و اسکرین‌ریدرها.'], 'en' => ['t' => 'Page language (lang)', 'd' => 'The html lang attribute for search engines and screen readers.'], 'tr' => ['t' => 'Sayfa dili (lang)', 'd' => 'html lang özniteliği.']],
        'links'           => ['fa' => ['t' => 'لینک‌های داخلی', 'd' => 'لینک‌دهی داخلی به خزش و توزیع اعتبار صفحات کمک می‌کند.'], 'en' => ['t' => 'Internal links', 'd' => 'Internal linking aids crawling and spreads page authority.'], 'tr' => ['t' => 'Dahili bağlantılar', 'd' => 'Taramaya yardımcı olur.']],

        // Performance
        'ttfb'            => ['fa' => ['t' => 'زمان اولین بایت (TTFB)', 'd' => 'سرعت پاسخ سرور — زیر ۶۰۰ میلی‌ثانیه عالی است.'], 'en' => ['t' => 'Time to First Byte', 'd' => 'Server response speed — under 600 ms is excellent.'], 'tr' => ['t' => 'İlk Bayt Süresi', 'd' => '600 ms altı mükemmeldir.']],
        'load_time'       => ['fa' => ['t' => 'زمان بارگذاری کامل', 'd' => 'مدت دریافت کل HTML صفحه.'], 'en' => ['t' => 'Full load time', 'd' => 'Total time to download the page HTML.'], 'tr' => ['t' => 'Tam yükleme süresi', 'd' => 'HTML indirme süresi.']],
        'page_size'       => ['fa' => ['t' => 'حجم صفحه (HTML)', 'd' => 'صفحات سبک‌تر سریع‌تر بارگذاری می‌شوند.'], 'en' => ['t' => 'Page size (HTML)', 'd' => 'Lighter pages load faster.'], 'tr' => ['t' => 'Sayfa boyutu', 'd' => 'Hafif sayfalar daha hızlı yüklenir.']],
        'compression'     => ['fa' => ['t' => 'فشرده‌سازی (Gzip/Brotli)', 'd' => 'حجم انتقال را تا ۷۰٪ کم می‌کند — باید حتماً فعال باشد.'], 'en' => ['t' => 'Compression (Gzip/Brotli)', 'd' => 'Cuts transfer size up to 70% — should always be on.'], 'tr' => ['t' => 'Sıkıştırma (Gzip/Brotli)', 'd' => 'Aktarım boyutunu %70 azaltır.']],
        'js_requests'     => ['fa' => ['t' => 'تعداد فایل‌های JS', 'd' => 'فایل‌های اسکریپت زیاد رندر را کند می‌کنند.'], 'en' => ['t' => 'JS files', 'd' => 'Too many scripts slow down rendering.'], 'tr' => ['t' => 'JS dosyaları', 'd' => 'Çok fazla script render\'ı yavaşlatır.']],
        'css_requests'    => ['fa' => ['t' => 'تعداد فایل‌های CSS', 'd' => 'ادغام فایل‌های استایل تعداد درخواست‌ها را کم می‌کند.'], 'en' => ['t' => 'CSS files', 'd' => 'Merging stylesheets reduces requests.'], 'tr' => ['t' => 'CSS dosyaları', 'd' => 'Birleştirme istekleri azaltır.']],
        'image_count'     => ['fa' => ['t' => 'تعداد تصاویر', 'd' => 'تصاویر زیاد بدون lazy-load بارگذاری را سنگین می‌کند.'], 'en' => ['t' => 'Image count', 'd' => 'Many images without lazy-loading add weight.'], 'tr' => ['t' => 'Görsel sayısı', 'd' => 'Çok görsel yükü artırır.']],
        'caching'         => ['fa' => ['t' => 'کش مرورگر', 'd' => 'هدر Cache-Control بازدیدهای بعدی را فوری می‌کند.'], 'en' => ['t' => 'Browser caching', 'd' => 'Cache-Control makes repeat visits instant.'], 'tr' => ['t' => 'Tarayıcı önbelleği', 'd' => 'Cache-Control tekrar ziyaretleri hızlandırır.']],
        'http_version'    => ['fa' => ['t' => 'نسخه HTTP', 'd' => 'HTTP/2 و HTTP/3 موازی‌سازی و سرعت بیشتری دارند.'], 'en' => ['t' => 'HTTP version', 'd' => 'HTTP/2 and HTTP/3 are faster and multiplexed.'], 'tr' => ['t' => 'HTTP sürümü', 'd' => 'HTTP/2 ve HTTP/3 daha hızlıdır.']],
        'inline_styles'   => ['fa' => ['t' => 'استایل‌های اینلاین', 'd' => 'استایل درون‌خطی زیاد نگهداری و کش را سخت می‌کند.'], 'en' => ['t' => 'Inline styles', 'd' => 'Excessive inline styles hurt caching and maintenance.'], 'tr' => ['t' => 'Satır içi stiller', 'd' => 'Aşırı inline stil önbelleği zorlaştırır.']],

        // Security
        'https'             => ['fa' => ['t' => 'اتصال امن HTTPS', 'd' => 'پایه امنیت و اعتماد — بدون آن مرورگرها هشدار می‌دهند.'], 'en' => ['t' => 'HTTPS encryption', 'd' => 'The baseline of trust — without it browsers warn users.'], 'tr' => ['t' => 'HTTPS şifreleme', 'd' => 'Güvenin temeli.']],
        'hsts'              => ['fa' => ['t' => 'هدر HSTS', 'd' => 'مرورگر را مجبور می‌کند همیشه از HTTPS استفاده کند.'], 'en' => ['t' => 'HSTS header', 'd' => 'Forces the browser to always use HTTPS.'], 'tr' => ['t' => 'HSTS başlığı', 'd' => 'Tarayıcıyı HTTPS\'e zorlar.']],
        'x_content_type'    => ['fa' => ['t' => 'X-Content-Type-Options', 'd' => 'جلوی حملات MIME-sniffing را می‌گیرد.'], 'en' => ['t' => 'X-Content-Type-Options', 'd' => 'Blocks MIME-sniffing attacks.'], 'tr' => ['t' => 'X-Content-Type-Options', 'd' => 'MIME-sniffing saldırılarını engeller.']],
        'x_frame'           => ['fa' => ['t' => 'محافظت Clickjacking', 'd' => 'X-Frame-Options یا CSP frame-ancestors از iframe مخرب جلوگیری می‌کند.'], 'en' => ['t' => 'Clickjacking protection', 'd' => 'X-Frame-Options or CSP frame-ancestors blocks malicious iframes.'], 'tr' => ['t' => 'Clickjacking koruması', 'd' => 'Kötü iframe\'leri engeller.']],
        'csp'               => ['fa' => ['t' => 'Content-Security-Policy', 'd' => 'قوی‌ترین سپر در برابر XSS و تزریق اسکریپت.'], 'en' => ['t' => 'Content-Security-Policy', 'd' => 'The strongest shield against XSS and script injection.'], 'tr' => ['t' => 'Content-Security-Policy', 'd' => 'XSS\'e karşı en güçlü kalkan.']],
        'referrer_policy'   => ['fa' => ['t' => 'Referrer-Policy', 'd' => 'کنترل می‌کند چه اطلاعاتی هنگام کلیک به سایت مقصد می‌رود.'], 'en' => ['t' => 'Referrer-Policy', 'd' => 'Controls what referrer info leaks on outbound clicks.'], 'tr' => ['t' => 'Referrer-Policy', 'd' => 'Yönlendiren bilgisini kontrol eder.']],
        'permissions_policy'=> ['fa' => ['t' => 'Permissions-Policy', 'd' => 'دسترسی به دوربین، میکروفون و موقعیت را محدود می‌کند.'], 'en' => ['t' => 'Permissions-Policy', 'd' => 'Restricts access to camera, mic and geolocation.'], 'tr' => ['t' => 'Permissions-Policy', 'd' => 'Kamera/mikrofon erişimini kısıtlar.']],
        'server_disclosure' => ['fa' => ['t' => 'افشای نسخه سرور', 'd' => 'نمایش نسخه دقیق وب‌سرور به مهاجم سرنخ می‌دهد.'], 'en' => ['t' => 'Server version disclosure', 'd' => 'Exposing the exact server version helps attackers.'], 'tr' => ['t' => 'Sunucu sürüm ifşası', 'd' => 'Saldırganlara ipucu verir.']],
        'powered_by'        => ['fa' => ['t' => 'هدر X-Powered-By', 'd' => 'تکنولوژی بک‌اند را لو می‌دهد — بهتر است حذف شود.'], 'en' => ['t' => 'X-Powered-By header', 'd' => 'Reveals your backend tech — best removed.'], 'tr' => ['t' => 'X-Powered-By başlığı', 'd' => 'Backend teknolojisini ifşa eder.']],
        'mixed_content'     => ['fa' => ['t' => 'محتوای ناامن (Mixed)', 'd' => 'منابع http روی صفحه https اتصال را ناامن و ناقص می‌کند.'], 'en' => ['t' => 'Mixed content', 'd' => 'HTTP resources on an HTTPS page break security.'], 'tr' => ['t' => 'Karışık içerik', 'd' => 'HTTPS sayfada HTTP kaynak güvenliği bozar.']],

        // Mobile
        'viewport'    => ['fa' => ['t' => 'متا Viewport', 'd' => 'بدون آن سایت روی موبایل درست مقیاس نمی‌شود — حیاتی.'], 'en' => ['t' => 'Viewport meta', 'd' => 'Without it the site won\'t scale on mobile — critical.'], 'tr' => ['t' => 'Viewport meta', 'd' => 'Mobil ölçekleme için kritik.']],
        'charset'     => ['fa' => ['t' => 'کدگذاری کاراکتر', 'd' => 'charset صحیح از نمایش به‌هم‌ریخته متن جلوگیری می‌کند.'], 'en' => ['t' => 'Charset declaration', 'd' => 'Correct charset prevents garbled text.'], 'tr' => ['t' => 'Karakter kodlaması', 'd' => 'Bozuk metni önler.']],
        'font_size'   => ['fa' => ['t' => 'اندازه فونت خوانا', 'd' => 'فونت زیر ۱۲px روی موبایل سخت خوانده می‌شود.'], 'en' => ['t' => 'Legible font size', 'd' => 'Fonts under 12px are hard to read on mobile.'], 'tr' => ['t' => 'Okunabilir yazı boyutu', 'd' => '12px altı mobilde zor okunur.']],
        'tap_targets' => ['fa' => ['t' => 'اندازه دکمه‌های لمسی', 'd' => 'دکمه‌ها باید به اندازه کافی بزرگ و از هم فاصله داشته باشند.'], 'en' => ['t' => 'Tap target size', 'd' => 'Buttons should be large enough and well spaced.'], 'tr' => ['t' => 'Dokunma hedefi boyutu', 'd' => 'Butonlar yeterince büyük olmalı.']],
        'apple_touch' => ['fa' => ['t' => 'آیکون Apple Touch', 'd' => 'آیکون هنگام افزودن به صفحه اصلی آیفون.'], 'en' => ['t' => 'Apple touch icon', 'd' => 'The icon used when added to an iPhone home screen.'], 'tr' => ['t' => 'Apple touch ikonu', 'd' => 'iPhone ana ekran ikonu.']],
        'theme_color' => ['fa' => ['t' => 'رنگ تم موبایل', 'd' => 'رنگ نوار مرورگر موبایل را هماهنگ با برند می‌کند.'], 'en' => ['t' => 'Theme color', 'd' => 'Matches the mobile browser bar to your brand.'], 'tr' => ['t' => 'Tema rengi', 'd' => 'Mobil tarayıcı çubuğunu markaya uydurur.']],

        // Best practices
        'doctype'         => ['fa' => ['t' => 'اعلان DOCTYPE', 'd' => '<!doctype html> مرورگر را در حالت استاندارد نگه می‌دارد.'], 'en' => ['t' => 'DOCTYPE declaration', 'd' => '<!doctype html> keeps the browser in standards mode.'], 'tr' => ['t' => 'DOCTYPE bildirimi', 'd' => 'Standart modu korur.']],
        'favicon'         => ['fa' => ['t' => 'فاوآیکون', 'd' => 'آیکون کوچک تب مرورگر — بخشی از هویت برند.'], 'en' => ['t' => 'Favicon', 'd' => 'The little browser-tab icon — part of your brand.'], 'tr' => ['t' => 'Favicon', 'd' => 'Tarayıcı sekmesi ikonu.']],
        'deprecated_tags' => ['fa' => ['t' => 'تگ‌های منسوخ', 'd' => 'تگ‌هایی مثل center و font دیگر پشتیبانی نمی‌شوند.'], 'en' => ['t' => 'Deprecated tags', 'd' => 'Tags like center and font are obsolete.'], 'tr' => ['t' => 'Eski etiketler', 'd' => 'center, font gibi etiketler eskidi.']],
        'console_logs'    => ['fa' => ['t' => 'console.log باقی‌مانده', 'd' => 'لاگ‌های دیباگ نباید در نسخه نهایی بمانند.'], 'en' => ['t' => 'Leftover console.log', 'd' => 'Debug logs should not ship to production.'], 'tr' => ['t' => 'Kalan console.log', 'd' => 'Debug logları kalmamalı.']],
        'hreflang'        => ['fa' => ['t' => 'تگ hreflang', 'd' => 'برای سایت‌های چندزبانه نسخه زبانی درست را به گوگل معرفی می‌کند.'], 'en' => ['t' => 'hreflang tags', 'd' => 'Tells Google the right language version for multilingual sites.'], 'tr' => ['t' => 'hreflang etiketleri', 'd' => 'Çok dilli siteler için dil sürümünü belirtir.']],
    ],
];
