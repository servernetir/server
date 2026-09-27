<?php

namespace App\Services\AiProviders;

/**
 * نتیجهٔ موفقِ درایور — بدنهٔ خامِ ارائه‌دهنده، بی‌تفسیرِ پولی.
 * `raw` برای خواندنِ دقیقِ `estimated_cost` است (JSON آن را float می‌کرد).
 */
final class DriverCallResult
{
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly string $raw = '',
        public readonly ?string $requestId = null,
    ) {}
}
