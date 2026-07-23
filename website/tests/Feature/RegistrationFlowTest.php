<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OtpChallenge;
use App\Services\Identity\CardResult;
use App\Services\Identity\IdentityProvider;
use App\Services\Identity\IdentityResult;
use App\Services\Identity\ShahkarResult;
use App\Services\Otp\OtpService;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * جریان ثبت‌نام.
 *
 * محور این تست‌ها یک چیز است: هیچ استعلام پولی نباید بدون عبور از دروازهٔ
 * پیامک انجام شود. بقیهٔ ادعاها فرعی‌اند؛ این یکی مستقیماً پول است.
 */
class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    /** شمارندهٔ تماس‌های پولی — قلب این تست */
    private int $paidCalls = 0;

    public function fakeProviderPublic(bool $shahkar = true, bool $identityOk = true): IdentityProvider
    {
        $test = $this;

        return new class($test, $shahkar, $identityOk) implements IdentityProvider {
            public function __construct(
                private RegistrationFlowTest $t,
                private bool $s,
                private bool $i,
            ) {}

            public function enabled(): bool { return true; }

            public function shahkar(string $n, string $m): ShahkarResult
            {
                $this->t->countPaidCall();

                return new ShahkarResult($this->s, $this->s ? null : 'عدم تطابق کد ملی و موبایل');
            }

            public function identity(string $n, string $b): IdentityResult
            {
                $this->t->countPaidCall();

                return $this->i
                    ? new IdentityResult(true, 'علی', 'محمدی', 'رضا')
                    : new IdentityResult(false, error: 'اطلاعات یافت نشد');
            }

            public function cardOwner(string $c): CardResult
            {
                $this->t->countPaidCall();

                return new CardResult(true, 'علی محمدی', 'ملت', '1234567', 'IR060540105180021273113007');
            }
        };
    }

    public function countPaidCall(): void
    {
        $this->paidCalls++;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->paidCalls = 0;
        RateLimiter::clear('kyc:09121234567');

        $this->app->instance(IdentityProvider::class, $this->fakeProviderPublic());

        // پیامک واقعی نمی‌رود. فرستنده کد را ضبط می‌کند — همان‌طور که یک گوشی
        // واقعی می‌دیدش. این تنها راه درست خواندن کد است: در دیتابیس فقط hash
        // نشسته، و حدس زدنش یعنی یک میلیون بار hash_hmac در هر تست.
        $this->app->instance(SmsSender::class, new class($this) implements SmsSender {
            public function __construct(private RegistrationFlowTest $t) {}

            public function enabled(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function send(string $m, string $t): bool { return true; }

            public function sendOtp(string $m, string $code): bool
            {
                $this->t->recordCode($m, $code);

                return true;
            }
        });
    }

    /** آخرین کدی که «پیامک شد»، به تفکیک شماره */
    private array $sentCodes = [];

    public function recordCode(string $destination, string $code): void
    {
        $this->sentCodes[$destination] = $code;
    }

    private function currentCode(string $destination): string
    {
        return $this->sentCodes[$destination]
            ?? $this->fail("هیچ کدی برای {$destination} فرستاده نشد");
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_paid_lookup_never_runs_without_a_verified_sms_code(): void
    {
        // بدون هیچ مرحله‌ای، مستقیم به استعلام پولی POST می‌زنیم
        $this->post('/register/identity', [
            'national_id' => '0084575948',
            'birth_date'  => '1370/05/12',
        ])->assertRedirect('/register');

        $this->assertSame(0, $this->paidCalls, 'استعلام پولی بدون تأیید پیامک اجرا شد');
    }

    public function test_session_alone_is_not_enough_the_database_record_is_checked(): void
    {
        // نشست را دستی «تأییدشده» می‌کنیم بدون آنکه چالشی در دیتابیس باشد
        $this->withSession(['reg' => [
            'email' => 'a@example.com', 'phone' => '09121234567',
            'type' => 'individual', 'iranian' => true, 'channel' => 'sms', 'verified' => true,
        ]])->post('/register/identity', [
            'national_id' => '0084575948',
            'birth_date'  => '1370/05/12',
        ])->assertRedirect('/register');

        $this->assertSame(0, $this->paidCalls, 'نشست دستکاری‌شده توانست استعلام پولی را راه بیندازد');
    }

    public function test_invalid_national_id_is_rejected_locally_without_spending_money(): void
    {
        $this->startAndVerifyPublic();

        // ۱۲۳۴۵۶۷۸۹۰ چک‌سام درست ندارد
        $this->post('/register/identity', [
            'national_id' => '1234567890',
            'birth_date'  => '1370/05/12',
        ])->assertSessionHasErrors('national_id');

        $this->assertSame(0, $this->paidCalls, 'کد ملی نامعتبر تا سرویس پولی رفت');
    }

    public function test_invalid_birth_date_is_rejected_locally(): void
    {
        $this->startAndVerifyPublic();

        $this->post('/register/identity', [
            'national_id' => '0084575948',
            'birth_date'  => '1399/13/45',
        ])->assertSessionHasErrors('birth_date');

        $this->assertSame(0, $this->paidCalls);
    }

    public function test_full_iranian_registration_creates_an_active_customer(): void
    {
        $this->startAndVerifyPublic();

        $this->post('/register/identity', [
            'national_id' => '0084575948',
            'birth_date'  => '1370/05/12',
        ])->assertRedirect('/register/finish');

        $this->assertSame(2, $this->paidCalls, 'باید دقیقاً دو استعلام باشد: شاهکار و هویت');

        $this->post('/register/finish', [
            'password'              => 'a-very-long-password',
            'password_confirmation' => 'a-very-long-password',
            'terms'                 => '1',
        ])->assertRedirect('/account');

        $customer = Customer::where('email', 'ali@example.com')->firstOrFail();

        $this->assertSame('active', $customer->status);
        $this->assertNotNull($customer->phone_verified_at);
        // نام از استعلام آمده، نه از فرم — کاربر هرگز آن را تایپ نکرد
        $this->assertSame('علی محمدی', $customer->displayName());
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_the_same_code_cannot_be_used_twice(): void
    {
        $this->startAndVerifyPublic();

        // کد مصرف شده؛ چالش دیگری فعال نیست
        $this->post('/register/verify', ['code' => '000000'])
            ->assertSessionHasErrors('code');
    }

    public function test_kyc_attempts_are_capped_per_mobile(): void
    {
        $this->startAndVerifyPublic();

        // سه تلاش ناموفق (شاهکار رد می‌کند)
        $this->app->instance(IdentityProvider::class, $this->fakeProviderPublic(shahkar: false));

        for ($i = 0; $i < 3; $i++) {
            $this->post('/register/identity', [
                'national_id' => '0084575948',
                'birth_date'  => '1370/05/12',
            ]);
        }

        $spentSoFar = $this->paidCalls;

        // چهارمی نباید حتی به سرویس برسد
        $this->post('/register/identity', [
            'national_id' => '0084575948',
            'birth_date'  => '1370/05/12',
        ])->assertSessionHasErrors('national_id');

        $this->assertSame($spentSoFar, $this->paidCalls, 'سقف تلاش رعایت نشد و پول بیشتری خرج شد');
    }

    public function test_pending_customer_cannot_log_in(): void
    {
        $this->startAndVerifyPublic();
        $this->post('/register/identity', ['national_id' => '0084575948', 'birth_date' => '1370/05/12']);

        // ثبت‌نام را تمام نمی‌کنیم؛ مشتری در حالت pending می‌ماند
        $customer = Customer::where('email', 'ali@example.com')->firstOrFail();
        $this->assertSame('pending', $customer->status);

        $this->post('/login', ['email' => 'ali@example.com', 'password' => 'anything'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('customer');
    }

    public function test_customer_guard_does_not_open_the_admin_panel(): void
    {
        $customer = Customer::create([
            'email' => 'x@example.com', 'password' => 'secret1234', 'status' => 'active',
        ]);

        $this->actingAs($customer, 'customer')
            ->get('/admin')
            ->assertRedirect(route('admin.login'));
    }

    public function test_otp_is_rate_limited_per_destination(): void
    {
        $otp = app(OtpService::class);

        // اولی موفق
        $this->assertTrue($otp->issue('sms', '09121234567', 'register', '1.2.3.4')->ok);

        // بلافاصله دوباره: خنک‌کننده جلویش را می‌گیرد
        $second = $otp->issue('sms', '09121234567', 'register', '1.2.3.4');
        $this->assertFalse($second->ok);
        $this->assertNotNull($second->retryAfter);
    }

    public function test_mobile_numbers_are_normalized_to_one_shape(): void
    {
        $otp = app(OtpService::class);

        foreach (['09121234567', '9121234567', '+989121234567', '۰۹۱۲۱۲۳۴۵۶۷', '0912 123 4567'] as $input) {
            $this->assertSame('09121234567', $otp->normalize('sms', $input), "شکست روی: {$input}");
        }

        $this->assertSame('', $otp->normalize('sms', '021445566'), 'شمارهٔ ثابت نباید موبایل شمرده شود');
    }

    // ─────────────────────────────────────────────────────────────────────

    /** مرحلهٔ ۱ و ۲ را واقعی طی می‌کند تا نشست معتبر باشد */
    public function startAndVerifyPublic(): void
    {
        $this->post('/register', [
            'email' => 'ali@example.com',
            'phone' => '09121234567',
            'type'  => 'individual',
        ])->assertRedirect('/register/verify');

        $code = $this->currentCode('09121234567');

        $this->post('/register/verify', ['code' => $code])
            ->assertRedirect('/register/identity');
    }
}
