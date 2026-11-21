<?php

namespace App\Http\Middleware;

use App\Services\Mcp\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class McpTokenAuthentication
{
    public function handle(Request $request, Closure $next)
    {
        if (! AdminAccess::enabled()) {
            return response()->json(['error' => 'temporarily_unavailable'], 503, ['Cache-Control' => 'no-store']);
        }
        if (strlen($request->getContent()) > 8192) {
            return response()->json(['error' => 'invalid_request'], 413);
        }
        if (! $request->bearerToken() || ! Auth::guard('mcp_admin')->check() || ! $this->resourceMatches($request)) {
            return response()->json(['error' => 'invalid_token'], 401, [
                'WWW-Authenticate' => 'Bearer resource_metadata="'.config('mcp_admin.origin').'/.well-known/oauth-protected-resource/api/mcp/admin"',
                'Cache-Control' => 'no-store',
            ]);
        }
        Auth::shouldUse('mcp_admin');
        return $next($request);
    }

    private function resourceMatches(Request $request): bool
    {
        // Passport has already checked signature, expiry, revocation and client.
        // This additional check rejects tokens minted for another resource.
        try {
            $token = (new \Lcobucci\JWT\Token\Parser(new \Lcobucci\JWT\Encoding\JoseEncoder()))
                ->parse($request->bearerToken());
            return $token instanceof \Lcobucci\JWT\UnencryptedToken
                && $token->claims()->get('iss') === config('mcp_admin.origin')
                && in_array(config('mcp_admin.resource'), $token->claims()->get('aud', []), true);
        } catch (\Throwable) {
            return false;
        }
    }
}
