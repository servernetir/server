<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class HetznerApiService implements ResellerApiInterface
{
    protected $baseUrl = 'https://api.hetzner.cloud/v1';
    protected $token;

    public function __construct()
    {
        $this->token = config('HETZNER_API_TOKEN'); 
    }

    public function createServer(array $params)
    {
        $response = Http::withToken($this->token)
            ->post($this->baseUrl . '/servers', $params);

        return $response->json();
    }

    public function getServerStatus($serverId)
    {
        $response = Http::withToken($this->token)
            ->get($this->baseUrl . "/servers/{$serverId}");

        return $response->json();
    }

    public function deleteServer($serverId)
    {
        $response = Http::withToken($this->token)
            ->delete($this->baseUrl . "/servers/{$serverId}");

        return $response->json();
    }
}