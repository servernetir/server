<?php

return [

    'categories' => [

        'text' => [
            'icon' => 'code',
            'fa' => ['t' => 'متن و کد', 'd' => 'تبدیل، قالب‌بندی و بررسی متن و داده'],
            'en' => ['t' => 'Text & Code', 'd' => 'Convert, format and inspect text and data'],
            'tr' => ['t' => 'Metin & Kod', 'd' => 'Metin ve veriyi dönüştür, biçimlendir, incele'],
            'tools' => [
                'json-formatter' => [
                    'icon' => 'code',
                    'fa' => ['t' => 'قالب‌بند و اعتبارسنج JSON', 'd' => 'JSON را مرتب کنید، خطای ساختاری را دقیق ببینید و فشرده‌اش کنید.'],
                    'en' => ['t' => 'JSON Formatter & Validator', 'd' => 'Pretty-print JSON, see the exact syntax error, and minify.'],
                    'tr' => ['t' => 'JSON Biçimlendirici & Doğrulayıcı', 'd' => "JSON'u düzenleyin, sözdizimi hatasını görün ve küçültün."],
                ],
                'base64' => [
                    'icon' => 'restore',
                    'fa' => ['t' => 'رمزگذار و رمزگشای Base64', 'd' => 'تبدیل متن به Base64 و برعکس، با پشتیبانی کامل از فارسی.'],
                    'en' => ['t' => 'Base64 Encoder & Decoder', 'd' => 'Convert text to Base64 and back, with full Unicode support.'],
                    'tr' => ['t' => 'Base64 Kodlayıcı & Çözücü', 'd' => "Metni Base64'e çevirin ve geri alın, tam Unicode desteğiyle."],
                ],
                'url-encoder' => [
                    'icon' => 'link',
                    'fa' => ['t' => 'رمزگذار و رمزگشای URL', 'd' => 'کدگذاری پارامترهای آدرس و رمزگشایی آدرس‌های درهم.'],
                    'en' => ['t' => 'URL Encoder & Decoder', 'd' => 'Encode query parameters and decode messy URLs.'],
                    'tr' => ['t' => 'URL Kodlayıcı & Çözücü', 'd' => "Sorgu parametrelerini kodlayın, karmaşık URL'leri çözün."],
                ],
                'jwt-decoder' => [
                    'icon' => 'key',
                    'fa' => ['t' => 'رمزگشای توکن JWT', 'd' => 'محتوای هدر و payload توکن را ببینید و زمان انقضا را بررسی کنید.'],
                    'en' => ['t' => 'JWT Decoder', 'd' => "Inspect a token's header and payload and check its expiry."],
                    'tr' => ['t' => 'JWT Çözücü', 'd' => "Token başlığını ve payload'ını inceleyin, süresini kontrol edin."],
                ],
                'case-converter' => [
                    'icon' => 'book',
                    'fa' => ['t' => 'تبدیل حالت حروف', 'd' => 'camelCase، snake_case، kebab-case، Title Case و بیشتر.'],
                    'en' => ['t' => 'Case Converter', 'd' => 'camelCase, snake_case, kebab-case, Title Case and more.'],
                    'tr' => ['t' => 'Harf Durumu Dönüştürücü', 'd' => 'camelCase, snake_case, kebab-case, Title Case ve daha fazlası.'],
                ],
                'text-counter' => [
                    'icon' => 'gauge',
                    'fa' => ['t' => 'شمارنده‌ی کلمه و کاراکتر', 'd' => 'شمارش کلمه، کاراکتر، پاراگراف و زمان تقریبی مطالعه.'],
                    'en' => ['t' => 'Word & Character Counter', 'd' => 'Count words, characters, paragraphs and reading time.'],
                    'tr' => ['t' => 'Kelime & Karakter Sayacı', 'd' => 'Kelime, karakter, paragraf ve okuma süresini sayın.'],
                ],
                'slug-generator' => [
                    'icon' => 'link',
                    'fa' => ['t' => 'سازنده‌ی اسلاگ سئو', 'd' => 'تبدیل عنوان فارسی یا انگلیسی به آدرس تمیز و سئوپسند.'],
                    'en' => ['t' => 'SEO Slug Generator', 'd' => 'Turn a Persian or English title into a clean, SEO-friendly URL.'],
                    'tr' => ['t' => 'SEO Slug Oluşturucu', 'd' => "Başlığı temiz, SEO dostu bir URL'ye çevirin."],
                ],
                'lorem-ipsum' => [
                    'icon' => 'book',
                    'fa' => ['t' => 'متن ساختگی (فارسی و لاتین)', 'd' => 'تولید متن نمونه برای طراحی — با متن فارسی واقعی، نه لاتین.'],
                    'en' => ['t' => 'Lorem Ipsum Generator', 'd' => 'Placeholder text for mockups — Latin or real Persian.'],
                    'tr' => ['t' => 'Lorem Ipsum Üreteci', 'd' => 'Tasarım için örnek metin — Latince veya Farsça.'],
                ],
                'css-formatter' => [
                    'icon' => 'code',
                    'fa' => ['t' => 'فرمت‌کنندهٔ CSS', 'd' => 'CSS فشرده را خوانا کن؛ تورفتگی دلخواه، هر اعلان در یک خط، بدون دست‌زدن به رشته‌ها'],
                    'en' => ['t' => 'CSS Formatter & Beautifier', 'd' => 'Beautify minified CSS with custom indentation, one declaration per line, strings intact'],
                    'tr' => ['t' => 'CSS Biçimlendirici', 'd' => 'Küçültülmüş CSS kodunu okunur hale getirin; girinti seçimi ve satır başına tek bildirim'],
                ],
                'css-minifier' => [
                    'icon' => 'zap',
                    'fa' => ['t' => 'فشرده‌ساز CSS', 'd' => 'حذف کامنت و فاصله‌های اضافی، کوتاه‌سازی رنگ‌ها و نمایش دقیق کاهش حجم.'],
                    'en' => ['t' => 'CSS Minifier', 'd' => 'Strip comments and whitespace, shorten colours, and see exactly how many bytes you save.'],
                    'tr' => ['t' => 'CSS Kucultucu', 'd' => 'Yorumlari ve bosluklari silin, renkleri kisaltin, kazanilan bayti gorun.'],
                ],
                'html-formatter' => [
                    'icon' => 'layout',
                    'fa' => ['t' => 'قالب‌بند و مرتب‌کننده HTML', 'd' => 'تورفتگی مرتب HTML با حفظ کامل pre و textarea و هشدار تگ‌های بسته‌نشده.'],
                    'en' => ['t' => 'HTML Formatter & Beautifier', 'd' => 'Re-indent HTML, keep pre and textarea byte-exact, and flag unbalanced tags.'],
                    'tr' => ['t' => 'HTML Biçimlendirici ve Düzenleyici', 'd' => 'HTML girintisini düzenleyin; pre ve textarea korunur, açık etiketler bildirilir.'],
                ],
                'html-minifier' => [
                    'icon' => 'code',
                    'fa' => ['t' => 'فشرده‌ساز HTML', 'd' => 'حذف کامنت، فشرده‌سازی فاصله‌ها و ویژگی‌های زائد، بدون دست‌زدن به pre و script'],
                    'en' => ['t' => 'HTML Minifier', 'd' => 'Strip comments, collapse whitespace and drop redundant attributes — safely.'],
                    'tr' => ['t' => 'HTML Kucultucu', 'd' => 'Yorumlari, fazla bosluklari ve gereksiz ozellikleri guvenle temizleyin.'],
                ],
            ],
        ],

        'web' => [
            'icon' => 'globe',
            'fa' => ['t' => 'وب و سئو', 'd' => 'ابزارهای روزمره‌ی وب‌مستر و بهینه‌سازی'],
            'en' => ['t' => 'Web & SEO', 'd' => 'Everyday webmaster and optimisation helpers'],
            'tr' => ['t' => 'Web & SEO', 'd' => 'Günlük web yöneticisi ve optimizasyon araçları'],
            'tools' => [
                'meta-tag-generator' => [
                    'icon' => 'layout',
                    'fa' => ['t' => 'سازنده‌ی متاتگ و Open Graph', 'd' => 'ساخت تگ‌های عنوان، توضیحات، OG و توییتر با پیش‌نمایش زنده.'],
                    'en' => ['t' => 'Meta Tag & Open Graph Generator', 'd' => 'Build title, description, OG and Twitter tags with a live preview.'],
                    'tr' => ['t' => 'Meta Etiket & Open Graph Üreteci', 'd' => 'Başlık, açıklama, OG ve Twitter etiketlerini canlı önizlemeyle oluşturun.'],
                ],
                'robots-generator' => [
                    'icon' => 'shield',
                    'fa' => ['t' => 'سازنده‌ی robots.txt', 'd' => 'ساخت فایل robots با قواعد درست و بدون اشتباه رایج.'],
                    'en' => ['t' => 'robots.txt Generator', 'd' => 'Build a correct robots file without the usual mistakes.'],
                    'tr' => ['t' => 'robots.txt Üreteci', 'd' => 'Yaygın hatalar olmadan doğru robots dosyası oluşturun.'],
                ],
                'htaccess-redirect' => [
                    'icon' => 'flow',
                    'fa' => ['t' => 'سازنده‌ی ریدایرکت htaccess', 'd' => 'قواعد آماده برای HTTPS، www، ریدایرکت صفحه و دامنه.'],
                    'en' => ['t' => '.htaccess Redirect Generator', 'd' => 'Ready rules for HTTPS, www, page and domain redirects.'],
                    'tr' => ['t' => '.htaccess Yönlendirme Üreteci', 'd' => 'HTTPS, www, sayfa ve alan adı yönlendirmeleri için hazır kurallar.'],
                ],
                'utm-builder' => [
                    'icon' => 'trend',
                    'fa' => ['t' => 'لینک‌ساز UTM', 'd' => 'ساخت لینک کمپین با پارامترهای استاندارد گوگل آنالیتیکس.'],
                    'en' => ['t' => 'UTM Link Builder', 'd' => 'Build campaign links with standard Google Analytics parameters.'],
                    'tr' => ['t' => 'UTM Bağlantı Oluşturucu', 'd' => 'Standart Google Analytics parametreleriyle kampanya bağlantısı oluşturun.'],
                ],
                'html-entities' => [
                    'icon' => 'code',
                    'fa' => ['t' => 'تبدیل موجودیت‌های HTML', 'd' => 'تبدیل کاراکترهای خاص به entity و برعکس — برای نمایش امن کد در صفحه.'],
                    'en' => ['t' => 'HTML Entity Encoder & Decoder', 'd' => 'Convert special characters to entities and back — for safely showing code on a page.'],
                    'tr' => ['t' => 'HTML Entity Kodlayıcı & Çözücü', 'd' => "Özel karakterleri entity'lere çevirin ve geri alın."],
                ],
            ],
        ],

        'design' => [
            'icon' => 'sparkles',
            'fa' => ['t' => 'طراحی و CSS', 'd' => 'رنگ، گرادیان و کد آماده‌ی CSS'],
            'en' => ['t' => 'Design & CSS', 'd' => 'Colours, gradients and ready-made CSS'],
            'tr' => ['t' => 'Tasarım & CSS', 'd' => 'Renkler, gradyanlar ve hazır CSS'],
            'tools' => [
                'color-converter' => [
                    'icon' => 'sparkles',
                    'fa' => ['t' => 'تبدیل رنگ HEX / RGB / HSL', 'd' => 'تبدیل بین فرمت‌های رنگ با انتخابگر و بررسی کنتراست.'],
                    'en' => ['t' => 'Colour Converter (HEX/RGB/HSL)', 'd' => 'Convert between colour formats with a picker and contrast check.'],
                    'tr' => ['t' => 'Renk Dönüştürücü (HEX/RGB/HSL)', 'd' => 'Renk formatları arasında dönüştürün, kontrastı kontrol edin.'],
                ],
                'gradient-generator' => [
                    'icon' => 'sparkles',
                    'fa' => ['t' => 'سازنده‌ی گرادیان CSS', 'd' => 'ساخت گرادیان خطی و شعاعی با خروجی CSS آماده.'],
                    'en' => ['t' => 'CSS Gradient Generator', 'd' => 'Build linear and radial gradients with ready CSS output.'],
                    'tr' => ['t' => 'CSS Gradyan Üreteci', 'd' => 'Doğrusal ve radyal gradyanlar oluşturun, hazır CSS alın.'],
                ],
                'box-shadow' => [
                    'icon' => 'layout',
                    'fa' => ['t' => 'سازنده‌ی سایه CSS', 'd' => 'تنظیم سایه با پیش‌نمایش زنده و کپی مستقیم کد.'],
                    'en' => ['t' => 'CSS Box-Shadow Generator', 'd' => 'Dial in a shadow with live preview and copy the code.'],
                    'tr' => ['t' => 'CSS Box-Shadow Üreteci', 'd' => 'Canlı önizlemeyle gölge ayarlayın ve kodu kopyalayın.'],
                ],
                'border-radius-generator' => [
                    'icon' => 'box',
                    'fa' => ['t' => 'سازندهٔ گِردی گوشه CSS', 'd' => 'چهار اسلایدر گوشه به‌همراه حالت بیضویِ هشت‌مقداری، پیش‌نمایش زنده و کدِ CSS آمادهٔ کپی.'],
                    'en' => ['t' => 'CSS Border Radius Generator', 'd' => 'Four corner sliders plus an 8-value elliptical mode with live preview and copy-ready CSS.'],
                    'tr' => ['t' => 'CSS Border Radius Olusturucu', 'd' => 'Dort kose kaydiricisi ve sekiz degerli eliptik mod, canli onizleme ve kopyalanabilir CSS.'],
                ],
                'clip-path-generator' => [
                    'icon' => 'layout',
                    'fa' => ['t' => 'مولد clip-path در CSS', 'd' => 'با کشیدن نقطه‌ها شکل بسازید و کد clip-path را برای چندضلعی، دایره، بیضی و inset بگیرید.'],
                    'en' => ['t' => 'CSS clip-path Generator', 'd' => 'Drag vertices to craft shapes and copy clip-path CSS for polygon, circle, ellipse and inset.'],
                    'tr' => ['t' => 'CSS clip-path Olusturucu', 'd' => 'Noktalari surukleyerek sekil olusturun; cokgen, daire, elips ve inset icin clip-path CSS kodu alin.'],
                ],
                'color-shades' => [
                    'icon' => 'layout',
                    'fa' => ['t' => 'سازنده طیف رنگ (۵۰ تا ۹۰۰)', 'd' => 'از یک رنگ پایه، طیف ده‌مرحله‌ای ۵۰–۹۰۰ با کد HEX و نسبت کنتراست WCAG بسازید.'],
                    'en' => ['t' => 'Colour Shades & Tints Generator', 'd' => 'Build a 10-step 50-900 scale from one base colour, with HEX and WCAG contrast ratios.'],
                    'tr' => ['t' => 'Renk Tonu ve Aciklik Uretici', 'd' => 'Tek bir ana renkten HEX ve WCAG kontrast oranlariyla 10 adimli 50-900 olcegi olusturun.'],
                ],
                'css-loader' => [
                    'icon' => 'restore',
                    'fa' => ['t' => 'سازنده‌ی لودر و اسپینر CSS', 'd' => '۱۲ لودر خالص CSS با تنظیم اندازه، رنگ و سرعت و کد آماده‌ی HTML و CSS.'],
                    'en' => ['t' => 'CSS Loader & Spinner Generator', 'd' => '12 pure-CSS loaders with size, colour and speed controls and ready-to-paste code.'],
                    'tr' => ['t' => 'CSS Yükleniyor Animasyonu Üreteci', 'd' => 'Boyut, renk ve hız ayarlı 12 saf CSS yükleyici; kopyalamaya hazır kod.'],
                ],
                'css-triangle' => [
                    'icon' => 'layout',
                    'fa' => ['t' => 'ساخت مثلث CSS', 'd' => 'ساخت مثلث CSS با ترفند border در هشت جهت، با پیش‌نمایش زنده و خروجی clip-path'],
                    'en' => ['t' => 'CSS Triangle Generator', 'd' => 'Generate CSS triangles with the border trick in 8 directions, live preview and clip-path'],
                    'tr' => ['t' => 'CSS Üçgen Oluşturucu', 'd' => 'border hilesiyle 8 yönde CSS üçgeni oluşturun: canlı önizleme ve clip-path çıktısı'],
                ],
                'cubic-bezier' => [
                    'icon' => 'trend',
                    'fa' => ['t' => 'ویرایشگر منحنی cubic-bezier', 'd' => 'ویرایش منحنی شتاب CSS با نقاط کنترل کشیدنی، پیش‌نمایش زنده و خروجی cubic-bezier()'],
                    'en' => ['t' => 'CSS Cubic Bezier Editor', 'd' => 'Edit CSS easing curves with draggable control points, live preview and copyable output'],
                    'tr' => ['t' => 'CSS Cubic Bezier Düzenleyici', 'd' => 'CSS hız eğrilerini sürüklenebilir kontrol noktaları ve canlı önizleme ile düzenleyin'],
                ],
                'palette-generator' => [
                    'icon' => 'sparkles',
                    'fa' => ['t' => 'تولید پالت رنگ', 'd' => 'ساخت پالت رنگ از روی هارمونی: مکمل، مشابه، سه‌گانه، چهارگانه و تک‌فام'],
                    'en' => ['t' => 'Colour Palette Generator', 'd' => 'Build palettes from hue geometry: complementary, analogous, triadic, tetradic, mono.'],
                    'tr' => ['t' => 'Renk Paleti Oluşturucu', 'd' => 'Renk uyumundan palet üretin: tamamlayıcı, benzer, üçlü, dörtlü ve tek renkli.'],
                ],
                'bg-pattern' => [
                    'icon' => 'layout',
                    'fa' => ['t' => 'سازندهٔ الگوی پس‌زمینه CSS', 'd' => 'الگوی هندسی پس‌زمینه فقط با گرادیان CSS؛ رنگ، اندازه و زاویه را تنظیم و کد را کپی کنید.'],
                    'en' => ['t' => 'CSS Background Pattern Generator', 'd' => 'Build geometric CSS background patterns from gradients and copy the ready-to-use CSS.'],
                    'tr' => ['t' => 'CSS Arka Plan Deseni Oluşturucu', 'd' => 'Gradyanlarla geometrik CSS arka plan desenleri oluşturun ve hazır kodu kopyalayın.'],
                ],
                'color-mixer' => [
                    'icon' => 'sparkles',
                    'fa' => ['t' => 'ترکیب رنگ (sRGB و OKLab)', 'd' => 'ترکیب دو رنگ در sRGB و OKLab کنار هم، با پله‌های میانی و خروجی هگز و CSS'],
                    'en' => ['t' => 'Colour Mixer (sRGB vs OKLab)', 'd' => 'Mix two colours in plain sRGB and in OKLab side by side, with a hex ramp.'],
                    'tr' => ['t' => 'Renk Karıştırıcı (sRGB ve OKLab)', 'd' => 'İki rengi sRGB ve OKLab uzaylarında yan yana karıştırın, hex rampası alın.'],
                ],
                'glassmorphism' => [
                    'icon' => 'box',
                    'fa' => ['t' => 'سازنده‌ی افکت شیشه‌ای CSS', 'd' => 'افکت شیشه‌ی مات را زنده تنظیم کنید و CSS آماده با پیشوند webkit بگیرید.'],
                    'en' => ['t' => 'CSS Glassmorphism Generator', 'd' => 'Dial in a frosted-glass card live and copy ready CSS with the -webkit- prefix.'],
                    'tr' => ['t' => 'CSS Glassmorphism Üreteci', 'd' => 'Buzlu cam kartı canlı ayarlayın, webkit önekli hazır CSS kodunu kopyalayın.'],
                ],
            ],
        ],

        'convert' => [
            'icon' => 'restore',
            'fa' => ['t' => 'تبدیل و محاسبه', 'd' => 'تاریخ، زمان، واحد و رمز'],
            'en' => ['t' => 'Convert & Calculate', 'd' => 'Dates, time, units and secrets'],
            'tr' => ['t' => 'Dönüştür & Hesapla', 'd' => 'Tarih, zaman, birim ve gizli anahtarlar'],
            'tools' => [
                'jalali-converter' => [
                    'icon' => 'clock',
                    'fa' => ['t' => 'تبدیل تاریخ شمسی و میلادی', 'd' => 'تبدیل دقیق بین تقویم جلالی، میلادی و قمری.'],
                    'en' => ['t' => 'Jalali ↔ Gregorian Date Converter', 'd' => 'Accurate conversion between Jalali, Gregorian and Hijri calendars.'],
                    'tr' => ['t' => 'Celali ↔ Miladi Tarih Dönüştürücü', 'd' => 'Celali, Miladi ve Hicri takvimler arasında doğru dönüşüm.'],
                ],
                'timestamp-converter' => [
                    'icon' => 'clock',
                    'fa' => ['t' => 'تبدیل Timestamp یونیکس', 'd' => 'تبدیل مهر زمانی به تاریخ خوانا و برعکس، با منطقه‌ی زمانی.'],
                    'en' => ['t' => 'Unix Timestamp Converter', 'd' => 'Convert timestamps to readable dates and back, with time zones.'],
                    'tr' => ['t' => 'Unix Zaman Damgası Dönüştürücü', 'd' => 'Zaman damgalarını okunabilir tarihlere çevirin.'],
                ],
                'password-generator' => [
                    'icon' => 'key',
                    'fa' => ['t' => 'سازنده‌ی رمز عبور قوی', 'd' => 'تولید رمز امن در مرورگر شما — هیچ‌جا ذخیره یا ارسال نمی‌شود.'],
                    'en' => ['t' => 'Strong Password Generator', 'd' => 'Generated in your browser — never stored or transmitted.'],
                    'tr' => ['t' => 'Güçlü Parola Üreteci', 'd' => 'Tarayıcınızda üretilir — asla saklanmaz veya gönderilmez.'],
                ],
                'hash-generator' => [
                    'icon' => 'lock',
                    'fa' => ['t' => 'سازنده‌ی هش SHA', 'd' => 'محاسبه‌ی SHA-1، SHA-256 و SHA-512 برای متن دلخواه.'],
                    'en' => ['t' => 'SHA Hash Generator', 'd' => 'Compute SHA-1, SHA-256 and SHA-512 for any text.'],
                    'tr' => ['t' => 'SHA Hash Üreteci', 'd' => 'Herhangi bir metin için SHA-1, SHA-256, SHA-512 hesaplayın.'],
                ],
                'uuid-generator' => [
                    'icon' => 'key',
                    'fa' => ['t' => 'سازنده‌ی UUID', 'd' => 'تولید شناسه‌ی یکتای نسخه ۴ به‌صورت تکی یا انبوه.'],
                    'en' => ['t' => 'UUID Generator', 'd' => 'Generate version-4 unique identifiers, one or in bulk.'],
                    'tr' => ['t' => 'UUID Üreteci', 'd' => 'Sürüm 4 benzersiz kimlikler üretin, tek veya toplu.'],
                ],
                'byte-converter' => [
                    'icon' => 'hdd',
                    'fa' => ['t' => 'تبدیل واحد حجم داده', 'd' => 'تبدیل بایت، کیلوبایت، مگابایت، گیگابایت و ترابایت.'],
                    'en' => ['t' => 'Data Size Converter', 'd' => 'Convert between bytes, KB, MB, GB and TB.'],
                    'tr' => ['t' => 'Veri Boyutu Dönüştürücü', 'd' => 'Bayt, KB, MB, GB ve TB arasında dönüştürün.'],
                ],
                'barcode-generator' => [
                    'icon' => 'box',
                    'fa' => ['t' => 'بارکدساز Code 128 و EAN-13', 'd' => 'بارکد Code 128 و EAN-13 با کاراکتر کنترلی خودکار و خروجی PNG.'],
                    'en' => ['t' => 'Barcode Generator (Code 128 & EAN-13)', 'd' => 'Generate Code 128 and EAN-13 barcodes with automatic check characters and PNG export.'],
                    'tr' => ['t' => 'Barkod Oluşturucu (Code 128 ve EAN-13)', 'd' => 'Otomatik kontrol karakteriyle Code 128 ve EAN-13 barkodu üretin, PNG indirin.'],
                ],
                'qr-generator' => [
                    'icon' => 'layout',
                    'fa' => ['t' => 'ساخت کد QR', 'd' => 'ساخت کد QR استاندارد با سطح تصحیح خطا و اندازه دلخواه، خروجی PNG، کاملاً در مرورگر.'],
                    'en' => ['t' => 'QR Code Generator', 'd' => 'Standards-compliant QR codes with L/M/Q/H error correction and PNG export, fully offline.'],
                    'tr' => ['t' => 'QR Kod Oluşturucu', 'd' => 'L/M/Q/H hata düzeltmeli standart QR kodu üretin ve PNG indirin, tamamen çevrimdışı.'],
                ],
            ],
        ],

        'image' => [
            'icon' => 'layout',
            'fa' => ['t' => 'تصویر', 'd' => 'فشرده‌سازی، تغییر اندازه و کار با تصویر در مرورگر'],
            'en' => ['t' => 'Image', 'd' => 'Compress, resize and work with images in the browser'],
            'tr' => ['t' => 'Görsel', 'd' => 'Tarayıcıda görsel sıkıştırma, boyutlandırma ve düzenleme'],
            'tools' => [
                'image-compressor' => [
                    'icon' => 'hdd',
                    'fa' => ['t' => 'فشرده‌ساز تصویر', 'd' => 'حجم عکس را با فشرده‌سازی JPEG یا WebP در مرورگر کم کنید — بدون آپلود.'],
                    'en' => ['t' => 'Image Compressor', 'd' => 'Shrink image file size with JPEG or WebP, right in your browser — no upload.'],
                    'tr' => ['t' => 'Görsel Sıkıştırıcı', 'd' => 'JPEG veya WebP ile görsel boyutunu tarayıcıda küçültün — yükleme yok.'],
                ],
                'image-filters' => [
                    'icon' => 'sparkles',
                    'fa' => ['t' => 'استودیو فیلتر تصویر', 'd' => 'روشنایی، کنتراست، سپیا، تاری و بیشتر را زنده اعمال کنید و رشته‌ی CSS آن را بردارید.'],
                    'en' => ['t' => 'Image Filter Studio', 'd' => 'Apply brightness, contrast, sepia, blur and more live, then copy the CSS filter string.'],
                    'tr' => ['t' => 'Görsel Filtre Stüdyosu', 'd' => 'Parlaklık, kontrast, sepya ve bulanıklığı canlı uygulayın, CSS filtresini kopyalayın.'],
                ],
                'image-palette' => [
                    'icon' => 'layout',
                    'fa' => ['t' => 'استخراج پالت رنگ از تصویر', 'd' => 'رنگ‌های غالب هر تصویر را با کد HEX و درصد سهم، به‌همراه بلوک آمادهٔ متغیرهای CSS و میانگین رنگ، به‌صورت کاملاً محلی در مرورگر استخراج کنید.'],
                    'en' => ['t' => 'Image Palette Extractor', 'd' => "Extract an image's dominant colors as HEX with share percentages, plus a ready-to-paste CSS variables block and the average color — all locally in your browser."],
                    'tr' => ['t' => 'Görsel Renk Paleti Çıkarıcı', 'd' => 'Herhangi bir görselin baskın renklerini HEX ve pay yüzdesiyle, hazır CSS değişkenleri bloğu ve ortalama renkle birlikte tamamen tarayıcınızda çıkarın.'],
                ],
                'image-to-base64' => [
                    'icon' => 'code',
                    'fa' => ['t' => 'تبدیل تصویر به Base64', 'd' => 'تصویر را به data URI بر پایه‌ی Base64 تبدیل کنید و کدهای آماده‌ی HTML، CSS و Markdown را بگیرید — کاملاً در مرورگر.'],
                    'en' => ['t' => 'Image to Base64', 'd' => 'Convert an image to a Base64 data URI and get ready-made HTML, CSS and Markdown snippets — fully in your browser.'],
                    'tr' => ['t' => 'Gorsel Base64 / Data URI', 'd' => 'Bir gorseli Base64 data URI ye donusturun ve hazir HTML, CSS ve Markdown kod parcalarini alin — tamamen tarayicida.'],
                ],
                'svg-to-png' => [
                    'icon' => 'restore',
                    'fa' => ['t' => 'تبدیل SVG به PNG', 'd' => 'SVG را در مرورگر به PNG با مقیاس ۱x تا ۴x یا عرض دلخواه تبدیل کنید؛ کاملاً محلی و بدون آپلود.'],
                    'en' => ['t' => 'SVG to PNG Converter', 'd' => 'Rasterize SVG to PNG at 1x–4x or a custom pixel width, entirely in your browser — nothing is uploaded.'],
                    'tr' => ['t' => 'SVG to PNG Donusturucu', 'd' => 'SVG dosyalarini tarayicida 1x-4x veya ozel piksel genisliginde PNG olarak kaydedin; hicbir sey yuklenmez.'],
                ],
            ],
        ],

    ],

];
