<?php

namespace App\Services\Ai;

/** بدنهٔ آمادهٔ ارسال + سقف‌های رزرو */
final class AiPreparedRequest
{
    /** @param list<string> $dropped */
    public function __construct(
        public readonly array $forward,
        public readonly int $maxInput,
        public readonly int $maxOutput,
        public readonly int $outputCap,
        public readonly bool $clientOmittedMaxTokens,
        public readonly array $dropped,
        public readonly string $sha256,
    ) {}

    /** بدنه‌ای که واقعاً به بالادست می‌رود — `max_tokens` همیشه اجباری */
    public function body(): array
    {
        return $this->forward + ['max_tokens' => $this->maxOutput];
    }

    public function withOutput(int $output): self
    {
        return new self($this->forward, $this->maxInput, $output, $this->outputCap,
            $this->clientOmittedMaxTokens, $this->dropped, $this->sha256);
    }
}
