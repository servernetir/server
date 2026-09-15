<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\AiProject;
use App\Models\CustomerApiToken;
use App\Models\CustomerIpRule;
use App\Services\Otp\OtpService;
use App\Services\Security\QrCode;
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
            'apiTokens'   => $c->apiTokens()->usable()->with('aiProject')->orderByDesc('id')->get(),
            // سرِ کوچکِ M2 — پروژه‌های AIِ همین حساب، مرتبِ تازه‌ترین
            'aiProjects'  => $c->aiProjects()->orderByDesc('id')->get(),
            'currentIp'   => $request->ip(),
            'hasPassword' => ! empty($c->password),
            'pwReady'     => $request->session()->has('pw_change_ctx'),

            /*
            | دومرحله‌ای — QR فقط در حالتِ «راه‌اندازیِ تأییدنشده» ساخته می‌شود.
            |
            | ⚠️ ساختنش برای حسابِ **فعال** یعنی رازِ زندهٔ کاربر روی هر بازدید
            | از این صفحه دوباره روی HTML بنشیند؛ چیزی که در کشِ مرورگر، در
            | تاریخچه، و روی شانهٔ هر کسی که رد شود باقی می‌مانَد.
            */
            'tfaQr'       => $c->twoFactorPending() ? QrCode::svg($c->twoFactorUri()) : null,
            'tfaSecret'   => $c->twoFactorPending() ? $c->two_factor_secret : null,
            'tfaLeft'     => count($c->twoFactorRecoveryCodes()),
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
            'abilities.*' => ['string', Rule::in([...array_keys(CustomerApiToken::ABILITIES), ...array_keys(CustomerApiToken::AI_ABILITIES)])],
            'cidrs'       => ['nullable', 'string', 'max:500'],
            'expires_days'=> ['nullable', 'integer', 'min:1', 'max:1825'],

            /* ── مالِ M2 — کلیدِ AI: پروژهٔ bound + نردبانِ ai:* ── */
            'ai_project_id'=> ['nullable', 'integer', 'min:1'],
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
        | 🔴 کلیدِ AI — دو قاعده، در هر دو جهت:
        |   ۱) تیکِ هر abilityی از نردبانِ `AI_ABILITIES` بدونِ پروژه **رد**
        |      می‌شود — کلیدِ AIِ بی‌پروژه ساکت صادر نمی‌شود؛ بی‌ظرفِ
        |      admission یعنی دَرِ بازِ بی‌سقف، و مشتری هم کلیدِ مرده
        |      نمی‌گیرد که تا ابد شبیهِ کارکردن باشد.
        |   ۲) بدونِ هیچ تیکِ AI، پروژهٔ فرستاده‌شده **نادیده** می‌شود:
        |      توکنِ عادی هرگز ناخواسته کلیدِ AI نمی‌شود.
        |   در هر دو مسیر، پروژه باید **مالِ همین حساب** و **فعال** باشد —
        |   پروژهٔ مشتریِ دیگر پیدا نمی‌شود و درخواست رد می‌شود.
        */
        $aiProjectId = (int) ($data['ai_project_id'] ?? 0);

        $aiAbilities = array_values(array_intersect($abilities, array_keys(CustomerApiToken::AI_ABILITIES)));

        $aiProject = null;

        if ($aiAbilities !== []) {
            if ($aiProjectId <= 0) {
                return back()->withErrors([
                    'ai_project_id' => __('ui.sec_ai_project_need'),
                ])->withFragment('sec-api');
            }

            $aiProject = AiProject::query()
                ->where('customer_id', $c->id)
                ->where('status', AiProject::STATUS_ACTIVE)
                ->find($aiProjectId);

            if ($aiProject === null) {
                return back()->withErrors([
                    'ai_project_id' => __('ui.sec_ai_project_need'),
                ])->withFragment('sec-api');
            }
        } else {
            $aiProjectId = 0;
        }

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
            $aiProject?->id,
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

    // ───────────────────────── پروژهٔ AI (M2) ─────────────────────────

    /** حداکثرِ پروژهٔ AIِ هم‌زمان برای هر حساب — سقفِ مِد را این‌جا نگه می‌داریم
     *  تا روی رابطِ صدور و در تست‌ها یکی باشد. */
    public const MAX_AI_PROJECTS = 10;

    public function aiProjectStore(Request $request): RedirectResponse
    {
        $c = $this->customer();

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:120'],
            'slug'           => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9._-]{0,119}$/'],
            'monthly_budget' => ['nullable', 'integer', 'min:1', 'max:9000000000000'],
            'budget_period'  => ['nullable', 'in:'.AiProject::PERIOD_NONE.','.AiProject::PERIOD_MONTHLY],
            'budget_reset_day' => ['nullable', 'integer', 'min:1', 'max:28'],
        ], [], ['name' => 'نام پروژه']);

        /*
        | سقفِ ۱۰ پروژه — چکِ سادهِ count. مسابقهٔ نظریِ دو POSTِ همزمان
        | پذیرفته‌شده و کم‌خسارت است (ردیفِ ۱۱اُم؛ هزینه‌اش فقط ظرفِ
        | admission است، نه پول) — عمداً قفل/تراکنش اضافه نمی‌شود.
        */
        if ($c->aiProjects()->count() >= self::MAX_AI_PROJECTS) {
            return back()->withErrors(['name' => __('ui.sec_ai_cap', ['n' => fa_num(self::MAX_AI_PROJECTS)])])
                ->withFragment('sec-ai');
        }

        $slug = $data['slug'] ?? $this->slugify($data['name']);

        if ($slug === null || $slug === '') {
            return back()->withErrors(['name' => __('ui.sec_ai_slug_bad')])->withFragment('sec-ai');
        }

        if ($c->aiProjects()->where('slug', $slug)->exists()) {
            return back()->withErrors(['slug' => __('ui.sec_ai_slug_taken')])->withFragment('sec-ai');
        }

        /*
        | بودجه: صفر هرگز بازمَندی نمی‌شود — «نال» یعنی بی‌سقف. دورهٔ ماهانه
        | بی‌بودجه بی‌خود نمی‌سازیم: دوره فقط با بودجه معنا دارد.
        */
        $period = $data['budget_period'] ?? AiProject::PERIOD_NONE;
        if ($period === AiProject::PERIOD_MONTHLY && ! isset($data['monthly_budget'])) {
            return back()->withErrors(['monthly_budget' => __('ui.sec_ai_budget_need')])
                ->withFragment('sec-ai');
        }

        $project = $c->aiProjects()->create([
            'name' => $data['name'],
            'slug' => $slug,
            'status' => AiProject::STATUS_ACTIVE,
            'monthly_budget_irt' => $period === AiProject::PERIOD_MONTHLY ? $data['monthly_budget'] : null,
            'budget_period' => $period,
            'budget_reset_day' => $period === AiProject::PERIOD_MONTHLY ? ($data['budget_reset_day'] ?? 1) : null,
        ]);
        $project->refreshBudgetWindow();

        \App\Models\ActivityLog::record($c->id, 'ai_project_created',
            __('ui.act_ai_project_created', ['name' => $data['name']]), $request, 'customer');

        return back()->with('ok', __('ui.sec_ai_created'))->withFragment('sec-ai');
    }

    public function aiProjectUpdate(Request $request, AiProject $project): RedirectResponse
    {
        $c = $this->customer();
        abort_unless($project->customer_id === $c->id, 404);

        $data = $request->validate([
            'status' => ['required', 'in:'.AiProject::STATUS_ACTIVE.','.AiProject::STATUS_DISABLED.','.AiProject::STATUS_ARCHIVED],
            'monthly_budget' => ['nullable', 'integer', 'min:1', 'max:9000000000000'],
            'budget_reset_day' => ['nullable', 'integer', 'min:1', 'max:28'],
        ]);

        if ($project->status === AiProject::STATUS_ARCHIVED) {
            return back()->withErrors(['status' => __('ui.sec_ai_archived_final')])->withFragment('sec-ai');
        }

        if ($data['status'] === AiProject::STATUS_ARCHIVED) {
            $project->archive(); // کلیدهایِ bound نرم ابطال می‌شوند — هیچ کلیدِ AI فعالی نمی‌مانَد

            \App\Models\ActivityLog::record($c->id, 'ai_project_archived',
                __('ui.act_ai_project_archived', ['name' => $project->name]), $request, 'customer');

            return back()->with('ok', __('ui.sec_ai_archived'))->withFragment('sec-ai');
        }

        $project->forceFill(['status' => $data['status']])->save();

        if (array_key_exists('monthly_budget', $data)) {
            $project->forceFill([
                'monthly_budget_irt' => $data['monthly_budget'] ?? null,
                'budget_period' => ($data['monthly_budget'] ?? null) > 0
                    ? AiProject::PERIOD_MONTHLY : AiProject::PERIOD_NONE,
                'budget_reset_day' => $data['budget_reset_day'] ?? null,
            ])->save();
            $project->refreshBudgetWindow();
        }

        \App\Models\ActivityLog::record($c->id, 'ai_project_update',
            __('ui.act_ai_project_updated', ['name' => $project->name, 'status' => $data['status']]),
            $request, 'customer');

        return back()->with('ok', __('ui.sec_ai_saved'))->withFragment('sec-ai');
    }

    // ───────────────────────── کمکی ─────────────────────────

    /** اسلگ از نام — لاتین/رقم/خط؛ فارس دریافت شد null (کاربر اسلگِ صریح می‌دهد) */
    private function slugify(string $raw): ?string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9._-]+/', '-', transliterator_transliterate('Any-Latin; Latin-ASCII', $raw) ?? $raw), '-'));

        return $slug !== '' && ctype_print($slug) ? substr($slug, 0, 120) : null;
    }

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
