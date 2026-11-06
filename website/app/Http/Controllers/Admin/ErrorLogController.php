<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ErrorTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * ردیاب خطا — سمت مدیریت.
 * پشت احراز هویت کارکنان؛ مشتری هرگز خطاهای سرور را نمی‌بیند.
 */
class ErrorLogController extends Controller
{
    public function index(): View
    {
        // دو فایلِ جدا: سیلِ ۴۰۴ دیگر خطاهای ۵۰۰ را از پنجره بیرون نمی‌اندازد
        // Independent windows keep a noisy scheduler from hiding a web failure.
        $all = ErrorTracker::recent(1200, 'error');

        // نام «errors» ممنوع است: لاراول $errors را برای کیف خطاهای اعتبارسنجی
        // (ViewErrorBag) رزرو کرده و لایوت $errors->any() صدا می‌زند. اگر اینجا
        // یک آرایه با همان نام بفرستیم، آن را می‌پوشاند و کل پنل ۵۰۰ می‌شود.
        return view('admin.errors', [
            'serverErrors' => array_slice(array_filter($all, fn ($e) => ($e['type'] ?? '') === 'error' && ! ErrorTracker::isConsoleRecord($e)), 0, 150),
            'consoleErrors' => array_slice(array_filter($all, fn ($e) => ($e['type'] ?? '') === 'error' && ErrorTracker::isConsoleRecord($e)), 0, 150),
            'incidents'    => array_slice(array_filter($all, fn ($e) => ($e['type'] ?? '') === 'incident'), 0, 150),
            'nf'           => ErrorTracker::recent(150, 'notfound'),
            // ⚠️ سلامت **کنارِ** خطاها و نه در صفحهٔ جدا: گران‌ترین خرابی‌های این
            //    پروژه هیچ خطایی تولید نکردند. اگر مدیر برای دیدنشان باید جای
            //    دیگری برود، نمی‌رود — و همان کوری برمی‌گردد.
            'health'       => app(\App\Services\SystemHealth::class)->checks(),
        ]);
    }

    public function clear(): RedirectResponse
    {
        ErrorTracker::clear();

        return back()->with('ok', 'ردیاب خطا پاک شد.');
    }
}
