<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ورود مشتری — guard «customer»، جدا از ادمین.
 *
 * سه محافظ روی هم:
 *   throttle روی روت      → حجم درخواست
 *   RateLimiter روی IP    → حدس زدن رمز یک کاربر خاص از یک شبکه
 *   locked_until روی حساب → قفل خود حساب بعد از تلاش‌های پیاپی
 *
 * پیام خطا عمداً یکسان است («ایمیل یا رمز عبور درست نیست») تا نشود فهمید کدام
 * ایمیل‌ها در سیستم حساب دارند.
 */
class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    public function show(Request $request)
    {
        if (Auth::guard('customer')->check()) {
            return redirect()->route($this->rp().'account.home');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ], [], ['email' => 'ایمیل', 'password' => 'رمز عبور']);

        $email = mb_strtolower(trim($data['email']));
        $key   = 'login:'.$request->ip().'|'.sha1($email);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withInput($request->except('password'))->withErrors([
                'email' => 'تلاش‌های ناموفق زیاد بود. '.ceil($seconds / 60).' دقیقه دیگر تلاش کنید.',
            ]);
        }

        $customer = Customer::where('email', $email)->first();

        // مقایسه همیشه انجام می‌شود — حتی وقتی کاربر وجود ندارد — تا از روی
        // زمان پاسخ نشود فهمید کدام ایمیل ثبت شده است
        $hash = $customer?->password ?? '$2y$12$'.str_repeat('.', 53);
        $ok   = Hash::check($data['password'], $hash) && $customer !== null;

        if (! $ok) {
            RateLimiter::hit($key, self::LOCK_MINUTES * 60);
            $customer?->increment('failed_login_count');

            return back()->withInput($request->except('password'))
                ->withErrors(['email' => 'ایمیل یا رمز عبور درست نیست.']);
        }

        if ($customer->status === 'pending') {
            return back()->withInput($request->except('password'))->withErrors([
                'email' => 'ثبت‌نام این حساب کامل نشده است. دوباره ثبت‌نام را از ابتدا شروع کنید.',
            ]);
        }

        if (! $customer->isActive()) {
            return back()->withInput($request->except('password'))->withErrors([
                'email' => 'این حساب فعال نیست. با پشتیبانی تماس بگیرید.',
            ]);
        }

        RateLimiter::clear($key);

        $customer->forceFill([
            'last_login_at'     => now(),
            'failed_login_count' => 0,
        ])->save();

        Auth::guard('customer')->login($customer, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route($this->rp().'account.home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($this->rp().'home');
    }

    private function rp(): string
    {
        return \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';
    }
}
