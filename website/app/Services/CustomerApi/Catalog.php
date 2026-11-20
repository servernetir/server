<?php

namespace App\Services\CustomerApi;

/** This catalog describes shipped routes only; planned operations stay in the roadmap. */
class Catalog
{
    public const ENDPOINTS = [
        ['GET', '/me', 'read', 'account', 'Account profile and IRT balance'],
        ['GET', '/services', 'read', 'account', 'All product services (legacy list)'],
        ['GET', '/services/{service}', 'read', 'account', 'Owned service and recorded billing terms'],
        ['GET', '/invoices', 'read', 'account', 'Latest 100 invoices'],
        ['GET', '/invoices/{invoice}', 'read', 'account', 'Invoice totals, tax and line items'],
        ['GET', '/credit', 'read', 'account', 'IRT balance (legacy response)'],
        ['GET', '/wallet', 'read', 'account', 'Ledger balances, holds and available funds by currency'],
        ['GET', '/cloud', 'cloud:read', 'cloud', 'Paginated GPU/VPS inventory; restricted to token resources'],
        ['GET', '/cloud/{service}', 'cloud:read', 'cloud', 'Stored status, billing and supported power actions'],
        ['GET', '/cloud/{service}/status', 'cloud:read', 'cloud', 'Observed status; cached for 20 seconds'],
        ['GET', '/cloud/{service}/metrics', 'cloud:read', 'cloud', 'Metrics; window=1h,24h,7d,30d; cached 2 minutes'],
        ['POST', '/cloud/{service}/actions/start', 'cloud:power', 'cloud', 'Start; accepted is not ready; hourly billing can begin'],
        ['POST', '/cloud/{service}/actions/stop', 'cloud:power', 'cloud', 'Stop; only interruptible hourly GPU pauses off-time billing'],
        ['POST', '/cloud/{service}/actions/reboot', 'cloud:power', 'cloud', 'Reboot where supported; use actions from service details'],
        ['GET', '/operations/{operation}', 'cloud:read', 'cloud', 'Durable command receipt; completed means dispatch result recorded'],
    ];

    public function openapi(): array
    {
        $paths = [];
        foreach (self::ENDPOINTS as [$method, $path, $scope, $tag, $summary]) {
            preg_match_all('/\{([a-z]+)\}/', $path, $matches);
            $parameters = array_map(fn ($name) => ['name' => $name, 'in' => 'path', 'required' => true,
                'schema' => $name === 'operation' ? ['type' => 'string', 'format' => 'uuid'] : ['type' => 'integer', 'minimum' => 1]], $matches[1]);
            if ($method === 'POST') {
                $parameters[] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true,
                    'description' => 'New UUID per intended command. Retry the SAME command with the SAME key. Retained without automatic expiry.',
                    'schema' => ['type' => 'string', 'minLength' => 16, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]+$']];
            }
            if ($path === '/cloud') {
                $parameters[] = ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]];
            }
            if (str_ends_with($path, '/metrics')) {
                $parameters[] = ['name' => 'window', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['1h', '24h', '7d', '30d'], 'default' => '24h']];
            }
            $responses = [];
            foreach ([$method === 'POST' ? 202 : 200, 401, 402, 403, 404, 409, 422, 429, 503] as $status) {
                $responses[(string) $status] = ['description' => match ($status) {
                    202 => 'Command accepted. Poll status separately; no readiness guarantee.',
                    200 => 'Success', 401 => 'Missing, expired, revoked or invalid token',
                    402 => 'Insufficient available credit', 403 => 'Scope, IP or inactive-account restriction',
                    404 => 'Resource absent or outside token ownership/resource restriction',
                    409 => 'Conflict, command pending, service inactive or reconciliation required',
                    422 => 'Invalid input or missing idempotency key', 429 => 'Rate limit exceeded',
                    default => 'Observation or command outcome unavailable; never blindly retry a new command',
                }, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]];
            }
            $paths[$path][strtolower($method)] = ['summary' => $summary, 'tags' => [$tag], 'x-required-scope' => $scope,
                'security' => [['bearerAuth' => []]], 'parameters' => $parameters, 'responses' => $responses];
        }
        return ['openapi' => '3.1.0', 'info' => ['title' => 'ServerNet Account and Cloud API', 'version' => '1.0.0',
            'description' => 'Amounts are integers in the explicit currency. IRT is toman; IRR is rial; EUR/USD/TRY use the existing invoice minor-unit convention. Cloud IRT fields are always toman. Scope cloud:power does not imply cloud:read or read. A token daily AI/domain cap does not cap ongoing cloud rental.'],
            'servers' => [['url' => 'https://console.servernet.cloud/api/v1']], 'paths' => $paths,
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
                'schemas' => ['Envelope' => ['type' => 'object', 'required' => ['ok'], 'properties' => [
                    'ok' => ['type' => 'boolean'], 'data' => [], 'error' => ['type' => 'string'],
                    'message' => ['type' => 'string'], 'operation_id' => ['type' => 'string', 'format' => 'uuid'],
                ]]]]];
    }
}
