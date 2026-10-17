<?php

namespace App\Services\IdentityApi;

use Illuminate\Support\Facades\Http;

class Provider
{
    public function configured(): bool
    {
        return rtrim((string) config('identity_api.base_url'), '/') === 'https://service.zohal.io' && filled(config('identity_api.token'));
    }

    public function send(array $service, array $payload): array
    {
        $op = $service['operations'][0];
        if ($service['contract_blocker'] || $op['method'] !== 'POST'
            || ! str_starts_with($op['path'], '/api/v0/services/inquiry/')) {
            throw new IdentityError('service_unavailable', 503);
        }
        $base = rtrim((string) config('identity_api.base_url'), '/');
        if ($base !== 'https://service.zohal.io' || ! filled(config('identity_api.token'))) {
            throw new IdentityError('provider_unavailable', 503);
        }
        // No automatic retries, arbitrary URLs, redirects, custom client headers, or raw exception logging.
        try {
            $r = Http::acceptJson()->asJson()->withToken(config('identity_api.token'))->connectTimeout(5)->timeout(30)
                ->withOptions(['allow_redirects' => false, 'debug' => false, 'progress' => function ($total, $downloaded) {
                    if ($downloaded > 2097152) {
                        throw new \RuntimeException('response_limit');
                    }
                }, 'on_headers' => function ($r) {
                    if ((int) $r->getHeaderLine('Content-Length') > 2097152) {
                        throw new \RuntimeException('response_limit');
                    }
                }])->post($base.$op['path'], $payload);
            if (strlen($r->body()) > 2097152) {
                return ['http' => $r->status(), 'json' => []];
            }

            return ['http' => $r->status(), 'json' => is_array($r->json()) ? $r->json() : []];
        } catch (\Throwable) {
            return ['http' => 0, 'json' => []]; // Possibly dispatched: reconciliation, never resend.
        }
    }
}
