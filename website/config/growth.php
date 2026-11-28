<?php

return [
    'gpu' => [
        ['post' => 'comfyui-hourly-gpu-guide'],
        ['post' => 'jupyter-hourly-gpu-guide'],
    ],
    'article-how-to-choose-a-vps' => [
        ['route' => 'vps.hourly', 'title' => ['fa' => 'مقایسه تعرفه و شرایط سرور مجازی ساعتی', 'en' => 'Compare hourly VPS rates and billing conditions', 'tr' => 'Saatlik VPS ücret ve koşullarını karşılaştırın']],
    ],
    'article-gpu-servers-for-ai' => [
        ['route' => 'gpu', 'title' => ['fa' => 'انتخاب کارت و برنامه در سرویس GPU ساعتی', 'en' => 'Choose a card and app on hourly GPU', 'tr' => 'Saatlik GPU için kart ve uygulama seçin']],
        ['post' => 'comfyui-hourly-gpu-guide'],
        ['post' => 'jupyter-hourly-gpu-guide'],
    ],
    'article-gpu-server-selection' => [
        ['route' => 'gpu', 'title' => ['fa' => 'انتخاب کارت و برنامه برای اجاره GPU ساعتی', 'en' => 'Choose a card and application for hourly GPU rental', 'tr' => 'Saatlik GPU kiralamak için kart ve uygulama seçin']],
        ['post' => 'comfyui-hourly-gpu-guide'],
        ['post' => 'jupyter-hourly-gpu-guide'],
    ],
    'article-hourly-vs-monthly-billing' => [
        ['route' => 'vps.hourly', 'title' => ['fa' => 'مقایسهٔ قیمت و شرایط خرید سرور ساعتی', 'en' => 'Compare hourly VPS prices and billing conditions', 'tr' => 'Saatlik VPS fiyatlarını ve ödeme koşullarını karşılaştırın']],
    ],
    'hourly' => [
        ['post' => 'how-to-choose-a-vps'],
        ['post' => 'hourly-vs-monthly-billing'],
        ['route' => 'gpu', 'title' => ['fa' => 'اجاره GPU برای پردازش گرافیکی و مدل', 'en' => 'GPU rental for graphics and model workloads', 'tr' => 'Grafik ve model işleri için GPU kiralama']],
    ],
    'article-php-version-upgrade-guide' => [
        ['route' => 'hosting', 'parameters' => ['slug' => 'linux'], 'title' => ['fa' => 'مقایسه محیط هاست لینوکس برای PHP', 'en' => 'Compare Linux hosting environments for PHP', 'tr' => 'PHP için Linux hosting ortamlarını karşılaştırın']],
        ['route' => 'vps.hourly', 'title' => ['fa' => 'محیط آزمایشی مستقل برای ارتقای PHP روی VPS ساعتی', 'en' => 'Compare hourly VPS options for an isolated PHP upgrade test', 'tr' => 'PHP yükseltme testi için bağımsız saatlik VPS seçenekleri']],
    ],
    'article-iranian-discord-servers' => [
        ['route' => 'catalog', 'parameters' => ['category' => 'vps', 'slug' => 'discord'], 'title' => ['fa' => 'برای اجرای ربات دیسکورد خودتان: سرور مجازی و شرایط میزبانی (نه عضویت در سرور دیسکورد)', 'en' => 'Hosting your own Discord bot: compare VPS options (not a community invite)', 'tr' => 'Kendi Discord botunuz için VPS seçenekleri (topluluk daveti değil)']],
    ],
    'article-wordpress-speed-optimization' => [
        ['route' => 'hosting', 'parameters' => ['slug' => 'linux'], 'title' => ['fa' => 'بررسی منابع و شرایط هاست لینوکس برای سایت وردپرسی', 'en' => 'Compare Linux hosting resources for your WordPress site', 'tr' => 'WordPress siteniz için Linux hosting kaynaklarını karşılaştırın']],
        ['route' => 'tools', 'parameters' => ['slug' => 'seo'], 'title' => ['fa' => 'گزارش عمومی سلامت و سئوی سایت', 'en' => 'Check your public website’s SEO and health', 'tr' => 'Sitenizin herkese açık SEO ve sağlık raporunu inceleyin']],
    ],
    'gpu-vps' => [
        ['route' => 'gpu', 'title' => ['fa' => 'محیط آماده GPU ساعتی برای ComfyUI و Jupyter', 'en' => 'Ready-to-use hourly GPU apps for ComfyUI and Jupyter', 'tr' => 'ComfyUI ve Jupyter için hazır saatlik GPU uygulamaları']],
    ],
    'n8n' => [
        ['post' => 'n8n-backup-migration-guide'],
        ['route' => 'vps.hourly', 'title' => ['fa' => 'VPS ساعتی برای نصب و مدیریت مستقل n8n', 'en' => 'Hourly VPS for a self-managed n8n installation', 'tr' => 'Kendi n8n kurulumunuz için saatlik VPS']],
    ],
    'python' => [
        ['post' => 'python-hosting-django-flask-deployment'],
        ['route' => 'vps.hourly', 'title' => ['fa' => 'سرور ساعتی برای worker و کنترل سیستم‌عامل', 'en' => 'Hourly VPS for workers and OS control', 'tr' => 'Worker ve işletim sistemi kontrolü için saatlik VPS']],
        ['post' => 'jupyter-hourly-gpu-guide'],
    ],
    'article-python-hosting-django-flask-deployment' => [
        ['route' => 'hosting', 'parameters' => ['slug' => 'python'], 'title' => ['fa' => 'مقایسهٔ پلن‌های هاست پایتون برای Django و Flask', 'en' => 'Compare Python hosting plans for Django and Flask', 'tr' => 'Django ve Flask için Python hosting paketlerini karşılaştırın']],
        ['route' => 'vps.hourly', 'title' => ['fa' => 'سرور ساعتی برای WebSocket و worker دائمی', 'en' => 'Hourly VPS for WebSockets and persistent workers', 'tr' => 'WebSocket ve sürekli worker için saatlik VPS']],
    ],
    'image-cropper' => [
        ['route' => 'webtools', 'parameters' => ['slug' => 'image-compressor'], 'title' => ['fa' => 'فشرده‌سازی تصویر پس از برش', 'en' => 'Compress an image after cropping', 'tr' => 'Kırptıktan sonra görseli sıkıştırın']],
    ],
    'clip-path-generator' => [
        ['route' => 'webtools', 'parameters' => ['slug' => 'css-triangle'], 'title' => ['fa' => 'ساخت مثلث CSS و مقایسه روش‌ها', 'en' => 'Build CSS triangles and compare methods', 'tr' => 'CSS üçgenleri oluşturun ve yöntemleri karşılaştırın']],
    ],
];
