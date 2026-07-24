<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\CustomerApiToken;
use App\Models\CustomerIpRule;
use App\Services\Otp\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * امنیت حساب مشتری — رمز عبور (با تأییدِ کد)، محدودسازیِ IP (لیست سفید/سیاه)
 * و توکن‌های API.
 *
 * ورود بی‌رمز (OTP) است، پس «تغییر رمز» با کدِ یک‌بارمصرف تأیید می‌شود نه با
 * رمزِ قبلی — خودِ کد اثباتِ هویت است و برای حساب‌های بدونِ رمز هم کار می‌کند.
 */
class SecurityController extends Controller
{
    public function __construct(private OtpService $otp) {}

    private function customer()
    {
        return Auth::guard('customer')->user();
    }

    public function index(Request $request): View
    {
        $c = $this->customer();

        return view('account.security', AccountController::shell('security') + [
            'customer'    => $c,
            'ipRules'     => $c->ipRules()->orderByDesc('id')->get(),
            'ipMode'      => $c->ip_restriction_mode ?? 'off',
            'apiTokens'   => $c->apiTokens()->orderByDesc('id')->get(),
            'currentIp'   => $request->ip(),
            'hasPassword' => ! empty($c->password),
            'pwReady'     => $request->session()->has('pw_change_ctx'),
        ]);
    }

    // ───────────────────────── رمز عبور (با OTP) ─────────────────────────

    public function passwordStart(Request $request): RedirectResponse
    {
        $c = $this->customer();
        $channel = $c->phone ? 'sms' : 'email';
        $destination = $channel === 'sms' ? $c->phone : $c->email;

        if (! $destination) {
            return back()->withErrors(['password' => 'راهی برای ارسال کد نداریم؛ ابتدا ایمیل یا موبایل ثبت کنید.']);
        }

        $issue = $this->otp->issue($channel, $destination, 'password_change', $request->ip());
        if (! $issue->ok && $issue->retryAfter === null) {
            return back()->withErrors(['password' => $issue->error]);
        }

        $request->session()->put('pw_change_ctx', ['channel' => $channel, 'destination' => $destination]);

        return back()->with('ok', 'کد تأیید فرستاده شد. آن را وارد کنید و رمز جدید را بگذارید.')
            ->withFragment('sec-pw');
    }

    public function passwordVerify(Request $request): RedirectResponse
    {
        $c = $this->customer();
        $ctx = $request->session()->get('pw_change_ctx');

        if (! is_array($ctx)) {
            return back()->withErrors(['password' => 'ابتدا کد تأیید را درخواست کنید.']);
        }

        $data = $request->validate([
            'code'     => ['required', 'string', 'max:12'],
            'password' => ['required', 'string', 'min:8', 'max:200', 'confirmed'],
        ], [], ['password' => 'رمز عبور جدید', 'code' => 'کد']);

        $check = $this->otp->verify($ctx['channel'], $ctx['destination'], 'password_change', $data['code']);
        if (! $check->ok) {
            return back()->withErrors(['code' => $check->error])->withFragment('sec-pw');
        }

        $c->forceFill(['password' => Hash::make($data['password'])])->save();
        $request->session()->forget('pw_change_ctx');

        \App\Models\ActivityLog::record($c->id, 'password', 'رمز عبور توسط خودِ کاربر تنظیم شد', $request, 'customer');

        return back()->with('ok', 'رمز عبور با موفقیت تنظیم شد.')->withFragment('sec-pw');
    }

    // ───────────────────────── قوانین IP ─────────────────────────

    public function ipStore(Request $request): RedirectResponse
    {
        $c = $this->customer();
        $data = $request->validate([
            'cidr'   => ['required', 'string', 'max:50'],
            'action' => ['required', 'in:allow,deny'],
            'label'  => ['nullable', 'string', 'max:64'],
        ], [], ['cidr' => 'IP یا رنج']);

        $cidr = $this->normalizeCidr($data['cidr']);
        if ($cidr === null) {
            return back()->withErrors(['cidr' => 'IP یا رنجِ CIDR معتبر نیست (مثل 1.2.3.4 یا 1.2.3.0/24).'])->withFragment('sec-ip');
        }

        if ($c->ipRules()->count() >= 50) {
            return back()->withErrors(['cidr' => 'حداکثر ۵۰ قاعده می‌توانید داشته باشید.'])->withFragment('sec-ip');
        }

        $c->ipRules()->create([
            'cidr'      => $cidr,
            'action'    => $data['action'],
            'label'     => $data['label'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('ok', 'قاعدهٔ IP اضافه شد.')->withFragment('sec-ip');
    }

    public function ipDestroy(Request $request, CustomerIpRule $rule): RedirectResponse
    {
        abort_unless($rule->customer_id === $this->customer()->id, 404);
        $rule->delete();

        return back()->with('ok', 'قاعده حذف شد.')->withFragment('sec-ip');
    }

    public function ipMode(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:off,warn,enforce']]);
        $c = $this->customer();
        $c->ip_restriction_mode = $data['mode'];
        $c->save();

        return back()->with('ok', 'حالتِ محدودسازیِ IP ذخیره شد.')->withFragment('sec-ip');
    }

    // ───────────────────────── توکن API ─────────────────────────

    public function tokenStore(Request $request): RedirectResponse
    {
        $c = $this->customer();
        $data = $request->validate(['name' => ['required', 'string', 'max:80']], [], ['name' => 'نام توکن']);

        if ($c->apiTokens()->count() >= 20) {
            return back()->withErrors(['name' => 'حداکثر ۲۰ توکن.'])->withFragment('sec-api');
        }

        [, $plain] = CustomerApiToken::issue($c->id, $data['name'], ['read']);

        // متنِ خامِ توکن فقط همین یک‌بار نشان داده می‌شود
        return back()->with('ok', 'توکن ساخته شد. همین حالا کپی‌اش کنید — دیگر نشان داده نمی‌شود.')
            ->with('new_token', $plain)->withFragment('sec-api');
    }

    public function tokenDestroy(Request $request, CustomerApiToken $token): RedirectResponse
    {
        abort_unless($token->customer_id === $this->customer()->id, 404);
        $token->delete();

        return back()->with('ok', 'توکن باطل شد.')->withFragment('sec-api');
    }

    // ───────────────────────── کمکی ─────────────────────────

    /** نرمال‌سازیِ IP/CIDR — تکِ IP به /32 یا /128؛ خروجی معتبر یا null */
    private function normalizeCidr(string $cidr): ?string
    {
        $cidr = trim($cidr);

        if (! str_contains($cidr, '/')) {
            if (filter_var($cidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $cidr.'/32';
            }
            if (filter_var($cidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return $cidr.'/128';
            }

            return null;
        }

        [$ip, $mask] = explode('/', $cidr, 2);
        if (! ctype_digit($mask)) {
            return null;
        }
        $mask = (int) $mask;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $mask >= 0 && $mask <= 32) {
            return $ip.'/'.$mask;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $mask >= 0 && $mask <= 128) {
            return $ip.'/'.$mask;
        }

        return null;
    }
}
