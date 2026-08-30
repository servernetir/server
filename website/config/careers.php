<?php

/*
|--------------------------------------------------------------------------
| ServerNet — فرصت‌های شغلی
|--------------------------------------------------------------------------
| موقعیت‌های شغلی و مزایا. فرم درخواست از طریق وبهوک n8n به تیم می‌رسد.
*/

return [

    // مزایای کار در سرورنت
    'perks' => [
        ['icon' => 'rocket', 'fa' => ['t' => 'رشد واقعی', 'd' => 'روی زیرساخت‌های واقعی و بزرگ کار می‌کنی و سریع یاد می‌گیری.'],
         'en' => ['t' => 'Real growth', 'd' => 'Work on real, large-scale infrastructure and learn fast.'],
         'tr' => ['t' => 'Gerçek büyüme', 'd' => 'Gerçek, büyük ölçekli altyapıda çalışın ve hızla öğrenin.']],
        ['icon' => 'headset', 'fa' => ['t' => 'تیم حرفه‌ای', 'd' => 'کنار متخصصانی که سال‌ها تجربه‌ی سرور و شبکه دارند.'],
         'en' => ['t' => 'Expert team', 'd' => 'Alongside specialists with years of server and network experience.'],
         'tr' => ['t' => 'Uzman ekip', 'd' => 'Yılların sunucu ve ağ deneyimine sahip uzmanlarla birlikte.']],
        ['icon' => 'clock', 'fa' => ['t' => 'انعطاف کاری', 'd' => 'ساعت‌کاری منعطف و امکان دورکاری برای بخشی از نقش‌ها.'],
         'en' => ['t' => 'Flexibility', 'd' => 'Flexible hours and remote options for many roles.'],
         'tr' => ['t' => 'Esneklik', 'd' => 'Birçok rol için esnek saatler ve uzaktan çalışma.']],
        ['icon' => 'trend', 'fa' => ['t' => 'مسیر پیشرفت', 'd' => 'عملکردت دیده می‌شود و مسیر رشد شغلی روشنی داری.'],
         'en' => ['t' => 'Clear path', 'd' => 'Your work is seen and you have a clear growth path.'],
         'tr' => ['t' => 'Net kariyer yolu', 'd' => 'Çalışmanız görülür ve net bir büyüme yolunuz olur.']],
    ],

    // موقعیت‌های باز
    'positions' => [
        [
            'slug' => 'linux-network-engineer', 'icon' => 'server', 'type' => 'full',
            'fa' => ['t' => 'مهندس لینوکس و شبکه', 'd' => 'راه‌اندازی، مدیریت و امن‌سازی سرورها و زیرساخت شبکه‌ی دیتاسنتر.',
                'req' => ['تسلط بر لینوکس (نصب، پیکربندی، عیب‌یابی)', 'آشنایی با شبکه: TCP/IP، فایروال، DNS', 'تجربه با وب‌سرور و دیتابیس', 'روحیه‌ی حل مسئله و دقت']],
            'en' => ['t' => 'Linux & Network Engineer', 'd' => 'Deploy, manage and secure datacenter servers and network infrastructure.',
                'req' => ['Strong Linux (install, config, troubleshoot)', 'Networking: TCP/IP, firewall, DNS', 'Web server & database experience', 'Problem-solving mindset']],
            'tr' => ['t' => 'Linux & Ağ Mühendisi', 'd' => 'Veri merkezi sunucularını ve ağ altyapısını kurun, yönetin ve güvenli hale getirin.',
                'req' => ['Güçlü Linux bilgisi', 'Ağ: TCP/IP, güvenlik duvarı, DNS', 'Web sunucu & veritabanı deneyimi', 'Problem çözme yeteneği']],
        ],
        [
            'slug' => 'devops-sre', 'icon' => 'flow', 'type' => 'full',
            'fa' => ['t' => 'مهندس DevOps / SRE', 'd' => 'اتوماسیون، مانیتورینگ و پایداری زیرساخت با ابزارهای مدرن.',
                'req' => ['تجربه با Docker و مجازی‌سازی', 'آشنایی با CI/CD و اسکریپت‌نویسی', 'مانیتورینگ و لاگ‌گیری', 'آشنایی با ابر و کوبرنتیز مزیت است']],
            'en' => ['t' => 'DevOps / SRE Engineer', 'd' => 'Automation, monitoring and reliability of infrastructure with modern tooling.',
                'req' => ['Docker & virtualization experience', 'CI/CD and scripting', 'Monitoring and logging', 'Cloud & Kubernetes a plus']],
            'tr' => ['t' => 'DevOps / SRE Mühendisi', 'd' => 'Modern araçlarla altyapının otomasyonu, izlenmesi ve güvenilirliği.',
                'req' => ['Docker & sanallaştırma deneyimi', 'CI/CD ve betik yazma', 'İzleme ve loglama', 'Bulut & Kubernetes artı']],
        ],
        [
            'slug' => 'fullstack-developer', 'icon' => 'code', 'type' => 'full',
            'fa' => ['t' => 'توسعه‌دهنده‌ی فول‌استک', 'd' => 'توسعه و نگهداری پنل‌ها، ابزارها و سرویس‌های وب سرورنت.',
                'req' => ['تسلط بر PHP/Laravel یا معادل', 'HTML/CSS/JavaScript', 'کار با API و دیتابیس', 'کد تمیز و مسئولیت‌پذیری']],
            'en' => ['t' => 'Full-stack Developer', 'd' => 'Build and maintain ServerNet\'s panels, tools and web services.',
                'req' => ['PHP/Laravel or equivalent', 'HTML/CSS/JavaScript', 'APIs and databases', 'Clean code and ownership']],
            'tr' => ['t' => 'Full-stack Geliştirici', 'd' => 'ServerNet panellerini, araçlarını ve web hizmetlerini geliştirin.',
                'req' => ['PHP/Laravel veya eşdeğeri', 'HTML/CSS/JavaScript', 'API ve veritabanları', 'Temiz kod ve sahiplenme']],
        ],
        [
            'slug' => 'technical-support', 'icon' => 'headset', 'type' => 'full',
            'fa' => ['t' => 'کارشناس پشتیبانی فنی', 'd' => 'پاسخ‌گویی و رفع مشکلات فنی مشتریان با صبر و دقت.',
                'req' => ['آشنایی با هاست، دامنه و کنترل‌پنل', 'مهارت ارتباطی و نوشتاری خوب', 'حوصله و مشتری‌مداری', 'کار شیفتی برای پشتیبانی ۲۴/۷']],
            'en' => ['t' => 'Technical Support Specialist', 'd' => 'Answer and resolve customers\' technical issues with patience and care.',
                'req' => ['Hosting, domain & control-panel knowledge', 'Good communication & writing', 'Patience and customer focus', 'Shift work for 24/7 support']],
            'tr' => ['t' => 'Teknik Destek Uzmanı', 'd' => 'Müşterilerin teknik sorunlarını sabır ve özenle yanıtlayın ve çözün.',
                'req' => ['Hosting, alan adı & kontrol paneli bilgisi', 'İyi iletişim & yazma', 'Sabır ve müşteri odağı', '7/24 destek için vardiya']],
        ],
        [
            'slug' => 'sales-account', 'icon' => 'trend', 'type' => 'full',
            'fa' => ['t' => 'کارشناس فروش و حساب مشتری', 'd' => 'مشاوره‌ی فروش، پیگیری مشتریان سازمانی و رشد درآمد.',
                'req' => ['فن بیان و مذاکره', 'آشنایی با محصولات هاستینگ و سرور مزیت است', 'پیگیری و نظم', 'هدف‌محور و پرانرژی']],
            'en' => ['t' => 'Sales & Account Specialist', 'd' => 'Sales consulting, enterprise account follow-up and revenue growth.',
                'req' => ['Communication & negotiation', 'Hosting/server product knowledge a plus', 'Follow-up and organization', 'Goal-oriented and energetic']],
            'tr' => ['t' => 'Satış & Müşteri Uzmanı', 'd' => 'Satış danışmanlığı, kurumsal müşteri takibi ve gelir artışı.',
                'req' => ['İletişim & müzakere', 'Hosting/sunucu ürün bilgisi artı', 'Takip ve organizasyon', 'Hedef odaklı ve enerjik']],
        ],
        [
            'slug' => 'content-seo', 'icon' => 'book', 'type' => 'full',
            'fa' => ['t' => 'کارشناس محتوا و سئو', 'd' => 'تولید محتوای تخصصی، آموزش و بهینه‌سازی سئوی سایت و بلاگ.',
                'req' => ['نگارش قوی فارسی (و ترجیحاً انگلیسی)', 'آشنایی با اصول سئو', 'علاقه به فناوری و هاستینگ', 'خلاقیت و نظم در تولید محتوا']],
            'en' => ['t' => 'Content & SEO Specialist', 'd' => 'Create expert content, tutorials and optimize site and blog SEO.',
                'req' => ['Strong writing (Persian, ideally English)', 'SEO fundamentals', 'Interest in tech & hosting', 'Creativity and consistency']],
            'tr' => ['t' => 'İçerik & SEO Uzmanı', 'd' => 'Uzman içerik, eğitimler üretin ve site/blog SEO\'sunu optimize edin.',
                'req' => ['Güçlü yazma', 'SEO temelleri', 'Teknoloji & hosting ilgisi', 'Yaratıcılık ve tutarlılık']],
        ],
    ],
];
