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
        $all = ErrorTracker::recent(150, 'error');

        // نام «errors» ممنوع است: لاراول $errors را برای کیف خطاهای اعتبارسنجی
        // (ViewErrorBag) رزرو کرده و لایوت $errors->any() صدا می‌زند. اگر اینجا
        // یک آرایه با همان نام بفرستیم، آن را می‌پوشاند و کل پنل ۵۰۰ می‌شود.
        return view('admin.errors', [
            'serverErrors' => array_filter($all, fn ($e) => ($e['type'] ?? '') === 'error'),
            'incidents'    => array_filter($all, fn ($e) => ($e['type'] ?? '') === 'incident'),
            'nf'           => ErrorTracker::recent(150, 'notfound'),
        ]);
    }

    public function clear(): RedirectResponse
    {
        ErrorTracker::clear();

        return back()->with('ok', 'ردیاب خطا پاک شد.');
    }
}
