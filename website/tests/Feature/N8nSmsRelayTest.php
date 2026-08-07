<?php

namespace Tests\Feature;

use App\Services\Sms\BaleRelaySender;
use App\Services\Sms\N8nRelaySender;
use App\Services\Sms\SmsDispatcher;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * رلهٔ پیامک — مستقیم به وب‌هوکِ n8n.
 *
 * ═══ چرا این مسیر ساخته شد ═══
 *
 * مسیرِ بله **هرگز کار نکرد**: بله (مثلِ تلگرام که کپی‌اش است) پیامِ یک ربات
 * را به رباتِ دیگر تحویل نمی‌دهد، پس رباتِ گیرنده هیچ‌وقت چیزی نمی‌دید.
 * وب‌هوک درست ست بود، صفِ بله خالی بود، رباتِ فرستنده پیام را در گروه
 * می‌نوشت — و n8n برای هیچ‌کدام اجرایی نمی‌ساخت، در حالی که درخواستِ مستقیم
 * بی‌درنگ اجرا می‌ساخت.
 */
class N8nSmsRelayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-shared-secret';

    private const URL = 'https://flow.example.test/webhook/servernet-sms-relay';

    private function relay(): N8nRelaySender
    {
        return new N8nRelaySender(self::URL, self::SECRET);
    }

    /**
     * ⚠️ `Http::swap(new Factory)` لازم است: `Http::fake()` استابها را به
     * ترتیبِ ثبت می‌سنجد و **اولین تطبیق برنده است**، پس یک استابِ `'*'` از
     * فیکسچرِ دیگر هر fakeِ بعدی را بی‌اثر می‌کند و تست بی‌صدا هیچ نمی‌سنجد.
     */
    private function fakeN8n(array $body = ['status' => 'sent'], int $status = 200): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($body, $status)]);
    }

    /** پاکتی که واقعاً روی سیم رفت */
    private function captured(): array
    {
        $sent = null;

        Http::assertSent(function ($r) use (&$sent) {
            $sent = ['url' => $r->url(), 'data' => $r->data()];

            return true;
        });

        $this->assertNotNull($sent, 'هیچ درخواستی به n8n نرفت');

        return $sent;
    }

    // ═══════════════ قراردادِ سیم ═══════════════

    public function test_it_posts_the_signed_envelope_to_the_webhook(): void
    {
        $this->fakeN8n();

        $this->assertTrue($this->relay()->sendOtp('09142223343', '483920'));

        $sent = $this->captured();

        $this->assertSame(self::URL, $sent['url']);
        $this->assertArrayHasKey('envelope', $sent['data'],
            'n8n پاکت را در کلیدِ `envelope` انتظار دارد');
        $this->assertStringStartsWith('SMS_RELAY_V1:', $sent['data']['envelope']);
    }

    /**
     * 🔴 امضا باید با همان رازی که n8n دارد قابلِ تأیید باشد.
     *
     * این تست همان محاسبه‌ای را می‌کند که گرهٔ n8n می‌کند. اگر روزی شکلِ پاکت
     * یا محلِ امضا عوض شود، این‌جا قرمز می‌شود — نه در پروداکشن با یک
     * `bad_signature` که کدِ ۲۰۰ می‌گیرد و شبیهِ موفقیت است.
     */
    public function test_the_signature_is_verifiable_with_the_shared_secret(): void
    {
        $this->fakeN8n();
        $this->relay()->sendOtp('09142223343', '483920');

        $envelope = $this->captured()['data']['envelope'];
        $rest = substr($envelope, strlen('SMS_RELAY_V1:'));
        $dot = strrpos($rest, '.');

        [$b64, $sig] = [substr($rest, 0, $dot), substr($rest, $dot + 1)];

        $this->assertSame(hash_hmac('sha256', $b64, self::SECRET), $sig,
            'امضا روی رشتهٔ Base64 زده نشده — n8n ردش می‌کند');

        $payload = json_decode(base64_decode(strtr($b64, '-_', '+/')), true);

        $this->assertSame('otp', $payload['template']);
        $this->assertSame('+989142223343', $payload['mobile']);
        $this->assertSame(['code' => '483920'], $payload['params']);
        $this->assertEqualsWithDelta(time(), $payload['issued_at'], 5,
            'مهرِ زمان باید حالا باشد — n8n پاکتِ کهنه‌تر از ۱۸۰ ثانیه را رد می‌کند');
    }

    /** 🔴 رازِ رله هرگز داخلِ پاکت نمی‌رود — فقط امضا را می‌سازد */
    public function test_the_secret_never_travels_inside_the_envelope(): void
    {
        $this->fakeN8n();
        $this->relay()->sendOtp('09142223343', '483920');

        $sent = json_encode($this->captured(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::SECRET, $sent);
    }

    /** کدِ الگو و کلیدِ آی‌پی‌پنل هم نباید برود — ترجمه‌شان کارِ n8n است */
    public function test_no_ippanel_pattern_code_is_sent(): void
    {
        $this->fakeN8n();
        $this->relay()->sendPattern('09142223343', 'invoice', ['number' => 'SN-1', 'amount' => '۱۰۰']);

        $envelope = $this->captured()['data']['envelope'];
        $payload = json_decode(base64_decode(strtr(
            substr($envelope, strlen('SMS_RELAY_V1:'), strrpos($envelope, '.') - strlen('SMS_RELAY_V1:')),
            '-_', '+/'
        )), true);

        $this->assertSame('invoice', $payload['template'],
            'فقط نامِ منطقی می‌رود، نه کدِ الگوی اپراتور');
    }

    // ═══════════════ 🔴 دامِ «۲۰۰ ولی نرفت» ═══════════════

    /**
     * ورک‌فلو برای پاکتی که از فیلتر رد نشود هم **کدِ ۲۰۰** می‌دهد:
     *
     *     {"status":"ignored","reason":"bad_signature"}
     *
     * اگر فقط به کدِ HTTP نگاه کنیم، رازِ ناهماهنگ «موفق» گزارش می‌شود و ما
     * باور می‌کنیم پیامک رفته. این دقیقاً همان کلاس از خرابیِ خاموش است که
     * کلِ این حوزه بارها خورده.
     */
    public function test_an_ignored_response_is_a_failure_even_with_http_200(): void
    {
        $this->fakeN8n(['status' => 'ignored', 'reason' => 'bad_signature']);

        $this->assertFalse($this->relay()->sendOtp('09142223343', '483920'),
            'پاسخِ ignored با کدِ ۲۰۰ به‌عنوان موفقیت شمرده شد');
    }

    public function test_a_failed_response_is_a_failure(): void
    {
        $this->fakeN8n(['status' => 'failed', 'reason' => 'ippanel_rejected']);

        $this->assertFalse($this->relay()->sendOtp('09142223343', '483920'));
    }

    public function test_an_http_error_is_a_failure(): void
    {
        $this->fakeN8n(['message' => 'Error in workflow'], 500);

        $this->assertFalse($this->relay()->sendOtp('09142223343', '483920'));
    }

    /**
     * 🔴 هر شکستی باید در `sms:last_error` بنشیند.
     *
     * `/system/sms-status` عمومی است و تنها پنجره‌ای است که بی‌ورود به پنل
     * می‌گوید چرا پیامک نرفت. بی‌این، عیب‌یابی به حدس‌زدن برمی‌گردد — همان
     * چیزی که یک شبانه‌روز طول کشید.
     */
    public function test_a_failure_is_visible_in_the_public_status_route(): void
    {
        Cache::forget('sms:last_error');
        $this->fakeN8n(['status' => 'ignored', 'reason' => 'unknown_template']);

        $this->relay()->sendOtp('09142223343', '483920');

        $err = Cache::get('sms:last_error');

        $this->assertIsArray($err, 'شکست در وضعیتِ عمومی دیده نمی‌شود');
        $this->assertSame('n8n-relay', $err['driver']);
        $this->assertStringContainsString('unknown_template', $err['reason']);
    }

    /** ⚠️ و نباید راز را در پیامِ خطا لو بدهد — آن روت عمومی است */
    public function test_the_recorded_error_leaks_no_secret(): void
    {
        Cache::forget('sms:last_error');
        $this->fakeN8n(['status' => 'ignored', 'reason' => 'bad_signature'], 200);

        $this->relay()->sendOtp('09142223343', '483920');

        $this->assertStringNotContainsString(self::SECRET,
            json_encode(Cache::get('sms:last_error'), JSON_UNESCAPED_UNICODE));
    }

    // ═══════════════ پیکربندی ═══════════════

    public function test_a_half_configured_relay_stays_disabled(): void
    {
        $this->assertFalse((new N8nRelaySender(null, self::SECRET))->enabled());
        $this->assertFalse((new N8nRelaySender(self::URL, null))->enabled());
        $this->assertTrue($this->relay()->enabled());
    }

    /**
     * 🔴 نشانیِ بدونِ TLS رد می‌شود.
     *
     * پاکت شمارهٔ موبایل و **کدِ ورود** دارد. روی http هر واسطی می‌خواندش —
     * و امضا فقط جلوی جعل را می‌گیرد، نه خواندن. یک اشتباهِ تایپی در `.env`
     * نباید بی‌صدا کلِ حفاظت را بردارد.
     */
    public function test_a_plain_http_url_is_refused(): void
    {
        $this->assertFalse(
            (new N8nRelaySender('http://flow.example.test/webhook/x', self::SECRET))->enabled(),
            'نشانیِ http پذیرفته شد — کدِ ورودِ مشتری روی سیمِ باز می‌رود'
        );
    }

    // ═══════════════ یکپارچگی ═══════════════

    /**
     * 🔴 دو حامل باید **دقیقاً همان پاکت** را ببرند.
     *
     * اگر یکی‌شان شکلِ پاکت را عوض کند و دیگری نه، سوییچ‌کردنِ `SMS_DRIVER`
     * بی‌هیچ خطایی به `bad_signature` می‌خورد. برای همین منطق در
     * `SignedRelaySender` مشترک است و این تست نگه‌اش می‌دارد.
     */
    public function test_both_carriers_produce_an_identical_envelope(): void
    {
        $shape = function (string $envelope): array {
            $rest = substr($envelope, strlen('SMS_RELAY_V1:'));
            $b64 = substr($rest, 0, strrpos($rest, '.'));
            $p = json_decode(base64_decode(strtr($b64, '-_', '+/')), true);

            // ⚠️ request_id و issued_at عمداً بیرون‌اند: هر بار متفاوت‌اند
            unset($p['request_id'], $p['issued_at']);

            return $p;
        };

        $this->fakeN8n();
        $this->relay()->sendOtp('09142223343', '483920');
        $direct = $shape($this->captured()['data']['envelope']);

        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        (new BaleRelaySender('BOT', '-100', self::SECRET, 'https://tapi.example.test'))
            ->sendOtp('09142223343', '483920');

        $bale = null;
        Http::assertSent(function ($r) use (&$bale, $shape) {
            $bale = $shape($r->data()['text']);

            return true;
        });

        $this->assertSame($direct, $bale, 'دو حامل پاکت‌های متفاوت می‌سازند');
    }

    /**
     * پایانِ زنجیره: رجیستری واقعاً این درایور را برمی‌دارد.
     *
     * تنها تستی که خودِ `match` در `AppServiceProvider` را می‌دواند. بی‌این،
     * یک مسیرِ غلطِ config یعنی سقوطِ بی‌صدا به `log` — همان باگی که ۲۴ ساعت
     * رله را خاموش نگه داشت.
     */
    public function test_the_driver_registry_resolves_the_direct_relay(): void
    {
        config([
            'services.sms.driver'            => 'n8n_relay',
            'services.sms.n8n_relay.url'     => self::URL,
            'services.sms.n8n_relay.secret'  => self::SECRET,
        ]);

        $this->app->forgetInstance(SmsSender::class);

        $this->assertSame('n8n-relay', app(SmsSender::class)->name());
    }

    /** و `SmsDispatcher` بی‌هیچ تغییری از همین مسیر می‌رود */
    public function test_the_existing_dispatcher_routes_through_it_unchanged(): void
    {
        $this->fakeN8n();

        (new SmsDispatcher($this->relay()))
            ->event('09142223343', 'service_ready', ['service' => 'LX-2', 'ip' => '1.2.3.4'], 'متنِ پشتیبان');

        $envelope = $this->captured()['data']['envelope'];
        $rest = substr($envelope, strlen('SMS_RELAY_V1:'));
        $p = json_decode(base64_decode(strtr(substr($rest, 0, strrpos($rest, '.')), '-_', '+/')), true);

        $this->assertSame('service_ready', $p['template']);
        $this->assertSame(['service' => 'LX-2', 'ip' => '1.2.3.4'], $p['params']);
    }

    /** ⚠️ متنِ آزاد عمداً پشتیبانی نمی‌شود — به هیچ الگویی نمی‌خورد */
    public function test_free_text_is_refused(): void
    {
        $this->fakeN8n();

        $this->assertFalse($this->relay()->send('09142223343', 'سلام'));

        Http::assertNothingSent();
    }

    /** شمارهٔ نامعتبر پیش از هر تماسِ شبکه‌ای رد می‌شود */
    public function test_an_invalid_mobile_never_reaches_the_network(): void
    {
        $this->fakeN8n();

        $this->assertFalse($this->relay()->sendOtp('12345', '483920'));

        Http::assertNothingSent();
    }

    /** ارقامِ فارسی باید **تبدیل** شوند، نه حذف */
    public function test_persian_digits_in_the_mobile_are_converted(): void
    {
        $this->fakeN8n();

        $this->assertTrue($this->relay()->sendOtp('۰۹۱۴۲۲۲۳۳۴۳', '483920'));

        $envelope = $this->captured()['data']['envelope'];
        $rest = substr($envelope, strlen('SMS_RELAY_V1:'));
        $p = json_decode(base64_decode(strtr(substr($rest, 0, strrpos($rest, '.')), '-_', '+/')), true);

        $this->assertSame('+989142223343', $p['mobile']);
    }
}
