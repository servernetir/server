<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Services\Identity\IranianKyc;
use App\Services\Otp\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * ثبت‌نام مشتری.
 *
 * ═══ چرا چند مرحله‌ای است ═══
 *
 * هر ثبت‌نام ایرانی ۸۱٬۰۰۰ تومان استعلام واقعی خرج دارد. اگر همه‌ی فرم یکجا
 * بود، هر بار زدن دکمه یعنی پول. پس ترتیب تخطی‌ناپذیر است:
 *
 *     ۱) ایمیل + موبایل     → رایگان
 *     ۲) کد پیامکی          → هزینهٔ ناچیز، ولی دروازهٔ اصلی
 *     ۳) کد ملی + تولد      → ۸۱٬۰۰۰ تومان، فقط بعد از عبور از ۲
 *     ۴) رمز + پذیرش شرایط  → رایگان
 *
 * مرحلهٔ ۳ هرگز بدون مرحلهٔ ۲ اجرا نمی‌شود؛ نه فقط با هدایت مرورگر، بلکه با
 * بررسی دوبارهٔ رکورد تأیید در دیتابیس (recentlyVerified). یعنی حتی اگر کسی
 * مستقیم POST بزند، استعلام پولی انجام نمی‌شود.
 *
 * ═══ نام ═══
 *
 * از کاربر نام و نام خانوادگی پرسیده نمی‌شود. نام از ثبت احوال می‌آید و همان
 * حرف آخر است — هم جلوی غلط املایی را می‌گیرد، هم تطبیق کارت بانکی را ممکن
 * می‌کند.
 *
 * ═══ خارجی‌ها ═══
 *
 * برای en/tr هیچ استعلامی نیست: ایمیل + کد ایمیلی + رمز. احراز هویت ایرانی
 * برای کسی که کد ملی ندارد بی‌معنی است.
 */
class RegisterController extends Controller
{
    /** حداکثر استعلام پولی برای یک شماره در یک شبانه‌روز */
    private const MAX_KYC_PER_MOBILE = 3;

    public function __construct(
        private OtpService $otp,
        private IranianKyc $kyc,
    ) {}

    // ───────────────────────── مرحلهٔ ۱ — تماس ─────────────────────────

    public function showStart(Request $request)
    {
        // اگر قبلاً وارد شده، ثبت‌نام معنی ندارد
        if (Auth::guard('customer')->check()) {
            return redirect()->route($this->rp().'account.home');
        }

        return view('auth.register.start', [
            'iranian' => $this->isIranianFlow(),
        ] + $this->shell('contact', $this->isIranianFlow()));
    }

    public function start(Request $request): RedirectResponse
    {
        $iranian = $this->isIranianFlow();

        /*
        | موبایل حالا برای **هر دو** جریان اجباری است (خواستِ کارفرما، ۵
        | شهریور ۱۴۰۵: «خارجی‌ها هم اجباری شماره بدهند»). نام/نام‌خانوادگی فقط
        | برای خارجی از فرم می‌آید — ایرانی نامش را از ثبتِ احوال می‌گیرد و
        | پرسیدنش دوباره همان چیزی است که جریانِ KYC عمداً حذف کرده.
        */
        $rules = [
            'email' => ['required', 'email:rfc', 'max:190'],
            'type'  => ['required', 'in:individual,company'],
            'phone' => ['required', 'string', 'max:24'],
        ];

        if (! $iranian) {
            $rules['first_name'] = ['required', 'string', 'max:100'];
            $rules['last_name']  = ['required', 'string', 'max:100'];
        }

        $data = $request->validate($rules, [], [
            'email' => __('ui.auth_email'),
            'phone' => __('ui.auth_mobile'),
            'type'  => __('ui.auth_acct_type'),
            'first_name' => __('ui.auth_first_name'),
            'last_name'  => __('ui.auth_last_name'),
        ]);

        $email = mb_strtolower(trim($data['email']));
        $phone = $this->otp->normalize('sms', $data['phone']);

        if ($iranian && ! str_starts_with($phone, '09')) {
            return back()->withInput()->withErrors([
                'phone' => 'شمارهٔ موبایل باید مثل ۰۹۱۲۱۲۳۴۵۶۷ باشد.',
            ]);
        }

        if (! $iranian && $phone === '') {
            return back()->withInput()->withErrors([
                'phone' => __('ui.auth_mobile_intl_bad'),
            ]);
        }

        // حساب فعال با این ایمیل یا شماره؟ ثبت‌نام دوباره معنی ندارد
        if ($this->takenByActive('email', $email)) {
            return back()->withInput()->withErrors([
                'email' => __('ui.auth_email_taken'),
            ]);
        }

        if ($phone !== null && $this->takenByActive('phone', $phone)) {
            return back()->withInput()->withErrors([
                'phone' => __('ui.auth_phone_taken'),
            ]);
        }

        /*
        | خارجی **دو** تأیید دارد (خواستِ کارفرما، ۵ شهریور: «شماره و ایمیل هر
        | دو تأیید شوند»): اول ایمیل (رایگان و همه‌جا می‌رسد)، بعد از قبولیِ آن
        | کدِ دوم به موبایل (SNS). پس این‌جا همیشه از ایمیل شروع می‌شود؛ مرحلهٔ
        | پیامکی در verify() زنجیر می‌شود.
        |
        | ⚠️ باگی که این ترتیب بست: نسخهٔ قبل کدِ خارجی را به موبایل می‌فرستاد
        | ولی verify مقصد را با «iranian ? phone : email» می‌سنجید — یعنی کدِ
        | پیامکی هرگز قابلِ تأیید نبود. حالا مقصد همه‌جا از خودِ channel می‌آید.
        */
        $channel     = $iranian ? 'sms' : 'email';
        $destination = $iranian ? $phone : $email;

        /*
         * اگر کاربر برگشته و مقصد را عوض کرده، کد قبلی باید بمیرد.
         *
         * شمارهٔ قبلی ممکن است غلط تایپی و مال کس دیگری باشد؛ کد زنده ماندنش
         * یعنی سه دقیقه پنجرهٔ سوءاستفاده. ضمناً خنک‌کنندهٔ آن شماره نباید
         * جلوی شمارهٔ تازه را بگیرد — همان چیزی که کاربر گزارش کرد.
         */
        $previous = $request->session()->get('reg');

        if (is_array($previous)) {
            $old = $previous['iranian'] ?? true ? ($previous['phone'] ?? null) : ($previous['email'] ?? null);

            if (filled($old) && $old !== $destination) {
                $this->otp->abandon($previous['channel'] ?? $channel, (string) $old, 'register');
            }
        }

        $issue = $this->otp->issue($channel, $destination, 'register', $request->ip());

        if (! $issue->ok) {
            // فقط روی فیلدی که واقعاً مقصد بود — قبلاً روی هر دو می‌نشست و
            // کاربر یک خطا را دو بار می‌دید
            return back()->withInput()->withErrors([
                $iranian ? 'phone' : 'email' => $issue->error,
            ]);
        }

        if ($channel === 'email') {
            $this->mailCode($email);
        }

        $request->session()->put('reg', [
            'email'    => $email,
            'phone'    => $phone,
            'type'     => $data['type'],
            'iranian'  => $iranian,
            'channel'  => $channel,
            'verified' => false,
            'first_name' => trim((string) ($data['first_name'] ?? '')),
            'last_name'  => trim((string) ($data['last_name'] ?? '')),
        ]);

        return redirect()->route($this->rp().'register.verify');
    }

    // ───────────────────────── مرحلهٔ ۲ — کد ─────────────────────────

    public function showVerify(Request $request)
    {
        if (($reg = $this->reg($request)) === null) {
            return redirect()->route($this->rp().'register');
        }

        return view('auth.register.verify', [
            'reg'     => $reg,
            'masked'  => $this->mask($reg),
            'iranian' => $reg['iranian'],
        ] + $this->shell('verify', $reg['iranian']));
    }

    public function verify(Request $request): RedirectResponse
    {
        if (($reg = $this->reg($request)) === null) {
            return redirect()->route($this->rp().'register');
        }

        $request->validate(['code' => ['required', 'string', 'max:12']], [], ['code' => __('ui.auth_code')]);

        /*
        | کدِ مرحلهٔ سندباکسی را خودِ AWS ساخته و فقط خودش می‌تواند بسنجد —
        | OtpService هیچ چالشی برای آن صادر نکرده و ابدی ردش می‌کرد.
        */
        if ($reg['channel'] === 'sms' && ! empty($reg['sandbox'])) {
            $ok = app(\App\Services\Sms\SnsSender::class)
                ->sandboxVerify((string) $reg['phone'], $request->string('code')->toString());

            if (! $ok) {
                return back()->withErrors(['code' => __('ui.auth_code_wrong')]);
            }

            $reg['phone_ok'] = true;
            $reg['verified'] = true;
            $request->session()->put('reg', $reg);

            return redirect()->route($this->rp().'register.finish');
        }

        // 🔴 مقصد از خودِ کانال — نه از حدسِ «ایرانی/خارجی». کانالِ sms یعنی
        // چالش روی شماره صادر شده؛ سنجیدنِ ایمیل جای آن، کد را ابدی رد می‌کرد.
        $check = $this->otp->verify(
            $reg['channel'],
            $reg['channel'] === 'sms' ? $reg['phone'] : $reg['email'],
            'register',
            $request->string('code')->toString(),
        );

        if (! $check->ok) {
            return back()->withErrors(['code' => $check->error]);
        }

        if ($reg['iranian']) {
            $reg['verified'] = true;
            $request->session()->put('reg', $reg);

            return redirect()->route($this->rp().'register.identity');
        }

        if ($reg['channel'] === 'email') {
            $reg['email_ok'] = true;

            /*
            | مرحلهٔ ۲ — کد به موبایل. فقط وقتی SNS آماده است و شماره E.164 است؛
            | وگرنه (کلیدِ AWS نیست، یا شمارهٔ ۰۹ داده) با همان تأییدِ ایمیل
            | جلو می‌رویم — ثبت‌نام هرگز پشتِ یک پیکربندیِ جانبی قفل نمی‌شود.
            |
            | کلیدِ خاموشیِ صریح (تصمیمِ کارفرما — ۶ شهریور ۱۴۰۵): تا خروج از
            | SMS Sandbox، تأییدِ شمارهٔ خارجی‌ها را می‌شود از تنظیمات به‌کل
            | خاموش کرد؛ احرازِ واقعی همان KYCِ سندی (پاسپورت + قبض) می‌مانَد
            | و ایمیل همیشه اجباری است. شماره جمع می‌شود ولی مهر نمی‌خورد.
            */
            $sns = app(\App\Services\Sms\SnsSender::class);
            $phoneStageOff = false;

            try {
                $phoneStageOff = (string) \App\Models\Setting::get('foreign_phone_stage_off') === '1';
            } catch (\Throwable) {
                // دیتابیسِ لنگ = رفتارِ پیش‌فرض (مرحلهٔ پیامکی سرِ جایش)
            }

            if (! $phoneStageOff && $sns->enabled() && str_starts_with((string) $reg['phone'], '+')) {
                /*
                | حسابِ AWS هنوز در SMS Sandbox؟ Publish به شمارهٔ تأییدنشده
                | ۲۰۰ می‌دهد ولی هرگز تحویل نمی‌شود. راهِ دررو: خودِ AWS کد
                | بفرستد (CreateSMSSandboxPhoneNumber) و ما همان را بسنجیم.
                | شماره‌ای که قبلاً در سندباکس Verified شده مسیرِ عادی می‌رود.
                */
                if ($sns->sandboxMode() && $sns->sandboxStatus((string) $reg['phone']) !== 'Verified') {
                    if ($sns->sandboxAdd((string) $reg['phone'])) {
                        $reg['channel'] = 'sms';
                        $reg['sandbox'] = true;
                        $request->session()->put('reg', $reg);

                        return redirect()->route($this->rp().'register.verify')
                            ->with('reg_notice', __('ui.auth_sms_sandbox_sent'));
                    }

                    /*
                    | سندباکس پر است (سقفِ ~۱۰ شماره) یا AWS رد کرد — ثبت‌نام
                    | با همان ایمیلِ تأییدشده تمام می‌شود؛ خطایش ثبت شده و
                    | راهِ حلِ واقعی تأییدِ کیسِ production است، نه قفلِ مشتری.
                    */
                    $reg['verified'] = true;
                    $request->session()->put('reg', $reg);

                    return redirect()->route($this->rp().'register.finish');
                }

                $issue = $this->otp->issue('sms', $reg['phone'], 'register', $request->ip());

                $reg['channel'] = 'sms';
                $request->session()->put('reg', $reg);

                if (! $issue->ok) {
                    // ایمیل تأیید شده و می‌مانَد؛ «ارسال دوباره» همین مرحلهٔ
                    // پیامکی را دوباره می‌زند و «تغییر شماره» به فرمِ اول برمی‌گردد.
                    return redirect()->route($this->rp().'register.verify')
                        ->withErrors(['code' => __('ui.auth_sms_stage_fail')]);
                }

                return redirect()->route($this->rp().'register.verify')
                    ->with('reg_notice', __('ui.auth_sms_stage_sent'));
            }

            $reg['verified'] = true;
            $request->session()->put('reg', $reg);

            return redirect()->route($this->rp().'register.finish');
        }

        // مرحلهٔ پیامکیِ خارجی قبول شد — هر دو مقصد تأییدشده
        $reg['phone_ok'] = true;
        $reg['verified'] = true;
        $request->session()->put('reg', $reg);

        return redirect()->route($this->rp().'register.finish');
    }

    public function resend(Request $request): RedirectResponse
    {
        if (($reg = $this->reg($request)) === null) {
            return redirect()->route($this->rp().'register');
        }

        // ارسالِ دوبارهٔ مرحلهٔ سندباکسی = Createِ دوباره (AWS همان کد را بازمی‌فرستد)
        if (($reg['channel'] ?? '') === 'sms' && ! empty($reg['sandbox'])) {
            $ok = app(\App\Services\Sms\SnsSender::class)->sandboxAdd((string) $reg['phone']);

            return $ok
                ? back()->with('ok', __('ui.auth_code_resent'))
                : back()->withErrors(['code' => __('ui.auth_sms_stage_fail')]);
        }

        $destination = $reg['channel'] === 'sms' ? $reg['phone'] : $reg['email'];
        $issue = $this->otp->issue($reg['channel'], $destination, 'register', $request->ip());

        if (! $issue->ok) {
            return back()->withErrors(['code' => $issue->error]);
        }

        if ($reg['channel'] === 'email' && ! $reg['iranian']) {
            $this->mailCode($reg['email']);
        }

        return back()->with('ok', __('ui.auth_code_resent'));
    }

    // ─────────────────── مرحلهٔ ۳ — احراز هویت (پولی) ───────────────────

    public function showIdentity(Request $request)
    {
        if (($reg = $this->reg($request, needVerified: true)) === null) {
            return redirect()->route($this->rp().'register');
        }

        if (! $reg['iranian']) {
            return redirect()->route($this->rp().'register.finish');
        }

        return view('auth.register.identity', ['reg' => $reg] + $this->shell('identity', true));
    }

    public function identity(Request $request): RedirectResponse
    {
        if (($reg = $this->reg($request, needVerified: true)) === null) {
            return redirect()->route($this->rp().'register');
        }

        $data = $request->validate([
            'national_id' => ['required', 'string', 'max:20'],
            'birth_date'  => ['required', 'string', 'max:20'],
        ], [], [
            'national_id' => 'کد ملی',
            'birth_date'  => 'تاریخ تولد',
        ]);

        $nationalId = $this->digits($data['national_id']);
        $birth      = $this->normalizeBirthDate($data['birth_date']);

        // ── هر بررسی که محلی ممکن است، قبل از تماس پولی انجام می‌شود ──
        if (! $this->validNationalId($nationalId)) {
            return back()->withInput()->withErrors(['national_id' => 'کد ملی معتبر نیست.']);
        }

        if ($birth === null) {
            return back()->withInput()->withErrors([
                'birth_date' => 'تاریخ تولد را مثل ۱۳۷۰/۰۵/۱۲ وارد کنید.',
            ]);
        }

        // ── دروازهٔ دوم: تأیید پیامک باید در دیتابیس هم ثبت شده باشد ──
        // نشست قابل دستکاری نیست، ولی این بررسی مستقل، حمله‌ی پرش از مرحله را
        // ساختاراً غیرممکن می‌کند.
        if (! $this->otp->recentlyVerified('sms', $reg['phone'], 'register')) {
            $request->session()->forget('reg');

            return redirect()->route($this->rp().'register')
                ->withErrors(['phone' => 'اعتبار تأیید شماره تمام شد. از ابتدا شروع کنید.']);
        }

        // ── دروازهٔ سوم: سقف تعداد استعلام پولی برای این شماره ──
        $limitKey = 'kyc:'.$reg['phone'];

        if (RateLimiter::tooManyAttempts($limitKey, self::MAX_KYC_PER_MOBILE)) {
            return back()->withInput()->withErrors([
                'national_id' => 'تعداد تلاش‌های احراز هویت برای این شماره زیاد شد. فردا دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
            ]);
        }

        $customer = $this->pendingCustomer($reg);
        RateLimiter::hit($limitKey, 86400);

        // ── و تازه اینجا پول خرج می‌شود ──
        $outcome = $this->kyc->verifyIdentity($customer, $nationalId, $birth, $reg['phone']);

        if (! $outcome->ok) {
            return back()->withInput()->withErrors([
                'national_id' => $outcome->serviceDown
                    ? 'سرویس استعلام موقتاً در دسترس نیست. چند دقیقه بعد تلاش کنید.'
                    : $outcome->error,
            ]);
        }

        $reg['customer_id'] = $customer->id;
        $reg['identity_ok'] = true;
        $reg['name']        = trim($outcome->verification->first_name.' '.$outcome->verification->last_name);
        $request->session()->put('reg', $reg);

        return redirect()->route($this->rp().'register.finish');
    }

    // ───────────────────── مرحلهٔ ۴ — رمز و شرایط ─────────────────────

    public function showFinish(Request $request)
    {
        if (($reg = $this->reg($request, needVerified: true)) === null) {
            return redirect()->route($this->rp().'register');
        }

        if ($reg['iranian'] && empty($reg['identity_ok'])) {
            return redirect()->route($this->rp().'register.identity');
        }

        return view('auth.register.finish', ['reg' => $reg] + $this->shell('password', $reg['iranian']));
    }

    public function finish(Request $request): RedirectResponse
    {
        if (($reg = $this->reg($request, needVerified: true)) === null) {
            return redirect()->route($this->rp().'register');
        }

        if ($reg['iranian'] && empty($reg['identity_ok'])) {
            return redirect()->route($this->rp().'register.identity');
        }

        $request->validate([
            'password' => ['required', 'string', 'min:10', 'max:200', 'confirmed'],
            'terms'    => ['accepted'],
        ], [
            'terms.accepted'  => 'پذیرش شرایط استفاده و حریم خصوصی الزامی است.',
            'password.min'    => 'رمز عبور باید دست‌کم ۱۰ نویسه باشد.',
            'password.confirmed' => 'تکرار رمز عبور یکسان نیست.',
        ], ['password' => 'رمز عبور']);

        $customer = $this->pendingCustomer($reg);

        $customer->forceFill([
            'password'           => Hash::make($request->string('password')->toString()),
            'status'             => 'active',
            'phone_verified_at'  => ($reg['iranian'] || ! empty($reg['phone_ok']))
                ? now() : $customer->phone_verified_at,
            'email_verified_at'  => $reg['iranian'] ? $customer->email_verified_at : now(),
        ])->save();

        $this->ensureProfile($customer, $reg);
        $this->recordAcceptance($customer, $request);

        // اعلانِ «مشتریِ جدید» به مدیر — best-effort، ثبت‌نام را نمی‌شکند
        app(\App\Services\Notify\AdminNotifier::class)->event('مشتریِ جدید ثبت‌نام کرد', [
            'نام'    => $customer->displayName(),
            'شناسه'  => $customer->code,
            'موبایل' => $customer->phone,
            'ایمیل'  => $customer->email,
            'نوع'    => ($reg['type'] ?? 'individual') === 'company' ? 'حقوقی' : 'حقیقی',
        ], null, '🙋', [[
            // کارتِ مشتری داخلِ خودِ بله باز می‌شود — «لینک به کارِ من نمی‌آید»
            ['text' => '👤 پروفایلِ مشتری', 'data' => \App\Services\Bale\Admin\AdminBaleRouter::CB_PREFIX.'c:'.$customer->id],
        ]]);

        /*
        | خوش‌آمد — الگویش سال‌ها بود و هیچ کدی صدایش نمی‌زد.
        |
        | ⚠️ بعد از `AdminNotifier` نمی‌آید بلکه جایگزینش نیست: آن اعلانِ مدیر
        |    جزئیاتِ ثبت‌نام را دارد و این پیامِ خودِ مشتری است.
        */
        try {
            app(\App\Services\Notify\Notifier::class)->fire(
                'welcome',
                $customer,
                ['name' => $customer->displayName() ?: 'کاربر گرامی'],
                'به سرورنت خوش آمدید 🎉 حسابِ شما ساخته شد. '
                .'از پنل می‌توانید سرویس بخرید، دامنه ثبت کنید و تیکت بزنید: '
                .console_lroute('account.home'),
            );
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('notify', $e, ['event' => 'welcome']);
        }

        $request->session()->forget('reg');
        Auth::guard('customer')->login($customer, remember: true);
        $request->session()->regenerate();

        /*
        | 🔴 ثبت‌نام هم باید به همان‌جایی برگردد که کاربر می‌خواست برود.
        |
        | مسیرِ ورود (`LoginController`) از `intended()` استفاده می‌کرد، ولی
        | ثبت‌نام نه — و **خریدارِ تازه دقیقاً از این مسیر می‌آید**. یعنی کسی که
        | روی «انتخاب پلن» کلیک می‌کرد، به `/account/order/wordpress-3` هدایت
        | می‌شد، آن‌جا به ثبت‌نام می‌رفت، و بعد از ساختِ حساب سر از داشبوردِ خالی
        | درمی‌آورد — بی‌هیچ نشانی از پلنی که انتخاب کرده بود.
        |
        | ⚠️ `regenerate()` شناسهٔ نشست را عوض می‌کند ولی **داده را نگه می‌دارد**،
        | پس `url.intended` که موقعِ ریدایرکتِ مهمان ذخیره شده هنوز سرِ جایش است.
        | ترتیب مهم است: اگر `intended()` پیش از `login()` خوانده شود، هنوز
        | نشستِ مهمان است و چیزِ درستی برنمی‌گردد.
        */
        return redirect()->intended(route($this->rp().'account.home'))
            ->with('ok', __('ui.auth_account_created'));
    }

    // ───────────────────────────── کمکی‌ها ─────────────────────────────

    /**
     * فهرست گام‌ها برای ریل کنار فرم.
     *
     * ایرانی چهار گام دارد و خارجی سه — چون احراز هویت ثبت احوال برای کسی
     * که کد ملی ندارد بی‌معنی است. شماره‌گذاری در قالب خودکار است تا حذف
     * یک گام، شمارهٔ بقیه را دستی نشکند.
     */
    private function shell(string $current, bool $iranian): array
    {
        $steps = [
            ['key' => 'contact', 'title' => __('ui.auth_s_contact'), 'desc' => __('ui.auth_s_contact_d')],
            ['key' => 'verify',  'title' => __('ui.auth_s_verify'),  'desc' => __('ui.auth_s_verify_d')],
        ];

        if ($iranian) {
            $steps[] = ['key' => 'identity', 'title' => __('ui.auth_s_identity'), 'desc' => __('ui.auth_s_identity_d')];
        }

        $steps[] = ['key' => 'password', 'title' => __('ui.auth_s_password'), 'desc' => __('ui.auth_s_password_d')];

        return ['authSteps' => $steps, 'authStep' => $current];
    }

    /** نشست ثبت‌نام؛ null یعنی از ابتدا شروع کند */
    private function reg(Request $request, bool $needVerified = false): ?array
    {
        $reg = $request->session()->get('reg');

        if (! is_array($reg) || blank($reg['email'] ?? null)) {
            return null;
        }

        if ($needVerified && empty($reg['verified'])) {
            return null;
        }

        return $reg;
    }

    /**
     * مشتری «در انتظار» را می‌سازد یا برمی‌گرداند.
     *
     * چرا updateOrCreate: اگر کسی مرحلهٔ ۳ را نیمه رها کند، ردیف pending با
     * ایمیل او می‌ماند و جای یکتای ایمیل را می‌گیرد. با این کار، همان شخص
     * (یا صاحب واقعی آن ایمیل) بعداً می‌تواند ثبت‌نام را کامل کند. حساب‌های
     * فعال زودتر در start رد شده‌اند، پس اینجا هیچ حساب واقعی بازنویسی نمی‌شود.
     */
    private function pendingCustomer(array $reg): Customer
    {
        if (! empty($reg['customer_id'])) {
            $found = Customer::find($reg['customer_id']);

            if ($found !== null) {
                return $found;
            }
        }

        $customer = Customer::where('email', $reg['email'])->first();

        if ($customer === null && filled($reg['phone'] ?? null)) {
            $customer = Customer::where('phone', $reg['phone'])->first();
        }

        if ($customer === null) {
            $customer = new Customer();
        }

        $customer->forceFill([
            'email'    => $reg['email'],
            'phone'    => $reg['phone'],
            'locale'   => app()->getLocale(),
            'timezone' => $reg['iranian'] ? 'Asia/Tehran' : 'UTC',
            'status'   => $customer->exists && $customer->status === 'active' ? 'active' : 'pending',
        ])->save();

        return $customer;
    }

    /** پروفایل صورت‌حساب اولیه — نام از استعلام می‌آید، نه از فرم */
    private function ensureProfile(Customer $customer, array $reg): void
    {
        $identity = $customer->identityVerification;
        $isCompany = ($reg['type'] ?? 'individual') === 'company';

        // کاربرِ حقوقی حتی با احراز هویتِ شخصیِ نماینده هم «تأییدشده» نمی‌شود؛
        // شرکت باید مدارک (معرفی‌نامه + اساسنامه) را در بخش احراز هویت آپلود کند
        // تا تیمِ پشتیبانی تأیید کند. احراز شخصی فقط هویتِ نماینده را تأیید می‌کند.
        CustomerProfile::updateOrCreate(
            ['customer_id' => $customer->id, 'is_default' => true],
            array_filter([
                'type'             => $reg['type'] ?? 'individual',
                'status'           => (! $isCompany && $identity?->status === 'verified') ? 'verified' : 'draft',
                'verified_at'      => $isCompany ? null : $identity?->verified_at,
                'mobile'           => $reg['phone'] ?? null,
                'email'            => $reg['email'],
                'country'          => $reg['iranian'] ? 'IR' : null,
                'first_name'       => $identity?->first_name ?? (filled($reg['first_name'] ?? null) ? $reg['first_name'] : null),
                'last_name'        => $identity?->last_name ?? (filled($reg['last_name'] ?? null) ? $reg['last_name'] : null),
                'birth_date'       => $identity?->birth_date,
                'national_id_enc'  => $identity?->national_id_enc,
                'national_id_hash' => $identity?->national_id_hash,
            ], fn ($v) => $v !== null),
        );
    }

    /**
     * پذیرش شرایط با نسخه ثبت می‌شود، نه فقط یک تیک.
     * اگر فردا شرایط عوض شود، باید بدانیم کاربر کدام نسخه را پذیرفته بود.
     */
    private function recordAcceptance(Customer $customer, Request $request): void
    {
        $locale = app()->getLocale();

        $docs = \DB::table('legal_documents')
            ->whereIn('kind', ['terms', 'privacy'])
            ->where('locale', $locale)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->get()
            ->unique('kind');

        foreach ($docs as $doc) {
            \DB::table('legal_acceptances')->updateOrInsert(
                ['customer_id' => $customer->id, 'legal_document_id' => $doc->id],
                [
                    'accepted_at' => now(),
                    'ip'          => $request->ip(),
                    'user_agent'  => substr((string) $request->userAgent(), 0, 255),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ],
            );
        }
    }

    private function takenByActive(string $column, string $value): bool
    {
        return Customer::where($column, $value)->where('status', 'active')->exists();
    }

    private function isIranianFlow(): bool
    {
        return app()->getLocale() === 'fa';
    }

    /** پیشوند نام روت برای زبان جاری */
    private function rp(): string
    {
        return \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';
    }

    private function mask(array $reg): string
    {
        // مرحلهٔ پیامکی (ایرانی، یا گامِ دومِ خارجی): شماره را نشان بده نه ایمیل
        if (($reg['channel'] ?? '') === 'sms') {
            $p = (string) $reg['phone'];

            return substr($p, 0, 4).'***'.substr($p, -4);
        }

        [$user, $host] = array_pad(explode('@', (string) $reg['email'], 2), 2, '');

        return mb_substr($user, 0, 2).'***@'.$host;
    }

    private function mailCode(string $email): void
    {
        // کد در OtpService ساخته و hash شده؛ اینجا فقط اطلاع‌رسانی است.
        // چون کد خام را نگه نمی‌داریم، متن ایمیل از خود سرویس نمی‌آید —
        // در فاز بعد با Notification و قالب اختصاصی جایگزین می‌شود.
        try {
            Mail::raw('Your ServerNet verification code was sent to this address.', function ($m) use ($email) {
                $m->to($email)->subject('ServerNet — verification');
            });
        } catch (\Throwable $e) {
            Log::warning('ارسال ایمیل تأیید انجام نشد', ['error' => $e->getMessage()]);
        }
    }

    /** ۱۳۷۰/۰۵/۱۲ یا 1370-5-12 → 1370-05-12 (شمسی، همان چیزی که زحل می‌خواهد) */
    private function normalizeBirthDate(string $s): ?string
    {
        $d = $this->digits($s);

        if (strlen($d) !== 8) {
            return null;
        }

        $y = (int) substr($d, 0, 4);
        $m = (int) substr($d, 4, 2);
        $day = (int) substr($d, 6, 2);

        if ($y < 1250 || $y > 1450 || $m < 1 || $m > 12 || $day < 1 || $day > 31) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $m, $day);
    }

    /** الگوریتم رسمی کد ملی ایران — بررسی محلی، قبل از خرج کردن پول */
    private function validNationalId(string $id): bool
    {
        if (preg_match('/^\d{10}$/', $id) !== 1) {
            return false;
        }

        // ۰۰۰۰۰۰۰۰۰۰ تا ۹۹۹۹۹۹۹۹۹۹ همه‌رقم‌یکسان معتبر نیستند
        if (preg_match('/^(\d)\1{9}$/', $id) === 1) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $id[$i] * (10 - $i);
        }

        $r = $sum % 11;
        $check = (int) $id[9];

        return $r < 2 ? $check === $r : $check === 11 - $r;
    }

    private function digits(string $s): string
    {
        $s = strtr($s, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);

        return preg_replace('/[^0-9]/', '', $s) ?? '';
    }
}
