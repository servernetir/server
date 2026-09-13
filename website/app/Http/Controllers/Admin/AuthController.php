<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * ورود مدیر — دومرحله‌ای.
 *
 * مرحلهٔ ۱: ایمیل + رمز. رمز درست تأیید می‌شود ولی نشست هنوز برقرار نمی‌شود.
 * مرحلهٔ ۲: یک کد یک‌بارمصرف به ایمیلِ مدیر می‌رود؛ فقط بعد از تأیید کد،
 * نشستِ مدیر ساخته می‌شود. کلیدِ گذارِ مرحله‌ها در نشست است تا مرحلهٔ دو
 * دستکاری‌ناپذیر باشد. (کاربران users شماره‌موبایل ندارند، پس کانالِ کد ایمیل
 * است — همان موتور OtpService که ورود مشتری استفاده می‌کند، با purpose جدا.)
 */
class AuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    /** راه‌اندازی اولین مدیر — فقط وقتی هیچ کاربری وجود ندارد (خودغیرفعال‌شونده) */
    public function showSetup(): View|RedirectResponse
    {
        if (User::count() > 0) {
            return redirect('/admin/login');
        }

        return view('admin.setup');
    }

    public function setup(Request $request): RedirectResponse
    {
        if (User::count() > 0) {
            return redirect('/admin/login');
        }
        $data = $request->validate([
            'name'     => 'required|string|max:80',
            'email'    => 'required|email|max:120',
            'password' => 'required|string|min:8|max:100|confirmed',
        ]);
        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'role'     => 'admin',
            'password' => Hash::make($data['password']),
        ]);
        Auth::login($user);

        return redirect('/admin');
    }

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/admin');
        }

        return view('admin.login');
    }

    /** مرحلهٔ ۱: تأیید رمز → ارسال کد به ایمیل → صفحهٔ کد */
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        // رمز را بدون ورود بررسی می‌کنیم؛ نشست هنوز ساخته نمی‌شود
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['email' => 'ایمیل یا رمز عبور نادرست است.'])->onlyInput('email');
        }

        // نشانگرِ گذار در نشست — مرحلهٔ دو نمی‌تواند کاربر را عوض کند
        $request->session()->put('admin_2fa', [
            'user_id'  => $user->id,
            'email'    => $user->email,
            'remember' => $request->boolean('remember'),
        ]);

        $issue = $this->otp->issue('email', $user->email, 'admin_login', $request->ip());

        // شکستِ سختِ ارسال (سرویس ایمیل پایین) — cooldown یعنی کد قبلی هنوز هست
        // و اشکالی ندارد، پس فقط شکستِ واقعی را متوقف می‌کنیم
        if (! $issue->ok && $issue->retryAfter === null) {
            $request->session()->forget('admin_2fa');

            return back()->withErrors(['email' => $issue->error])->onlyInput('email');
        }

        return redirect()->route('admin.login.otp');
    }

    /** مرحلهٔ ۲ (نمایش): فرم کد، از روی نشست */
    public function showOtp(Request $request): View|RedirectResponse
    {
        $ctx = $request->session()->get('admin_2fa');

        if (! is_array($ctx)) {
            return redirect('/admin/login');
        }

        return view('admin.login-otp', ['masked' => $this->maskEmail($ctx['email'])]);
    }

    /** مرحلهٔ ۲ (ثبت): کد → ورود واقعی */
    public function verifyOtp(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        $ctx = $request->session()->get('admin_2fa');

        if (! is_array($ctx)) {
            return redirect('/admin/login')->withErrors(['email' => 'نشست منقضی شد. دوباره وارد شوید.']);
        }

        $check = $this->otp->verify('email', $ctx['email'], 'admin_login', $data['code']);

        if (! $check->ok) {
            return back()->withErrors(['code' => $check->error]);
        }

        $user = User::find($ctx['user_id']);

        if (! $user) {
            $request->session()->forget('admin_2fa');

            return redirect('/admin/login')->withErrors(['email' => 'حساب مدیر پیدا نشد.']);
        }

        /*
        | مرحلهٔ ۳ — اپلیکیشنِ احرازِ هویت، فقط اگر خودِ کاربر روشنش کرده باشد.
        |
        | 🔴 هنوز `Auth::login` نمی‌زنیم. اگر می‌زدیم و بعد کد می‌خواستیم، کاربرِ
        | نیمه‌احرازشده برای میان‌افزارِ `auth:web` وارد حساب می‌شد و کافی بود
        | مهاجم به‌جای پرکردنِ فرمِ کد، مستقیم `/admin` را باز کند.
        |
        | ⚠️ کلیدِ نشست همان `admin_2fa` نیست: کلیدِ جدا یعنی یک درخواستِ
        | جامانده از مرحلهٔ دو نمی‌تواند مرحلهٔ سه را رد کند.
        */
        if ($user->hasTwoFactor()) {
            $request->session()->forget('admin_2fa');
            $request->session()->put('admin_totp', [
                'user_id'  => $user->id,
                'remember' => (bool) ($ctx['remember'] ?? false),
            ]);

            return redirect()->route('admin.login.totp');
        }

        $request->session()->forget('admin_2fa');

        return $this->completeLogin($request, $user, (bool) ($ctx['remember'] ?? false));
    }

    /** مرحلهٔ ۳ (نمایش): فرمِ کدِ اپلیکیشن */
    public function showTotp(Request $request): View|RedirectResponse
    {
        if (! is_array($request->session()->get('admin_totp'))) {
            return redirect('/admin/login');
        }

        return view('admin.login-totp');
    }

    /** مرحلهٔ ۳ (ثبت): کدِ اپلیکیشن یا کدِ بازیابی → ورود واقعی */
    public function verifyTotp(Request $request): RedirectResponse
    {
        // ۲۴ نویسه و نه ۱۲: کدِ بازیابی (`xxxxx-xxxxx`) هم از همین فرم می‌آید
        $data = $request->validate(['code' => ['required', 'string', 'max:24']]);

        $ctx = $request->session()->get('admin_totp');

        if (! is_array($ctx)) {
            return redirect('/admin/login')->withErrors(['email' => 'نشست منقضی شد. دوباره وارد شوید.']);
        }

        $user = User::find($ctx['user_id']);

        if (! $user || ! $user->hasTwoFactor()) {
            $request->session()->forget('admin_totp');

            return redirect('/admin/login')->withErrors(['email' => 'حساب مدیر پیدا نشد.']);
        }

        if (! $user->verifyTwoFactorCode($data['code'], $reason)) {
            return back()->withErrors(['code' => $reason === 'replay'
                ? 'این کد قبلاً استفاده شده است. تا کد بعدی اپلیکیشن صبر کنید.'
                : 'کد درست نیست. اگر گوشی‌تان در دسترس نیست، یکی از کدهای بازیابی را وارد کنید.']);
        }

        $request->session()->forget('admin_totp');

        return $this->completeLogin($request, $user, (bool) ($ctx['remember'] ?? false));
    }

    /** برقراریِ نشستِ کارکنان — نقطهٔ واحدِ «از این‌جا به بعد واقعاً وارد است» */
    private function completeLogin(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended('/admin');
    }

    /** ارسال دوبارهٔ کد */
    public function resendOtp(Request $request): RedirectResponse
    {
        $ctx = $request->session()->get('admin_2fa');

        if (! is_array($ctx)) {
            return redirect('/admin/login');
        }

        // 🔴 مثلِ مسیرِ مشتری: نتیجه باید خوانده شود، وگرنه مدیر پنج بار
        //    «ارسال دوباره» می‌زند، هر بار تأیید می‌گیرد، و بی‌خبر به سقف
        //    می‌خورد — و آن‌وقت از پنلِ خودش قفل می‌شود.
        $issue = $this->otp->issue('email', $ctx['email'], 'admin_login', $request->ip());

        if (! $issue->ok) {
            return redirect()->route('admin.login.otp')
                ->with($issue->retryAfter === null ? 'err' : 'ok', $issue->error);
        }

        return redirect()->route('admin.login.otp')->with('ok', 'کد دوباره به ایمیل شما فرستاده شد.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(['admin_2fa', 'admin_totp']);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
    }

    /** نمایشِ نیمه‌پنهانِ ایمیل — مدیر بشناسدش ولی کامل روی صفحه نیفتد */
    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return $email;
        }
        $name = substr($email, 0, $at);

        return mb_substr($name, 0, 1).str_repeat('*', max(1, mb_strlen($name) - 1)).substr($email, $at);
    }
}
