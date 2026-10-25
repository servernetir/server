<?php

namespace App\Services\Growth;

use App\Models\Setting;
use App\Services\Calendar\Google\GoogleCalendarClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GrowthDrive
{
    public const SCOPES = 'openid email https://www.googleapis.com/auth/drive.file';

    public function configured(): bool
    {
        return GoogleCalendarClient::configured();
    }

    public function connected(): bool
    {
        return filled(Setting::getSecret('growth_drive_credential'));
    }

    public function authUrl(string $state, string $verifier, string $redirect): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => GoogleCalendarClient::clientId(),
            'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => self::SCOPES, 'access_type' => 'offline', 'prompt' => 'consent',
            'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256']);
    }

    public function exchange(string $code, string $verifier, string $redirect): void
    {
        $res = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $redirect, 'grant_type' => 'authorization_code',
            'client_id' => GoogleCalendarClient::clientId(), 'client_secret' => GoogleCalendarClient::clientSecret()]);
        if (! $res->successful() || ! is_string($res->json('refresh_token')) || ! is_string($res->json('access_token'))) {
            throw new \RuntimeException('google_oauth_failed');
        }
        $scopes = explode(' ', (string) $res->json('scope'));
        if (! in_array('https://www.googleapis.com/auth/drive.file', $scopes, true)) {
            throw new \RuntimeException('google_scope_missing');
        }
        $identity = Http::withToken($res->json('access_token'))->timeout(12)->get('https://openidconnect.googleapis.com/v1/userinfo');
        if (! $identity->successful() || ! $identity->json('email_verified')
            || strtolower((string) $identity->json('email')) !== 'ehsanebrahimitr@gmail.com') {
            throw new \RuntimeException('google_account_mismatch');
        }
        Setting::putSecret('growth_drive_credential', json_encode(['refresh_token' => $res->json('refresh_token'),
            'access_token' => $res->json('access_token'), 'expires' => now()->timestamp + (int) $res->json('expires_in', 3600)], JSON_THROW_ON_ERROR));
        Setting::put('growth_drive_email', (string) $identity->json('email'));
    }

    private function token(): string
    {
        return Cache::lock('growth-drive-refresh', 30)->block(5, function () {
            $credential = json_decode(Setting::getSecret('growth_drive_credential') ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            if (empty($credential['refresh_token'])) {
                throw new \RuntimeException('drive_not_connected');
            }
            if (($credential['expires'] ?? 0) > now()->timestamp + 60) {
                return $credential['access_token'];
            }
            $res = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'client_id' => GoogleCalendarClient::clientId(), 'client_secret' => GoogleCalendarClient::clientSecret(),
                'refresh_token' => $credential['refresh_token'], 'grant_type' => 'refresh_token']);
            if (! $res->successful() || ! is_string($res->json('access_token'))) {
                throw new \RuntimeException('drive_refresh_failed');
            }
            $credential['access_token'] = $res->json('access_token');
            if (is_string($res->json('refresh_token')) && $res->json('refresh_token') !== '') {
                $credential['refresh_token'] = $res->json('refresh_token');
            }
            $credential['expires'] = now()->timestamp + (int) $res->json('expires_in', 3600);
            Setting::putSecret('growth_drive_credential', json_encode($credential, JSON_THROW_ON_ERROR));

            return $credential['access_token'];
        });
    }

    public function archive(string $key, string $name, string $bytes, string $mime): string
    {
        if (strlen($bytes) > 25_000_000) {
            throw new \RuntimeException('archive_too_large');
        }

        return Cache::lock('growth-drive-archive', 60)->block(5, function () use ($key, $name, $bytes, $mime) {
            $token = $this->token();
            $existing = Http::withToken($token)->timeout(12)->get('https://www.googleapis.com/drive/v3/files', [
                'q' => "trashed = false and appProperties has { key='servernet_growth' and value='".$key."' }", 'fields' => 'files(id)']);
            if (! $existing->successful()) {
                throw new \RuntimeException('drive_lookup_failed');
            }
            if ($id = data_get($existing->json(), 'files.0.id')) {
                return $id;
            }
            $folder = Setting::get('growth_drive_folder');
            if (! $folder) {
                $found = Http::withToken($token)->timeout(12)->get('https://www.googleapis.com/drive/v3/files', [
                    'q' => "trashed = false and appProperties has { key='servernet_growth' and value='root' }", 'fields' => 'files(id)']);
                if (! $found->successful()) {
                    throw new \RuntimeException('drive_lookup_failed');
                }
                $folder = data_get($found->json(), 'files.0.id');
                if ($folder) {
                    Setting::put('growth_drive_folder', $folder);
                }
            }
            if (! $folder) {
                $res = Http::withToken($token)->timeout(15)->post('https://www.googleapis.com/drive/v3/files', [
                    'name' => 'ServerNet AI Growth', 'mimeType' => 'application/vnd.google-apps.folder', 'appProperties' => ['servernet_growth' => 'root']]);
                if (! $res->successful() || ! $res->json('id')) {
                    throw new \RuntimeException('drive_folder_failed');
                }
                $folder = $res->json('id');
                Setting::put('growth_drive_folder', $folder);
            }
            $boundary = 'snet-growth-'.bin2hex(random_bytes(16));
            $metadata = json_encode(['name' => $name, 'parents' => [$folder], 'appProperties' => ['servernet_growth' => $key]], JSON_THROW_ON_ERROR);
            $body = '--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".$metadata.
                "\r\n--".$boundary."\r\nContent-Type: ".$mime."\r\n\r\n".$bytes."\r\n--".$boundary."--\r\n";
            $res = Http::withToken($token)->timeout(40)->withBody($body, 'multipart/related; boundary='.$boundary)
                ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
            if (! $res->successful() || ! $res->json('id')) {
                throw new \RuntimeException('drive_archive_failed');
            }

            return (string) $res->json('id');
        });
    }

    public function disconnect(): void
    {
        Setting::putSecret('growth_drive_credential', null);
        Setting::put('growth_drive_email', null);
    }
}
