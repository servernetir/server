<?php

/*
|--------------------------------------------------------------------------
| ServerNet — بلاگ
|--------------------------------------------------------------------------
| دسته‌بندی‌ها و تنظیمات. پست‌ها به‌صورت فایل JSON در resources/blog/posts
| نگهداری می‌شوند (BlogRepository آن‌ها را می‌خواند).
*/

return [
    'per_page' => 9,

    'categories' => [
        'hosting'   => ['icon' => 'server', 'accent' => 'cyan',   'fa' => 'هاست و سرور',        'en' => 'Hosting & Servers',   'tr' => 'Hosting & Sunucu'],
        'cloud'     => ['icon' => 'cloud',  'accent' => 'violet', 'fa' => 'ابر و زیرساخت',       'en' => 'Cloud & Infrastructure', 'tr' => 'Bulut & Altyapı'],
        'security'  => ['icon' => 'shield', 'accent' => 'green',  'fa' => 'امنیت',               'en' => 'Security',            'tr' => 'Güvenlik'],
        'seo'       => ['icon' => 'trend',  'accent' => 'green',  'fa' => 'سئو و دیجیتال مارکتینگ', 'en' => 'SEO & Marketing',   'tr' => 'SEO & Pazarlama'],
        'tutorial'  => ['icon' => 'book',   'accent' => 'amber',  'fa' => 'آموزش',               'en' => 'Tutorials',          'tr' => 'Eğitimler'],
        'tech'      => ['icon' => 'cpu',    'accent' => 'cyan',   'fa' => 'تکنولوژی',            'en' => 'Technology',         'tr' => 'Teknoloji'],
        'business'  => ['icon' => 'coins',  'accent' => 'violet', 'fa' => 'کسب‌وکار',            'en' => 'Business',           'tr' => 'İş'],
    ],

    // پوسته‌های گرادیانی جلد پست (بدون تصویر خارجی)
    'covers' => [
        'a' => 'linear-gradient(135deg,#0891b2,#7c3aed)',
        'b' => 'linear-gradient(135deg,#7c3aed,#db2777)',
        'c' => 'linear-gradient(135deg,#0d9488,#0891b2)',
        'd' => 'linear-gradient(135deg,#d97706,#dc2626)',
        'e' => 'linear-gradient(135deg,#2563eb,#0891b2)',
        'f' => 'linear-gradient(135deg,#059669,#0d9488)',
    ],
];
