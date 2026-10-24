<?php

namespace App\Services\Dns;

/**
 * نتیجهٔ یک اجرای انتشار روی primary.
 *
 * ⚠️ `ok=true` فقط یعنی primary پذیرفت؛ «منتشر شد» برای مشتری فقط وقتی است که هر
 * دو نیم‌سرور serialِ همین انتشار را داشته باشند (NodeVerifier) — طراحی §4.3.
 */
final class PublishResult
{
    /**
     * @param  string  $code  published|unchanged|deleted|suspended|locked|not_configured|failed|skipped
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly ?int $version = null,
        public readonly ?int $serial = null,
        public readonly ?string $error = null,
        public readonly int $changes = 0,
    ) {}

    public static function fail(string $code, ?string $error = null): self
    {
        return new self(false, $code, null, null, $error);
    }
}
