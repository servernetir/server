<?php

namespace App\Services\Dns;

use App\Models\Service;
use App\Services\Dns\Wire\DnsResponse;
use App\Services\Dns\Wire\DnsWireClient;

/** Read-only DNS evidence; never probes a customer-controlled HTTP address. */
class HostingDomainConnection
{
    public function __construct(private DnsWireClient $dns) {}

    public static function nameservers(Service $service): array
    {
        // The current assigned server is authoritative, not an old delivery snapshot.
        $values = preg_split('/[\s,;]+/', (string) $service->server?->nameservers, -1, PREG_SPLIT_NO_EMPTY);
        $values = array_map(fn ($s) => strtolower(rtrim($s, '.')), $values ?: []);

        return array_values(array_unique(array_filter($values, fn ($s) => self::validName($s))));
    }

    public static function validName(string $name): bool
    {
        return strlen($name) <= 253 && str_contains($name, '.')
            && filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    public function inspect(Service $service): array
    {
        $domain = strtolower(rtrim(trim((string) $service->domain), '.'));
        $expected = self::nameservers($service);
        $ip = (string) ($service->server_ip ?: $service->server?->server_ip);

        return $this->inspectDomain($domain, $expected, $ip);
    }

    public function inspectDomain(string $domain, array $expected, string $ip): array
    {
        if (! self::validName($domain) || count($expected) < 2 || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return $this->result('unconfigured');
        }

        // Fixed public resolver destinations; no user-controlled socket destination.
        $samples = [];
        foreach (['1.1.1.1', '8.8.8.8'] as $resolver) {
            $ns = $this->dns->query($resolver, 53, $domain, 'NS', true, 1.2);
            $addressType = str_contains($ip, ':') ? 'AAAA' : 'A';
            $a = $this->dns->query($resolver, 53, $domain, $addressType, true, 1.2);
            $otherType = $addressType === 'A' ? 'AAAA' : 'A';
            $other = $this->dns->query($resolver, 53, $domain, $otherType, true, 1.2);
            if (! $ns instanceof DnsResponse || ! $a instanceof DnsResponse || ! $other instanceof DnsResponse
                || ! in_array($ns->rcode, [0, 3], true) || ! in_array($a->rcode, [0, 3], true) || ! in_array($other->rcode, [0, 3], true)) {
                continue;
            }
            if (count(array_unique([$ns->rcode, $a->rcode, $other->rcode])) > 1) {
                continue; // Contradictory NXDOMAIN/no-error evidence is not a valid sample.
            }
            $observed = array_column($ns->records('answer', 'NS', $domain), 'data');
            $addresses = array_column($a->records('answer', $addressType, $domain), 'data');
            // CNAME and proxy configurations need separate verification; never guess green.
            $direct = ! $other->records('answer', $otherType, $domain) && count($addresses) > 0 && count(array_filter($addresses, fn ($v) => @inet_pton($v) === @inet_pton($ip))) === count($addresses);
            $nsMatch = count($observed) === count($expected) && ! array_diff($expected, $observed);
            $samples[] = ['state' => $direct ? 'connected' : ($nsMatch ? 'propagating' : 'needs_setup'), 'ns' => $observed];
        }
        if (count($samples) !== 2) {
            return $this->result('unknown');
        }
        $state = $samples[0]['state'] === $samples[1]['state'] ? $samples[0]['state'] : 'propagating';

        return $this->result($state, array_values(array_unique(array_merge($samples[0]['ns'], $samples[1]['ns']))));
    }

    private function result(string $state, array $observed = []): array
    {
        return ['state' => $state, 'observed_ns' => $observed, 'checked_at' => gmdate('c')];
    }
}
