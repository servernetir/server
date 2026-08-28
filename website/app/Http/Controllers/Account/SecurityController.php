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
use Illuminate\Validation\Rule;
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
            // ⚠️ توکنِ باطل‌شده نمایش داده نمی‌شود ولی **حذف هم نمی‌شود**: ردیفش
            //    برای حسابرسیِ حادثه لازم است (`reseller_api_logs.token_id`).
            'apiTokens'   => $c->apiTokens()->usable()->orderByDesc('id')->get(),
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
            return back()->withErrors(['password' => __('ui.scf_no_dest')]);
        }

        $issue = $this->otp->issue($channel, $destination, 'password_change', $request->ip());
        if (! $issue->ok && $issue->retryAfter === null) {
            return back()->withErrors(['password' => $issue->error]);
        }

        $request->session()->put('pw_change_ctx', ['channel' => $channel, 'destination' => $destination]);

        return back()->with('ok', __('ui.scf_code_sent'))
            ->withFragment('sec-pw');
    }

    public function passwordVerify(Request $request): RedirectResponse
    {
        $c = $this->customer();
        $ctx = $request->session()->get('pw_change_ctx');

        if (! is_array($ctx)) {
            return back()->withErrors(['password' => __('ui.scf_ask_code_first')]);
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

        \App\Models\ActivityLog::record($c->id, 'password', __('ui.act_pw_self'), $request, 'customer');

        return back()->with('ok', __('ui.scf_pw_set'))->withFragment('sec-pw');
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
            return back()->withErrors(['cidr' => __('ui.scf_cidr_bad')])->withFragment('sec-ip');
        }

        if ($c->ipRules()->count() >= 50) {
            return back()->withErrors(['cidr' => __('ui.scf_rule_cap')])->withFragment('sec-ip');
        }

        $c->ipRules()->create([
            'cidr'      => $cidr,
            'action'    => $data['action'],
            'label'     => $data['label'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('ok', __('ui.scf_rule_added'))->withFragment('sec-ip');
    }

    public function ipDestroy(Request $request, CustomerIpRule $rule): RedirectResponse
    {
        abort_unless($rule->customer_id === $this->customer()->id, 404);
        $rule->delete();

        return back()->with('ok', __('ui.scf_rule_deleted'))->withFragment('sec-ip');
    }

    public function ipMode(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:off,warn,enforce']]);
        $c = $this->customer();
        $c->ip_restriction_mode = $data['mode'];
        $c->save();

        return back()->with('ok', __('ui.scf_mode_saved'))->withFragment('sec-ip');
    }

    // ───────────────────────── توکن API ─────────────────────────

    public function tokenStore(Request $request): RedirectResponse
    {
        $c = $this->customer();

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:80'],
            'abilities'   => ['nullable', 'array'],
            'abilities.*' => ['string', Rule::in(array_keys(CustomerApiToken::ABILITIES))],
            'cidrs'       => ['nullable', 'string', 'max:500'],
            'expires_days'=> ['nullable', 'integer', 'min:1', 'max:1825'],
        ], [], ['name' => 'نام توکن']);

        // سقف از مدل می‌آید چون مستنداتِ /developers هم همان را چاپ می‌کند
        if ($c->apiTokens()->usable()->count() >= CustomerApiToken::MAX_ACTIVE) {
            return back()->withErrors([
                'name' => 'حداکثر '.fa_num(CustomerApiToken::MAX_ACTIVE).' توکنِ فعال.',
            ])->withFragment('sec-api');
        }

        // ⚠️ پیش‌فرضِ `read` می‌مانَد: فرمی که تیک نخورده نباید ناخواسته توکنِ
        //    نوشتنی بسازد. دسترسیِ خطرناک باید **انتخاب** شود، نه پیش‌فرض باشد.
        $abilities = array_values(array_unique($data['abilities'] ?? [])) ?: ['read'];

        /*
        | CIDRهای مجاز — نامعتبرها بی‌صدا دور ریخته نمی‌شوند، خطا می‌گیرند.
        |
        | 🔴 یک CIDRِ غلطِ نادیده‌گرفته‌شده یعنی کاربر خیال می‌کند توکنش محدود
        | شده و نیست. محافظی که کاربر اشتباه فکر کند دارد، از نداشتنش بدتر
        | است — چون بر اساسش ریسکِ بیشتری می‌پذیرد.
        */
        $cidrs = [];

        foreach (preg_split('/[\s,]+/', (string) ($data['cidrs'] ?? '')) ?: [] as $raw) {
            $raw = trim($raw);

            if ($raw === '') {
                continue;
            }

            $norm = $this->normalizeCidr($raw);

            if ($norm === null) {
                return back()->withErrors(['cidrs' => __('ui.scf_addr_bad', ['raw' => $raw])])
                    ->withFragment('sec-api');
            }

            $cidrs[] = $norm;
        }

        $days = (int) ($data['expires_days'] ?? 0);

        [, $plain] = CustomerApiToken::issue(
            $c->id,
            $data['name'],
            $abilities,
            $cidrs,
            $days > 0 ? now()->addDays($days) : null,
        );

        // متنِ خامِ توکن فقط همین یک‌بار نشان داده می‌شود
        return back()->with('ok', __('ui.scf_token_ok'))
            ->with('new_token', $plain)->withFragment('sec-api');
    }

    /**
     * ابطالِ توکن — **نرم**، نه حذفِ فیزیکی.
     *
     * 🔴 حذف دقیقاً در لحظه‌ای که کاربر می‌گوید «این توکن لو رفته»، تنها چیزی
     * را که می‌گفت آن توکن چه کرده هم پاک می‌کند: `reseller_api_logs.token_id`
     * به نال می‌افتد و حسابرسیِ حادثه غیرممکن می‌شود. توکنِ باطل از فهرست
     * ناپدید می‌شود و دیگر کار نمی‌کند — همان چیزی که کاربر می‌خواهد.
     */
    public function tokenDestroy(Request $request, CustomerApiToken $token): RedirectResponse
    {
        abort_unless($token->customer_id === $this->customer()->id, 404);
        $token->revoke();

        return back()->with('ok', __('ui.scf_token_revoked'))->withFragment('sec-api');
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
