<?php

namespace App\Http\Middleware;

use App\Services\Cloud\Engine\EngineApiError;
use App\Support\ErrorTracker;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Keep framework debug pages and raw infrastructure exceptions outside the API. */
class CloudEnginePrivacy
{
    public function handle(Request $request, Closure $next)
    {
        try {
            if (strlen($request->getContent()) > 16384) {
                throw new EngineApiError('payload_too_large', 413);
            }
            $response = $next($request);
        } catch (\Throwable $e) {
            $response = self::failure($e);
        }

        return $response->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public static function failure(\Throwable $e)
    {
        [$code, $status] = match (true) {
            $e instanceof EngineApiError => [$e->publicCode, $e->httpStatus],
            $e instanceof ValidationException => ['invalid_input', 422],
            $e instanceof ModelNotFoundException => ['not_found', 404],
            $e instanceof HttpExceptionInterface => [match ($e->getStatusCode()) {
                404 => 'not_found', 429 => 'rate_limited', 403 => 'forbidden', default => 'invalid_request',
            }, $e->getStatusCode()],
            default => ['temporarily_unavailable', 503],
        };
        if ($status >= 500) {
            ErrorTracker::noteOnce('cloud_engine_api', 'Server API operation requires review', 300, ['exception_class' => $e::class]);
        }
        $headers = $e instanceof HttpExceptionInterface
            ? array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'])) : [];

        return response()->json(['ok' => false, 'error' => $code], $status, $headers);
    }
}
