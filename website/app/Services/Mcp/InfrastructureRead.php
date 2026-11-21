<?php

namespace App\Services\Mcp;

use App\Models\Server;
use App\Models\Setting;
use App\Services\Provisioning\WhmClient;
use App\Services\Provisioning\PleskClient;
use Illuminate\Support\Facades\Http;

final class InfrastructureRead
{
    public function inventory(): array
    {
        $panels = Server::query()->whereIn('type', ['whm', 'plesk'])->orderBy('id')->get()
            ->map(fn ($s) => ['id' => 'panel-'.$s->id, 'name' => $s->name, 'type' => $s->type,
                'country' => $s->country, 'status' => $s->status, 'tls_verification' => $s->verify_tls])->all();
        return ['panels' => $panels, 'proxmox' => ['id' => 'proxmox',
            'configured' => filled(Setting::getSecret('proxmox_token_secret'))],
            'ssh_targets' => app(SshGateway::class)->inventory()];
    }

    public function status(string $target): array
    {
        if ($target === 'proxmox') {
            return $this->proxmox();
        }
        abort_unless(preg_match('/\Apanel-([1-9][0-9]*)\z/', $target, $m), 422);
        $server = Server::query()->whereIn('type', ['whm', 'plesk'])->findOrFail($m[1]);
        // New access never inherits an old TLS verification exception.
        if (! $server->verify_tls) {
            return ['ok' => false, 'code' => 'tls_verification_required'];
        }
        if ($server->type === 'whm') {
            $r = (new WhmClient($server))->call('version');
            return ['ok' => $r['ok'], 'version' => $r['ok'] ? ($r['data']['version'] ?? null) : null];
        }
        $r = (new PleskClient($server))->call('<packet><server><get><gen_info/></get></server></packet>');
        return ['ok' => $r['ok'], 'type' => 'plesk'];
    }

    private function proxmox(): array
    {
        $base = rtrim((string) Setting::get('proxmox_api_url', ''), '/');
        $secret = Setting::getSecret('proxmox_token_secret');
        $id = Setting::get('proxmox_token_id', '');
        if (! $secret || ! $id || ! filter_var($base, FILTER_VALIDATE_URL)
            || parse_url($base, PHP_URL_SCHEME) !== 'https'
            || parse_url($base, PHP_URL_USER) || parse_url($base, PHP_URL_QUERY)) {
            return ['ok' => false, 'code' => 'configuration_required'];
        }
        try {
            $r = Http::acceptJson()->connectTimeout(5)->timeout(15)
                ->withHeaders(['Authorization' => 'PVEAPIToken='.$id.'='.$secret])->get($base.'/cluster/resources', ['type' => 'vm']);
            $data = $r->json('data');
            if (! $r->successful() || ! is_array($data)) {
                return ['ok' => false, 'code' => 'upstream_unavailable'];
            }
            $rows = array_map(fn ($v) => array_intersect_key($v, array_flip([
                'vmid', 'type', 'name', 'status', 'node', 'cpu', 'maxcpu', 'mem', 'maxmem', 'disk', 'maxdisk', 'uptime',
            ])), array_slice($data, 0, 500));
            return ['ok' => true, 'guests' => $rows, 'truncated' => count($data) > 500];
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'connection_or_tls_failed'];
        }
    }
}
