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
        public readonly bool $stream = false,
        public readonly string $operation = 'chat',
        public readonly ?array $native = null,
    ) {}

    /** بدنه‌ای که واقعاً به بالادست می‌رود — `max_tokens` همیشه اجباری */
    public function body(): array
    {
        if ($this->operation !== 'chat') {
            return $this->forward;
        }
        return $this->forward + ['max_tokens' => $this->maxOutput]
            + ($this->stream ? ['stream' => true, 'stream_options' => ['include_usage' => true]] : []);
    }

    public function withOutput(int $output): self
    {
        return new self($this->forward, $this->maxInput, $output, $this->outputCap,
            $this->clientOmittedMaxTokens, $this->dropped, $this->sha256, $this->stream, $this->operation,$this->native);
    }
}
