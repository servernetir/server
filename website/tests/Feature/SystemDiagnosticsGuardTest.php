<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 `/system/openprovider` یک روتِ کاملاً عمومی بود که با یک پرچمِ سادهٔ کوئری
 * تماس‌های **واقعی و پولی** می‌زد.
 *
 * کامنتِ بالای کد می‌گفت «فقط دستی زده می‌شود»، ولی هیچ چیزی در کد این را
 * تضمین نمی‌کرد. یعنی هر کسی روی اینترنت با یک URL می‌توانست:
 *
 *   • تلاشِ ورودِ واقعی به رجیسترار بزند — از آی‌پیِ اصلیِ سرور
 *   • authorityِ واقعیِ زرین‌پال با merchant_idِ زنده بسازد
 *   • با `?mailtest=1` صفِ ارسالِ SMTP را بسوزاند
 *
 * و اولی دقیقاً همان کاری است که یک‌بار حسابِ رجیسترار را علامت‌دار کرد. با
 * سقفِ ۴۰ درخواست در دقیقه، یک نفر می‌توانست حساب را قفل کند — و آن‌وقت هیچ
 * دامنه‌ای ثبت نمی‌شد.
 */
class SystemDiagnosticsGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ هر تماسِ بیرونی مسدود می‌شود تا اگر محافظ نشتی داشته باشد، تست
        //    خودش به رجیسترارِ واقعی وصل نشود.
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['code' => 0], 200)]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** بخشِ فقط‌خواندنی عمومی می‌مانَد — برای عیب‌یابیِ allowlist لازم است */
    public function test_the_read_only_part_stays_public(): void
    {
        $json = $this->get('/system/openprovider')->assertOk()->json();

        $this->assertArrayHasKey('creds_present', $json);
        $this->assertFalse($json['admin']);
    }

    /** 🔴 مهم‌ترین تست: مهمان نمی‌تواند تماسِ واقعی به رجیسترار بزند */
    public function test_a_guest_cannot_trigger_a_registrar_login(): void
    {
        $json = $this->get('/system/openprovider?probe=1')->assertOk()->json();

        $this->assertSame('skipped', $json['auth'],
            'مهمان توانست تلاشِ ورود به رجیسترار بزند — همان کاری که حساب را قفل می‌کند');
        $this->assertNull($json['sample_code']);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/auth/login'));
    }

    /** درگاهِ پرداخت هم نباید با یک URL از بیرون تحریک شود */
    public function test_a_guest_cannot_trigger_the_payment_gateway(): void
    {
        $json = $this->get('/system/openprovider?probe=1')->assertOk()->json();

        $this->assertNull($json['zarinpal_test']);
        $this->assertNull($json['zarinpal_gw_start']);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'zarinpal.com'));
    }

    /** ارسالِ ایمیل هم — وگرنه هر بازدید یک ایمیل و سوختنِ سهمیهٔ SMTP */
    public function test_a_guest_cannot_trigger_a_real_email(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $this->get('/system/openprovider?mailtest=1')->assertOk();

        \Illuminate\Support\Facades\Mail::assertNothingSent();
    }

    /** نقشهٔ زیرساختِ ایمیل هم عمومی نیست — کارِ جعلِ فرستنده را آسان می‌کند */
    public function test_mail_configuration_is_hidden_from_guests(): void
    {
        $json = $this->get('/system/openprovider')->assertOk()->json();

        $this->assertIsString($json['mail_env_values']);
        $this->assertIsString($json['mail_env_keys']);
    }

    /** مدیرِ واردشده همچنان همه‌چیز را می‌بیند — ابزار نباید بی‌فایده شود */
    public function test_an_admin_still_sees_everything(): void
    {
        $json = $this->actingAs($this->admin())->get('/system/openprovider')->assertOk()->json();

        $this->assertTrue($json['admin']);
        $this->assertIsArray($json['mail_env_values']);
    }

    // ═══════════════ رلهٔ بله در `/system/sms-status` ═══════════════

    /**
     * روت باید **بالا بیاید**.
     *
     * نسخهٔ اولِ این تشخیص `app(BaleRelaySender::class)` را صدا می‌زد. سازندهٔ آن
     * سه رشته می‌گیرد و در کانتینر بسته نشده، پس autowire شکست می‌خورد و روت
     * ۵۰۰ می‌داد — یعنی ابزارِ عیب‌یابی دقیقاً وقتی می‌مُرد که لازمش داشتیم، و
     * چون محلی هم همان اتفاق می‌افتاد، فقط این تست جلویش را می‌گیرد.
     */
    public function test_the_sms_status_route_does_not_blow_up(): void
    {
        $json = $this->get('/system/sms-status')->assertOk()->json();

        foreach (['bot_token_set', 'chat_id_set', 'secret_set'] as $k) {
            $this->assertIsBool($json['bale_relay'][$k], "bale_relay.{$k} باید بولین باشد");
        }

        $this->assertIsArray($json['bale_relay']['duplicate_keys']);
    }

    /**
     * 🔴 هیچ رازی برنمی‌گردد.
     *
     * این روت **عمومی** است. توکنِ ربات و رازِ HMAC آن‌جا هستند و چاپِ حتی
     * بخشی‌شان یعنی هر کسی می‌تواند پیامِ جعلی به صفِ پیامک تزریق کند. تست با
     * مقادیرِ شناخته‌شده پُر می‌کند و می‌سنجد که در بدنه نباشند.
     */
    public function test_the_relay_secrets_never_appear_in_the_response(): void
    {
        config([
            'services.bale_relay.bot_token' => '1234567:AA-SECRET-BOT-TOKEN',
            'services.bale_relay.chat_id'   => '-1009876543210',
            'services.bale_relay.secret'    => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4',
        ]);

        $body = $this->get('/system/sms-status')->assertOk()->getContent();

        foreach (['AA-SECRET-BOT-TOKEN', '-1009876543210', 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'] as $secret) {
            $this->assertStringNotContainsString($secret, $body,
                'رازِ رله در پاسخِ یک روتِ عمومی نشت کرد');
        }
    }
}
