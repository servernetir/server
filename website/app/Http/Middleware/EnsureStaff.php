<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * مدیر **یا** پشتیبان — بخش‌های امورِ پشتیبانی.
 *
 * ═══ 🔴 حفره‌ای که این میان‌افزار می‌بندد ═══
 *
 * تا امروز روت‌های `/admin/tickets*` و `/admin/customers*` **هیچ گاردِ نقشی
 * نداشتند**؛ فقط `auth:web`. و پنل عمداً اجازه می‌دهد کاربر با نقشِ `author`
 * (برای نوشتنِ بلاگ) ساخته شود. یعنی یک نویسنده می‌توانست پروندهٔ کاملِ
 * مشتریان را ببیند، و بدتر — `POST /admin/customers/{id}/password` و
 * `/status` و `/reseller` هم گاردِ داخلی نداشتند، پس می‌توانست **رمزِ هر
 * مشتری را عوض کند**. این هیچ‌وقت تصمیم نبود؛ فقط کسی نقش را نسنجیده بود.
 *
 * حالا: کارهای پشتیبانی پشتِ این میان‌افزار (مدیر + پشتیبان)، و کارهای
 * خطرناک/مالی همچنان پشتِ `EnsureAdmin` (فقط مدیر).
 *
 * ۴۰۳ و نه ۴۰۴ — به همان دلیلِ ثبت‌شده در `EnsureAdmin`: مخاطب یک همکارِ
 * واردشده است، نه غریبه.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        abort_unless($user !== null && $user->isStaff(), 403,
            'این بخش فقط برای مدیر و کارشناسانِ پشتیبانی است.');

        return $next($request);
    }
}
