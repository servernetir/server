<?php

use App\Http\Controllers\Account;
use App\Http\Controllers\AiBuilderController;
use App\Http\Controllers\Auth;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DomainCheckController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SolutionController;
use App\Http\Controllers\ToolController;
use Illuminate\Support\Facades\Route;

/*
| ساختار دوزبانه: فارسی در ریشه (پیش‌فرض) و انگلیسی با پیشوند /en
| صفحات جدید را فقط داخل $site اضافه کنید تا خودکار در هر دو زبان ساخته شوند.
*/

$site = function (): void {
    Route::get('/', [SiteController::class, 'home'])->name('home');
    Route::get('/hosting/{slug}', [CatalogController::class, 'hosting'])->name('hosting')->where('slug', '[a-z-]+');
    Route::get('/contact', [SiteController::class, 'contact'])->name('contact');
    Route::get('/knowledge', [SiteController::class, 'knowledge'])->name('knowledge');
    Route::get('/careers', [\App\Http\Controllers\CareersController::class, 'show'])->name('careers');
    Route::post('/careers/apply', [\App\Http\Controllers\CareersController::class, 'apply'])->name('careers.apply')->middleware('throttle:forms');
    Route::get('/about', fn () => app(SiteController::class)->page('about'))->name('about');
    Route::get('/privacy', fn () => app(SiteController::class)->page('privacy'))->name('privacy');
    Route::get('/terms', fn () => app(SiteController::class)->page('terms'))->name('terms');

    Route::get('/tools/{slug}', [ToolController::class, 'show'])->name('tools')->where('slug', '[a-z-]+');
    Route::post('/api/audit', [ToolController::class, 'audit'])->name('api.audit')->middleware('throttle:tools');
    Route::post('/api/whois', [ToolController::class, 'whois'])->name('api.whois')->middleware('throttle:tools');
    Route::post('/api/ip', [ToolController::class, 'ip'])->name('api.ip')->middleware('throttle:tools');

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

    // پیش‌نمایش طراحی پنل کاربری — موقتی، بدون داده و بدون احراز هویت.
    // با ساخت پنل واقعی حذف می‌شود.
    Route::get('/panel-preview', [\App\Http\Controllers\PanelPreviewController::class, 'dashboard'])->name('panel.preview');
    Route::get('/panel-preview/server', [\App\Http\Controllers\PanelPreviewController::class, 'server'])->name('panel.preview.server');
    Route::get('/panel-preview/admin', [\App\Http\Controllers\PanelPreviewController::class, 'adminDashboard'])->name('panel.preview.admin');
    Route::get('/panel-preview/tickets', [\App\Http\Controllers\PanelPreviewController::class, 'tickets'])->name('panel.preview.tickets');
    Route::get('/panel-preview/admin/tickets', [\App\Http\Controllers\PanelPreviewController::class, 'adminTickets'])->name('panel.preview.admin.tickets');

    // مستندات
    Route::get('/docs', [\App\Http\Controllers\DocsController::class, 'index'])->name('docs.index');
    Route::get('/docs/{slug}', [\App\Http\Controllers\DocsController::class, 'show'])->name('docs')->where('slug', '[a-z0-9-]+');

    // بلاگ
    Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
    Route::get('/blog/mod/{comment}/{action}', [BlogController::class, 'moderate'])->name('blog.moderate')->where('action', 'approve|delete');
    Route::post('/blog/{slug}/comment', [BlogController::class, 'comment'])->name('blog.comment')->where('slug', '[a-z0-9-]+')->middleware('throttle:forms');
    Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog')->where('slug', '[a-z0-9-]+');

    // صفحات راهکار سازمانی
    Route::get('/solutions/{slug}', [SolutionController::class, 'show'])->name('solution')->where('slug', '[a-z-]+');
    Route::get('/{category}/{slug}', [CatalogController::class, 'show'])->name('catalog')
        ->whereIn('category', ['vps', 'dedicated', 'cloud', 'domain', 'services'])->where('slug', '[a-z0-9-]+');
    Route::post('/api/chat', ChatController::class)->name('chat')->middleware('throttle:ai');
    Route::post('/api/domain-check', DomainCheckController::class)->name('domain.check')->middleware('throttle:tools');

    // جستجوی دامنه از رسیلری (OpenProvider) — مسیر جدید، جدا از مسیر WHMCS بالا
    Route::get('/domains', [\App\Http\Controllers\DomainSearchController::class, 'page'])->name('domain.search');
    Route::post('/api/domains/search', [\App\Http\Controllers\DomainSearchController::class, 'check'])
        ->name('domain.search.check')->middleware('throttle:tools');
    Route::get('/api/domains/status', [\App\Http\Controllers\DomainSearchController::class, 'status'])
        ->name('domain.status')->middleware('throttle:tools');
    Route::post('/api/builder', [AiBuilderController::class, 'chat'])->name('builder.chat')->middleware('throttle:ai');
    Route::post('/api/builder/save', [AiBuilderController::class, 'save'])->name('builder.save')->middleware('throttle:tools');

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
        Route::post('/register/resend', [Auth\RegisterController::class, 'resend'])->name('register.resend')->middleware('throttle:otp');

        Route::get('/register/identity', [Auth\RegisterController::class, 'showIdentity'])->name('register.identity.form');
        Route::post('/register/identity', [Auth\RegisterController::class, 'identity'])->name('register.identity')->middleware('throttle:kyc');

        Route::get('/register/finish', [Auth\RegisterController::class, 'showFinish'])->name('register.finish.form');
        Route::post('/register/finish', [Auth\RegisterController::class, 'finish'])->name('register.finish')->middleware('throttle:reg');

        Route::get('/login', [Auth\LoginController::class, 'show'])->name('login');
        Route::post('/login', [Auth\LoginController::class, 'start'])->name('login.start')->middleware('throttle:signin');
        Route::get('/login/code', [Auth\LoginController::class, 'code'])->name('login.code');
        Route::post('/login/verify', [Auth\LoginController::class, 'verify'])->name('login.verify')->middleware('throttle:otp');
        Route::post('/login/resend', [Auth\LoginController::class, 'resend'])->name('login.resend')->middleware('throttle:otp');
    });

    Route::post('/logout', [Auth\LoginController::class, 'logout'])->name('logout');

    Route::middleware(['auth:customer', \App\Http\Middleware\EnforceCustomerIp::class])->prefix('account')->name('account.')->group(function () {
        Route::get('/', [Account\AccountController::class, 'home'])->name('home');
        Route::get('/services', [Account\ServiceController::class, 'index'])->name('services');
        Route::get('/services/{service}/cpanel', [Account\ServiceController::class, 'cpanel'])->name('services.cpanel');
        Route::get('/services/{service}/stats', [Account\ServiceController::class, 'stats'])->name('services.stats');
        // خرید — از دکمهٔ خریدِ سایت اصلی مستقیم به تسویهٔ همان پکیج در پنل
        Route::get('/store', [Account\StoreController::class, 'index'])->name('store');            // به کاتالوگِ سایت اصلی می‌فرستد
        Route::get('/order/{product:slug}', [Account\StoreController::class, 'checkout'])->name('order');
        Route::post('/order/{product:slug}', [Account\StoreController::class, 'order'])->name('order.place')->middleware('throttle:12,1');
        Route::get('/profile', [Account\AccountController::class, 'profile'])->name('profile');
        // احراز هویت — به‌ویژه کاربرِ حقوقی (اطلاعات شرکت + معرفی‌نامه + اساسنامه)
        Route::get('/verify', [Account\VerificationController::class, 'show'])->name('verify');
        Route::post('/verify', [Account\VerificationController::class, 'submit'])->name('verify.submit')->middleware('throttle:forms');
        Route::get('/bank', [Account\BankAccountController::class, 'index'])->name('bank');
        Route::post('/bank', [Account\BankAccountController::class, 'store'])->name('bank.store')->middleware('throttle:bank');

        // امنیت حساب — رمز (با OTP)، قوانین IP، توکن‌های API
        Route::get('/security', [Account\SecurityController::class, 'index'])->name('security');
        Route::post('/security/password/start', [Account\SecurityController::class, 'passwordStart'])->name('security.pw.start')->middleware('throttle:otp');
        Route::post('/security/password', [Account\SecurityController::class, 'passwordVerify'])->name('security.pw')->middleware('throttle:otp');
        Route::post('/security/ip', [Account\SecurityController::class, 'ipStore'])->name('security.ip')->middleware('throttle:forms');
        Route::post('/security/ip/{rule}/delete', [Account\SecurityController::class, 'ipDestroy'])->name('security.ip.delete');
        Route::post('/security/ip-mode', [Account\SecurityController::class, 'ipMode'])->name('security.ipmode')->middleware('throttle:forms');
        Route::post('/security/api-token', [Account\SecurityController::class, 'tokenStore'])->name('security.token')->middleware('throttle:forms');
        Route::post('/security/api-token/{token}/delete', [Account\SecurityController::class, 'tokenDestroy'])->name('security.token.delete');

        Route::get('/invoices', [Account\PaymentController::class, 'index'])->name('invoices');
        Route::get('/invoices/{invoice}', [Account\PaymentController::class, 'show'])->name('invoice');
        Route::get('/invoices/{invoice}/print', [Account\PaymentController::class, 'printInvoice'])->name('invoice.print');
        Route::post('/invoices/{invoice}/pay', [Account\PaymentController::class, 'pay'])
            ->name('invoice.pay')->middleware('throttle:pay');
        Route::post('/invoices/{invoice}/bank-transfer', [Account\PaymentController::class, 'bankTransfer'])
            ->name('invoice.bank')->middleware('throttle:forms');
        Route::post('/invoices/{invoice}/cancel', [Account\PaymentController::class, 'cancel'])
            ->name('invoice.cancel')->middleware('throttle:forms');

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

Route::get('/sitemap.xml', [SiteController::class, 'sitemap']);

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

    // مهم: به‌صورت پیش‌فرض هیچ درخواستی به اوپن‌پروایدر نمی‌زنیم. تلاش‌های
    // ورودِ ناموفقِ پیاپی می‌تواند حساب را حساس/قفل کند. فقط با ?probe=1
    // یک تلاش زده می‌شود، آن هم دستی.
    $probe = request()->boolean('probe');

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
        'mail_env_keys'      => (function () {
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
        'mail_env_values'    => [
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
        'mail_test'          => request()->boolean('mailtest') ? (function () {
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
        })() : 'برای تست ارسال ?mailtest=1 اضافه کنید',
        'hint'               => 'اوپن‌پروایدر: sample_code=0 یعنی وصل شد، 196 یعنی IP/رمز رد شد. زرین‌پال: code=100 یعنی درگاه سالم و ۳۰۲ همان هدایت درست به صفحهٔ پرداخت است.',
    ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
});

Route::middleware('throttle:tools')->get('/system/health', function () {
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

    return response()->json($out);
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

        return response()->json($out + [
            'mariadb' => 'connected',
            'ready'   => $missing === [],
            'missing' => $missing,
            'pending_migrations' => $pending,
            'new_tables' => $newTables,
            'counts' => $counts,
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

    try {
        \Illuminate\Support\Facades\Artisan::call('view:clear');
        \Illuminate\Support\Facades\Artisan::call('cache:clear');
    } catch (\Throwable) {
    }

    // کاتالوگِ هاست را فقط اگر جدولِ products خالی است یک‌بار می‌سازد (پکیج‌های
    // ویرایش‌شدهٔ بعدی را پاک نمی‌کند). ~۵۲ پکیج از config/hosting.php.
    $seeded = null;
    try {
        if (\Illuminate\Support\Facades\Schema::hasTable('products') && \App\Models\Product::count() === 0) {
            \Illuminate\Support\Facades\Artisan::call('products:seed-hosting');
            $seeded = trim(\Illuminate\Support\Facades\Artisan::output());
        }
    } catch (\Throwable $e) {
        $seeded = 'seed error: '.$e->getMessage();
    }

    // سرور با opcache و validate_timestamps=0 اجرا می‌شود: بدون این ریست،
    // کدِ تازه دپلوی‌شده (روت‌ها، ویوها) روی دیسک عوض شده ولی بایت‌کد قدیمی
    // سرو می‌شود. این‌جا کنار مهاجرت ریست می‌کنیم تا هر دپلوی با یک migrate
    // زنده شود.
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    // تأیید اینکه جدول‌های تازه واقعاً ساخته شدند
    $tables = ['services', 'settings', 'servers', 'products', 'customer_api_tokens', 'activity_logs', 'invoices'];
    $present = [];
    foreach ($tables as $t) {
        $present[$t] = \Illuminate\Support\Facades\Schema::hasTable($t);
    }

    return response()->json([
        'ok'      => $migrateError === null,
        'error'   => $migrateError,
        'migrate' => $migrate,
        'seeded'  => $seeded,
        'tables'  => $present,
    ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
})->middleware('throttle:6,1');

/*
| ریست opcache — بدون مهاجرت.
|
| سرور opcache را با validate_timestamps=0 اجرا می‌کند، پس فایل PHPِ
| ویرایش‌شده تا ریست شدنِ opcache زنده نمی‌شود. این روت برای دپلوی‌هایی است
| که فقط کد عوض می‌کنند و مهاجرت ندارند. توکن‌دار و POST، مثل بقیهٔ system.
*/
Route::post('/system/opcache', function (\Illuminate\Http\Request $r) {
    $expected = (string) env('DEPLOY_TOKEN', '');
    abort_if($expected === '' || ! hash_equals($expected, (string) $r->input('token', '')), 404);

    \Illuminate\Support\Facades\Artisan::call('view:clear');
    \Illuminate\Support\Facades\Artisan::call('cache:clear');
    $reset = function_exists('opcache_reset') ? @opcache_reset() : null;

    return response()->json([
        'opcache_reset' => $reset,
        'note'          => $reset ? 'opcache پاک شد؛ کدِ تازه زنده است.' : 'opcache در دسترس نبود.',
    ], 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
})->middleware('throttle:6,1');

/*
| پنل مدیریت محتوا (/admin) — احراز هویت با سشن، غیرلوکالایز
*/
use App\Http\Controllers\Admin\AuthController as AdminAuth;
use App\Http\Controllers\Admin\CommentController as AdminComment;
use App\Http\Controllers\Admin\DashboardController as AdminDash;
use App\Http\Controllers\Admin\PostController as AdminPost;
use App\Http\Controllers\Admin\UserController as AdminUser;

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
    Route::post('/login/otp/resend', [AdminAuth::class, 'resendOtp'])->middleware('throttle:otp');
    Route::post('/logout', [AdminAuth::class, 'logout']);

    // «auth:web» صریح و نه «auth» — گارد پیش‌فرض ممکن است در طول یک درخواست
    // عوض شود؛ پنل مدیریت باید همیشه دقیقاً گارد کارکنان را بخواهد.
    Route::middleware('auth:web')->group(function () {
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
        Route::get('/users', [AdminUser::class, 'index']);
        Route::post('/users', [AdminUser::class, 'store']);
        Route::post('/users/{user}/delete', [AdminUser::class, 'destroy']);

        // ردیاب خطای سرور و ۴۰۴
        Route::get('/errors', [\App\Http\Controllers\Admin\ErrorLogController::class, 'index'])->name('admin.errors');
        Route::post('/errors/clear', [\App\Http\Controllers\Admin\ErrorLogController::class, 'clear']);

        // داشبورد مالی کسب‌وکار — سرمایه، سود، مالیات
        Route::get('/finance', [\App\Http\Controllers\Admin\FinanceController::class, 'index'])->name('admin.finance');
        Route::post('/finance', [\App\Http\Controllers\Admin\FinanceController::class, 'store']);
        Route::post('/finance/{entry}/delete', [\App\Http\Controllers\Admin\FinanceController::class, 'destroy']);

        // تراکنش‌ها و اعتبار — پرداخت‌های ریز + دفتر اعتبار + بدهیِ اعتبارِ مشتریان
        Route::get('/transactions', [\App\Http\Controllers\Admin\TransactionController::class, 'index'])->name('admin.transactions');

        // تیکت پشتیبانی — روی همان احراز هویت کارکنان
        Route::get('/tickets', [\App\Http\Controllers\Admin\TicketController::class, 'index'])->name('admin.tickets');
        Route::get('/tickets/{ticket}', [\App\Http\Controllers\Admin\TicketController::class, 'show'])->name('admin.ticket');
        Route::post('/tickets/{ticket}/reply', [\App\Http\Controllers\Admin\TicketController::class, 'reply']);
        Route::post('/tickets/{ticket}/update', [\App\Http\Controllers\Admin\TicketController::class, 'update']);
        Route::get('/tickets/{ticket}/attachments/{attachment}', [\App\Http\Controllers\Admin\TicketController::class, 'attachment']);

        // مدیریت مشتریان — بخشِ شبیه‌WHMCS
        Route::get('/customers', [\App\Http\Controllers\Admin\CustomerController::class, 'index'])->name('admin.customers');
        Route::get('/customers/{customer}', [\App\Http\Controllers\Admin\CustomerController::class, 'show'])->name('admin.customer');
        Route::post('/customers/{customer}/status', [\App\Http\Controllers\Admin\CustomerController::class, 'status']);
        Route::post('/customers/{customer}/password', [\App\Http\Controllers\Admin\CustomerController::class, 'password']);
        Route::post('/customers/{customer}/delete', [\App\Http\Controllers\Admin\CustomerController::class, 'destroy']);
        // حذف فاکتورِ پرداخت‌نشده (فاکتورِ پرداخت‌شده هرگز حذف نمی‌شود)
        Route::post('/invoices/{invoice}/delete', [\App\Http\Controllers\Admin\CustomerController::class, 'destroyInvoice']);

        // فروش و مدیریت سرویس‌های مشتری
        Route::post('/customers/{customer}/services', [\App\Http\Controllers\Admin\ServiceController::class, 'store']);
        Route::post('/services/{service}/status', [\App\Http\Controllers\Admin\ServiceController::class, 'update']);
        Route::post('/services/{service}/renew', [\App\Http\Controllers\Admin\ServiceController::class, 'renew']);

        // سرورهای تحویل (WHM/cPanel/…)
        // احراز هویتِ مشتریان — صفِ بررسی، تأیید/رد، دانلودِ امنِ مدارک
        Route::get('/verifications', [\App\Http\Controllers\Admin\VerificationController::class, 'index'])->name('admin.verifications');
        Route::get('/verifications/{profile}/doc/{document}', [\App\Http\Controllers\Admin\VerificationController::class, 'document'])->name('admin.verification.doc');
        Route::post('/verifications/{profile}/approve', [\App\Http\Controllers\Admin\VerificationController::class, 'approve']);
        Route::post('/verifications/{profile}/reject', [\App\Http\Controllers\Admin\VerificationController::class, 'reject']);

        Route::get('/servers', [\App\Http\Controllers\Admin\ServerController::class, 'index'])->name('admin.servers');
        Route::post('/servers', [\App\Http\Controllers\Admin\ServerController::class, 'store']);
        Route::post('/servers/{server}', [\App\Http\Controllers\Admin\ServerController::class, 'update']);
        Route::post('/servers/{server}/test', [\App\Http\Controllers\Admin\ServerController::class, 'test']);
        Route::post('/servers/{server}/delete', [\App\Http\Controllers\Admin\ServerController::class, 'destroy']);

        // پکیج‌های فروش — کاتالوگی که مشتری از آن آنلاین می‌خرد
        Route::get('/products', [\App\Http\Controllers\Admin\ProductController::class, 'index'])->name('admin.products');
        Route::post('/products', [\App\Http\Controllers\Admin\ProductController::class, 'store']);
        Route::post('/products/{product}', [\App\Http\Controllers\Admin\ProductController::class, 'update']);
        Route::post('/products/{product}/delete', [\App\Http\Controllers\Admin\ProductController::class, 'destroy']);
        // ساختِ package در WHM از روی پکیج
        Route::post('/products/{product}/whm-sync', [\App\Http\Controllers\Admin\ProductController::class, 'syncWhm']);
        Route::post('/products-whm-sync-all', [\App\Http\Controllers\Admin\ProductController::class, 'syncWhmAll']);

        // اقداماتِ تحویلِ سرویس — ساخت/تلاش دوباره، تعلیق، حذف روی سرور
        Route::post('/services/{service}/provision', [\App\Http\Controllers\Admin\ServiceController::class, 'provision']);
        Route::post('/services/{service}/suspend', [\App\Http\Controllers\Admin\ServiceController::class, 'suspend']);
        Route::post('/services/{service}/unsuspend', [\App\Http\Controllers\Admin\ServiceController::class, 'unsuspend']);
        Route::post('/services/{service}/terminate', [\App\Http\Controllers\Admin\ServiceController::class, 'terminate']);

        // اعلان به مشتریان — یک نفر یا همه (پیامک + بله)
        Route::get('/broadcasts', [\App\Http\Controllers\Admin\BroadcastController::class, 'index'])->name('admin.broadcasts');
        Route::post('/broadcasts', [\App\Http\Controllers\Admin\BroadcastController::class, 'send']);

        // هزینه‌های ثابت سرویس‌ها — که خودِ مدیر تعیین می‌کند
        Route::get('/costs', [\App\Http\Controllers\Admin\CostController::class, 'index'])->name('admin.costs');
        Route::post('/costs', [\App\Http\Controllers\Admin\CostController::class, 'update']);
        Route::post('/costs/add', [\App\Http\Controllers\Admin\CostController::class, 'store']);
        Route::post('/costs/{cost}/delete', [\App\Http\Controllers\Admin\CostController::class, 'destroy']);

        // واریز به حساب — صف تأیید پرداخت‌های دستی
        Route::get('/bank-transfers', [\App\Http\Controllers\Admin\BankTransferController::class, 'index'])->name('admin.bank_transfers');
        Route::post('/bank-transfers/{receipt}/approve', [\App\Http\Controllers\Admin\BankTransferController::class, 'approve']);
        Route::post('/bank-transfers/{receipt}/reject', [\App\Http\Controllers\Admin\BankTransferController::class, 'reject']);

        // تنظیمات — مشخصات حساب بانکی شرکت
        Route::get('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'edit'])->name('admin.settings');
        Route::post('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update']);
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
    ->middleware(\App\Http\Middleware\CustomerApiToken::class.':read')
    ->group(function () {
        Route::get('/me', [\App\Http\Controllers\Api\CustomerApiController::class, 'me']);
        Route::get('/services', [\App\Http\Controllers\Api\CustomerApiController::class, 'services']);
        Route::get('/invoices', [\App\Http\Controllers\Api\CustomerApiController::class, 'invoices']);
        Route::get('/credit', [\App\Http\Controllers\Api\CustomerApiController::class, 'credit']);
    });
