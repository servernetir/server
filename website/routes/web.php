Warning: truncated output (original token count: 65492)
Total output lines: 3718

<?php

// Dedicated VM-console broker; HMAC replaces browser CSRF only on this exact route.
\Illuminate\Support\Facades\Route::post('/system/vpstc-console-broker',
    [\App\Services\Cloud\VpsTcConsoleBroker::class, 'handle'])
    ->middleware('throttle:30,1')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);

// Dedicated HMAC endpoint; no customer session grants broker access.
\Illuminate\Support\Facades\Route::post('/system/arvan-console-broker',
    [\App\Services\Cloud\ArvanConsoleBroker::class, 'handle'])
    ->middleware('throttle:60,1')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);

use App\Http\Controllers\Account;
use App\Http\Controllers\AiBuilderController;
use App\Http\Controllers\Auth;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DomainCheckController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\PartsShopController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\ServerShopController;
use App\Http\Controllers\SolutionController;
use App\Http\Controllers\ToolController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
| ساختار دوزبانه: فارسی در ریشه (پیش‌فرض) و انگلیسی با پیشوند /en
| صفحات جدید را فقط داخل $site اضافه کنید تا خودکار در هر دو زبان ساخته شوند.
*/

// Register private identity routes before the broad uppercase-locale redirect below.
require __DIR__.'/identity-api.php';
require __DIR__.'/sms-admin.php';
require __DIR__.'/urmia-auth.php';
require __DIR__.'/ai-growth.php';
require __DIR__.'/domain-suppliers.php';
require __DIR__.'/madar-admin.php';

// Private n8n registration callback: dedicated Bearer credential, no browser session.
Route::post('/api/internal/chat-leads', \App\Http\Controllers\ChatLeadIngestController::class)
    ->middleware([\App\Http\Middleware\ChatLeadToken::class, 'throttle:120,1'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);

$site = function (): void {
    require __DIR__.'/identity-site.php';
    require __DIR__.'/sms-site.php';
    Route::get('/', [SiteController::class, 'home'])->name('home');
    // [ai-storefront:start]
    Route::get('/ai', [\App\Http\Controllers\AiStorefrontController::class, 'home'])->name('ai.home');
    Route::get('/ai/models', [\App\Http\Controllers\AiStorefrontController::class, 'models'])->name('ai.models');
    Route::get('/ai/compare', [\App\Http\Controllers\AiStorefrontController::class, 'compare'])->name('ai.compare');
    Route::get('/ai/docs', [\App\Http\Controllers\AiStorefrontController::class, 'docs'])->name('ai.docs');
    Route::get('/ai/models/{slug}', [\App\Http\Controllers\AiStorefrontController::class, 'model'])->name('ai.model');
    // [ai-storefront:end]
    Route::get('/hosting/{slug}', [CatalogController::class, 'hosting'])->name('hosting')->where('slug', '[a-z-]+');

    /*
    |--------------------------------------------------------------------------
    | 🔴 هابِ دسته‌ها — `/hosting`، `/vps` و `/domain` هیچ روتی نداشتند
    |--------------------------------------------------------------------------
    |
    | در ردیابِ ۴۰۴ ترافیکِ واقعی روی هر سه دیده شد. جستجوی کلِ مخزن (ایستا و
    | با رندرِ ۲۳ صفحه و برداشتِ همهٔ hrefها) ثابت کرد **هیچ لینکی در سایت به
    | آنها نمی‌رود** — پس «لینک را درست کن» مقصدی ندارد؛ چیزی که کم است خودِ
    | روت است. منبعِ ترافیک بیرونی است: ۳۰۱های `servernet.ir` (که
    | `config/legacy.php` با `unknown => same-path` هر مسیرِ نگاشته‌نشده را
    | عیناً به این‌جا می‌فرستد)، بک‌لینکِ قدیمی، و کاربری که آدرسِ بدیهیِ
    | دسته‌ای را حدس می‌زند که خودِ هدر نامش را می‌بَرد («هاست»، «سرور»،
    | «دامنه») ولی تبش یک `<button>` است نه لینک.
    |
    | 🔴 چرا closure و نه `Route::redirect()`: مقصدِ `Route::redirect()` یک رشتهٔ
    | ثابت است که در **لحظهٔ ثبت** حساب می‌شود. این closure سه بار ثبت می‌شود
    | (fa/en/tr)، پس هر سه نسخه به آدرسِ فارسی می‌رفتند. `lroute()` داخلِ
    | closure در **لحظهٔ درخواست** اجرا می‌شود، بعد از میدل‌ورِ `locale`.
    |
    | ⚠️ ۳۰۱ عمدی است نه ۳۰۲: اینها آدرسِ دائمی‌اند و می‌خواهیم موتورِ جستجو
    | اعتبارِ لینک‌های قدیمی را به مقصد منتقل کند.
    */
    Route::get('/hosting', fn () => redirect()->to(lroute('hosting', 'linux'), 301))->name('hosting.index');
    Route::get('/vps', fn () => redirect()->to(lroute('cloud.index'), 301))->name('vps.index');
    /*
    | 🔴 صفحهٔ فرودِ «سرور مجازی ساعتی».
    |
    | فروشِ ساعتی محصولِ زنده‌ای بود که هیچ صفحهٔ عمومی نداشت؛ Search Console
    | همین عبارت را با CTR ۷۵٪ ولی فقط ۴ نمایش نشان می‌داد. باید **پیش از**
    | روتِ فراگیرِ `/{category}/{slug}` ثبت شود وگرنه کاتالوگ `/vps/hourly` را
    | می‌قاپد و ۴۰۴ می‌دهد (چون 'hourly' در config/catalog/vps.php نیست).
    */
    Route::get('/vps/hourly', [\App\Http\Controllers\HourlyVpsController::class, 'show'])->name('vps.hourly');

    /*
    | سرورِ گرافیکی — /gpu (خطِ محصولِ مستقل، نه زیرِ /vps).
    |
    | 🔴 عمداً زیرِ `/vps/` نیست: آن مسیر خودش ۳۰۱ به /cloud می‌خورد، و
    | مهم‌تر، هر صفحهٔ /vps/* وعدهٔ ماشینِ مجازیِ پایدار با root می‌دهد در حالی
    | که این محصول هیچ‌کدام را ندارد و قطع هم می‌شود. همان اشتباهِ ثبت‌شدهٔ
    | «پکیجِ نمایندگی که cPanelِ ساده تحویل می‌داد».
    */
    Route::get('/gpu', [\App\Http\Controllers\GpuController::class, 'show'])->name('gpu');
    Route::post('/gpu/order', [\App\Http\Controllers\GpuOrderController::class, 'start'])->name('gpu.order')->middleware('throttle:12,1');
    /*
    | «نشان سرورنت» — موتورِ لینک‌سازیِ مشتری‌ها (ممیزی بک‌لینک ۲۵ اوت: لینکِ
    | واقعیِ کسب‌شده تقریباً صفر بود). صفحهٔ ایستا؛ همهٔ داده در خودِ ویو ساخته
    | می‌شود، پس Route::view کافی است.
    */
    Route::view('/badge', 'pages.badge')->name('badge');
    Route::get('/domain', fn () => redirect()->to(lroute('domain.search'), 301))->name('domain.index');
    /*
    | 🔴 آدرس‌های مردهٔ دورانِ وردپرس/WHMCS که Search Console هنوز ۴۰۴شان را
    | گزارش می‌کند (ممیزی ۲۴ اوت ۲۰۲۶): /privacy-policy (اسلاگِ استاندارد
    | وردپرس)، /home، /cart (سبدِ WHMCSِ مرده)، /services، /marketing و
    | /servernet. مقصدها نزدیک‌ترین معادلِ واقعی‌اند؛ همان الگویِ closure +
    | lroute بالا (سه‌بار ثبت، مقصدِ هم‌زبان). بقیهٔ ۴۰۴ها (wp-*.php،
    | cgi-bin…) عمداً ۴۰۴ می‌مانند — آدرسِ آشغال مقصد ندارد.
    */
    Route::get('/privacy-policy', fn () => redirect()->to(lroute('privacy'), 301))->name('privacy.legacy');
    Route::get('/home', fn () => redirect()->to(lroute('home'), 301))->name('home.legacy');
    Route::get('/cart', fn () => redirect()->to(lroute('cloud.index'), 301))->name('cart.legacy');
    Route::get('/services', fn () => redirect()->to(lroute('home'), 301))->name('services.legacy');
    Route::get('/marketing', fn () => redirect()->to(lroute('home'), 301))->name('marketing.legacy');
    Route::get('/servernet', fn () => redirect()->to(lroute('home'), 301))->name('servernet.legacy');
    Route::get('/contact', [SiteController::class, 'contact'])->name('contact');
    Route::get('/knowledge', [SiteController::class, 'knowledge'])->name('knowledge');

    // فروشگاهِ سرورِ فیزیکی (HP/Dell/Lenovo/Supermicro) — فهرست + صفحهٔ هر مدل با گالری
    Route::get('/servers', [ServerShopController::class, 'index'])->name('servers.index');
    Route::get('/servers/{slug}', [ServerShopController::class, 'show'])->name('servers.show')->where('slug', '[a-z0-9-]+');

    /*
    |--------------------------------------------------------------------------
    | فروشگاهِ قطعاتِ سرور
    |--------------------------------------------------------------------------
    |
    | 🔴 ترتیبِ این چهار خط **معنادار** است.
    |
    | `/parts/compare` باید **پیش از** `/parts/{category}` ثبت شود، وگرنه لاراول
    | «compare» را یک دستهٔ نامعتبر می‌بیند و صفحهٔ مقایسه — که قلبِ تصمیم‌گیریِ
    | خریدارِ فنی است — همیشه ۴۰۴ می‌دهد. هیچ خطایی هم دیده نمی‌شود؛ فقط یک
    | دکمه که کار نمی‌کند. `ServerPartsRoutingTest` همین را قفل می‌کند.
    |
    | `/servers/hp/{gen}` با `/servers/{slug}` تداخل ندارد (دو بخش در برابرِ یک
    | بخش)، پس جایش این‌جا امن است و کنارِ فروشگاهِ سرور خواناتر می‌ماند.
    */
    Route::get('/parts', [PartsShopController::class, 'index'])->name('parts.index');
    Route::get('/parts/compare', [PartsShopController::class, 'compare'])->name('parts.compare');
    Route::get('/parts/{category}', [PartsShopController::class, 'category'])
        ->name('parts.category')->where('category', '[a-z]+');
    Route::get('/parts/{category}/{slug}', [PartsShopController::class, 'show'])
        ->name('parts.show')->where(['category' => '[a-z]+', 'slug' => '[a-z0-9-]+']);
    Route::get('/servers/hp/{gen}', [PartsShopController::class, 'generation'])
        ->name('servers.generation')->where('gen', 'gen(8|9|10|11|12)');
    Route::get('/careers', [\App\Http\Controllers\CareersController::class, 'show'])->name('careers');
    Route::post('/careers/apply', [\App\Http\Controllers\CareersController::class, 'apply'])->name('careers.apply')->middleware('throttle:forms');
    Route::get('/about', fn () => app(SiteController::class)->page('about'))->name('about');
    Route::get('/privacy', fn () => app(SiteController::class)->page('privacy'))->name('privacy');
    Route::get('/terms', fn () => app(SiteController::class)->page('terms'))->name('terms');
    // AUP — منع بازفروش سرویس عبور روی زیرساخت ایران (ممیزی ۳، مدیر حقوقی/امنیت)
    Route::get('/aup', fn () => app(SiteController::class)->page('aup'))->name('aup');

    // خلاصهٔ سفارشِ پیش از ورود (ممیزی ۴ — «اگر فقط یک کار در ۳۰ روز»):
    // قیمت و دوره‌ها بی‌نشست روی خودِ سایت؛ console فقط در گامِ پرداخت.
    Route::get('/order/{slug}', [\App\Http\Controllers\OrderSummaryController::class, 'show'])
        ->name('order.summary')->where('slug', '[a-z0-9-]+');

    /*
    | /go/pay — تنها گذرگاهِ «سفارش ← پرداخت» (ممیزی ۷ — رشد، قلم ۳ رودمپ).
    |
    | چرا ریدایرکتِ داخلی به‌جای لینکِ مستقیم به console:
    |   · هر کلیک در **اکسس‌لاگِ خودِ سایت** و در Funnel ثبت می‌شود — اولین
    |     عددِ قیف (نرخِ تبدیلِ سفارش ← پرداخت)، بدونِ کوکی و JS.
    |   · امضای HMAC (OrderHandoff) در لحظهٔ کلیک ساخته می‌شود، نه در رندرِ
    |     صفحهٔ کش‌شده — exp همیشه تازه است و لینکِ تبِ شبانه نمی‌میرد.
    |
    | داخلِ $site است تا سه‌زبانه ثبت شود و console_lroute دورهٔ زبانِ درست را
    | بسازد (زبان فقط از پیشوندِ URL — قراردادِ پروژه). هرگز کش نمی‌شود
    | (exclude_paths) و robots هم Disallow /go/ دارد. هیچ open-redirectی ممکن
    | نیست: مقصد همیشه روتِ ثابتِ console است.
    */
    Route::get('/go/pay', [\App\Http\Controllers\OrderSummaryController::class, 'pay'])
        ->name('go.pay')->middleware('throttle:60,1');

    // متدولوژی سرعت — جایگزینِ ادعای بی‌سندِ req/s (ممیزی ۴، مارکتینگ/حقوقی)
    Route::get('/speed', fn () => app(SiteController::class)->page('speed'))->name('speed');

    // گزارش سوءاستفاده (ممیزی ۴ — امنیت): «AUP بدونِ کانالِ ورودی اجرا
    // نمی‌شود؛ فقط مسئولیت می‌سازد.» فرم عمومی + گلوگاهِ فرم‌ها.
    Route::get('/abuse', [\App\Http\Controllers\AbuseController::class, 'show'])->name('abuse');
    Route::post('/abuse', [\App\Http\Controllers\AbuseController::class, 'report'])
        ->name('abuse.report')->middleware('throttle:forms');

    /*
    | ممیزی ۶ — «قلمِ شاهد» شش دور: /share/url و /sharing/share-offsite.
    | حکم: «بساز یا کامل حذف کن — ۲۰۰ یا ۴۱۰؛ آنچه می‌سنجیم تصمیم است.»
    | تصمیم: ۴۱۰ Gone — همان کاری که /panel-preview را بست. هیچ backendی برای
    | اشتراک‌گذاری وجود ندارد و نخواهد داشت؛ اشتراک با لینکِ ایستا/بومی است.
    */
    Route::get('/share/{any?}', fn () => abort(410))->where('any', '.*')->name('share.gone');
    Route::get('/sharing/{any?}', fn () => abort(410))->where('any', '.*')->name('sharing.gone');

    // رویدادهای قیف از مرورگر (ممیزی ۶ — رشد) — صفحاتِ HIT به PHP نمی‌رسند
    Route::post('/api/funnel', [\App\Http\Controllers\FunnelController::class, 'store'])
        ->name('api.funnel')->middleware('throttle:beacon');

    // کانال‌های رسمی (ممیزی ۶ — مارکتینگ/حقوقی): پاسخ به کانالِ هم‌نامِ جعلی
    Route::get('/official-channels', fn () => app(SiteController::class)->page('official-channels'))->name('official');

    // صفحهٔ وضعیت و سندِ SLA — تبدیلِ «آپتایم تضمینی» از ادعا به سند.
    // بی‌اینها، تعهدِ عمومی بدونِ سقف و بدونِ فرآیندِ مطالبه بود.
    Route::get('/status', [SiteController::class, 'status'])->name('status');
    Route::get('/sla', fn () => view('pages.sla'))->name('sla');

    /*
    | مستنداتِ APIِ نمایندگیِ دامنه.
    |
    | ⚠️ مسیر عمداً `/developers` است و نه `/api`: `bootstrap/app.php` می‌گوید
    | `shouldRenderJsonWhen(is('api/*'))`، پس هر صفحهٔ HTMLای زیرِ `/api`
    | خطاهایش را JSON برمی‌گرداند — یعنی یک ۵۰۴ ساده به‌جای صفحهٔ خطای سایت،
    | یک بلوکِ JSON به بازدیدکننده نشان می‌دهد.
    */
    Route::get('/developers', fn () => view('pages.developers'))->name('developers');

    /*
    | مرجعِ دومِ API — تونلِ سرورِ اکسیت.
    |
    | ⚠️ عمداً صفحهٔ جداست و نه بخشی از `/developers`: آن صفحه عنوانش «مرجع
    | API نمایندگی دامنه» است و مشتریِ سرورِ اکسیت چهار ردیفِ لخت میانِ چهارده
    | ردیفِ دامنه می‌دید. فهرست‌شدن مستندسازی نیست.
    */
    Route::get('/developers/tunnel', fn () => view('pages.developers-tunnel'))->name('developers.tunnel');

    // صفحهٔ فرودِ شخصیِ «طراحی سایت و زیرساخت» — مقصدِ لینکِ لینکدین/اینستاگرام.
    // ⚠️ عمداً در منوی اصلی نیست، ولی در نقشهٔ سایت **هست**: کلِ هدفش ورودیِ
    //    ارگانیک از «طراحی سایت در ارومیه» است و صفحهٔ بی‌نقشه د��رتر ایندکس می‌شود.
    Route::get('/webdesign', fn () => view('pages.webdesign'))->name('webdesign');

    Route::get('/tools/{slug}', [ToolController::class, 'show'])->name('tools')->where('slug', '[a-z-]+');
    Route::post('/api/audit', [ToolController::class, 'audit'])->name('api.audit')->middleware('throttle:tools');
    Route::post('/api/whois', [ToolController::class, 'whois'])->name('api.whois')->middleware('throttle:tools');
    Route::post('/api/ip', [ToolController::class, 'ip'])->name('api.ip')->middleware('throttle:tools');
    // پیشنهادگر نام دامنه — سطل ai چون هر درخواست یک تماس مدل است
    Route::post('/api/domain-ideas', [ToolController::class, 'ideas'])->name('api.ideas')->middleware('throttle:ai');
    // تست سرعت اینترنت کاربر — سطل جدا؛ هر اجرا ~۱۵ درخواست است و down پرحجم
    Route::get('/api/speedtest/ping', [ToolController::class, 'speedPing'])->name('api.spt.ping')->middleware('throttle:speedtest');
    Route::get('/api/speedtest/down', [ToolController::class, 'speedDown'])->name('api.spt.down')->middleware('throttle:speedtest');
    Route::post('/api/speedtest/up', [ToolController::class, 'speedUp'])->name('api.spt.up')->middleware('throttle:speedtest');

    /*
     * گزارشِ ماندگارِ بررسیِ سایت — نشانی‌ای که برای صاحبِ سایت می‌فرستیم.
     *
     * داخلِ همین closure است، پس در هر سه زبان ساخته می‌شود و گزارشی که به
     * زبانِ انگلیسی گرفته شده با لینکِ `/en/report/…` باز می‌شود
     * (`AuditReport::url()` همین را می‌سازد).
     *
     * ⚠️ ترتیب مهم است: «unsubscribe» پیش از «{token}» بیاید وگرنه خودش یک
     * توکن خوانده می‌شود و ۴۰۴ می‌گیرد.
     */
    Route::get('/report/unsubscribe/{token}', [ReportController::class, 'unsubscribe'])
        ->name('report.unsubscribe')->where('token', '[a-z0-9]{16,40}');
    Route::get('/report/{token}', [ReportController::class, 'show'])
        ->name('report')->where('token', '[a-z0-9]{16,40}');
    Route::post('/report/{token}/consultation', [\App\Http\Controllers\ReportConsultationController::class, 'store'])
        ->name('report.consultation')->where('token', '[a-z0-9]{16,40}')->middleware('throttle:3,1');

    /*
     * گزارشِ ماندگارِ بررسیِ سایت — نشانی‌ای که برای صاحبِ سایت می‌فرستیم.
     *
     * داخلِ همین closure است، پس در هر سه زبان ساخته می‌شود و گزارشی که به
     * زبانِ انگلیسی گرفته شده با لینکِ `/en/report/…` باز می‌شود
     * (`AuditReport::url()` همین را می‌سازد).
     *
     * ⚠️ ترتیب مهم است: «unsubscribe» پیش از «{token}» بیاید وگرنه خودش یک
     * توکن خوانده می‌شود و ۴۰۴ می‌گیرد.
     */
    Route::get('/report/unsubscribe/{token}', [ReportController::class, 'unsubscribe'])
        ->name('report.unsubscribe')->where('token', '[a-z0-9]{16,40}');
    Route::get('/report/{token}', [ReportController::class, 'show'])
        ->name('report')->where('token', '[a-z0-9]{16,40}');

    // ابزارهای جامع DNS و شبکه (هاب)
    Route::get('/dns-lookup', [LookupController::class, 'hub'])->name('hub.dns')->defaults('hub', 'dns');
    Route::get('/network-scan', [LookupController::class, 'hub'])->name('hub.network')->defaults('hub', 'network');
    Route::post('/api/dns-report', [LookupController::class, 'dnsReport'])->name('api.dnsreport')->middleware('throttle:tools');

    // مجموعه ابزار DNS و شبکه (Lookup) — صفحات تکی سئویی
    Route::get('/lookup', [LookupController::class, 'index'])->name('lookup.index');
    Route::get('/lookup/{type}', [LookupController::class, 'show'])->name('lookup')->where('type', '[a-z-]+');
    Route::post('/api/lookup', [LookupController::class, 'run'])->name('api.lookup')->middleware('throttle:tools');

    // ابزارهای رایگان وب‌مستر (همه سمت کاربر)
    Route::get('/webtools', [\App\Http\Controllers\WebToolsController::class, 'index'])->name('webtools.index');
    Route::get('/webtools/{slug}', [\App\Http\Controllers\WebToolsController::class, 'show'])->name('webtools')->where('slug', '[a-z0-9-]+');

    /*
    | 🔴 پیش‌نمایشِ ماکِ پنل **حذف شد** — و این‌جا عمداً ۴۱۰ می‌مانَد، نه هیچ.
    |
    | آن پنج مسیر بی‌هیچ احراز هویتی عمومی بودند و چون در همین closure ثبت
    | شده بودند، در هر سه زبان. محتوایشان دادهٔ ساختگی بود ولی با **نامِ واقعیِ
    | مالک**، شمارهٔ فاکتور و مبلغ و آی‌پی — یعنی صفحه‌ای عمومی که یک رابطهٔ
    | مالیِ ناموجود را به اسمِ یک شخصِ حقیقی نشان می‌داد. تاریخ‌های داخلش مالِ
    | ۱۴۰۴ بود: بیش از یک سال رهاشده.
    |
    | شرطِ حذفش از قبل برقرار بود — CLAUDE.md نوشته بود «با ساخت پنل واقعی
    | حذف می‌شود» و پنلِ واقعیِ مشتری ماه‌هاست کار می‌کند. فقط کسی برنگشت.
    |
    | ⚠️ چرا ۴۱۰ و نه ۴۰۴: این آدرس‌ها ممکن است ایندکس شده باشند و ۴۱۰ به
    | خزنده می‌گوید «برای همیشه رفت»، که حذفشان را سریع‌تر می‌کند. ۴۰۴ یعنی
    | «الان نیست» و ماه‌ها دوباره امتحان می‌شود.
    |
    | ⚠️ این مسیر عمداً `{any?}` دارد: زیرمسیرها (server، admin، tickets…) هم
    | باید همان جواب را بدهند، وگرنه نیمی از آدرس‌های ایندکس‌شده ۴۰۴ می‌گیرند.
    */
    Route::get('/panel-preview/{any?}', fn () => abort(410))
        ->where('any', '.*')->name('panel.preview.gone');

    // مستندات
    Route::get('/docs', [\App\Http\Controllers\DocsController::class, 'index'])->name('docs.index');
    Route::get('/docs/{slug}', [\App\Http\Controllers\DocsController::class, 'show'])->name('docs')->where('slug', '[a-z0-9-]+');

    // بلاگ
    Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
    Route::get('/blog/mod/{comment}/{action}', [BlogController::class, 'moderate'])->name('blog.moderate')->where('action', 'approve|delete');
    Route::post('/blog/{slug}/comment', [BlogController::class, 'comment'])->name('blog.comment')->where('slug', '[a-z0-9-]+')->middleware('throttle:forms');
    Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog')->where('slug', '[a-z0-9-]+');

    // صفحات راهکار سازمانی
    // هابِ راهکارها — والدِ موضوعیِ صفحات راهکار. پیش از روتِ {slug} ثبت می‌شود
    // تا «/solutions» را خودش بگیرد نه الگوی slug.
    Route::get('/transactional-email', [\App\Http\Controllers\TransactionalEmailController::class, 'show'])->name('email.transactional');
    Route::post('/transactional-email/quote', [\App\Http\Controllers\TransactionalEmailController::class, 'store'])->name('email.quote')->middleware('throttle:forms');
    Route::get('/solutions', [SolutionController::class, 'index'])->name('solutions.index');
    Route::get('/solutions/{slug}', [SolutionController::class, 'show'])->name('solution')->where('slug', '[a-z-]+');

    // سرورِ مجازی — کاتالوگِ عمومی و صفحهٔ اختصاصیِ هر مکان.
    // داخلِ همین closure است، پس خودکار در سه زبان ساخته می‌شود (/cloud، /en/cloud،
    // /tr/cloud). دکمهٔ خرید به سرورسازِ پنل می‌رود، نه سبدِ خریدِ بیرونی.
    // ⚠️ ترتیب مهم است: مسیرِ ثابت پیش از الگوی {location} تا خودش را بگیرد.
    Route::get('/cloud', [\App\Http\Controllers\CloudCatalogController::class, 'index'])->name('cloud.index');
    Route::get('/cloud/{location}', [\App\Http\Controllers\CloudCatalogController::class, 'location'])
        ->name('cloud.location')->where('location', '[a-z0-9-]+');
    Route::get('/{category}/{slug}', [CatalogController::class, 'show'])->name('catalog')
        ->whereIn('category', ['vps', 'dedicated', 'cloud', 'domain', 'services'])->where('slug', '[a-z0-9-]+');
    Route::post('/api/chat', ChatController::class)->name('chat')->middleware('throttle:ai');
    Route::post('/api/domain-check', DomainCheckController::class)->name('domain.check')->middleware('throttle:tools');

    // جستجوی دامنه از رسیلری (OpenProvider) — مسیر جدید، جدا از مسیر WHMCS بالا
    Route::get('/domains', [\App\Http\Controllers\DomainSearchController::class, 'page'])->name('domain.search');

    /*
    | صفحهٔ عمومیِ انتقالِ دامنه.
    |
    | ⚠️ زیرِ `/domains/` است و نه `/domain/transfer`: مسیرِ دوم را روتِ
    | کاتالوگ (`/{category}/{slug}` با category در فهرستِ domain) می‌بلعد و
    | نتیجه‌اش یک ۴۰۴ می‌شود که علتش هیچ‌جا پیدا نیست.
    */
    Route::view('/domains/transfer', 'pages.domain-transfer')->name('domain.transfer.page');
    Route::post('/api/domains/search', [\App\Http\Controllers\DomainSearchController::class, 'check'])
        ->name('domain.search.check')->middleware('throttle:tools');
    Route::get('/api/domains/status', [\App\Http\Controllers\DomainSearchController::class, 'status'])
        ->name('domain.status')->middleware('throttle:tools');
    Route::post('/api/builder', [AiBuilderController::class, 'chat'])->name('builder.chat')->middleware('throttle:ai');
    // نسخهٔ SSE — تولیدِ کامل ~۲ دقیقه است و پشتِ Cloudflare درخواستِ بی‌خروجی
    // ۵۰۴ می‌گیرد؛ builder.js اول این را می‌زند و اگر نبود به بالایی برمی‌گردد
    Route::post('/api/builder/stream', [AiBuilderController::class, 'stream'])->name('builder.stream')->middleware('throttle:ai');
    Route::post('/api/builder/save', [AiBuilderController::class, 'save'])->name('builder.save')->middleware('throttle:tools');
    // انتشارِ آزمایشیِ ۴۸ساعته — لینکِ عمومی در GET /sb/{ref} (بیرونِ closure، تک‌نسخه)
    Route::post('/api/builder/publish', [AiBuilderController::class, 'publish'])->name('builder.publish')->middleware('throttle:tools');

    /*
    |------------------------------------------------------------------------
    | حساب مشتری — ثبت‌نام، ورود، پنل
    |------------------------------------------------------------------------
    | throttle روی هر POST که یا پول خرج می‌کند یا قابل حدس زدن است.
    | اعداد سخت‌گیرانه‌اند چون هر ثبت‌نام ایرانی ۸۱٬۰۰۰ تومان استعلام دارد؛
    | سقف واقعی داخل OtpService و RegisterController است و این فقط لایهٔ اول.
    */
    Route::middleware('guest:customer')->group(function () {
        Route::get('/login/google', [Auth\GoogleController::class, 'start'])->name('google.start')->middleware('throttle:signin');
        Route::get('/register/google-phone', [Auth\GoogleController::class, 'phone'])->name('google.phone')->middleware('throttle:signin');
        Route::get('/login/google/callback', [Auth\GoogleController::class, 'callback'])->name('google.callback')->middleware('throttle:signin');
        Route::get('/register', [Auth\RegisterController::class, 'showStart'])->name('register');
        Route::post('/register', [Auth\RegisterController::class, 'start'])->name('register.start')->middleware('throttle:reg');

        Route::get('/register/verify', [Auth\RegisterController::class, 'showVerify'])->name('register.verify.form');
        Route::post('/register/verify', [Auth\RegisterController::class, 'verify'])->name('register.verify')->middleware('throttle:otp');
        Route::post('/register/resend', [Auth\RegisterController::class, 'resend'])->name('register.resend')->middleware('throttle:resend');

        Route::get('/register/identity', [Auth\RegisterController::class, 'showIdentity'])->name('register.identity.form');
        Route::post('/register/identity', [Auth\RegisterController::class, 'identity'])->name('register.identity')->middleware('throttle:kyc');

        Route::get('/register/finish', [Auth\RegisterController::class, 'showFinish'])->name('register.finish.form');
        Route::post('/register/finish', [Auth\RegisterController::class, 'finish'])->name('register.finish')->middleware('throttle:reg');

        Route::get('/login', [Auth\LoginController::class, 'show'])->name('login');
        Route::post('/login', [Auth\LoginController::class, 'start'])->name('login.start')->middleware('throttle:signin');
        Route::get('/login/code', [Auth\LoginController::class, 'code'])->name('login.code');
        Route::post('/login/verify', [Auth\LoginController::class, 'verify'])->name('login.verify')->middleware('throttle:otp');
        Route::post('/login/resend', [Auth\LoginController::class, 'resend'])->name('login.resend')->middleware('throttle:resend');

        /*
        | مرحلهٔ سومِ ورود — کدِ اپلیکیشنِ احرازِ هویت، فقط برای حسابی که خودش
        | روشنش کرده. مثلِ مرحلهٔ دو بیرونِ `auth:customer` است چون کاربر در
        | این لحظه هنوز وارد **نشده**؛ گذارش فقط با کلیدِ نشست است.
        */
        Route::get('/login/2fa', [Auth\LoginController::class, 'twoFactor'])->name('login.2fa');
        Route::post('/login/2fa', [Auth\LoginController::class, 'twoFactorVerify'])->name('login.2fa.verify')->middleware('throttle:otp');
    });

    Route::post('/logout', [Auth\LoginController::class, 'logout'])->name('logout');

    Route::middleware(['auth:customer', \App\Http\Middleware\EnsureCustomerActive::class, \App\Http\Middleware\EnforceCustomerIp::class, \App\Http\Middleware\PrepareBirthdayReward::class])->prefix('account')->name('account.')->group(function () {
        require __DIR__.'/domain-management.php';
        require __DIR__.'/madar-account.php';
        Route::get('/', [Account\AccountController::class, 'home'])->name('home');
        Route::get('/offers', [Account\OfferController::class, 'index'])->name('offers');
        Route::post('/offers/preferences', [Account\OfferController::class, 'preferences'])->name('offers.preferences')->middleware('throttle:10,1');
        Route::get('/marketing/unsubscribe', [Account\OfferController::class, 'unsubscribePage'])->name('marketing.unsubscribe');
        Route::post('/marketing/unsubscribe', [Account\OfferController::class, 'unsubscribe'])->middleware('throttle:10,1');
        Route::get('/offers/{grant}/open', [Account\OfferController::class, 'open'])->name('offers.open');
        Route::post('/invoices/{invoice}/offer', [Account\OfferController::class, 'apply'])->name('invoice.offer')->middleware('throttle:10,1');
        Route::post('/invoices/{invoice}/offer/free', [Account\OfferController::class, 'free'])->name('invoice.offer.free')->middleware('throttle:10,1');

        // «همه» — این نشانی هرگز عوض نمی‌شود: هشت ارجاعِ ورودی رویش نشسته‌اند،
        // از جمله اعلانِ بازگشتِ وجه که واقعاً برای مشتری فرستاده می‌شود
        // (ProvisioningService) و لینکِ مطلقِ کنسول در هدرِ سایت.
        Route::get('/services', [Account\ServiceController::class, 'index'])->name('services');

        /*
        | «چهار اتاق» — هر نوعِ سرویس، صفحه و چیدمانِ دادهٔ خودش.
        |
        | هم‌ترازِ `/account/domains` که از قبل همین الگو را داشت. عمداً بیرونِ
        | `/services/…` تعریف شده‌اند تا با پنج زیرمسیرِ موجودِ آن
        | (cancel/terminate/cpanel/stats) هم‌خانواده به نظر نرسند — آن‌ها روی یک
        | سرویسِ مشخص عمل می‌کنند، این‌ها فهرست‌اند.
        */
        Route::get('/hosting', [Account\SectionController::class, 'hosting'])->name('hosting');
        Route::get('/servers', [Account\SectionController::class, 'servers'])->name('servers');
        Route::get('/other', [Account\SectionController::class, 'other'])->name('other');
        require __DIR__.'/sms-account.php';
        // [managed-dns:start]
        /*
        |----------------------------------------------------------------------
        | DNSِ مدیریت‌شده v2 — docs/dns/MANAGED-DNS-DESIGN.md §8، §10
        |----------------------------------------------------------------------
        |
        | ⚠️ `{zone}`/`{claim}`/`{record}` عمداً مدلِ بایندشده نیستند: کنترلر از
        | `zoneOf()`/`claimOf()`/`recordOf()` می‌گذرد که مالکیت را می‌سنجد، و
        | ZoneService همان را دوباره می‌سنجد (IDOR در دو لایه بسته است).
        |
        | throttle روی هر مسیری که استعلامِ DNSِ بیرونی می‌کند یا می‌نویسد.
        */
        Route::get('/dns', [Account\DnsController::class, 'index'])->name('dns');
        Route::post('/dns', [Account\DnsController::class, 'store'])->middleware('throttle:dns-lookup');
        Route::get('/dns/claims/{claim}', [Account\DnsController::class, 'claim'])->name('dns.claim')->whereNumber('claim');
        Route::post('/dns/claims/{claim}/verify', [Account\DnsController::class, 'verifyClaim'])->name('dns.claim.verify')->whereNumber('claim')->middleware('throttle:dns-lookup');
        Route::post('/dns/claims/{claim}/cancel', [Account\DnsController::class, 'cancelClaim'])->name('dns.claim.cancel')->whereNumber('claim')->middleware('throttle:dns-write');
        Route::get('/dns/{zone}', [Account\DnsController::class, 'show'])->name('dns.zone')->whereNumber('zone');
        Route::post('/dns/{zone}/check', [Account\DnsController::class, 'check'])->whereNumber('zone')->middleware('throttle:dns-lookup');
        Route::post('/dns/{zone}/reviewed', [Account\DnsController::class, 'reviewed'])->whereNumber('zone')->middleware('throttle:dns-write');
        Route::get('/dns/{zone}/export', [Account\DnsController::class, 'export'])->whereNumber('zone')->middleware('throttle:dns-write');
        Route::post('/dns/{zone}/delete', [Account\DnsController::class, 'destroy'])->whereNumber('zone')->middleware('throttle:dns-delete');
        Route::get('/dns/{zone}/import', [Account\DnsController::class, 'import'])->name('dns.import')->whereNumber('zone');
        Route::post('/dns/{zone}/import/preview', [Account\DnsController::class, 'importPreview'])->whereNumber('zone')->middleware('throttle:dns-import');
        Route::post('/dns/{zone}/import/apply', [Account\DnsController::class, 'importApply'])->whereNumber('zone')->middleware('throttle:dns-import');
        Route::post('/dns/{zone}/records', [Account\DnsController::class, 'storeRecord'])->whereNumber('zone')->middleware('throttle:dns-write');
        Route::post('/dns/{zone}/records/{record}', [Account\DnsController::class, 'updateRecord'])->whereNumber(['zone', 'record'])->middleware('throttle:dns-write');
        Route::post('/dns/{zone}/records/{record}/toggle', [Account\DnsController::class, 'toggleRecord'])->whereNumber(['zone', 'record'])->middleware('throttle:dns-write');
        Route::post('/dns/{zone}/records/{record}/delete', [Account\DnsController::class, 'deleteRecord'])->whereNumber(['zone', 'record'])->middleware('throttle:dns-write');
        // [managed-dns:end]
        // لغوِ سفارشِ تحویل‌نشده توسط خودِ مشتری (با بازگشتِ وجه به کیفِ پول).
        // بی‌این، سفارشی که تحویلش شکست خورده تا ابد «در حالِ آماده‌سازی» می‌ماند
        // و مشتری نه سرور دارد نه پولش.
        Route::post('/services/{service}/cancel', [Account\ServiceController::class, 'cancel'])
            ->name('services.cancel')->middleware('throttle:6,1');
        // حذفِ سرویسِ تحویل‌شده — دومرحله‌ای. سقفِ صدور تنگ‌تر از تأیید است
        // چون هر صدور یک پیامک/ایمیلِ واقعی می‌فرستد و هزینه دارد.
        Route::post('/services/{service}/terminate/start', [Account\ServiceController::class, 'terminateStart'])
            ->name('services.terminate.start')->middleware('throttle:4,1');
        Route::post('/services/{service}/terminate', [Account\ServiceController::class, 'terminate'])
            ->name('services.terminate')->middleware('throttle:8,1');

        Route::get('/services/{service}/cpanel', [Account\ServiceController::class, 'cpanel'])->name('services.cpanel');
        // ⚠️ محدودیتِ نرخ مثلِ دوقلوی ابری‌اش: این مسیر به API کنترل‌پنل می‌رسد
        // و یک حلقهٔ ساده در مرورگر می‌تواند سهمیهٔ WHM را بسوزاند. کشِ سه
        // دقیقه‌ای بیشترش را می‌گیرد، ولی کش per-service است نه per-customer.
        Route::get('/services/{service}/stats', [Account\ServiceController::class, 'stats'])->name('services.stats')->middleware('throttle:60,1');
        Route::get('/services/{service}/domain-connection', Account\HostingDomainConnectionController::class)->name('services.domain-connection')->middleware('throttle:20,1');
        Route::get('/services/{service}/upgrade', [Account\HostingUpgradeController::class, 'show'])->name('services.upgrade')->middleware('throttle:20,1');
        Route::post('/services/{service}/upgrade', [Account\HostingUpgradeController::class, 'store'])->name('services.upgrade.store')->middleware('throttle:10,1');

        // سرورساز — مشتری خودش سرورِ مجازی می‌سازد: مکان → پلن → سیستم‌عامل/
        // نرم‌افزارِ آماده → دوره → نامِ سرور → پیش‌فاکتور → پرداخت → تحویلِ خودکار.
        // صفحاتِ عمومیِ سایت با ?location=…&plan=… به همین‌جا لینک می‌دهند، پس
        // مسیر و نامِ روت‌ها را عوض نکنید. ثبتِ سفارش محدودیتِ نرخ دارد چون هر
        // سفارش یک سرویسِ pending و یک پیش‌فاکتورِ واقعی می‌سازد.
        Route::get('/cloud-store', [Account\CloudStoreController::class, 'index'])->name('cloud.store');
        Route::get('/gpu/continue', [\App\Http\Controllers\GpuOrderController::class, 'resume'])->name('gpu.resume');
        Route::post('/gpu/continue', [\App\Http\Controllers\GpuOrderController::class, 'complete'])->name('gpu.complete')->middleware('throttle:12,1');
        Route::get('/gpu-capacity', [Account\GpuCapacityController::class, 'check'])
            ->name('gpu.capacity')->middleware('throttle:30,1');
        Route::post('/cloud/{service}/gpu-price', [Account\GpuCapacityController::class, 'confirmPrice'])
            ->name('cloud.gpu-price')->middleware('throttle:12,1');
        Route::post('/cloud-store', [Account\CloudStoreController::class, 'order'])
            ->name('cloud.store.place')->middleware('throttle:12,1');

        // مدیریتِ سرورِ ابری — روشن/خاموش، نصبِ دوباره، رمز، کنسول، نمودارِ مصرف.
        // عملیاتِ حساس همه POST‌اند تا با یک لینکِ جعلی (CSRF/prefetch) اجرا نشوند.
        Route::get('/cloud/{service}', [Account\CloudServerController::class, 'show'])->name('cloud.show');
        // ⚠️ این دو GET با زیرساخت تماس می‌گیرند، پس مثلِ POSTها سقف دارند.
        // بی‌سقف، یک حلقهٔ ساده با کوکیِ نشست سهمیهٔ APIِ **مشترکِ کلِ پروژه** را
        // می‌سوزاند و از آن لحظه تحویلِ سرورِ همهٔ مشتریانِ دیگر شکست می‌خورد.
        Route::get('/cloud/{service}/status', [Account\CloudServerController::class, 'status'])
            ->name('cloud.status')->middleware('throttle:60,1');
        Route::get('/cloud/{service}/metrics', [Account\CloudServerController::class, 'metrics'])
            ->name('cloud.metrics')->middleware('throttle:30,1');
        Route::post('/cloud/{service}/power', [Account\CloudServerController::class, 'power'])->name('cloud.power');

        /*
        | نمایشِ یک‌بارهٔ رمزِ root.
        |
        | ⚠️ POST است چون **حالت را عوض می‌کند** (`password_seen`). تا امروز
        | همین کار داخلِ GETِ صفحه انجام می‌شد و هر رفرش یا prefetch رمز را
        | پیش از دیده‌شدن می‌سوزاند — مشتری سرور داشت و راهی به داخلش نداشت.
        */
        Route::post('/cloud/{service}/reveal-password', [Account\CloudServerController::class, 'revealPassword'])
            ->name('cloud.reveal')->middleware('throttle:6,1');
        Route::post('/cloud/{service}/rebuild', [Account\CloudServerController::class, 'rebuild'])->name('cloud.rebuild');
        Route::post('/cloud/{service}/password', [Account\CloudServerController::class, 'resetPassword'])->name('cloud.password');
        Route::post('/cloud/{service}/console', [Account\CloudServerController::class, 'console'])->name('cloud.console');
        // سرورِ ساعتی → دوره‌ای، و «وقتی اعتبار تمام شد» پس از خرید (TK-261018-5379)
        Route::post('/cloud/{service}/convert-cycle', [Account\CloudServerController::class, 'convertCycle'])
            ->name('cloud.convert-cycle')->middleware('throttle:10,1');
        Route::post('/cloud/{service}/credit-out', [Account\CloudServerController::class, 'setCreditOut'])
            ->name('cloud.credit-out')->middleware('throttle:10,1');
        // سوییچِ کشورِ خروج توسطِ خودِ مشتری (فازِ A) — فقط برای سرورهای دارای اکسیت
        Route::post('/cloud/{service}/exit-country', [Account\CloudServerController::class, 'setExitCountry'])
            ->name('cloud.exit-country')->middleware('throttle:12,1');

        // اکانت‌های «WireGuard روی TCP» — فقط برای سرورهایی که پروفایلِ تونل دارند.
        Route::post('/cloud/{service}/tunnel', [Account\CloudServerController::class, 'issueTunnelAccount'])
            ->name('cloud.tunnel.issue')->middleware('throttle:12,1');
        Route::post('/cloud/{service}/tunnel/remove', [Account\CloudServerController::class, 'removeTunnelAccount'])
            ->name('cloud.tunnel.remove')->middleware('throttle:20,1');
        Route::post('/cloud/{service}/tunnel/agent', [Account\CloudServerController::class, 'enrollTunnelAgent'])
            ->name('cloud.tunnel.agent')->middleware('throttle:5,1');
        // صفحهٔ کنسولِ زنده روی **دامنهٔ خودمان** + بلیتِ یک‌بارمصرفِ آدرسِ اتصال.
        // ⚠️ الگوی مسیرِ view در SecurityHeaders هم آمده (تنها جایی که CSP اجازهٔ
        // wss: می‌دهد). اگر مسیر را عوض کردی، آن‌جا را هم عوض کن وگرنه مرورگر
        // اتصال را بی‌صدا بلاک می‌کند و صفحه تا ابد «در حالِ اتصال» می‌مانَد.
        Route::get('/cloud/{service}/console/view', [Account\CloudServerController::class, 'consoleView'])
            ->name('cloud.console.view');
        Route::get('/cloud/{service}/console/ticket', [Account\CloudServerController::class, 'consoleTicket'])
            ->name('cloud.console.ticket')->middleware('throttle:20,1');
        // خرید — از دکمهٔ خریدِ سایت اصلی مستقیم به تسویهٔ همان پکیج در پنل
        Route::get('/store', [Account\StoreController::class, 'index'])->name('store');            // به کاتالوگِ سایت اصلی می‌فرستد
        Route::get('/order/{product:slug}', [Account\StoreController::class, 'checkout'])->name('order');
        Route::post('/order/{product:slug}', [Account\StoreController::class, 'order'])->name('order.place')->middleware('throttle:12,1');
        // تسویهٔ سایت‌ساز: هاست + دامنه در یک فاکتور، استقرارِ خودکار بعد از پرداخت
        Route::get('/builder-checkout', [Account\BuilderCheckoutController::class, 'show'])->name('builder.checkout');
        Route::post('/builder-checkout', [Account\BuilderCheckoutController::class, 'order'])->name('builder.order')->middleware('throttle:12,1');
        Route::get('/profile', [Account\AccountController::class, 'profile'])->name('profile');

        /*
        | دامنه — خرید و مدیریت.
        |
        | ⚠️ `order` نرخ‌محدود است چون هر سفارش یک ردیفِ دامنه و یک فاکتور
        | می‌سازد؛ بی‌محدودیت، یک اسکریپت می‌تواند دفترِ فاکتور را پر کند.
        | خودِ ثبت این‌جا انجام نمی‌شود (بعد از پرداخت، با کرون).
        */
        Route::get('/domains', [Account\DomainController::class, 'index'])->name('domains');

        /*
        |----------------------------------------------------------------------
        | پنلِ نمایندگیِ دامنه
        |----------------------------------------------------------------------
        |
        | ⚠️ نامِ `account.reseller` از سه جای **بیرونِ** این فایل صدا زده
        | می‌شود — `AccountController::shell()` (یعنی هدرِ هر صفحهٔ پنل)،
        | `account/reseller.blade.php` و صفحهٔ `/domain/reseller`. تا وقتی این
        | روت نبود، `lroute()` استثنا می‌داد و **هر صفحهٔ پنل** ۵۰۰ می‌شد، نه
        | فقط صفحهٔ نمایندگی. اگر روزی این روت را برداشتی، آن سه را هم بردار.
        |
        | صفحه برای مشتریِ غیرِنماینده هم باز است (حالتِ معرفی) — عمدی است، پس
        | هیچ middlewareِ «فقط نماینده» رویش نیست؛ خودِ کنترلر تفکیک می‌کند.
        */
        Route::get('/reseller', [Account\ResellerController::class, 'index'])->name('reseller');

        /*
        | دانلودِ افزونه‌ها (WHMCS و وردپرس). سقفِ نرخ دارد چون zip در **لحظه**
        | ساخته می‌شود (عمداً در مخزن نگه داشته نمی‌شود) و یک حلقهٔ ساده در
        | مرورگر می‌تواند CPU سرور را بخورد.
        |
        | ⚠️ این روت یک بار **دو نسخه** داشت — یکی این‌جا و یکی پایین‌تر کنارِ
        | `/reseller` — هر دو با نامِ `reseller.module`. لاراول خطا نمی‌دهد و
        | نامِ تکراری را بی‌صدا به آخری می‌بندد، پس `lroute()` به یکی می‌رفت و
        | throttle روی آنِ دیگری بود. اگر روزی خواستی روتِ تازه‌ای این‌جا اضافه
        | کنی، اول `grep` بزن که نامش تکراری نباشد.
        */
        Route::get('/reseller/module/{kind}', [Account\ResellerController::class, 'download'])
            ->name('reseller.module')
            ->where('kind', 'whmcs|wordpress')
            ->middleware('throttle:10,1');

        /*
        | 🔴 صفحهٔ تسویه **پیش از** مسیرِ `/domains/{domain}` بیاید.
        |
        | لاراول اولین تطبیق را برمی‌دارد و `{domain}` هر رشته‌ای را می‌گیرد،
        | پس اگر پایین‌تر بنشیند، `/domains/checkout/12` به‌عنوانِ «دامنه‌ای به
        | نامِ checkout» تفسیر و ۴۰۴ می‌شود.
        */
        // ⚠️ throttle: شناسهٔ استعلام ترتیبی است؛ نرخِ محدود پیمایش را کند و
        //    پرهزینه می‌کند (گاردِ اصلی مالکیتِ quote در خودِ کنترلر است).
        Route::get('/domains/checkout/{quote}', [Account\DomainController::class, 'checkout'])
            ->name('domains.checkout')->middleware('throttle:30,1');

        Route::post('/domains/order', [Account\DomainController::class, 'order'])
            ->name('domains.order')->middleware('throttle:12,1');

        /*
        | انتقالِ دامنه — دو مرحله، و ترتیبشان اجباری است:
        |   order  → فاکتور (هیچ تماسی با رجیسترار)
        |   submit → کدِ انتقال + ارسالِ واقعی (فقط پس از پرداخت)
        | دلیلِ کاملِ دو مرحله بودن در `DomainController::transferOrder()`.
        |
        | ⚠️ `transfer` پیش از `/domains/{domain}` می‌آید، وگرنه لاراول آن را
        | «دامنه‌ای به نامِ transfer» می‌خوانَد — همان تله‌ای که چند خط پایین‌تر
        | برای `checkout` هم نوشته شده.
        */
        Route::post('/domains/transfer', [Account\DomainController::class, 'transferOrder'])
            ->name('domains.transfer')->middleware('throttle:12,1');
        Route::get('/domains/{domain}', [Account\DomainController::class, 'show'])->name('domain');
        Route::post('/domains/{domain}/nameservers', [Account\DomainController::class, 'nameservers'])
            ->name('domain.ns')->middleware('throttle:20,1');
        Route::post('/domains/{domain}/lock', [Account\DomainController::class, 'lock'])
            ->name('domain.lock')->middleware('throttle:20,1');
        Route::post('/domains/{domain}/authcode', [Account\DomainController::class, 'authCode'])
            ->name('domain.authcode')->middleware('throttle:6,1');
        Route::post('/domains/{domain}/auto-renew', [Account\DomainController::class, 'autoRenew'])
            ->name('domain.autorenew')->middleware('throttle:20,1');
        // تمدیدِ دستی: فقط فاکتور می‌سازد؛ تماسِ رجیسترار پس از پرداخت و با کرون
        Route::post('/domains/{domain}/renew', [Account\DomainController::class, 'renew'])
            ->name('domain.renew')->middleware('throttle:6,1');
        // بازیابیِ دامنهٔ منقضی (redemption) — همان قاعده: فقط فاکتور
        Route::post('/domains/{domain}/restore', [Account\DomainController::class, 'restore'])
            ->name('domain.restore')->middleware('throttle:6,1');
        // ⚠️ نرخِ پایین عمدی است: هر ارسال یک سفارشِ پولی نزدِ رجیسترار است
        Route::post('/domains/{domain}/transfer', [Account\DomainController::class, 'transferSubmit'])
            ->name('domain.transfer.submit')->middleware('throttle:6,1');

        // احراز هویت — به‌ویژه کاربرِ حقوقی (اطلاعات شرکت + معرفی‌نامه + اساسنامه)
        Route::get('/verify', [Account\VerificationController::class, 'show'])->name('verify');
        Route::post('/verify', [Account\VerificationController::class, 'submit'])->name('verify.submit')->middleware('throttle:forms');
        Route::get('/bank', [Account\BankAccountController::class, 'index'])->name('bank');
        Route::post('/bank', [Account\BankAccountController::class, 'store'])->name('bank.store')->middleware('throttle:bank');

        // امنیت حساب — رمز (با OTP)، قوانین IP، توکن‌های API
        Route::get('/ai', [\App\Http\Controllers\Account\AiController::class, 'index'])->name('ai');
        Route::get('/security', [Account\SecurityController::class, 'index'])->name('security')->middleware(\App\Http\Middleware\IdentityPrivacy::class);
        Route::middleware(\App\Http\Middleware\IdentityPrivacy::class)->group(function () {
            $identity=\App\Http\Controllers\Account\IdentityController::class;
            Route::get('/identity-api',[$identity,'index'])->name('identity');
            Route::post('/identity-api/applications',[$identity,'activate'])->name('identity.activate')->middleware('throttle:10,1');
            Route::post('/identity-api/applications/{application}',[$identity,'update'])->name('identity.application')->middleware('throttle:20,1');
            Route::post('/identity-api/inquire',[$identity,'inquire'])->name('identity.inquire')->middleware('throttle:10,1');
            Route::post('/identity-api/sandbox',[$identity,'sandbox'])->name('identity.sandbox')->middleware('throttle:20,1');
            Route::post('/identity-api/keys/{token}',[Account\SecurityController::class,'identityTokenUpdate'])->name('identity.key-update')->middleware('throttle:20,1');
            Route::get('/identity-api/requests/{id}',[$identity,'result'])->name('identity.result')->middleware('throttle:60,1');
        });
        Route::post('/security/password/start', [Account\SecurityController::class, 'passwordStart'])->name('security.pw.start')->middleware('throttle:resend');
        Route::post('/security/password', [Account\SecurityController::class, 'passwordVerify'])->name('security.pw')->middleware('throttle:otp');
        Route::post('/security/ip', [Account\SecurityController::class, 'ipStore'])->name('security.ip')->middleware('throttle:forms');
        Route::post('/security/ip/{rule}/delete', [Account\SecurityController::class, 'ipDestroy'])->name('security.ip.delete');
        Route::post('/security/ip-mode', [Account\SecurityController::class, 'ipMode'])->name('security.ipmode')->middleware('throttle:forms');
        Route::post('/security/api-token', [Account\SecurityController::class, 'tokenStore'])->name('security.token')->middleware('throttle:forms');
        Route::post('/security/api-token/{token}/delete', [Account\SecurityController::class, 'tokenDestroy'])->name('security.token.delete');

        /*
        | زیرساختِ AI — بخشِ M2: پروژه‌های AI همان بخشِ `#sec-ai` صفحهٔ
        | امنیت می‌شوند؛ فقط همین دو روتِ نوشتنی. هیچ /v1 و هیچ عمومیِ AI
        | این‌جا نیست.
        */
        Route::post('/security/ai-project', [Account\SecurityController::class, 'aiProjectStore'])->name('security.ai-project')->middleware('throttle:forms');
        Route::post('/security/ai-project/{project}/update', [Account\SecurityController::class, 'aiProjectUpdate'])->name('security.ai-project.update')->middleware('throttle:forms');

        /*
        | ورود دومرحله‌ای با اپلیکیشنِ احرازِ هویت (Google Authenticator).
        |
        | همه روی همان صفحهٔ `/account/security` می‌نشینند (بخشِ `#sec-2fa`)؛
        | صفحهٔ جدایی ندارد چون «امنیتِ حساب» یک جا باید باشد.
        |
        | ⚠️ `throttle:otp` روی سه مسیری که کد می‌سنجند: بدونش، فرمِ
        | غیرفعال‌سازی یک اوراکلِ حدسِ شش‌رقمیِ بی‌سقف است.
        */
        Route::post('/security/2fa/start', [Account\TwoFactorController::class, 'start'])->name('security.2fa.start')->middleware('throttle:forms');
        Route::post('/security/2fa/confirm', [Account\TwoFactorController::class, 'confirm'])->name('security.2fa.confirm')->middleware('throttle:otp');
        Route::post('/security/2fa/cancel', [Account\TwoFactorController::class, 'cancel'])->name('security.2fa.cancel')->middleware('throttle:forms');
        Route::post('/security/2fa/recovery', [Account\TwoFactorController::class, 'recovery'])->name('security.2fa.recovery')->middleware('throttle:otp');
        Route::post('/security/2fa/disable', [Account\TwoFactorController::class, 'disable'])->name('security.2fa.disable')->middleware('throttle:otp');

        // پنلِ نمایندگیِ دامنه — برای غیرِ نماینده هم باز است و حالتِ معرفی
        // نشان می‌دهد؛ ۴۰۴ یعنی لینکِ بازاریابی به دیوار می‌خورد.
        Route::get('/reseller', [Account\ResellerController::class, 'index'])->name('reseller');

        Route::get('/invoices', [Account\PaymentController::class, 'index'])->name('invoices');
        Route::get('/invoices/{invoice}', [Account\PaymentController::class, 'show'])->name('invoice');
        Route::get('/invoices/{invoice}/print', [Account\PaymentController::class, 'printInvoice'])->name('invoice.print');
        Route::post('/invoices/{invoice}/pay', [Account\PaymentController::class, 'pay'])
            ->name('invoice.pay')->middleware('throttle:pay');
        // پرداخت از اعتبارِ داخلی — همان مسیرِ تسویهٔ رسمی (settleConfirmed)
        Route::post('/invoices/{invoice}/pay-credit', [Account\PaymentController::class, 'payCredit'])
            ->name('invoice.paycredit')->middleware('throttle:pay');
        // کوپنِ هدیه — روی همان ریلِ تسویه می‌نشیند؛ قیمت و مالیات دست‌نخورده
        Route::post('/invoices/{invoice}/coupon', [Account\PaymentController::class, 'applyCoupon'])
            ->name('invoice.coupon')->middleware('throttle:pay');
        Route::post('/invoices/{invoice}/bank-transfer', [Account\PaymentController::class, 'bankTransfer'])
            ->name('invoice.bank')->middleware('throttle:forms');
        Route::post('/invoices/{invoice}/cancel', [Account\PaymentController::class, 'cancel'])
            ->name('invoice.cancel')->middleware('throttle:forms');

        /*
        | پرداختِ رمزارز — درگاهِ خودمان.
        |
        | ⚠️ عمداً از `GatewayRegistry` رد نمی‌شود: قرارداد `PaymentGateway`
        | یک `Payment` از قبل ساخته‌شده می‌خواهد، ولی در رمزارز تا لحظهٔ رسیدنِ
        | پول اصلاً پرداختی رخ نداده. `Payment` را همان `CryptoReconciler`
        | می‌سازد و از **همان** `settleConfirmed` تسویه می‌کند، پس مسیرِ پول
        | یکی است حتی اگر مسیرِ شروع فرق کند.
        |
        | status عمداً GET و سبک است: صفحهٔ مشتری هر چند ثانیه صدایش می‌زند.
        */
        Route::post('/invoices/{invoice}/crypto', [Account\PaymentController::class, 'cryptoIssue'])
            ->name('invoice.crypto')->middleware('throttle:pay');
        Route::get('/invoices/{invoice}/crypto/status', [Account\PaymentController::class, 'cryptoStatus'])
            ->name('invoice.crypto.status');

        Route::get('/topup', [Account\PaymentController::class, 'topupForm'])->name('topup');
        Route::post('/topup', [Account\PaymentController::class, 'topup'])
            ->name('topup.start')->middleware('throttle:pay');

        // Wallet balance refunds: disabled per customer unless an administrator enables access.
        Route::get('/wallet-refunds', [Account\WalletRefundController::class, 'index'])->name('wallet-refunds');
        Route::post('/wallet-refunds', [Account\WalletRefundController::class, 'store'])->name('wallet-refunds.store')->middleware('throttle:forms');
        Route::post('/wallet-refunds/{refund}/cancel', [Account\WalletRefundController::class, 'cancel'])->whereUuid('refund')->name('wallet-refunds.cancel')->middleware('throttle:forms');
        Route::get('/wallet-refunds/{refund}/receipt', [Account\WalletRefundController::class, 'receipt'])->whereUuid('refund')->name('wallet-refunds.receipt');

        // تیکت پشتیبانی
        Route::get('/tickets', [Account\TicketController::class, 'index'])->name('tickets');
        Route::get('/tickets/new', [Account\TicketController::class, 'create'])->name('ticket.new');
        Route::post('/tickets', [Account\TicketController::class, 'store'])->name('ticket.store')->middleware('throttle:forms');
        Route::get('/tickets/{ticket}', [Account\TicketController::class, 'show'])->name('ticket');
        Route::post('/tickets/{ticket}/reply', [Account\TicketController::class, 'reply'])->name('ticket.reply')->middleware('throttle:forms');
        Route::post('/tickets/{ticket}/close', [Account\TicketController::class, 'close'])->name('ticket.close');
        Route::get('/tickets/{ticket}/att/{attachment}', [Account\TicketController::class, 'attachment'])->name('ticket.attachment');
    });
};

Route::middleware('locale:fa')->group($site);
Route::prefix('en')->name('en.')->middleware('locale:en')->group($site);
Route::prefix('tr')->name('tr.')->middleware('locale:tr')->group($site);

/*
| بخشِ محلی ارومیه — سه‌زبانه (مرداد ۱۴۰۵، خواستِ مدیر: «هر صفحه‌ای en/tr
| نداشت درستش کن»). closureِ جدا از $site مانده تا ترتیبِ ثبت و whereها
| دست‌نخورده بمانند، ولی مثل $site سه بار ثبت می‌شود.
|
| 🔵 نسخهٔ en/tr دیگر فارسی نمی‌گیرد: UrmiaController ترجمه‌های واقعی را از
|    config/urmia_i18n.php در لحظهٔ رندر overlay می‌کند. تست: UrmiaPagesTest.
|    جانشینِ سئوی محلیِ servernet.ir در مهاجرت است — نقشهٔ ۳۰۱ آن دامنه به
|    همین آدرس‌ها اشاره می‌کند، پس تغییرِ اسلاگ‌ها یعنی شکستنِ ریدایرکت‌ها.
| ⚠️ «cities» پیش از «{slug}» بیاید وگرنه خودش یک slug خوانده می‌شود.
*/
$urmia = function () {
    Route::get('/urmia', [\App\Http\Controllers\UrmiaController::class, 'hub'])->name('urmia.hub');
    Route::get('/urmia/cities/{slug}', [\App\Http\Controllers\UrmiaController::class, 'city'])
        ->name('urmia.city')->where('slug', '[a-z0-9-]+');
    Route::get('/urmia/{slug}', [\App\Http\Controllers\UrmiaController::class, 'page'])
        ->name('urmia.page')->where('slug', '[a-z0-9-]+');
};
Route::middleware('locale:fa')->group($urmia);

/*
| 🔴 ممیزی نهم (قلم ۲ رودمپ): نسخهٔ en/tr ارومیه **برداشته شد**.
|
| مرداد ۱۴۰۵ به خواستِ صریحِ مدیر سه‌زبانه شده بود («هر صفحه‌ای en/tr نداشت
| درستش کن»). ممیزی نهم آن تصمیم را برگرداند، با سه دلیلِ اندازه‌گیری‌شده:
|
|   • «طراحی سایت در خوی» به انگلیسی/ترکی تقاضای جست‌وجوی ~صفر دارد
|   • خدمت ذاتاً محلی و فارسی‌زبان است
|   • ۳۷۲–۶۵۹ کلمه، **نازک‌تر از نسخهٔ فارسی** (۳۵۰–۱۰۳۸) — سطحِ محتوای
|     نازکِ کلِ سایت را سه برابر کرد (۲۹ → ۸۷ صفحه)
|
| ⚠️ چرا ۴۱۰ و نه noindex: `noindex` صفحه را در خزش، لینکِ داخلی و خوشهٔ
| hreflang نگه می‌دارد و برای همیشه نگهداری می‌خواهد. این صفحات دو روزه‌اند،
| بک‌لینک ندارند و احتمالاً هنوز ایندکس نشده‌اند — ۴۱۰ هزینه‌ای ندارد.
|
| 🔴 و حذفِ alternateها **باید در همین دیپلوی** باشد: ۴۱۰ با hreflangِ زندهٔ
| اشاره‌کننده به آن، یک حلقهٔ خطای خزش می‌سازد. ویوهای ارومیه `@section('faOnly')`
| دارند — همان مکانیسمی که برای همین ساخته شده بود و از مرداد بلااستفاده مانده.
|
| ⚠️ نسخهٔ **فارسی** دست‌نخورده می‌مانَد: نقشهٔ ۳۰۱ مهاجرتِ servernet.ir به
| همان آدرس‌ها اشاره می‌کند.
*/
/*
| 🔴 این روت‌ها عمداً **نام ندارند** — و نبودِ نام خودش یک قابلیت است.
|
| سوییچرِ زبان در `AppServiceProvider` لینکِ هر زبان را با
| `route($prefix.$baseRoute, $params)` می‌سازد و روی استثنا به خانهٔ همان
| زبان برمی‌گردد. اگر این روت‌ها نام داشتند، `route('en.urmia.hub')` با
| موفقیت `/en/urmia` را می‌ساخت و بازدیدکنندهٔ صفحهٔ فارسی با کلیک روی
| «English» به یک ۴۱۰ می‌رسید — بدتر از قبل از این تغییر.
|
| بی‌نام، همان `catch` که برای ۴۰۴ها نوشته شده بود این را هم می‌گیرد و
| کاربر به خانهٔ انگلیسی می‌رود. یعنی صفحه برای گوگل «رفته» است و برای
| آدم «این‌جا نیست، بفرما خانه».
|
| ⚠️ پس هیچ‌جای دیگری هم نباید `lroute('urmia.*')` در زبانِ en/tr صدا زده
| شود، وگرنه همان استثنا این‌بار وسطِ رندر می‌افتد. تنها فراخوانِ سراسری،
| لینکِ فوترِ ارومیه بود که حالا فقط در فارسی رندر می‌شود.
*/
$urmiaGone = function () {
    Route::get('/urmia', fn () => response('', 410));
    Route::get('/urmia/cities/{slug}', fn () => response('', 410))->where('slug', '[a-z0-9-]+');
    Route::get('/urmia/{slug}', fn () => response('', 410))->where('slug', '[a-z0-9-]+');
};
Route::prefix('en')->name('en.')->middleware('locale:en')->group($urmiaGone);
Route::prefix('tr')->name('tr.')->middleware('locale:tr')->group($urmiaGone);

/*
| ریدایرکتِ آدرس‌های میراثیِ وردپرسیِ خودِ .cloud — گزارشِ 404 در GSC
| (شهریور ۱۴۰۵): این‌ها لینکِ ورودی/تاریخچهٔ خزش دارند و مقصدِ روشن. ۳۰۱
| اعتبارشان را نگه می‌دارد؛ بقیهٔ 404ها (wp-*, cgi-bin, …) عمداً 404 می‌مانند.
| بدونِ پیشوندِ زبان: این آدرس‌ها فقط در نسخهٔ قدیمیِ فارسی وجود داشتند.
*/
foreach ([
    '/privacy-policy'     => '/privacy',
    '/guide/privacy.php'  => '/privacy',
    '/services'           => '/solutions',
    '/marketing'          => '/solutions',
    '/home'               => '/',
    '/cart'               => '/',
    '/servernet'          => '/',
] as $legacyFrom => $legacyTo) {
    Route::redirect($legacyFrom, $legacyTo, 301);
}

/*
| پیش‌نمایشِ منتشرشدهٔ سایت‌ساز — عمداً بیرونِ closureِ $site (یک لینک، نه سه).
| ۴۸ ساعت زنده است (سنجه: mtime فایل)، noindex، و با CSP sandbox در originِ
| یکتا سرو می‌شود تا خروجیِ کاربرساخته به کوکی/نشستِ دامنهٔ ما نرسد.
*/
Route::get('/sb/{ref}', [AiBuilderController::class, 'shared'])
    ->name('builder.shared')->where('ref', 'SB-[A-Za-z0-9]+')
    ->middleware('throttle:tools');

/*
| پیشوندِ زبان با حروفِ بزرگ → هدایتِ ۳۰۱ به نسخهٔ کوچک.
|
| در ردیابِ ۴۰۴ دیدیم لینکِ بیوی اینستاگرام «/TR» است و ۴۰۴ می‌گرفت — یعنی
| ترافیکِ واقعیِ تبلیغات دور می‌ریخت. پیشوندها فقط با حروفِ کوچک ثبت شده‌اند.
|
| بعد از گروه‌های $site ثبت می‌شود تا مسیرهای واقعی را سایه نیندازد، و فقط
| زبان‌های شناخته‌شده را قبول می‌کند (وگرنه هر مسیرِ دوحرفی را می‌قاپید).
*/
Route::get('/{loc}/{rest?}', function (string $loc, ?string $rest = null) {
    $lower = strtolower($loc);

    abort_if($lower === $loc || ! array_key_exists($lower, \App\Providers\AppServiceProvider::LOCALES), 404);

    /*
    | 🔴 اسلشِ دوتایی = ریدایرکتِ باز.
    |
    | نسخهٔ قبلی برای `fa` مقدارِ `$target` را `'/'` می‌گذاشت و بعد
    | `$target.'/'.$rest` می‌ساخت ⇒ `//vps/austria`. لاراول هر رشتهٔ
    | شروع‌شده با `//` را **آدرسِ کامل** می‌شمارد و بی‌تغییر در هدرِ
    | `Location` می‌گذارد؛ مرورگر هم `//x/y` را `https://x/y` می‌خواند.
    |
    | دو خرابیِ هم‌زمان:
    |   • `/FA/vps/austria` ⇒ مرورگر به میزبانِ ناموجودِ `https://vps` می‌رود
    |     و کاربر خطای DNS می‌بیند — حتی صفحهٔ ۴۰۴ خودمان را هم نه.
    |   • `/FA/evil.example/x` ⇒ ۳۰۱ به دامنهٔ مهاجم. یعنی **اعتبارِ دامنهٔ
    |     شرکت خرجِ فیشینگ می‌شود**، با آدرسی که ظاهرش servernet.cloud است.
    |
    | ⚠️ `ltrim` روی رشتهٔ ترکیب‌شده انجام می‌شود نه روی اجزا، چون خودِ `$rest`
    |    هم می‌تواند با اسلش شروع شود.
    */
    $path = '/'.ltrim(($lower === 'fa' ? '' : $lower).'/'.($rest ?? ''), '/');

    return redirect($path === '/' ? '/' : rtrim($path, '/'), 301);
})->where('loc', '[A-Za-z]{2}')->where('rest', '.*');

/*
| بازگشت از درگاه پرداخت.
|
| عمداً بیرون از گروه‌های زبانی و بیرون از auth:customer:
|   • آدرس بازگشت موقع ساخت تراکنش به درگاه داده می‌شود و باید ثابت بماند؛
|     اگر با پیشوند زبان ساخته شود، کاربری که زبانش را عوض کند سر از ۴۰۴
|     درمی‌آورد و پرداختش معلق می‌ماند.
|   • کاربر ممکن است در مرورگر دیگری یا با نشستِ منقضی برگردد. پرداخت فقط
|     از روی Authority پیدا می‌شود که ستونی یکتاست.
*/
// [snapppay-callback:start]
// ⚠️ اسنپ‌پی نتیجه را با **POST** برمی‌گرداند، نه GET (مستندات: «نتیجهٔ تراکنش
// به صورت POST یک فرم … به آن آدرس ارسال گردد»). همان کنترلر و همان مسیر، فقط
// فعلِ دیگر — و بدونِ CSRF (در bootstrap/app.php مستثنا شده)، چون فرستنده یک
// سرور است نه مرورگرِ دارای نشست.
// 🔴 دامنهٔ این آدرس باید همانی باشد که نزدِ اسنپ‌پی whitelist شده.
// (این بلوک نشان‌دار است و با apply-marked-block منتشر می‌شود؛ برای همین
// **پیش از** روتِ GET نشسته: لنگرش باید دقیقاً یک خط باشد.)
// 🔴 بدونِ نشست: این POST از دامنهٔ اسنپ‌پی می‌آید و کوکیِ SameSite=Lax ِ ورود
// همراهش نیست. با نشست، لاراول یک نشستِ خالیِ تازه می‌ساخت و کوکی‌اش جای
// کوکیِ ورود می‌نشست ⇒ مشتری بعد از پرداخت از حسابش بیرون می‌افتاد.
// این‌جا فقط ۳۰۳ به همان آدرس با GET؛ verify/settle آن‌جا، با نشستِ خودِ مشتری.
Route::post('/payment/callback/{gateway}', [\App\Http\Controllers\Account\PaymentController::class, 'callbackPost'])
    ->name('payment.callback.post')
    ->where('gateway', '[a-z]+')
    ->middleware('throttle:pay')
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        // لاراول ۱۳ نامِ میان‌افزارِ CSRF را عوض کرده؛ هر دو، تا با هر نسخه بی‌نشست بماند
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \App\Http\Middleware\PageCache::class,
    ]);
// [snapppay-callback:end]
Route::get('/payment/callback/{gateway}', [\App\Http\Controllers\Account\PaymentController::class, 'callback'])
    ->name('payment.callback')
    ->where('gateway', '[a-z]+')
    ->middleware('throttle:pay');

/*
| پل پیامک — سرور ایران این دو را صدا می‌زند.
|
| بیرون از گروه‌های زبانی و بدون احراز هویت نشستی: تماس‌گیرنده یک سرور است
| نه مرورگر. محافظت با امضای HMAC داخل کنترلر انجام می‌شود.
|
| throttle سخاوتمند است چون فرستنده هر ۳ ثانیه سر می‌زند — و اگر ۴۲۹ بگیرد،
| پیامک کاربر معطل می‌ماند.
*/
Route::middleware('throttle:60,1')->prefix('api/sms')->group(function () {
    Route::post('/pull', [\App\Http\Controllers\SmsBridgeController::class, 'pull']);
    Route::post('/report', [\App\Http\Controllers\SmsBridgeController::class, 'report']);
});

/*
| وب‌هوک بله — این‌جا chat_id کاربران به دست می‌آید.
|
| بیرون از گروه‌های زبانی و بدون احراز هویت (بله یک سرور است). محافظت با
| توکن در مسیر که هش توکن ربات است — فقط بله آن را دارد. CSRF هم معاف است.
*/
/*
| راه‌اندازی وب‌هوک بله — یک بار اجرا می‌شود تا بله بداند updateها را کجا
| بفرستد. آدرس وب‌هوک شامل هش توکن ربات است، پس قابل حدس نیست.
*/
Route::get('/system/bale-setup', fn () => response(
    '<!doctype html><meta charset=utf-8><title>ثبت وب‌هوک بله</title>'
    .'<body style="font:15px/1.8 system-ui;max-width:560px;margin:60px auto;padding:0 20px;direction:rtl">'
    .'<h2>ثبت وب‌هوک بله روی سرورنت</h2>'
    .'<p>وب‌هوک ربات بله را به اپِ سرورنت وصل می‌کند تا «اتصال حساب» و «پرداخت» کار کند '
    .'(وب‌هوک فعلی به n8n وصل است و باید بازنویسی شود). توکن <code>DEPLOY_TOKEN</code> را وارد کنید.</p>'
    .'<form method=post><input name=token style="width:100%;padding:10px;font-size:15px" '
    .'placeholder="DEPLOY_TOKEN" autocomplete=off>'
    .'<button style="margin-top:12px;padding:10px 22px;font-size:15px;cursor:pointer">ثبت وب‌هوک</button></form>'
    .'<pre id=out style="background:#111;color:#0f0;padding:14px;border-radius:8px;white-space:pre-wrap;margin-top:20px"></pre>'
    .'<script>document.querySelector("form").addEventListener("submit",async e=>{e.preventDefault();'
    .'var o=document.getElementById("out");o.textContent="در حال ثبت…";'
    .'var r=await fetch("",{method:"POST",headers:{"Content-Type":"application/json"},'
    .'body:JSON.stringify({token:e.target.token.value})});'
    .'o.textContent=JSON.stringify(await r.json(),null,2)});</script>'
))->name('system.bale_setup');

Route::post('/system/bale-setup', function (\Illuminate\Http\Request $r) {
    $expected = (string) env('DEPLOY_TOKEN', '');
    abort_if($expected === '' || ! hash_equals($expected, (string) $r->input('token')), 404);

    $bot = (string) config('services.bale.token');
    if ($bot === '') {
        return response()->json(['ok' => false, 'reason' => 'BALE_BOT_TOKEN در .env نیست'], 422, [], JSON_UNESCAPED_UNICODE);
    }

    $hook = url('/bale/webhook/'.substr(hash('sha256', $bot), 0, 32));
    $base = rtrim((string) config('services.bale.base', 'https://tapi.bale.ai'), '/');

    $set = \Illuminate\Support\Facades\Http::timeout(15)->asJson()
        ->post($base.'/bot'.$bot.'/setWebhook', ['url' => $hook]);
    $me  = \Illuminate\Support\Facades\Http::timeout(15)->get($base.'/bot'.$bot.'/getMe');

    return response()->json([
        'webhook_url' => $hook,
        'set_result'  => $set->json(),
        'bot'         => $me->json('result'),
    ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
})->middleware('throttle:6,1');

Route::post('/bale/webhook/{token}', \App\Http\Controllers\BaleWebhookController::class)
    ->middleware('throttle:60,1')->where('token', '[a-f0-9]{32}');

/*
| وبهوکِ تلفن ابری «دفتر شما» — رویدادهای لحظه‌ایِ تماس.
|
| ⚠️ سقفِ نرخ سخاوتمندانه است و عمداً. یک تماسِ واحد تا **۵ رویداد** می‌دهد
| (شروع، دو رویدادِ انتقال، و یک `Ended` به ازای هر پا)، و در ساعتِ شلوغ چند
| تماس هم‌زمان‌اند. وبهوکی که ۴۲۹ بگیرد از سمتِ فرستنده retry و بعد **غیرفعال**
| می‌شود — یعنی سقفِ تنگ، خودش ابزارِ خاموشیِ ماست.
|
| ⚠️ الگوی توکن `[A-Za-z0-9_-]{16,80}` است نه هگزِ ۳۲تایی: مسیرِ وبهوک را
| خودمان می‌سازیم و ممکن است base64url باشد. اگر روزی توکن با الگو نخواند،
| لاراول ۴۰۴ می‌دهد **پیش از** کنترلر — یعنی نه لاگی، نه ردی، و از بیرون شبیه
| «دفتر شما وبهوک نمی‌فرستد».
*/
Route::post('/cloud-phone/webhook/{token}', \App\Http\Controllers\CloudPhoneWebhookController::class)
    ->middleware('throttle:600,1')->where('token', '[A-Za-z0-9_-]{16,80}');

Route::get('/sitemap.xml', [SiteController::class, 'sitemap']);
Route::get('/hardware-sitemap.xml', [ServerShopController::class, 'sitemap']);
// Legacy WordPress/SEO-plugin sitemap entry po…25492 tokens truncated… |==========================================================================
    |
    | تا امروز `admin` را باید روی تک‌تکِ روت‌ها می‌نوشتیم و سه بار جا افتاد —
    | با نتیجه‌ای که یک حسابِ نقشِ `author` (بلاگ‌نویس، کم‌ارزش‌ترین حساب) به
    | این‌ها می‌رسید:
    |
    |   • `/admin/verifications/{profile}/doc/{document}` — دانلودِ اسکنِ کارتِ
    |     ملی و اساسنامهٔ **هر** مشتری
    |   • `/admin/customers/{customer}/password` — ست‌کردنِ رمزِ هر مشتری، یعنی
    |     ورود به پنلش: رمزِ root سرورهای ابری، اطلاعاتِ cPanel، توکنِ API
    |   • `/admin/users` — ساختنِ کاربرِ تازه
    |   • `/admin/broadcasts` — پیامکِ انبوه به همهٔ مشتریان
    |   • `/admin/errors` — و ردیاب، آدرسِ کاملِ درخواست را ذخیره می‌کند، پس
    |     توکن‌های در-URL هم از همان‌جا خوانده می‌شوند
    |
    | ⚠️ چرا فهرستِ سفید و نه افزودنِ `admin` به روت‌های جامانده: همان کاری بود
    | که تا حالا می‌کردیم و سه بار شکست خورد. با این ساختار، روتِ **تازه‌ای** که
    | فردا اضافه شود خودبه‌خود محافظت‌شده است؛ باز گذاشتنش یک تصمیمِ صریح و
    | دیدنی می‌شود، نه یک فراموشی.
    |
    | ⚠️ `/users` عمداً بیرونِ فهرستِ سفید است: ساختِ کاربر یعنی ساختِ همکار.
    */
    Route::middleware(['auth:web', 'admin'])->group(function () {

        // ── تنها چیزهایی که نویسندهٔ محتوا هم به آن‌ها دسترسی دارد ──
        Route::withoutMiddleware('admin')->group(function () {
            Route::get('/', [AdminDash::class, 'index']);
            Route::get('/posts', [AdminPost::class, 'index']);
            Route::get('/posts/new', [AdminPost::class, 'edit']);
            Route::get('/posts/{post}/edit', [AdminPost::class, 'edit']);
            Route::post('/posts', [AdminPost::class, 'save']);
            Route::post('/posts/{post}', [AdminPost::class, 'save']);
            Route::post('/posts/{post}/delete', [AdminPost::class, 'destroy']);
            Route::post('/ai/translate', [AdminPost::class, 'translate']);
            Route::post('/ai/seo', [AdminPost::class, 'seo']);
            Route::get('/comments', [AdminComment::class, 'index']);
            Route::post('/comments/{comment}/approve', [AdminComment::class, 'approve']);
            Route::post('/comments/{comment}/delete', [AdminComment::class, 'destroy']);
            Route::post('/comments/{comment}/drop-reply', [AdminComment::class, 'dropReply']);

            /*
            | امنیتِ حسابِ **خودِ کاربر** — دومرحله‌ای با اپلیکیشن.
            |
            | ⚠️ عمداً در فهرستِ سفیدِ غیرِمدیر است. این صفحه به هیچ دادهٔ
            | مدیریتی دست نمی‌زند و فقط حسابِ همان کاربر را سفت می‌کند؛ بستنش
            | روی نویسنده و پشتیبان یعنی ضعیف‌ترین حساب‌های پنل تنها کسانی
            | باشند که نمی‌توانند از خودشان محافظت کنند.
            */
            Route::get('/security', [AdminSecurity::class, 'index'])->name('admin.security');
            Route::post('/security/2fa/start', [AdminSecurity::class, 'start'])->middleware('throttle:forms');
            Route::post('/security/2fa/confirm', [AdminSecurity::class, 'confirm'])->middleware('throttle:otp');
            Route::post('/security/2fa/cancel', [AdminSecurity::class, 'cancel'])->middleware('throttle:forms');
            Route::post('/security/2fa/recovery', [AdminSecurity::class, 'recovery'])->middleware('throttle:otp');
            Route::post('/security/2fa/disable', [AdminSecurity::class, 'disable'])->middleware('throttle:otp');
        });

        Route::get('/users', [AdminUser::class, 'index']);
        Route::post('/users', [AdminUser::class, 'store']);
        Route::post('/users/{user}/delete', [AdminUser::class, 'destroy']);
        // داخلیِ تلفن ابری هر کارمند — بدونِ آن دکمهٔ تماسِ او غیرفعال است
        Route::post('/users/{user}/extension', [AdminUser::class, 'extension']);
        Route::post('/users/{user}/names', [AdminUser::class, 'names']);

        /*
        | ═══ زیرساختِ AI — دروازه (M1) ═══
        |
        | فقط رجیستری و قیمت: ارائه‌دهنده‌ها · مدل‌ها · قیمت. تمام روت‌های
        | نوشتنی پشتِ `middleware('admin')` صریح‌اند — پشتیبان/نویسنده فقط
        | باید بتواند *ببیند* که چه مدل و چه قیمتی وجود دارد، نه اینکه
        | دروازهٔ آیندهٔ مالی را بگرداند.
        */
        Route::get('/ai', [\App\Http\Controllers\Admin\AiGatewayController::class, 'providers'])
            ->name('admin.ai.providers');
        Route::get('/ai/providers/edit', [\App\Http\Controllers\Admin\AiGatewayController::class, 'editProvider'])
            ->name('admin.ai.providers.edit');
        Route::post('/ai/providers/{provider}', [\App\Http\Controllers\Admin\AiGatewayController::class, 'updateProvider'])
            ->name('admin.ai.providers.update')->middleware('admin');
        Route::get('/ai/models', [\App\Http\Controllers\Admin\AiGatewayController::class, 'models'])
            ->name('admin.ai.models');
        Route::get('/ai/models/{model}/edit', [\App\Http\Controllers\Admin\AiGatewayController::class, 'editModel'])
            ->name('admin.ai.models.edit');
        Route::post('/ai/models/{model}', [\App\Http\Controllers\Admin\AiGatewayController::class, 'updateModel'])
            ->name('admin.ai.models.update')->middleware('admin');
        Route::post('/ai/models/{model}/status', [\App\Http\Controllers\Admin\AiGatewayController::class, 'toggleModel'])
            ->name('admin.ai.models.status')->middleware('admin');
        // [ai-admin-routes-create:start] ─ بلوکِ نشان‌دار؛ روی سرور با scripts/apply-marked-block.php
        // درج می‌شود نه با ادغامِ کلِ routes (سرور زیرمجموعهٔ develop است). ساختِ مدل فقط
        // برای مدیر؛ مدلِ تازه خاموش ساخته می‌شود چون مسیرِ قدیمیِ /v1 تا M5.1b سدهای فروش
        // را نمی‌خوانَد (نگاه کنید به AiGatewayController::createModel).
        Route::get('/ai/models/create', [\App\Http\Controllers\Admin\AiGatewayController::class, 'createModel'])
            ->name('admin.ai.models.create')->middleware('admin');
        Route::post('/ai/models', [\App\Http\Controllers\Admin\AiGatewayController::class, 'storeModel'])
            ->name('admin.ai.models.store')->middleware('admin');
        // [ai-admin-routes-create:end]
        Route::get('/ai/pricing', [\App\Http\Controllers\Admin\AiGatewayController::class, 'pricing'])
            ->name('admin.ai.pricing');
        Route::post('/ai/pricing/supersede', [\App\Http\Controllers\Admin\AiGatewayController::class, 'supersedePrice'])
            ->name('admin.ai.pricing.supersede')->middleware('admin');

        // ردیاب خطای سرور و ۴۰۴
        Route::get('/errors', [\App\Http\Controllers\Admin\ErrorLogController::class, 'index'])->name('admin.errors');
        Route::post('/errors/clear', [\App\Http\Controllers\Admin\ErrorLogController::class, 'clear']);

        Route::prefix('wallet-refunds')->middleware('admin')->group(function () {
            $c = \App\Http\Controllers\Admin\WalletRefundController::class;
            Route::get('/', [$c, 'index'])->name('admin.wallet-refunds');
            Route::get('/export', [$c, 'export'])->name('admin.wallet-refunds.export');
            Route::get('/{refund}', [$c, 'show'])->whereUuid('refund')->name('admin.wallet-refunds.show');
            Route::post('/{refund}/approve', [$c, 'approve'])->whereUuid('refund')->name('admin.wallet-refunds.approve')->middleware('throttle:forms');
            Route::post('/{refund}/reject', [$c, 'reject'])->whereUuid('refund')->name('admin.wallet-refunds.reject')->middleware('throttle:forms');
            Route::post('/{refund}/paid', [$c, 'paid'])->whereUuid('refund')->name('admin.wallet-refunds.paid')->middleware('throttle:forms');
        });
        Route::post('/customers/{customer}/wallet-refund-permission', [\App\Http\Controllers\Admin\WalletRefundController::class, 'permission'])
            ->whereNumber('customer')->name('admin.wallet-refunds.permission')->middleware(['admin', 'throttle:forms']);

        // داشبورد مالی کسب‌وکار — سرمایه، سود، مالیات
        Route::prefix('finance/bank-reconciliation')->middleware('admin')->group(function () {
            $c = \App\Http\Controllers\Admin\BankReconciliationController::class;
            Route::get('/', [$c, 'index'])->name('admin.bank-reconciliation');
            Route::get('/imports/{import}', [$c, 'download'])->whereNumber('import')->name('admin.bank-reconciliation.download');
            Route::post('/import', [$c, 'import'])->name('admin.bank-reconciliation.import');
            Route::post('/post-collection', [$c, 'postCollection'])->name('admin.bank-reconciliation.post-collection');
            Route::post('/match', [$c, 'match'])->name('admin.bank-reconciliation.match');
            Route::post('/matches/{match}/void', [$c, 'void'])->whereNumber('match')->name('admin.bank-reconciliation.void');
        });
        Route::prefix('finance/accounting')->middleware('admin')->group(function () {
            $c = \App\Http\Controllers\Admin\AccountingController::class;
            Route::get('/', [$c, 'index'])->name('admin.accounting');
            Route::get('/export', [$c, 'export'])->name('admin.accounting.export');
            Route::get('/evidence-bundle', [$c, 'bundle'])->name('admin.accounting.bundle');
            Route::post('/accounts', [$c, 'account'])->name('admin.accounting.accounts');
            Route::post('/vouchers', [$c, 'store'])->name('admin.accounting.store');
            Route::get('/vouchers/{voucher}', [$c, 'show'])->whereNumber('voucher')->name('admin.accounting.voucher');
            Route::post('/vouchers/{voucher}/reverse', [$c, 'reverse'])->whereNumber('voucher')->name('admin.accounting.reverse');
            Route::post('/vouchers/{voucher}/tax-review', [$c, 'reviewTax'])->whereNumber('voucher')->name('admin.accounting.tax-review');
            Route::post('/vouchers/{voucher}/evidence', [$c, 'evidence'])->whereNumber('voucher')->name('admin.accounting.evidence');
            Route::get('/evidence/{document}', [$c, 'download'])->whereNumber('document')->name('admin.accounting.download');
            Route::post('/assets/{asset}/depreciation', [$c, 'depreciate'])->whereNumber('asset')->name('admin.accounting.depreciate');
            Route::post('/tax-profile', [$c, 'taxProfile'])->name('admin.accounting.tax-profile');
            Route::post('/close-period', [$c, 'closePeriod'])->name('admin.accounting.close-period');
            Route::post('/investment', [$c, 'investment'])->name('admin.accounting.investment');
        });
        Route::get('/finance/export', [\App\Http\Controllers\Admin\FinanceController::class, 'export'])->name('admin.finance.export')->middleware('admin');
        Route::get('/finance', [\App\Http\Controllers\Admin\FinanceController::class, 'index'])->name('admin.finance')->middleware('admin');
        Route::post('/finance', [\App\Http\Controllers\Admin\FinanceController::class, 'store'])->middleware('admin');
        Route::post('/finance/{entry}/delete', [\App\Http\Controllers\Admin\FinanceController::class, 'destroy'])->middleware('admin');

        // گزارشِ کسب‌وکار — پولِ در راه، رشدِ مشتری، ظرفیتِ زیرساخت
        // ⚠️ فقط می‌خوانَد؛ هیچ روتِ نوشتنی ندارد و عمداً هم نباید داشته باشد.
        Route::get('/reports', [\App\Http\Controllers\Admin\ReportController::class, 'index'])->name('admin.reports')->middleware('admin');

        // تراکنش‌ها و اعتبار — پرداخت‌های ریز + دفتر اعتبار + بدهیِ اعتبارِ مشتریان
        Route::get('/transactions/export', [\App\Http\Controllers\Admin\TransactionController::class, 'export'])->name('admin.transactions.export')->middleware('admin');
        Route::get('/transactions', [\App\Http\Controllers\Admin\TransactionController::class, 'index'])->name('admin.transactions')->middleware('admin');

        // [snapppay-admin-routes:start]
        /*
        | ═══ سفارش‌های اسنپ‌پی ═══
        |
        | 🔴 خواستهٔ صریحِ اسنپ‌پی و بخشی از بازبینیِ فنی‌شان: شمارهٔ تراکنش
        | باید در پنلِ ادمین **نمایش داده و جست‌وجو** شود، و ادمین بتواند از
        | همان‌جا سفارش را تغییر دهد.
        |
        | ⚠️ `update` و `cancel` برگشت‌ناپذیرند، پس فقط POST — و هر دو در
        | کنترلر یک تأییدیهٔ متنیِ صریح هم می‌خواهند، نه صرفاً یک دکمه.
        */
        Route::middleware('admin')->group(function () {
            Route::get('/snapppay', [\App\Http\Controllers\Admin\SnappPayController::class, 'index'])->name('admin.snapppay');
            Route::get('/snapppay/{order}', [\App\Http\Controllers\Admin\SnappPayController::class, 'show'])->name('admin.snapppay.show');
            Route::post('/snapppay/{order}/status', [\App\Http\Controllers\Admin\SnappPayController::class, 'status']);
            Route::post('/snapppay/{order}/update', [\App\Http\Controllers\Admin\SnappPayController::class, 'update']);
            Route::post('/snapppay/{order}/cancel', [\App\Http\Controllers\Admin\SnappPayController::class, 'cancel']);
        });
        // [snapppay-admin-routes:end]

        /*
        | ═══ تیکت پشتیبانی — مدیر **یا** پشتیبان ═══
        |
        | 🔴 `withoutMiddleware('admin')` لازم است، نه اضافه: کلِ این گروه پشتِ
        | `['auth:web','admin']` است، پس افزودنِ `staff` به‌تنهایی هیچ دری باز
        | نمی‌کرد — `EnsureAdmin` زودتر ۴۰۳ می‌داد و پشتیبان همچنان بیرون
        | می‌مانْد. گاردِ نقش این‌جا **جایگزین** می‌شود، نه افزوده.
        |
        | ⚠️ ترتیب مهم است: اول `admin` برداشته می‌شود، بعد `staff` می‌نشیند —
        | یعنی این مسیرها هرگز بی‌گارد نمی‌مانند.
        */
        Route::withoutMiddleware('admin')->middleware('staff')->group(function () {
            Route::get('/tickets', [\App\Http\Controllers\Admin\TicketController::class, 'index'])
                ->name('admin.tickets');
        });
        /*
        | ⚠️ `bulk` پیش از `{ticket}` ثبت می‌شود — درسِ روتِ compare در
        | فروشگاهِ قطعات: مسیرِ ثابتی که بعدِ پارامتری بیاید بلعیده می‌شود و
        | دکمه بی‌صدا از کار می‌افتد.
        */
        Route::withoutMiddleware('admin')->middleware('staff')->group(function () {
            Route::post('/tickets/create', [\App\Http\Controllers\Admin\TicketController::class, 'store']);
            Route::post('/tickets/bulk', [\App\Http\Controllers\Admin\TicketController::class, 'bulk']);
            Route::get('/tickets/{ticket}', [\App\Http\Controllers\Admin\TicketController::class, 'show'])->name('admin.ticket');
            Route::post('/tickets/{ticket}/reply', [\App\Http\Controllers\Admin\TicketController::class, 'reply']);
            // تصحیحِ نگارش با AI — فقط برمی‌گرداند، هیچ‌چیز نمی‌فرستد
            Route::post('/tickets/{ticket}/polish', [\App\Http\Controllers\Admin\TicketController::class, 'polish']);
            // پیشنهادِ پاسخ با AI — همان موتورِ بله؛ فقط برمی‌گرداند، هیچ‌چیز نمی‌فرستد
            Route::post('/tickets/{ticket}/draft', [\App\Http\Controllers\Admin\TicketController::class, 'draft']);
            Route::post('/tickets/{ticket}/update', [\App\Http\Controllers\Admin\TicketController::class, 'update']);
            Route::get('/tickets/{ticket}/attachments/{attachment}', [\App\Http\Controllers\Admin\TicketController::class, 'attachment']);
        });

        /*
        | کنسولِ مدیر در بله — روشن/خاموش، اتصال، قطع.
        |
        | 🔴 `admin` علاوه بر `auth:web`: این صفحه تنها راهِ **دادنِ** دسترسیِ
        | مدیر به یک چتِ بله است. کارمندی که مدیر نیست نباید بتواند کدِ اتصال
        | بسازد، حتی اگر به تیکت‌ها دسترسی دارد.
        |
        | ⚠️ throttle روی `pair` چون هر بار یک ایمیل می‌فرستد.
        */
        Route::get('/bale', [\App\Http\Controllers\Admin\BaleAdminController::class, 'index'])
            ->name('admin.bale')->middleware('admin');
        Route::get('/bale/reporting', [\App\Http\Controllers\Admin\ManagerReportingController::class, 'index'])->name('admin.bale.reporting')->middleware('admin');
        Route::post('/bale/reporting/configure', [\App\Http\Controllers\Admin\ManagerReportingController::class, 'configure'])->middleware(['admin', 'throttle:forms']);
        Route::get('/bale/reporting/connect', [\App\Http\Controllers\Admin\ManagerReportingController::class, 'connect'])->middleware('admin');
        Route::post('/bale/reporting/disconnect', [\App\Http\Controllers\Admin\ManagerReportingController::class, 'disconnect'])->middleware(['admin', 'throttle:forms']);
        Route::post('/bale/review-sms', [\App\Http\Controllers\Admin\BaleAdminController::class, 'reviewSms'])->middleware(['admin', 'throttle:forms'])->name('admin.bale.review-sms');
        Route::post('/bale/pair', [\App\Http\Controllers\Admin\BaleAdminController::class, 'pair'])
            ->middleware(['admin', 'throttle:6,1']);
        Route::post('/bale/revoke', [\App\Http\Controllers\Admin\BaleAdminController::class, 'revoke'])
            ->middleware('admin');
        Route::post('/bale/toggle', [\App\Http\Controllers\Admin\BaleAdminController::class, 'toggle'])
            ->middleware('admin');

        /*
        | تلفن ابری.
        |
        | ⚠️ `/calls` عمداً برای نقشِ نویسنده هم باز است (مثلِ بقیهٔ این گروه)
        | چون پشتیبانی باید تماس‌ها را ببیند. ولی **برقراریِ تماس** پول خرج
        | می‌کند و از خطِ شرکت می‌رود، پس `admin` می‌خواهد.
        */
        Route::get('/calls', [\App\Http\Controllers\Admin\PhoneCallController::class, 'index'])->name('admin.calls');
        Route::post('/customers/{customer}/call', [\App\Http\Controllers\Admin\PhoneCallController::class, 'call'])
            ->middleware('admin')->name('admin.customer.call');

        /*
        | شماره‌گیریِ دلخواه — «مشتریم نبود هم بتوانم تماس بگیرم».
        |
        | ⚠️ `throttle` این‌جا تزئینی نیست: برخلافِ تماس با مشتری، مقصد از فرم
        | می‌آید. اگر روزی نشستِ مدیر لو برود، بی‌این سقف می‌شد با یک حلقه صدها
        | تماس از خطِ شرکت گرفت. ۱۰ تماس در دقیقه از هر سرعتِ انسانی بیشتر است.
        */
        Route::post('/calls/dial', [\App\Http\Controllers\Admin\PhoneCallController::class, 'dial'])
            ->middleware(['admin', 'throttle:10,1'])->name('admin.calls.dial');

        /*
        | ═══ مدیریت مشتریان — بخشِ شبیه‌WHMCS ═══
        |
        | 🔴 **دیدن** برای پشتیبان باز است، **تغییر** نه. پشتیبان باید بتواند
        | پروندهٔ مشتری را بخواند تا تیکتش را جواب دهد؛ ولی عوض‌کردنِ رمز،
        | تعلیقِ حساب، نمایندگی و حذف تصمیمِ مدیر است و پشتِ `admin` (گاردِ
        | خودِ گروه) می‌مانَد — بی‌آنکه خطی این‌جا لازم باشد.
        */
        Route::withoutMiddleware('admin')->middleware('staff')->group(function () {
            Route::get('/customers', [\App\Http\Controllers\Admin\CustomerController::class, 'index'])
                ->name('admin.customers');
            /*
            | ⚠️ جستجوی زنده **پیش از** `{customer}` — وگرنه لاراول «search» را
            | شناسهٔ مشتری می‌خوانَد و روت هرگز صدا زده نمی‌شود. همان درسِ
            | `tickets/bulk` و `parts/compare`: مسیرِ ثابت قبل از پارامتری.
            */
            Route::get('/customers/search', [\App\Http\Controllers\Admin\CustomerController::class, 'search'])
                ->name('admin.customers.search');
            Route::get('/customers/{customer}', [\App\Http\Controllers\Admin\CustomerController::class, 'show'])
                ->name('admin.customer');
            Route::post('/customers/{customer}/notes', [\App\Http\Controllers\Admin\CustomerNoteController::class, 'store']);
            Route::put('/customers/{customer}/notes/{note}', [\App\Http\Controllers\Admin\CustomerNoteController::class, 'update']);
            Route::delete('/customers/{customer}/notes/{note}', [\App\Http\Controllers\Admin\CustomerNoteController::class, 'destroy']);
        });
        Route::post('/customers/{customer}/status', [\App\Http\Controllers\Admin\CustomerController::class, 'status']);
        Route::post('/customers/{customer}/discount-entry', [\App\Http\Controllers\Admin\DiscountVisibilityController::class, 'updateCustomer'])->middleware('admin');
        Route::post('/customers/{customer}/password', [\App\Http\Controllers\Admin\CustomerController::class, 'password']);
        /*
        | نمایشِ یک‌بارهٔ شمارهٔ کاملِ کارت — فقط `admin`، نه هر کاربرِ پنل.
        | دیدنِ PAN در لاگِ فعالیت ثبت می‌شود و سقفِ نرخ دارد.
        */
        Route::post('/customers/{customer}/bank/{account}/reveal-card',
            [\App\Http\Controllers\Admin\CustomerController::class, 'revealCard'])->middleware('admin');
        // تنظیمِ دستیِ کیفِ پول (افزایش/کاهش با توضیحِ اجباری) — دفتر افزودنی است
        Route::post('/customers/{customer}/credit', [\App\Http\Controllers\Admin\CustomerController::class, 'credit'])->middleware('admin');
        Route::post('/customers/{customer}/bank-accounts/{bankAccount}/reveal', \App\Http\Controllers\Admin\BankCardRevealController::class)->middleware(['admin', 'throttle:10,1']);
        // نمایندگیِ دامنه — فعال‌سازی، سطحِ دستی، تخفیفِ توافقی، سقفِ روزانه
        Route::post('/customers/{customer}/reseller', [\App\Http\Controllers\Admin\CustomerController::class, 'reseller']);
        Route::post('/customers/{customer}/delete', [\App\Http\Controllers\Admin\CustomerController::class, 'destroy']);
        // حذف فاکتورِ پرداخت‌نشده (فاکتورِ پرداخت‌شده هرگز حذف نمی‌شود)
        Route::post('/invoices/{invoice}/delete', [\App\Http\Controllers\Admin\CustomerController::class, 'destroyInvoice']);

        // مرکزِ تحویل‌ها — هر سفارشِ پول‌آمده که سرویسش هنوز دستِ مشتری نیست،
        // با تشخیصِ علت و دکمهٔ اقدامِ درست (روت‌های اقدام همان‌های موجودند)
        Route::get('/provisioning', [\App\Http\Controllers\Admin\ProvisioningController::class, 'index'])
            ->middleware('admin')->name('admin.provisioning');

        // فروش و مدیریت سرویس‌های مشتری
        Route::post('/customers/{customer}/services', [\App\Http\Controllers\Admin\ServiceController::class, 'store']);
        Route::post('/services/{service}/status', [\App\Http\Controllers\Admin\ServiceController::class, 'update']);
        // لغو + بازگشتِ وجه به کیف پول — مسیرِ کاملِ خاتمه (زیرساخت هم آزاد می‌شود)
        Route::post('/services/{service}/cancel-refund', [\App\Http\Controllers\Admin\ServiceController::class, 'cancelRefund']);

        /*
        | حذفِ کاملِ یک سرویسِ لغوشده‌ای که هرگز ساخته نشده.
        |
        | ⚠️ اجازه را **مدل** می‌دهد (`Service::isDeletable()`)، نه این روت و نه
        | ویو. سه شرطش — مرده، تحویل‌نشده، بی‌پرداخت — در یک جا زندگی می‌کنند تا
        | دکمه و کنترلر نتوانند با هم واگرا شوند.
        */
        Route::delete('/services/{service}', [\App\Http\Controllers\Admin\ServiceController::class, 'destroy']);
        Route::post('/services/{service}/renew', [\App\Http\Controllers\Admin\ServiceController::class, 'renew']);
        // تنظیمِ سررسیدِ سرویسِ قدیمیِ بی‌سررسید — بی‌آن، آن سرویس هرگز
        // فاکتورِ تمدید نمی‌گیرد. اعتبارسنجیِ `after:today` در کنترلر است.
        Route::post('/services/{service}/due', [\App\Http\Controllers\Admin\ServiceController::class, 'setDue']);
        /*
        | شبکهٔ ماهِ شمسیِ دیت‌پیکر.
        |
        | ⚠️ فقط می‌خوانَد و هیچ دادهٔ مشتری نمی‌دهد، ولی زیرِ گروهِ admin است
        | چون تنها مصرف‌کننده‌اش پنلِ مدیریت است و سطحِ حمله بی‌دلیل باز نشود.
        */
        Route::get('/jdate', [\App\Http\Controllers\Admin\JalaliDateController::class, 'month']);

        // سرورهای تحویل (WHM/cPanel/…)
        // ورودِ مدیر به پنلِ مشتری (جای او نشستن) — فقط نقشِ مدیر، با لاگ
        Route::post('/customers/{customer}/impersonate', [\App\Http\Controllers\Admin\ImpersonateController::class, 'start'])->middleware('admin');

        // احراز هویتِ مشتریان — صفِ بررسی، تأیید/رد، دانلودِ امنِ مدارک
        Route::get('/verifications', [\App\Http\Controllers\Admin\VerificationController::class, 'index'])->name('admin.verifications');
        Route::get('/verifications/{profile}/doc/{document}', [\App\Http\Controllers\Admin\VerificationController::class, 'document'])->name('admin.verification.doc');
        Route::post('/verifications/{profile}/approve', [\App\Http\Controllers\Admin\VerificationController::class, 'approve']);
        Route::post('/verifications/{profile}/reject', [\App\Http\Controllers\Admin\VerificationController::class, 'reject']);

        Route::get('/servers', [\App\Http\Controllers\Admin\ServerController::class, 'index'])->name('admin.servers')->middleware('admin');
        Route::post('/servers', [\App\Http\Controllers\Admin\ServerController::class, 'store'])->middleware('admin');
        Route::post('/servers/{server}', [\App\Http\Controllers\Admin\ServerController::class, 'update'])->middleware('admin');
        Route::post('/servers/{server}/test', [\App\Http\Controllers\Admin\ServerController::class, 'test'])->middleware('admin');
        Route::post('/servers/{server}/delete', [\App\Http\Controllers\Admin\ServerController::class, 'destroy'])->middleware('admin');

        // پکیج‌های فروش — کاتالوگی که مشتری از آن آنلاین می‌خرد
        Route::get('/products', [\App\Http\Controllers\Admin\ProductController::class, 'index'])->name('admin.products')->middleware('admin');
        Route::post('/products', [\App\Http\Controllers\Admin\ProductController::class, 'store'])->middleware('admin');
        Route::post('/products/{product}', [\App\Http\Controllers\Admin\ProductController::class, 'update'])->middleware('admin');
        Route::post('/products/{product}/delete', [\App\Http\Controllers\Admin\ProductController::class, 'destroy'])->middleware('admin');
        // ساختِ package در WHM از روی پکیج
        Route::post('/products/{product}/whm-sync', [\App\Http\Controllers\Admin\ProductController::class, 'syncWhm'])->middleware('admin');
        Route::post('/products-whm-sync-all', [\App\Http\Controllers\Admin\ProductController::class, 'syncWhmAll'])->middleware('admin');
        // تغییرِ قیمتِ گروهی (درصدی/مبلغی) با گردکردنِ رو به بالا
        Route::post('/products-reprice', [\App\Http\Controllers\Admin\ProductController::class, 'reprice'])->middleware('admin');

        // فروشگاهِ سرورِ فیزیکی — کاتالوگِ HP/Dell/Lenovo/Supermicro (مشخصاتِ سه‌زبانه + گالری)
        Route::get('/server-shop', [\App\Http\Controllers\Admin\PhysicalServerController::class, 'index'])->name('admin.server-shop')->middleware('admin');
        Route::get('/server-shop/create', [\App\Http\Controllers\Admin\PhysicalServerController::class, 'create'])->middleware('admin');
        Route::post('/server-shop', [\App\Http\Controllers\Admin\PhysicalServerController::class, 'store'])->middleware('admin');
        Route::get('/server-shop/{server}/edit', [\App\Http\Controllers\Admin\PhysicalServerController::class, 'edit'])->middleware('admin');
        Route::post('/server-shop/{server}', [\App\Http\Controllers\Admin\PhysicalServerController::class, 'update'])->middleware('admin');
        Route::post('/server-shop/{server}/delete', [\App\Http\Controllers\Admin\PhysicalServerController::class, 'destroy'])->middleware('admin');

        // فروشگاهِ قطعات — همان الگوی server-shop. `{part}` با اتصالِ مدلِ
        // ServerPart حل می‌شود، پس شناسهٔ ناموجود خودبه‌خود ۴۰۴ می‌دهد.
        Route::get('/parts', [\App\Http\Controllers\Admin\ServerPartController::class, 'index'])->name('admin.parts')->middleware('admin');
        Route::get('/parts/create', [\App\Http\Controllers\Admin\ServerPartController::class, 'create'])->middleware('admin');
        Route::post('/parts', [\App\Http\Controllers\Admin\ServerPartController::class, 'store'])->middleware('admin');
        Route::get('/parts/{part}/edit', [\App\Http\Controllers\Admin\ServerPartController::class, 'edit'])->middleware('admin');
        Route::post('/parts/{part}', [\App\Http\Controllers\Admin\ServerPartController::class, 'update'])->middleware('admin');
        Route::post('/parts/{part}/delete', [\App\Http\Controllers\Admin\ServerPartController::class, 'destroy'])->middleware('admin');

        // زیرساختِ سرورِ ابری — کاتالوگ، آزمونِ اتصال، همگام‌سازی
        // الگوی پیام‌ها — متنِ ایمیل/بله/اعلان یک‌جا
        // اعلامِ اختلال روی صفحهٔ وضعیت — کانالِ ارتباطیِ ازپیش‌آمادهٔ حادثه
        Route::get('/status', [\App\Http\Controllers\Admin\StatusIncidentController::class, 'index'])->middleware('admin');
        Route::post('/status', [\App\Http\Controllers\Admin\StatusIncidentController::class, 'store'])->middleware('admin');
        Route::post('/status/{incident}', [\App\Http\Controllers\Admin\StatusIncidentController::class, 'update'])->middleware('admin');

        Route::get('/templates', [\App\Http\Controllers\Admin\NotificationTemplateController::class, 'index'])->name('admin.templates')->middleware('admin');
        Route::get('/templates/{template}', [\App\Http\Controllers\Admin\NotificationTemplateController::class, 'edit'])->middleware('admin');
        Route::post('/templates/{template}', [\App\Http\Controllers\Admin\NotificationTemplateController::class, 'update'])->middleware('admin');
        Route::post('/templates/{template}/test', [\App\Http\Controllers\Admin\NotificationTemplateController::class, 'test'])->middleware(['admin', 'throttle:6,1']);
        Route::post('/templates/{template}/toggle', [\App\Http\Controllers\Admin\NotificationTemplateController::class, 'toggle'])->middleware('admin');

        /*
        | منوی هدر و فوتر — ویرایشِ متنِ سه‌زبانه، ترتیب، خاموشی و لینکِ تازه.
        |
        | ⚠️ همه `admin` می‌خواهند: فوتر روی **هر** صفحهٔ سایت رندر می‌شود، پس
        | نوشتن در آن یعنی نوشتن روی کلِ سایت — نه یک تنظیمِ داخلی.
        */
        Route::post('/menus/save', [\App\Http\Controllers\Admin\MenuController::class, 'save'])->middleware('admin');
        Route::post('/menus/hide', [\App\Http\Controllers\Admin\MenuController::class, 'hide'])->middleware('admin');
        Route::post('/menus/add', [\App\Http\Controllers\Admin\MenuController::class, 'add'])->middleware('admin');
        Route::post('/menus/{override}/toggle', [\App\Http\Controllers\Admin\MenuController::class, 'toggle'])->middleware('admin');
        Route::delete('/menus/{override}', [\App\Http\Controllers\Admin\MenuController::class, 'destroy'])->middleware('admin');

        // [vpstc-readonly-admin:start]
        Route::get('/cloud/vpstc', [\App\Http\Controllers\Admin\VpsTcController::class, 'index'])->middleware('admin')->name('admin.cloud.vpstc');
        Route::post('/cloud/vpstc', [\App\Http\Controllers\Admin\VpsTcController::class, 'save'])->middleware(['admin', 'throttle:6,1'])->name('admin.cloud.vpstc.save');
        Route::post('/cloud/vpstc/preflight', [\App\Http\Controllers\Admin\VpsTcController::class, 'preflight'])->middleware(['admin', 'throttle:3,1'])->name('admin.cloud.vpstc.preflight');
        Route::post('/cloud/vpstc/pricing', [\App\Http\Controllers\Admin\VpsTcController::class, 'savePricing'])->middleware(['admin', 'throttle:6,1'])->name('admin.cloud.vpstc.pricing');
        Route::post('/cloud/vpstc/catalog', [\App\Http\Controllers\Admin\VpsTcController::class, 'syncCatalog'])->middleware(['admin', 'throttle:3,1'])->name('admin.cloud.vpstc.catalog');
        Route::post('/cloud/vpstc/sales', [\App\Http\Controllers\Admin\VpsTcController::class, 'saveSales'])->middleware(['admin', 'throttle:6,1'])->name('admin.cloud.vpstc.sales');
        // [vpstc-readonly-admin:end]

        Route::get('/cloud', [\App\Http\Controllers\Admin\CloudController::class, 'index'])->name('admin.cloud')->middleware('admin');
        Route::post('/cloud/test', [\App\Http\Controllers\Admin\CloudController::class, 'test'])->middleware('admin');
        // Action URLs are POST-only. A browser/history/expired form must return
        // to the catalogue instead of exposing Laravel's generic 405 page.
        // This GET never queues or executes provider work.
        Route::get('/cloud/sync', fn () => redirect()->route('admin.cloud', status: 303)
            ->with('err', 'درخواست همگام‌سازی از مسیر امن اجرا نشد و هیچ تغییری اعمال نشد. از دکمهٔ همگام‌سازی همین صفحه استفاده کنید.'))
            ->middleware('admin');
        Route::post('/cloud/sync', [\App\Http\Controllers\Admin\CloudController::class, 'sync'])->middleware('admin');
        // ابزارِ عیب‌یابیِ ساختارِ پاسخ — نگاشتِ فیلدهای زیرساختِ ۲ کاملاً قطعی نیست
        // اتصالِ سرورِ ازقبل‌ساخته‌شده به مشتری — سرور نمی‌سازد، فقط ثبت می‌کند.
        Route::get('/cloud/attach', [\App\Http\Controllers\Admin\CloudAttachController::class, 'form'])->middleware('admin');
        Route::post('/cloud/attach', [\App\Http\Controllers\Admin\CloudAttachController::class, 'store'])->middleware('admin');
        // تطبیقِ موجودی: سرورِ بی‌مشتری و سرویسِ بی‌سرور — هر دو نشتیِ پول‌اند
        Route::get('/cloud/inventory', [\App\Http\Controllers\Admin\CloudAttachController::class, 'inventory'])->middleware('admin');

        /*
        | ناوگانِ زیرساخت — نسخهٔ **ماندگار و قابلِ جست‌وجوی** همان تطبیق.
        |
        | `/cloud/inventory` گزارشِ لحظه‌ای می‌دهد؛ این‌جا همان داده در جدول
        | می‌نشیند تا بشود رویش جست‌وجو کرد، سنِ رهاشدگی و ضررِ انباشته را حساب
        | کرد، و ماشین را طبقه‌بندی کرد. هر دو می‌مانند: آن یکی برای «همین الان
        | چه می‌بینی»، این یکی برای «چه چیزی از دستمان در رفته».
        */
        // [managed-dns-admin:start]
        /*
        | DNSِ مدیریت‌شده v2 — اتصالِ primary، نیم‌سرورها، پرچمِ عرضه، zoneها.
        | ⚠️ `admin` روی تک‌تک: این صفحه کلیدِ APIِ سرورِ DNSِ همهٔ مشتریان را
        | عوض می‌کند و نباید هرگز به نقشِ پشتیبان باز شود.
        */
        Route::get('/dns', [\App\Http\Controllers\Admin\DnsController::class, 'index'])->name('admin.dns')->middleware('admin');
        Route::post('/dns/settings', [\App\Http\Controllers\Admin\DnsController::class, 'settings'])->middleware('admin');
        Route::post('/dns/test', [\App\Http\Controllers\Admin\DnsController::class, 'test'])->middleware(['admin', 'throttle:20,1']);
        Route::post('/dns/zones/{zone}/republish', [\App\Http\Controllers\Admin\DnsController::class, 'republish'])->whereNumber('zone')->middleware(['admin', 'throttle:30,1']);
        Route::post('/dns/zones/{zone}/check', [\App\Http\Controllers\Admin\DnsController::class, 'check'])->whereNumber('zone')->middleware(['admin', 'throttle:30,1']);
        Route::post('/dns/zones/{zone}/status', [\App\Http\Controllers\Admin\DnsController::class, 'status'])->whereNumber('zone')->middleware('admin');
        Route::post('/dns/zones/{zone}/delete', [\App\Http\Controllers\Admin\DnsController::class, 'destroy'])->whereNumber('zone')->middleware(['admin', 'throttle:6,1']);
        // [managed-dns-admin:end]

        Route::get('/fleet', [\App\Http\Controllers\Admin\FleetController::class, 'index'])
            ->name('admin.fleet')->middleware('admin');
        Route::get('/fleet/export', [\App\Http\Controllers\Admin\FleetController::class, 'export'])->middleware('admin');
        // اسکن نرخ‌بندی می‌شود: هر اجرا چند تماسِ زنده با همهٔ زیرساخت‌هاست و
        // رفرشِ پیاپی می‌تواند ما را به سقفِ نرخِ API آنها برساند.
        Route::post('/fleet/scan', [\App\Http\Controllers\Admin\FleetController::class, 'scan'])
            ->middleware(['admin', 'throttle:10,1']);
        Route::post('/fleet/{asset}/annotate', [\App\Http\Controllers\Admin\FleetController::class, 'annotate'])
            ->whereNumber('asset')->middleware('admin');
        // حذفِ واقعیِ ماشین نزدِ زیرساخت — برگشت‌ناپذیر، پس نرخ‌بندیِ سفت‌تر
        Route::post('/fleet/{asset}/release', [\App\Http\Controllers\Admin\FleetController::class, 'release'])
            ->whereNumber('asset')->middleware(['admin', 'throttle:6,1']);

        // زیرساختِ اکسیت — دیدِ اپراتور به Exit VPSها + سوییچِ کشورِ خروج (فازِ A)
        Route::get('/exit-infra', [\App\Http\Controllers\Admin\ExitInfraController::class, 'index'])
            ->name('admin.exit-infra')->middleware('admin');
        // سوییچِ کشورِ خروجِ یک ماشین — فقط meta را می‌نویسد؛ ایجنتِ ایران اعمال می‌کند
        Route::post('/exit-infra/{instance}/country', [\App\Http\Controllers\Admin\ExitInfraController::class, 'setCountry'])
            ->name('admin.exit-infra.country')->middleware('admin');

        // مدیریتِ ماشین‌ها: وارد کردن (اسکنِ Proxmox یا دستی)، پورتِ عمومی، و
        // حذف از فهرستِ اکسیت. 🔴 گاردِ خطِ‌قرمز (VM108 و زیرساخت) در کنترلر است؛
        // اسکن فقط با ?scan=1 اجرا می‌شود تا بازکردنِ صفحه تماسِ API نزند.
        Route::get('/exit-infra/import', [\App\Http\Controllers\Admin\ExitInfraController::class, 'importForm'])
            ->name('admin.exit-infra.import')->middleware('admin');
        Route::post('/exit-infra/import', [\App\Http\Controllers\Admin\ExitInfraController::class, 'import'])
            ->name('admin.exit-infra.import.store')->middleware('admin');
        Route::post('/exit-infra/{instance}/port', [\App\Http\Controllers\Admin\ExitInfraController::class, 'setPort'])
            ->name('admin.exit-infra.port')->middleware('admin');
        Route::post('/exit-infra/{instance}/detach', [\App\Http\Controllers\Admin\ExitInfraController::class, 'detach'])
            ->name('admin.exit-infra.detach')->middleware('admin');
        // اجازه/منعِ دسترسی به شبکهٔ داخلی — فقط «حالتِ مطلوب»؛ عاملِ میزبان اعمال می‌کند.
        Route::post('/exit-infra/{instance}/lan', [\App\Http\Controllers\Admin\ExitInfraController::class, 'setLan'])
            ->name('admin.exit-infra.lan')->middleware('admin');
        // تخصیصِ پورتِ عمومی به ماشین‌های بی‌پورت — جایگزینِ کارِ بی‌صدایی که
        // تا دیروز داخلِ GETِ عامل انجام می‌شد.
        Route::post('/exit-infra/sync-ports', [\App\Http\Controllers\Admin\ExitInfraController::class, 'syncPorts'])
            ->name('admin.exit-infra.sync-ports')->middleware('admin');

        // آپ‌استریم‌های اکسیت — رله‌های SSH و نودهای VLESS که موتورِ اکسیت از
        // راهشان از کشور خارج می‌شود. پنل «حالتِ مطلوب» را می‌نویسد و میزبانِ
        // ایران آن را می‌کشد (همان الگوی countryroutes).
        // ⚠️ /upstreams/create پیش از {upstream} تعریف‌شدن لازم ندارد چون شمارِ
        // segmentها فرق دارد و {upstream} فقط در ۴-segmentها می‌آید.
        Route::get('/exit-infra/upstreams', [\App\Http\Controllers\Admin\ExitUpstreamController::class, 'index'])
            ->name('admin.exit-upstreams')->middleware('admin');
        Route::get('/exit-infra/upstreams/create', [\App\Http\Controllers\Admin\ExitUpstreamController::class, 'create'])
            ->name('admin.exit-upstreams.create')->middleware('admin');
        Route::post('/exit-infra/upstreams', [\App\Http\Controllers\Admin\ExitUpstreamController::class, 'store'])
            ->name('admin.exit-upstreams.store')->middleware('admin');
        Route::get('/exit-infra/upstreams/{upstream}/edit', [\App\Http\Controllers\Admin\ExitUpstreamController::class, 'edit'])
            ->name('admin.exit-upstreams.edit')->middleware('admin');
        Route::post('/exit-infra/upstreams/{upstream}', [\App\Http\Controllers\Admin\ExitUpstreamController::class, 'update'])
            ->name('admin.exit-upstreams.update')->middleware('admin');
        Route::post('/exit-infra/upstreams/{upstream}/toggle', [\App\Http\Controllers\Admin\ExitUpstreamController::class, 'toggle'])
            ->name('admin.exit-upstreams.toggle')->middleware('admin');
        Route::post('/exit-infra/upstreams/{upstream}/delete', [\App\Http\Controllers\Admin\ExitUpstreamController::class, 'destroy'])
            ->name('admin.exit-upstreams.delete')->middleware('admin');

        /*
        | دامنه‌ها — و مهم‌تر از فهرست، **صفِ دستی**.
        |
        | 🔴 `DomainRegistrar` دامنهٔ مشکل‌دار را به `provision_status='manual'`
        | می‌بَرد تا کرون رهایش کند و آدم تصمیم بگیرد. ولی تا امروز هیچ آدمی آن
        | صف را نمی‌دید: نه صفحه‌ای بود، نه اعلانی، و خروجیِ کرون به /dev/null
        | می‌رفت. مشتری پول داده بود و تنها نشانه‌اش یک ردیف در دیتابیس بود.
        */
        Route::get('/domains', [\App\Http\Controllers\Admin\DomainController::class, 'index'])
            ->name('admin.domains')->middleware('admin');
        Route::post('/domains/{domain}/retry', [\App\Http\Controllers\Admin\DomainController::class, 'retry'])
            ->name('admin.domains.retry')->middleware('admin');
        Route::post('/domains/{domain}/register', [\App\Http\Controllers\Admin\DomainController::class, 'registerNow'])
            ->name('admin.domains.register')->middleware('admin');

        Route::get('/cloud/probe', [\App\Http\Controllers\Admin\CloudController::class, 'probe'])->middleware('admin');
        // خاموش/روشنِ پکیج/مکان/کشور/زیرساخت — همه POST و پشتِ گیتِ مدیر.
        // روی admin_disabled می‌نویسند نه is_active، تا کرونِ سینک تصمیم را
        // بی‌صدا برنگرداند.
        Route::post('/cloud/plans/{plan}/toggle', [\App\Http\Controllers\Admin\CloudController::class, 'togglePlan'])->middleware('admin');
        /*
        | اقدامِ گروهی روی ردیف‌های انتخاب‌شده — «دونه دونه مدیریتشون سخته».
        |
        | 🔴 `bulk-open` فقط قرنطینهٔ **خودکار** را برمی‌گردانَد (همان تفکیکِ
        | `cloud:reopen`)؛ پلنی که مدیر آگاهانه بسته دست نمی‌خورد و علتِ
        | ردشدنش گزارش می‌شود. یک دکمهٔ گروهی که تصمیمِ انسانی را پاک کند، از
        | ۲۲۱ بار کلیک بدتر است.
        */
        Route::post('/cloud/plans/bulk-open', [\App\Http\Controllers\Admin\CloudController::class, 'bulkOpen'])->middleware('admin');
        Route::post('/cloud/plans/bulk-close', [\App\Http\Controllers\Admin\CloudController::class, 'bulkClose'])->middleware('admin');
        Route::post('/cloud/locations/{code}/toggle', [\App\Http\Controllers\Admin\CloudController::class, 'toggleLocation'])->middleware('admin');
        Route::post('/cloud/countries/{iso}/toggle', [\App\Http\Controllers\Admin\CloudController::class, 'toggleCountry'])->middleware('admin');
        Route::post('/cloud/providers/{provider}/toggle', [\App\Http\Controllers\Admin\CloudController::class, 'toggleProvider'])->middleware('admin');

        // اقداماتِ تحویلِ سرویس — ساخت/تلاش دوباره، تعلیق، حذف روی سرور
        Route::post('/services/{service}/provision', [\App\Http\Controllers\Admin\ServiceController::class, 'provision']);

        /*
        | رهاسازیِ صریحِ سفارشی که محافظِ سوءاستفاده نگهش داشته.
        |
        | ⚠️ عمداً روتِ جداست نه یک فیلد روی `/provision`: آن یکی فرمِ «تلاشِ
        | دوباره»ی هاست هم هست، و کنارگذاشتنِ محافظ نباید از یک جریانِ بی‌ربط
        | قابلِ رسیدن باشد یا در تاریخچه شبیهِ یک تلاشِ معمولی دیده شود.
        */
        Route::post('/services/{service}/provision-override', [\App\Http\Controllers\Admin\ServiceController::class, 'provisionOverride']);
        Route::post('/services/{service}/ack-manual', [\App\Http\Controllers\Admin\ServiceController::class, 'ackManual']);
        Route::post('/services/{service}/resolve-release', [\App\Http\Controllers\Admin\ServiceController::class, 'resolveRelease']);
        Route::post('/services/{service}/suspend', [\App\Http\Controllers\Admin\ServiceController::class, 'suspend']);
        Route::post('/services/{service}/unsuspend', [\App\Http\Controllers\Admin\ServiceController::class, 'unsuspend']);
        Route::post('/services/{service}/terminate', [\App\Http\Controllers\Admin\ServiceController::class, 'terminate']);

        // تاریخچهٔ مالکیتِ یک سرویس: کی خرید، کی تمدید کرد، کی تعلیق/حذف شد
        Route::get('/services/{service}/history', [\App\Http\Controllers\Admin\ServiceController::class, 'history'])->name('admin.service.history');

        /*
         * تقویمِ کسب‌وکار — سررسیدِ دامنه، سرویس، فاکتور، انتشارِ محتوا و
         * یادآوری‌های دستی، همه در یک صفحه.
         *
         * ⚠️ داخلِ گروهِ `admin` می‌مانَد (بیرونِ فهرستِ سفیدِ نویسنده): این صفحه
         * سررسیدِ فاکتور و پروندهٔ مشتری را کنار هم نشان می‌دهد، و نقشِ `author`
         * هیچ‌کدام را نباید ببیند.
         *
         * فعل‌های PATCH/DELETE عمداً واقعی‌اند نه POSTِ پوشیده — این روت‌ها فقط
         * با fetch صدا زده می‌شوند (فرمِ HTML در کار نیست) و CSRF از هدرِ
         * `X-CSRF-TOKEN` می‌آید.
         */
        Route::get('/calendar', [\App\Http\Controllers\Admin\CalendarController::class, 'index'])->name('admin.calendar');
        Route::get('/calendar/events', [\App\Http\Controllers\Admin\CalendarController::class, 'events'])->name('admin.calendar.events');
        Route::post('/calendar/events', [\App\Http\Controllers\Admin\CalendarController::class, 'store']);
        Route::patch('/calendar/events/{event}', [\App\Http\Controllers\Admin\CalendarController::class, 'update']);
        Route::delete('/calendar/events/{event}', [\App\Http\Controllers\Admin\CalendarController::class, 'destroy']);
        Route::post('/calendar/preferences', [\App\Http\Controllers\Admin\CalendarController::class, 'preferences']);

        // ارسالِ آزمایشیِ یادآوری — همان الگوی `/admin/templates/{t}/test`.
        // throttle چون هر بار یک پیامِ واقعیِ بله و یک ایمیل می‌فرستد.
        Route::post('/calendar/remind-test', [\App\Http\Controllers\Admin\CalendarController::class, 'remindTest'])
            ->middleware('throttle:6,1');

        /*
         * بررسیِ سایت + ارسالِ گزارش.
         *
         * ⚠️ همهٔ POSTها JSON برمی‌گردانند و مرورگر حلقه می‌زند (هر بررسی چند
         * ثانیه است). هیچ‌کدام زمان‌بندی نشده‌اند: این کار به آدم‌های واقعی
         * ایمیل می‌فرستد و باید هر بار یک انسان دکمه را بزند.
         */
        Route::get('/seo', [\App\Http\Controllers\Admin\SeoOutreachController::class, 'index'])->name('admin.seo');
        Route::post('/seo/send-one', [\App\Http\Controllers\Admin\SeoOutreachController::class, 'sendOne']);
        Route::post('/seo/list', [\App\Http\Controllers\Admin\SeoOutreachController::class, 'importList']);
        Route::post('/seo/list-own', [\App\Http\Controllers\Admin\SeoOutreachController::class, 'importOwn']);
        Route::post('/seo/scan-next', [\App\Http\Controllers\Admin\SeoOutreachController::class, 'scanNext']);
        Route::post('/seo/send-next', [\App\Http\Controllers\Admin\SeoOutreachController::class, 'sendNext']);
        Route::post('/seo/reports/{report}/revoke', [\App\Http\Controllers\Admin\SeoOutreachController::class, 'revokeReport'])->name('admin.seo.reports.revoke');

        /*
         * اتصالِ تقویمِ گوگل — **per-user**. هر کاربرِ پنل حسابِ خودش را وصل
         * می‌کند و فقط رویدادهای خودش را می‌بیند.
         *
         * ⚠️ `callback` عمداً GET و بی‌CSRF است: گوگل کاربر را با یک ریدایرکتِ
         * مرورگری برمی‌گرداند و توکنِ CSRF در آن نیست. محافظش پارامترِ `state`
         * است که در نشست نشسته و در بازگشت با `hash_equals` سنجیده می‌شود.
         */
        Route::get('/calendar/google/connect', [\App\Http\Controllers\Admin\CalendarController::class, 'googleConnect']);
        Route::get('/calendar/google/callback', [\App\Http\Controllers\Admin\CalendarController::class, 'googleCallback']);
        Route::post('/calendar/google/disconnect', [\App\Http\Controllers\Admin\CalendarController::class, 'googleDisconnect']);
        // شناسهٔ رویدادِ گوگل می‌تواند نقطه و خط‌تیره داشته باشد، پس الگو باز است
        Route::delete('/calendar/google/events/{eventId}', [\App\Http\Controllers\Admin\CalendarController::class, 'googleDestroyEvent'])
            ->where('eventId', '[A-Za-z0-9_\-@.]+');

        // اعلان به مشتریان — یک نفر یا همه (پیامک + بله)
        Route::get('/broadcasts', [\App\Http\Controllers\Admin\BroadcastController::class, 'index'])->name('admin.broadcasts');
        Route::post('/broadcasts', [\App\Http\Controllers\Admin\BroadcastController::class, 'send']);

        // هزینه‌های ثابت سرویس‌ها — که خودِ مدیر تعیین می‌کند
        Route::get('/costs', [\App\Http\Controllers\Admin\CostController::class, 'index'])->name('admin.costs')->middleware('admin');
        Route::post('/costs', [\App\Http\Controllers\Admin\CostController::class, 'update'])->middleware('admin');
        Route::post('/costs/add', [\App\Http\Controllers\Admin\CostController::class, 'store'])->middleware('admin');
        Route::post('/costs/{cost}/delete', [\App\Http\Controllers\Admin\CostController::class, 'destroy'])->middleware('admin');

        /*
        | جذبِ مشتریِ خارجی — قیفِ فروش و صفِ تأییدِ ایمیل.
        |
        | 🔴 همه‌شان `admin` می‌خواهند و نه فقط `auth:web`: این صفحه ایمیل به
        | بیرون می‌فرستد از نشانیِ ceo@servernet.cloud. نویسندهٔ بلاگ نباید
        | بتواند به نامِ مدیرعامل برای یک کلینیکِ خارجی نامه بفرستد.
        */
        /*
        | بازاریابی هوشمند — قیفِ فروش، صفِ تأییدِ پیام، و رشدِ ارگانیک.
        |
        | 🔴 همه‌شان `admin` می‌خواهند و نه فقط `auth:web`: این صفحه ایمیل به
        | بیرون می‌فرستد از نشانیِ ceo@servernet.cloud. نویسندهٔ بلاگ نباید
        | بتواند به نامِ مدیرعامل برای یک کلینیکِ خارجی نامه بفرستد.
        */
        Route::prefix('marketing/campaigns')->withoutMiddleware('admin')->group(function () {
            $controller = \App\Http\Controllers\Admin\CampaignController::class;
            Route::get('/', [$controller, 'index'])->name('admin.marketing.campaigns');
            Route::get('/create', [$controller, 'create']);
            Route::post('/', [$controller, 'store']);
            Route::post('/sms-policy', [$controller, 'policy']);
            Route::post('/permissions', [$controller, 'permissions']);
            Route::post('/redemptions/{redemption}/reconcile', [$controller, 'reconcile']);
            Route::post('/grants/{grant}/revoke', [$controller, 'revoke']);
            Route::get('/{campaign}', [$controller, 'show'])->whereNumber('campaign');
            Route::get('/{campaign}/edit', [$controller, 'edit'])->whereNumber('campaign');
            Route::post('/{campaign}', [$controller, 'update'])->whereNumber('campaign');
            Route::post('/{campaign}/transition', [$controller, 'transition'])->whereNumber('campaign');
            Route::post('/{campaign}/issue', [$controller, 'issue'])->whereNumber('campaign')->middleware('throttle:20,1');
            Route::get('/{campaign}/export', [$controller, 'export'])->whereNumber('campaign');
        });
        Route::get('/marketing', [\App\Http\Controllers\Admin\MarketingController::class, 'index'])->name('admin.marketing')->middleware('admin');
        Route::post('/marketing', [\App\Http\Controllers\Admin\MarketingController::class, 'store'])->middleware('admin');
        Route::get('/marketing/growth', [\App\Http\Controllers\Admin\MarketingController::class, 'growth'])->name('admin.marketing.growth')->middleware('admin');
        Route::get('/marketing/{lead}', [\App\Http\Controllers\Admin\MarketingController::class, 'show'])->name('admin.marketing.lead')->middleware('admin')->whereNumber('lead');
        Route::post('/marketing/{lead}/enrich', [\App\Http\Controllers\Admin\MarketingController::class, 'enrich'])->middleware(['admin', 'throttle:20,1']);
        Route::post('/marketing/{lead}/compose', [\App\Http\Controllers\Admin\MarketingController::class, 'compose'])->middleware(['admin', 'throttle:20,1']);
        Route::post('/marketing/{lead}/social', [\App\Http\Controllers\Admin\MarketingController::class, 'social'])->middleware(['admin', 'throttle:20,1']);
        Route::post('/marketing/{lead}/stage', [\App\Http\Controllers\Admin\MarketingController::class, 'stage'])->middleware('admin');
        Route::post('/marketing/{lead}/suppress', [\App\Http\Controllers\Admin\MarketingController::class, 'suppress'])->middleware('admin');
        Route::post('/marketing/message/{message}/approve', [\App\Http\Controllers\Admin\MarketingController::class, 'approve'])->middleware(['admin', 'throttle:30,1']);
        Route::post('/marketing/message/{message}/reject', [\App\Http\Controllers\Admin\MarketingController::class, 'reject'])->middleware('admin');
        Route::post('/marketing/message/{message}/sent', [\App\Http\Controllers\Admin\MarketingController::class, 'markSent'])->middleware('admin');

        /*
        | صندوق‌های ایمیلِ مدیریتی — ceo@ · support@ · info@
        |
        | 🔴 `admin` و نه `auth:web`: این صفحه سرآیندِ نامه‌های شرکت را نشان
        | می‌دهد، از جمله صندوقِ پشتیبانی که پر از دادهٔ مشتری است. نویسندهٔ
        | بلاگ کاری با آن ندارد.
        */
        Route::get('/mail', [\App\Http\Controllers\Admin\MailboxController::class, 'index'])->name('admin.mail')->middleware('admin');
        Route::post('/mail/clear', [\App\Http\Controllers\Admin\MailboxController::class, 'clear'])->middleware('admin');
        Route::post('/mail/{message}/handled', [\App\Http\Controllers\Admin\MailboxController::class, 'handled'])->middleware('admin');
        Route::post('/mail/{message}/reopen', [\App\Http\Controllers\Admin\MailboxController::class, 'reopen'])->middleware('admin');
        /*
        | ⚠️ خواندنِ نامه و دانلودِ پیوست هر دو GET اند ولی **هرکدام یک اتصالِ
        | زندهٔ IMAP** می‌زنند (بدنه ذخیره نمی‌شود). پس ربات و پیش‌واکشیِ
        | مرورگر نباید سراغشان بروند — `noindex` روی خودِ صفحه است و لینکِ
        | پیوست `rel=nofollow` دارد.
        */
        Route::get('/mail/{message}', [\App\Http\Controllers\Admin\MailboxController::class, 'show'])->name('admin.mail.show')->middleware('admin');
        Route::get('/mail/{message}/attachment/{index}', [\App\Http\Controllers\Admin\MailboxController::class, 'attachment'])->whereNumber('index')->middleware('admin');
        Route::post('/mail/{message}/reply', [\App\Http\Controllers\Admin\MailboxController::class, 'reply'])->middleware('admin');
        /*
        | ⚠️ `move` روی **خودِ صندوق** اثر دارد، نه فقط روی ردیفِ دیتابیس. پس
        | POST است نه GET: یک پیش‌واکشیِ مرورگر یا رباتِ لینک‌خوان نباید بتواند
        | نامهٔ کسی را به سطلِ زباله ببرد.
        */
        Route::post('/mail/{message}/move/{kind}', [\App\Http\Controllers\Admin\MailboxController::class, 'move'])
            ->whereIn('kind', ['trash', 'junk', 'archive'])->middleware('admin');
        Route::post('/mail/{message}/remind', [\App\Http\Controllers\Admin\MailboxController::class, 'remind'])->middleware('admin');

        // واریز به حساب — صف تأیید پرداخت‌های دستی
        Route::get('/bank-transfers', [\App\Http\Controllers\Admin\BankTransferController::class, 'index'])->name('admin.bank_transfers')->middleware('admin');
        Route::get('/customers/{customer}/evidence', [\App\Http\Controllers\Admin\CustomerController::class, 'evidence'])->middleware('admin')->name('admin.customer.evidence');
        Route::get('/bank-transfers/{receipt}/evidence', [\App\Http\Controllers\Admin\BankTransferController::class, 'evidence'])->middleware('admin')->name('admin.bank-transfers.evidence');
        Route::post('/bank-transfers/{receipt}/approve', [\App\Http\Controllers\Admin\BankTransferController::class, 'approve'])->middleware('admin');
        Route::post('/bank-transfers/{receipt}/reject', [\App\Http\Controllers\Admin\BankTransferController::class, 'reject'])->middleware('admin');

        // حساب‌های دریافتِ آفلاین — حوالهٔ ارزی (یورو/پوند/لیر) و کیفِ رمزارز.
        // از پنل مدیریت می‌آیند نه config، چون حساب‌ها در زمان‌های مختلف باز می‌شوند
        // و هر کدام نباید یک دیپلوی لازم داشته باشد.
        Route::get('/payment-accounts', [\App\Http\Controllers\Admin\PaymentAccountController::class, 'index'])->name('admin.payment_accounts')->middleware('admin');
        Route::post('/payment-accounts', [\App\Http\Controllers\Admin\PaymentAccountController::class, 'store'])->middleware('admin');
        Route::post('/payment-accounts/{account}', [\App\Http\Controllers\Admin\PaymentAccountController::class, 'update'])->middleware('admin');
        Route::post('/payment-accounts/{account}/archive', [\App\Http\Controllers\Admin\PaymentAccountController::class, 'destroy'])->middleware('admin');

        // استخرِ آدرس‌های رمزارز + صفِ بازبینیِ پرداخت‌هایی که خودکار تأیید نشدند
        Route::get('/crypto-wallets', [\App\Http\Controllers\Admin\CryptoWalletController::class, 'index'])->name('admin.crypto_wallets')->middleware('admin');
        Route::post('/crypto-wallets', [\App\Http\Controllers\Admin\CryptoWalletController::class, 'store'])->middleware('admin');
        Route::post('/crypto-wallets/{wallet}/toggle', [\App\Http\Controllers\Admin\CryptoWalletController::class, 'toggle'])->middleware('admin');

        // تنظیمات — مشخصات حساب بانکی شرکت
        Route::get('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'edit'])->name('admin.settings')->middleware('admin');
        Route::post('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update'])->middleware('admin');
        Route::post('/settings/payment-visibility', [\App\Http\Controllers\Admin\SettingsController::class, 'paymentVisibility'])->middleware('admin');
        Route::post('/settings/discount-entry', [\App\Http\Controllers\Admin\DiscountVisibilityController::class, 'updateDefault'])->middleware('admin');
        Route::post('/settings/birthday-rewards', [\App\Http\Controllers\Admin\BirthdayRewardsController::class, 'update'])->middleware('admin');
        Route::post('/settings/birthday-costs', [\App\Http\Controllers\Admin\BirthdayRewardsController::class, 'cost'])->middleware('admin');
    });
});

/*
 * APIِ مشتری — نسخهٔ ۱، احراز با توکنِ Bearer (بدونِ نشست). خطاها JSON‌اند
 * (bootstrap: shouldRenderJsonWhen is('api/*')). فعلاً فقط‌خواندنی؛ زیرساختِ
 * ability طوری است که بعداً روت‌های نوشتنی (ساختِ سرویس/دامنه) با توکنِ
 * دارای دسترسیِ نوشتن اضافه شوند.
 */
Route::prefix('api/v1')
    // بی‌نشست: تماس‌گیرنده با توکنِ Bearer می‌آید نه کوکی؛ بدونِ حذفِ این‌ها،
    // StartSession روی هر تماسِ بی‌کوکی یک ردیفِ نشستِ تازه در SQLite می‌نویسد.
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        // CSRF به نشست وابسته است؛ چون نشست را برداشتیم و احراز با توکن است، این هم می‌رود
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    ])
    ->group(function () {
        $api = \App\Http\Middleware\CustomerApiToken::class;
        $rate = (array) config('domain_reseller.limits.rate', []);

        /*
        |----------------------------------------------------------------------
        | 🔴 throttle روی **همهٔ** مسیرها، از جمله خواندنی‌ها
        |----------------------------------------------------------------------
        |
        | تا امروز این بلوک هیچ throttle نداشت، در حالی که خودِ همین فایل
        | قاعده‌اش را نوشته: «throttle روی هر POST که یا پول خرج می‌کند یا
        | قابلِ حدس زدن است» — و `signin`, `otp`, `kyc` و حتی سفارشِ دامنهٔ
        | پنل (`throttle:12,1`) را محدود کرده. یعنی تنها سطحی که قرار بود
        | پول خرج کند، استثنا بود.
        |
        | ⚠️ سطحِ استعلام جدا و سخت‌گیرانه‌تر است: هر `check` چند تماسِ واقعی
        | با رجیسترار می‌سازد، و حسابِ ما یک بار به‌خاطرِ تماسِ زیاد علامت
        | خورده. محدودیتِ ما این‌جا از محدودیتِ آنها ارزان‌تر تمام می‌شود.
        */

        // ── خواندنیِ حساب (سازگارِ عقب‌رو با نسخهٔ قبل) ──
        Route::middleware([$api.':read', 'throttle:'.($rate['read'] ?? '120,1')])
            ->group(function () {
                Route::get('/me', [\App\Http\Controllers\Api\CustomerApiController::class, 'me']);
                Route::get('/services', [\App\Http\Controllers\Api\CustomerApiController::class, 'services']);
                Route::get('/invoices', [\App\Http\Controllers\Api\CustomerApiController::class, 'invoices']);
                Route::get('/credit', [\App\Http\Controllers\Api\CustomerApiController::class, 'credit']);

                // آزمونِ اتصالِ ماژولِ WHMCS — عمداً با کم‌ترین دسترسیِ ممکن،
                // تا نماینده بتواند پیش از ساختنِ توکنِ نوشتنی هم تست کند.
                Route::get('/ping', [\App\Http\Controllers\Api\DomainApiController::class, 'ping']);
            });

        // ── دامنه: خواندن ──
        Route::middleware([$api.':domains:read', 'throttle:'.($rate['read'] ?? '120,1')])
            ->group(function () {
                Route::get('/domains', [\App\Http\Controllers\Api\DomainApiController::class, 'index']);
                Route::get('/domains/{domain}', [\App\Http\Controllers\Api\DomainApiController::class, 'show']);
            });

        // ── دامنه: استعلام (تماسِ واقعی با رجیسترار ⇒ سقفِ جداگانه) ──
        Route::middleware([$api.':domains:read', 'throttle:'.($rate['check'] ?? '60,1')])
            ->group(function () {
                Route::post('/domains/check', [\App\Http\Controllers\Api\DomainApiController::class, 'check']);
                Route::get('/tlds', [\App\Http\Controllers\Api\DomainApiController::class, 'tlds']);
            });

        // ── دامنه: مدیریتِ دامنهٔ موجود (پول خرج نمی‌کند) ──
        Route::middleware([$api.':domains:manage', 'throttle:'.($rate['write'] ?? '20,1')])
            ->group(function () {
                Route::put('/domains/{domain}/nameservers', [\App\Http\Controllers\Api\DomainApiController::class, 'nameservers']);
                Route::post('/domains/{domain}/lock', [\App\Http\Controllers\Api\DomainApiController::class, 'lock']);
                Route::post('/domains/{domain}/auto-renew', [\App\Http\Controllers\Api\DomainApiController::class, 'autoRenew']);
            });

        // ── دامنه: خرید (از اعتبار کسر می‌شود) ──
        Route::middleware([$api.':domains:write', 'throttle:'.($rate['write'] ?? '20,1')])
            ->group(function () {
                Route::post('/domains', [\App\Http\Controllers\Api\DomainApiController::class, 'register']);
                Route::post('/domains/{domain}/renew', [\App\Http\Controllers\Api\DomainApiController::class, 'renew']);
            });

        /*
        |----------------------------------------------------------------------
        | تونلِ WireGuard-روی-TCP — همان کارِ پنل، با توکن
        |----------------------------------------------------------------------
        |
        | مشتریِ سرورِ اکسیت اکانت‌های تونلش را از سامانهٔ خودش می‌سازد.
        | ⚠️ هیچ‌کدام از این‌ها به روتر دست نمی‌زند؛ پاسخ `router_command` دارد
        |    و اجرایش کارِ مشتری است (روتر از سمتِ ما در دسترس نیست).
        |
        | سقفِ نوشتن جدا و سخت‌گیرانه است: هر ساخت یک جفت‌کلیدِ تازه و یک
        | نوشتن در `meta` است، و برخلافِ دامنه پولی خرج نمی‌کند — یعنی چیزی
        | جز همین سقف جلوی یک حلقهٔ اشتباه را نمی‌گیرد.
        */
        Route::middleware([$api.':tunnel:read', 'throttle:'.($rate['read'] ?? '120,1')])
            ->group(function () {
                Route::get('/tunnel/servers', [\App\Http\Controllers\Api\TunnelApiController::class, 'servers']);
                Route::get('/tunnel/{service}/accounts', [\App\Http\Controllers\Api\TunnelApiController::class, 'accounts']);
                Route::get('/tunnel/{service}/agent', [\App\Http\Controllers\Api\TunnelApiController::class, 'agentStatus']);
            });

        Route::middleware([$api.':tunnel:write', 'throttle:20,1'])
            ->group(function () {
                Route::post('/tunnel/{service}/accounts', [\App\Http\Controllers\Api\TunnelApiController::class, 'issue']);
                Route::delete('/tunnel/{service}/accounts/{name}', [\App\Http\Controllers\Api\TunnelApiController::class, 'remove']);
                // صدورِ توکنِ ایجنت. سقفِ سخت‌گیرانه‌تر چون هر فراخوان توکنِ
                // قبلی را می‌کُشد — تکرارِ تصادفی نباید روترِ زنده را قطع کند.
                Route::post('/tunnel/{service}/agent', [\App\Http\Controllers\Api\TunnelApiController::class, 'agentEnroll'])
                    ->middleware('throttle:5,1');
            });
    });

/*
|----------------------------------------------------------------------
| دروازهٔ AIِ عمومی — سازگارِ OpenAI (M4-b)
|----------------------------------------------------------------------
|
| `POST /v1/chat/completions` — همان مسیری که SDKهای OpenAI پیش‌فرض
| می‌زنند، تا مشتری فقط base_url و کلید را عوض کند. احراز با همان
| توکنِ Bearerِ `CustomerApiToken` است ولی درایورِ مسیر،
| `Ai\V1ChatController` است: گیتِ admission مالِ `AiAdmission` است نه
| میدل‌ورِ `api/v1` (شرحِ کامل در هدرِ خودِ کنترلر).
|
| ⚠️ همان برداشتِ نشست/CSRF که `api/v1` دارد: تماس‌گیرنده یک برنامه
|    است نه مرورگر — بی‌نشست و بی‌کوکی. CSRF هم بی‌اثر می‌شود چون خودِ
|    میدل‌ورش برداشته می‌شود، نه اینکه استثنا شود.
|
| سقفِ نرخ: سطلِ همگانیِ `ai` (۱۲/دقیقه بر IP) — همان سقفِ چتِ سایت.
|    سقفِ per-token در M5 جدا می‌شود؛ تا آن‌جا این سقف جلوی حلقهٔ
|    بی‌کلیدِ پول‌خور را می‌گیرد و رزرو/TTL خودش سقفِ دوم است.
*/
Route::get('/api/ai-media/{id}', \App\Http\Controllers\Ai\AiMediaRelayController::class)
    ->where('id', '[a-f0-9]{64}')
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        \App\Http\Middleware\PageCache::class,
    ])->name('ai.media.relay');

Route::prefix('v1')
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    ])
    ->group(function () {
        Route::post('/chat/completions', [\App\Http\Controllers\Ai\V1ChatController::class, 'chat'])
            ->name('ai.v1.chat.completions');
        Route::post('/embeddings', [\App\Http\Controllers\Ai\V1ChatController::class, 'embeddings'])->name('ai.v1.embeddings');
        Route::post('/rerank', [\App\Http\Controllers\Ai\V1ChatController::class, 'rerank'])->name('ai.v1.rerank');
        Route::post('/inference', [\App\Http\Controllers\Ai\V1ChatController::class, 'native'])->name('ai.v1.native');
        Route::get('/inference/{id}', [\App\Http\Controllers\Ai\V1ChatController::class, 'nativeResult'])->where('id','[a-z0-9]{26}')->name('ai.v1.native.result');
        Route::get('/models', [\App\Http\Controllers\Ai\V1ModelsController::class, 'index'])->name('ai.v1.models');
    });

/*
 * مسیرهای «کششیِ» موتورِ هاستِ ایران (pull-agent) — بیرونِ closureِ سه‌زبانهٔ
 * سایت. احراز با هدرِ `X-Agent-Token` داخلِ کنترلر (Setting::getSecret). فقط
 * GET و فقط‌خواندنی؛ ایجنت هر چند دقیقه این‌ها را می‌خوانَد تا حالتِ مطلوب
 * (مسیرِ کشوریِ خروج + port-forward) را یاد بگیرد.
 */
/*
 * ایجنتِ روترِ **مشتری** — جدا از ایجنتِ هاستِ ایران، با احرازِ per-service.
 *
 * 🔴 نشست عمداً استارت نمی‌شود. هر روتر هر ۳۰ ثانیه می‌پرسد و هیچ کوکی‌ای
 * نمی‌فرستد، پس `StartSession` برای هر پیمایش یک نشستِ تازه می‌ساخت:
 * ۲۸۸۰ فایلِ نشستِ دورریز در روز به‌ازای هر مشتری، روی همان دیسکی که
 * `storage/` رویش است. هیچ خطایی هم تولید نمی‌کرد تا روزی که دیسک پر شود.
 *
 * CSRF هم با همان حذف می‌رود — و درست است: تماس‌گیرنده یک روتر است نه
 * مرورگر. محافظش `X-Agent-Token` است.
 */
Route::prefix('agent/tunnel')
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->middleware('throttle:120,1')
    ->group(function () {
        Route::get('pending', [\App\Http\Controllers\Agent\TunnelAgentController::class, 'pending']);
        Route::post('ack',    [\App\Http\Controllers\Agent\TunnelAgentController::class, 'ack']);
        Route::get('install', [\App\Http\Controllers\Agent\TunnelAgentController::class, 'install']);
    });

Route::prefix('agent')->group(function () {
    Route::get('countryroutes', [\App\Http\Controllers\Agent\PullController::class, 'countryRoutes']);
    Route::get('portforwards',  [\App\Http\Controllers\Agent\PullController::class, 'portForwards']);

    // آپ‌استریم‌های ثبت‌شده در پنل (رله‌ها + اکسیت‌های کشوریِ خودمان).
    // 🔴 تنها مسیری که اعتبارنامهٔ خام می‌دهد — چون میزبان برای dial لازمش دارد.
    // پاسخ `no-store` است و مثلِ بقیه پشتِ همان توکنِ ایجنت.
    Route::get('exitupstreams', [\App\Http\Controllers\Agent\PullController::class, 'exitUpstreams']);

    // سیاستِ شبکهٔ داخلیِ هر مهمان: `[{ip, lan}]`.
    // 🔴 مسیرِ جدا و نه کلیدِ تازه روی countryroutes — آن مسیر فقط ماشین‌های
    // دارای کشورِ خروج را دارد و بازکردنش، عاملِ موجود را با ردیف‌های `cc`ِ تهی
    // روبه‌رو می‌کرد.
    Route::get('guestpolicy', [\App\Http\Controllers\Agent\PullController::class, 'guestPolicy']);
    // تأییدِ اعمال. 🔴 ضربان می‌گوید «زنده‌ام»؛ این می‌گوید «کارت را کردم».
    Route::post('guestpolicy/ack', [\App\Http\Controllers\Agent\PullController::class, 'guestPolicyAck']);
});


// Tracking stores no public PII and never redeems a grant.
Route::get('/m/{token}', [\App\Http\Controllers\Account\OfferController::class, 'track'])
    ->where('token', '[a-f0-9]{48}')->middleware('throttle:60,1');


