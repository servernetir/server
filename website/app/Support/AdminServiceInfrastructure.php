<?php

namespace App\Support;

use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudManager;

/** Internal presentation only: never append provider details to customer models or JSON. */
class AdminServiceInfrastructure
{
    private const PANELS = [
        'hetzner' => 'https://console.hetzner.com/',
        'hetzner-robot' => 'https://robot.hetzner.com/',
        'arvan' => 'https://panel.arvancloud.ir/ecc',
        'aeza' => 'https://my.aeza.net/services',
        'ovh' => 'https://www.ovh.com/manager/',
        'salad' => 'https://portal.salad.com/',
        'lightnode' => 'https://console.lightnode.com/',
    ];

    public function describe(Service $service): array
    {
        $instance = $service->cloudInstance;
        $plan = $service->cloudPlan;
        $server = $service->server;
        // The delivered instance wins: fulfillment can choose a different provider.
        $provider = trim((string) ($instance?->provider ?: $plan?->provider));
        $reference = trim((string) $instance?->provider_ref);
        $matchingPlan = $plan && $plan->provider === $provider ? $plan : null;
        $location = trim((string) ($instance?->location_code ?: $matchingPlan?->location_code));
        $url = null;
        $direct = false;

        if ($provider !== '') {
            $label = CloudManager::REAL_NAMES[$provider] ?? $provider;
            $url = self::PANELS[$provider] ?? null;

            if ($provider === 'arvan' && preg_match('/^([a-z0-9_-]+):([a-zA-Z0-9-]+)$/D', $reference, $parts)) {
                $location = $parts[1];
                $url = 'https://panel.arvancloud.ir/ecc/'.rawurlencode($parts[1])
                    .'/servers/'.rawurlencode($parts[2]).'/details';
                $direct = true;
            } elseif ($provider === 'aeza' && ctype_digit($reference)) {
                // order:* identifies an order awaiting a resource, never a server.
                $url = 'https://my.aeza.net/services/'.rawurlencode($reference);
                $direct = true;
            } elseif ($provider === 'salad' && $reference !== '') {
                $org = trim((string) Setting::get('salad_org', ''));
                $project = trim((string) Setting::get('salad_project', '')) ?: 'default';
                if ($org !== '') {
                    $url = 'https://portal.salad.com/organizations/'.rawurlencode($org)
                        .'/projects/'.rawurlencode($project).'/containers/'.rawurlencode($reference);
                    $direct = true;
                }
            } elseif ($provider === 'proxmox') {
                $url = $this->panelOrigin((string) Setting::get('proxmox_api_url', ''));
            }
        } elseif ($server) {
            // Vendor is explicit inventory data; do not guess it from names or IPs.
            $vendor = trim((string) $server->vendor);
            $knownVendor = strtolower($vendor);
            $label = $knownVendor === 'hetzner' ? 'Hetzner'
                : (CloudManager::REAL_NAMES[$knownVendor] ?? ($vendor ?: $server->typeLabel()));
            $provider = $knownVendor;
            $location = trim((string) $server->locationLabel());
            $url = self::PANELS[$knownVendor] ?? null;
            if (in_array($server->type, ['whm', 'plesk', 'directadmin'], true)) {
                $url = $this->panelOrigin((string) $server->hostname, $server->effectivePort());
            }
            if ($vendor === '' && in_array($server->type, ['hetzner_storage', 'hetzner_volume', 'hetzner_object'], true)) {
                $label = 'Hetzner';
                $url = self::PANELS['hetzner'];
            }
        } else {
            $label = 'ثبت نشده';
        }

        return [
            'label' => $label,
            'reference' => $reference !== '' ? $reference : null,
            'location' => $location !== '' ? $location : null,
            'server_name' => $server?->name,
            'url' => $url,
            'direct' => $direct,
            'awaiting_resource' => $instance === null && $plan !== null,
        ];
    }

    private function panelOrigin(string $value, ?int $port = null): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $parts = parse_url(str_contains($value, '://') ? $value : 'https://'.$value);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $host = $parts['host'];
        if (preg_match('/[\s<>"\x27]/', $host)) {
            return null;
        }
        $effectivePort = $port ?? ($parts['port'] ?? null);

        return $parts['scheme'].'://'.$host.($effectivePort ? ':'.$effectivePort : '').'/';
    }
}
