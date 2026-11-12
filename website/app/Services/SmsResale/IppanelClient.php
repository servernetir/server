<?php

namespace App\Services\SmsResale;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class IppanelClient
{
    private const BASE = 'https://edge.ippanel.com/v1';

    public function __construct(private Controls $controls) {}

    private function request(string $method, string $path, array $data = []): mixed
    {
        if (blank($key = $this->controls->token())) { throw new ProviderError('not_configured', true); }
        try {
            $http = Http::withHeaders(['Authorization' => $key])->acceptJson()->asJson()
                ->connectTimeout(5)->timeout(20)->withoutRedirecting();
            $response = $method === 'GET' ? $http->get(self::BASE.$path, $data) : $http->post(self::BASE.$path, $data);
        } catch (ConnectionException) { throw new ProviderError('unknown'); }
        if (in_array($response->status(), [401, 403], true)) { throw new ProviderError('access_denied', true); }
        if ($response->status() === 422) { throw new ProviderError('identity_rejected', true); }
        if (! $response->successful() || $response->json('meta.status') !== true) {
            throw new ProviderError('unknown');
        }
        return $response->json('data');
    }

    public function users(string $username): array
    {
        $rows = $this->request('GET', '/api/user/list', ['username' => $username, 'sub_reseller' => 0, 'per_page' => 100]);
        if (! is_array($rows) || ! array_is_list($rows)) { throw new ProviderError('invalid_response'); }
        return array_values(array_filter($rows, fn ($r) => is_array($r) && ($r['uname'] ?? null) === $username));
    }

    public function show(int $id): array
    {
        $user = $this->request('GET', '/api/user/show', ['user_id' => $id]);
        if (! is_array($user) || (int) ($user['user_id'] ?? 0) !== $id) { throw new ProviderError('invalid_response'); }
        return $user;
    }

    public function create(array $data): void { $this->request('POST', '/api/user/create', $data); }
    public function update(int $id, array $data): void { $this->request('POST', '/api/user/update', ['user_id' => $id] + $data); }
    public function packages(): array { return (array) $this->request('GET', '/api/acl/package/list', ['per_page' => 1000]); }
    public function credit(): mixed { return $this->request('GET', '/api/payment/credit/mine'); }
}
