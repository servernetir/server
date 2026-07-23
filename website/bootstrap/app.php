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
        ]);
        // کنسول قبل از هر چیز — تا ریدایرکت میزبان زودتر از رندر انجام شود
        $middleware->prepend(\App\Http\Middleware\ConsoleHost::class);

        // هدرهای امنیتی روی همه‌ی پاسخ‌ها
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

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
    })->create();
