<?php

namespace App\Http\Middleware;

use App\Services\Hermes\HermesMiniAppErrors;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Observe existing API outcomes without changing authentication, response content or delivery. */
final class HermesMiniAppAudit
{
    public function __construct(private HermesMiniAppErrors $errors) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $error) {
            try {
                $status = method_exists($error, 'getStatusCode') ? (int) $error->getStatusCode()
                    : (is_a($error, 'Illuminate\Validation\ValidationException') ? 422 : 503);
            } catch (Throwable) {
                $status = 503;
            }
            $this->record($request, $this->code('', $status), $status, 'application/json');
            throw $error;
        }
        try {
            if ($response instanceof JsonResponse) {
                $data = $response->getData(true);
                if (is_array($data)) {
                    $status = $response->getStatusCode();
                    $type = (string) $response->headers->get('Content-Type', 'application/json');
                    $code = is_string($data['code'] ?? null) ? $data['code'] : '';
                    if ($status >= 400 || ($data['ok'] ?? null) === false) {
                        $this->record($request, $this->code($code, $status), $status, $type);
                    } elseif (($data['ok'] ?? null) === true) {
                        $this->observeDelivery($request, $data, $status, $type);
                    }
                }
            }
        } catch (Throwable) {
            // Monitoring availability must never affect the existing owner API.
        }
        return $response;
    }

    private function observeDelivery(Request $request, array $data, int $status, string $type): void
    {
        $recorded = false;
        $messages = is_array($data['state']['messages'] ?? null) ? $data['state']['messages'] : [];
        foreach (array_slice($messages, -10) as $message) {
            if (! is_array($message) || ($message['role'] ?? '') !== 'user'
                || ! in_array($message['status'] ?? '', ['uncertain', 'failed'], true)) {
                continue;
            }
            $id = $message['id'] ?? null;
            if (! is_string($id) || ! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id)) {
                continue;
            }
            $recorded = true;
            if ($this->firstIncident('message:'.strtolower($id))) {
                $this->record($request, 'agent_failed', $status, $type);
            }
        }
        // Some endpoints return a delivery result without a state message. Coalesce those safely as well.
        if (! $recorded && in_array($data['delivery'] ?? $data['status'] ?? '', ['uncertain', 'failed'], true)
            && $this->firstIncident('delivery:'.$request->path())) {
            $this->record($request, 'agent_failed', $status, $type);
        }
    }

    private function firstIncident(string $identifier): bool
    {
        try {
            // Only a digest enters the cache; raw message identifiers and chat text are never persisted here.
            return Cache::add('hermes-miniapp-incident:'.hash('sha256', $identifier), true, 86400) !== false;
        } catch (Throwable) {
            return true;
        }
    }

    private function code(string $code, int $status): string
    {
        if (HermesMiniAppErrors::knownCode($code)) {
            return $code;
        }
        return match ($code) {
            'session_expired' => 'owner_unauthorized',
            'storage_unavailable' => 'state_unavailable',
            'empty_message', 'empty_memory', 'memory_limit' => 'validation_failed',
            'request_pending' => 'busy',
            default => match ($status) {
                401, 403 => 'owner_unauthorized', 404, 405 => 'request_not_found',
                400, 415, 422 => 'validation_failed', 429 => 'throttled', default => 'internal_error',
            },
        };
    }

    private function record(Request $request, string $code, int $status, string $type): void
    {
        try {
            $this->errors->record($code, '/'.$request->path(), max(0, min(599, $status)), $type, HermesMiniAppErrors::requestId($request));
        } catch (Throwable) {
            // Record metadata only; never replace or disclose the original response/exception.
        }
    }
}
