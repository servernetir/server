<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * ورود مدیر — دومرحله‌ای.
 *
 * مرحلهٔ ۱: ایمیل + رمز. رمز درست تأیید می‌شود ولی نشست هنوز برقرار نمی‌شود.
 * مرحلهٔ ۲: یکی از ایمیل، شمارهٔ پیامک تأییدشده یا Authenticator تأییدشده.
 * نشست تا تأیید روش انتخابی برقرار نمی‌شود؛ مقصد و صاحب چالش از نشست سرور می‌آیند.
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
        abort_unless(strlen((string) config('auth.admin_bootstrap_token')) >= 32, 404);

        return view('admin.setup');
    }

    public function setup(Request $request): RedirectResponse
    {
        if (User::count() > 0) {
            return redirect('/admin/login');
        }
        $expected = (string) config('auth.admin_bootstrap_token');
        $given = (string) $request->header('X-Admin-Bootstrap-Token', $request->post('bootstrap_token', ''));
        abort_unless(strlen($expected) >= 32 && hash_equals($expected, $given), 403);
        $data = $request->validate([
            'name'     => 'required|string|max:80',
            'email'    => 'required|email|max:120',
            'password' => 'required|string|min:8|max:100|confirmed',
        ]);
        DB::transaction(function () use ($data) {
            // The unique settings key serializes bootstrap across different web workers.
            $claimed = DB::table('settings')->insertOrIgnore([
                'key' => 'admin_bootstrap_claimed', 'value' => '1',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            abort_unless($claimed === 1 && User::count() === 0, 409);
            User::create([
                'name' => $data['name'], 'email' => $data['email'],
                'role' => 'admin', 'password' => Hash::make($data['password']),
            ]);
        });

        return redirect('/admin/login');
    }

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/admin');
        }

        return view('admin.login');
    }

    /** تأیید رمز، سپس شروع روش انتخاب‌شدهٔ ورود. */
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
            'method' => 'sometimes|in:email,sms,totp',
        ]);

        $request->session()->forget(['admin_2fa', 'admin_totp']);

        $user = User::where('email', $data['email'])->first();

        // رمز را بدون ورود بررسی می‌کنیم؛ نشست هنوز ساخته نمی‌شود
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['email' => 'ایمیل یا رمز عبور نادرست است.'])->onlyInput('email');
        }

        $method = $data['method'] ?? 'email';
        if (($method === 'totp' && ! $user->hasTwoFactor())
            || ($method === 'sms' && ! $user->hasSmsLogin())) {
            return back()->withErrors(['method' => 'این روش برای حساب شما فعال نیست؛ از روش دیگری وارد شوید و در امنیت حساب آن را فعال کنید.'])
                ->withInput($request->only('email', 'method'));
        }
        $request->session()->regenerate();
        // The password is never stored; a credential change invalidates this pending login.
        $ctx = [
            'user_id'  => $user->id,
            'email'    => $user->email,
            'remember' => $request->boolean('remember'),
            'method' => $method,
            'expires_at' => now()->addMinutes(10)->timestamp,
            'password_signature' => hash('sha256', $user->password),
            // Preserve the previous combined flow for old clients that do not select a method.
            'legacy_totp' => ! $request->has('method'),
        ];
        if ($method === 'totp') {
            $request->session()->put('admin_totp', $ctx);
            return redirect()->route('admin.login.totp');
        }
        $ctx['destination'] = $method === 'sms' ? $user->login_sms_phone : $user->email;
        $request->session()->put('admin_2fa', $ctx);

        $issue = $this->otp->issue($method, $ctx['destination'], $this->purpose($ctx), $request->ip(), smsOnly: $method === 'sms');

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

        if (! is_array($ctx) || ! $this->pendingUser($ctx)) {
            $request->session()->forget('admin_2fa');
            return redirect('/admin/login');
        }

        return view('admin.login-otp', [
            'channel' => $ctx['method'],
            'masked' => $ctx['method'] === 'sms'
                ? substr($ctx['destination'], 0, 3).'****'.substr($ctx['destination'], -4)
                : $this->maskEmail($ctx['destination']),
        ]);
    }

    /** مرحلهٔ ۲ (ثبت): کد → ورود واقعی */
    public function verifyOtp(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        $ctx = $request->session()->get('admin_2fa');

        if (! is_array($ctx) || ! ($user = $this->pendingUser($ctx))) {
            $request->session()->forget('admin_2fa');
            return redirect('/admin/login')->withErrors(['email' => 'نشست منقضی شد. دوباره وارد شوید.']);
        }

        $check = $this->otp->verify($ctx['method'], $ctx['destination'], $this->purpose($ctx), $data['code']);

        if (! $check->ok) {
            return back()->withErrors(['code' => $check->error]);
        }

        $user = $this->pendingUser($ctx);

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
        if ($user->hasTwoFactor() && ($ctx['legacy_totp'] ?? false)) {
            $request->session()->forget('admin_2fa');
            $ctx['method'] = 'totp';
            $request->session()->put('admin_totp', $ctx);

            return redirect()->route('admin.login.totp');
        }

        $request->session()->forget('admin_2fa');

        return $this->completeLogin($request, $user, (bool) ($ctx['remember'] ?? false));
    }

    /** مرحلهٔ ۳ (نمایش): فرمِ کدِ اپلیکیشن */
    public function showTotp(Request $request): View|RedirectResponse
    {
        $ctx = $request->session()->get('admin_totp');
        if (! is_array($ctx) || ! $this->pendingUser($ctx, true)) {
            $request->session()->forget('admin_totp');
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

        if (! is_array($ctx) || ! ($user = $this->pendingUser($ctx, true))) {
            $request->session()->forget('admin_totp');
            return redirect('/admin/login')->withErrors(['email' => 'نشست منقضی شد. دوباره وارد شوید.']);
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
        $request->session()->forget(['admin_2fa', 'admin_totp']);
        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended('/admin');
    }

    /** ارسال دوبارهٔ کد */
    public function resendOtp(Request $request): RedirectResponse
    {
        $ctx = $request->session()->get('admin_2fa');

        if (! is_array($ctx) || ! $this->pendingUser($ctx)) {
            $request->session()->forget('admin_2fa');
            return redirect('/admin/login');
        }

        // 🔴 مثلِ مسیرِ مشتری: نتیجه باید خوانده شود، وگرنه مدیر پنج بار
        //    «ارسال دوباره» می‌زند، هر بار تأیید می‌گیرد، و بی‌خبر به سقف
        //    می‌خورد — و آن‌وقت از پنلِ خودش قفل می‌شود.
        $issue = $this->otp->issue($ctx['method'], $ctx['destination'], $this->purpose($ctx), $request->ip(), smsOnly: $ctx['method'] === 'sms');

        if (! $issue->ok) {
            return redirect()->route('admin.login.otp')
                ->with($issue->retryAfter === null ? 'err' : 'ok', $issue->error);
        }

        return redirect()->route('admin.login.otp')->with('ok', 'کد از طریق روش انتخاب‌شده دوباره فرستاده شد.');
    }

    private function purpose(array $ctx): string
    {
        return $ctx['method'] === 'sms' ? 'admin_login_sms:'.$ctx['user_id'] : 'admin_login';
    }

    private function pendingUser(array $ctx, bool $totp = false): ?User
    {
        if (($ctx['expires_at'] ?? 0) < now()->timestamp
            || ! in_array($ctx['method'] ?? '', $totp ? ['totp'] : ['email', 'sms'], true)) {
            return null;
        }
        $user = User::find($ctx['user_id'] ?? 0);
        if (! $user || ($ctx['email'] ?? null) !== $user->email
            || ! hash_equals(hash('sha256', $user->password), (string) ($ctx['password_signature'] ?? ''))) {
            return null;
        }
        if ($ctx['method'] === 'totp') {
            return $user->hasTwoFactor() ? $user : null;
        }
        $destination = $ctx['method'] === 'sms' ? ($user->hasSmsLogin() ? $user->login_sms_phone : null) : $user->email;
        return $destination !== null && $destination === ($ctx['destination'] ?? null) ? $user : null;
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
