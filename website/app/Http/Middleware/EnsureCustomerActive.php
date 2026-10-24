<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Check existing sessions as well as new logins, without logging staff out. */
class EnsureCustomerActive
{
    public const SESSION_KEY = 'customer_session_version';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('customer');
        $customer = $guard->user();

        if (! $customer) {
            return $next($request);
        }

        // Fetch current state even when another guard has already loaded this model.
        $customer->refresh();
        $active = $customer->isActive();
        $version = (int) $request->session()->get(self::SESSION_KEY, 0);

        if ($active && $version === (int) $customer->session_version) {
            return $next($request);
        }

        $guard->logout();
        $request->session()->forget([self::SESSION_KEY, 'login_otp', 'login_2fa', 'impersonator_id']);
        // Preserve a staff session in the same browser (support impersonation).
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        $message = __($active ? 'ui.auth_session_revoked' : 'ui.auth_account_blocked');

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'error' => $active ? 'session_revoked' : 'account_inactive',
                'message' => $message,
            ], 403);
        }

        $prefix = \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';

        return redirect()->route($prefix.'login')->withErrors(['identifier' => $message]);
    }
}
