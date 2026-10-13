<?php

namespace App\Services\AiProviders;

/**
 * شکستِ تماس با کدِ پایدار — تنها مسیرِ خطای درایور.
 * `sent` مالکِ تصمیمِ پول است: false ⇒ آزادسازی، true ⇒ بسته به وضعیت.
 */
final class AiProviderCallException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $sent = false,
        public readonly ?int $status = null,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }
}
