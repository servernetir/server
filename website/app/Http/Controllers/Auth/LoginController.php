<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Otp\OtpService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ورود مشتری با کد یک‌بارمصرف — بدون رمز.
 *
 * قاعدهٔ کارفرما: موبایل اولویت است؛ اگر کاربر به موبایلش دسترسی نداشت، با
 * ایمیل و همان‌جور کدِ یک‌بارمصرف وارد شود. دو مرحله:
 *
 *   ۱) شناسه (موبایل یا ایمیل) → کد فرستاده می‌شود
 *   ۲) کد → ورود
 *
 * اگر شناسه به هیچ حسابی نخورد (یا ثبت‌نامش نیمه‌کاره باشد)، کد فرستاده
 * نمی‌شود؛ کاربر با همان شناسهٔ پرشده به صفحهٔ ثبت‌نام هدایت می‌شود. مقصدِ
 * مرحلهٔ دو از نشست می‌آید نه از فرم، پس کاربر نمی‌تواند با کدِ یک حساب،
 * وارد حساب دیگری شود.
 */
class LoginController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function show(Request $request): View|RedirectResponse
    {
        if (Auth::guard('customer')->check()) {
            return redirect()->route($this->rp().'account.home');
        }

        return view('auth.login');
    }

    /** مرحلهٔ ۱: شناسه → ارسال کد → صفحهٔ کد */
    public function start(Request $request): View|RedirectResponse
    {
        $data = $request->validate([
            'method'     => ['required', 'in:mobile,email'],
            'identifier' => ['required', 'string', 'max:190'],
        ], [], ['identifier' => 'شناسه']);

        $channel = $data['method'] === 'email' ? 'email' : 'sms';
        $destination = $this->otp->normalize($channel, $data['identifier']);

        if ($destination === '' || ($channel === 'email' && ! filter_var($destination, FILTER_VALIDATE_EMAIL))) {
            return back()->withInput()->withErrors([
                'identifier' => $channel === 'email' ? 'ایمیل معتبر نیست.' : 'شمارهٔ موبایل معتبر نیست (مثل ۰۹۱۲۳۴۵۶۷۸۹).',
            ]);
        }

        $customer = $this->find($channel, $destination);

        // حسابِ ناموجود یا نیمه‌ثبت‌نام: کد نفرست (پول و سردرگمی)، بلکه کاربر را
        // با شناسهٔ پرشده به صفحهٔ ثبت‌نام ببر تا کار حرفه‌ای باشد. این عمداً
        // جایگزینِ رفتار قدیمیِ «ضدِّ برشماری» است — کارفرما نمایشِ صریحِ
        // «چنین حسابی نیست» و هدایت به ثبت‌نام را خواست.
        if ($customer === null || $customer->status === 'pending') {
            $prefill = $channel === 'email' ? ['email' => $destination] : ['phone' => $destination];

            return redirect()->route($this->rp().'register')
                ->withInput($prefill)
                ->with('reg_notice', __('ui.auth_no_account_signup'));
        }

        // حساب هست ولی فعال نیست (معلق/بسته): کدِ پولی نفرست، شفاف بگو.
        if (! $customer->isActive()) {
            return back()->withInput()->withErrors(['identifier' => __('ui.auth_account_blocked')]);
        }

        $issue = $this->otp->issue($channel, $destination, 'login', $request->ip());

        // شکستِ سختِ ارسال (سرویس پیامک/ایمیل پایین) — برخلاف cooldown که
        // یعنی کد قبلی هنوز هست — باید به کاربر گفته شود
        if (! $issue->ok && $issue->retryAfter === null) {
            return back()->withInput()->withErrors(['identifier' => $issue->error]);
        }

        // مقصد در نشست — مرحلهٔ ۲ نمی‌تواند دستکاری‌اش کند
        $request->session()->put('login_otp', ['channel' => $channel, 'destination' => $destination]);

        // Post-Redirect-Get: به مرحلهٔ کد هدایت می‌شویم تا رفرش دوباره POST نکند
        // و خطاهای اعتبارسنجی از نشست بیایند
        return redirect()->route($this->rp().'login.code');
    }

    /** مرحلهٔ ۲ (نمایش): فرم کد، از روی نشست */
    public function code(Request $request): View|RedirectResponse
    {
        $ctx = $request->session()->get('login_otp');

        if (! is_array($ctx)) {
            return redirect()->route($this->rp().'login');
        }

        return view('auth.login-code', [
            'channel' => $ctx['channel'],
            'masked'  => $this->mask($ctx['channel'], $ctx['destination']),
        ]);
    }

    /** مرحلهٔ ۲ (ثبت): کد → ورود */
    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        $ctx = $request->session()->get('login_otp');

        if (! is_array($ctx)) {
            return redirect()->route($this->rp().'login')
                ->withErrors(['identifier' => __('ui.auth_session_expired')]);
        }

        $check = $this->otp->verify($ctx['channel'], $ctx['destination'], 'login', $data['code']);

        if (! $check->ok) {
            return redirect()->route($this->rp().'login.code')->withErrors(['code' => $check->error]);
        }

        $customer = $this->find($ctx['channel'], $ctx['destination']);

        if ($customer === null) {
            $request->session()->forget('login_otp');

            return redirect()->route($this->rp().'login')
                ->withErrors(['identifier' => __('ui.auth_not_found')]);
        }

        if ($customer->status === 'pending') {
            return redirect()->route($this->rp().'register')
                ->withErrors(['mobile' => __('ui.auth_reg_incomplete')]);
        }

        if (! $customer->isActive()) {
            return redirect()->route($this->rp().'login.code')
                ->withErrors(['code' => __('ui.auth_account_blocked')]);
        }

        // قوانین IP (فقط اگر خودِ کاربر «سخت‌گیرانه» را روشن کرده باشد)
        if ($customer->ipBlocks($request->ip())) {
            $request->session()->forget('login_otp');

            return redirect()->route($this->rp().'login')
                ->withErrors(['identifier' => __('ui.auth_ip_blocked')]);
        }

        // ورود موفق
        $request->session()->forget('login_otp');

        $customer->forceFill([
            'last_login_at'      => now(),
            'last_login_ip'      => $request->ip(),
            'failed_login_count' => 0,
        ])->save();

        Auth::guard('customer')->login($customer, true);
        $request->session()->regenerate();

        \App\Models\ActivityLog::record($customer->id, 'login',
            __('ui.act_login', ['channel' => __($ctx['channel'] === 'email' ? 'ui.act_ch_email' : 'ui.act_ch_mobile')]),
            $request, 'customer');

        return redirect()->intended(route($this->rp().'account.home'));
    }

    /** ارسال دوبارهٔ کد */
    public function resend(Request $request): RedirectResponse
    {
        $ctx = $request->session()->get('login_otp');

        if (! is_array($ctx)) {
            return redirect()->route($this->rp().'login');
        }

        $customer = $this->find($ctx['channel'], $ctx['destination']);

        // اگر حساب نیست/نیمه‌ثبت‌نام است، اصلاً نباید روی صفحهٔ کد باشد —
        // به ورود برگردان (هم‌راستا با start که دیگر برای این حالت کد نمی‌فرستد).
        if ($customer === null || $customer->status === 'pending') {
            $request->session()->forget('login_otp');

            return redirect()->route($this->rp().'login');
        }

        /*
        | 🔴 نتیجهٔ `issue()` باید خوانده شود.
        |
        | نسخهٔ قبلی نتیجه را دور می‌ریخت و **همیشه** می‌گفت «کد دوباره فرستاده
        | شد». ولی `issue()` شش دلیلِ متفاوت برای شکست دارد — سقفِ آی‌پی، سقفِ
        | مقصد، خنک‌کننده، مقصدِ نامعتبر، شکستِ SMTP، شکستِ درایورِ پیامک — و
        | هیچ‌کدام به کاربر نمی‌رسید.
        |
        | نتیجهٔ عملی: کاربری که کدش نمی‌آید، پنج بار «ارسال دوباره» می‌زند،
        | هر بار پیامِ سبزِ موفقیت می‌بیند، و بی‌خبر به سقفِ ساعتی می‌خورد —
        | بعد از آن حتی مسیرِ درست هم برایش بسته است. بدترین نوعِ رابط: تأییدی
        | که هیچ ربطی به واقعیت ندارد.
        */
        $issue = $this->otp->issue($ctx['channel'], $ctx['destination'], 'login', $request->ip());

        if (! $issue->ok) {
            // ⚠️ خنک‌کننده خطا نیست: کدِ قبلی هنوز معتبر است و کاربر باید
            //    همان را وارد کند، نه اینکه فکر کند چیزی خراب شده.
            return redirect()->route($this->rp().'login.code')
                ->with($issue->retryAfter === null ? 'err' : 'ok', $issue->error);
        }

        return redirect()->route($this->rp().'login.code')->with('ok', __('ui.auth_code_resent'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($this->rp().'home');
    }

    // ───────────────────────────── کمکی‌ها ─────────────────────────────

    private function find(string $channel, string $destination): ?Customer
    {
        return $channel === 'email'
            ? Customer::where('email', $destination)->first()
            : Customer::where('phone', $destination)->first();
    }

    /** نمایش نیمه‌پنهانِ مقصد — کاربر بشناسدش ولی روی صفحه کامل نیفتد */
    private function mask(string $channel, string $destination): string
    {
        if ($channel === 'email') {
            $at = strpos($destination, '@');
            if ($at === false || $at < 1) {
                return $destination;
            }
            $name = substr($destination, 0, $at);
            $head = mb_substr($name, 0, 1);

            return $head.str_repeat('*', max(1, mb_strlen($name) - 1)).substr($destination, $at);
        }

        // 0912***4567
        return mb_substr($destination, 0, 4).'***'.mb_substr($destination, -4);
    }

    private function rp(): string
    {
        return \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';
    }
}
