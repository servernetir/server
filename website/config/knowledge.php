<?php

/*
|--------------------------------------------------------------------------
| پایگاه دانش — محتوای صفحه /knowledge (fa / en / tr)
|--------------------------------------------------------------------------
| مقالات فعلاً نمونه‌اند و href ندارند؛ بعد از راه‌اندازی بلاگ لینک می‌شوند.
*/

return [

    /* مقالات بلاگ — کارت‌های شاخص */
    'articles' => [
        ['tag' => 'WordPress', 'icon' => 'zap', 'min' => 8,
            'fa' => ['t' => 'آموزش صفر تا صد افزونه Query Monitor در وردپرس', 'd' => 'کوئری‌های کند، هوک‌های سنگین و خطاهای PHP را مثل یک حرفه‌ای پیدا کنید — راهنمای کامل با مثال واقعی.', 'date' => '۴ اسفند ۱۴۰۴'],
            'en' => ['t' => 'Query Monitor for WordPress, from zero to pro', 'd' => 'Find slow queries, heavy hooks and PHP errors like a professional — a complete guide with real examples.', 'date' => 'Feb 23, 2026'],
            'tr' => ['t' => 'WordPress için Query Monitor: sıfırdan profesyonelliğe', 'd' => 'Yavaş sorguları ve PHP hatalarını profesyonel gibi bulun.', 'date' => '23 Şub 2026']],
        ['tag' => 'Security', 'icon' => 'shield', 'min' => 6,
            'fa' => ['t' => 'چگونه افزونه کپچا در وردپرس را فعال کنیم؟', 'd' => 'جلوی ربات‌های اسپم و حملات Brute-Force را با ۳ روش عملی بگیرید — از reCAPTCHA تا کپچاهای بدون مزاحمت.', 'date' => '۲ اسفند ۱۴۰۴'],
            'en' => ['t' => 'Enabling CAPTCHA in WordPress the right way', 'd' => 'Stop spam bots and brute-force attacks with 3 practical methods — from reCAPTCHA to invisible challenges.', 'date' => 'Feb 21, 2026'],
            'tr' => ['t' => "WordPress'te CAPTCHA'yı doğru şekilde etkinleştirme", 'd' => 'Spam botlarını ve brute-force saldırılarını 3 pratik yöntemle durdurun.', 'date' => '21 Şub 2026']],
        ['tag' => 'Email', 'icon' => 'mail', 'min' => 12,
            'fa' => ['t' => 'راه‌اندازی بهترین میل‌سرور لینوکسی: Postfix و iRedMail', 'd' => 'میل‌سرور اختصاصی با تحویل مطمئن به Inbox — گام‌به‌گام از نصب تا DKIM/SPF/DMARC.', 'date' => '۲۸ بهمن ۱۴۰۴'],
            'en' => ['t' => 'The best Linux mail server: Postfix & iRedMail', 'd' => 'A private mail server with reliable inbox delivery — step by step from install to DKIM/SPF/DMARC.', 'date' => 'Feb 17, 2026'],
            'tr' => ['t' => 'En iyi Linux mail sunucusu: Postfix ve iRedMail', 'd' => 'Kurulumdan DKIM/SPF/DMARC\'a adım adım özel mail sunucusu.', 'date' => '17 Şub 2026']],
        ['tag' => 'DevOps', 'icon' => 'flow', 'min' => 10,
            'fa' => ['t' => 'Docker Compose برای پروژه‌های لاراول: الگوی حرفه‌ای ۱۴۰۵', 'd' => 'محیط توسعه و پروداکشن یکسان با یک فایل — به‌همراه Nginx، Redis و کیوها.', 'date' => '۲۰ بهمن ۱۴۰۴'],
            'en' => ['t' => 'Docker Compose for Laravel: the 2026 pattern', 'd' => 'Identical dev and production environments from one file — with Nginx, Redis and queues.', 'date' => 'Feb 9, 2026'],
            'tr' => ['t' => 'Laravel için Docker Compose: 2026 deseni', 'd' => 'Tek dosyadan aynı geliştirme ve üretim ortamı.', 'date' => '9 Şub 2026']],
        ['tag' => 'AI', 'icon' => 'bot', 'min' => 7,
            'fa' => ['t' => 'اتوماسیون پشتیبانی با n8n و مدل‌های زبانی — تجربه سرورنت', 'd' => 'چطور دستیار هوشمند سایتمان را با n8n ساختیم: از سرنخ فروش تا پیامک پیگیری، بدون کدنویسی.', 'date' => '۱۵ بهمن ۱۴۰۴'],
            'en' => ['t' => 'Support automation with n8n and LLMs — the ServerNet story', 'd' => 'How we built our site assistant with n8n: from sales leads to follow-up SMS, no code.', 'date' => 'Feb 4, 2026'],
            'tr' => ['t' => 'n8n ve LLM ile destek otomasyonu — ServerNet deneyimi', 'd' => 'Site asistanımızı n8n ile nasıl kurduk: kodsuz.', 'date' => '4 Şub 2026']],
        ['tag' => 'Performance', 'icon' => 'gauge', 'min' => 9,
            'fa' => ['t' => 'امتیاز ۱۰۰ گوگل PageSpeed برای فروشگاه ووکامرسی', 'd' => 'چک‌لیست واقعی ما برای فروشگاه‌های پرمحصول: LiteSpeed، Redis، تصاویر WebP و حذف JSهای مرده.', 'date' => '۸ بهمن ۱۴۰۴'],
            'en' => ['t' => 'A perfect PageSpeed score for WooCommerce stores', 'd' => 'Our real checklist for large stores: LiteSpeed, Redis, WebP images and killing dead JS.', 'date' => 'Jan 28, 2026'],
            'tr' => ['t' => 'WooCommerce mağazaları için mükemmel PageSpeed skoru', 'd' => 'Büyük mağazalar için gerçek kontrol listemiz.', 'date' => '28 Oca 2026']],
    ],

    /* وبینارها */
    'webinars' => [
        ['icon' => 'mic', 'len' => '90 min',
            'fa' => ['t' => 'مهاجرت بدون قطعی به Kubernetes', 'd' => 'لایو کدینگ: یک اپ لاراول واقعی را مرحله‌به‌مرحله به کلاستر K8s می‌بریم.', 'date' => 'پنجشنبه ۲۵ تیر · ۱۸:۰۰'],
            'en' => ['t' => 'Zero-downtime migration to Kubernetes', 'd' => 'Live coding: moving a real Laravel app to a K8s cluster, step by step.', 'date' => 'Thu, Jul 16 · 18:00'],
            'tr' => ['t' => "Kubernetes'e kesintisiz geçiş", 'd' => 'Canlı kodlama: gerçek bir Laravel uygulamasını K8s\'e taşıyoruz.', 'date' => '16 Tem Per · 18:00']],
        ['icon' => 'shield', 'len' => '60 min',
            'fa' => ['t' => 'امنیت وردپرس در ۲۰۲۶: تهدیدهای جدید', 'd' => 'تحلیل حمله‌های واقعی امسال و ۱۰ اقدامی که همین امشب باید انجام دهید.', 'date' => 'سه‌شنبه ۳۰ تیر · ۱۷:۰۰'],
            'en' => ['t' => 'WordPress security in 2026: the new threats', 'd' => 'Analysis of this year\'s real attacks and 10 actions to take tonight.', 'date' => 'Tue, Jul 21 · 17:00'],
            'tr' => ['t' => "2026'da WordPress güvenliği: yeni tehditler", 'd' => 'Bu yılın gerçek saldırıları ve bu gece yapılacak 10 aksiyon.', 'date' => '21 Tem Sal · 17:00']],
        ['icon' => 'bot', 'len' => '75 min',
            'fa' => ['t' => 'LLM خصوصی روی زیرساخت خودتان', 'd' => 'از انتخاب GPU تا سرو مدل با vLLM — دموی زنده روی سرور GPU سرورنت.', 'date' => 'یکشنبه ۴ مرداد · ۱۸:۰۰'],
            'en' => ['t' => 'Private LLMs on your own infrastructure', 'd' => 'From GPU sizing to serving with vLLM — live demo on a ServerNet GPU server.', 'date' => 'Sun, Jul 26 · 18:00'],
            'tr' => ['t' => 'Kendi altyapınızda özel LLM\'ler', 'd' => 'GPU seçiminden vLLM ile sunuma — canlı demo.', 'date' => '26 Tem Paz · 18:00']],
    ],

    /* دسته‌های مستندات */
    'docs' => [
        ['icon' => 'globe', 'count' => 42, 'fa' => 'هاست و کنترل‌پنل', 'en' => 'Hosting & control panels', 'tr' => 'Hosting ve kontrol panelleri'],
        ['icon' => 'cpu', 'count' => 35, 'fa' => 'سرور مجازی و اختصاصی', 'en' => 'VPS & dedicated servers', 'tr' => 'VPS ve fiziksel sunucular'],
        ['icon' => 'db', 'count' => 28, 'fa' => 'دامنه و DNS', 'en' => 'Domains & DNS', 'tr' => 'Alan adları ve DNS'],
        ['icon' => 'mail', 'count' => 19, 'fa' => 'ایمیل سازمانی', 'en' => 'Business email', 'tr' => 'Kurumsal e-posta'],
        ['icon' => 'cloud', 'count' => 24, 'fa' => 'خدمات ابری و API', 'en' => 'Cloud & API', 'tr' => 'Bulut ve API'],
        ['icon' => 'shield', 'count' => 16, 'fa' => 'امنیت و بکاپ', 'en' => 'Security & backups', 'tr' => 'Güvenlik ve yedekler'],
    ],

    /* آموزش و رویدادها */
    'learning' => [
        ['icon' => 'cap', 'tag' => ['fa' => 'دوره رایگان', 'en' => 'Free course', 'tr' => 'Ücretsiz kurs'],
            'fa' => ['t' => 'مدیریت سرور لینوکس از صفر', 'd' => '۱۲ جلسه ویدیویی — از اولین SSH تا امن‌سازی و مانیتورینگ.'],
            'en' => ['t' => 'Linux server administration from zero', 'd' => '12 video sessions — from your first SSH to hardening and monitoring.'],
            'tr' => ['t' => 'Sıfırdan Linux sunucu yönetimi', 'd' => '12 video ders — ilk SSH\'tan sıkılaştırmaya.']],
        ['icon' => 'mic', 'tag' => ['fa' => 'پادکست', 'en' => 'Podcast', 'tr' => 'Podcast'],
            'fa' => ['t' => 'رادیو زیرساخت', 'd' => 'هر دو هفته: گفتگو با مهندسان زیرساخت شرکت‌های بزرگ ایرانی.'],
            'en' => ['t' => 'Infrastructure Radio', 'd' => 'Every two weeks: conversations with infrastructure engineers at major companies.'],
            'tr' => ['t' => 'Altyapı Radyosu', 'd' => 'İki haftada bir: büyük şirketlerin altyapı mühendisleriyle sohbet.']],
        ['icon' => 'video', 'tag' => ['fa' => 'رویداد حضوری', 'en' => 'In-person event', 'tr' => 'Yüz yüze etkinlik'],
            'fa' => ['t' => 'میتاپ DevOps تهران', 'd' => 'میزبانی فصلی سرورنت از جامعه دواپس — شبکه‌سازی، ارائه و پیتزا.'],
            'en' => ['t' => 'Tehran DevOps Meetup', 'd' => 'ServerNet\'s quarterly hosting of the DevOps community — networking, talks and pizza.'],
            'tr' => ['t' => 'Tahran DevOps Buluşması', 'd' => 'DevOps topluluğunun üç aylık buluşması.']],
    ],
];
