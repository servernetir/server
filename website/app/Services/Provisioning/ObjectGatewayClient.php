<?php

namespace App\Services\Provisioning;

use App\Models\Server;
use Illuminate\Support\Facades\Http;

/** Signed control-plane client; customer S3 traffic never passes through Laravel. */
class ObjectGatewayClient
{
    public function __construct(private Server $server) {}

    public function isConfigured(): bool
    {
        return filled($this->server->hostname) && filled($this->server->api_token);
    }

    /** @return array{ok:bool,transport:bool,status:int,reason:string,data:array} */
    public function call(string $method, string $path, array $payload = []): array
    {
        if (! $this->isConfigured()) {
            return $this->fail('آدرس یا کلید کنترل Gateway ثبت نشده است.');
        }

        $method = strtoupper($method);
        $path = '/'.ltrim($path, '/');
        $body = $payload === [] ? '' : (json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $signature = hash_hmac('sha256', $method."\n".$path."\n".$timestamp."\n".$nonce."\n".$body, (string) $this->server->api_token);

        try {
            $request = Http::acceptJson()->connectTimeout(8)->timeout($method === 'GET' ? 20 : 90)
                ->retry(1, 350, throw: false)
                ->withOptions(['verify' => (bool) $this->server->verify_tls])
                ->withHeaders([
                    'X-ServerNet-Timestamp' => $timestamp,
                    'X-ServerNet-Nonce' => $nonce,
                    'X-ServerNet-Signature' => $signature,
                ]);
            $response = $body !== ''
                ? $request->withBody($body, 'application/json')->send($method, $this->baseUrl().$path)
                : $request->send($method, $this->baseUrl().$path);
        } catch (\Throwable $exception) {
            return $this->fail('ارتباط با Gateway برقرار نشد: '.mb_substr($exception->getMessage(), 0, 150), true);
        }

        $data = $response->json();
        if (! is_array($data)) {
            return $this->fail('پاسخ نامعتبر Gateway (HTTP '.$response->status().')', false, $response->status());
        }
        if (! $response->successful()) {
            return $this->fail((string) ($data['error'] ?? $data['message'] ?? 'خطای Gateway'), false, $response->status(), $data);
        }

        return ['ok' => true, 'transport' => false, 'status' => $response->status(), 'reason' => '', 'data' => $data];
    }

    public function tenantState(string $tenantId): array|false|null
    {
        $result = $this->call('GET', '/v1/tenants/'.rawurlencode($tenantId));
        if ($result['ok']) {
            return is_array($result['data']['tenant'] ?? null) ? $result['data']['tenant'] : null;
        }

        return $result['status'] === 404 ? false : null;
    }

    public function createTenant(string $tenantId, array $spec): array
    {
        return $this->call('PUT', '/v1/tenants/'.rawurlencode($tenantId), $spec);
    }

    public function setSuspended(string $tenantId, bool $suspended): array
    {
        return $this->call('POST', '/v1/tenants/'.rawurlencode($tenantId).($suspended ? '/suspend' : '/unsuspend'));
    }

    public function rotateCredentials(string $tenantId): array
    {
        return $this->call('POST', '/v1/tenants/'.rawurlencode($tenantId).'/credentials');
    }

    public function retireTenant(string $tenantId, int $retentionDays): array
    {
        return $this->call('DELETE', '/v1/tenants/'.rawurlencode($tenantId), ['retention_days' => $retentionDays]);
    }

    public function testConnection(): array
    {
        $result = $this->call('GET', '/v1/health');
        if (! $result['ok'] || ($result['data']['ready'] ?? false) !== true) {
            return ['ok' => false, 'message' => $result['reason'] ?: 'Gateway هنوز آماده نیست.'];
        }

        return ['ok' => true, 'message' => 'Object Storage Gateway آماده است.'];
    }

    public function gatewayStatus(): ?array
    {
        $result = $this->call('GET', '/v1/status');

        return $result['ok'] && is_array($result['data']) ? $result['data'] : null;
    }

    private function baseUrl(): string
    {
        $host = trim((string) $this->server->hostname);
        if (! preg_match('~^https?://~i', $host)) {
            $host = 'https://'.$host;
        }
        $parts = parse_url($host);
        if (! is_array($parts) || blank($parts['host'] ?? null)) {
            return rtrim($host, '/');
        }
        $port = $parts['port'] ?? $this->server->port;

        return strtolower((string) ($parts['scheme'] ?? 'https')).'://'.$parts['host'].($port ? ':'.(int) $port : '');
    }

    private function fail(string $reason, bool $transport = false, int $status = 0, array $data = []): array
    {
        return compact('transport', 'status', 'reason', 'data') + ['ok' => false];
    }
}
