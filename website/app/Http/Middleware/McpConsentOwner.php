<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class McpConsentOwner
{
    public function handle(Request $request, Closure $next)
    {
        $serialized = $request->session()->get('authRequest');
        abort_unless(is_string($serialized), 400);
        $auth = unserialize($serialized, ['allowed_classes' => [
            \League\OAuth2\Server\RequestTypes\AuthorizationRequest::class,
            \Laravel\Passport\Bridge\Client::class, \Laravel\Passport\Bridge\Scope::class,
            \Laravel\Passport\Bridge\User::class,
        ]]);
        abort_unless($auth instanceof \League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface
            && (string) $auth->getUser()?->getIdentifier() === (string) $request->user('web')->id, 403);
        return $next($request);
    }
}
