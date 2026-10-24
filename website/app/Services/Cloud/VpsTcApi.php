<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Independent, read-only VPS.TC reseller API client. No service mutations. */
final class VpsTcApi
{
    private string $origin;
    private string $username;
    private string $password;
    private ?string $token = null;
    private ?string $refresh = null;

    public function __construct(?string $origin = null, ?string $username = null, ?string $password = null)
    {
        // Never combine saved credentials with another configured host/account.
        if ($origin !== null || $username !== null || $password !== null) {
            $tuple = [$origin ?? '', $username ?? '', $password ?? ''];
        } else {
            $savedOrigin = (string) Setting::get('cloud_vpstc_api_origin', '');
            $tuple = trim($savedOrigin) !== ''
                ? [$savedOrigin, (string) Setting::getSecret('cloud_vpstc_username'), (string) Setting::getSecret('cloud_vpstc_password')]
                : [(string) config('services.vpstc.base_url', ''), (string) config('services.vpstc.username', ''), (string) config('services.vpstc.password', '')];
        }
        $rawOrigin = trim($tuple[0]);
        $this->origin = self::validOrigin($rawOrigin) ? 'https://'.strtolower((string) parse_url($rawOrigin, PHP_URL_HOST)) : $rawOrigin;
        $this->username = trim($tuple[1]);
        $this->password = $tuple[2];
    }

    public static function validOrigin(string $origin): bool
    {
        $origin = trim($origin);
        if (! preg_match('~\Ahttps://([^/?#]+?)/?\z~iD', $origin, $match)) {
            return false;
        }
        $host = strtolower($match[1]);
        if (strlen($host) > 253 || ! preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*(?:vps\.tc|whitelabelservices\.us)\z/D', $host)) {
            return false;
        }
        $parts = parse_url($origin);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === $host
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['port'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true);
    }

    public function isConfigured(): bool
    {
        return self::validOrigin($this->origin) && $this->username !== '' && $this->password !== '';
    }

    public function balance(): array
    {
        return $this->read('/api/balance', 'details');
    }

    public function categories(): array
    {
        return $this->read('/api/category', 'categories');
    }

    public function invoices(): array
    {
        return $this->read('/api/invoice', 'invoices');
    }

    public function services(): array
    {
        return $this->read('/api/service', 'services');
    }

    public function products(string $categoryId): array
    {
        return $this->withIds([$categoryId], fn () => $this->read('/api/category/'.$categoryId.'/product', 'products'));
    }

    public function productOrderForm(string $productId): array
    {
        return $this->withIds([$productId], fn () => $this->read('/api/order/'.$productId, 'product'));
    }

    public function service(string $serviceId): array
    {
        return $this->withIds([$serviceId], fn () => $this->read('/api/service/'.$serviceId, 'service'));
    }

    public function templates(string $serviceId): array
    {
        return $this->withIds([$serviceId], fn () => $this->read('/api/service/'.$serviceId.'/templates', 'templates'));
    }

    public function vms(string $serviceId): array
    {
        return $this->withIds([$serviceId], fn () => $this->read('/api/service/'.$serviceId.'/vms', 'vms'));
    }

    public function vm(string $serviceId, string $vmId): array
    {
        return $this->withIds([$serviceId, $vmId], fn () => $this->read('/api/service/'.$serviceId.'/vms/'.$vmId, 'vm'));
    }

    private function withIds(array $ids, callable $operation): array
    {
        foreach ($ids as $id) {
            if (! preg_match('/\A[1-9][0-9]{0,17}\z/D', $id)) {
                return $this->failure('invalid_id', 'A valid provider resource identifier is required.');
            }
        }

        return $operation();
    }

    private function read(string $path, string $expectedKey): array
    {
        if (! $this->isConfigured()) {
            return $this->failure('not_configured', 'VPS.TC credentials and an approved HTTPS API origin are required.');
        }
        if ($this->token === null && ! $this->authenticate(false)) {
            return $this->failure('authentication_failed', 'VPS.TC authentication failed. Check the reseller credentials and API origin.');
        }
        try {
            $response = $this->request('GET', $path, null, true);
            // Only one recovery per read. No blind retries or mutation endpoints.
            if ($response->status() === 401) {
                if (! $this->authenticate(true)) {
                    return $this->failure('authentication_failed', 'VPS.TC authentication could not be renewed.');
                }
                $response = $this->request('GET', $path, null, true);
            }
            if ($response->status() >= 300 && $response->status() < 400) {
                return $this->failure('redirect_rejected', 'VPS.TC returned a redirect. Verify the API origin.');
            }
            if ($response->status() === 401 || $response->status() === 403) {
                return $this->failure('access_denied', 'The VPS.TC reseller account cannot access this resource.');
            }
            if ($response->status() === 429) {
                return $this->failure('rate_limited', 'VPS.TC is rate limiting requests. Try again later.');
            }
            if (! $response->successful()) {
                return $this->failure('provider_error', 'VPS.TC could not complete the read-only request.');
            }
            $data = $this->decode($response);
            if ($data === null || ! array_key_exists($expectedKey, $data) || ! is_array($data[$expectedKey]) || $this->hasApiError($data)) {
                return $this->failure('invalid_response', 'VPS.TC returned an unsupported response.');
            }

            return ['ok' => true, 'data' => $data, 'message' => '', 'code' => 'ok'];
        } catch (Throwable) {
            return $this->failure('connection_failed', 'The VPS.TC API could not be reached securely.');
        }
    }

    private function authenticate(bool $renew): bool
    {
        try {
            $useRefresh = $renew && $this->refresh !== null;
            $response = $this->request(
                'POST',
                $useRefresh ? '/api/token' : '/api/login',
                $useRefresh ? ['refresh_token' => $this->refresh] : ['username' => $this->username, 'password' => $this->password],
                false
            );
            if (! $response->successful()) {
                $this->token = null;

                return false;
            }
            $data = $this->decode($response);
            if ($data === null || $this->hasApiError($data) || ! isset($data['token']) || ! is_string($data['token']) || trim($data['token']) === '' || preg_match('/[\r\n]/', $data['token'])) {
                $this->token = null;

                return false;
            }
            $this->token = $data['token'];
            $this->refresh = isset($data['refresh']) && is_string($data['refresh']) && $data['refresh'] !== '' ? $data['refresh'] : null;

            return true;
        } catch (Throwable) {
            $this->token = null;

            return false;
        }
    }

    private function request(string $method, string $path, ?array $payload, bool $authenticated): Response
    {
        $request = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(20)
            ->withOptions(['verify' => true, 'allow_redirects' => false]);
        if ($authenticated) {
            $request = $request->withToken($this->token);
        }

        return $method === 'POST'
            ? $request->post($this->origin.$path, $payload ?? [])
            : $request->get($this->origin.$path);
    }

    private function decode(Response $response): ?array
    {
        $body = trim($response->body());
        if ($body === '' || strlen($body) > 2000000 || $body[0] !== '{') {
            return null;
        }
        try {
            $data = json_decode($body, true, 128, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function hasApiError(array $data): bool
    {
        if ((array_key_exists('success', $data) && $data['success'] === false)
            || (array_key_exists('status', $data) && $data['status'] === false)) {
            return true;
        }

        return array_key_exists('error', $data)
            && ! in_array($data['error'], [null, false, '', [], 0], true);
    }

    private function failure(string $code, string $message): array
    {
        return ['ok' => false, 'data' => [], 'message' => $message, 'code' => $code];
    }
}
