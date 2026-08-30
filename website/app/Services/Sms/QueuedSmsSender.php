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
 * ═══ اینجا هیچ کد الگویی نمی‌داند ═══
 *
 * انتخاب الگو کار سرور ایران است، نه اینجا. این کلاس فقط می‌گوید «این یک
 * پیام از نوع otp است با code=123456»؛ اینکه با کدام الگو و با چه نام
 * متغیری فرستاده شود، آن‌طرف تصمیم می‌گیرد.
 *
 * چرا: الگوها در پنل آی‌پی‌پنل ساخته می‌شوند، ممکن است چند تا باشند و هر
 * بار یکی‌شان استفاده شود. اگر فهرستشان اینجا هم بود، یک تنظیم در دو جا
 * می‌ماند و دیر یا زود یکی کهنه می‌شد — و نتیجه‌اش پیامکی است که بی‌صدا
 * نمی‌رود.
 *
 * ═══ true برگرداندن یعنی «پذیرفته شد»، نه «رسید» ═══
 *
 * از دید کاربر فرقی ندارد: قبلاً هم «تحویل به ارائه‌دهنده» تضمین رسیدن به
 * گوشی نبود. ولی در کد باید صریح باشد.
 *
 * ═══ عمر پیام ═══
 *
 * هر پیام تاریخ انقضا دارد. کد یک‌بارمصرف سه دقیقه اعتبار دارد؛ فرستادنش
 * پنج دقیقه بعد یعنی کاربر کدِ مرده می‌گیرد و فقط گیج می‌شود.
 */
class QueuedSmsSender implements SmsSender, SupportsPatterns
{
    public function __construct(
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
        // چهار دقیقه: کمی بیش از سه دقیقه اعتبار کد، تا تأخیر شبکه پیام را
        // بی‌جهت دور نیندازد — ولی نه آن‌قدر که کد مرده برسد.
        //
        // متن پشتیبان هم همراهش می‌رود: اگر آن‌طرف الگویی برای otp تعریف
        // نشده باشد، پیام آزاد فرستاده می‌شود و کاربر بی‌کد نمی‌ماند.
        return $this->queue($mobile, 'otp', ['code' => $code], "کد ورود سرورنت: {$code}", 4);
    }

    /**
     * همیشه صف می‌شود.
     *
     * برخلاف درایور مستقیم، اینجا null برنمی‌گردانیم: نمی‌دانیم آن‌طرف
     * الگویی برای این رویداد دارد یا نه، و حدس زدنش یعنی پیام‌هایی که
     * می‌توانستند بروند، نروند. تصمیم به فرستندهٔ ایران واگذار می‌شود که
     * واقعاً می‌داند.
     */
    public function sendPattern(string $mobile, string $event, array $values): ?bool
    {
        return $this->queue($mobile, $event, $values, null, $this->ttlMinutes);
    }

    public function hasPattern(string $event): bool
    {
        // از اینجا قابل دانستن نیست؛ true یعنی «بفرست، آن‌طرف می‌داند»
        return true;
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
