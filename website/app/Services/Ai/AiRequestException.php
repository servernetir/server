<?php

namespace App\Services\Ai;

/** ردِ درخواست پیش از هر پولی — کدِ پایدار + وضعیتِ HTTP */
final class AiRequestException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}
