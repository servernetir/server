<?php

namespace Tests\Feature;

use App\Services\Bale\BaleSafirSender;
use App\Support\ErrorTracker;
use App\Support\IranianMobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * سفیرِ بله — پیام به شمارهٔ موبایل، بی‌نیاز به ورودِ کاربر به ربات.
 *
 * ═══ چرا این کانال اهمیت دارد ═══
 *
 * مسیرِ قدیمیِ بله `chat_id` می‌خواست، و `chat_id` فقط وقتی وجود داشت که کاربر
 * **خودش** وارد ربات شده و شماره‌اش را به اشتراک گذاشته باشد. یعنی برای
 * اکثریتِ مشتری‌ها کانالِ بله بی‌صدا خاموش بود. با سفیر، بله مسیرِ دومِ واقعی
 * می‌شود — همان چیزی که وقتی پیامک نمی‌رسد (فیلتر، اپراتور) تفاوتِ بینِ ورودِ
 * موفق و مشتریِ پشتِ درِ بسته است.
 */
class BaleSafirTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-safir-key';

    private const BOT = 2017652664;

    private function safir(): BaleSafirSender
    {
        return new BaleSafirSender(self::KEY, self::BOT, 'https://safir.example.test');
    }

    /** ⚠️ `Http::swap` لازم است — اولین استابِ تطبیق‌یافته برنده است */
    private function fake(array $body, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($body, $status)]);
    }

    private function captured(): array
    {
        $sent = null;

        Http::assertSent(function ($r) use (&$sent) {
            $sent = ['url' => $r->url(), 'data' => $r->data(), 'headers' => $r->headers()];

            return true;
        });

        $this->assertNotNull($sent, 'هیچ درخواستی به سفیر نرفت');

        return $sent;
    }

    // ═══════════════ قراردادِ سیم ═══════════════

    /** کد از مسیرِ **اختصاصیِ** سفیر می‌رود، نه به‌صورتِ متنِ معمولی */
    public function test_the_otp_uses_the_dedicated_endpoint_shape(): void
    {
        $this->fake(['message_id' => 'm1']);

        $this->assertTrue($this->safir()->otp('09142223343', '483920'));

        $sent = $this->captured();

        $this->assertSame('https://safir.example.test/api/v3/send_message', $sent['url']);
        $this->assertSame(self::KEY, $sent['headers']['api-access-key'][0]);
        $this->assertSame(self::BOT, $sent['data']['bot_id']);
        $this->assertSame(['otp_message' => ['otp' => '483920']], $sent['data']['message_data']);
    }

    public function test_a_normal_message_uses_the_text_shape(): void
    {
        $this->fake(['message_id' => 'm2', 'error_data' => null]);

        $this->assertTrue($this->safir()->text('09142223343', 'سرویس شما آماده شد'));

        $this->assertSame(['message' => ['text' => 'سرویس شما آماده شد']],
            $this->captured()['data']['message_data']);
    }

    /**
     * 🔴 قالبِ شماره — سفیر `09…` را با کدِ ۸ رد می‌کند.
     *
     * باید `989142223343` باشد: بی‌`+`، بی‌صفر، با کدِ کشور.
     */
    public function test_the_phone_number_is_sent_in_the_format_safir_demands(): void
    {
        foreach (['09142223343', '+989142223343', '00989142223343', '989142223343', '۰۹۱۴۲۲۲۳۳۴۳'] as $input) {
            $this->fake(['message_id' => 'x']);
            $this->safir()->otp($input, '1');

            $this->assertSame('989142223343', $this->captured()['data']['phone_number'],
                "قالبِ «{$input}» درست تبدیل نشد");
        }
    }

    /** شمارهٔ نامعتبر پیش از هر تماسِ شبکه‌ای رد می‌شود */
    public function test_an_invalid_number_never_reaches_the_network(): void
    {
        $this->fake(['message_id' => 'x']);

        $this->assertFalse($this->safir()->otp('12345', '1'));

        Http::assertNothingSent();
    }

    /** 🔴 `request_id` برای ضدِّ تکرار — بی‌آن هر retry یک پیامِ تکراری است */
    public function test_every_send_carries_an_idempotency_key(): void
    {
        $this->fake(['message_id' => 'x']);
        $this->safir()->otp('09142223343', '1');

        $this->assertMatchesRegularExpression('~^[0-9a-f-]{36}$~',
            $this->captured()['data']['request_id']);
    }

    // ═══════════════ 🔴 خواندنِ پاسخ — fail-closed ═══════════════

    /**
     * موفقیت یعنی `error_data` خالی یا نال. هر شکلِ ناشناخته‌ای **شکست** است.
     *
     * این همان درسی است که امروز دو بار گران تمام شد: یک بار پاسخِ موفق با
     * شکلِ اشتباه خوانده شد و پیامکِ رفته «شکست» گزارش شد، و یک بار هر بدنهٔ
     * ناشناختهٔ ۲۰۰ «موفق» شمرده می‌شد.
     */
    public function test_an_unknown_body_is_a_failure_not_a_success(): void
    {
        $this->fake(['unexpected' => 'shape']);

        $this->assertFalse($this->safir()->text('09142223343', 'x'));
    }

    public function test_an_http_error_is_a_failure(): void
    {
        $this->fake(['message' => 'nope'], 500);

        $this->assertFalse($this->safir()->text('09142223343', 'x'));
    }

    public function test_an_empty_error_array_is_success(): void
    {
        $this->fake(['message_id' => 'ok', 'error_data' => []]);

        $this->assertTrue($this->safir()->text('09142223343', 'x'));
    }

    // ═══════════════ خطاهای معنادار ═══════════════

    /**
     * 🔴 «کاربر بله ندارد» خطا **نیست**.
     *
     * بخشِ بزرگی از مشتری‌ها بله ندارند. اگر ثبتش کنیم، ردیابِ خطا با نویز پر
     * می‌شود و مدیر از روزِ دوم نگاهش نمی‌کند — و آن‌وقت خطاهای واقعی هم دیده
     * نمی‌شوند. هشدارِ نویزی از نبودِ هشدار بدتر است.
     */
    public function test_a_customer_without_bale_is_not_recorded_as_an_error(): void
    {
        ErrorTracker::clear();
        $this->fake(['message_id' => 'x', 'error_data' => [
            ['phone_number' => '989142223343', 'code' => 17, 'description' => 'NotBaleUser'],
        ]]);

        $this->assertFalse($this->safir()->text('09142223343', 'x'));

        $this->assertSame([], ErrorTracker::recent(20),
            'نداشتنِ حسابِ بله به‌عنوان خطا ثبت شد — ردیاب با نویز پر می‌شود');
    }

    /**
     * 🔴 ولی نبودِ اعتبار باید **بلند** گزارش شود.
     *
     * تفاوتش با بقیهٔ خطاها این است که خودبه‌خود درست نمی‌شود: تا شارژ نشود،
     * هیچ پیامی به هیچ مشتری‌ای نمی‌رود.
     */
    public function test_running_out_of_credit_is_recorded_loudly(): void
    {
        ErrorTracker::clear();
        Cache::forget('bale:safir_error');

        $this->fake(['message_id' => 'x', 'error_data' => [
            ['phone_number' => '989142223343', 'code' => 20, 'description' => 'PaymentRequired'],
        ]]);

        $this->assertFalse($this->safir()->text('09142223343', 'x'));

        $flag = Cache::get('bale:safir_error');

        $this->assertIsArray($flag, 'تمام‌شدنِ اعتبارِ سفیر هیچ‌جا دیده نمی‌شود');
        $this->assertStringContainsString('اعتبار', $flag['reason']);
        $this->assertNotSame([], ErrorTracker::recent(20));
    }

    /** ⚠️ و کلیدِ API هرگز در چیزی که ثبت می‌شود ظاهر نمی‌شود */
    public function test_the_api_key_never_leaks_into_the_error_tracker(): void
    {
        ErrorTracker::clear();
        $this->fake(['message_id' => 'x', 'error_data' => [['code' => 3, 'description' => 'RateLimitExceeded']]]);

        $this->safir()->text('09142223343', 'x');

        $this->assertStringNotContainsString(self::KEY,
            json_encode(ErrorTracker::recent(20), JSON_UNESCAPED_UNICODE));
    }

    // ═══════════════ پیکربندی ═══════════════

    public function test_a_half_configured_safir_is_inert(): void
    {
        Http::swap(new Factory);
        Http::fake();

        $this->assertFalse((new BaleSafirSender(null, self::BOT))->enabled());
        $this->assertFalse((new BaleSafirSender(self::KEY, null))->enabled());
        $this->assertFalse((new BaleSafirSender(self::KEY, 0))->enabled());
        $this->assertTrue($this->safir()->enabled());

        $this->assertFalse((new BaleSafirSender(null, null))->text('09142223343', 'x'));

        Http::assertNothingSent();
    }

    // ═══════════════ نرمال‌سازِ مشترک ═══════════════

    /**
     * ⚠️ ارقامِ فارسی **تبدیل** می‌شوند، نه حذف.
     *
     * `preg_replace('/\D+/')` تنها، «۰۹۱۲…» را به رشتهٔ خالی تبدیل می‌کند —
     * یعنی شمارهٔ کاملاً درستِ کاربری که با صفحه‌کلیدِ فارسی تایپ کرده، به
     * «نامعتبر» می‌رسد و آن کانال بی‌صدا خاموش می‌شود.
     */
    public function test_the_shared_normaliser_handles_every_real_world_format(): void
    {
        foreach (['09142223343', '+98 914 222 3343', '0098-914-222-3343', '۹۸۹۱۴۲۲۲۳۳۴۳', '٠٩١٤٢٢٢٣٣٤٣'] as $in) {
            $this->assertSame('9142223343', IranianMobile::national($in), "«{$in}» درست نشد");
            $this->assertSame('+989142223343', IranianMobile::e164($in));
            $this->assertSame('989142223343', IranianMobile::bare($in));
        }

        foreach (['', '12345', '02112345678', '+14155551234', 'salam'] as $bad) {
            $this->assertNull(IranianMobile::national($bad), "«{$bad}» نباید پذیرفته شود");
        }
    }
}
