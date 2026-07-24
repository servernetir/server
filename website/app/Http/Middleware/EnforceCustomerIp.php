<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * اعمالِ پیوستهٔ قوانینِ IP روی هر درخواستِ پنلِ مشتری.
 *
 * محدودسازیِ IP فقط در لحظهٔ ورود کافی نیست: کوکیِ «مرا به‌خاطر بسپار» و نشستِ
 * فعال، ورود را دور می‌زنند. این میدل‌ور در حالتِ «enforce» هر درخواست را
 * دوباره می‌سنجد و اگر IP مجاز نباشد کاربر را خارج می‌کند. برای حالتِ پیش‌فرضِ
 * «off» عملاً هیچ کاری نمی‌کند (فقط یک بررسیِ ویژگی، بدونِ کوئری).
 */
class EnforceCustomerIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = Auth::guard('customer')->user();

        if ($customer && $customer->ipBlocks($request->ip())) {
            Auth::guard('customer')->logout();          // توکنِ remember را هم باطل می‌کند
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $prefix = \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';

            return redirect()->route($prefix.'login')
                ->withErrors(['identifier' => 'ورود از این IP برای حساب شما مجاز نیست. برای رفعِ محدودیت با پشتیبانی تماس بگیرید.']);
        }

        return $next($request);
    }
}
