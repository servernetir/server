<?php

namespace App\Services\Sms;

use App\Models\SmsOutbox;

/**
 * فرستنده‌ای که نمی‌فرستد — در صف می‌گذارد.
 *
 * لازم است چون آی‌پی‌پنل به آی‌پی آلمان سرویس نمی‌دهد و سرور ایران هم
 * اتصال ورودی از آلمان را نمی‌پذیرد. تنها مسیر باقی‌مانده این است که سرور
 * ایران خودش بیاید و صف را خالی کند.
 *
 * ═══ true برگرداندن یعنی «پذیرفته شد»، نه «رسید» ═══
 *
 * از دید کاربر فرقی ندارد: قبلاً هم «تحویل به ارائه‌دهنده» تضمین رسیدن به
 * گوشی نبود. ولی در کد باید صریح باشد، چون اگر جایی روی «فرستاده شد» حساب
 * باز شود، اشتباه است.
 *
 * ═══ عمر پیام ═══
 *
 * هر پیام تاریخ انقضا دارد. کد یک‌بارمصرف سه دقیقه اعتبار دارد؛ فرستادنش
 * پنج دقیقه بعد یعنی کاربر کدِ مرده می‌گیرد و فقط گیج می‌شود. پیام منقضی
 * اصلاً فرستاده نمی‌شود.
 */
class QueuedSmsSender implements SmsSender, SupportsPatterns
{
    /** @param array<string,string> $patterns نام رویداد → کد الگو */
    public function __construct(
        private array $patterns = [],
        private string $variable = 'code',
        /** عمر پیام‌های معمولی؛ کد یک‌بارمصرف کوتاه‌تر است */
        private int $ttlMinutes = 30,
    ) {}

    public function enabled(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'queue';
    }

    public function send(string $mobile, string $text): bool
    {
        return $this->queue($mobile, null, null, $text, $this->ttlMinutes);
    }

    public function sendOtp(string $mobile, string $code): bool
    {
        // چهار دقیقه: کمی بیش از سه دقیقه اعتبار کد، تا تأخیر شبکه
        // پیام را بی‌جهت دور نیندازد — ولی نه آن‌قدر که کد مرده برسد
        return $this->queue(
            $mobile,
            $this->patterns['otp'] ?? null ? 'otp' : null,
            [$this->variable => $code],
            "کد ورود سرورنت: {$code}",
            4,
        );
    }

    public function sendPattern(string $mobile, string $event, array $values): ?bool
    {
        if (blank($this->patterns[$event] ?? null)) {
            return null;
        }

        return $this->queue($mobile, $event, $values, null, $this->ttlMinutes);
    }

    public function hasPattern(string $event): bool
    {
        return filled($this->patterns[$event] ?? null);
    }

    private function queue(string $mobile, ?string $event, ?array $params, ?string $body, int $ttl): bool
    {
        SmsOutbox::create([
            'destination' => $mobile,
            'event'       => $event,
            'params'      => $params,
            'body'        => $body,
            'status'      => 'queued',
            'expires_at'  => now()->addMinutes($ttl),
        ]);

        return true;
    }
}
