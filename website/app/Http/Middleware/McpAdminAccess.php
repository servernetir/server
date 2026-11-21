<?php

namespace App\Http\Middleware;

use App\Services\Mcp\AdminAccess;
use Closure;
use Illuminate\Http\Request;

final class McpAdminAccess
{
    public function handle(Request $request, Closure $next, string $mode = 'token')
    {
        if (! AdminAccess::enabled()) {
            return response()->json(['error' => 'temporarily_unavailable'], 503, ['Cache-Control' => 'no-store']);
        }
        $user = $request->user($mode === 'web' ? 'web' : 'mcp_admin');
        if (! AdminAccess::allowed($user)) {
            return response()->json(['error' => 'access_denied'], 403, ['Cache-Control' => 'no-store']);
        }
        if ($mode !== 'web' && (! $user->currentAccessToken()
            || ! $user->tokenCan(AdminAccess::READ))) {
            return response()->json(['error' => 'insufficient_scope'], 403, ['Cache-Control' => 'no-store']);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
