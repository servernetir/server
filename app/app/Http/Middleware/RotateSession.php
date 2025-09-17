<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RotateSession
{
    //هر 15 دقیقه شناسه سشن را عوض میکنیم تا سرقت سشن سخت تر شود. (Session Rotation)
    public function handle(Request $request, Closure $next)
    {
        $rotatedAt = session('rotated_at');

        if (! $rotatedAt || now()->diffInMinutes($rotatedAt) >= 15) {
            if (auth()->check()) {
                $request->session()->migrate(true); // regenerate + destroy old
                session(['rotated_at' => now()]);
            }
        }

        return $next($request);
    }
}
