<?php

/*
|--------------------------------------------------------------------------
| سقفِ صفحه (page budget) — ممیزی ۷، بخشِ «تصمیمِ محصولی»
|--------------------------------------------------------------------------
|
| «همان موتوری که در دو روز ۱۶۵ صفحه ساخت، در همان دو روز ۲۲ صفحه بدهی
| ساخت.» این سقف‌ها ترمزِ انتشارِ بی‌تصمیم‌اند: ReleaseGate (سنجهٔ RG-BUDGET)
| شمارِ صفحاتِ **فارسیِ** sitemap در هر بخش را با این عددها می‌سنجد و عبور،
| دروازه را قرمز می‌کند.
|
| عددها = عکسِ لحظه‌ایِ ۲ شهریور ۱۴۰۵ (پس از حذفِ ۲۱ صفحهٔ کد-دوبل از
| sitemap ابر). یعنی: هر صفحهٔ تازه در این بخش‌ها = قرمز، تا وقتی کسی
| **آگاهانه** همین فایل را ویرایش کند و در پیامِ کامیت بگوید چرا.
|
| /order عمداً سقف ندارد: شمارش از products فعالِ DB می‌آید و با کاتالوگ
| بزرگ می‌شود — دروازه‌اش new_page_gate است (عنوانِ یکتا + اسکیما)، نه عدد.
*/

return [

    /*
    | Search discovery and model training are separate decisions. The checked-in
    | robots.txt is generated with `php artisan seo:robots`; changing either
    | switch never weakens the private-path rules for an allowed crawler.
    */
    'crawler_policy' => [
        'search_discovery' => [
            'allow' => env('SEO_AI_SEARCH_CRAWL', true),
            'agents' => ['OAI-SearchBot', 'PerplexityBot', 'Googlebot', 'Bingbot'],
        ],
        'model_training' => [
            'allow' => env('SEO_AI_TRAINING_CRAWL', true),
            'agents' => ['GPTBot', 'Google-Extended'],
        ],
        'private_paths' => [
            '/api/', '/en/api/', '/tr/api/',
            '/admin',
            '/system/',
            '/go/', '/en/go/', '/tr/go/',
            '/account', '/en/account', '/tr/account',
            '/login', '/en/login', '/tr/login',
            '/register', '/en/register', '/tr/register',
            '/payment/', '/sb/',
            '/bale/webhook/', '/cloud-phone/webhook/',
            '/healthz', '/up',
        ],
        'sitemap' => 'https://servernet.cloud/sitemap.xml',
    ],

    'page_budget' => [
        '/cloud' => 21,
        '/parts' => 107,   // منجمد — ممیزی ۷
        '/urmia' => 29,    // منجمد — ممیزی ۷
        '/webtools' => 49,    // منجمد — ممیزی ۷
    ],

];
