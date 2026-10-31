<?php

namespace App\Services\Bale\Management;

use App\Models\Setting;
use App\Services\Calendar\Google\GoogleCalendarClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Separate, read-only reporting grant. Calendar credentials/tokens are never changed. */
class ManagerGoogleReporting
{
    public const TOKEN_KEY = 'bale_manager_reporting_google_token';
    public const PROPERTY_KEY = 'bale_manager_reporting_ga4_property';
    public const SITE_KEY = 'bale_manager_reporting_gsc_site';
    public const SCOPES = 'openid email https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly';
    private const REQUIRED = ['https://www.googleapis.com/auth/analytics.readonly', 'https://www.googleapis.com/auth/webmasters.readonly'];

    public function configuration(): array
    {
        $values = Setting::query()->whereIn('key', [self::PROPERTY_KEY, self::SITE_KEY])->pluck('value', 'key');
        $property = (string) ($values[self::PROPERTY_KEY] ?? '300424142');
        $site = (string) ($values[self::SITE_KEY] ?? 'sc-domain:servernet.cloud');

        return ['property_id' => self::validProperty($property) ? $property : '300424142',
            'site' => self::validSite($site) ? $site : 'sc-domain:servernet.cloud'];
    }

    public static function validProperty(string $value): bool
    {
        return preg_match('/\A[1-9][0-9]{0,11}\z/D', $value) === 1;
    }

    public static function validSite(string $value): bool
    {
        if (strlen($value) > 500) { return false; }
        $host = str_starts_with($value, 'sc-domain:') ? substr($value, 10) : null;
        if ($host === null) {
            $url = parse_url($value);
            if (! is_array($url) || ($url['scheme'] ?? '') !== 'https'
                || isset($url['user']) || isset($url['pass']) || isset($url['port'])
                || isset($url['query']) || isset($url['fragment'])) { return false; }
            $host = $url['host'] ?? '';
        }

        return is_string($host) && strlen($host) <= 253
            && preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/Di', $host) === 1;
    }

    public function status(): array
    {
        $token = $this->credential();

        return ['configured' => GoogleCalendarClient::configured(), 'connected' => $token !== null,
            'email' => $token['email'] ?? null, 'connected_at' => $token['connected_at'] ?? null];
    }

    /** @see https://developers.google.com/identity/protocols/oauth2/web-server */
    public function authUrl(string $state, string $redirect, string $verifier): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => GoogleCalendarClient::clientId(), 'redirect_uri' => $redirect,
            'response_type' => 'code', 'scope' => self::SCOPES, 'access_type' => 'offline',
            'prompt' => 'consent select_account', 'include_granted_scopes' => 'false', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function exchangeCode(string $code, string $redirect, string $verifier): array
    {
        $reply = $this->tokenRequest(['code' => $code, 'redirect_uri' => $redirect,
            'code_verifier' => $verifier, 'grant_type' => 'authorization_code']);
        if (! $reply['ok']) { return $reply; }
        $body = $reply['body'];
        if (! $this->validToken($body['access_token'] ?? null) || ! $this->validToken($body['refresh_token'] ?? null)) {
            return ['ok' => false, 'reason' => 'persistent_grant_missing'];
        }
        $scopes = $this->scopes($body['scope'] ?? null);
        if ($scopes === null) { return ['ok' => false, 'reason' => 'scopes_unverified']; }
        $expires = $this->expires($body['expires_in'] ?? null);
        if ($expires === null || strtolower($body['token_type'] ?? 'bearer') !== 'bearer') {
            return ['ok' => false, 'reason' => 'token_unverified'];
        }
        $email = null;
        $identity = $this->get('https://openidconnect.googleapis.com/v1/userinfo', $body['access_token']);
        if ($identity['ok'] && ($identity['body']['email_verified'] ?? false) === true
            && filter_var($identity['body']['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $email = $identity['body']['email'];
        }
        $this->save(['access_token' => $body['access_token'], 'refresh_token' => $body['refresh_token'],
            'expires_at' => now()->addSeconds($expires)->timestamp, 'scopes' => $scopes,
            'email' => $email, 'connected_at' => now()->toIso8601String(), 'client' => $this->clientIdentity()]);

        return ['ok' => true];
    }

    public function disconnect(): void
    {
        // Revoking the shared OAuth client grant could also disconnect Calendar.
        Setting::putSecret(self::TOKEN_KEY, null);
    }

    /** Never returns secrets; used only to isolate cached observations by grant/config. */
    public function accountFingerprint(): ?string
    {
        $token = $this->credential();
        if ($token === null) { return null; }

        return $this->fingerprint($token, $this->configuration());
    }

    /** The caller owns the reporting refresh lock; this is never called by cachedSnapshot. */
    public function accessToken(): array
    {
        $stored = (string) Setting::query()->whereKey(self::TOKEN_KEY)->value('value');
        $token = $this->credential($stored);
        if ($token === null) { return ['ok' => false, 'reason' => 'not_connected']; }
        if (($token['expires_at'] ?? 0) > now()->timestamp + 60) { return $this->accessResult($token); }
        $reply = $this->tokenRequest(['refresh_token' => $token['refresh_token'], 'grant_type' => 'refresh_token']);
        if (! $reply['ok']) { return $reply; }
        $body = $reply['body'];
        $expires = $this->expires($body['expires_in'] ?? null);
        $scopes = isset($body['scope']) ? $this->scopes($body['scope']) : $token['scopes'];
        if (! $this->validToken($body['access_token'] ?? null) || $expires === null || $scopes === null) {
            return ['ok' => false, 'reason' => 'token_unverified'];
        }
        $token['access_token'] = $body['access_token'];
        $token['expires_at'] = now()->addSeconds($expires)->timestamp;
        $token['scopes'] = $scopes;
        // Google may rotate a refresh token; never replace one with an empty value.
        if ($this->validToken($body['refresh_token'] ?? null)) { $token['refresh_token'] = $body['refresh_token']; }
        // A reconnect/disconnect while Google was responding must win over this
        // older refresh. Compare the ciphertext under a row lock before saving.
        $saved = DB::transaction(function () use ($stored, $token) {
            $current = Setting::query()->whereKey(self::TOKEN_KEY)->lockForUpdate()->value('value');
            if (! is_string($current) || ! hash_equals($stored, $current)) { return false; }
            $this->save($token);

            return true;
        });
        if (! $saved) { return ['ok' => false, 'reason' => 'connection_changed']; }

        return $this->accessResult($token);
    }

    /** Fixed API origins only; POST runReport/query are documented read operations. */
    public function report(string $kind, string $accessToken, array $body, ?array $configuration = null): array
    {
        if (! in_array($kind, ['ga4', 'gsc'], true)) { return ['ok' => false, 'reason' => 'report_unsupported']; }
        $config = $configuration ?? $this->configuration();
        if (! self::validProperty((string) ($config['property_id'] ?? '')) || ! self::validSite((string) ($config['site'] ?? ''))) {
            return ['ok' => false, 'reason' => 'source_unverified'];
        }
        $url = $kind === 'ga4'
            ? 'https://analyticsdata.googleapis.com/v1beta/properties/'.$config['property_id'].':runReport'
            : 'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($config['site']).'/searchAnalytics/query';
        try {
            return $this->response(Http::withToken($accessToken)->acceptJson()->withoutRedirecting()
                ->timeout(10)->connectTimeout(3)->post($url, $body));
        } catch (\Throwable) { return ['ok' => false, 'reason' => 'network_unavailable']; }
    }

    private function credential(?string $stored = null): ?array
    {
        try {
            $stored ??= (string) Setting::query()->whereKey(self::TOKEN_KEY)->value('value');
            $token = json_decode(Crypt::decryptString($stored), true, 12, JSON_THROW_ON_ERROR);
            if (! is_array($token) || ! $this->validToken($token['access_token'] ?? null)
                || ! $this->validToken($token['refresh_token'] ?? null)
                || ! is_string($token['client'] ?? null) || ! hash_equals($this->clientIdentity(), $token['client'])
                || $this->scopes(implode(' ', (array) ($token['scopes'] ?? []))) === null) { return null; }

            return $token;
        } catch (\Throwable) { return null; }
    }

    private function save(array $token): void
    {
        Setting::putSecret(self::TOKEN_KEY, json_encode($token, JSON_THROW_ON_ERROR));
    }

    private function clientIdentity(): string
    {
        return hash_hmac('sha256', (string) GoogleCalendarClient::clientId()."\0".(string) GoogleCalendarClient::clientSecret(), (string) config('app.key'));
    }

    private function fingerprint(array $token, array $configuration): string
    {
        return hash_hmac('sha256', json_encode([$token['refresh_token'], $token['client'], $configuration]), (string) config('app.key'));
    }

    private function accessResult(array $token): array
    {
        $configuration = $this->configuration();

        return ['ok' => true, 'token' => $token['access_token'], 'configuration' => $configuration,
            'identity' => $this->fingerprint($token, $configuration)];
    }

    private function validToken(mixed $token): bool
    {
        return is_string($token) && $token !== '' && strlen($token) <= 8192 && ! preg_match('/[\x00-\x20\x7f]/', $token);
    }

    private function expires(mixed $seconds): ?int
    {
        return (is_int($seconds) || (is_string($seconds) && ctype_digit($seconds)))
            && (int) $seconds >= 60 && (int) $seconds <= 86400 ? (int) $seconds : null;
    }

    private function scopes(mixed $value): ?array
    {
        if (! is_string($value)) { return null; }
        $scopes = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        $allowed = [...self::REQUIRED, 'openid', 'email', 'https://www.googleapis.com/auth/userinfo.email'];
        if (array_diff(self::REQUIRED, $scopes) !== [] || array_diff($scopes, $allowed) !== []) { return null; }

        return $scopes;
    }

    private function tokenRequest(array $body): array
    {
        try {
            return $this->response(Http::asForm()->acceptJson()->withoutRedirecting()->timeout(10)->connectTimeout(3)
                ->post('https://oauth2.googleapis.com/token', $body + ['client_id' => GoogleCalendarClient::clientId(),
                    'client_secret' => GoogleCalendarClient::clientSecret()]));
        } catch (\Throwable) { return ['ok' => false, 'reason' => 'network_unavailable']; }
    }

    private function get(string $url, string $token): array
    {
        try {
            return $this->response(Http::withToken($token)->acceptJson()->withoutRedirecting()->timeout(10)->connectTimeout(3)->get($url));
        } catch (\Throwable) { return ['ok' => false, 'reason' => 'network_unavailable']; }
    }

    private function response(\Illuminate\Http\Client\Response $response): array
    {
        if (! $response->successful()) {
            $error = strlen($response->body()) <= 500000 ? $response->json('error') : null;
            $reason = match ($response->status()) {
                401 => 'reauthorization_required', 403 => 'permission_or_api_unavailable', 429 => 'rate_limited',
                default => 'google_unavailable',
            };
            if ($error === 'invalid_grant') { $reason = 'reauthorization_required'; }
            if (in_array($error, ['invalid_client', 'deleted_client'], true)) { $reason = 'credentials_unavailable'; }

            return ['ok' => false, 'reason' => $reason];
        }
        if (strlen($response->body()) > 500000 || ! is_array($response->json()) || isset($response->json()['error'])) {
            return ['ok' => false, 'reason' => 'response_unverified'];
        }

        return ['ok' => true, 'body' => $response->json()];
    }
}
