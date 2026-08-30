<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * شبکهٔ یک ماهِ شمسی برای دیت‌پیکر — **همهٔ ریاضی این‌جا**.
 *
 * ═══ 🔴 چرا تبدیل در جاوااسکریپت انجام نمی‌شود ═══
 *
 * قاعدهٔ ثبت‌شدهٔ این پروژه (CLAUDE.md، بخشِ تقویم): «ریاضیِ جلالی فقط در PHP
 * است… وگرنه مرورگر باید الگوریتم را دوباره پیاده کند و دو پیاده‌سازی روزی یک
 * روز اختلاف پیدا می‌کنند — در صفحه‌ای که سررسیدِ فاکتور نشان می‌دهد.»
 *
 * این فیلد از آن هم حساس‌تر است: خروجی‌اش `services.next_due_at` را می‌نویسد،
 * یعنی تاریخی که کرونِ تمدید و کرونِ تعلیق هر دو رویش تصمیم می‌گیرند. یک روز
 * خطا یعنی فاکتورِ زودهنگام یا تعلیقِ ناخواسته.
 *
 * پس پاسخ برای هر خانه **تاریخِ میلادیِ آماده** می‌دهد و مرورگر فقط همان را
 * در فیلد می‌گذارد. هیچ تبدیلی سمتِ کاربر نیست — نه امروز، نه وقتی کسی فردا
 * این پیکر را جای دیگری استفاده کند.
 *
 * ⚠️ سالِ کبیسه هم همین‌جا حل می‌شود (`Jalali::daysInMonth`)، جایی که از قبل
 * تست دارد. همان باگِ «اسفندِ ۳۰ روزه» که یک بار `jalali_ymd()` را گاز گرفت.
 */
class JalaliDateController extends Controller
{
    /** بازهٔ منطقیِ سال — جلوی حلقه‌های بی‌معنی و ورودیِ مخرب را می‌گیرد */
    private const MIN_YEAR = 1300;

    private const MAX_YEAR = 1500;

    public function month(Request $request): JsonResponse
    {
        /*
        | ⚠️ اعتبارسنجیِ دستی، نه `$request->validate()`.
        |
        | `bootstrap/app.php` می‌گوید `shouldRenderJsonWhen(is('api/*'))`, پس
        | خطای اعتبارسنجی روی `/admin/*` یک **۳۰۲ به صفحهٔ قبل** است حتی با
        | `Accept: application/json` — و `fetch` یک صفحهٔ HTML می‌گیرد و
        | `r.json()` می‌ترکد. همان تلهٔ ثبت‌شدهٔ `CalendarController::check()`.
        */
        // ⚠️ لیست برمی‌گرداند نه آرایهٔ کلیددار: [jy, jm, jd]
        [$ty, $tm, $td] = Jalali::ofMoment(now(), (string) config('calendar.display_timezone', 'Asia/Tehran'));

        $jy = (int) ($request->query('y') ?: $ty);
        $jm = (int) ($request->query('m') ?: $tm);

        // نرمال‌سازیِ سرریزِ ماه: ۱۳ → فروردینِ سالِ بعد، ۰ → اسفندِ سالِ قبل
        while ($jm > 12) {
            $jm -= 12;
            $jy++;
        }

        while ($jm < 1) {
            $jm += 12;
            $jy--;
        }

        if ($jy < self::MIN_YEAR || $jy > self::MAX_YEAR) {
            return response()->json(['ok' => false, 'error' => 'out_of_range'], 422);
        }

        $tz = (string) config('calendar.display_timezone', 'Asia/Tehran');
        $days = Jalali::daysInMonth($jy, $jm);

        // ستونِ روزِ اولِ ماه (۰ = شنبه)
        $first = Jalali::startOfDay($jy, $jm, 1, $tz);
        $lead = Jalali::weekdayIndex($first);

        $cells = [];

        for ($d = 1; $d <= $days; $d++) {
            $moment = Jalali::startOfDay($jy, $jm, $d, $tz);

            $cells[] = [
                'd'     => $d,
                'label' => fa_num((string) $d),
                // 🔴 تاریخِ میلادیِ آماده — مرورگر هیچ تبدیلی نمی‌کند
                'iso'   => $moment->toDateString(),
                'today' => $jy === $ty && $jm === $tm && $d === $td,
            ];
        }

        return response()->json([
            'ok'    => true,
            'jy'    => $jy,
            'jm'    => $jm,
            'title' => Jalali::monthName($jm).' '.fa_num((string) $jy),
            'lead'  => $lead,
            'cells' => $cells,
            'dows'  => ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'],
        ]);
    }
}
