<?php

namespace App\Support;

use Illuminate\Http\Request;

class DeploymentToken
{
    public static function fromRequest(Request $request): string
    {
        return (string) ($request->bearerToken() ?: $request->post('token', ''));
    }
}
