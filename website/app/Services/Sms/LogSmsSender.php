<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * فرستندهٔ توسعه: پیامک را در لاگ می‌نویسد.
 *
 * فقط در محیط توسعه و آزمون فعال است.
 */
class LogSmsSender implements SmsSender
{
    public function enabled(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    public function name(): string
    {
        return 'log';
    }

    public function send(string $mobile, string $text): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        Log::channel(config('services.sms.log_channel', 'stack'))
            ->info('SMS (درایور لاگ)', ['to' => $mobile, 'text' => $text]);

        return true;
    }

    public function sendOtp(string $mobile, string $code): bool
    {
        return $this->send($mobile, "کد ورود سرورنت: {$code}");
    }
}
