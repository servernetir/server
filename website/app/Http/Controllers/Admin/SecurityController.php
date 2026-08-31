<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Security\QrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * امنیتِ حسابِ خودِ کاربرِ پنل — `/admin/security`.
 *
 * ⚠️ عمداً بیرونِ گاردِ `admin` است (با `withoutMiddleware`): این صفحه به
 * هیچ دادهٔ مدیریتی دست نمی‌زند و فقط حسابِ **خودِ همان کاربر** را سفت می‌کند.
 * بستنش روی نویسنده و پشتیبان یعنی ضعیف‌ترین حساب‌های پنل — همان‌هایی که
 * هدفِ ساده‌تری هستند — تنها کسانی باشند که نمی‌توانند از خودشان محافظت کنند.
 *
 * هر کاربر فقط حسابِ خودش را می‌بیند؛ هیچ مسیری برای دست‌زدن به دومرحله‌ایِ
 * کاربرِ دیگر این‌جا نیست و نباید باشد. اگر مدیری از حسابش قفل شد، برداشتنِ
 * دومرحله‌ای‌اش کارِ دیتابیس است نه یک دکمه در پنل — دکمه‌اش یعنی هر مدیری
 * می‌تواند محافظِ مدیرِ دیگر را بردارد.
 */
class SecurityController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();

        return view('admin.security', [
            'user'      => $user,
            'qr'        => $user->twoFactorPending() ? QrCode::svg($user->twoFactorUri()) : null,
            'secret'    => $user->twoFactorPending() ? $user->two_factor_secret : null,
            'recovery'  => session('tfa_recovery'),
            'leftCodes' => count($user->twoFactorRecoveryCodes()),
        ]);
    }

    public function start(): RedirectResponse
    {
        $user = Auth::user();

        if ($user->hasTwoFactor()) {
            return redirect('/admin/security')->with('err', 'ورود دومرحله‌ای از قبل روشن است.');
        }

        $user->startTwoFactorSetup();

        return redirect('/admin/security')->with('ok', 'کد QR ساخته شد. با اپلیکیشن اسکنش کنید و کدِ شش‌رقمی را وارد کنید.');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $data = $request->validate(['code' => ['required', 'string', 'max:24']]);

        if (! $user->twoFactorPending()) {
            return redirect('/admin/security')->with('err', 'اول راه‌اندازی را شروع کنید.');
        }

        $codes = $user->confirmTwoFactor($data['code']);

        if ($codes === null) {
            return redirect('/admin/security')->with('err', 'کد درست نیست. ساعتِ گوشی‌تان را با شبکه هماهنگ کنید و دوباره امتحان کنید.');
        }

        return redirect('/admin/security')
            ->with('ok', 'ورود دومرحله‌ای روشن شد.')
            ->with('tfa_recovery', $codes);
    }

    public function cancel(): RedirectResponse
    {
        $user = Auth::user();

        if ($user->twoFactorPending()) {
            $user->disableTwoFactor();
        }

        return redirect('/admin/security')->with('ok', 'راه‌اندازی لغو شد.');
    }

    public function recovery(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $reason = null;

        $data = $request->validate(['code' => ['required', 'string', 'max:24']]);

        if (! $user->hasTwoFactor() || ! $user->verifyTwoFactorCode($data['code'], $reason)) {
            return redirect('/admin/security')->with('err', $this->codeError($reason));
        }

        return redirect('/admin/security')
            ->with('ok', 'کدهای بازیابی از نو ساخته شد. کدهای قبلی دیگر کار نمی‌کنند.')
            ->with('tfa_recovery', $user->regenerateRecoveryCodes());
    }

    /** «تکراری» و «نادرست» یک پیام نمی‌گیرند — وگرنه کاربر همان کد را دوباره می‌زند */
    private function codeError(?string $reason): string
    {
        return $reason === 'replay'
            ? 'این کد قبلاً استفاده شده است. تا کد بعدی اپلیکیشن صبر کنید.'
            : 'کد درست نیست.';
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $reason = null;

        $data = $request->validate(['code' => ['required', 'string', 'max:24']]);

        if (! $user->hasTwoFactor() || ! $user->verifyTwoFactorCode($data['code'], $reason)) {
            return redirect('/admin/security')->with('err', $this->codeError($reason));
        }

        $user->disableTwoFactor();

        return redirect('/admin/security')->with('ok', 'ورود دومرحله‌ای خاموش شد.');
    }
}
