<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * فرستندهٔ توسعه: پیامک را در لاگ می‌نویسد.
 *
 * تا وقتی ارائه‌دهندهٔ واقعی تنظیم نشده، این جای آن می‌نشیند تا جریان ثبت‌نام
 * قابل تست باشد. عمداً true برمی‌گرداند — چون از دید کد بالادست پیام
 * «فرستاده شده» است و رفتار در توسعه و تولید یکسان می‌ماند.
 */
class LogSmsSender implements SmsSender
{
    public function enabled(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'log';
    }

    public function send(string $mobile, string $text): bool
    {
        Log::channel(config('services.sms.log_channel', 'stack'))
            ->info('SMS (درایور لاگ)', ['to' => $mobile, 'text' => $text]);

        return true;
    }

    public function sendOtp(string $mobile, string $code): bool
    {
        return $this->send($mobile, "کد ورود سرورنت: {$code}");
    }
}
