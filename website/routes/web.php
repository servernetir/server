<?php

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

$site = function (): void {
    Route::get('/', [SiteController::class, 'home'])->name('home');
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

    // صفحهٔ فرودِ شخصیِ «طراحی سایت و زیرساخت» — مقصدِ لینکِ لینکدین/اینستاگرام.
    // ⚠️ عمداً در منوی اصلی نیست، ولی در نقشهٔ سایت **هست**: کلِ هدفش ورودیِ
    //    ارگانیک از «طراحی سایت در ارومیه» است و صفحهٔ بی‌نقشه دیرتر ایندکس می‌شود.
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
    });

    Route::post('/logout', [Auth\LoginController::class, 'logout'])->name('logout');

    Route::middleware(['auth:customer', \App\Http\Middleware\EnforceCustomerIp::class])->prefix('account')->name('account.')->group(function () {
        Route::get('/', [Account\AccountController::class, 'home'])->name('home');
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

        // سرورساز — مشتری خودش سرورِ مجازی می‌سازد: مکان → پلن → سیستم‌عامل/
        // نرم‌افزارِ آماده → دوره → نامِ سرور → پیش‌فاکتور → پرداخت → تحویلِ خودکار.
        // صفحاتِ عمومیِ سایت با ?location=…&plan=… به همین‌جا لینک می‌دهند، پس
        // مسیر و نامِ روت‌ها را عوض نکنید. ثبتِ سفارش محدودیتِ نرخ دارد چون هر
        // سفارش یک سرویسِ pending و یک پیش‌فاکتورِ واقعی می‌سازد.
        Route::get('/cloud-store', [Account\CloudStoreController::class, 'index'])->name('cloud.store');
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
        // سوییچِ کشورِ خروج توسطِ خودِ مشتری (فازِ A) — فقط برای سرورهای دارای اکسیت
        Route::post('/cloud/{service}/exit-country', [Account\CloudServerController::class, 'setExitCountry'])
            ->name('cloud.exit-country')->middleware('throttle:12,1');

        // اکانت‌های «WireGuard روی TCP» — فقط برای سرورهایی که پروفایلِ تونل دارند.
        Route::post('/cloud/{service}/tunnel', [Account\CloudServerController::class, 'issueTunnelAccount'])
            ->name('cloud.tunnel.issue')->middleware('throttle:12,1');
        Route::post('/cloud/{service}/tunnel/remove', [Account\CloudServerController::class, 'removeTunnelAccount'])
            ->name('cloud.tunnel.remove')->middleware('throttle:20,1');
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
        Route::get('/security', [Account\SecurityController::class, 'index'])->name('security');
        Route::post('/security/password/start', [Account\SecurityController::class, 'passwordStart'])->name('security.pw.start')->middleware('throttle:resend');
        Route::post('/security/password', [Account\SecurityController::class, 'passwordVerify'])->name('security.pw')->middleware('throttle:otp');
        Route::post('/security/ip', [Account\SecurityController::class, 'ipStore'])->name('security.ip')->middleware('throttle:forms');
        Route::post('/security/ip/{rule}/delete', [Account\SecurityController::class, 'ipDestroy'])->name('security.ip.delete');
        Route::post('/security/ip-mode', [Account\SecurityController::class, 'ipMode'])->name('security.ipmode')->middleware('throttle:forms');
        Route::post('/security/api-token', [Account\SecurityController::class, 'tokenStore'])->name('security.token')->middleware('throttle:forms');
        Route::post('/security/api-token/{token}/delete', [Account\SecurityController::class, 'tokenDestroy'])->name('security.token.delete');

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
Route::prefix('en')->name('en.')->middleware('locale:en')->group($urmia);
Route::prefix('tr')->name('tr.')->middleware('locale:tr')->group($urmia);

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

/*
| /healthz — «تفکیک‌کنندهٔ قطعی»ِ ممیزی ۷ (CTO): یک پاسخِ ثابت، بدونِ دیتابیس،
| بدونِ view و بدونِ کشِ صفحه. اگر همین مسیر هم کند شد، مشکل زیرِ لاراول است
| (PHP-FPM/OPcache/CPU steal)؛ اگر نشد، مشکل در لایهٔ اپلیکیشن است.
|
| ⚠️ no-store عمدی است: این مسیر باید **هر بار** کلِ بوتِ فریم‌ورک را طی کند
| وگرنه چیزی را نمی‌سنجد. Server-Timing از PageCache::tag روی آن نمی‌نشیند
| (storable نیست)، پس زمانِ اپ را خودش می‌نویسد.
*/
Route::get('/healthz', function () {
    $t0 = defined('LARAVEL_START') ? LARAVEL_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

    return response('ok', 200, [
        'Content-Type'  => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'no-store',
        'X-Robots-Tag'  => 'noindex',
        'Server-Timing' => 'app;dur='.(int) round((microtime(true) - (float) $t0) * 1000),
    ]);
})->name('healthz');

/*
| llms.txt — معرفیِ سرورنت به مدلِ زبانی، نه به خزندهٔ جست‌وجو.
|
| چرا: امروز بخشی از خریدارها به‌جای گوگل از ChatGPT و Perplexity می‌پرسند
| «هاستِ ایرانیِ خوب کدام است». آن مدل‌ها این فایل را می‌خوانند تا بفهمند
| این سایت چیست و کدام صفحه‌ها معتبرند. نبودش یعنی موجودیتِ «سرورنت» برای
| مدل قفل نمی‌شود و در پاسخ‌ها اسمی از ما نمی‌آید.
|
| عمداً از روتِ لاراول می‌آید نه فایلِ ثابت: فهرستِ محصولات و مکان‌ها زنده
| است و فایلِ دستی همان روزِ اول کهنه می‌شود.
*/
Route::get('/llms.txt', [SiteController::class, 'llms']);


// تولید و انتشار محتوای برنامه‌ریزی‌شده (کران روزانه یا فراخوانی دستی)
Route::middleware('throttle:6,1')->get('/system/content/{token}', function (string $token) {
    $expected = (string) env('DEPLOY_TOKEN', '');
    // بدون DEPLOY_TOKEN در .env این روت اصلاً وجود ندارد — توکن هاردکد یعنی
    // هرکس به مخزن دسترسی دارد می‌تواند مهاجرت و تولید محتوای پولی را اجرا کند.
    abort_if($expected === '' || ! hash_equals($expected, $token), 404);
    @set_time_limit(600);
    $n = max(0, min(5, (int) request('n', 1)));
    $out = [];

    // پیش‌نمایش کیفیت آخرین مقاله‌های ساخته‌شده (فقط خواندنی)
    if (request()->boolean('preview')) {
        return response()->json(\App\Models\Post::with('translations')->latest('id')->take(3)->get()
            ->map(fn ($p) => [
                'slug'    => $p->slug,
                'status'  => $p->status,
                'due'     => optional($p->published_at)->toDateTimeString(),
                'locales' => $p->translations->pluck('locale')->all(),
                'fa'      => ($t = $p->translations->firstWhere('locale', 'fa')) ? [
                    'title'    => $t->title,
                    'excerpt'  => $t->excerpt,
                    // str_word_count روی فارسی همیشه صفر می‌دهد چون فقط حروف لاتین را
                    // کلمه می‌شناسد؛ با آن، پیش‌نمایشِ کیفیت هر مقاله‌ی سالم را «خالی» نشان می‌داد.
                    'words'    => word_count_fa($t->content),
                    'chars'    => mb_strlen(strip_tags($t->content)),
                    'h2'       => substr_count($t->content, '<h2'),
                    'code'     => substr_count($t->content, '<pre'),
                    'tags'     => $t->tags,
                    'opening'  => mb_substr(trim(strip_tags($t->content)), 0, 220),
                ] : null,
            ]), 200, [], JSON_UNESCAPED_UNICODE);
    }

    if (request()->boolean('fill')) {
        \Illuminate\Support\Facades\Artisan::call('content:translate-missing', ['--limit' => 3]);
        $out['fill'] = trim(\Illuminate\Support\Facades\Artisan::output());
    }

    if ($n > 0) {
        $plan = request('plan') === 'docs' ? 'docs-plan' : 'plan';
        $args = ['--limit' => $n, '--plan' => $plan];
        if ($plan === 'docs-plan') {
            $args['--daily'] = true;      // پایگاه دانش: هر مطلب در یک روز جداگانه
        }
        \Illuminate\Support\Facades\Artisan::call('content:generate', $args);
        $out['generate'] = trim(\Illuminate\Support\Facades\Artisan::output());
    }
    \Illuminate\Support\Facades\Artisan::call('content:publish-due');
    $out['publish'] = trim(\Illuminate\Support\Facades\Artisan::output());
    $out['stats'] = [
        'published' => \App\Models\Post::where('type', 'blog')->where('status', 'published')->count(),
        'scheduled' => \App\Models\Post::where('type', 'blog')->where('status', 'draft')->count(),
    ];

    return response()->json($out, 200, [], JSON_UNESCAPED_UNICODE);
});

/*
 * صفحهٔ آماده‌سازی دیتابیس.
 *
 * توکن با POST گرفته می‌شود نه در مسیر URL — چون هر چیزی که در URL بیاید در
 * لاگ سرور و Cloudflare و تاریخچهٔ مرورگر ثبت می‌شود. یک بار سر کلید DeepSeek
 * همین اتفاق افتاد.
 *
 * خودِ صفحه بدون توکن هیچ کاری نمی‌کند، پس عمومی بودنش خطری ندارد.
 */
Route::get('/system/setup', fn () => response(view('system.setup'))
    // این صفحه نباید در گوگل بیاید و نباید در کش واسطه‌ها بماند
    ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
    ->header('Cache-Control', 'no-store, private')
)->middleware('throttle:tools');

/*
 * وضعیت آمادگی MariaDB — فقط خواندنی و بدون توکن.
 *
 * عمداً هیچ اعتبارنامه، نام دیتابیس یا نام کاربری برنمی‌گرداند؛ فقط اینکه
 * اتصال برقرار است و چند جدول و چند ردیف دارد. این‌قدر بی‌خطر هست که بدون
 * توکن باشد، و اجازه می‌دهد آمادگی را بدون فرستادن رمز در هیچ کانالی بسنجیم.
 */
/*
| وضعیت پیامک — فقط بولین و نام رویداد. هیچ توکن، شماره یا متنی برنمی‌گرداند،
| پس بی‌خطر است. لازم است چون به SSH سرور دسترسی نداریم و بدون آن نمی‌شود
| فهمید «کد نرفت» یعنی کلید غلط است یا الگو تعریف نشده.
*/
Route::middleware('throttle:tools')->get('/system/sms-status', function () {
    $cfg    = (array) config('services.sms.ippanel');
    $sender = app(\App\Services\Sms\SmsSender::class);
    $active = $sender->name();

    return response()->json([
        'driver_requested' => (string) config('services.sms.driver'),
        'driver_active'    => $active,
        // «log» یعنی پیامک واقعی نمی‌رود و کد فقط در فایل لاگ می‌نشیند
        'sends_real_sms'   => $active !== 'log',
        'has_key'          => filled($cfg['token'] ?? null),
        'has_from'         => filled($cfg['from'] ?? null),
        'pattern_variable' => (string) ($cfg['variable'] ?? 'code'),
        'patterns_defined' => array_keys(array_filter((array) ($cfg['patterns'] ?? []))),
        // اگر false باشد، کد ورود از مسیر پیام آزاد می‌رود و ممکن است دیر برسد
        'otp_uses_pattern' => filled($cfg['patterns']['otp'] ?? null),

        // شکل خط فرستنده همان‌طور که واقعاً به آی‌پی‌پنل می‌رود — یکی از
        // رایج‌ترین علت‌های رد شدن، خط فرستندهٔ اشتباه است
        'from_as_sent'     => $cfg['from'] ? \App\Services\Sms\IppanelSender::preview((string) $cfg['from']) : null,

        /*
        | تشخیص شماره‌های آزمایشی.
        |
        | خود شماره‌ها برنمی‌گردند (اطلاعات شخصی‌اند). سه عدد کافی است تا
        | بفهمیم مشکل کجاست:
        |   raw_len = 0            → متغیر اصلاً در .env نیست یا نامش فرق دارد
        |   raw_len > 0, valid = 0 → هست ولی قالبش اشتباه است (جداکننده یا شکل شماره)
        |   valid > 0              → سالم
        */
        'test_numbers' => (function () {
            $raw   = (string) config('services.sms.test_numbers', '');
            $otp   = app(\App\Services\Otp\OtpService::class);
            $parts = array_filter(array_map('trim', explode(',', $raw)));

            return [
                'raw_len' => strlen($raw),
                'entries' => count($parts),
                'valid'   => count(array_filter($parts, fn ($n) => $otp->normalize('sms', $n) !== '')),

                // false یعنی فایل config روی سرور قدیمی است (تقصیر دیپلوی من)
                'key_in_config' => array_key_exists('test_numbers', (array) config('services.sms')),
                // طول مقدار مستقیم از .env، بدون عبور از config
                'env_len'       => strlen((string) env('OTP_TEST_NUMBERS')),
            ];
        })(),

        // آخرین خطای واقعی خود سرویس. بدون توکن و بدون شمارهٔ گیرنده.
        'last_error'       => \Illuminate\Support\Facades\Cache::get('sms:last_error'),

        /*
        | وضعیت پل — وقتی درایور «queue» است، این مهم‌ترین بخش است.
        |
        | last_pull  آخرین باری که فرستندهٔ ایران سر زد. اگر خالی یا کهنه
        |            باشد، یعنی کران آن‌طرف اجرا نمی‌شود یا نمی‌تواند به ما
        |            برسد — و هیچ پیامکی نخواهد رفت.
        | queue      شمارش وضعیت‌های صف. «failed» یعنی رسید ولی آی‌پی‌پنل
        |            ردش کرد (معمولاً نام متغیر الگو).
        */
        /*
        | رلهٔ بله — چرا درایور فعال نشد.
        |
        | 🔴 فقط **بولین**، هرگز مقدار. سه کلیدِ راز این‌جاست و این روت عمومی
        |    است؛ چاپِ حتی چند حرفِ اولشان یعنی لو رفتنِ راز.
        |
        | ⚠️ `duplicate_keys` مهم‌ترین ستون است: phpDotenv **اولین** مقدارِ هر
        |    کلید را نگه می‌دارد. اگر همان کلید دو بار در .env باشد (یکی خالی
        |    از قالبِ اولیه، یکی پرشده در انتها)، مقدارِ خالی برنده می‌شود و
        |    درایور بی‌صدا به «لاگ» برمی‌گردد — دقیقاً همان تله‌ای که یک‌بار
        |    `SESSION_DOMAIN` را بی‌اثر کرد.
        */
        'bale_relay' => (function () {
            $keys = ['BALE_OTP_SENDER_BOT_TOKEN', 'BALE_OTP_RELAY_CHAT_ID', 'BALE_OTP_RELAY_SECRET'];
            $lines = [];
            $dupes = [];

            try {
                $env = @file_get_contents(base_path('.env')) ?: '';

                foreach ($keys as $k) {
                    /*
                    | 🔴 فقط **شمارشِ خط**، نه مقدار.
                    |
                    | ۰  → کلید اصلاً در .env نیست (یا املایش فرق دارد)
                    | ۱  → خط هست؛ اگر config خالی باشد یعنی مقدارش خالی است یا
                    |      نقل‌قول/`#` بریده‌اش
                    | ۲+ → تلهٔ phpDotenv: **اولین** مقدار برنده می‌شود، پس خطِ
                    |      دومِ پرشده بی‌اثر است — همان چیزی که SESSION_DOMAIN را خورد
                    */
                    $n = preg_match_all('/^\s*'.preg_quote($k, '/').'\s*=/m', $env);
                    $lines[$k] = $n;

                    if ($n > 1) {
                        $dupes[] = $k.' ×'.$n;
                    }
                }
            } catch (\Throwable) {
                $lines = ['unreadable' => -1];
            }

            /*
            | خواندنِ **مستقیم** از env، بی‌عبور از config.
            |
            | اگر این `true` باشد و ستونِ config `false`، یعنی خودِ .env سالم است
            | و مشکل در لایهٔ config است (کشِ config یا فایلِ کهنه) — دو علتِ
            | کاملاً متفاوت که بی‌این ستون از هم قابلِ تشخیص نیستند.
            */
            $direct = [];

            foreach ($keys as $k) {
                $direct[$k] = filled(env($k));
            }

            /*
            | ⚠️ این‌جا `app(BaleRelaySender::class)` **صدا زده نمی‌شود**: سازندهٔ
            |    آن سه رشته می‌گیرد و در کانتینر بسته نشده، پس autowire شکست
            |    می‌خورد و این روتِ عیب‌یابی خودش ۵۰۰ می‌داد — دقیقاً وقتی که
            |    برای عیب‌یابی لازمش داریم. `enabled()` هم چیزی جز `filled()`
            |    روی همین سه مقدار نیست، پس مستقیم می‌سنجیمشان.
            */
            return [
                'bot_token_set'   => filled(config('services.sms.bale_relay.bot_token')),
                'chat_id_set'     => filled(config('services.sms.bale_relay.chat_id')),
                'secret_set'      => filled(config('services.sms.bale_relay.secret')),
                'env_lines'       => $lines,   // ۰ = نیست · ۱ = هست · ۲+ = تله
                'env_direct'      => $direct,  // بی‌عبور از config
                'env_path'        => base_path('.env'),
                'env_exists'      => is_file(base_path('.env')),
                'duplicate_keys'  => $dupes,   // ⚠️ غیرِخالی = اولین مقدار برنده شده
            ];
        })(),

        'bridge' => [
            'last_attempt' => \Illuminate\Support\Facades\Cache::get('smsbridge:last_attempt'),
            'last_deny'    => \Illuminate\Support\Facades\Cache::get('smsbridge:last_deny'),
            'last_pull'    => \Illuminate\Support\Facades\Cache::get('smsbridge:last_pull'),
            'poller_alive' => (function () {
                $at = \Illuminate\Support\Facades\Cache::get('smsbridge:last_pull');

                return $at !== null && \Illuminate\Support\Carbon::parse($at)->gt(now()->subMinutes(3));
            })(),
            'queue' => \Illuminate\Support\Facades\Schema::hasTable('sms_outbox')
                ? \App\Models\SmsOutbox::selectRaw('status, count(*) as n')
                    ->groupBy('status')->pluck('n', 'status')
                : null,
        ],
    ]);
});

/*
| در دسترس بودن سرویس‌های بیرونی — از دید خود سرور.
|
| لازم است چون هر سه سرویس ایرانی‌اند و گاهی قطع می‌شوند، و ماشین توسعه
| شبکهٔ قابل اتکایی ندارد (به هر پورتی «وصل» می‌شود). بدون این، «کار نکرد»
| را نمی‌شود از «سرویس پایین است» تفکیک کرد.
|
| فقط HEAD/GET سبک می‌زند و هیچ استعلام پولی انجام نمی‌دهد.
*/
/*
| تشخیص OpenProvider — موقتی.
|
| خروجی فقط بولین و آی‌پی است (نه رمز، نه توکن)، پس بدون DEPLOY_TOKEN هم امن
| است و کارفرما/ما می‌توانیم مستقیم صدایش بزنیم. دو چیز را جواب می‌دهد:
|   • آی‌پی خروجیِ سرور — همانی که باید در allowlistِ API اوپن‌پروایدر باشد
|   • آیا با اعتبارنامهٔ .env احراز هویت می‌شود (code 0) یا رد (196=IP/رمز)
*/
Route::middleware('throttle:tools')->get('/system/openprovider', function () {
    $client = app(\App\Services\Domain\OpenProviderClient::class);

    // آی‌پی خروجی: از چند سرویس، اولین جوابِ معتبر
    $outIp = null;
    foreach (['https://api.ipify.org', 'https://ifconfig.me/ip', 'https://icanhazip.com'] as $svc) {
        try {
            $resp = \Illuminate\Support\Facades\Http::timeout(8)->get($svc);
            if ($resp->ok() && filter_var(trim($resp->body()), FILTER_VALIDATE_IP)) {
                $outIp = trim($resp->body());
                break;
            }
        } catch (\Throwable) {
        }
    }

    $enabled = $client->enabled();
    $auth = 'skipped'; $sampleCode = null; $sampleDesc = null;

    /*
    | 🔴 «کاوش» فقط برای **مدیرِ واردشده**.
    |
    | قبلاً `?probe=1` یک پرچمِ سادهٔ کوئری بود روی یک روتِ کاملاً عمومی، و
    | کامنتِ بالایش می‌گفت «فقط دستی زده می‌شود» — ولی هیچ چیزی در کد این را
    | تضمین نمی‌کرد. یعنی هر کسی روی اینترنت می‌توانست با یک URL:
    |
    |   • تلاشِ ورودِ واقعی به اوپن‌پروایدر بزند، از آی‌پیِ اصلیِ سرور
    |   • authorityِ واقعیِ زرین‌پال با merchant_idِ زنده بسازد
    |   • با ?mailtest=1 صفِ ارسالِ SMTP ما را بسوزاند
    |
    | و اولی دقیقاً همان کاری است که یک‌بار حسابِ اوپن‌پروایدر را علامت‌دار کرد.
    | با ۴۰ درخواست در دقیقه به‌ازای هر آی‌پی، یک نفر می‌توانست حساب را قفل کند
    | و آن‌وقت **هیچ** دامنه‌ای ثبت نمی‌شد.
    |
    | ⚠️ توکن در URL راه‌حل نبود (لاگِ سرور و کلادفلر و تاریخچهٔ مرورگر ثبتش
    | می‌کنند — یک‌بار کلید همین‌طور لو رفت). نشستِ مدیر هم همان چیزی است که
    | کارفرما در عمل دارد، چون کوکی روی `.servernet.cloud` مشترک است.
    |
    | بخشِ فقط‌خواندنی (آی‌پیِ خروجی، «اعتبارنامه هست یا نه») عمداً عمومی
    | مانده: هیچ تماسِ بیرونی نمی‌زند و برای عیب‌یابیِ allowlist لازم است.
    */
    $isAdmin = auth('web')->check() && auth('web')->user()?->isAdmin();
    $probe = request()->boolean('probe') && $isAdmin;

    if ($enabled && $probe) {
        // ورودِ خام تا کد واقعیِ پاسخ را ببینیم (196 = IP یا رمز رد شد). فقط
        // کد و توضیح برمی‌گردد، نه رمز.
        try {
            $login = \Illuminate\Support\Facades\Http::timeout(15)->asJson()->post(
                rtrim((string) config('services.openprovider.base_url'), '/').'/auth/login',
                ['username' => config('services.openprovider.username'), 'password' => config('services.openprovider.password')],
            );
            $sampleCode = (int) data_get($login->json(), 'code', -1);
            $sampleDesc = (string) data_get($login->json(), 'desc', '');
            $auth = $sampleCode === 0 ? 'ok' : 'failed';
        } catch (\Throwable $e) {
            $auth = 'error';
            $sampleDesc = 'network: '.$e->getMessage();
        }
    }

    // آزمون واقعیِ زرین‌پال — آیا merchant_id واقعاً پرداخت می‌سازد؟ (code 100 = بله)
    // authority می‌سازد ولی تا کاربر پرداخت نکند هیچ پولی جابه‌جا نمی‌شود.
    $zarin = null;
    $zMerchant = (string) config('services.zarinpal.merchant_id', '');
    if ($zMerchant !== '' && $probe) {
        try {
            $zr = \Illuminate\Support\Facades\Http::timeout(15)->asJson()->post(
                'https://api.zarinpal.com/pg/v4/payment/request.json',
                ['merchant_id' => $zMerchant, 'amount' => 10000, 'callback_url' => 'https://console.servernet.cloud/payment/callback/zarinpal', 'description' => 'آزمون اتصال'],
            );
            $j = $zr->json();
            $zarin = [
                'code'    => data_get($j, 'data.code', data_get($j, 'errors.code', 'n/a')),
                'message' => data_get($j, 'data.message', data_get($j, 'errors.message', '')),
                'got_authority' => filled(data_get($j, 'data.authority')),
            ];
        } catch (\Throwable $e) {
            $zarin = ['error' => $e->getMessage()];
        }
    }

    // وضعیت درگاه‌های پرداخت — چرا زرین‌پال ۳۰۲ می‌دهد و بله دیده نمی‌شود
    $gateways = [];
    try {
        $reg = app(\App\Services\Payment\GatewayRegistry::class);
        foreach ($reg->all() as $g) {
            $gateways[$g->key()] = [
                'enabled'  => $g->enabled(),
                'currency' => $g->currency(),
            ];
        }
    } catch (\Throwable $e) {
        $gateways = ['error' => $e->getMessage()];
    }

    $zarinpal_sandbox = (bool) config('services.zarinpal.sandbox');

    // آزمونِ مسیرِ واقعیِ گیت‌وی زرین‌پال (همان کدی که کاربر می‌زند) — با ?probe=1
    $gw_start = null;
    if ($probe) {
        try {
            $cust = new \App\Models\Customer(['phone' => '09120000000', 'email' => 't@servernet.cloud']);
            $inv  = new \App\Models\Invoice(['kind' => 'service', 'number' => 'DIAG-TEST', 'currency_code' => 'IRT']);
            $pay  = new \App\Models\Payment(['amount' => 10000, 'currency_code' => 'IRT']);
            $pay->setRelation('customer', $cust);
            $pay->setRelation('invoice', $inv);
            $z = app(\App\Services\Payment\GatewayRegistry::class)->get('zarinpal');
            $r = $z?->start($pay, 'https://console.servernet.cloud/payment/callback/zarinpal');
            $gw_start = $r === null ? 'gateway zarinpal ثبت نشده' : [
                'ok'          => $r->ok,
                'has_redirect' => $r->redirectUrl !== null,
                'redirect_host' => $r->redirectUrl ? parse_url($r->redirectUrl, PHP_URL_HOST) : null,
                'error'       => $r->error,
                'error_code'  => $r->errorCode ?? null,
            ];
        } catch (\Throwable $e) {
            $gw_start = ['exception' => $e->getMessage()];
        }
    }

    // وضعیت وب‌هوک بله — اگر url خالی باشد، ربات به /start جواب نمی‌دهد و
    // دکمهٔ اشتراک شماره نمی‌آید (علتِ «گزینه‌ای نمی‌بینم»)
    $bale_webhook = null;
    if ($probe && filled(config('services.bale.token'))) {
        try {
            $tok  = config('services.bale.token');
            $base = rtrim((string) config('services.bale.base', 'https://tapi.bale.ai'), '/');
            $wh   = \Illuminate\Support\Facades\Http::timeout(12)->get("{$base}/bot{$tok}/getWebhookInfo");
            $bale_webhook = [
                'url_set'  => filled(data_get($wh->json(), 'result.url')),
                'url_host' => ($u = data_get($wh->json(), 'result.url')) ? parse_url($u, PHP_URL_HOST).parse_url($u, PHP_URL_PATH) : null,
                'pending'  => data_get($wh->json(), 'result.pending_update_count'),
            ];
        } catch (\Throwable $e) {
            $bale_webhook = ['error' => $e->getMessage()];
        }
    }

    return response()->json([
        'creds_present'      => $enabled,
        'server_outgoing_ip' => $outIp,
        'auth'               => $auth,
        'sample_code'        => $sampleCode,   // 0=موفق، 196=رد (IP یا رمز)
        'sample_desc'        => $sampleDesc,
        'gateways'           => $gateways,     // enabled=false یعنی اعتبارنامه‌اش ناقص است
        'zarinpal_test'      => $zarin,        // code 100 = merchant_id سالم است
        'zarinpal_sandbox'   => $zarinpal_sandbox,   // اگر true با merchantِ واقعی، درگاه باز نمی‌شود!
        'zarinpal_gw_start'  => $gw_start,     // آزمونِ مسیرِ واقعیِ کد (?probe=1)
        'bale_webhook'       => $bale_webhook, // url_set=false یعنی ربات جواب نمی‌دهد (?probe=1)
        // پیکربندی ارسال کد ورود — نام درایورها (نه رمز). برای عیب‌یابیِ
        // «ورود گیر می‌کند / کد نمی‌رود»
        'otp_channels'       => [
            'sms_driver'          => config('services.sms.driver'),      // queue = غیرمسدود، ippanel = تماس مستقیم
            'sms_relay_set'       => filled(config('services.sms.relay_url')) && filled(config('services.sms.relay_secret')),
            'mail_mailer'         => config('mail.default'),             // log = ارسال نمی‌شود، smtp = واقعی
            // کد ورود صریحاً از میلر smtp می‌رود؛ این‌ها می‌گویند آن پیکربندی هست یا نه
            'smtp_host_set'       => filled(config('mail.mailers.smtp.host')),
            'smtp_host'           => config('mail.mailers.smtp.host'),   // هاست راز نیست
            'smtp_port'           => config('mail.mailers.smtp.port'),
            'smtp_user_set'       => filled(config('mail.mailers.smtp.username')),
            'mail_from'           => config('mail.from.address'),
            'bale_token_set'      => filled(config('services.bale.token')),
        ],
        // ?mailtest=1 یک ایمیل تستِ واقعی فقط به آدرسِ فرستندهٔ خودمان می‌فرستد
        // (نه به آدرس دلخواه، تا رله‌ی اسپم نشود) و نتیجه را می‌گوید — تأیید
        // اینکه SMTP (هاست/رمز) واقعاً کار می‌کند، نه فقط سوییچ.
        // کلیدهای MAIL که واقعاً در محیط بارگذاری شده‌اند — فقط نامِ کلید به hex
        // (نه مقدار، نه رمز). اگر hex یک کلید با e2808e/e2808f شروع شود یعنی
        // کاراکتر نامرئیِ RTL چسبیده؛ اگر هیچ کلیدی نباشد یعنی خطوط بارگذاری نشده.
        // ⚠️ فقط برای مدیر: نامِ هاستِ SMTP و آدرسِ فرستنده راز نیستند، ولی
        //    دادنِ نقشهٔ زیرساختِ ایمیل به هر بازدیدکننده کارِ فیشینگ و
        //    جعلِ فرستنده را آسان می‌کند.
        'mail_env_keys'      => ! $isAdmin ? 'برای دیدنِ این بخش با حسابِ مدیر وارد شوید' : (function () {
            $out = [];
            foreach (array_merge($_ENV, $_SERVER) as $k => $v) {
                if (is_string($k) && stripos($k, 'MAIL') !== false) {
                    $out[] = ['key' => preg_replace('/[^\x20-\x7E]/', '?', $k), 'hex' => bin2hex($k)];
                }
            }

            return $out ?: 'هیچ کلیدِ MAIL در محیط نیست — خطوط MAIL بارگذاری نشده‌اند (فایل/ذخیره را چک کنید)';
        })(),
        // مقدارِ خامِ کلیدهای غیرمحرمانه (نه یوزر/رمز) — از env() و از $_ENV
        // مستقیم، تا معلوم شود env() چه می‌بیند
        'mail_env_values'    => ! $isAdmin ? 'برای دیدنِ این بخش با حسابِ مدیر وارد شوید' : [
            'env(MAIL_MAILER)'   => env('MAIL_MAILER'),
            'env(MAIL_HOST)'     => env('MAIL_HOST'),
            'env(MAIL_PORT)'     => env('MAIL_PORT'),
            'env(MAIL_ENCRYPTION)' => env('MAIL_ENCRYPTION'),
            'env(MAIL_SCHEME)'   => env('MAIL_SCHEME'),
            'env(MAIL_FROM_ADDRESS)' => env('MAIL_FROM_ADDRESS'),
            'ENV[MAIL_HOST]'     => $_ENV['MAIL_HOST'] ?? '(غایب در $_ENV)',
            'SERVER[MAIL_HOST]'  => $_SERVER['MAIL_HOST'] ?? '(غایب در $_SERVER)',
            'getenv(MAIL_HOST)'  => getenv('MAIL_HOST') === false ? '(getenv=false)' : getenv('MAIL_HOST'),
        ],
        // ⚠️ ارسالِ واقعی هم فقط برای مدیر — وگرنه هر بازدید یک ایمیل می‌فرستد
        //    و سهمیهٔ SMTP را می‌سوزاند.
        'mail_test'          => (request()->boolean('mailtest') && $isAdmin) ? (function () {
            $to = config('mail.from.address');
            if (! filled($to)) {
                return 'MAIL_FROM_ADDRESS تنظیم نشده';
            }
            try {
                \Illuminate\Support\Facades\Mail::mailer('smtp')->raw('تست اتصال SMTP سرورنت', function ($m) use ($to) {
                    $m->to($to)->subject('تست SMTP');
                });

                return 'OK — ایمیل تست به '.$to.' فرستاده شد (اینباکس/اسپم را ببینید)';
            } catch (\Throwable $e) {
                return 'خطا: '.$e->getMessage();
            }
        })() : ($isAdmin ? 'برای تست ارسال ?mailtest=1 اضافه کنید' : 'نیازمندِ ورودِ مدیر'),
        'admin'              => $isAdmin,
        'hint'               => 'اوپن‌پروایدر: sample_code=0 یعنی وصل شد، 196 یعنی IP/رمز رد شد. زرین‌پال: code=100 یعنی درگاه سالم و ۳۰۲ همان هدایت درست به صفحهٔ پرداخت است.'
            .($isAdmin ? '' : ' ⚠️ probe و mailtest فقط با ورودِ مدیر کار می‌کنند — تلاشِ ورودِ پیاپی حسابِ رجیسترار را قفل می‌کند.'),
    ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
});

/*
|--------------------------------------------------------------------------
| 🔴 ۱۰۰ ثانیه کارِ بیرونی، روی یک روتِ **عمومی**
|--------------------------------------------------------------------------
|
| اندازه‌گیریِ واقعی روی پروداکشن (مرداد ۱۴۰۵): این روت **۵۲٫۸ ثانیه** طول
| می‌کشید و ۲۰۰ برمی‌گرداند. بدترین‌حالتش بیشتر است — پنج هدف × ۱۲ ثانیه،
| به‌علاوهٔ نگهبانِ رله ۱۵ و امضاشده ۲۵ ⇒ تا ۱۰۰ ثانیه، که پشتِ Cloudflare
| اصلاً ۵۰۴ می‌گیرد (همان قاعدهٔ ثبت‌شده دربارهٔ درخواستِ بی‌خروجی).
|
| و `throttle:tools` یعنی **۴۰ درخواست در دقیقه از یک آی‌پی، بدونِ هیچ
| احرازی**. پس یک نفر به‌تنهایی می‌توانست ده‌ها پروسهٔ PHP را هرکدام ~۵۳
| ثانیه اشغال کند و هم‌زمان ۷ تماسِ خروجی به آی‌پی‌پنل و زرین‌پال و زحل
| بفرستد. ابزارِ پایش، خودش اهرمِ از‌کاراندازی شده بود.
|
| ⚠️ راهِ حل **بستنِ روت نیست**: این نشانی هدفِ پایشِ بیرونیِ ماست و پشتِ
| احراز که برود، پایش کور می‌شود. پس نتیجه کش می‌شود — تماس‌ها حداکثر یک بار
| در هر پنجره می‌روند و بقیهٔ درخواست‌ها همان عکسِ تازه را می‌گیرند.
|
| ⚠️ اگر خودِ کش خطا داد، سنجش **زنده** اجرا می‌شود نه اینکه صفحه بشکند:
| کش روی همان دیتابیسی است که این روت قرار است سلامتش را گزارش کند، و
| وابسته‌کردنِ گزارش به آن همان تلهٔ «ناظری که روی چیزِ تحتِ نظارت نشسته» است.
*/
Route::middleware('throttle:tools')->get('/system/health', function () {
    // ⚠️ نامش `$probe` نیست: داخلِ همین بلوک، `$probe` متغیرِ محلیِ سنجشِ
    // رله است و هم‌نامی دو چیزِ متفاوت را شبیهِ هم می‌کند.
    $runProbes = function () {
    $targets = [
        'ippanel'  => 'https://edge.ippanel.com/v1/api/send',
        'zohal'    => rtrim((string) config('services.zohal.base_url'), '/').'/api/v0/services/',
        'zarinpal' => 'https://api.zarinpal.com/pg/v4/payment/request.json',
        // آیا سرور ایرانی خودمان اصلاً از آلمان در دسترس است؟ اگر نه، هیچ
        // مسیر رابطی کار نمی‌کند و باید جهت اتصال برعکس شود.
        'servernet_ir' => 'https://servernet.ir/',
        'bale' => 'https://tapi.bale.ai/bot000:test/getMe',
    ];

    $out = [];

    foreach ($targets as $name => $url) {
        $started = microtime(true);

        try {
            // POST با بدنهٔ خالی: هیچ تراکنشی نمی‌سازد ولی نشان می‌دهد
            // سرویس زنده است یا جلوی دروازه‌اش خطا می‌دهد
            $res  = \Illuminate\Support\Facades\Http::timeout(12)->asJson()->post($url, []);
            $code = $res->status();
            // ۵۰۲/۵۰۳ یعنی خود سرویس پایین است؛ ۴۰۰/۴۰۱/۴۲۲ یعنی زنده است و
            // فقط ورودی ما را نپذیرفته — که برای این بررسی «سالم» است
            $up = $code < 500;
        } catch (\Throwable $e) {
            $code = 0;
            $up   = false;
        }

        $out[$name] = [
            'up'     => $up,
            'http'   => $code,
            'ms'     => (int) ((microtime(true) - $started) * 1000),
        ];
    }

    /*
    | رابط سرور ایران — سه لایه، از ارزان به گران:
    |
    |   guard   درخواست بدون امضا باید رد شود. یعنی فایل نصب است و
    |           نگهبانی می‌کند. هیچ تماسی با آی‌پی‌پنل نمی‌گیرد.
    |   signed  درخواست امضاشده با بدنه‌ای که آی‌پی‌پنل قطعاً رد می‌کند.
    |           یعنی کل تونل کار می‌کند — بدون اینکه پیامکی برود.
    |
    | عمداً هیچ پیامک واقعی فرستاده نمی‌شود؛ sending_type بی‌معنی است.
    */
    $relayUrl    = (string) config('services.sms.relay_url');
    $relaySecret = (string) config('services.sms.relay_secret');

    if ($relayUrl !== '' && $relaySecret !== '') {
        $probe = ['guard' => null, 'signed' => null];

        // آدرس عمومی است و رازی نیست؛ نمایشش تشخیص غلط‌بودن مسیر را ممکن می‌کند
        $probe['url'] = $relayUrl;

        try {
            $r = \Illuminate\Support\Facades\Http::timeout(15)->asJson()->post($relayUrl, []);
            // ۴۰۱ یعنی دقیقاً همان چیزی که باید: امضا نداشتی، رد شدی
            $probe['guard'] = ['ok' => $r->status() === 401, 'http' => $r->status(),
                               'reason' => data_get($r->json(), 'reason'),
                               'body' => mb_substr(strip_tags($r->body()), 0, 120)];
        } catch (\Throwable $e) {
            // پیام واقعی cURL — بدون آن «unreachable» هیچ نمی‌گوید
            $probe['guard'] = ['ok' => false, 'http' => 0,
                               'reason' => mb_substr($e->getMessage(), 0, 200)];
        }

        try {
            $raw   = json_encode(['sending_type' => 'healthcheck'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ts    = (string) time();
            $nonce = bin2hex(random_bytes(12));

            $r = \Illuminate\Support\Facades\Http::withHeaders([
                    'Content-Type'          => 'application/json',
                    'X-Relay-Timestamp'     => $ts,
                    'X-Relay-Nonce'         => $nonce,
                    'X-Relay-Signature'     => hash_hmac('sha256', $ts."\n".$nonce."\n".$raw, $relaySecret),
                    'X-Relay-Authorization' => (string) config('services.sms.ippanel.token'),
                ])
                ->timeout(25)
                ->withBody($raw, 'application/json')
                ->post($relayUrl);

            $json = $r->json();
            // اگر «relay:false» برگشت یعنی خودِ رابط رد کرد (امضا/کلید).
            // هر چیز دیگری یعنی به آی‌پی‌پنل رسید و پاسخش برگشت.
            $reachedUpstream = ! (is_array($json) && ($json['relay'] ?? null) === false);

            $probe['signed'] = [
                'ok'       => $reachedUpstream,
                'http'     => $r->status(),
                'via'      => $r->header('X-Relay') ?: null,
                'reason'   => data_get($json, 'reason'),
                'upstream' => data_get($json, 'meta.message_code') ?? data_get($json, 'meta.message'),
            ];
        } catch (\Throwable $e) {
            $probe['signed'] = ['ok' => false, 'http' => 0, 'reason' => 'unreachable'];
        }

        $out['relay'] = $probe;
    } else {
        $out['relay'] = ['configured' => false];
    }

        return $out;
    };

    /*
    | پنجرهٔ ۹۰ ثانیه: از بدترین‌حالتِ خودِ سنجش بلندتر است، پس دو درخواستِ
    | هم‌زمان هم نمی‌توانند دو بار تماس بگیرند؛ و آن‌قدر کوتاه که پایشِ
    | بیرونی داده‌ی کهنه نبیند.
    |
    | `age_seconds` عمداً برمی‌گردد: بی‌آن، پایشگر نمی‌داند عددی که می‌بیند
    | همین حالا گرفته شده یا عکسِ یک دقیقه پیش است — و همان ابهام، خرابیِ
    | تازه را یک دقیقه پنهان می‌کند.
    */
    try {
        $cached = \Illuminate\Support\Facades\Cache::get('system.health.snapshot');

        if (is_array($cached) && isset($cached['at'])) {
            return response()->json($cached['data'] + [
                'cached'      => true,
                'age_seconds' => max(0, time() - (int) $cached['at']),
            ]);
        }

        $out = $runProbes();
        \Illuminate\Support\Facades\Cache::put('system.health.snapshot',
            ['at' => time(), 'data' => $out], 90);

        return response()->json($out + ['cached' => false, 'age_seconds' => 0]);
    } catch (\Throwable $e) {
        // کش خراب است — سنجشِ زنده بهتر از صفحهٔ شکسته
        return response()->json($runProbes() + ['cached' => false, 'cache_error' => true]);
    }
});

/*
| کدام جدول‌های CMS ساخته شده‌اند — فقط بولین، بدون توکن، مثل db-status.
| برای تشخیص «کدام مهاجرت اجرا نشده» بدون دسترسی SSH.
*/
/*
| بررسی بارگذاری کلاس‌ها روی سرور — تأیید اینکه فایل مدل‌ها واقعاً هستند.
| بدون این، «Class not found» فقط با ورود واقعی دیده می‌شود.
*/
Route::middleware('throttle:tools')->get('/system/classcheck', function () {
    $classes = [
        \App\Models\Invoice::class, \App\Models\InvoiceItem::class, \App\Models\Payment::class,
        \App\Models\CreditEntry::class, \App\Models\BusinessEntry::class, \App\Models\Ticket::class,
        \App\Models\TicketMessage::class, \App\Models\SmsOutbox::class, \App\Models\BaleContact::class,
        \App\Services\Finance\BusinessLedger::class, \App\Services\Notify\CustomerNotifier::class,
        \App\Services\Bale\BaleNotifier::class, \App\Services\Payment\BaleGateway::class,
    ];

    $missing = array_values(array_filter($classes, fn ($c) => ! class_exists($c)));

    // تلاش برای ساختن یک Customer و صدا زدن رابطه‌هایش — همان کاری که /account می‌کند
    $relations = 'not_tested';
    try {
        $c = \App\Models\Customer::query()->first();
        if ($c) {
            $c->invoices()->count();
            $c->payments()->count();
            $c->tickets()->count();
            $c->creditBalance();
            $relations = 'ok';
        } else {
            $relations = 'no_customer_yet';
        }
    } catch (\Throwable $e) {
        $relations = 'ERROR: '.mb_substr($e->getMessage(), 0, 120);
    }

    return response()->json([
        'all_classes_present' => $missing === [],
        'missing'             => $missing,
        'account_relations'   => $relations,
    ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
});

Route::middleware('throttle:tools')->get('/system/tables', function () {
    $expected = [
        // فاز اول
        'currencies', 'tax_rates', 'customers', 'customer_profiles',
        'identity_verifications', 'bank_accounts', 'domain_quotes',
        // پرداخت و فاکتور
        'invoices', 'invoice_items', 'payments', 'credit_ledger',
        // پیامک، تیکت، مالی
        'otp_challenges', 'sms_outbox', 'tickets', 'ticket_messages', 'business_ledger',
    ];

    $present = [];
    $missing = [];
    foreach ($expected as $t) {
        if (\Illuminate\Support\Facades\Schema::hasTable($t)) {
            $present[] = $t;
        } else {
            $missing[] = $t;
        }
    }

    // آخرین مهاجرت‌های ثبت‌شده در جدول migrations — می‌گوید کجا متوقف شده
    $lastMigrations = \Illuminate\Support\Facades\Schema::hasTable('migrations')
        ? \Illuminate\Support\Facades\DB::table('migrations')->orderByDesc('id')->limit(8)->pluck('migration')
        : [];

    return response()->json([
        'driver'          => config('database.default'),
        'present_count'   => count($present),
        'missing'         => $missing,
        'last_migrations' => $lastMigrations,
    ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
});

Route::get('/system/db-status', function () {
    $out = ['site_driver' => \Illuminate\Support\Facades\DB::connection()->getDriverName()];

    $db   = env('MARIADB_DATABASE');
    $user = env('MARIADB_USERNAME');

    if (blank($db) || blank($user)) {
        return response()->json($out + ['mariadb' => 'MARIADB_* در .env تنظیم نشده']);
    }

    config(['database.connections.status_mariadb' => [
        'driver' => 'mariadb', 'host' => env('MARIADB_HOST', '127.0.0.1'),
        'port' => env('MARIADB_PORT', '3306'), 'database' => $db,
        'username' => $user, 'password' => env('MARIADB_PASSWORD'),
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
    ]]);

    try {
        $c = \Illuminate\Support\Facades\DB::connection('status_mariadb');
        $schema = $c->getSchemaBuilder();

        // عمداً هیچ شمارشی برنمی‌گردانیم. تعداد کاربر و موجودی محتوا، اطلاعاتی
        // است که نباید عمومی باشد. اینجا فقط «آماده هست یا نه» را می‌گوییم؛
        // جزئیات پشت توکن در صفحهٔ /system/setup است.
        $required = ['posts', 'post_translations', 'customers', 'currencies', 'tax_rates'];
        $missing = array_values(array_filter($required, fn ($t) => ! $schema->hasTable($t)));

        // مهاجرت‌های اجرانشده روی اتصالِ پیش‌فرض (همان که migrate استفاده می‌کند) —
        // برای عیب‌یابیِ «migrate هنگ می‌کند»
        $pending = (function () {
            try {
                if (! \Illuminate\Support\Facades\Schema::hasTable('migrations')) {
                    return ['(جدول migrations نیست)'];
                }
                $ran   = \Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();
                $files = array_map(fn ($p) => basename($p, '.php'), glob(database_path('migrations/*.php')));

                return array_values(array_diff($files, $ran));
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        })();

        $newTables = [];
        foreach (['services', 'settings', 'servers', 'products', 'customer_api_tokens', 'activity_logs'] as $t) {
            $newTables[$t] = \Illuminate\Support\Facades\Schema::hasTable($t);
        }

        // شمارشِ پکیج‌ها و سرورها — برای تأییدِ seed و آمادگیِ فروشگاه
        $counts = [];
        foreach (['products' => \App\Models\Product::class, 'servers' => \App\Models\Server::class] as $k => $model) {
            try {
                $counts[$k] = \Illuminate\Support\Facades\Schema::hasTable($k) ? $model::count() : 0;
            } catch (\Throwable) {
                $counts[$k] = 0;
            }
        }

        // آمادگیِ سرورهای تحویل — فقط بولین/تعداد، بدونِ نام و میزبان و توکن.
        // برای تشخیصِ «توکن ندارد» از «وصل نمی‌شود» بی‌آنکه رازی فاش شود.
        $whm = ['servers' => 0, 'with_token' => 0, 'with_country' => 0, 'active' => 0];
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('servers')) {
                foreach (\App\Models\Server::where('type', 'whm')->get() as $s) {
                    $whm['servers']++;
                    $whm['with_token'] += filled($s->api_token) ? 1 : 0;
                    $whm['with_country'] += filled($s->country) ? 1 : 0;
                    $whm['active'] += $s->status === 'active' ? 1 : 0;
                }
            }
        } catch (\Throwable) {
        }

        return response()->json($out + [
            'mariadb' => 'connected',
            'ready'   => $missing === [],
            'missing' => $missing,
            'pending_migrations' => $pending,
            'new_tables' => $newTables,
            'counts' => $counts,
            'whm'    => $whm,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        // پیام خام درایور ممکن است نام کاربر را داشته باشد — فقط نوع خطا را می‌دهیم
        $msg = $e->getMessage();
        $kind = match (true) {
            str_contains($msg, 'Access denied')    => 'اعتبارنامه رد شد',
            str_contains($msg, 'Unknown database') => 'دیتابیس با این نام وجود ندارد',
            str_contains($msg, 'Connection refused') => 'سرویس MariaDB در دسترس نیست',
            default => 'خطای اتصال',
        };

        return response()->json($out + ['mariadb' => 'failed', 'reason' => $kind], 200, [], JSON_UNESCAPED_UNICODE);
    }
})->middleware('throttle:tools');

Route::post('/system/setup', function (\Illuminate\Http\Request $r) {
    $expected = trim((string) env('DEPLOY_TOKEN', ''));
    $given    = trim((string) $r->input('token', ''));

    if ($expected === '') {
        return response()->json([
            'message' => "DEPLOY_TOKEN در فایل .env سرور تنظیم نشده است.\n\n".
                         "یک خط به .env اضافه کنید:\n  DEPLOY_TOKEN=یک_رشتهٔ_تصادفی_بلند\n\n".
                         "فقط از حروف انگلیسی و رقم استفاده کنید.",
        ]);
    }

    // تلهٔ رایج: متن نمونه عیناً کپی شده باشد
    if (! preg_match('/^[A-Za-z0-9_\-]{8,}$/', $expected)) {
        return response()->json([
            'message' => "مقدار DEPLOY_TOKEN معتبر نیست — احتمالاً متن نمونه را عیناً کپی کرده‌اید.\n\n".
                         "باید فقط حروف انگلیسی، رقم، خط تیره یا زیرخط باشد و دست‌کم ۸ نویسه.\n".
                         "مقدار فعلی شامل نویسه‌های دیگری است (مثلاً فارسی یا « »).",
        ]);
    }

    if (! hash_equals($expected, $given)) {
        return response()->json([
            'message' => "توکن اشتباه است.\n\nمقدار روبه‌روی DEPLOY_TOKEN در .env را دقیقاً کپی کنید ".
                         "(بدون فاصله یا گیومهٔ اضافه).",
        ]);
    }

    @set_time_limit(300);

    $step = (string) $r->input('step', 'check');

    /*
    | seed پست‌های بلاگ از resources/blog/posts (مهاجرت servernet.ir).
    | SeedBlogDb فقط اسلاگِ ناموجود را می‌سازد (insert-missing) پس اجرای
    | دوباره بی‌خطر است. اینجاست چون SSH نداریم و کارِ پروداکشن طبق قرارداد
    | با POST توکن‌دار توسط خود مدیر اجرا می‌شود — همان الگوی migrate.
    */
    if ($step === 'blogseed' || $step === 'blogrefresh') {
        \Illuminate\Support\Facades\Artisan::call('blog:seed-db',
            $step === 'blogrefresh' ? ['--refresh' => true] : []);

        return response()->json([
            'step'   => $step,
            'output' => trim(\Illuminate\Support\Facades\Artisan::output()) ?: '(بدون خروجی)',
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /*
    | ترجمهٔ en/tr پست‌های بی‌ترجمه (مقالات مهاجرت‌شده از servernet.ir).
    | همان کرونِ content:translate-missing است ولی دستی و دسته‌ای: هر ترجمه
    | یک تماسِ AI پولی و ~۳۰-۹۰ ثانیه است، پس سقفِ هر فراخوان کوچک می‌ماند
    | (وگرنه از مهلتِ ۳۰۰ ثانیه‌ای رد می‌شود) و مدیر آن‌قدر تکرار می‌کند تا
    | بگوید «همه‌ی پست‌ها هر سه زبان را دارند». limit از فرم: ۱ تا ۴.
    */
    if ($step === 'translate') {
        $limit = min(4, max(1, (int) $r->input('limit', 2)));
        \Illuminate\Support\Facades\Artisan::call('content:translate-missing', ['--limit' => $limit]);

        return response()->json([
            'step'   => 'translate',
            'limit'  => $limit,
            'output' => trim(\Illuminate\Support\Facades\Artisan::output()) ?: '(بدون خروجی)',
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    $flags = match ($step) {
        'migrate' => ['--migrate' => true],
        'port'    => ['--port' => true],
        'verify'  => ['--verify' => true],
        default   => ['--check' => true],
    };

    \Illuminate\Support\Facades\Artisan::call('db:setup-mariadb', $flags);

    return response()->json([
        'step'   => $step,
        'output' => trim(\Illuminate\Support\Facades\Artisan::output()) ?: '(بدون خروجی)',
    ], 200, [], JSON_UNESCAPED_UNICODE);
})->middleware('throttle:tools');

/*
 * بررسی نهایی پیش از سوییچ به MariaDB.
 *
 * سوییچ .env عملاً بازگشت‌ناپذیر است (یک بار سایت را انداخت)، پس قبلش
 * همه‌چیز را می‌سنجیم: جدول‌ها، کامل بودن داده، نوشتن واقعی، و سالم بودن فارسی.
 * فقط بولین برمی‌گرداند تا عمومی بودنش چیزی لو ندهد.
 */
Route::get('/system/preflight', function () {
    $db = env('MARIADB_DATABASE');
    $user = env('MARIADB_USERNAME');

    if (blank($db) || blank($user)) {
        return response()->json(['ok' => false, 'why' => 'MARIADB_* تنظیم نشده'], 200, [], JSON_UNESCAPED_UNICODE);
    }

    config(['database.connections.pf' => [
        'driver' => 'mariadb', 'host' => env('MARIADB_HOST', '127.0.0.1'),
        'port' => env('MARIADB_PORT', '3306'), 'database' => $db,
        'username' => $user, 'password' => env('MARIADB_PASSWORD'),
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
    ]]);

    try {
        $m = \Illuminate\Support\Facades\DB::connection('pf');
        $s = \Illuminate\Support\Facades\DB::connection();
        $checks = [];

        // ۱) هر جدولی که سایت برای بالا آمدن لازم دارد
        $need = ['posts', 'post_translations', 'comments', 'users', 'sessions', 'cache', 'jobs',
                 'customers', 'customer_profiles', 'currencies', 'tax_rates',
                 'identity_verifications', 'bank_accounts', 'domain_quotes'];
        $miss = array_values(array_filter($need, fn ($t) => ! $m->getSchemaBuilder()->hasTable($t)));
        $checks['tables'] = $miss === [];

        // ۲) داده کمتر از مبدأ نباشد
        $checks['posts'] = $m->table('posts')->count() >= $s->table('posts')->count();
        $checks['translations'] = $m->table('post_translations')->count() >= $s->table('post_translations')->count();
        $checks['users'] = $m->table('users')->count() >= $s->table('users')->count();

        // ۳) دادهٔ پایه پر شده
        $checks['currencies_seeded'] = $m->table('currencies')->count() >= 2;
        $checks['tax_seeded'] = $m->table('tax_rates')->count() >= 2;

        // ۴) نوشتن واقعاً کار می‌کند — با تراکنشی که عمداً برگردانده می‌شود
        try {
            $m->transaction(function () use ($m) {
                $m->table('currencies')->where('code', 'ZZZ')->delete();
                $m->table('currencies')->insert(['code' => 'ZZZ', 'exponent' => 0, 'rounding_step' => 1, 'symbol' => '']);
                throw new \RuntimeException('rollback');
            });
        } catch (\Throwable) {
            // عمدی
        }
        $checks['writable'] = $m->table('currencies')->where('code', 'ZZZ')->count() === 0;

        // ۵) فارسی سالم ذخیره شده؟ کولیشن غلط، متن را خراب می‌کند
        $t = $m->table('post_translations')->where('locale', 'fa')->first();
        $checks['utf8'] = $t === null
            || (mb_check_encoding($t->title ?? '', 'UTF-8') && preg_match('/\p{Arabic}/u', $t->title ?? '') === 1);

        return response()->json([
            'ok'      => ! in_array(false, $checks, true),
            'checks'  => $checks,
            'missing' => $miss,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        return response()->json(['ok' => false, 'why' => 'خطای اتصال'], 200, [], JSON_UNESCAPED_UNICODE);
    }
})->middleware('throttle:tools');

/*
 * آماده‌سازی MariaDB بدون قطعی سایت.
 *
 * روی اتصال جداگانه (MARIADB_*) کار می‌کند، پس تا وقتی DB_CONNECTION سایت
 * عوض نشده، هیچ ریسکی برای سایت زنده ندارد. ترتیب:
 *   ?step=check → ?step=migrate → ?step=port → ?step=verify
 * و تنها بعد از verify موفق، .env سوییچ می‌شود.
 */
Route::middleware('throttle:tools')->get('/system/mariadb/{token}', function (string $token) {
    $expected = (string) env('DEPLOY_TOKEN', '');
    abort_if($expected === '' || ! hash_equals($expected, $token), 404);
    @set_time_limit(300);

    $step = request('step', 'check');
    $flags = match ($step) {
        'migrate' => ['--migrate' => true],
        'port'    => ['--port' => true],
        'verify'  => ['--verify' => true],
        'all'     => ['--migrate' => true, '--port' => true, '--verify' => true],
        default   => ['--check' => true],
    };

    \Illuminate\Support\Facades\Artisan::call('db:setup-mariadb', $flags);

    return response(
        "گام: {$step}\n\n".\Illuminate\Support\Facades\Artisan::output(),
        200, ['Content-Type' => 'text/plain; charset=utf-8']
    );
});

// موقتی: اجرای امن مهاجرت + سیدِ دیتابیس روی پروداکشن (بعد از دیپلوی حذف می‌شود)
/*
| اجرای مهاجرت‌های جاری روی دیتابیس زنده.
|
| توکن با POST می‌آید، نه در مسیر URL — چون توکن در مسیر، در لاگ سرور و
| Cloudflare و تاریخچهٔ مرورگر ثبت می‌شود. یک بار کلید DeepSeek همین‌طور
| لو رفت. جایگزین مسیر قدیمی /system/db/{token} است که همین کار را با
| توکن در URL می‌کرد.
|
| فرم سبک زیر GET است تا بشود در مرورگر بازش کرد؛ ولی خودِ اجرا فقط با POST.
*/
Route::get('/system/migrate', fn () => response(
    '<!doctype html><meta charset=utf-8><title>مهاجرت دیتابیس</title>'
    .'<body style="font:15px/1.8 system-ui;max-width:560px;margin:60px auto;padding:0 20px;direction:rtl">'
    .'<h2>اجرای مهاجرت‌های جدید</h2>'
    .'<p>توکن <code>DEPLOY_TOKEN</code> را وارد کنید. مهاجرت‌های اجرانشده روی دیتابیس زنده اجرا می‌شوند.</p>'
    .'<form method=post><input name=token style="width:100%;padding:10px;font-size:15px" '
    .'placeholder="DEPLOY_TOKEN" autocomplete=off> '
    .'<input type=hidden name=_token value="">'
    .'<button style="margin-top:12px;padding:10px 22px;font-size:15px;cursor:pointer">اجرا</button></form>'
    .'<pre id=out style="background:#111;color:#0f0;padding:14px;border-radius:8px;white-space:pre-wrap;margin-top:20px"></pre>'
    .'<script>document.querySelector("form").addEventListener("submit",async e=>{e.preventDefault();'
    .'var o=document.getElementById("out");o.textContent="در حال اجرا…";'
    .'var r=await fetch("",{method:"POST",headers:{"Content-Type":"application/json"},'
    .'body:JSON.stringify({token:e.target.token.value})});'
    .'o.textContent=JSON.stringify(await r.json(),null,2)});</script>'
))->name('system.migrate');

Route::post('/system/migrate', function (\Illuminate\Http\Request $r) {
    $expected = (string) env('DEPLOY_TOKEN', '');
    $given    = (string) $r->input('token', '');

    // بدون DEPLOY_TOKEN این مسیر عملاً وجود ندارد
    abort_if($expected === '' || ! hash_equals($expected, $given), 404);

    @set_time_limit(300);

    /*
    | 🔴 ریستِ opcache **پیش از** سیدرها، نه بعدشان.
    |
    | سرور با `validate_timestamps=0` اجرا می‌شود: فایلِ PHPِ تازه‌آپلودشده تا
    | ریست‌نشدنِ opcache **زنده نمی‌شود**. تا امروز این ریست انتهای همین روت بود،
    | یعنی ترتیب این می‌شد:
    |
    |     سیدرها با بایت‌کدِ **قدیمی** اجرا می‌شوند → بعد opcache ریست می‌شود
    |
    | نتیجهٔ عملی: هر دیپلویی که سیدری را عوض کرده بود، **اجرای اولش بی‌اثر بود**
    | و صفحه هم «موفق» می‌گفت. فقط اجرای دومِ همین روت کار می‌کرد. مرداد ۱۴۰۵
    | دقیقاً همین رخ داد: ردیفِ تازهٔ الگوی پیام ساخته نشد و از بیرون شبیهِ
    | «سیدر خراب است» به‌نظر می‌رسید.
    |
    | ⚠️ `opcache_reset()` تضمین نمی‌کند فایلی که در **همین** درخواست از قبل
    | کامپایل شده دوباره خوانده شود، برای همین پایین‌تر هر فایلِ سیدر جداگانه با
    | `opcache_invalidate($f, true)` هم باطل می‌شود — آن یکی روی همان درخواست
    | اثر دارد و به کلاسی که هنوز autoload نشده می‌رسد.
    */
    $opcacheReset = function_exists('opcache_reset') ? @opcache_reset() : null;

    // اگر یک مهاجرت خطا دهد، بدون try/catch کل روت ۵۰۰ (HTML) می‌شد و JSِ
    // فرم روی «در حال اجرا…» هنگ می‌کرد. حالا خطا را برمی‌گردانیم تا دیده شود.
    $migrateError = null;
    try {
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        $migrate = trim(\Illuminate\Support\Facades\Artisan::output());
    } catch (\Throwable $e) {
        $migrate = trim(\Illuminate\Support\Facades\Artisan::output());
        $migrateError = $e->getMessage();
    }

    /*
    | 🔴 هیچ `catch`ی این‌جا خالی نیست — و این درسِ گران‌قیمتِ همین روت است.
    |
    | تا امروز شکستِ هر سیدر بی‌صدا بلعیده می‌شد و صفحه همچنان «موفق» نشان
    | می‌داد. یعنی دقیقاً در ابزاری که برای **دیدنِ** نتیجهٔ دیپلوی ساخته شده،
    | خرابی نامرئی بود. هر خطا حالا در `errors` برمی‌گردد.
    |
    | ⚠️ ولی `catch` همچنان لازم است: یک سیدرِ خراب نباید بقیه را متوقف کند —
    | ادامه می‌دهیم و آخرش گزارش می‌کنیم.
    */
    $errors = [];

    $step = function (string $name, callable $fn) use (&$errors) {
        try {
            $fn();
        } catch (\Throwable $e) {
            $errors[$name] = mb_substr($e->getMessage(), 0, 300);
        }
    };

    $step('clear', function () {
        \Illuminate\Support\Facades\Artisan::call('view:clear');
        \Illuminate\Support\Facades\Artisan::call('cache:clear');
    });

    /*
    | فایل‌های سیدر را **پیش از اولین autoload** صریح باطل کن، وگرنه با
    | `validate_timestamps=0` نسخهٔ تازه‌آپلودشده در همین درخواست خوانده نمی‌شود.
    |
    | ⚠️ عمداً از `ReflectionClass` برای پیداکردنِ مسیر استفاده نشده: آن خودش
    | کلاس را autoload می‌کند، یعنی فایل همان لحظه کامپایل و کش می‌شود و
    | باطل‌کردنِ بعدش دیگر بی‌فایده است. مسیرِ پوشه را مستقیم می‌خوانیم.
    */
    $invalidated = 0;
    if (function_exists('opcache_invalidate')) {
        foreach ((array) glob(base_path('database/seeders/*.php')) as $file) {
            if (@opcache_invalidate($file, true)) {
                $invalidated++;
            }
        }
    }

    // کاتالوگِ هاست را فقط اگر جدولِ products خالی است یک‌بار می‌سازد (پکیج‌های
    // ویرایش‌شدهٔ بعدی را پاک نمی‌کند). ~۵۲ پکیج از config/hosting.php.
    $seeded = null;
    try {
        /*
        | 🔴 شرطِ `Product::count() === 0` برداشته شد.
        |
        | آن شرط یعنی seeder فقط روی دیتابیسِ **خالی** می‌دوید — یعنی روی
        | پروداکشن که از قبل ده‌ها پکیج دارد، **هرگز**. نتیجه‌اش این بود که هر
        | خطِ محصولِ تازه‌ای که به کاتالوگ اضافه می‌شد، بعد از دیپلوی روی سایت
        | زنده ساخته نمی‌شد: صفحه قیمت را نشان می‌داد و دکمهٔ خرید به سبدِ
        | WHMCSِ بیرونی برمی‌گشت. دقیقاً همین برای ۴ پکیجِ نمایندگیِ
        | دایرکت‌ادمین و ۸ پکیجِ لایسنس پیش می‌آمد.
        |
        | ⚠️ برداشتنِ شرط بی‌خطر است چون **هر دو فرمان insert-missing هستند**
        | (firstOrCreate روی slug): پکیجِ موجود دست نمی‌خورد و قیمتی که مدیر در
        | پنل ویرایش کرده بازنویسی نمی‌شود. فقط ردیفِ نبوده ساخته می‌شود.
        */
        if (\Illuminate\Support\Facades\Schema::hasTable('products')) {
            \Illuminate\Support\Facades\Artisan::call('products:seed-hosting');
            $seeded = trim(\Illuminate\Support\Facades\Artisan::output());

            /*
            | لایسنس‌ها — از `LicenseProductSeeder` که در develop هم همین‌جا
            | صدا زده می‌شود. عمداً همان کلاس، نه یک فرمانِ موازیِ دیگر: دو
            | مسیرِ seed برای یک کاتالوگ یعنی روزی یکی‌شان کهنه می‌شود و
            | هیچ‌کس نمی‌فهمد کدام روی prod دویده.
            |
            | ⚠️ خودش idempotent است و ردیفی را که مدیر ویرایش کرده دست
            | نمی‌زند (تشخیص با «قیمتِ فعلی = قیمتِ نسخهٔ قبلیِ seeder»).
            */
            (new \Database\Seeders\LicenseProductSeeder)->run();
            $seeded .= "\nلایسنس‌ها: seed اجرا شد.";

            // پکیج‌های سایت‌ساز — تسویهٔ builder بی‌این‌ها به fallbackِ WHMCS می‌افتد
            \Illuminate\Support\Facades\Artisan::call('products:seed-builder');
            $seeded .= "\n".trim(\Illuminate\Support\Facades\Artisan::output());
        }

        /*
        | 🔴 پکیج‌های سایت‌ساز **بیرونِ** شرطِ `count() === 0` — عمداً.
        |
        | آن شرط یعنی «فقط روی دیتابیسِ خالی»، و پروداکشن هرگز خالی نیست؛ پس
        | seedِ سایت‌ساز که داخلِ همان بلاک بود روی سرورِ واقعی **هرگز اجرا
        | نمی‌شد** — `seeded: null`، بی‌هیچ خطایی، و دکمهٔ استقرار برای همیشه
        | روی fallbackِ WHMCS می‌مانْد. فرمانِ خودش idempotent است
        | (firstOrCreate روی slug)، پس هر بار اجرا امن است و ویرایش‌های مدیر
        | دست نمی‌خورد.
        */
        if (\Illuminate\Support\Facades\Schema::hasTable('products')) {
            \Illuminate\Support\Facades\Artisan::call('products:seed-builder');
            $seeded = trim(($seeded ?? '')."\n".trim(\Illuminate\Support\Facades\Artisan::output()));
        }
    } catch (\Throwable $e) {
        // متنش از قبل در `seeded` دیده می‌شد، ولی روی `ok` اثر نداشت — پس یک
        // شکستِ واقعی همچنان «موفق» گزارش می‌شد.
        $seeded = 'seed error: '.$e->getMessage();
        $errors['products'] = mb_substr($e->getMessage(), 0, 300);
    }

    // کاتالوگِ الگوی پیام‌ها — همان الگو: firstOrCreate، پس متنی که مدیر در
    // /admin/templates ویرایش کرده هرگز با دیپلوی بعدی به متنِ کد برنمی‌گردد.
    $step('notification_templates', function () {
        if (\Illuminate\Support\Facades\Schema::hasTable('notification_templates')) {
            (new \Database\Seeders\NotificationTemplateSeeder())->run();
        }
    });

    /*
    | اسنادِ حقوقی — بی‌این، **هیچ مشتری‌ای قوانین را نپذیرفته**.
    |
    | `recordAcceptance()` از این جدول می‌خواند تا ثبت کند کاربر کدام نسخه را
    | پذیرفته. جدولِ خالی یعنی حلقه روی مجموعهٔ خالی می‌چرخد، هیچ استثنایی
    | پرتاب نمی‌شود، و `legal_acceptances` برای همیشه خالی می‌مانَد — پس سقفِ
    | مسئولیت و جدولِ SLA روی پذیرشی می‌ایستند که هیچ مدرکی ندارد.
    |
    | ⚠️ نسخه از هشِ خودِ متن ساخته می‌شود، پس ویرایشِ قوانین خودبه‌خود نسخهٔ
    | تازه می‌سازد و پذیرشِ قبلی‌ها دست‌نخورده می‌مانَد.
    */
    $step('legal_documents', function () {
        if (\Illuminate\Support\Facades\Schema::hasTable('legal_documents')) {
            (new \Database\Seeders\LegalDocumentSeeder())->run();
        }
    });

    // کاتالوگِ سرورِ فیزیکی — insert-missing از config. هر بار امن است (اسلاگِ
    // موجود را دست نمی‌زند)، پس مدل‌های تازهٔ config در هر دیپلوی سینک می‌شوند.
    $step('physical_servers', function () {
        if (\Illuminate\Support\Facades\Schema::hasTable('physical_servers')) {
            (new \Database\Seeders\PhysicalServerSeeder())->run();
        }
    });

    // کاتالوگِ قطعاتِ سرور — همان الگوی insert-missing. قیمتِ یوروییِ ویرایش‌شده
    // در /admin/parts با دیپلویِ بعدی به عددِ سیدر برنمی‌گردد.
    $step('server_parts', function () {
        if (\Illuminate\Support\Facades\Schema::hasTable('server_parts')) {
            (new \Database\Seeders\ServerPartSeeder())->run();
            app(\App\Services\Shop\PartsCatalog::class)->flush();
        }
    });

    // پکیج‌های لایسنس — insert-missing؛ قیمتِ ویرایش‌شده‌ی مدیر دست نمی‌خورد.
    $step('license_products', function () {
        if (\Illuminate\Support\Facades\Schema::hasTable('products')) {
            (new \Database\Seeders\LicenseProductSeeder())->run();
        }
    });

    /*
    | ریستِ دوم، برای بقیهٔ کد (روت‌ها، ویوها، کلاس‌های اپ) که این درخواست
    | اجراشان نکرد. ریستِ اولِ بالای تابع فقط سیدرها را هدف داشت.
    */
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    // تأیید اینکه جدول‌های تازه واقعاً ساخته شدند
    $tables = ['services', 'settings', 'servers', 'products', 'customer_api_tokens', 'activity_logs', 'invoices'];
    $present = [];
    foreach ($tables as $t) {
        $present[$t] = \Illuminate\Support\Facades\Schema::hasTable($t);
    }

    /*
    | ⚠️ `ok` حالا شکستِ سیدر را هم می‌بیند، نه فقط مهاجرت را.
    |
    | پیش از این `ok` فقط به `$migrateError` نگاه می‌کرد، پس یک سیدرِ خراب
    | «موفق» گزارش می‌شد. همان قاعدهٔ ثبت‌شده در CLAUDE.md: پرس‌وجوی ناظر باید
    | خودِ خرابی را ببیند، نه ستونِ همسایه.
    */
    return response()->json([
        'ok'          => $migrateError === null && $errors === [],
        'error'       => $migrateError,
        'errors'      => $errors === [] ? null : $errors,
        'migrate'     => $migrate,
        'seeded'      => $seeded,
        'opcache'     => ['reset' => $opcacheReset, 'seeders_invalidated' => $invalidated],
        'tables'      => $present,
    ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
})->middleware('throttle:6,1');

/*
| ریست opcache — بدون مهاجرت.
|
| سرور opcache را با validate_timestamps=0 اجرا می‌کند، پس فایل PHPِ
| ویرایش‌شده تا ریست شدنِ opcache زنده نمی‌شود. این روت برای دپلوی‌هایی است
| که فقط کد عوض می‌کنند و مهاجرت ندارند. توکن‌دار و POST، مثل بقیهٔ system.
*/
/*
| فرمِ مرورگری برای ریستِ opcache.
|
| ⚠️ چرا لازم شد: خودِ عمل فقط POST است (توکن نباید در URL برود). ولی وقتی
| کارفرما آدرس را در مرورگر باز می‌کرد، مرورگر GET می‌فرستاد و «۴۰۵ Method Not
| Allowed» می‌گرفت — دقیقاً همان تجربه‌ای که پیش آمد. /system/migrate این صفحه
| را داشت و کار می‌کرد؛ opcache نداشت. حالا هر دو یک‌شکل‌اند.
*/
/*
| عیب‌یابیِ تحویلِ سرورِ ابری — «چرا سفارش در حالِ آماده‌سازی مانده؟»
|
| بدونِ این، تنها راهِ فهمیدنِ علت، خواندنِ لاگِ چندمگابایتی روی سرور بود.
| این‌جا وضعیتِ صفِ تحویل، خطای هر سرویس، و سلامتِ کاتالوگ (پلن/ایمیج/مکان)
| یک‌جا دیده می‌شود. POST + توکن، چون داده‌اش عملیاتی است.
*/
Route::get('/system/cloud-status', fn () => response(
    '<!doctype html><meta charset=utf-8><title>وضعیتِ تحویلِ سرورِ ابری</title>'
    .'<body style="font:15px/1.8 system-ui;max-width:900px;margin:60px auto;padding:0 20px;direction:rtl">'
    .'<h2>وضعیتِ تحویلِ سرورِ ابری</h2>'
    .'<p>توکن <code>DEPLOY_TOKEN</code> را وارد کنید تا صفِ تحویل، خطاها و سلامتِ کاتالوگ را ببینیم.</p>'
    .'<form method=post><input name=token style="width:100%;padding:10px;font-size:15px" '
    .'placeholder="DEPLOY_TOKEN" autocomplete=off> '
    .'<button style="margin-top:12px;padding:10px 22px;font-size:15px;cursor:pointer">بررسی</button></form>'
    .'<pre id=out style="background:#111;color:#0f0;padding:14px;border-radius:8px;white-space:pre-wrap;margin-top:20px"></pre>'
    .'<script>document.querySelector("form").addEventListener("submit",async e=>{e.preventDefault();'
    .'var o=document.getElementById("out");o.textContent="در حال بررسی…";'
    .'var r=await fetch("",{method:"POST",headers:{"Content-Type":"application/json"},'
    .'body:JSON.stringify({token:e.target.token.value})});'
    .'o.textContent=JSON.stringify(await r.json(),null,2)});</script>'
))->name('system.cloud-status');

Route::post('/system/cloud-status', function (\Illuminate\Http\Request $r) {
    $expected = (string) env('DEPLOY_TOKEN', '');
    abort_if($expected === '' || ! hash_equals($expected, (string) $r->input('token', '')), 404);

    $S = \Illuminate\Support\Facades\Schema::class;
    $out = [];

    // ۱) سرویس‌هایی که تحویل نشده‌اند — دقیقاً همان‌هایی که مشتری منتظرشان است
    if ($S::hasTable('services')) {
        $out['صفِ تحویل'] = \App\Models\Service::query()
            ->whereIn('status', ['awaiting_provision', 'pending'])
            ->orWhere(fn ($q) => $q->whereNotNull('provision_status')
                ->whereNotIn('provision_status', ['done']))
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'name', 'status', 'provision_status', 'provision_error',
                'cloud_plan_id', 'cloud_image_key', 'created_at'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'نام' => mb_substr((string) $s->name, 0, 40),
                'وضعیت' => $s->status,
                'تحویل' => $s->provision_status,
                'خطا' => mb_substr((string) $s->provision_error, 0, 200) ?: null,
                'پلن' => $s->cloud_plan_id,
                'سیستم‌عامل' => $s->cloud_image_key,
                'ثبت' => (string) $s->created_at,
            ])->all();
    }

    // ۲) سلامتِ کاتالوگ: هر مکان چند پلن و چند ایمیجِ قابلِ استفاده دارد؟
    //    ایمیجِ صفر ⇒ مشتری نمی‌تواند سیستم‌عامل انتخاب کند و تحویل شکست می‌خورد.
    if ($S::hasTable('cloud_locations') && $S::hasTable('cloud_plans')) {
        $rows = [];

        foreach (\App\Models\CloudLocation::orderBy('code')->get() as $loc) {
            $plans = \App\Models\CloudPlan::where('location_code', $loc->code)->get();
            $providers = $plans->pluck('provider')->unique()->filter()->values();

            $imgCount = $S::hasTable('cloud_images')
                ? \App\Models\CloudImage::query()->usable()
                    ->whereIn('provider', $providers)->count()
                : 0;

            $rows[] = [
                'مکان' => $loc->code,
                'فعال' => (bool) $loc->is_active,
                'پلن' => $plans->count(),
                'قابلِ‌فروش' => $plans->where('is_active', true)->where('in_stock', true)
                    ->where('price_irt', '>', 0)->count(),
                'ایمیج' => $imgCount,
                'هشدار' => $imgCount === 0 ? '⚠️ بدونِ سیستم‌عامل — قابلِ سفارش نیست' : null,
            ];
        }

        $out['کاتالوگ'] = $rows;
    }

    // ۳) کرون واقعاً می‌دود؟ آخرین اجرای زمان‌بند
    // ⚠️ از **فایل** خوانده می‌شود نه کش — کش روی همان دیتابیسی است که وقتی
    //    می‌میرد کرون را هم می‌کشد، و ضربانی که با بیمار بمیرد بی‌فایده است.
    $out['کرون'] = [
        'آخرین اجرای زمان‌بند' => app(\App\Services\SystemHealth::class)->heartbeatAt()?->toDateTimeString(),
        'اکنون' => now()->toDateTimeString(),
    ];

    // ۵) چرا سیستم‌عاملی برای انتخاب نمی‌آید؟ (فقط خواندن، بی‌هیچ تغییری)
    //    برای اولین پلنِ فروختنیِ هر زیرساخت، فهرستِ قابلِ انتخاب و دلیلِ ردشدنِ
    //    ایمیج‌ها را نشان می‌دهد — همان سه شرطِ deliverable(): زیرساخت، دیسک، معماری.
    if ($S::hasTable('cloud_plans') && $S::hasTable('cloud_images')) {
        $diag = [];

        foreach (\App\Models\CloudPlan::query()->sellable()->get()->groupBy('provider') as $prov => $rows) {
            $plan = $rows->first();
            $os = \App\Http\Controllers\Account\CloudStoreController::imageKeysFor($plan, 'os');
            $app = \App\Http\Controllers\Account\CloudStoreController::imageKeysFor($plan, 'app');

            $imgs = \App\Models\CloudImage::query()->usable()->where('provider', $prov)->get();

            $diag[] = [
                'زیرساخت' => $prov,
                'پلنِ نمونه' => $plan->public_name.' (دیسک '.$plan->disk_gb.'GB · معماری '.$plan->arch.')',
                'اسلاگ' => $plan->slug,
                'سیستم‌عاملِ قابلِ انتخاب' => count($os),
                'نرم‌افزارِ قابلِ انتخاب' => count($app),
                'ایمیجِ فعالِ این زیرساخت' => $imgs->count(),
                'بیشترین min_disk_gb' => (int) $imgs->max('min_disk_gb'),
                'معماریِ ایمیج‌ها' => $imgs->pluck('arch')->unique()->values()->all(),
                'ردشده به‌خاطرِ دیسک' => $imgs->where('min_disk_gb', '>', (int) $plan->disk_gb)->count(),
                'ردشده به‌خاطرِ معماری' => $imgs->filter(fn ($i) => filled($i->arch)
                    && filled($plan->arch) && (string) $i->arch !== (string) $plan->arch)->count(),
                'نمونهٔ ایمیج' => $imgs->take(3)->map(fn ($i) => $i->key.' [kind='.$i->kind
                    .' min_disk='.$i->min_disk_gb.' arch='.$i->arch.']')->values()->all(),
            ];
        }

        $out['عیب‌یابیِ سیستم‌عامل'] = $diag;
    }

    // ۴) ایمیج‌ها به تفکیکِ زیرساخت (نامِ زیرساخت فقط برای مدیر)
    if ($S::hasTable('cloud_images')) {
        $out['ایمیج به تفکیکِ زیرساخت'] = \App\Models\CloudImage::query()
            ->selectRaw('provider, count(*) as n, sum(case when is_active = 1 then 1 else 0 end) as active')
            ->groupBy('provider')->get()->map(fn ($x) => [
                'زیرساخت' => $x->provider, 'کل' => (int) $x->n, 'فعال' => (int) $x->active,
            ])->all();
    }

    return response()->json($out, 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
});

Route::get('/system/opcache', fn () => response(
    '<!doctype html><meta charset=utf-8><title>ریست opcache</title>'
    .'<body style="font:15px/1.8 system-ui;max-width:560px;margin:60px auto;padding:0 20px;direction:rtl">'
    .'<h2>ریست opcache</h2>'
    .'<p>توکن <code>DEPLOY_TOKEN</code> را وارد کنید. کشِ بایت‌کد و کشِ config/route/view پاک می‌شود '
    .'تا کدِ تازه دپلوی‌شده زنده شود.</p>'
    .'<form method=post><input name=token style="width:100%;padding:10px;font-size:15px" '
    .'placeholder="DEPLOY_TOKEN" autocomplete=off> '
    .'<button style="margin-top:12px;padding:10px 22px;font-size:15px;cursor:pointer">اجرا</button></form>'
    .'<pre id=out style="background:#111;color:#0f0;padding:14px;border-radius:8px;white-space:pre-wrap;margin-top:20px"></pre>'
    .'<script>document.querySelector("form").addEventListener("submit",async e=>{e.preventDefault();'
    .'var o=document.getElementById("out");o.textContent="در حال اجرا…";'
    .'var r=await fetch("",{method:"POST",headers:{"Content-Type":"application/json"},'
    .'body:JSON.stringify({token:e.target.token.value})});'
    .'o.textContent=JSON.stringify(await r.json(),null,2)});</script>'
))->name('system.opcache');

Route::post('/system/opcache', function (\Illuminate\Http\Request $r) {
    $expected = (string) env('DEPLOY_TOKEN', '');
    abort_if($expected === '' || ! hash_equals($expected, (string) $r->input('token', '')), 404);

    // config/route را هم پاک کن تا تغییرِ .env (مثل SESSION_DOMAIN) و روت‌های تازه
    // واقعاً خوانده شوند؛ اگر کش نشده باشند، این‌ها بی‌ضررند.
    $cleared = [];
    foreach (['config:clear', 'route:clear', 'view:clear', 'cache:clear'] as $cmd) {
        try {
            \Illuminate\Support\Facades\Artisan::call($cmd);
            $cleared[] = $cmd;
        } catch (\Throwable) {
        }
    }
    $reset = function_exists('opcache_reset') ? @opcache_reset() : null;

    return response()->json([
        'opcache_reset' => $reset,
        'cleared'       => $cleared,
        'note'          => $reset ? 'opcache و کشِ config/route/view پاک شد؛ کدِ تازه و .env زنده است.' : 'opcache در دسترس نبود.',
    ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
})->middleware('throttle:6,1');

/*
| تشخیصِ نشست/دامنه — برای فهمِ اینکه چرا نامِ کاربر روی سایتِ اصلی نمی‌افتد.
| هیچ رازی فاش نمی‌شود: app_fingerprint یک هَشِ یک‌طرفه است، کوکی‌ها فقط «نام»
| (نه مقدار). این را روی هر دو دامنه (servernet.cloud و console.servernet.cloud)
| در حالتِ واردشده باز کنید و JSON را مقایسه کنید. بعد از تشخیص حذف می‌شود.
*/
/*
| آخرین خطاهای ۵۰۰ به‌صورت JSON — برای عیب‌یابی بدون دسترسیِ SSH.
| توکن‌دار و POST. فقط کلاس/پیام/فایل/فریمِ خطا برمی‌گردد؛ هیچ ورودیِ کاربر و
| هیچ اعتبارنامه‌ای در tracker ذخیره نمی‌شود.
*/
Route::post('/system/errors', function (\Illuminate\Http\Request $r) {
    $expected = (string) env('DEPLOY_TOKEN', '');
    abort_if($expected === '' || ! hash_equals($expected, (string) $r->input('token', '')), 404);

    $rows = collect(\App\Support\ErrorTracker::recent(200, 'error'))
        ->filter(fn ($e) => ($e['type'] ?? '') === 'error')
        ->take((int) $r->input('limit', 8))
        ->map(fn ($e) => [
            'at'      => $e['at'] ?? null,
            'url'     => $e['url'] ?? null,
            'method'  => $e['method'] ?? null,
            'class'   => $e['class'] ?? null,
            'message' => $e['message'] ?? null,
            'file'    => $e['file'] ?? null,
            'frame'   => $e['frame'] ?? null,
        ])->values();

    return response()->json(['count' => $rows->count(), 'errors' => $rows],
        200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
})->middleware('throttle:12,1');

Route::get('/system/whoami', function (\Illuminate\Http\Request $r) {
    return response()->json([
        'host'              => $r->getHost(),
        'secure'            => $r->isSecure(),
        'app_fingerprint'   => substr(hash('sha256', (string) config('app.key')), 0, 12),
        'session_domain'    => config('session.domain'),      // باید .servernet.cloud باشد؛ اگر null یعنی .env خوانده نشده (کشِ config)
        'session_cookie'    => config('session.cookie'),
        'session_secure'    => config('session.secure'),
        'session_same_site' => config('session.same_site'),
        'customer_auth'     => auth('customer')->check(),
        'customer_code'     => optional(auth('customer')->user())->code,
        'cookies_present'   => array_keys($r->cookies->all()),
        'config_cached'     => app()->configurationIsCached(),
    ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
})->middleware('throttle:30,1');

/*
| پنل مدیریت محتوا (/admin) — احراز هویت با سشن، غیرلوکالایز
*/
use App\Http\Controllers\Admin\AuthController as AdminAuth;
use App\Http\Controllers\Admin\CommentController as AdminComment;
use App\Http\Controllers\Admin\DashboardController as AdminDash;
use App\Http\Controllers\Admin\PostController as AdminPost;
use App\Http\Controllers\Admin\UserController as AdminUser;

/*
| پایانِ «جای مشتری نشستن».
|
| عمداً بیرونِ گروهِ auth:web است: مدیر در این لحظه با گاردِ customer در پنل
| نشسته و ممکن است نشستِ webش منقضی شده باشد؛ باز هم باید بتواند خارج شود.
| خودِ کنترلر هر دو حالت را مدیریت می‌کند.
*/
Route::post('/admin/impersonate/stop', [\App\Http\Controllers\Admin\ImpersonateController::class, 'stop'])
    ->name('admin.impersonate.stop');

Route::prefix('admin')->group(function () {
    Route::get('/setup', [AdminAuth::class, 'showSetup']);
    Route::post('/setup', [AdminAuth::class, 'setup']);
    // نام «admin.login» و نه «login» — چون «login» مال ورود مشتری است و اگر
    // هر دو یک نام داشته باشند، route('login') بی‌سروصدا یکی را می‌پوشاند و
    // مشتری به صفحهٔ ورود مدیر هدایت می‌شود. هدایت مهمان‌ها در bootstrap/app.php
    // تفکیک شده است.
    Route::get('/login', [AdminAuth::class, 'showLogin'])->name('admin.login');
    // محدودیت نرخ: جلوگیری از حمله‌ی جستجوی فراگیر روی رمز مدیر
    Route::post('/login', [AdminAuth::class, 'login'])->middleware('throttle:signin');
    // ورود دومرحله‌ای مدیر: بعد از تأیید درست رمز، یک کد یک‌بارمصرف به ایمیل مدیر
    // می‌رود و تا تأیید نشود، نشستِ مدیر برقرار نمی‌شود. این روت‌ها بیرونِ
    // «auth:web» هستند چون کاربر هنوز وارد نشده است.
    Route::get('/login/otp', [AdminAuth::class, 'showOtp'])->name('admin.login.otp');
    Route::post('/login/otp', [AdminAuth::class, 'verifyOtp'])->middleware('throttle:otp');
    Route::post('/login/otp/resend', [AdminAuth::class, 'resendOtp'])->middleware('throttle:resend');
    Route::post('/logout', [AdminAuth::class, 'logout']);

    // «auth:web» صریح و نه «auth» — گارد پیش‌فرض ممکن است در طول یک درخواست
    // عوض شود؛ پنل مدیریت باید همیشه دقیقاً گارد کارکنان را بخواهد.
    /*
    |==========================================================================
    | 🔴 پیش‌فرضِ این گروه **وارونه** شد: `admin` روی کل، استثنا صریح.
    |==========================================================================
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
        });

        Route::get('/users', [AdminUser::class, 'index']);
        Route::post('/users', [AdminUser::class, 'store']);
        Route::post('/users/{user}/delete', [AdminUser::class, 'destroy']);
        // داخلیِ تلفن ابری هر کارمند — بدونِ آن دکمهٔ تماسِ او غیرفعال است
        Route::post('/users/{user}/extension', [AdminUser::class, 'extension']);

        // ردیاب خطای سرور و ۴۰۴
        Route::get('/errors', [\App\Http\Controllers\Admin\ErrorLogController::class, 'index'])->name('admin.errors');
        Route::post('/errors/clear', [\App\Http\Controllers\Admin\ErrorLogController::class, 'clear']);

        // داشبورد مالی کسب‌وکار — سرمایه، سود، مالیات
        Route::get('/finance', [\App\Http\Controllers\Admin\FinanceController::class, 'index'])->name('admin.finance')->middleware('admin');
        Route::post('/finance', [\App\Http\Controllers\Admin\FinanceController::class, 'store'])->middleware('admin');
        Route::post('/finance/{entry}/delete', [\App\Http\Controllers\Admin\FinanceController::class, 'destroy'])->middleware('admin');

        // گزارشِ کسب‌وکار — پولِ در راه، رشدِ مشتری، ظرفیتِ زیرساخت
        // ⚠️ فقط می‌خوانَد؛ هیچ روتِ نوشتنی ندارد و عمداً هم نباید داشته باشد.
        Route::get('/reports', [\App\Http\Controllers\Admin\ReportController::class, 'index'])->name('admin.reports')->middleware('admin');

        // تراکنش‌ها و اعتبار — پرداخت‌های ریز + دفتر اعتبار + بدهیِ اعتبارِ مشتریان
        Route::get('/transactions', [\App\Http\Controllers\Admin\TransactionController::class, 'index'])->name('admin.transactions')->middleware('admin');

        // تیکت پشتیبانی — روی همان احراز هویت کارکنان
        Route::get('/tickets', [\App\Http\Controllers\Admin\TicketController::class, 'index'])->name('admin.tickets');
        /*
        | ⚠️ `bulk` پیش از `{ticket}` ثبت می‌شود — درسِ روتِ compare در
        | فروشگاهِ قطعات: مسیرِ ثابتی که بعدِ پارامتری بیاید بلعیده می‌شود و
        | دکمه بی‌صدا از کار می‌افتد.
        */
        Route::post('/tickets/bulk', [\App\Http\Controllers\Admin\TicketController::class, 'bulk']);
        Route::get('/tickets/{ticket}', [\App\Http\Controllers\Admin\TicketController::class, 'show'])->name('admin.ticket');
        Route::post('/tickets/{ticket}/reply', [\App\Http\Controllers\Admin\TicketController::class, 'reply']);
        // تصحیحِ نگارش با AI — فقط برمی‌گرداند، هیچ‌چیز نمی‌فرستد
        Route::post('/tickets/{ticket}/polish', [\App\Http\Controllers\Admin\TicketController::class, 'polish']);
        // پیشنهادِ پاسخ با AI — همان موتورِ بله؛ فقط برمی‌گرداند، هیچ‌چیز نمی‌فرستد
        Route::post('/tickets/{ticket}/draft', [\App\Http\Controllers\Admin\TicketController::class, 'draft']);
        Route::post('/tickets/{ticket}/update', [\App\Http\Controllers\Admin\TicketController::class, 'update']);
        Route::get('/tickets/{ticket}/attachments/{attachment}', [\App\Http\Controllers\Admin\TicketController::class, 'attachment']);

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

        // مدیریت مشتریان — بخشِ شبیه‌WHMCS
        Route::get('/customers', [\App\Http\Controllers\Admin\CustomerController::class, 'index'])->name('admin.customers');
        Route::get('/customers/{customer}', [\App\Http\Controllers\Admin\CustomerController::class, 'show'])->name('admin.customer');
        Route::post('/customers/{customer}/status', [\App\Http\Controllers\Admin\CustomerController::class, 'status']);
        Route::post('/customers/{customer}/password', [\App\Http\Controllers\Admin\CustomerController::class, 'password']);
        // نمایندگیِ دامنه — فعال‌سازی، سطحِ دستی، تخفیفِ توافقی، سقفِ روزانه
        Route::post('/customers/{customer}/reseller', [\App\Http\Controllers\Admin\CustomerController::class, 'reseller']);
        Route::post('/customers/{customer}/delete', [\App\Http\Controllers\Admin\CustomerController::class, 'destroy']);
        // حذف فاکتورِ پرداخت‌نشده (فاکتورِ پرداخت‌شده هرگز حذف نمی‌شود)
        Route::post('/invoices/{invoice}/delete', [\App\Http\Controllers\Admin\CustomerController::class, 'destroyInvoice']);

        // فروش و مدیریت سرویس‌های مشتری
        Route::post('/customers/{customer}/services', [\App\Http\Controllers\Admin\ServiceController::class, 'store']);
        Route::post('/services/{service}/status', [\App\Http\Controllers\Admin\ServiceController::class, 'update']);

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

        Route::get('/cloud', [\App\Http\Controllers\Admin\CloudController::class, 'index'])->name('admin.cloud')->middleware('admin');
        Route::post('/cloud/test', [\App\Http\Controllers\Admin\CloudController::class, 'test'])->middleware('admin');
        Route::post('/cloud/sync', [\App\Http\Controllers\Admin\CloudController::class, 'sync'])->middleware('admin');
        // ابزارِ عیب‌یابیِ ساختارِ پاسخ — نگاشتِ فیلدهای زیرساختِ ۲ کاملاً قطعی نیست
        // اتصالِ سرورِ ازقبل‌ساخته‌شده به مشتری — سرور نمی‌سازد، فقط ثبت می‌کند.
        Route::get('/cloud/attach', [\App\Http\Controllers\Admin\CloudAttachController::class, 'form'])->middleware('admin');
        Route::post('/cloud/attach', [\App\Http\Controllers\Admin\CloudAttachController::class, 'store'])->middleware('admin');
        // تطبیقِ موجودی: سرورِ بی‌مشتری و سرویسِ بی‌سرور — هر دو نشتیِ پول‌اند
        Route::get('/cloud/inventory', [\App\Http\Controllers\Admin\CloudAttachController::class, 'inventory'])->middleware('admin');

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
    });

/*
 * مسیرهای «کششیِ» موتورِ هاستِ ایران (pull-agent) — بیرونِ closureِ سه‌زبانهٔ
 * سایت. احراز با هدرِ `X-Agent-Token` داخلِ کنترلر (Setting::getSecret). فقط
 * GET و فقط‌خواندنی؛ ایجنت هر چند دقیقه این‌ها را می‌خوانَد تا حالتِ مطلوب
 * (مسیرِ کشوریِ خروج + port-forward) را یاد بگیرد.
 */
Route::prefix('agent')->group(function () {
    Route::get('countryroutes', [\App\Http\Controllers\Agent\PullController::class, 'countryRoutes']);
    Route::get('portforwards',  [\App\Http\Controllers\Agent\PullController::class, 'portForwards']);
});
