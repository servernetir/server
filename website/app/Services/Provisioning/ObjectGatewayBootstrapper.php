<?php

namespace App\Services\Provisioning;

use App\Models\Server;
use App\Models\Setting;
use App\Services\Cloud\HetznerClient;
use App\Services\Dns\CloudflareDns;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Lazily creates the shared gateway only after the first paid order reaches provisioning. */
class ObjectGatewayBootstrapper
{
    public const ACCESS_SETTING = 'hetzner_object_s3_access';
    public const SECRET_SETTING = 'hetzner_object_s3_secret';
    public const ACCEPTED_SETTING = 'hetzner_object_gateway_acceptance';

    public function coldStartReady(Server $server): bool
    {
        $accepted = json_decode((string) Setting::get(self::ACCEPTED_SETTING), true);

        return filled(Setting::getSecret(self::ACCESS_SETTING))
            && filled(Setting::getSecret(self::SECRET_SETTING))
            && filled(Setting::getSecret('hetzner_api_token'))
            && app(CloudflareDns::class)->isConfigured()
            && ($accepted['ok'] ?? false) === true
            && ($server->meta['cold_start'] ?? false) === true;
    }

    /** @return array{ok:bool,pending:bool,message:string} */
    public function ensureReady(Server $server): array
    {
        if ((new ObjectGatewayClient($server))->testConnection()['ok'] ?? false) {
            if ($server->status !== 'active') {
                $server->forceFill(['status' => 'active'])->save();
            }

            return ['ok' => true, 'pending' => false, 'message' => ''];
        }

        if (! $this->coldStartReady($server)) {
            return ['ok' => false, 'pending' => false, 'message' => 'آمادگی راه‌اندازی خودکار Gateway تأیید نشده است.'];
        }

        $lock = Cache::lock('object-gateway.bootstrap.'.$server->id, 300);
        if (! $lock->get()) {
            return ['ok' => false, 'pending' => true, 'message' => 'Gateway در حال راه‌اندازی است.'];
        }

        try {
            $server->refresh();
            $meta = (array) $server->meta;
            if (blank($server->api_token)) {
                $server->api_token = bin2hex(random_bytes(32));
            }
            if (blank($meta['provider_ref'])) {
                $created = app(HetznerClient::class)->createServer([
                    'name' => 'servernet-object-gateway',
                    'plan_ref' => 'cax11',
                    'location_ref' => 'fsn1',
                    'image_ref' => 'ubuntu-24.04',
                    'user_data' => $this->cloudInit((string) $server->api_token),
                    'labels' => ['servernet-role' => 'object-gateway', 'billing' => 'hourly'],
                ]);
                if (! ($created['ok'] ?? false) || blank($created['ipv4'] ?? null)) {
                    return ['ok' => false, 'pending' => false, 'message' => 'ساخت Gateway ناموفق بود: '.($created['message'] ?? 'خطای نامشخص')];
                }
                $meta['provider_ref'] = (string) $created['ref'];
                $meta['ipv4'] = (string) $created['ipv4'];
                $meta['started_at'] = now()->utc()->toIso8601String();
            }

            foreach (['object-api', 'fsn1.s3', 'nbg1.s3', 'hel1.s3'] as $label) {
                $dns = app(CloudflareDns::class)->pointSubdomain($label.'.servernet.cloud', (string) $meta['ipv4']);
                if (! $dns['ok']) {
                    return ['ok' => false, 'pending' => true, 'message' => 'Gateway ساخته شد؛ DNS در حال آماده‌سازی است.'];
                }
            }
            $server->forceFill([
                'hostname' => 'object-api.servernet.cloud', 'port' => 443, 'verify_tls' => true,
                'server_ip' => (string) $meta['ipv4'], 'status' => 'maintenance', 'meta' => $meta,
            ])->save();

            return ['ok' => false, 'pending' => true, 'message' => 'Gateway ساخته شد و نصب خودکار در حال تکمیل است.'];
        } finally {
            $lock->release();
        }
    }

    public function cloudInit(string $hmacSecret): string
    {
        $template = file_get_contents($this->infraPath('cloud-init.sh.tpl'));
        if (! is_string($template)) {
            throw new RuntimeException('Object Gateway cloud-init template is unavailable.');
        }
        $archive = $this->sourceArchive();
        $replace = [
            '__SOURCE_ARCHIVE_B64__' => base64_encode($archive),
            '__HETZNER_S3_ACCESS__' => $this->shellValue((string) Setting::getSecret(self::ACCESS_SETTING)),
            '__HETZNER_S3_SECRET__' => $this->shellValue((string) Setting::getSecret(self::SECRET_SETTING)),
            '__CONTROL_HMAC_SECRET__' => $this->shellValue($hmacSecret),
        ];

        return strtr($template, $replace);
    }

    private function sourceArchive(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sn-ogw-');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create gateway archive.');
        }
        @unlink($tmp);
        $tar = $tmp.'.tar';
        try {
            $phar = new \PharData($tar);
            foreach (glob($this->infraPath('*')) ?: [] as $file) {
                if (is_file($file) && basename($file) !== 'cloud-init.sh.tpl') {
                    $phar->addFile($file, basename($file));
                }
            }
            $phar->compress(\Phar::GZ);
            $data = file_get_contents($tar.'.gz');
            if (! is_string($data) || $data === '') {
                throw new RuntimeException('Gateway archive is empty.');
            }

            return $data;
        } finally {
            @unlink($tar);
            @unlink($tar.'.gz');
        }
    }

    private function infraPath(string $file): string
    {
        foreach ([base_path('infra/object-storage-gateway'), dirname(base_path()).'/infra/object-storage-gateway'] as $dir) {
            if (is_dir($dir)) {
                return $dir.'/'.$file;
            }
        }
        throw new RuntimeException('Object Gateway source directory is unavailable.');
    }

    private function shellValue(string $value): string
    {
        if ($value === '' || str_contains($value, "\n")) {
            throw new RuntimeException('Object Gateway credential is invalid.');
        }

        return str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], $value);
    }
}
