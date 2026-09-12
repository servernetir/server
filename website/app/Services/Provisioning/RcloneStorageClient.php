<?php

namespace App\Services\Provisioning;

use App\Models\Server;
use Illuminate\Support\Facades\Http;

/**
 * کلاینتِ Gateway فضای بکاپ مدیریت‌شده.
 *
 * حساب‌های Google/rclone فقط روی Gateway شناخته می‌شوند. کنترل‌پنل هیچ OAuth
 * token یا رمزِ Driveای ندارد و فقط درخواست‌های کوتاهِ HMACشدهٔ tenant را
 * می‌فرستد. بنابراین تعویض backend، پنل و سرویس‌های مشتری را تغییر نمی‌دهد.
 */
class RcloneStorageClient
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
            return $this->fail('آدرس یا کلیدِ Gateway ثبت نشده است.');
        }

        $method = strtoupper($method);
        $path = '/'.ltrim($path, '/');
        $body = $payload === [] ? '' : (json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $signature = hash_hmac('sha256', $method."\n".$path."\n".$ts."\n".$nonce."\n".$body, (string) $this->server->api_token);

        try {
            $request = Http::acceptJson()
                ->connectTimeout(8)
                ->timeout($method === 'GET' ? 15 : 45)
                ->retry(1, 300, throw: false)
                ->withOptions(['verify' => (bool) $this->server->verify_tls])
                ->withHeaders([
                    'X-ServerNet-Timestamp' => $ts,
                    'X-ServerNet-Nonce' => $nonce,
                    'X-ServerNet-Signature' => $signature,
                ]);

            $response = $body !== ''
                ? $request->withBody($body, 'application/json')->send($method, $this->baseUrl().$path)
                : $request->send($method, $this->baseUrl().$path);
        } catch (\Throwable $e) {
            return $this->fail('ارتباط با Gateway برقرار نشد: '.mb_substr($e->getMessage(), 0, 150), true);
        }

        $json = $response->json();
        if (! is_array($json)) {
            return $this->fail('پاسخ نامعتبر از Gateway (HTTP '.$response->status().')', false, $response->status());
        }

        if (! $response->successful()) {
            return $this->fail((string) ($json['error'] ?? $json['message'] ?? 'خطای Gateway'), false, $response->status(), $json);
        }

        return ['ok' => true, 'transport' => false, 'status' => $response->status(), 'reason' => '', 'data' => $json];
    }

    /** آرایه=هست، false=نیست، null=پاسخ قطعی نگرفتیم. */
    public function tenantState(string $tenantId): array|false|null
    {
        $r = $this->call('GET', '/v1/tenants/'.rawurlencode($tenantId));

        if ($r['ok']) {
            return is_array($r['data']['tenant'] ?? null) ? $r['data']['tenant'] : null;
        }

        if ($r['status'] === 404) {
            return false;
        }

        return null;
    }

    public function createTenant(string $tenantId, array $spec): array
    {
        return $this->call('PUT', '/v1/tenants/'.rawurlencode($tenantId), $spec);
    }

    public function setSuspended(string $tenantId, bool $suspended): array
    {
        return $this->call('POST', '/v1/tenants/'.rawurlencode($tenantId).($suspended ? '/suspend' : '/unsuspend'));
    }

    /** بازیابیِ قطعیِ دسترسی وقتی ساختِ قبلی کامل شده ولی رمز در پنل نمانده است. */
    public function rotatePassword(string $tenantId, string $password): array
    {
        return $this->call('POST', '/v1/tenants/'.rawurlencode($tenantId).'/credentials', [
            'password' => $password,
        ]);
    }

    /** حذف فوری نیست؛ Gateway tenant را تا پایان مهلت بازیابی قرنطینه می‌کند. */
    public function retireTenant(string $tenantId, int $retentionDays): array
    {
        return $this->call('DELETE', '/v1/tenants/'.rawurlencode($tenantId), ['retention_days' => $retentionDays]);
    }

    public function testConnection(): array
    {
        $r = $this->call('GET', '/v1/health');
        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['reason']];
        }

        $free = isset($r['data']['free_bytes']) ? number_format((int) $r['data']['free_bytes'] / 1024 ** 3, 1).' GB آزاد' : 'ظرفیت نامشخص';

        return ['ok' => true, 'message' => 'Gateway آماده است — '.$free];
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

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = $parts['port'] ?? $this->server->port;

        return $scheme.'://'.$parts['host'].($port ? ':'.(int) $port : '');
    }

    private function fail(string $reason, bool $transport = false, int $status = 0, array $data = []): array
    {
        return compact('transport', 'status', 'reason', 'data') + ['ok' => false];
    }
}
