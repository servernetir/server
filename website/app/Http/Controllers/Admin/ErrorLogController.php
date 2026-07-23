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
        $all = ErrorTracker::recent(150);

        return view('admin.errors', [
            'errors' => array_filter($all, fn ($e) => ($e['type'] ?? '') === 'error'),
            'nf'     => array_filter($all, fn ($e) => ($e['type'] ?? '') === 'notfound'),
        ]);
    }

    public function clear(): RedirectResponse
    {
        ErrorTracker::clear();

        return back()->with('ok', 'ردیاب خطا پاک شد.');
    }
}
