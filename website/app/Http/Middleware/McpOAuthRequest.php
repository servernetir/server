<?php

namespace App\Http\Middleware;

use App\Services\Mcp\AdminAccess;
use Closure;
use Illuminate\Http\Request;

/** Bind every grant to this resource; require S256 even for confidential clients. */
final class McpOAuthRequest
{
    public function handle(Request $request, Closure $next, string $mode = 'authorize')
    {
        if (! AdminAccess::enabled()) {
            return response()->json(['error' => 'temporarily_unavailable'], 503, ['Cache-Control' => 'no-store']);
        }
        if ($request->input('resource') !== config('mcp_admin.resource')) {
            return response()->json(['error' => 'invalid_target'], 400, ['Cache-Control' => 'no-store']);
        }
        if ($mode === 'authorize') {
            $scope = $request->input('scope');
            $challenge = $request->input('code_challenge');
            if (! is_string($scope) || ! is_string($challenge)
                || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $challenge) !== 1
                || $request->input('code_challenge_method') !== 'S256'
                || $request->input('response_type') !== 'code') {
                return response()->json(['error' => 'invalid_request'], 400);
            }
            $scopes = preg_split('/ +/', trim($scope));
            if (! in_array(AdminAccess::READ, $scopes, true)
                || array_diff($scopes, array_keys(AdminAccess::scopes()))) {
                return response()->json(['error' => 'invalid_scope'], 400);
            }
            // Always show the exact client, callback and scopes before a new grant.
            $request->merge(['prompt' => 'consent']);
            // Passport validates the immutable PSR query separately.
            $request->query->set('prompt', 'consent');
        } elseif (! in_array($request->input('grant_type'), ['authorization_code', 'refresh_token'], true)) {
            return response()->json(['error' => 'unsupported_grant_type'], 400);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
