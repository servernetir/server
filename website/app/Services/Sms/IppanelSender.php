<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * آی‌پی‌پنل (ippanel.com) — Edge API.
 *
 * ═══ چیزهایی که در مستندات هست ولی راحت از قلم می‌افتد ═══
 *
 * ۱) هدر احراز هویت «Authorization: <token>» است — **بدون** پیشوند Bearer.
 *    اگر Bearer بگذارید ۴۰۱ می‌گیرید و پیام خطا هم نمی‌گوید چرا.
 *
 * ۲) شماره‌ها باید E.164 باشند: +989121234567. شکل داخلی ما 09121234567
 *    است، پس اینجا تبدیل می‌شود. خط فرستنده هم همین قاعده را دارد (+983000…).
 *
 * ۳) هر دو حالت روی یک مسیر می‌روند (POST /api/send) و فقط sending_type فرق
 *    می‌کند — ولی جای گیرنده‌ها یکی نیست:
 *        webservice → params.recipients  (آرایه، چندتایی مجاز)
 *        pattern    → recipients          (آرایه، فقط یک نفر)
 *    این ناهمگونی در خود API است، نه اشتباه تایپی.
 *
 * ۴) پاسخ موفق meta.status === true دارد. مثل بقیهٔ سرویس‌های ایرانی، به کد
 *    HTTP تنها اکتفا نمی‌کنیم و بدنه را می‌خوانیم.
 *
 * ═══ چرا پترن برای کد ورود ═══
 *
 * پیام تبلیغاتی/آزاد ممکن است دقایقی در صف اپراتور بماند. کد ورود ما ۳ دقیقه
 * اعتبار دارد؛ با مسیر آزاد، کاربر کدِ منقضی دریافت می‌کند. پترن مسیر خدماتی
 * است و بلافاصله می‌رسد.
 */
class IppanelSender implements SmsSender, SupportsPatterns
{
    private const BASE = 'https://edge.ippanel.com/v1';

    public function __construct(
        private ?string $token,
        private ?string $fromNumber,
        /** نام رویداد → کد الگو، مثلاً ['otp' => 'ab12', 'invoice' => 'cd34'] */
        private array $patterns = [],
        /** نام متغیر پیش‌فرض داخل الگو — در پنل آی‌پی‌پنل تعریف می‌شود */
        private string $variable = 'code',
    ) {}

    public function enabled(): bool
    {
        return filled($this->token) && filled($this->fromNumber);
    }

    public function name(): string
    {
        return 'ippanel';
    }

    public function send(string $mobile, string $text): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $this->post([
            'sending_type' => 'webservice',
            'from_number'  => $this->e164($this->fromNumber),
            'message'      => $text,
            'params'       => ['recipients' => [$this->e164($mobile)]],
        ]);
    }

    public function sendOtp(string $mobile, string $code): bool
    {
        return $this->sendPattern($mobile, 'otp', [$this->variable => $code])
            ?? $this->send($mobile, "کد ورود سرورنت: {$code}");
    }

    /**
     * ارسال با الگو.
     *
     * null یعنی «الگویی برای این رویداد تعریف نشده» — که با false (یعنی
     * «تلاش کردیم و نشد») فرق دارد. کد بالادست از این تفاوت برای تصمیم به
     * برگشت به پیام آزاد استفاده می‌کند.
     *
     * @param  array<string,string|int>  $values  متغیرهای داخل الگو
     */
    public function sendPattern(string $mobile, string $event, array $values): ?bool
    {
        $pattern = $this->patterns[$event] ?? null;

        if (! $this->enabled() || blank($pattern)) {
            return null;
        }

        return $this->post([
            'sending_type' => 'pattern',
            'from_number'  => $this->e164($this->fromNumber),
            'code'         => $pattern,
            'recipients'   => [$this->e164($mobile)],   // اینجا بیرون params است
            'params'       => array_map(strval(...), $values),
        ]);
    }

    /** آیا برای این رویداد الگو تعریف شده؟ */
    public function hasPattern(string $event): bool
    {
        return filled($this->patterns[$event] ?? null);
    }

    private function post(array $body): bool
    {
        try {
            $res = Http::withHeaders([
                    // بدون Bearer — عمدی
                    'Authorization' => (string) $this->token,
                    'Accept'        => 'application/json',
                ])
                ->timeout(15)
                ->asJson()
                ->post(self::BASE.'/api/send', $body);
        } catch (\Throwable $e) {
            Log::warning('آی‌پی‌پنل در دسترس نبود', ['error' => $e->getMessage()]);

            return false;
        }

        $json = $res->json();

        if (data_get($json, 'meta.status') === true) {
            return true;
        }

        Log::warning('آی‌پی‌پنل پیامک را رد کرد', [
            'http'    => $res->status(),
            'code'    => data_get($json, 'meta.message_code'),
            'message' => data_get($json, 'meta.message') ?: $res->body(),
        ]);

        return false;
    }

    /** 09121234567 → +989121234567 ; شماره‌ای که از قبل +98 دارد دست‌نخورده می‌ماند */
    private function e164(string $number): string
    {
        $d = preg_replace('/[^0-9]/', '', $number) ?? '';

        return match (true) {
            str_starts_with($d, '0098') => '+'.substr($d, 2),
            str_starts_with($d, '98')   => '+'.$d,
            str_starts_with($d, '0')    => '+98'.substr($d, 1),
            default                     => '+98'.$d,
        };
    }

    /**
     * پیام قابل خواندن برای خطاهای رایج — در دستور تست استفاده می‌شود.
     * کدها از مستندات Edge: 400-1 توکن، 400-2 ورودی نامعتبر.
     */
    public static function explain(?string $messageCode): ?string
    {
        return match ($messageCode) {
            '400-1' => 'توکن نامعتبر یا منقضی است (IPPANEL_KEY را بررسی کنید)',
            '400-2' => 'ورودی نامعتبر است — معمولاً خط فرستنده یا کد الگو اشتباه است',
            default => null,
        };
    }
}
