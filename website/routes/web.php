<?php

use App\Http\Controllers\AiBuilderController;
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
    Route::post('/careers/apply', [\App\Http\Controllers\CareersController::class, 'apply'])->name('careers.apply')->middleware('throttle:5,10');
    Route::get('/about', fn () => app(SiteController::class)->page('about'))->name('about');
    Route::get('/privacy', fn () => app(SiteController::class)->page('privacy'))->name('privacy');
    Route::get('/terms', fn () => app(SiteController::class)->page('terms'))->name('terms');

    Route::get('/tools/{slug}', [ToolController::class, 'show'])->name('tools')->where('slug', '[a-z-]+');
    Route::post('/api/audit', [ToolController::class, 'audit'])->name('api.audit')->middleware('throttle:10,1');
    Route::post('/api/whois', [ToolController::class, 'whois'])->name('api.whois')->middleware('throttle:20,1');
    Route::post('/api/ip', [ToolController::class, 'ip'])->name('api.ip')->middleware('throttle:20,1');

    // ابزارهای جامع DNS و شبکه (هاب)
    Route::get('/dns-lookup', [LookupController::class, 'hub'])->name('hub.dns')->defaults('hub', 'dns');
    Route::get('/network-scan', [LookupController::class, 'hub'])->name('hub.network')->defaults('hub', 'network');
    Route::post('/api/dns-report', [LookupController::class, 'dnsReport'])->name('api.dnsreport')->middleware('throttle:15,1');

    // مجموعه ابزار DNS و شبکه (Lookup) — صفحات تکی سئویی
    Route::get('/lookup', [LookupController::class, 'index'])->name('lookup.index');
    Route::get('/lookup/{type}', [LookupController::class, 'show'])->name('lookup')->where('type', '[a-z-]+');
    Route::post('/api/lookup', [LookupController::class, 'run'])->name('api.lookup')->middleware('throttle:30,1');

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
    Route::post('/blog/{slug}/comment', [BlogController::class, 'comment'])->name('blog.comment')->where('slug', '[a-z0-9-]+')->middleware('throttle:6,10');
    Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog')->where('slug', '[a-z0-9-]+');

    // صفحات راهکار سازمانی
    Route::get('/solutions/{slug}', [SolutionController::class, 'show'])->name('solution')->where('slug', '[a-z-]+');
    Route::get('/{category}/{slug}', [CatalogController::class, 'show'])->name('catalog')
        ->whereIn('category', ['vps', 'dedicated', 'cloud', 'domain', 'services'])->where('slug', '[a-z0-9-]+');
    Route::post('/api/chat', ChatController::class)->name('chat')->middleware('throttle:12,1');
    Route::post('/api/domain-check', DomainCheckController::class)->name('domain.check')->middleware('throttle:30,1');

    // جستجوی دامنه از رسیلری (OpenProvider) — مسیر جدید، جدا از مسیر WHMCS بالا
    Route::get('/domains', [\App\Http\Controllers\DomainSearchController::class, 'page'])->name('domain.search');
    Route::post('/api/domains/search', [\App\Http\Controllers\DomainSearchController::class, 'check'])
        ->name('domain.search.check')->middleware('throttle:20,1');
    Route::get('/api/domains/status', [\App\Http\Controllers\DomainSearchController::class, 'status'])
        ->name('domain.status')->middleware('throttle:10,1');
    Route::post('/api/builder', [AiBuilderController::class, 'chat'])->name('builder.chat')->middleware('throttle:5,1');
    Route::post('/api/builder/save', [AiBuilderController::class, 'save'])->name('builder.save')->middleware('throttle:10,1');
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
    Route::get('/login', [AdminAuth::class, 'showLogin'])->name('login');
    // محدودیت نرخ: جلوگیری از حمله‌ی جستجوی فراگیر روی رمز مدیر
    Route::post('/login', [AdminAuth::class, 'login'])->middleware('throttle:5,1');
    Route::post('/logout', [AdminAuth::class, 'logout']);

    Route::middleware('auth')->group(function () {
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
