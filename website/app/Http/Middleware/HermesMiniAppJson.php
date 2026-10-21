<?php

namespace App\Http\Middleware;

use App\Services\Bale\Admin\HermesMiniAppAuth;
use App\Services\Hermes\HermesMiniAppErrors;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Signed owner API, independent of browser cookies. Applied before route throttles. */
final class HermesMiniAppJson
{
    public function __construct(private HermesMiniAppAuth $auth, private HermesMiniAppErrors $errors) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $requestId = HermesMiniAppErrors::requestId($request);
        $operation = HermesMiniAppErrors::operation('/'.$request->path());
        try {
            $owner = $this->auth->owner($request);
            if (! $owner) {
                return $this->failure('owner_unauthorized', $operation, 403, $requestId);
            }
            $request->attributes->set('hermes_miniapp_owner', $owner);
            if ($request->isMethod('POST') && (! $request->isJson() || strlen($request->getContent()) > 32768)) {
                return $this->failure('validation_failed', $operation, 422, $requestId);
            }
            if ($request->isMethod('POST')) {
                try {
                    $input = json_decode($request->getContent(), false, 16, JSON_THROW_ON_ERROR);
                    if (! is_object($input)) {
                        return $this->failure('validation_failed', $operation, 422, $requestId);
                    }
                } catch (\JsonException) {
                    return $this->failure('validation_failed', $operation, 422, $requestId);
                }
            }
            $response = $next($request);
            if (! $response instanceof JsonResponse) {
                $status = $response->getStatusCode();
                return $this->failure($status === 429 ? 'throttled' : 'internal_error', $operation, $status === 429 ? 429 : 503, $requestId);
            }
            $status = $response->getStatusCode();
            $data = $response->getData(true);
            if (! is_array($data) || array_is_list($data) || ($data['ok'] ?? null) !== true || $status >= 400) {
                // Laravel's route pipeline may render throttle/validation exceptions before this middleware catches them.
                // Never pass that default body (or its raw exception message) to the browser.
                $fallback = match ($status) {
                    404, 405 => 'request_not_found', 429 => 'throttled', 401, 403 => 'owner_unauthorized', 400, 415, 422 => 'validation_failed', default => 'internal_error',
                };
                $code = is_string($data['code'] ?? null) && HermesMiniAppErrors::knownCode($data['code']) ? $data['code'] : $fallback;
                return $this->failure($code, $operation, $status >= 400 && $status <= 599 ? $status : 503, $requestId);
            }
            $data['request_id'] = $requestId;
            $response->setData($data);
            return $this->headers($response, $requestId);
        } catch (Throwable $error) {
            $status = method_exists($error, 'getStatusCode') ? (int) $error->getStatusCode() : 503;
            $code = match ($status) {
                404, 405 => 'request_not_found', 429 => 'throttled', 401, 403 => 'owner_unauthorized', 422 => 'validation_failed', default => 'internal_error',
            };
            return $this->failure($code, $operation, in_array($status, [401, 403, 404, 405, 422, 429], true) ? $status : 503, $requestId);
        }
    }

    private function failure(string $code, string $operation, int $status, string $requestId): JsonResponse
    {
        $this->errors->record($code, $operation, $status, 'application/json', $requestId);
        return $this->headers(response()->json(HermesMiniAppErrors::failure($code, $requestId, $status), $status), $requestId);
    }

    private function headers(JsonResponse $response, string $requestId): JsonResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Request-Id', $requestId);
        return $response;
    }
}
