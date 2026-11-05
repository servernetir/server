<?php

namespace App\Services\Cloud\Engine;

use App\Models\EngineNode;
use Illuminate\Support\Facades\Http;

/** No retries on mutations: a lost response does not establish that a POST failed. */
class ProxmoxTransport
{
    public function request(EngineNode $node, string $method, string $path, array $payload = []): array
    {
        $base = rtrim((string) $node->api_url, '/');
        $url = parse_url($base);
        $method = strtoupper($method);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! str_ends_with($url['path'] ?? '', '/api2/json')
            || ! in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)
            || ! str_starts_with($path, '/') || str_contains($path, '..')
            || blank($node->token_id) || blank($node->token_secret)) {
            return $this->failure('node_configuration_invalid');
        }
        $verify = $node->tls_ca_file ?: true;
        if (is_string($verify) && (! is_file($verify) || ! is_readable($verify))) {
            return $this->failure('node_ca_unavailable');
        }
        try {
            $http = Http::withOptions(['verify' => $verify, 'allow_redirects' => false])
                ->withHeaders(['Authorization' => 'PVEAPIToken='.$node->token_id.'='.$node->token_secret])
                ->acceptJson()->timeout(20)->connectTimeout(5);
            $response = match ($method) {
                'GET' => $http->get($base.$path, $payload),
                'POST' => $http->asForm()->post($base.$path, $payload),
                'PUT' => $http->asForm()->put($base.$path, $payload),
                'DELETE' => $http->delete($base.$path, $payload),
            };
            $body = $response->json();
            if (! $response->successful()) {
                return ['ok' => false, 'status' => $response->status(), 'data' => null,
                    'error' => match ($response->status()) {
                        401, 403 => 'node_access_denied', 404 => 'resource_not_found',
                        default => 'node_request_failed',
                    }, 'ambiguous' => $method !== 'GET'];
            }
            if (! is_array($body) || ! array_key_exists('data', $body)) {
                return $this->failure('node_response_invalid', $method !== 'GET');
            }
            return ['ok' => true, 'status' => $response->status(), 'data' => $body['data'],
                'error' => null, 'ambiguous' => false];
        } catch (\Throwable) {
            // Exception text can contain a credential header or private management endpoint.
            return $this->failure('node_unreachable', $method !== 'GET');
        }
    }

    private function failure(string $error, bool $ambiguous = false): array
    {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $error, 'ambiguous' => $ambiguous];
    }
}
