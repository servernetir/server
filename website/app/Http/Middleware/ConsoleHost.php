<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * جداکردن کنسول از سایت فروش.
 *
 * هر دو میزبان یک اپ لاراول‌اند (یک کد، یک نشست، یک دیتابیس)، ولی هر کدام
 * فقط بخش خودش را نشان می‌دهد:
 *
 *   console.servernet.cloud   → ورود، ثبت‌نام، پنل کاربری، پنل مدیریت
 *   servernet.cloud           → سایت فروش، بلاگ، ابزارها (بازاریابی)
 *
 * ═══ دو جهت ریدایرکت ═══
 *
 *   ۱) روی کنسول، مسیرِ غیرپنلی (بلاگ، ابزار) → ۳۰۱ به دامنهٔ اصلی.
 *      چون اگر هر دو میزبان یک بلاگ بدهند، گوگل محتوای تکراری می‌بیند.
 *
 *   ۲) روی دامنهٔ اصلی، مسیرِ پنلی (ورود، ثبت‌نام، حساب، مدیریت) → ۳۰۱ به
 *      کنسول. کارفرما خواست همهٔ پنل روی کنسول باشد؛ این تضمین می‌کند حتی
 *      لینک قدیمی هم به جای درست برود.
 *
 * فقط GET ریدایرکت می‌شود — POST (ارسال فرم) نباید ۳۰۱ شود چون بدنه‌اش گم
 * می‌شود. فرم‌ها روی کنسول رندر می‌شوند و مستقیم به کنسول POST می‌کنند.
 */
class ConsoleHost
{
    private const CONSOLE = 'https://console.servernet.cloud/';
    private const MAIN    = 'https://servernet.cloud/';

    /** مسیرهای پنلی — روی کنسول مجازند، روی دامنهٔ اصلی به کنسول می‌روند */
    private const PANEL = [
        'login', 'logout', 'register', 'register/*',
        'account', 'account/*',
        'admin', 'admin/*',
        'en/login', 'en/logout', 'en/register', 'en/register/*', 'en/account', 'en/account/*',
        'tr/login', 'tr/logout', 'tr/register', 'tr/register/*', 'tr/account', 'tr/account/*',
    ];

    /** روی کنسول اینها هم مجازند (علاوه بر پنل) */
    private const CONSOLE_EXTRA = [
        'assets/*', 'favicon.ico', 'favicon.svg', 'robots.txt', 'up',
        // بازگشت درگاه پرداخت: اگر پرداخت روی کنسول شروع شده، callback هم
        // باید روی کنسول بنشیند وگرنه وسط تسویه به دامنهٔ اصلی پرت می‌شود
        'payment/*',
        // AJAX پنل (وضعیت دامنه و…) نباید ۳۰۱ شود
        'api/*',
        // وب‌هوک‌ها و پل‌های سرور-به-سرور باید روی هر میزبانی کار کنند
        'bale/webhook/*', 'system/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isConsole($request)) {
            return $this->onConsole($request, $next);
        }

        // ریدایرکت به کنسول فقط روی دامنهٔ واقعی تولید — نه localhost یا
        // محیط تست، وگرنه توسعهٔ محلی هم به کنسول تولید پرت می‌شود
        if ($this->isMainProd($request) && $request->isMethod('GET') && $request->is(self::PANEL)) {
            return redirect()->away(self::CONSOLE.ltrim($request->getRequestUri(), '/'), 301);
        }

        return $next($request);
    }

    private function onConsole(Request $request, Closure $next): Response
    {
        // ریشهٔ کنسول: کاربر واردشده → داشبورد، بقیه → ورود
        if ($request->path() === '/') {
            $prefix = \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';

            return redirect()->route(
                Auth::guard('customer')->check() ? $prefix.'account.home' : $prefix.'login',
            );
        }

        // مسیر غیرپنلی روی کنسول → به دامنهٔ اصلی
        if (! $request->is([...self::PANEL, ...self::CONSOLE_EXTRA])) {
            return redirect()->away(self::MAIN.ltrim($request->getRequestUri(), '/'), 301);
        }

        $response = $next($request);
        // پنل و ورود هیچ ارزش جستجویی ندارند
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function isConsole(Request $request): bool
    {
        return str_starts_with(strtolower($request->getHost()), 'console.');
    }

    /** فقط دامنهٔ واقعی تولید — localhost و تست مستثنا */
    private function isMainProd(Request $request): bool
    {
        return in_array(strtolower($request->getHost()), ['servernet.cloud', 'www.servernet.cloud'], true);
    }
}
