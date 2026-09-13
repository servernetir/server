<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\Security\Totp;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ورود دومرحله‌ای با اپلیکیشن احراز هویت — اختیاری، برای مشتری و کارکنانِ پنل.
 *
 * این سوئیت روی چیزی تمرکز دارد که «کد ۲۰۰ گرفت» ثابتش نمی‌کند: اینکه در
 * لحظهٔ درست **جلوی ورود گرفته شود**.
 */
class TwoFactorAppTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,string> مقصد => کدِ پیامک‌شده */
    public array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->app->instance(SmsSender::class, new class($this) implements SmsSender
        {
            public function __construct(private TwoFactorAppTest $t) {}

            public function enabled(): bool { return true; }

            public function name(): string { return 'fake'; }

            public function send(string $m, string $text): bool { return true; }

            public function sendOtp(string $m, string $code): bool
            {
                $this->t->codes[$m] = $code;

                return true;
            }
        });
    }

    // ───────────────────────────── کمکی‌ها ─────────────────────────────

    private function customer(): Customer
    {
        return Customer::create([
            'email'    => 'c'.random_int(1, 999999).'@example.com',
            'phone'    => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'),
            'status'   => 'active',
            'locale'   => 'fa',
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name'     => 'مدیر',
            'email'    => 'boss'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('secret1234'),
            'role'     => 'admin',
        ]);
    }

    /** حسابی که دومرحله‌ایِ تأییدشده دارد؛ رازش برگردانده می‌شود */
    private function withTwoFactor(Customer|User $account): string
    {
        $secret = $account->startTwoFactorSetup();
        $account->refresh();
        $account->confirmTwoFactor(Totp::code($secret));

        return $secret;
    }

    /**
     * یک بازهٔ زمانی جلو می‌رود.
     *
     * ⚠️ کدِ «بازهٔ بعدی» را نمی‌شود دستی ساخت و همین حالا فرستاد: پنجرهٔ
     * پذیرش ±۱ بازه است، پس کدِ دو بازه جلوتر **رد** می‌شود. تنها راهِ درستِ
     * آزمودنِ گاردِ تکرار، جلوبردنِ خودِ ساعت است — و برای همین `Totp` با
     * `now()` کار می‌کند نه `time()`.
     */
    private function nextStep(): void
    {
        $this->travel(Totp::PERIOD + 1)->seconds();
    }

    /** مشتری را تا انتهای مرحلهٔ کدِ پیامکی می‌بَرد */
    private function passSmsStep(Customer $c): void
    {
        $this->post('/login', ['method' => 'mobile', 'identifier' => $c->phone]);
        $this->post('/login/verify', ['code' => $this->codes[$c->phone]]);
    }

    // ───────────────────── موتورِ TOTP ─────────────────────

    /**
     * بردارهای مرجعِ RFC 6238 — تنها راهِ اثباتِ اینکه پیاده‌سازی با
     * Google Authenticator یکی است، بدون داشتنِ خودِ اپلیکیشن.
     *
     * بردارهای استاندارد هشت‌رقمی‌اند و ما شش‌رقمی می‌سازیم؛ شش رقمِ آخر همان
     * است (برشِ پویا یکی است، فقط پیمانه فرق می‌کند).
     */
    public function test_totp_matches_the_rfc_6238_reference_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        $vectors = [
            59          => '94287082',
            1111111109  => '07081804',
            1111111111  => '14050471',
            1234567890  => '89005924',
            2000000000  => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $time => $eightDigits) {
            $this->assertSame(substr($eightDigits, -6), Totp::code($secret, $time), 'T='.$time);
        }
    }

    /**
     * 🔴 ارقامِ فارسی باید پذیرفته شوند.
     *
     * کاربرِ ایرانی روی صفحه‌کلیدِ فارسی «۱۲۳۴۵۶» می‌زند. بدونِ تبدیل، کدِ
     * کاملاً درستش «نادرست» شمرده می‌شود — بارها، تا به سقفِ تلاش بخورد و از
     * حسابِ خودش بیرون بماند، در حالی که هیچ‌جا هیچ خطایی ثبت نشده.
     */
    public function test_persian_and_arabic_digits_are_accepted(): void
    {
        $secret = Totp::generateSecret();
        $latin = Totp::code($secret);

        $persian = strtr($latin, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);

        $this->assertNotSame($latin, $persian);
        $this->assertTrue(Totp::verify($secret, $persian), 'کدِ فارسی باید پذیرفته شود');
        $this->assertTrue(Totp::verify($secret, ' '.$latin.' '), 'فاصلهٔ اضافی نباید کد را رد کند');
    }

    public function test_the_accepted_window_is_one_step_either_side(): void
    {
        $secret = Totp::generateSecret();
        $now = time();

        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now - Totp::PERIOD), $now));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now + Totp::PERIOD), $now));
        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now + Totp::PERIOD * 3), $now));
    }

    public function test_a_malformed_secret_never_validates_anything(): void
    {
        $this->assertNull(Totp::base32Decode('nope-1890'));
        $this->assertFalse(Totp::verify('nope-1890', '000000'));
        $this->assertFalse(Totp::verify(Totp::generateSecret(), ''));
    }

    // ───────────────────── مدل: گاردهای واقعی ─────────────────────

    /**
     * 🔴 راز تا وقتی کاربر یک کدِ درست نداده **فعال نمی‌شود**.
     *
     * اگر با ساختنِ راز بلافاصله فعال می‌شد، کاربری که QR را اسکن نکرده یا
     * اپلیکیشنش را اشتباه تنظیم کرده در همان لحظه از حسابِ خودش بیرون می‌ماند.
     */
    public function test_a_secret_is_not_active_until_a_real_code_confirms_it(): void
    {
        $c = $this->customer();
        $secret = $c->startTwoFactorSetup();

        $this->assertTrue($c->twoFactorPending());
        $this->assertFalse($c->hasTwoFactor());
        $this->assertNull($c->confirmTwoFactor('000000'), 'کدِ غلط نباید فعال کند');
        $this->assertFalse($c->fresh()->hasTwoFactor());

        $codes = $c->confirmTwoFactor(Totp::code($secret));

        $this->assertCount(Customer::RECOVERY_COUNT, $codes);
        $this->assertTrue($c->fresh()->hasTwoFactor());
    }

    /**
     * ⚠️ بازدید از صفحهٔ امنیت نباید رازِ **فعال** را عوض کند.
     *
     * وگرنه یک رفرشِ ساده اپلیکیشنِ کاربر را بی‌صدا باطل می‌کرد.
     */
    public function test_starting_setup_again_never_touches_an_active_secret(): void
    {
        $c = $this->customer();
        $secret = $this->withTwoFactor($c);

        $this->assertSame($secret, $c->fresh()->startTwoFactorSetup());
        $this->assertTrue($c->fresh()->hasTwoFactor());
    }

    /**
     * 🔴 کدِ TOTP در بازهٔ سی‌ثانیه‌ای‌اش بارها «درست» است.
     *
     * بدونِ گاردِ تکرار، کدی که یک بار دیده شود (روی شانه، صفحهٔ فیشینگ) تا
     * پایانِ همان بازه برای مهاجم هم کار می‌کند.
     */
    public function test_the_same_code_cannot_be_used_twice(): void
    {
        $c = $this->customer();
        $secret = $this->withTwoFactor($c);

        $this->nextStep();
        $code = Totp::code($secret);

        $c = $c->fresh();
        $this->assertTrue($c->verifyTwoFactorCode($code));

        $c = $c->fresh();
        $reason = null;
        $this->assertFalse($c->verifyTwoFactorCode($code, $reason), 'کدِ مصرف‌شده نباید بارِ دوم کار کند');

        // و باید بگوید **چرا** — وگرنه کاربر همان کد را دوباره می‌زند
        $this->assertSame('replay', $reason);

        $c->fresh()->verifyTwoFactorCode('000000', $reason);
        $this->assertSame('invalid', $reason, 'کدِ غلط و کدِ تکراری نباید یک دلیل بگیرند');
    }

    public function test_a_recovery_code_works_once_and_is_format_tolerant(): void
    {
        $c = $this->customer();
        $secret = $c->startTwoFactorSetup();
        $c->refresh();
        $codes = $c->confirmTwoFactor(Totp::code($secret));

        // بزرگ‌نویسی و فاصله به‌جای خط تیره — همان چیزی که آدم از روی کاغذ می‌زند
        $typed = strtoupper(str_replace('-', ' ', $codes[0]));

        $c = $c->fresh();
        $this->assertTrue($c->verifyTwoFactorCode($typed));

        $c = $c->fresh();
        $this->assertFalse($c->verifyTwoFactorCode($codes[0]), 'کدِ بازیابی یک‌بارمصرف است');
        $this->assertCount(Customer::RECOVERY_COUNT - 1, $c->twoFactorRecoveryCodes());
    }

    /** رازِ خام هرگز نباید در دیتابیس بنشیند */
    public function test_the_secret_is_encrypted_at_rest_for_both_account_types(): void
    {
        $c = $this->customer();
        $secret = $this->withTwoFactor($c);
        $raw = DB::table('customers')->where('id', $c->id)->value('two_factor_secret');
        $this->assertStringNotContainsString($secret, (string) $raw);

        $u = $this->admin();
        $adminSecret = $this->withTwoFactor($u);
        $rawUser = DB::table('users')->where('id', $u->id)->value('two_factor_secret');
        $this->assertStringNotContainsString($adminSecret, (string) $rawUser);
        $this->assertNotEmpty($rawUser);
    }

    // ───────────────────── ورودِ مشتری ─────────────────────

    /** حسابِ بدونِ دومرحله‌ای باید دقیقاً مثلِ قبل وارد شود */
    public function test_an_account_without_the_app_logs_in_exactly_as_before(): void
    {
        $c = $this->customer();
        $this->passSmsStep($c);

        $this->assertTrue(Auth::guard('customer')->check());
    }

    /**
     * 🔴 مهم‌ترین ادعای این قابلیت: کدِ پیامکیِ درست، به‌تنهایی وارد نمی‌کند.
     */
    public function test_the_sms_code_alone_does_not_log_in_when_the_app_is_on(): void
    {
        $c = $this->customer();
        $this->withTwoFactor($c);

        $this->post('/login', ['method' => 'mobile', 'identifier' => $c->phone]);
        $this->post('/login/verify', ['code' => $this->codes[$c->phone]])
            ->assertRedirect(route('login.2fa'));

        $this->assertFalse(Auth::guard('customer')->check());
    }

    /**
     * 🔴 و مهم‌تر: نیمه‌احرازشده نباید بتواند فرمِ کد را **دور بزند**.
     *
     * اگر جایی `Auth::login` زودتر صدا زده شود، این تست تنها چیزی است که
     * می‌گیردش — چون صفحهٔ ورود همچنان درست کار می‌کند و هیچ خطایی نمی‌دهد.
     */
    public function test_the_half_finished_handshake_cannot_reach_the_panel(): void
    {
        $c = $this->customer();
        $this->withTwoFactor($c);
        $this->passSmsStep($c);

        $this->get('/account')->assertRedirect();
        $this->get('/account/security')->assertRedirect();
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_a_wrong_app_code_does_not_log_in(): void
    {
        $c = $this->customer();
        $this->withTwoFactor($c);
        $this->passSmsStep($c);

        $this->post('/login/2fa', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_the_right_app_code_completes_the_login(): void
    {
        $c = $this->customer();
        $secret = $this->withTwoFactor($c);
        $this->passSmsStep($c);

        $this->nextStep();
        $this->post('/login/2fa', ['code' => Totp::code($secret)])->assertRedirect();

        $this->assertTrue(Auth::guard('customer')->check());
        $this->assertSame($c->id, Auth::guard('customer')->id());
    }

    /** گوشیِ گم‌شده: کدِ بازیابی باید کارِ ورود را تمام کند */
    public function test_a_recovery_code_completes_the_login(): void
    {
        $c = $this->customer();
        $secret = $c->startTwoFactorSetup();
        $c->refresh();
        $codes = $c->confirmTwoFactor(Totp::code($secret));

        $this->passSmsStep($c);
        $this->post('/login/2fa', ['code' => $codes[2]])->assertRedirect();

        $this->assertTrue(Auth::guard('customer')->check());
    }

    /** بدونِ گذشتن از مرحلهٔ کدِ پیامکی، صفحهٔ سوم اصلاً باز نمی‌شود */
    public function test_the_app_step_is_unreachable_without_the_sms_step(): void
    {
        $this->get('/login/2fa')->assertRedirect(route('login'));
        $this->post('/login/2fa', ['code' => '123456'])->assertRedirect(route('login'));
    }

    // ───────────────────── ورودِ کارکنانِ پنل ─────────────────────

    public function test_the_admin_email_code_alone_does_not_log_in_when_the_app_is_on(): void
    {
        Mail::fake();
        $u = $this->admin();
        $this->withTwoFactor($u);

        $this->post('/admin/login', ['email' => $u->email, 'password' => 'secret1234']);

        $code = null;
        Mail::assertSent(\App\Mail\OtpMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });

        $this->post('/admin/login/otp', ['code' => $code])->assertRedirect(route('admin.login.totp'));

        $this->assertFalse(Auth::check());
        $this->get('/admin')->assertRedirect();
    }

    public function test_the_admin_app_code_completes_the_login(): void
    {
        Mail::fake();
        $u = $this->admin();
        $secret = $this->withTwoFactor($u);

        $this->post('/admin/login', ['email' => $u->email, 'password' => 'secret1234']);

        $code = null;
        Mail::assertSent(\App\Mail\OtpMail::class, function ($m) use (&$code) {
            $code = $m->code;

            return true;
        });

        $this->post('/admin/login/otp', ['code' => $code]);

        $this->nextStep();
        $this->post('/admin/login/totp', ['code' => Totp::code($secret)])->assertRedirect('/admin');

        $this->assertTrue(Auth::check());
        $this->assertSame($u->id, Auth::id());
    }

    // ───────────────────── صفحه‌های مدیریتِ تنظیم ─────────────────────

    public function test_a_customer_can_turn_it_on_and_off_from_the_panel(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->post('/account/security/2fa/start')->assertRedirect();
        $c = $c->fresh();
        $this->assertTrue($c->twoFactorPending());

        $secret = $c->two_factor_secret;

        $this->actingAs($c, 'customer')
            ->post('/account/security/2fa/confirm', ['code' => Totp::code($secret)])
            ->assertSessionHas('tfa_recovery');

        $this->assertTrue($c->fresh()->hasTwoFactor());

        // خاموش‌کردن بدونِ کد نباید کار کند — وگرنه یک نشستِ رهاشده کافی است
        $this->actingAs($c->fresh(), 'customer')
            ->post('/account/security/2fa/disable', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertTrue($c->fresh()->hasTwoFactor());

        $this->nextStep();
        $this->actingAs($c->fresh(), 'customer')
            ->post('/account/security/2fa/disable', ['code' => Totp::code($secret)]);

        $this->assertFalse($c->fresh()->hasTwoFactor());
    }

    public function test_regenerating_recovery_codes_invalidates_the_old_ones(): void
    {
        $c = $this->customer();
        $secret = $c->startTwoFactorSetup();
        $c->refresh();
        $old = $c->confirmTwoFactor(Totp::code($secret));

        $this->nextStep();
        $this->actingAs($c->fresh(), 'customer')
            ->post('/account/security/2fa/recovery', ['code' => Totp::code($secret)])
            ->assertSessionHas('tfa_recovery');

        $fresh = $c->fresh()->twoFactorRecoveryCodes();

        $this->assertCount(Customer::RECOVERY_COUNT, $fresh);
        $this->assertSame([], array_intersect($old, $fresh), 'کدهای قبلی باید باطل شده باشند');
    }


    // ───────────────────── رندرِ واقعیِ صفحه‌ها ─────────────────────

    /**
     * ⚠️ کلیدِ ترجمهٔ جاافتاده صفحه را نمی‌شکند — فقط «ui.tfa_h» را وسطِ صفحه
     * چاپ می‌کند و کد ۲۰۰ برمی‌گردد. پس خودِ رشته باید سنجیده شود.
     */
    public function test_the_security_section_renders_in_all_states_and_locales(): void
    {
        $c = $this->customer();

        foreach (['/account/security', '/en/account/security', '/tr/account/security'] as $url) {
            $html = $this->actingAs($c, 'customer')->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('sec-2fa', $html, $url);
            $this->assertStringNotContainsString('ui.tfa_', $html, 'کلیدِ خامِ ترجمه روی '.$url);
        }

        // در حالِ راه‌اندازی: QR باید واقعاً روی صفحه باشد
        $c->startTwoFactorSetup();
        $html = $this->actingAs($c->fresh(), 'customer')->get('/account/security')->assertOk()->getContent();

        /*
        | ⚠️ `class="tfa-qr"` و نه فقط `tfa-qr`.
        |
        | نامِ کلاس در بلوکِ <style> همین صفحه هم هست، پس جست‌وجوی نامِ خالی در
        | **هر سه حالت** سبز می‌شود — یعنی تستی که هیچ‌وقت شکست نمی‌خورد.
        */
        $this->assertStringContainsString('class="tfa-qr"', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('ui.tfa_', $html);

        // روشن: دیگر نه QR، نه راز
        $c = $c->fresh();
        $secret = $c->two_factor_secret;
        $c->confirmTwoFactor(Totp::code($secret));

        $html = $this->actingAs($c->fresh(), 'customer')->get('/account/security')->assertOk()->getContent();

        $this->assertStringContainsString('class="tfa-off"', $html);
        $this->assertStringNotContainsString('class="tfa-qr"', $html);
        $this->assertStringNotContainsString($secret, $html, 'رازِ فعال نباید روی هر بازدید دوباره چاپ شود');
    }

    public function test_the_panel_security_page_and_login_challenges_render(): void
    {
        $u = $this->admin();

        $this->actingAs($u, 'web')->get('/admin/security')->assertOk()->assertSee('امنیت حساب من');

        $u->startTwoFactorSetup();
        $this->actingAs($u->fresh(), 'web')->get('/admin/security')->assertOk()->assertSee('class="totp-qr"', false);

        $c = $this->customer();
        $this->withTwoFactor($c);

        $this->withSession(['login_2fa' => ['customer_id' => $c->id, 'channel' => 'sms']])
            ->get('/login/2fa')->assertOk()->assertDontSee('ui.tfa_');

        $this->withSession(['admin_totp' => ['user_id' => $u->id, 'remember' => false]])
            ->get('/admin/login/totp')->assertOk()->assertSee('تأیید دومرحله‌ای');
    }

    /**
     * ⚠️ صفحهٔ امنیتِ پنل عمداً برای نویسنده هم باز است.
     *
     * ضعیف‌ترین حساب‌های پنل نباید تنها کسانی باشند که نمی‌توانند از خودشان
     * محافظت کنند.
     */
    public function test_a_non_admin_panel_user_can_still_protect_their_own_account(): void
    {
        $author = User::create([
            'name'     => 'نویسنده',
            'email'    => 'w'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'),
            'role'     => 'author',
        ]);

        $this->actingAs($author, 'web')->get('/admin/security')->assertOk();
        $this->actingAs($author, 'web')->post('/admin/security/2fa/start')->assertRedirect('/admin/security');

        $this->assertTrue($author->fresh()->twoFactorPending());

        // ولی همچنان به بخش‌های مدیریتی راه ندارد
        $this->actingAs($author, 'web')->get('/admin/users')->assertForbidden();
    }
}
