<?php

namespace App\Http\Middleware;

use App\Services\IdentityApi\IdentityError;
use App\Support\ErrorTracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class IdentityPrivacy
{
    public static function sensitive(Request $request): bool
    {
        return $request->is('api/v1/identity*', 'api/internal/urmia-auth*', 'account/identity-api*', 'en/account/identity-api*', 'tr/account/identity-api*',
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
            $status = $e instanceof IdentityError ? $e->httpStatus : ($e instanceof ValidationException ? 422 : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : 503));
            $code = $e instanceof IdentityError ? $e->errorCode : ($e instanceof ValidationException || $status < 500 ? ($status === 429 ? 'quota_exceeded' : 'invalid_input') : 'temporarily_unavailable');
            if ($status === 503) {
                ErrorTracker::noteOnce('identity_api', 'Inquiry request requires review', 300, ['exception_class' => $e::class]);
            }
            $headers = $e instanceof HttpExceptionInterface ? array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'])) : [];
            $response = response()->json(['ok' => false, 'error' => $code, 'message' => __('identity.error_'.$code)], $status, $headers);
        }

        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag','noindex, nofollow');
    }
}
