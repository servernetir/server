<?php

namespace App\Services\Domain\Pricing;

/** A safe internal reason; never carries a supplier response or contact data. */
class DomainProcurementException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
