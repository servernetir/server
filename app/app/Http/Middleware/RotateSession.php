<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RotateSession
{
    // Rotate session every 15 minutes for extra security
    public function handle(Request $request, Closure $next)
    {
        $rotatedAt = session('rotated_at');

        // Check if the user is authenticated through WHMCS session, not Laravel Auth
        $isWhmcsAuthenticated = session()->has('whmcs_auth.client_id');

        if ($isWhmcsAuthenticated && (! $rotatedAt || now()->diffInMinutes($rotatedAt) >= 15)) {
            $request->session()->migrate(true); // regenerate + destroy old
            session(['rotated_at' => now()]);
        }

        return $next($request);
    }
}