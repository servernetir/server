<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleIdentity
{
    public static function configured(): bool
    {
        return (bool) config('google-auth.enabled')
            && filled(config('google-auth.client_id')) && filled(config('google-auth.client_secret'));
    }

    public function authorizationUrl(string $state, string $verifier, string $redirect, bool $phone = false): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('google-auth.client_id'), 'redirect_uri' => $redirect,
            'response_type' => 'code', 'scope' => 'openid email profile'.($phone ? ' https://www.googleapis.com/auth/user.phonenumbers.read' : ''),
            'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'prompt' => 'select_account',
        ]);
    }

    /** Only Google can redeem this session's authorization code and PKCE verifier. */
    public function exchange(string $code, string $verifier, string $redirect, bool $phone = false): array
    {
        $token = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('google-auth.client_id'), 'client_secret' => config('google-auth.client_secret'),
            'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
        ]);
        if (! $token->successful() || ! is_string($token->json('access_token')) || $token->json('access_token') === '') {
            throw new RuntimeException('Google code exchange failed');
        }
        $response = Http::withToken($token->json('access_token'))->timeout(15)
            ->get('https://openidconnect.googleapis.com/v1/userinfo');
        $user = $response->json();
        if (! $response->successful() || ! is_array($user)
            || ($user['email_verified'] ?? false) !== true
            || ! is_string($user['sub'] ?? null) || ! preg_match('/^[0-9]{1,255}$/D', $user['sub'])
            || ! is_string($user['email'] ?? null) || strlen($user['email']) > 190
            || ! filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Google identity was not verified');
        }
        // Google is authoritative for Gmail and managed Workspace addresses only.
        if (! str_ends_with(strtolower($user['email']), '@gmail.com') && empty($user['hd'])) {
            throw new RuntimeException('Google email ownership is not authoritative');
        }
        $identity = ['sub' => $user['sub'], 'email' => strtolower($user['email']),
            'first_name' => mb_substr((string) ($user['given_name'] ?? ''), 0, 100),
            'last_name' => mb_substr((string) ($user['family_name'] ?? ''), 0, 100)];
        $phoneDiagnostics = ['http_status' => 0, 'number_count' => 0, 'canonical_count' => 0, 'value_count' => 0];
        if ($phone) {
            $identity['phone_status'] = 'not_granted';
        }
        if ($phone && in_array('https://www.googleapis.com/auth/user.phonenumbers.read',
            explode(' ', (string) $token->json('scope')), true)) {
            $identity['phone_status'] = 'unavailable';
            try {
                $people = Http::withToken($token->json('access_token'))->timeout(10)
                    ->get('https://people.googleapis.com/v1/people/me', [
                        'personFields' => 'phoneNumbers', 'sources' => 'READ_SOURCE_TYPE_PROFILE',
                    ]);
                $numbers = $people->successful() ? $people->json('phoneNumbers', []) : [];
                $phoneDiagnostics['http_status'] = $people->status();
                if ($people->successful() && is_array($numbers)) {
                    $identity['phone_status'] = 'no_number';
                    $numbers = array_filter($numbers, 'is_array');
                    $phoneDiagnostics['number_count'] = count($numbers);
                    $phoneDiagnostics['canonical_count'] = count(array_filter($numbers, fn ($n) => is_string($n['canonicalForm'] ?? null)));
                    $phoneDiagnostics['value_count'] = count(array_filter($numbers, fn ($n) => is_string($n['value'] ?? null)));
                    usort($numbers, fn ($a, $b) => (int) ($b['metadata']['primary'] ?? false) <=> (int) ($a['metadata']['primary'] ?? false));
                    foreach ($numbers as $number) {
                        $canonical = $number['canonicalForm'] ?? null;
                        // Google only supplies canonicalForm when it can interpret the number.
                        // Accept an explicitly international value too; never guess a country code.
                        if (! is_string($canonical) || ! preg_match('/^\+[1-9][0-9]{7,14}$/D', $canonical)) {
                            $value = $number['value'] ?? null;
                            $canonical = is_string($value) ? preg_replace('/[\s().-]+/', '', trim($value)) : null;
                        }
                        if (is_string($canonical) && preg_match('/^\+[1-9][0-9]{7,14}$/D', $canonical)) {
                            $identity['phone'] = $canonical;
                            $identity['phone_status'] = 'received';
                            break;
                        }
                    }
                }
            } catch (\Throwable) {
                // Phone access is optional; never block registration or log access tokens.
            }
        }
        if ($phone) {
            // Counts and a fixed outcome only; never include response bodies or personal values.
            try {
                Log::build(['driver' => 'daily', 'path' => storage_path('logs/google-phone.log'),
                    'level' => 'info', 'days' => 7, 'permission' => 0600])
                    ->info('Google phone lookup', ['outcome' => $identity['phone_status']] + $phoneDiagnostics);
            } catch (\Throwable) {
                // Diagnostic storage must never prevent sign-in or registration.
            }
        }
        return $identity;
    }
}
