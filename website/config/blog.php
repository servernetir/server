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

    /*
    |----------------------------------------------------------------------
    | پلِ بلاگ ⇄ محصول (ممیزی ۳ — «جزیرهٔ محتوا پل ندارد»)
    |----------------------------------------------------------------------
    | هر دستهٔ بلاگ به یک صفحهٔ محصولِ قابلِ خرید وصل می‌شود. قالبِ تک‌پست
    | بالای «مطالب مرتبط» بلاکِ «سرویس مرتبط» را از همین نقشه می‌سازد، و
    | قالبِ صفحهٔ محصول بلاکِ «راهنماها» را از جهتِ معکوسِ همین نقشه
    | (`product_guides`) پر می‌کند — هر دو در قالب، هیچ‌کدام کارِ دستی.
    |
    | ⚠️ عنوان و توضیحِ محصول این‌جا تکرار نمی‌شود؛ `blog_related_product()`
    | آن‌ها را از configِ خودِ محصول می‌خواند تا دو منبعِ حقیقت نسازیم.
    |
    | kind: hosting (config/hosting.php) · catalog (config/catalog/*) ·
    |       solution (config/solutions.php)
    */
    'category_products' => [
        'hosting'  => ['kind' => 'hosting',  'slug' => 'linux'],
        'cloud'    => ['kind' => 'catalog',  'category' => 'cloud', 'slug' => 'iaas'],
        'security' => ['kind' => 'catalog',  'category' => 'services', 'slug' => 'security'],
        'seo'      => ['kind' => 'solution', 'slug' => 'seo-services'],
        'tutorial' => ['kind' => 'hosting',  'slug' => 'wordpress'],
        'tech'     => ['kind' => 'catalog',  'category' => 'vps', 'slug' => 'iran'],
        'business' => ['kind' => 'solution', 'slug' => 'infrastructure'],
    ],

    /*
    | زنجیرهٔ fallback (ممیزی ۴): «هیچ پستی نباید صفر لینکِ محصول رندر کند.»
    |
    | دورِ چهارم شمرد: ۸۴ از ۱۰۷ پست لینک داشتند — ۲۳ پستِ باقی‌مانده در
    | دسته‌هایی‌اند که در نقشهٔ بالا نیستند (اخبار، مفاهیم عمومی، دسته‌های
    | قدیمیِ دیتابیس). مدلِ نگاشت برایشان جوابی نداشت؛ این پیش‌فرض جواب است:
    | پست ← دستهٔ نگاشته ← hubِ خطِ محصولِ پرچم‌دار. معیارِ پذیرش دورِ بعد:
    | کمینه ≥۱ و میانگین ≥۱ در هر ۱۰۷ پست.
    */
    'category_products_fallback' => ['kind' => 'hosting', 'slug' => 'linux'],

    /*
    | جهتِ معکوس: صفحهٔ هر دستهٔ محصول از کدام دستهٔ بلاگ «راهنما» بردارد.
    | کلیدها دستهٔ کاتالوگ‌اند (+`hosting` و چند قالبِ خاص). اگر دسته‌ای این‌جا
    | نبود یا پستِ کافی نداشت، `blog_guides()` با تازه‌ترین‌ها پر می‌کند —
    | بلاکِ خالی از بلاکِ نه‌چندان‌مرتبط بدتر است (همان قاعدهٔ `related()`).
    */
    'product_guides' => [
        'hosting'   => 'hosting',
        'vps'       => 'hosting',
        'dedicated' => 'hosting',
        'cloud'     => 'cloud',
        'domain'    => 'tutorial',
        'services'  => 'tech',
        'servers'   => 'hosting',
        'solutions' => 'business',
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
