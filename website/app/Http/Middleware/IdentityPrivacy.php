<?php

namespace App\Http\Middleware;

use App\Services\IdentityApi\IdentityError;
use App\Support\ErrorTracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IdentityPrivacy
{
    public static function sensitive(Request $request): bool
    {
        return $request->is('api/v1/identity*', 'account/identity-api*', 'en/account/identity-api*', 'tr/account/identity-api*',
            'admin/identity-api*', 'en/admin/identity-api*', 'tr/admin/identity-api*');
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            if (strlen($request->getContent()) > config('identity_api.max_payload_bytes', 16384)) {
                throw new IdentityError('payload_too_large', 413);
            }
            if ($request->is('api/v1/identity/*') && $request->query->count()) {
                throw new IdentityError('query_not_allowed', 422);
            }
            $response = $next($request);
        } catch (\Throwable $e) {
            $code = $e instanceof IdentityError ? $e->errorCode : ($e instanceof ValidationException ? 'invalid_input' : 'temporarily_unavailable');
            $status = $e instanceof IdentityError ? $e->httpStatus : ($e instanceof ValidationException ? 422 : 503);
            if ($status === 503) {
                ErrorTracker::noteOnce('identity_api', 'Inquiry request requires review', 300, ['exception_class' => $e::class]);
            }
            $response = response()->json(['ok' => false, 'error' => $code, 'message' => __('identity.error_'.$code)], $status);
        }

        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag','noindex, nofollow');
    }
}
