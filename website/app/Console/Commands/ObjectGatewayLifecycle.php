<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Service;
use App\Services\Cloud\HetznerClient;
use App\Services\Dns\CloudflareDns;
use Illuminate\Console\Command;

class ObjectGatewayLifecycle extends Command
{
    protected $signature = 'hetzner:object-gateway-lifecycle';
    protected $description = 'Release the hourly Object Gateway after the last tenant retention period';

    public function handle(): int
    {
        foreach (Server::query()->where('type', 'hetzner_object_gateway')->get() as $server) {
            $meta = (array) $server->meta;
            if (blank($meta['provider_ref'] ?? null)) {
                continue;
            }
            $services = Service::query()->where('server_id', $server->id)->get();
            if ($services->contains(fn (Service $service) => ! $service->isDead())) {
                continue;
            }
            $last = $services->max(fn (Service $service) => $service->cancelled_at?->timestamp ?? $service->updated_at?->timestamp ?? time());
            $days = max(1, (int) config('provisioning.object_gateway.termination_retention_days', 30));
            if ($services->isNotEmpty() && $last > now()->subDays($days)->timestamp) {
                continue;
            }
            $deleted = app(HetznerClient::class)->deleteServer((string) $meta['provider_ref']);
            if (! ($deleted['ok'] ?? false)) {
                $this->error('Gateway release failed: '.($deleted['message'] ?? 'unknown'));
                continue;
            }
            foreach (['object-api', 'fsn1.s3', 'nbg1.s3', 'hel1.s3'] as $label) {
                app(CloudflareDns::class)->removeSubdomain($label.'.servernet.cloud');
            }
            unset($meta['provider_ref'], $meta['ipv4'], $meta['started_at']);
            $server->forceFill(['server_ip' => null, 'status' => 'active', 'meta' => $meta])->save();
            $this->info('Released idle Object Gateway '.$server->id.'.');
        }

        return self::SUCCESS;
    }
}
