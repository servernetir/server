<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ورود با کد یک‌بارمصرف — موبایل‌اول، ایمیل جایگزین.
 */
class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,string> destination => code */
    public array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->app->instance(SmsSender::class, new class($this) implements SmsSender {
            public function __construct(private OtpLoginTest $t) {}
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

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'email' => 'c'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ], $over));
    }

    public function test_mobile_otp_login_end_to_end(): void
    {
        $c = $this->customer();

        $this->post('/login', ['method' => 'mobile', 'identifier' => $c->phone])
            ->assertRedirect('/login/code');

        $this->get('/login/code')->assertOk()->assertSee('***');

        $code = $this->codes[$c->phone] ?? null;
        $this->assertNotNull($code, 'کد باید فرستاده شده باشد');

        $this->post('/login/verify', ['code' => $code])->assertRedirect();
        $this->assertTrue(Auth::guard('customer')->check());
        $this->assertSame($c->id, Auth::guard('customer')->id());
        $this->assertNotNull($c->fresh()->last_login_ip);
    }

    public function test_wrong_code_does_not_log_in(): void
    {
        $c = $this->customer();
        $this->post('/login', ['method' => 'mobile', 'identifier' => $c->phone])->assertRedirect('/login/code');

        $this->post('/login/verify', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_unknown_number_redirects_to_signup_and_sends_nothing(): void
    {
        // شماره‌ای که هیچ حسابی ندارد: کد فرستاده نمی‌شود و کاربر با موبایلِ
        // پرشده به صفحهٔ ثبت‌نام هدایت می‌شود (رفتارِ خواسته‌شدهٔ کارفرما).
        $this->post('/login', ['method' => 'mobile', 'identifier' => '09120000000'])
            ->assertRedirect('/register')
            ->assertSessionHas('reg_notice');

        $this->assertEmpty($this->codes);
    }

    public function test_pending_registration_is_sent_to_signup(): void
    {
        // ثبت‌نامِ نیمه‌کاره (status=pending) هم به‌جای کد، به ثبت‌نام می‌رود
        $c = $this->customer(['status' => 'pending']);

        $this->post('/login', ['method' => 'mobile', 'identifier' => $c->phone])
            ->assertRedirect('/register');

        $this->assertEmpty($this->codes);
    }

    public function test_email_channel_issues_a_code(): void
    {
        Mail::fake();   // ارسال واقعی نرود
        $c = $this->customer();

        $this->post('/login', ['method' => 'email', 'identifier' => $c->email])
            ->assertRedirect('/login/code');

        // چالش ماندگارِ channel=email یعنی sendEmail موفق بوده (وگرنه issue حذفش می‌کرد)
        $this->assertDatabaseHas('otp_challenges', [
            'channel' => 'email', 'destination' => $c->email, 'purpose' => 'login',
        ]);
    }

    public function test_suspended_customer_is_blocked_at_start_without_sending_code(): void
    {
        // حسابِ معلق پیش از ارسالِ کد رد می‌شود تا کدِ پولی هدر نرود
        $c = $this->customer(['status' => 'suspended']);

        $this->post('/login', ['method' => 'mobile', 'identifier' => $c->phone])
            ->assertSessionHasErrors('identifier');

        $this->assertEmpty($this->codes);
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_invalid_mobile_is_rejected(): void
    {
        $this->post('/login', ['method' => 'mobile', 'identifier' => '12345'])
            ->assertSessionHasErrors('identifier');
    }

    public function test_destination_cannot_be_swapped_at_verify_step(): void
    {
        // کد برای حساب A فرستاده می‌شود؛ نباید بشود با آن وارد حساب B شد
        $a = $this->customer();
        $this->post('/login', ['method' => 'mobile', 'identifier' => $a->phone])->assertRedirect('/login/code');
        $code = $this->codes[$a->phone];

        // مقصد از نشست می‌آید نه فرم؛ verify فقط code می‌گیرد. کد A فقط A را وارد می‌کند.
        $this->post('/login/verify', ['code' => $code])->assertRedirect();
        $this->assertSame($a->id, Auth::guard('customer')->id());
    }
}
