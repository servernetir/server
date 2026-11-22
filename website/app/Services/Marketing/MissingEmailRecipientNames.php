<?php

namespace App\Services\Marketing;

use DomainException;

final class MissingEmailRecipientNames extends DomainException
{
    public function __construct(public readonly int $count)
    {
        parent::__construct('Recipient names are required by this email template.');
    }
}
