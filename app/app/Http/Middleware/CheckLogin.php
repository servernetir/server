<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckLogin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            // اگر درخواست از نوع API / AJAX باشه
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthorized. Please login first.'
                ], 401);
            }

            // اگر درخواست معمولی وب باشه → بره به صفحه لاگین
            return redirect()->route('login');
        }

        return $next($request);
    }
}