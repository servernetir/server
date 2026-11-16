<?php

namespace App\Services\Growth;

use App\Models\Setting;
use App\Services\Calendar\Google\GoogleCalendarClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SearchConsoleClient
{
    public const KEY = 'growth_search_console_token';
    public const SITE = 'sc-domain:servernet.cloud';
    public const SITEMAP = 'https://servernet.cloud/sitemaps/blog.xml';
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters';
    public const SCOPES = 'openid email https://www.googleapis.com/auth/webmasters';

    public function status(): array
    {
        $token = $this->credential();

        return ['configured' => GoogleCalendarClient::configured(), 'connected' => $token !== null,
            'email' => $token['email'] ?? null, 'site' => self::SITE, 'sitemap' => self::SITEMAP];
    }

    public function authUrl(string $state, string $verifier, string $redirect): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => GoogleCalendarClient::clientId(), 'redirect_uri' => $redirect, 'response_type' => 'code',
            'scope' => self::SCOPES, 'access_type' => 'offline', 'prompt' => 'consent select_account',
            'include_granted_scopes' => 'false', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchange(string $code, string $verifier, string $redirect): void
    {
        $body = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code,
            'code_verifier' => $verifier, 'redirect_uri' => $redirect]);
        $scopes = preg_split('/\s+/', (string) ($body['scope'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (! in_array(self::SCOPE, $scopes, true) || ! $this->validToken($body['access_token'] ?? null)
            || ! $this->validToken($body['refresh_token'] ?? null)) {
            throw new \RuntimeException('persistent_write_grant_required');
        }
        $access = $body['access_token'];
        $site = $this->jsonGet($this->siteEndpoint(), $access);
        if (($site['siteUrl'] ?? null) !== self::SITE || ! in_array($site['permissionLevel'] ?? '', ['siteOwner', 'siteFullUser'], true)) {
            throw new \RuntimeException('property_write_permission_required');
        }
        $identity = $this->jsonGet('https://openidconnect.googleapis.com/v1/userinfo', $access);
        if (($identity['email_verified'] ?? false) !== true || ! filter_var($identity['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('google_identity_unverified');
        }
        Setting::putSecret(self::KEY, json_encode(['access_token' => $access, 'refresh_token' => $body['refresh_token'],
            'expires_at' => now()->timestamp + $this->expires($body), 'scopes' => $scopes,
            'client' => $this->clientIdentity(), 'email' => $identity['email'], 'connected_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR));
    }

    public function disconnect(): void
    {
        // Do not revoke the shared Google OAuth client or change Calendar/Drive/reporting grants.
        Setting::putSecret(self::KEY, null);
    }

    public function submitSitemap(): void
    {
        $response = Http::withToken($this->token())->timeout(15)->connectTimeout(4)->withoutRedirecting()->acceptJson()
            ->send('PUT', $this->siteEndpoint().'/sitemaps/'.rawurlencode(self::SITEMAP), ['body' => '']);
        if (! $response->successful()) {
            throw new \RuntimeException($this->errorCode($response->status()));
        }
        if (trim($response->body()) !== '') {
            throw new \RuntimeException('submission_response_unverified');
        }
    }

    public function inspect(string $url): array
    {
        if (! self::allowedUrl($url)) {
            throw new \RuntimeException('inspection_url_rejected');
        }
        $response = Http::withToken($this->token())->timeout(15)->connectTimeout(4)->withoutRedirecting()->acceptJson()
            ->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
                ['inspectionUrl' => $url, 'siteUrl' => self::SITE, 'languageCode' => 'en-US']);
        if (! $response->successful()) {
            throw new \RuntimeException($this->errorCode($response->status()));
        }
        $status = $response->json('inspectionResult.indexStatusResult');
        if (! is_array($status) || ! is_string($status['verdict'] ?? null)) {
            throw new \RuntimeException('inspection_response_unverified');
        }

        return array_intersect_key($status, array_flip(['verdict', 'coverageState', 'robotsTxtState', 'indexingState',
            'pageFetchState', 'lastCrawlTime', 'googleCanonical', 'userCanonical']));
    }

    public static function allowedUrl(string $url): bool
    {
        return preg_match('~\Ahttps://servernet\.cloud/(?:en/|tr/)?blog/[a-zA-Z0-9_-]+\z~D', $url) === 1;
    }

    private function token(): string
    {
        return Cache::lock('search-console-refresh', 30)->block(3, function () {
            $stored = (string) Setting::query()->whereKey(self::KEY)->value('value');
            $token = $this->credential();
            if (! $token) {
                throw new \RuntimeException('google_connection_required');
            }
            if ($token['expires_at'] > now()->timestamp + 60) {
                return $token['access_token'];
            }
            $body = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $token['refresh_token']]);
            if (! $this->validToken($body['access_token'] ?? null)
                || (isset($body['scope']) && ! in_array(self::SCOPE, preg_split('/\s+/', $body['scope']), true))) {
                throw new \RuntimeException('google_write_scope_missing');
            }
            $token['access_token'] = $body['access_token'];
            $token['expires_at'] = now()->timestamp + $this->expires($body);
            if ($this->validToken($body['refresh_token'] ?? null)) {
                $token['refresh_token'] = $body['refresh_token'];
            }
            DB::transaction(function () use ($stored, $token) {
                $current = Setting::query()->whereKey(self::KEY)->lockForUpdate()->value('value');
                if (! is_string($current) || ! hash_equals($stored, $current)) {
                    throw new \RuntimeException('google_connection_changed');
                }
                Setting::putSecret(self::KEY, json_encode($token, JSON_THROW_ON_ERROR));
            });

            return $token['access_token'];
        });
    }

    private function credential(): ?array
    {
        try {
            $token = json_decode(Setting::getSecret(self::KEY) ?? '', true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($token) || ! $this->validToken($token['access_token'] ?? null)
                || ! $this->validToken($token['refresh_token'] ?? null) || ! is_int($token['expires_at'] ?? null)
                || ! is_array($token['scopes'] ?? null) || ! in_array(self::SCOPE, $token['scopes'], true)
                || ! is_string($token['client'] ?? null) || ! hash_equals($this->clientIdentity(), $token['client'])) {
                return null;
            }

            return $token;
        } catch (\Throwable) {
            return null;
        }
    }

    private function tokenRequest(array $body): array
    {
        $response = Http::asForm()->acceptJson()->withoutRedirecting()->timeout(15)->connectTimeout(4)
            ->post('https://oauth2.googleapis.com/token', $body + ['client_id' => GoogleCalendarClient::clientId(),
                'client_secret' => GoogleCalendarClient::clientSecret()]);
        if (! $response->successful()) {
            throw new \RuntimeException(in_array($response->json('error'), ['invalid_grant', 'invalid_client', 'deleted_client'], true)
                ? 'google_reauthorization_required' : $this->errorCode($response->status()));
        }
        if (! is_array($response->json()) || isset($response->json()['error'])) {
            throw new \RuntimeException('google_token_unverified');
        }

        return $response->json();
    }

    private function jsonGet(string $url, string $token): array
    {
        $response = Http::withToken($token)->acceptJson()->withoutRedirecting()->timeout(12)->connectTimeout(4)->get($url);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new \RuntimeException($this->errorCode($response->status()));
        }

        return $response->json();
    }

    private function expires(array $body): int
    {
        $n = $body['expires_in'] ?? null;
        if ((! is_int($n) && ! (is_string($n) && ctype_digit($n))) || (int) $n < 60 || (int) $n > 86400) {
            throw new \RuntimeException('google_expiry_unverified');
        }

        return (int) $n;
    }

    private function clientIdentity(): string
    {
        return hash_hmac('sha256', GoogleCalendarClient::clientId()."\0".GoogleCalendarClient::clientSecret(), (string) config('app.key'));
    }

    private function siteEndpoint(): string
    {
        return 'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode(self::SITE);
    }

    private function validToken(mixed $token): bool
    {
        return is_string($token) && $token !== '' && strlen($token) <= 8192 && ! preg_match('/[\x00-\x20\x7f]/', $token);
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
            401 => 'google_reauthorization_required', 403 => 'google_permission_or_api_missing',
            429 => 'google_rate_limited', default => 'google_unavailable',
        };
    }
}
