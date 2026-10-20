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
    'hourly' => [
        ['post' => 'how-to-choose-a-vps'],
        ['route' => 'gpu', 'title' => ['fa' => 'اجاره GPU برای پردازش گرافیکی و مدل', 'en' => 'GPU rental for graphics and model workloads', 'tr' => 'Grafik ve model işleri için GPU kiralama']],
    ],
    'gpu-vps' => [
        ['route' => 'gpu', 'title' => ['fa' => 'محیط آماده GPU ساعتی برای ComfyUI و Jupyter', 'en' => 'Ready-to-use hourly GPU apps for ComfyUI and Jupyter', 'tr' => 'ComfyUI ve Jupyter için hazır saatlik GPU uygulamaları']],
    ],
    'n8n' => [
        ['post' => 'n8n-backup-migration-guide'],
        ['route' => 'vps.hourly', 'title' => ['fa' => 'VPS ساعتی برای نصب و مدیریت مستقل n8n', 'en' => 'Hourly VPS for a self-managed n8n installation', 'tr' => 'Kendi n8n kurulumunuz için saatlik VPS']],
    ],
    'python' => [
        ['route' => 'vps.hourly', 'title' => ['fa' => 'سرور ساعتی برای worker و کنترل سیستم‌عامل', 'en' => 'Hourly VPS for workers and OS control', 'tr' => 'Worker ve işletim sistemi kontrolü için saatlik VPS']],
        ['post' => 'jupyter-hourly-gpu-guide'],
    ],
    'image-cropper' => [
        ['route' => 'webtools', 'parameters' => ['slug' => 'image-compressor'], 'title' => ['fa' => 'فشرده‌سازی تصویر پس از برش', 'en' => 'Compress an image after cropping', 'tr' => 'Kırptıktan sonra görseli sıkıştırın']],
    ],
    'clip-path-generator' => [
        ['route' => 'webtools', 'parameters' => ['slug' => 'css-triangle'], 'title' => ['fa' => 'ساخت مثلث CSS و مقایسه روش‌ها', 'en' => 'Build CSS triangles and compare methods', 'tr' => 'CSS üçgenleri oluşturun ve yöntemleri karşılaştırın']],
    ],
];
