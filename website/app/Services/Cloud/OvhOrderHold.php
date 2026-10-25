<?php

namespace App\Services\Cloud;

class OvhOrderHold extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly bool $transient = false)
    { parent::__construct($message); }
}
