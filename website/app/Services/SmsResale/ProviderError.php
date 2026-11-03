<?php

namespace App\Services\SmsResale;

class ProviderError extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $definite = false)
    {
        parent::__construct('SMS operation '.$reason); // Never carry upstream body, identity or secrets.
    }
}
