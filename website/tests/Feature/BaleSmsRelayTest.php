<?php

namespace Tests\Feature;

use App\Services\Sms\BaleRelaySender;
use App\Services\Sms\SmsDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * رلهٔ پیامک از راهِ بله.
 *
 * مسیر: پروژه → رباتِ فرستنده → گروهِ خصوصی → رباتِ گیرنده → n8n → آی‌پی‌پنل.
 * لازم است چون آی‌پی‌پنل به آی‌پیِ آلمان سرویس نمی‌دهد.
 */
class BaleSmsRelayTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-shared-secret';

    private function relay(): BaleRelaySender
    {
        return new BaleRelaySender('BOT123', '-100999', self::SECRET, 'https://tapi.example.test');
    }

    private function fakeBale(bool $ok = true): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => $ok, 'result' => ['message_id' => 7]], 200)]);
    }

    /** متنِ پیامِ بله را بردار و به بدنه و امضا بشکن */
    private function captured(): array
    {
        $text = null;

        Http::assertSent(function ($r) use (&$text) {
            $text = $r->data()['text'] ?? null;

            return true;
        });

        $this->assertNotNull($text, 'هیچ پیامی به بله نرفت');
        $this->assertStringStartsWith('SMS_RELAY_V1:', $text);

        [$b64, $sig] = explode('.', substr($text, strlen('SMS_RELAY_V1:')), 2);

        return [
            'b64'     => $b64,
            'sig'     => $sig,
            'payload' => json_decode(base64_decode(strtr($b64, '-_', '+/')), true),
        ];
    }

    // ═══════════════ قراردادِ سیم ═══════════════

    public function test_the_payload_has_the_agreed_shape(): void
    {
        $this->fakeBale();
        $this->relay()->sendOtp('09121234567', '482731');

        $p = $this->captured()['payload'];

        $this->assertSame(1, $p['version']);
        $this->assertSame('otp', $p['template']);
        $this->assertSame('+989121234567', $p['mobile']);
        $this->assertSame(['code' => '482731'], $p['params']);
        $this->assertMatchesRegularExpression('~^[0-9a-f-]{36}$~', $p['request_id']);
        $this->assertIsInt($p['issued_at']);
    }

    /** 🔴 امضا روی رشتهٔ Base64 است، نه روی JSON خام */
    public function test_the_signature_is_over_the_base64_string(): void
    {
        $this->fakeBale();
        $this->relay()->sendOtp('09121234567', '111111');

        $c = $this->captured();

        $this->assertSame(hash_hmac('sha256', $c['b64'], self::SECRET), $c['sig']);
    }

    /**
     * 🔴 کدِ واقعیِ الگو هرگز از این‌جا نمی‌رود.
     *
     * ترجمهٔ `otp` به `nn36pa6fq5qt` کارِ n8n است. نگه‌داشتنِ فهرستِ الگو در دو
     * جا یعنی دیر یا زود یکی کهنه می‌شود و پیامک بی‌صدا نمی‌رود.
     */
    public function test_no_real_pattern_code_leaves_the_project(): void
    {
        $this->fakeBale();
        $this->relay()->sendPattern('09121234567', 'paid', ['amount' => '2,500,000']);

        $p = $this->captured()['payload'];

        $this->assertArrayNotHasKey('pattern_code', $p);
        $this->assertArrayNotHasKey('api_key', $p);
        $this->assertSame('paid', $p['template']);
    }

    /** ⚠️ و رازِ مشترک هرگز داخلِ بدنه نمی‌نشیند — فقط امضا می‌سازد */
    public function test_the_secret_never_appears_in_the_message(): void
    {
        $this->fakeBale();
        $this->relay()->sendOtp('09121234567', '222222');

        Http::assertSent(fn ($r) => ! str_contains($r->data()['text'] ?? '', self::SECRET));
    }

    // ═══════════════ شمارهٔ موبایل ═══════════════

    public static function mobiles(): array
    {
        return ['09121234567', '+989121234567', '00989121234567', '989121234567', '۰۹۱۲۱۲۳۴۵۶۷'];
    }

    /**
     * ⚠️ ارقامِ فارسی هم باید کار کنند.
     *
     * `preg_replace('/\D+/')` تنها، ارقامِ فارسی را **پاک** می‌کند نه تبدیل —
     * یعنی شمارهٔ کاملاً درستِ کاربر به رشتهٔ خالی می‌رسید و پیامک بی‌صدا
     * نمی‌رفت. کاربرِ فارسی‌زبان معمولاً با صفحه‌کلیدِ فارسی تایپ می‌کند.
     */
    public function test_every_iranian_mobile_format_normalises(): void
    {
        foreach (self::mobiles() as $input) {
            $this->fakeBale();
            $this->relay()->sendOtp($input, '333333');

            $this->assertSame('+989121234567', $this->captured()['payload']['mobile'],
                'قالبِ «'.$input.'» درست نرمال نشد');
        }
    }

    public function test_a_non_iranian_number_is_refused_without_calling_bale(): void
    {
        $this->fakeBale();

        $this->assertFalse($this->relay()->sendOtp('+14155551234', '444444'));

        Http::assertNothingSent();
    }

    // ═══════════════ محافظ‌ها ═══════════════

    /** الگوی ناشناخته `null` می‌دهد تا دیسپچر سراغِ متنِ آزاد برود */
    public function test_an_unknown_template_is_not_sent(): void
    {
        $this->fakeBale();

        $this->assertNull($this->relay()->sendPattern('09121234567', 'not_a_template', ['x' => '1']));

        Http::assertNothingSent();
    }

    /** بدونِ پیکربندی، هیچ تماسی زده نمی‌شود */
    public function test_an_unconfigured_relay_is_inert(): void
    {
        $this->fakeBale();
        $relay = new BaleRelaySender(null, null, null);

        $this->assertFalse($relay->enabled());
        $this->assertNull($relay->sendPattern('09121234567', 'otp', ['code' => '1']));

        Http::assertNothingSent();
    }

    /**
     * 🔴 بله روی خطا هم ۲۰۰ می‌دهد؛ نتیجهٔ واقعی در `ok` بدنه است.
     *
     * تکیه بر کدِ HTTP یعنی هر پیامِ ردشده «موفق» شمرده می‌شود.
     */
    public function test_a_rejected_message_is_reported_as_failure(): void
    {
        $this->fakeBale(ok: false);

        $this->assertFalse($this->relay()->sendOtp('09121234567', '555555'));
    }

    /**
     * ⚠️ متنِ آزاد عمداً پشتیبانی نمی‌شود.
     *
     * n8n متنِ آزاد را به هیچ الگویی نمی‌تواند نگاشت کند و اپراتورِ ایرانی هم
     * پیامِ آزاد را ساعت‌ها در صف نگه می‌دارد. `false` صادقانه‌تر از فرستادنِ
     * چیزی است که نمی‌رسد — و ایمیل و بله جدا فرستاده می‌شوند.
     */
    public function test_free_text_is_refused_by_design(): void
    {
        $this->fakeBale();

        $this->assertFalse($this->relay()->send('09121234567', 'یک متنِ آزاد'));

        Http::assertNothingSent();
    }

    // ═══════════════ جفت‌شدن با کدِ موجود ═══════════════

    /**
     * 🔴 مهم‌ترین تستِ یکپارچگی.
     *
     * چون رله یک **درایور** است، هیچ فراخوانی در کد عوض نشد. این تست ثابت
     * می‌کند `SmsDispatcher` — که ده‌ها نقطهٔ پروژه از آن استفاده می‌کنند —
     * بی‌هیچ تغییری از همین مسیر می‌رود.
     */
    public function test_the_existing_dispatcher_routes_through_the_relay_unchanged(): void
    {
        $this->fakeBale();

        (new SmsDispatcher($this->relay()))
            ->event('09121234567', 'service_ready', ['service' => 'LX-2', 'ip' => '1.2.3.4'], 'متنِ پشتیبان');

        $p = $this->captured()['payload'];

        $this->assertSame('service_ready', $p['template']);
        $this->assertSame(['service' => 'LX-2', 'ip' => '1.2.3.4'], $p['params']);
    }

    /** فهرستِ الگوهای مجاز باید با پیکربندیِ آی‌پی‌پنل بخواند */
    public function test_the_template_list_matches_the_ippanel_patterns(): void
    {
        $configured = array_keys((array) config('services.sms.ippanel.patterns', []));

        $this->assertSame(
            array_values(array_diff($configured, BaleRelaySender::TEMPLATES)), [],
            'الگویی در config هست که رله نمی‌شناسدش — آن پیامک بی‌صدا نمی‌رود'
        );
    }

    // ═══════════════ سیم‌کشیِ واقعیِ config (نه ست‌کردنِ دستی) ═══════════════

    /**
     * 🔴 باگی که ۲۴ ساعت رله را روی سرور خاموش نگه داشت.
     *
     * بلوکِ `bale_relay` در `config/services.php` کنارِ `ippanel` و `kavenegar`
     * **داخلِ** آرایهٔ `sms` نشست، ولی `AppServiceProvider` مسیرِ سطحِ بالای
     * `services.bale_relay` را می‌خواند. نتیجه: `.env` درست، `env()` درست،
     * `config()` **خالی** ⇒ `enabled()` کاذب ⇒ سقوطِ بی‌صدا به `LogSmsSender`.
     * سایت می‌گفت پیامک فرستادم و هیچ پیامکی نمی‌رفت.
     *
     * ⚠️ چرا هیچ تستی نگرفتش: همهٔ تست‌ها مقدار را با `config([...])` دستی ست
     * می‌کردند، و `config()` هر مسیری را که نام ببری **می‌سازد**. پس تست مسیرِ
     * غلط را خودش به‌وجود می‌آورد و سبز می‌شد. این تست عمداً چیزی ست نمی‌کند و
     * فقط فایلِ واقعی را می‌سنجد.
     */
    public function test_the_relay_block_sits_where_the_provider_looks_for_it(): void
    {
        $block = config('services.sms.bale_relay');

        $this->assertIsArray($block,
            'بلوکِ رله در مسیری که AppServiceProvider می‌خوانَد نیست');

        foreach (['bot_token', 'chat_id', 'secret', 'base'] as $k) {
            $this->assertArrayHasKey($k, $block, "کلیدِ {$k} در بلوکِ رله نیست");
        }

        $this->assertNull(config('services.bale_relay'),
            'بلوک در دو جا تعریف شده — یکی از آن دو دیر یا زود کهنه می‌شود');
    }

    /**
     * 🔴 پایانِ زنجیره: با پیکربندیِ درست، رجیستری واقعاً رله را برمی‌دارد.
     *
     * این تنها تستی است که خودِ `match` در `AppServiceProvider` را اجرا می‌کند.
     * بی‌این، هر بار مسیرِ config در آن‌جا عوض/غلط شود، درایور بی‌صدا به `log`
     * برمی‌گردد و تنها نشانه‌اش پیامکی است که هرگز نمی‌رسد.
     */
    public function test_the_driver_registry_actually_resolves_the_relay(): void
    {
        config([
            'services.sms.driver'               => 'bale_relay',
            'services.sms.bale_relay.bot_token' => 'BOT123',
            'services.sms.bale_relay.chat_id'   => '-100999',
            'services.sms.bale_relay.secret'    => self::SECRET,
        ]);

        // singletonِ از پیش ساخته‌شده را دور بریز تا match دوباره بدود
        $this->app->forgetInstance(\App\Services\Sms\SmsSender::class);

        $this->assertSame('bale-relay', app(\App\Services\Sms\SmsSender::class)->name(),
            'درایورِ فعال رله نیست — پیامک بی‌صدا فقط در لاگ می‌نشیند');
    }

    /** و نیم‌پیکربندی باید **صادقانه** به لاگ برگردد، نه وانمود کند کار می‌کند */
    public function test_a_half_configured_relay_falls_back_to_log(): void
    {
        config([
            'services.sms.driver'               => 'bale_relay',
            'services.sms.bale_relay.bot_token' => 'BOT123',
            'services.sms.bale_relay.chat_id'   => null,   // جامانده
            'services.sms.bale_relay.secret'    => self::SECRET,
        ]);

        $this->app->forgetInstance(\App\Services\Sms\SmsSender::class);

        $this->assertSame('log', app(\App\Services\Sms\SmsSender::class)->name());
    }
}
