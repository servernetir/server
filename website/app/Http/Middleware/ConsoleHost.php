<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * جداکردن کنسول از سایت فروش.
 *
 * هر دو میزبان یک اپ لاراول‌اند (یک کد، یک نشست، یک دیتابیس)، ولی نباید یک
 * چیز نشان بدهند:
 *
 *   console.servernet.cloud/        → ورود یا داشبورد، نه صفحهٔ فروش
 *   console.servernet.cloud/blog    → ۳۰۱ به دامنهٔ اصلی
 *   servernet.cloud/account         → همچنان کار می‌کند (کسی که لینک قدیمی دارد)
 *
 * چرا ۳۰۱ و نه ۴۰۴: اگر هر دو میزبان همان بلاگ را سرو کنند، گوگل محتوای
 * تکراری می‌بیند و رتبهٔ دامنهٔ اصلی را می‌شکند. ریدایرکت دائم این را تمیز
 * حل می‌کند و لینک‌های اشتباه هم به جای درست می‌روند.
 *
 * ضمناً کل کنسول noindex می‌شود — صفحهٔ ورود و پنل هیچ ارزش جستجویی ندارند.
 */
class ConsoleHost
{
    /** مسیرهایی که روی کنسول معنی دارند */
    private const ALLOWED = [
        'account', 'account/*',
        'login', 'logout',
        'register', 'register/*',
        'en/account', 'en/account/*', 'en/login', 'en/logout', 'en/register', 'en/register/*',
        'tr/account', 'tr/account/*', 'tr/login', 'tr/logout', 'tr/register', 'tr/register/*',
        // دارایی‌های ثابت و بررسی سلامت
        'assets/*', 'favicon.ico', 'robots.txt', 'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isConsole($request)) {
            return $next($request);
        }

        // ریشهٔ کنسول: کاربر واردشده مستقیم به داشبورد، بقیه به ورود
        if ($request->path() === '/') {
            $prefix = \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';

            return redirect()->route(
                Auth::guard('customer')->check() ? $prefix.'account.home' : $prefix.'login',
            );
        }

        if (! $request->is(self::ALLOWED)) {
            return redirect()->away('https://servernet.cloud/'.ltrim($request->getRequestUri(), '/'), 301);
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function isConsole(Request $request): bool
    {
        return str_starts_with(strtolower($request->getHost()), 'console.');
    }
}
