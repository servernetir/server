<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ورود دومرحله‌ای مدیر: رمز درست → کد به ایمیل → تأیید کد → ورود.
 */
class AdminAuthOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function admin(): User
    {
        return User::create([
            'name'     => 'مدیر',
            'email'    => 'boss'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'),
            'role'     => 'admin',
        ]);
    }

    public function test_correct_password_sends_otp_and_does_not_log_in(): void
    {
        Mail::fake();
        $u = $this->admin();

        $this->post('/admin/login', ['email' => $u->email, 'password' => 'secret1234'])
            ->assertRedirect(route('admin.login.otp'));

        $this->assertFalse(Auth::check(), 'رمزِ درست نباید مستقیم وارد کند — اول باید کد را تأیید کرد');
        Mail::assertSent(OtpMail::class);
    }

    public function test_wrong_password_is_rejected_without_sending_otp(): void
    {
        Mail::fake();
        $u = $this->admin();

        $this->post('/admin/login', ['email' => $u->email, 'password' => 'WRONG-PASS'])
            ->assertSessionHasErrors('email');

        $this->assertFalse(Auth::check());
        Mail::assertNothingSent();
    }

    public function test_full_two_factor_flow_logs_in(): void
    {
        Mail::fake();
        $u = $this->admin();

        $this->post('/admin/login', ['email' => $u->email, 'password' => 'secret1234'])
            ->assertRedirect(route('admin.login.otp'));

        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $this->assertNotNull($code, 'کد باید در ایمیل باشد');

        $this->post('/admin/login/otp', ['code' => $code])->assertRedirect('/admin');
        $this->assertTrue(Auth::check());
        $this->assertSame($u->id, Auth::id());
    }

    public function test_wrong_otp_does_not_log_in(): void
    {
        Mail::fake();
        $u = $this->admin();
        $this->post('/admin/login', ['email' => $u->email, 'password' => 'secret1234']);

        $this->post('/admin/login/otp', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse(Auth::check());
    }

    public function test_otp_page_requires_pending_session(): void
    {
        // بدونِ گذر از مرحلهٔ رمز، صفحهٔ کد به ورود برمی‌گردد
        $this->get('/admin/login/otp')->assertRedirect('/admin/login');
    }
}
