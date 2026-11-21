<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\{Customer, User};
use App\Services\Security\Totp;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminLoginMethodsTest extends TestCase
{
    use RefreshDatabase;

    private object $sms;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        $this->sms = new class implements SmsSender {
            public array $codes = [];
            public function enabled(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function send(string $mobile, string $text): bool { return true; }
            public function sendOtp(string $mobile, string $code): bool { $this->codes[$mobile] = $code; return true; }
        };
        $this->app->instance(SmsSender::class, $this->sms);
        $this->mock(\App\Services\Bale\BaleNotifier::class)->shouldNotReceive('otp');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'password' => 'secret1234']);
    }

    private function smsUser(): User
    {
        $user = $this->admin();
        $user->forceFill(['login_sms_phone' => '09120001122', 'login_sms_confirmed_at' => now()])->save();
        return $user;
    }

    private function authenticator(User $user): string
    {
        $secret = $user->startTwoFactorSetup();
        $user->refresh()->confirmTwoFactor(Totp::code($secret));
        $this->travel(31)->seconds();
        return $secret;
    }

    private function login(User $user, string $method, array $extra = [])
    {
        return $this->post('/admin/login', $extra + ['email' => $user->email,
            'password' => 'secret1234', 'method' => $method]);
    }

    public function test_selected_email_is_a_complete_alternative_to_the_authenticator(): void
    {
        $user = $this->admin();
        $this->authenticator($user);
        $this->login($user, 'email')->assertRedirect(route('admin.login.otp'));
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$code) { $code = $mail->code; return true; });
        $this->assertGuest('web');
        $this->post('/admin/login/otp', ['code' => $code])->assertRedirect('/admin')
            ->assertSessionMissing('admin_totp')->assertSessionMissing('admin_2fa');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame([], $this->sms->codes);
    }

    public function test_selected_authenticator_does_not_require_an_email_or_sms_code(): void
    {
        $user = $this->admin();
        $secret = $this->authenticator($user);
        $this->login($user, 'totp')->assertRedirect(route('admin.login.totp'));
        $this->assertGuest('web');
        Mail::assertNothingSent();
        $this->assertSame([], $this->sms->codes);
        $this->post('/admin/login/totp', ['code' => Totp::code($secret)])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_sms_uses_only_the_verified_account_phone_and_cannot_skip_its_challenge(): void
    {
        $user = $this->smsUser();
        Customer::create(['email' => 'customer@example.test', 'phone' => '09120001122',
            'password' => 'test-only', 'status' => 'active']);
        $this->login($user, 'sms', ['phone' => '09129999999'])->assertRedirect(route('admin.login.otp'));
        $this->assertSame(['09120001122'], array_keys($this->sms->codes));
        Mail::assertNothingSent();
        $this->assertGuest('web');
        $code = $this->sms->codes['09120001122'];
        $this->post('/admin/login/totp', ['code' => $code])->assertRedirect('/admin/login');
        $this->assertGuest('web');
        $this->post('/admin/login/otp', ['code' => $code, 'method' => 'email'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_unregistered_factors_do_not_send_anything_or_authenticate(): void
    {
        $user = $this->admin();
        $user->forceFill(['login_sms_phone' => '09120001122'])->save();
        foreach (['sms', 'totp'] as $method) {
            $this->login($user, $method)->assertSessionHasErrors('method');
            $this->assertGuest('web');
        }
        Mail::assertNothingSent();
        $this->assertSame([], $this->sms->codes);
    }

    public function test_wrong_password_never_sends_sms(): void
    {
        $this->login($this->smsUser(), 'sms', ['password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest('web');
        $this->assertSame([], $this->sms->codes);
    }

    public function test_password_change_invalidates_a_pending_sms_login(): void
    {
        $user = $this->smsUser();
        $this->login($user, 'sms');
        $user->forceFill(['password' => 'changed-password'])->save();
        $this->post('/admin/login/otp', ['code' => $this->sms->codes['09120001122']])
            ->assertRedirect('/admin/login');
        $this->assertGuest('web');
    }

    public function test_phone_change_invalidates_a_pending_sms_login(): void
    {
        $user = $this->smsUser();
        $this->login($user, 'sms');
        $user->forceFill(['login_sms_phone' => '09120003344'])->save();
        $this->post('/admin/login/otp', ['code' => $this->sms->codes['09120001122']])
            ->assertRedirect('/admin/login');
        $this->assertGuest('web');
    }

    public function test_pending_authenticator_login_expires_even_with_a_current_valid_code(): void
    {
        $user = $this->admin();
        $secret = $this->authenticator($user);
        $this->login($user, 'totp');
        $this->travel(11)->minutes();
        $this->post('/admin/login/totp', ['code' => Totp::code($secret)])->assertRedirect('/admin/login');
        $this->assertGuest('web');
    }

    public function test_phone_enrollment_requires_password_and_proof_and_changes_only_its_owner(): void
    {
        $owner = $this->admin();
        $other = $this->admin();
        Customer::create(['email' => 'customer@example.test', 'phone' => '09120001122',
            'password' => 'test-only', 'status' => 'active']);
        $this->actingAs($owner, 'web')->post('/admin/security/sms/start', [
            'phone' => '09120001122', 'current_password' => 'wrong',
        ])->assertSessionHasErrors('current_password');
        $this->assertSame([], $this->sms->codes);
        $this->post('/admin/security/sms/start', ['phone' => '09120001122',
            'current_password' => 'secret1234', 'user_id' => $other->id])->assertRedirect('/admin/security');
        $this->assertFalse($owner->fresh()->hasSmsLogin());
        $this->post('/admin/security/sms/confirm', ['code' => 'wrong'])->assertRedirect('/admin/security');
        $this->assertFalse($owner->fresh()->hasSmsLogin());
        $this->post('/admin/security/sms/confirm', ['code' => $this->sms->codes['09120001122'], 'user_id' => $other->id])
            ->assertRedirect('/admin/security')->assertSessionMissing('admin_sms_enrollment');
        $this->assertTrue($owner->fresh()->hasSmsLogin());
        $this->assertFalse($other->fresh()->hasSmsLogin());
        $this->assertNotSame('09120001122', DB::table('users')->where('id', $owner->id)->value('login_sms_phone'));
        $this->assertArrayNotHasKey('login_sms_phone', $owner->fresh()->toArray());
        Mail::assertNothingSent();
        $this->get('/admin/security')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private')->assertSee('091****1122');
    }

    public function test_phone_enrollment_cannot_be_finished_by_another_account(): void
    {
        $owner = $this->admin();
        $this->actingAs($owner, 'web')->post('/admin/security/sms/start', [
            'phone' => '09120001122', 'current_password' => 'secret1234',
        ])->assertRedirect('/admin/security');
        $other = $this->admin();
        $this->actingAs($other, 'web')->post('/admin/security/sms/confirm', [
            'code' => $this->sms->codes['09120001122'],
        ])->assertRedirect('/admin/security');
        $this->assertFalse($owner->fresh()->hasSmsLogin());
        $this->assertFalse($other->fresh()->hasSmsLogin());
    }

    public function test_sms_code_cannot_be_reused_after_successful_login(): void
    {
        $user = $this->smsUser();
        $this->login($user, 'sms');
        $code = $this->sms->codes['09120001122'];
        $this->post('/admin/login/otp', ['code' => $code])->assertRedirect('/admin');
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->login($user, 'sms');
        $this->post('/admin/login/otp', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('web');
    }

    public function test_login_form_offers_all_three_methods(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('value="email"', false)
            ->assertSee('value="totp"', false)->assertSee('value="sms"', false);
    }
}
