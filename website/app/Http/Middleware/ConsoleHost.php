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
        'login', 'login/*', 'logout', 'register', 'register/*',
        'account', 'account/*',
        'admin', 'admin/*',
        'en/login', 'en/login/*', 'en/logout', 'en/register', 'en/register/*', 'en/account', 'en/account/*',
        'tr/login', 'tr/login/*', 'tr/logout', 'tr/register', 'tr/register/*', 'tr/account', 'tr/account/*',
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

    /**
     * نشانیِ یک مسیرِ **پنلی** که از هر میزبانی امن باشد.
     *
     * 🔴 چرا لازم است: `ConsoleHost` فقط **GET** را به کنسول می‌فرستد (۳۰۱ روی
     * POST بدنه را دور می‌ریزد). کامنتِ بالای همین کلاس فرضش را صریح نوشته:
     * «فرم‌ها روی کنسول رندر می‌شوند و مستقیم به کنسول POST می‌کنند.»
     *
     * ⚠️ آن فرض برای یک فرم **غلط** است: نوارِ «جای مشتری نشسته‌اید» در
     * `partials/header.blade.php` است، یعنی هدرِ **سایتِ اصلی**. مدیرِ
     * جای‌نشسته می‌تواند وسطِ کار روی `/vps/germany` یا `/blog` باشد و همان‌جا
     * دکمهٔ «بازگشت به پنل مدیریت» را ببیند — و آن فرم با `action` نسبی به
     * `servernet.cloud/admin/impersonate/stop` پست می‌کرد: یک کنشِ مدیریتی روی
     * میزبانِ بازاریابی، جایی که هیچ مسیرِ پنلی نباید بنشیند.
     *
     * امروز نشست بین دو میزبان مشترک است (`SESSION_DOMAIN=.servernet.cloud`)
     * پس معمولاً کار می‌کند؛ ولی این یک **وابستگیِ نانوشته** به یک تنظیمِ
     * `.env` است. اگر آن تنظیم روزی برداشته شود، دکمه بی‌صدا به صفحهٔ ورود
     * می‌رود و مدیر داخلِ حسابِ مشتری گیر می‌افتد — همان خرابی که یک بار
     * (به علتِ z-index) رخ داد و در کامنتِ همان فایل ثبت است.
     *
     * ⚠️ روی localhost و تست عمداً مسیرِ نسبی برمی‌گردد، وگرنه دکمهٔ محلی به
     * کنسولِ **پروداکشن** پست می‌کرد.
     */
    public static function panelUrl(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        $host = strtolower((string) request()?->getHost());

        return in_array($host, ['servernet.cloud', 'www.servernet.cloud'], true)
            ? self::CONSOLE.ltrim($path, '/')
            : $path;
    }

    /**
     * وارونهٔ `panelUrl()` — نشانیِ **دامنهٔ اصلی** برای چیزی که از سایت بیرون
     * می‌رود.
     *
     * 🔴 باگی که این را لازم کرد: لینکِ گزارشِ بررسیِ سایت با `url()` ساخته
     * می‌شد، یعنی از میزبانِ **درخواستِ جاری**. مدیر گزارش را از پنل می‌فرستد و
     * پنل روی `console.` است، پس مشتری در ایمیلش نشانیِ پنلِ مدیریتِ ما را
     * می‌دید. ریدایرکتِ ۳۰۱ بازش می‌کرد، ولی لینکی که در ایمیل نشانِ یک میزبانِ
     * ناآشنای «console» را دارد، دقیقاً شبیهِ فیشینگ است — و این ایمیل به کسی
     * می‌رود که هنوز به ما اعتماد نکرده.
     *
     * ⚠️ مثلِ `panelUrl()` روی localhost و تست دست به میزبان نمی‌زند، وگرنه
     * لینکِ محلی به پروداکشن اشاره می‌کرد.
     */
    public static function siteUrl(string $path): string
    {
        $path = ltrim($path, '/');

        return strtolower((string) request()?->getHost()) === 'console.servernet.cloud'
            ? self::MAIN.$path
            : url($path);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isConsole($request)) {
            return $this->onConsole($request, $next);
        }

        // ── www → دامنهٔ اصلی ────────────────────────────────────────────────
        //
        // 🔴 هر دو میزبان کلِ سایتِ سه‌زبانه را سرو می‌کردند و
        // `layouts/site.blade.php` تگِ canonical را از `url()->current()` — یعنی
        // از میزبانِ خودِ درخواست — می‌سازد. نتیجه: هر صفحه دو نسخهٔ
        // ایندکس‌شدنی داشت که هرکدام خودش را canonical معرفی می‌کرد و با دیگری
        // بر سرِ همان کلیدواژه رقابت می‌کرد؛ اعتبارِ لینک‌ها هم بینشان نصف می‌شد.
        //
        // فقط GET و HEAD: تغییرِ متد روی 301 بدنهٔ POST را دور می‌ریزد و فرمی که
        // به www فرستاده شده بی‌صدا خالی می‌رسید.
        //
        // ⚠️ HEAD هم لازم است. `Route::get()` در لاراول HEAD را هم می‌گیرد، پس
        // بی‌این شرط، هر ابزارِ لینک‌سنج و مانیتورِ آپ‌تایم و پروکسی که HEAD
        // می‌زند، `www.servernet.cloud/…` را منبعی زندهٔ ۲۰۰ ثبت می‌کرد
        // درحالی‌که مرورگر ۳۰۱ می‌گرفت — یعنی همان دو-نسخه‌ایِ سئویی که این
        // ریدایرکت برای رفعش نوشته شد، فقط برای خزنده‌ها باقی می‌ماند.
        if ($request->isMethodSafe() && ! $request->isMethod('OPTIONS')
            && strtolower($request->getHost()) === 'www.servernet.cloud') {
            return redirect()->away(self::MAIN.ltrim($request->getRequestUri(), '/'), 301);
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
