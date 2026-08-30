<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * کاوه‌نگار.
 *
 * دو نکته که در مستندات کم‌رنگ‌اند و اینجا صریح شده‌اند:
 *
 * ۱) کاوه‌نگار روی خطا هم HTTP 200 می‌دهد؛ وضعیت واقعی در `return.status`
 *    بدنه است. پس مثل زحل، کد HTTP معیار نیست.
 * ۲) برای کد یک‌بارمصرف باید از «لوکاپ» (verify/lookup) استفاده شود نه ارسال
 *    ساده — اپراتورها پیامک تبلیغاتی را دیر می‌رسانند و کد OTP منقضی می‌شود.
 */
class KavenegarSender implements SmsSender
{
    public function __construct(
        private ?string $apiKey,
        private ?string $template,
        private ?string $sender = null,
    ) {}

    public function enabled(): bool
    {
        return filled($this->apiKey);
    }

    public function name(): string
    {
        return 'kavenegar';
    }

    public function send(string $mobile, string $text): bool
    {
        return $this->enabled() && $this->plain($mobile, $text);
    }

    public function sendOtp(string $mobile, string $code): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return filled($this->template)
            ? $this->lookup($mobile, $code)
            : $this->plain($mobile, "کد ورود سرورنت: {$code}");
    }

    private function lookup(string $mobile, string $code): bool
    {
        return $this->call('verify/lookup.json', [
            'receptor' => $mobile,
            'token'    => $code,
            'template' => $this->template,
        ]);
    }

    private function plain(string $mobile, string $text): bool
    {
        return $this->call('sms/send.json', array_filter([
            'receptor' => $mobile,
            'message'  => $text,
            'sender'   => $this->sender,
        ]));
    }

    private function call(string $path, array $params): bool
    {
        try {
            $res = Http::timeout(12)->asForm()->post(
                'https://api.kavenegar.com/v1/'.$this->apiKey.'/'.$path,
                $params,
            );
        } catch (\Throwable $e) {
            Log::warning('کاوه‌نگار در دسترس نبود', ['error' => $e->getMessage()]);

            return false;
        }

        // وضعیت واقعی در بدنه است، نه در کد HTTP
        $status = (int) data_get($res->json(), 'return.status');

        if ($status !== 200) {
            Log::warning('کاوه‌نگار پیامک را رد کرد', [
                'status'  => $status,
                'message' => data_get($res->json(), 'return.message'),
            ]);

            return false;
        }

        return true;
    }
}
