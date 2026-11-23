<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Service;
use Illuminate\Support\Facades\Crypt;
use phpseclib4\Net\SFTP;

/** Installation transport for an already allocated, dedicated customer VM. */
class ManagedN8nSsh
{
    protected function connect(array $endpoint): SFTP
    {
        return new SFTP($endpoint['host'], $endpoint['port'], 8);
    }

    public function step(Service $service, CloudInstance $instance, array $installation): array
    {
        $endpoint = $instance->endpoint();
        if (! $endpoint || blank($instance->password())) { return ['state' => 'waiting_ssh']; }
        $ssh = $this->connect($endpoint);
        $ssh->setTimeout(15);
        // Pin the key for this allocation. Subsequent mismatches never send the password.
        $key = $ssh->getServerPublicHostKey();
        if (! is_string($key) || $key === '') { throw new \RuntimeException('ssh_host_key_missing'); }
        $fingerprint = hash('sha256', $key);
        $pinned = data_get($installation, 'ssh_key_sha256');
        if ($pinned && ! hash_equals($pinned, $fingerprint)) { throw new \RuntimeException('ssh_host_key_changed'); }
        $username = (string) ($service->username ?: 'root');
        if (! $ssh->login($username, $instance->password())) { throw new \RuntimeException('ssh_login_failed'); }
        if (! $pinned) {
            CloudInstance::whereKey($instance->id)->update(['meta->n8n->ssh_key_sha256' => $fingerprint]);
        }
        $probe = trim((string) $ssh->exec(
            'if test -f /opt/servernet-n8n/ready.json; then '
            .'curl -fsS --max-time 4 http://127.0.0.1:5678/healthz/readiness >/dev/null '
            .'&& cat /opt/servernet-n8n/ready.json; '
            .'elif systemctl is-active --quiet servernet-n8n-install.service; then printf installing; '
            .'elif test -f /opt/servernet-n8n/failed; then printf failed; else printf pending; fi'
        ));
        $ready = json_decode($probe, true);
        if (is_array($ready) && ($ready['service_id'] ?? null) === $service->id
            && hash_equals($installation['nonce'], (string) ($ready['nonce'] ?? ''))) {
            return ['state' => 'installed', 'version' => $ready['version'] ?? null];
        }
        if ($probe === 'installing') { return ['state' => 'installing']; }
        if ($probe === 'failed') { throw new \RuntimeException('guest_install_failed'); }
        if ($probe !== 'pending') { return ['state' => 'installing']; }

        $ssh->exec('install -d -m 0700 /opt/servernet-n8n');
        if ($ssh->getExitStatus() !== 0) { throw new \RuntimeException('guest_directory_failed'); }
        $spec = ['service_id' => $service->id, 'hostname' => $installation['hostname'],
            'nonce' => $installation['nonce'], 'email' => $installation['owner_email'],
            'firstName' => 'ServerNet', 'lastName' => 'Customer',
            'password' => Crypt::decryptString($installation['password_enc']),
            'version' => $installation['version'], 'private' => $instance->hasPrivateIp(),
            'vcpu' => (int) data_get($instance->specs, 'vcpu'),
            'ram_mb' => (int) data_get($instance->specs, 'ram_mb'),
            'disk_gb' => (int) data_get($instance->specs, 'disk_gb')];
        $ssh->put('/opt/servernet-n8n/request.json', json_encode($spec, JSON_THROW_ON_ERROR));
        $ssh->chmod('/opt/servernet-n8n/request.json', 0600);
        $ssh->put('/opt/servernet-n8n/install.py', file_get_contents(resource_path('n8n/install.py')));
        $ssh->chmod('/opt/servernet-n8n/install.py', 0700);
        // A dedicated systemd unit survives the short SSH request. Never launch a second installer.
        $ssh->exec('systemd-run --quiet --unit=servernet-n8n-install --property=RuntimeMaxSec=1800 '
            .'--property=UMask=0077 /usr/bin/python3 /opt/servernet-n8n/install.py');
        if ($ssh->getExitStatus() !== 0) { return ['state' => 'installing']; }
        return ['state' => 'installing'];
    }
}
