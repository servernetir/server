<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Security\QrCode;
use App\Services\Otp\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

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
    public function index(): Response
    {
        $user = Auth::user();

        return response()->view('admin.security', [
            'user'      => $user,
            'qr'        => $user->twoFactorPending() ? QrCode::svg($user->twoFactorUri()) : null,
            'secret'    => $user->twoFactorPending() ? $user->two_factor_secret : null,
            'recovery'  => session('tfa_recovery'),
            'leftCodes' => count($user->twoFactorRecoveryCodes()),
            'smsPending' => $this->smsContext(request()) !== null,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function startSms(Request $request, OtpService $otp): RedirectResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:32',
            'current_password' => 'required|current_password:web',
        ]);
        $phone = $otp->normalize('sms', $data['phone']);
        if (! preg_match('/^09[0-9]{9}$/D', $phone)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['phone' => 'شماره موبایل ایران معتبر وارد کنید.']);
        }
        $user = Auth::user();
        $issue = $otp->issue('sms', $phone, 'admin_sms_setup:'.$user->id, $request->ip(), smsOnly: true);
        if (! $issue->ok && $issue->retryAfter === null) {
            return redirect('/admin/security')->with('err', $issue->error);
        }
        $request->session()->put('admin_sms_enrollment', [
            'user_id' => $user->id, 'phone' => $phone, 'expires_at' => now()->addMinutes(5)->timestamp,
            'password_signature' => hash('sha256', $user->password),
        ]);
        return redirect('/admin/security')->with('ok', 'کد تأیید شماره فرستاده شد. تا تأیید آن، شمارهٔ قبلی تغییر نمی‌کند.');
    }

    public function confirmSms(Request $request, OtpService $otp): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:12']);
        $ctx = $this->smsContext($request);
        if ($ctx === null) {
            return redirect('/admin/security')->with('err', 'مهلت تأیید شماره تمام شده است. دوباره شروع کنید.');
        }
        $user = Auth::user();
        $check = $otp->verify('sms', $ctx['phone'], 'admin_sms_setup:'.$user->id, $data['code']);
        if (! $check->ok) {
            return redirect('/admin/security')->with('err', $check->error);
        }
        $user->forceFill(['login_sms_phone' => $ctx['phone'], 'login_sms_confirmed_at' => now()])->save();
        $request->session()->forget('admin_sms_enrollment');
        return redirect('/admin/security')->with('ok', 'شماره تأیید شد. اکنون در صفحهٔ ورود می‌توانید روش پیامک را انتخاب کنید.');
    }

    private function smsContext(Request $request): ?array
    {
        $ctx = $request->session()->get('admin_sms_enrollment');
        $user = Auth::user();
        return is_array($ctx) && (int) ($ctx['user_id'] ?? 0) === (int) $user->id
            && ($ctx['expires_at'] ?? 0) >= now()->timestamp
            && hash_equals(hash('sha256', $user->password), (string) ($ctx['password_signature'] ?? ''))
            ? $ctx : null;
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
