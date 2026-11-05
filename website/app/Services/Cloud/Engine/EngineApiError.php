<?php

namespace App\Services\Cloud\Engine;

use App\Http\Middleware\CloudEnginePrivacy;

class EngineApiError extends \RuntimeException
{
    public function __construct(public readonly string $publicCode, public readonly int $httpStatus = 409)
    {
        parent::__construct($publicCode);
    }

    public function render()
    {
        return CloudEnginePrivacy::failure($this);
    }
}
