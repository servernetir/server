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
        Route::post('/login', [Auth\LoginController::class, 'login'])->name('login.post')->middleware('throttle:signin');
    });

    Route::post('/logout', [Auth\LoginController::class, 'logout'])->name('logout');

    Route::middleware('auth:customer')->prefix('account')->name('account.')->group(function () {
        Route::get('/', [Account\AccountController::class, 'home'])->name('home');
        Route::get('/profile', [Account\AccountController::class, 'profile'])->name('profile');
        Route::get('/bank', [Account\BankAccountController::class, 'index'])->name('bank');
        Route::post('/bank', [Account\BankAccountController::class, 'store'])->name('bank.store')->middleware('throttle:bank');
    });
};

Route::middleware('locale:fa')->group($site);
Route::prefix('en')->name('en.')->middleware('locale:en')->group($site);
Route::prefix('tr')->name('tr.')->middleware('locale:tr')->group($site);

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

        return response()->json($out + [
            'mariadb' => 'connected',
            'ready'   => $missing === [],
            'missing' => $missing,
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
Route::middleware('throttle:6,1')->get('/system/db/{token}', function (string $token) {
    $expected = (string) env('DEPLOY_TOKEN', '');
    // بدون DEPLOY_TOKEN در .env این روت اصلاً وجود ندارد — توکن هاردکد یعنی
    // هرکس به مخزن دسترسی دارد می‌تواند مهاجرت و تولید محتوای پولی را اجرا کند.
    abort_if($expected === '' || ! hash_equals($expected, $token), 404);
    $out = [];
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $out['migrate'] = trim(\Illuminate\Support\Facades\Artisan::output());
    \Illuminate\Support\Facades\Artisan::call('blog:seed-db');
    $out['seed'] = trim(\Illuminate\Support\Facades\Artisan::output());
    \Illuminate\Support\Facades\Artisan::call('docs:seed');
    $out['docs'] = trim(\Illuminate\Support\Facades\Artisan::output());
    \Illuminate\Support\Facades\Artisan::call('view:clear');
    \Illuminate\Support\Facades\Artisan::call('cache:clear');
    $out['counts'] = ['posts' => \App\Models\Post::count(), 'users' => \App\Models\User::count()];

    return response()->json($out);
});

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
    });
});
