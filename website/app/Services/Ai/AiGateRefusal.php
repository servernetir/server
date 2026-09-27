<?php

namespace App\Services\Ai;

/** ردِ یک سد با پاسخِ آماده — فقط داخلِ `AiCaller` برای خروجِ زودهنگام از محاسبه‌ها */
final class AiGateRefusal extends \RuntimeException
{
    public function __construct(public readonly AiCallOutcome $outcome)
    {
        parent::__construct($outcome->code);
    }
}
