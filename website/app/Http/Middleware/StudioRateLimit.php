<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** All Studio customers share backend egress; the normal quota must be per key. */
final class StudioRateLimit
{
    public function handle(Request $request, Closure $next)
    {
        $ipKey = 'studio-ip|'.hash('sha256', (string) $request->ip());
        $bearer = (string) $request->bearerToken();
        $key = 'studio-key|'.hash('sha256', $bearer !== '' ? $bearer : (string) $request->ip());
        if (RateLimiter::tooManyAttempts($ipKey, 1000) || RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json(['code'=>'rate_limited', 'message'=>'Studio request limit exceeded.'], 429,
                ['Retry-After'=>(string) max(1, RateLimiter::availableIn($key), RateLimiter::availableIn($ipKey)),
                 'Cache-Control'=>'no-store']);
        }
        RateLimiter::hit($ipKey, 60);
        RateLimiter::hit($key, 60);
        return $next($request);
    }
}
