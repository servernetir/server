<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * صفحهٔ امنیت حساب — رمز (با OTP)، قوانین IP + اعمال در ورود، توکن API + endpoint.
 */
class SecurityPageTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,string> */
    public array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->app->instance(SmsSender::class, new class($this) implements SmsSender {
            public function __construct(private SecurityPageTest $t) {}
            public function enabled(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function send(string $m, string $text): bool { return true; }
            public function sendOtp(string $m, string $code): bool { $this->t->codes[$m] = $code; return true; }
        });
    }

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'email' => 'c'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => null, 'status' => 'active', 'locale' => 'fa',
        ], $over));
    }

    public function test_security_page_renders(): void
    {
        $c = $this->customer();
        $this->actingAs($c, 'customer')->get('/account/security')
            ->assertOk()
            ->assertSee('امنیت حساب')
            ->assertSee('محدودسازیِ IP', false)
            ->assertSee('دسترسیِ API', false);
    }

    public function test_password_change_via_otp(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->post('/account/security/password/start')
            ->assertRedirect();
        $code = $this->codes[$c->phone] ?? null;
        $this->assertNotNull($code, 'کد باید فرستاده شده باشد');

        $this->actingAs($c, 'customer')->post('/account/security/password', [
            'code' => $code, 'password' => 'newsecret123', 'password_confirmation' => 'newsecret123',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('newsecret123', $c->fresh()->password));
    }

    public function test_password_change_rejects_wrong_code(): void
    {
        $c = $this->customer();
        $this->actingAs($c, 'customer')->post('/account/security/password/start');

        $this->actingAs($c, 'customer')->post('/account/security/password', [
            'code' => '000000', 'password' => 'newsecret123', 'password_confirmation' => 'newsecret123',
        ])->assertSessionHasErrors('code');

        $this->assertNull($c->fresh()->password);
    }

    public function test_ip_rule_add_and_normalize(): void
    {
        $c = $this->customer();
        $this->actingAs($c, 'customer')->post('/account/security/ip', [
            'cidr' => '1.2.3.4', 'action' => 'allow', 'label' => 'خانه',
        ])->assertRedirect();

        $rule = $c->ipRules()->first();
        $this->assertNotNull($rule);
        $this->assertSame('1.2.3.4/32', $rule->cidr);   // تکِ IP به /32 نرمال شد
        $this->assertSame('allow', $rule->action);
    }

    public function test_invalid_cidr_is_rejected(): void
    {
        $c = $this->customer();
        $this->actingAs($c, 'customer')->post('/account/security/ip', [
            'cidr' => 'not-an-ip', 'action' => 'allow',
        ])->assertSessionHasErrors('cidr');
        $this->assertSame(0, $c->ipRules()->count());
    }

    public function test_enforce_mode_blocks_login_from_non_allowed_ip(): void
    {
        $c = $this->customer();
        // فقط رنجِ 10.0.0.0/8 مجاز است؛ 127.0.0.1 (IP تست) مجاز نیست
        $c->ipRules()->create(['cidr' => '10.0.0.0/8', 'action' => 'allow', 'is_active' => true]);
        $c->forceFill(['ip_restriction_mode' => 'enforce'])->save();

        // جریانِ ورود: کد فرستاده می‌شود چون حساب فعال است
        $this->post('/login', ['method' => 'mobile', 'identifier' => $c->phone])->assertRedirect('/login/code');
        $code = $this->codes[$c->phone] ?? null;
        $this->assertNotNull($code);

        // تأیید کد درست است ولی IP مجاز نیست → مسدود، وارد نمی‌شود
        $this->post('/login/verify', ['code' => $code])->assertRedirect('/login');
        $this->assertGuest('customer');
    }

    public function test_off_mode_does_not_block_login(): void
    {
        $c = $this->customer();
        $c->ipRules()->create(['cidr' => '10.0.0.0/8', 'action' => 'allow', 'is_active' => true]);
        // حالت پیش‌فرض off — نباید بلاک کند حتی با قاعدهٔ نامنطبق
        $this->post('/login', ['method' => 'mobile', 'identifier' => $c->phone])->assertRedirect('/login/code');
        $code = $this->codes[$c->phone];
        $this->post('/login/verify', ['code' => $code])->assertRedirect();
        $this->assertTrue(Auth::guard('customer')->check());
    }

    public function test_enforce_mode_logs_out_active_session_from_blocked_ip(): void
    {
        // اعمالِ پیوسته: حتی نشستِ فعال (بدونِ گذر از ورود) هم باید در حالتِ
        // enforce از IPِ غیرمجاز خارج شود — تا کوکیِ remember دور نزند.
        $c = $this->customer();
        $c->ipRules()->create(['cidr' => '10.0.0.0/8', 'action' => 'allow', 'is_active' => true]);
        $c->forceFill(['ip_restriction_mode' => 'enforce'])->save();

        // IP تست 127.0.0.1 در فهرستِ مجاز نیست → میدل‌ور خارجش می‌کند
        $this->actingAs($c, 'customer')->get('/account')->assertRedirect('/login');
        $this->assertGuest('customer');
    }

    public function test_enforce_does_not_lock_out_from_allowed_ip(): void
    {
        // 127.0.0.1 مجاز است → نشست باقی می‌ماند
        $c = $this->customer();
        $c->ipRules()->create(['cidr' => '127.0.0.1/32', 'action' => 'allow', 'is_active' => true]);
        $c->forceFill(['ip_restriction_mode' => 'enforce'])->save();

        $this->actingAs($c, 'customer')->get('/account')->assertOk();
    }

    public function test_api_token_create_and_use(): void
    {
        $c = $this->customer();

        // ساخت از UI
        $this->actingAs($c, 'customer')->post('/account/security/api-token', ['name' => 'monitor'])
            ->assertRedirect()->assertSessionHas('new_token');
        $this->assertSame(1, $c->apiTokens()->count());

        // استفاده از API با توکن (issue مستقیم برای گرفتنِ متنِ خام)
        [, $plain] = CustomerApiToken::issue($c->id, 'test2', ['read']);
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.code', $c->code);
    }

    public function test_api_rejects_missing_and_bad_token(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer sn_bogus'])->assertStatus(401);
    }

    public function test_api_token_revoke(): void
    {
        $c = $this->customer();
        [$token] = CustomerApiToken::issue($c->id, 'x', ['read']);

        $this->actingAs($c, 'customer')->post("/account/security/api-token/{$token->id}/delete")
            ->assertRedirect();

        /*
        | ابطال از شهریور ۱۴۰۵ **نرم** است، نه حذفِ فیزیکی.
        |
        | این تست پیش از این `assertNull(find($id))` می‌زد. تغییرش عمدی است:
        | با آمدنِ توکنِ نوشتنیِ نمایندگی، حذفِ فیزیکی یعنی درست در لحظه‌ای که
        | کاربر می‌گوید «این توکن لو رفته»، تنها ردی که می‌گفت آن توکن چه کرده
        | هم پاک می‌شود (`reseller_api_logs.token_id` به نال می‌افتد) — یعنی
        | حسابرسیِ حادثه دقیقاً وقتی از بین می‌رود که لازمش داریم.
        |
        | چیزی که کاربر می‌خواهد همچنان برقرار است و همین‌جا سنجیده می‌شود:
        | توکن از فهرست می‌رود و **دیگر کار نمی‌کند**.
        */
        $token->refresh();

        $this->assertNotNull($token->revoked_at, 'توکن باطل نشد');
        $this->assertSame('token_revoked', $token->unusableReason());
        $this->assertSame(0, $c->apiTokens()->usable()->count(), 'توکنِ باطل هنوز در فهرست است');
    }
}
