<?php

return [
    // Must match an allowed IP on the dedicated Gateway key, if that key has CIDR restrictions.
    'gateway_source_ip' => env('GROWTH_GATEWAY_SOURCE_IP'),
    'media_hosts' => ['api.deepinfra.com', 'inference.deepinfra.com', 'storage.googleapis.com', 'generativelanguage.googleapis.com'],
    'max_asset_bytes' => 25_000_000,
    'autopilot_text_models' => [
        'di-qwenqwen3-235b-a22b-instruct-2507-12d7a45f0c',
        'di-googlegemini-25-flash-cd62f9d5ea',
    ],
    'autopilot_image_models' => ['di-black-forest-labsflux-1-schnell-9979f6c653'],
    'autopilot_topics' => [
        ['path' => '/vps/hourly', 'fa' => 'چگونه منابع سرور ساعتی را برای یک پروژه آزمایشی انتخاب کنیم؟', 'en' => 'Choosing hourly server resources for a pilot project', 'tr' => 'Pilot proje için saatlik sunucu kaynakları nasıl seçilir?'],
        ['path' => '/gpu', 'fa' => 'انتخاب حافظه GPU برای اجرای مدل؛ از آزمایش تا سرویس', 'en' => 'GPU memory planning from experiments to a service', 'tr' => 'Deneyden hizmete GPU belleği planlama'],
        ['path' => '/ai', 'fa' => 'چگونه سقف هزینه و کلیدهای API پروژه هوش مصنوعی را مدیریت کنیم؟', 'en' => 'Managing AI project API keys and spending limits', 'tr' => 'Yapay zekâ projelerinde API anahtarları ve harcama sınırları'],
        ['path' => '/transactional-email', 'fa' => 'چک‌لیست راه‌اندازی ایمیل تراکنشی برای یک محصول جدید', 'en' => 'Transactional email checklist for a new product', 'tr' => 'Yeni ürün için işlemsel e-posta kontrol listesi'],
        ['path' => '/hosting/linux', 'fa' => 'چه زمانی هاست اشتراکی را به سرور مجازی ارتقا دهیم؟', 'en' => 'When to move from shared hosting to a virtual server', 'tr' => 'Paylaşımlı barındırmadan sanal sunucuya ne zaman geçilmeli?'],
        ['path' => '/transactional-email', 'fa' => 'چک‌لیست SPF و DKIM و DMARC برای ایمیل یک محصول', 'en' => 'SPF, DKIM and DMARC checklist for product email', 'tr' => 'Ürün e-postası için SPF, DKIM ve DMARC kontrol listesi'],
        ['path' => '/ai', 'fa' => 'مدیریت خطا و محدودیت درخواست در اتصال به API هوش مصنوعی', 'en' => 'Handling errors and rate limits in an AI API integration', 'tr' => 'Yapay zekâ API bağlantısında hata ve istek sınırı yönetimi'],
        ['path' => '/gpu', 'fa' => 'انتخاب محیط اجرای مدل؛ نوت‌بوک، رابط تصویری یا API', 'en' => 'Choosing a model environment: notebook, visual workflow or API', 'tr' => 'Model ortamı seçimi: not defteri, görsel iş akışı veya API'],
        ['path' => '/vps/hourly', 'fa' => 'چک‌لیست پایان پروژه آزمایشی؛ پشتیبان و حذف امن سرور', 'en' => 'Closing a pilot project: backups and safe server removal', 'tr' => 'Pilot projeyi kapatma: yedekleme ve güvenli sunucu silme'],
        ['path' => '/hosting/linux', 'fa' => 'چک‌لیست مهاجرت یک سایت بدون فراموش‌کردن DNS و ایمیل', 'en' => 'Website migration checklist covering DNS and email', 'tr' => 'DNS ve e-postayı kapsayan web sitesi taşıma kontrol listesi'],
    ],
    'research_sources' => [
        'Google AI releases' => 'https://ai.google.dev/gemini-api/docs/changelog',
        'Cloudflare updates' => 'https://blog.cloudflare.com/',
        'ServerNet products' => 'https://servernet.cloud/',
    ],
];
