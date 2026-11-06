<?php

namespace App\Services\Domain\Providers;

/** Safe classification only: never include a raw reply, credential or URL. */
class DomainProviderException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $uncertain = false)
    {
        parent::__construct($reason);
    }
}
