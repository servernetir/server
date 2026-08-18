<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'locale' => \App\Http\Middleware\SetLocale::class,
            // فقط مدیر — روی مسیرهای پرهزینه/حساسِ پنلِ مدیریت. مدیریتِ محتوا
            // عمداً برای نقشِ نویسنده باز می‌مانَد.
            'admin'  => \App\Http\Middleware\EnsureAdmin::class,
        ]);
        // کنسول قبل از هر چیز — تا ریدایرکت میزبان زودتر از رندر انجام شود
        $middleware->prepend(\App\Http\Middleware\ConsoleHost::class);

        // ...و دامنهٔ قدیمی حتی زودتر: میزبانِ servernet.ir نه کنسول است نه
        // دامنهٔ اصلی، پس باید پیش از هر منطقِ میزبانِ دیگری تعیین تکلیف شود.
        // (`prepend` آخرین صدازده اول اجرا می‌شود.)
        $middleware->prepend(\App\Http\Middleware\LegacyDomain::class);

        // هدرهای امنیتی روی همه‌ی پاسخ‌ها
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        /*
         * کشِ کاملِ صفحه برای مهمان — عمداً داخلِ گروهِ web (بعد از
         * StartSession)، چون در لحظهٔ HIT توکنِ CSRF نشستِ جاری را در HTML
         * می‌نشاند؛ global می‌بود، توکنِ نفرِ قبلی پخش می‌شد و اولین POSTِ
         * هر بازدیدکنندهٔ تازه ۴۱۹ می‌گرفت. (ممیزی ۳ — سه دور «X-Cache: none»)
         */
        $middleware->web(append: \App\Http\Middleware\PageCache::class);

        // ثبت ۴۰۴ها بر اساس وضعیت پاسخ — چون ۴۰۴ استثنایی است که report()
        // نمی‌گیردش. ۵۰۰ها از مسیر withExceptions پایین ثبت می‌شوند.
        $middleware->append(\App\Http\Middleware\TrackNotFound::class);

        /*
         * پشتِ Cloudflare هستیم. بدونِ اعتماد به پروکسی، $request->ip() آدرسِ
         * لبهٔ Cloudflare است نه کاربر — که قوانینِ IPِ مشتری، محدودیتِ per-IPِ
         * OTP (که به یک سطلِ مشترکِ سراسری فرومی‌پاشد)، مکان‌یابیِ ژئو و آدرسِ
         * لاگِ فعالیت را خراب می‌کند. فقط رنج‌های واقعیِ Cloudflare مورد اعتمادند
         * تا با زدنِ مستقیمِ سرور نشود X-Forwarded-For را جعل کرد.
         * منبع رنج‌ها: https://www.cloudflare.com/ips (به‌ندرت عوض می‌شود)
         */
        $middleware->trustProxies(at: [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ], headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        /*
         * پل پیامک از CSRF مستثناست.
         *
         * تماس‌گیرنده یک سرور است نه مرورگر: نه نشستی دارد نه توکنی. بدون
         * این استثنا هر تماس با «CSRF token mismatch» رد می‌شود — و چون
         * middleware پیش از کنترلر رد می‌کند، حتی در لاگ تشخیصی هم نمی‌افتد
         * و «فرستنده نمی‌آید» به نظر می‌رسد.
         *
         * جای CSRF را امضای HMAC گرفته است: کلید مشترک، پنجرهٔ زمانی ۱۲۰
         * ثانیه‌ای و nonce یک‌بارمصرف — که برای تماس سرور-به-سرور محافظ
         * قوی‌تری هم هست.
         */
        $middleware->validateCsrfTokens(except: [
            'api/sms/*',
            // APIِ مشتری با توکنِ Bearer احراز می‌شود، نه نشست/CSRF (فعلاً GET،
            // ولی برای روت‌های نوشتنیِ آینده از الان مستثنا می‌کنیم)
            'api/v1/*',
            // محافظش DEPLOY_TOKEN است، نه نشست؛ فرم بی‌نشست هم باید کار کند
            'system/migrate',
            // بله یک سرور است، نشست ندارد؛ محافظش توکن در مسیر است
            'bale/webhook/*',
            'system/bale-setup',
            // ریست opcache/کش بعد از دپلوی — محافظش DEPLOY_TOKEN است، نه نشست
            'system/opcache',
        ]);

        // دو ورود مستقل داریم: مدیر (/admin) و مشتری (/login با نسخهٔ زبانی).
        // بدون این تفکیک، مشتریِ وارد نشده به صفحهٔ ورود مدیر پرتاب می‌شود.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('admin', 'admin/*')) {
                return route('admin.login');
            }

            $prefix = \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';

            return route($prefix.'login');
        });

        // کاربر واردشده که به /login می‌رود، به پنل خودش برگردد
        $middleware->redirectUsersTo(function () {
            $prefix = \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';

            return route($prefix.'account.home');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // هر ۵۰۰ در ردیاب خطا ثبت می‌شود — فایل‌محور، تا حتی وقتی علتِ خطا
        // خودِ دیتابیس است هم گرفته شود. جزئیات (کلاس، پیام، فایل:خط، اولین
        // قاب در کد خودمان) نوشته می‌شود تا بشود مستقیم رفت سراغ رفعش.
        $exceptions->report(function (\Throwable $e) {
            \App\Support\ErrorTracker::exception($e, request());
        });
    })->create();
