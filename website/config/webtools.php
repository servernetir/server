<?php

/*
|--------------------------------------------------------------------------
| ابزارهای رایگان وب‌مستر
|--------------------------------------------------------------------------
| همه‌ی این ابزارها سمت کاربر (JavaScript) اجرا می‌شوند: هیچ داده‌ای به سرور
| نمی‌رود، هزینه‌ی سروری ندارند و آنی جواب می‌دهند.
|
| افزودن ابزار جدید:
|   ۱) یک آیتم اینجا اضافه کنید
|   ۲) یک partial با همان slug در resources/views/webtools/ بسازید
| مسیر /webtools/{slug} و ثبت در sitemap خودکار انجام می‌شود.
*/

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
                    'tr' => ['t' => 'Base64 Kodlayıcı & Çözücü', 'd' => 'Metni Base64\'e çevirin ve geri alın, tam Unicode desteğiyle.'],
                ],
                'url-encoder' => [
                    'icon' => 'link',
                    'fa' => ['t' => 'رمزگذار و رمزگشای URL', 'd' => 'کدگذاری پارامترهای آدرس و رمزگشایی آدرس‌های درهم.'],
                    'en' => ['t' => 'URL Encoder & Decoder', 'd' => 'Encode query parameters and decode messy URLs.'],
                    'tr' => ['t' => 'URL Kodlayıcı & Çözücü', 'd' => 'Sorgu parametrelerini kodlayın, karmaşık URL\'leri çözün.'],
                ],
                'jwt-decoder' => [
                    'icon' => 'key',
                    'fa' => ['t' => 'رمزگشای توکن JWT', 'd' => 'محتوای هدر و payload توکن را ببینید و زمان انقضا را بررسی کنید.'],
                    'en' => ['t' => 'JWT Decoder', 'd' => 'Inspect a token\'s header and payload and check its expiry.'],
                    'tr' => ['t' => 'JWT Çözücü', 'd' => 'Token başlığını ve payload\'ını inceleyin, süresini kontrol edin.'],
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
                    'tr' => ['t' => 'SEO Slug Oluşturucu', 'd' => 'Başlığı temiz, SEO dostu bir URL\'ye çevirin.'],
                ],
                'lorem-ipsum' => [
                    'icon' => 'book',
                    'fa' => ['t' => 'متن ساختگی (فارسی و لاتین)', 'd' => 'تولید متن نمونه برای طراحی — با متن فارسی واقعی، نه لاتین.'],
                    'en' => ['t' => 'Lorem Ipsum Generator', 'd' => 'Placeholder text for mockups — Latin or real Persian.'],
                    'tr' => ['t' => 'Lorem Ipsum Üreteci', 'd' => 'Tasarım için örnek metin — Latince veya Farsça.'],
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
            ],
        ],

    ],
];
