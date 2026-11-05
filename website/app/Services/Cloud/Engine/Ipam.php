<?php

namespace App\Services\Cloud\Engine;

use App\Models\{EngineAddress, EngineIpEvent, EngineNode, EngineSubnet, EngineVm, Service};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Explicit routed public IPv4 inventory, with conservative release semantics. */
class Ipam
{
    public function import(EngineNode $node, string $cidr, string $gateway, int $maxAddresses = 4096): EngineSubnet
    {
        if (! preg_match('/\A([0-9.]+)\/(\d{1,2})\z/', $cidr, $match)
            || ! $this->publicIpv4($match[1]) || ! $this->publicIpv4($gateway)) {
            throw new \DomainException('A public IPv4 subnet and gateway are required.');
        }
        $prefix = (int) $match[2];
        // /31 point-to-point and /32 host routes need a different guest networking contract.
        if ($prefix < 20 || $prefix > 30 || $maxAddresses < 1 || $maxAddresses > 4096) {
            throw new \DomainException('Routed subnet imports support /20 through /30 and at most 4096 addresses.');
        }
        $size = 1 << (32 - $prefix);
        $first = (int) sprintf('%u', ip2long($match[1]));
        if ($first % $size !== 0 || $size > $maxAddresses) {
            throw new \DomainException('Use a canonical network address within the import limit.');
        }
        $gatewayNumber = (int) sprintf('%u', ip2long($gateway));
        if ($gatewayNumber <= $first || $gatewayNumber >= $first + $size - 1) {
            throw new \DomainException('The routed bridge gateway must be a usable host inside its assigned subnet.');
        }
        $addresses = [];
        for ($offset = 1; $offset < $size - 1; $offset++) {
            $ip = long2ip($first + $offset);
            if (! $this->publicIpv4($ip)) {
                throw new \DomainException('Every imported address must be public IPv4.');
            }
            if ($ip !== $gateway) {
                $addresses[] = $ip;
            }
        }

        return DB::transaction(function () use ($node, $cidr, $gateway, $addresses) {
            EngineNode::whereKey($node->id)->lockForUpdate()->firstOrFail();
            $existing = EngineSubnet::where('cidr', $cidr)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->node_id !== (int) $node->id || $existing->gateway !== $gateway) {
                    throw new \DomainException('The subnet is already assigned with different routing.');
                }

                return $existing;
            }
            if (EngineAddress::whereIn('address', $addresses)->exists()) {
                throw new \DomainException('The subnet overlaps existing IPv4 inventory.');
            }
            $subnet = EngineSubnet::create(['node_id' => $node->id, 'cidr' => $cidr, 'gateway' => $gateway]);
            foreach ($addresses as $ip) {
                $address = EngineAddress::create([
                    'subnet_id' => $subnet->id, 'node_id' => $node->id, 'address' => $ip, 'state' => 'free',
                ]);
                EngineIpEvent::create(['address_id' => $address->id, 'action' => 'imported', 'evidence' => ['cidr' => $cidr]]);
            }

            return $subnet;
        }, 3);
    }

    /** Called inside placement's service -> node transaction. */
    public function reserveLocked(EngineNode $node, EngineVm $vm): EngineAddress
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('IPv4 allocation requires the placement transaction and node lock.');
        }
        $address = EngineAddress::where('node_id', $node->id)->where('state', 'free')
            ->whereNull('service_id')->whereNull('vm_id')
            ->whereHas('subnet', fn ($q) => $q->where('node_id', $node->id)->where('enabled', true))
            ->orderBy('id')->lockForUpdate()->first();
        if (! $address) {
            throw new \DomainException('No accepted routed IPv4 address is available.');
        }
        $address->forceFill([
            'state' => 'allocated', 'service_id' => $vm->service_id,
            'vm_id' => $vm->id, 'allocated_at' => now(), 'quarantine_reason' => null,
        ])->save();
        EngineIpEvent::create([
            'address_id' => $address->id, 'service_id' => $vm->service_id,
            'vm_id' => $vm->id, 'action' => 'allocated', 'evidence' => ['vm_uuid' => $vm->uuid],
        ]);

        return $address;
    }

    /** Internal worker boundary: the caller must supply authoritative deletion evidence. */
    public function releaseVerified(EngineVm $vm, array $proof): void
    {
        try {
            $verified = Carbon::parse($proof['verified_at'] ?? 'invalid');
        } catch (\Throwable) {
            throw new \DomainException('A current deletion observation is required.');
        }
        if (($proof['uuid'] ?? null) !== $vm->uuid || (int) ($proof['node_id'] ?? 0) !== (int) $vm->node_id
            || (int) ($proof['vmid'] ?? 0) !== (int) $vm->vmid || ($proof['absent'] ?? null) !== true
            || ($proof['network_clear'] ?? null) !== true || ($proof['console_clear'] ?? null) !== true
            || $verified->lt(now()->subMinutes(5)) || $verified->gt(now()->addSeconds(30))) {
            throw new \DomainException('Deletion and dependency absence must be verified for this exact VM.');
        }
        DB::transaction(function () use ($vm, $proof) {
            Service::whereKey($vm->service_id)->lockForUpdate()->firstOrFail();
            EngineNode::whereKey($vm->node_id)->lockForUpdate()->firstOrFail();
            $fresh = EngineVm::whereKey($vm->id)->lockForUpdate()->firstOrFail();
            if (! in_array($fresh->status, ['deleting', 'deleted'], true)
                || $fresh->uuid !== $proof['uuid'] || (int) $fresh->vmid !== (int) $proof['vmid']
                || (int) $fresh->node_id !== (int) $proof['node_id']) {
                throw new \DomainException('The VM is not awaiting verified deletion.');
            }
            $address = EngineAddress::whereKey($fresh->address_id)->lockForUpdate()->first();
            if (! $address) {
                throw new \DomainException('The VM address reservation is missing.');
            }
            // A replay after successful release is safe, but a reallocated address is never touched.
            if ($address->state === 'free' && $address->vm_id === null && $address->service_id === null) {
                if (EngineIpEvent::where('address_id', $address->id)->where('vm_id', $fresh->id)
                    ->where('action', 'released')->exists()) {
                    return;
                }
            }
            if ((int) $address->vm_id !== (int) $fresh->id || (int) $address->service_id !== (int) $fresh->service_id) {
                throw new \DomainException('Address ownership changed; release is refused.');
            }
            EngineIpEvent::create([
                'address_id' => $address->id, 'service_id' => $fresh->service_id,
                'vm_id' => $fresh->id, 'action' => 'released', 'evidence' => $proof,
            ]);
            $address->forceFill([
                'state' => 'free', 'service_id' => null, 'vm_id' => null,
                'allocated_at' => null, 'quarantine_reason' => null,
            ])->save();
            $fresh->forceFill(['status' => 'deleted', 'metadata' => array_merge($fresh->metadata ?? [], ['deletion_proof' => $proof])])->save();
        }, 3);
    }

    private function publicIpv4(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $n = (int) sprintf('%u', ip2long($ip));
        foreach ([['0.0.0.0', 8], ['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24],
            ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4]] as [$network, $bits]) {
            $mask = (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;
            if (($n & $mask) === ((int) sprintf('%u', ip2long($network)) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
