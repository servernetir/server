<?php

namespace App\Services\Cloud\Engine;

use App\Models\CloudSshKey;
use App\Models\Service;

/** Proxmox consumes public keys inline; references stay bound to the paid owner. */
class EngineSshKeys
{
    public function upload(string $publicKey): array
    {
        $key = strlen($publicKey) <= 16384 ? CloudSshKey::inspect($publicKey) : ['ok' => false];
        if (! $key['ok']) return ['ok' => false, 'message' => 'A valid public SSH key is required.', 'ref' => null];
        return ['ok' => true, 'message' => '', 'ref' => 'ssh:'.hash('sha256', $key['normalized'])];
    }

    public function resolve(Service $service, array $references): array
    {
        if (! $service->cloud_ssh_key_id) {
            if ($references !== []) throw new \DomainException('ssh_key_not_owned');
            return [];
        }
        $key = CloudSshKey::whereKey($service->cloud_ssh_key_id)->where('customer_id', $service->customer_id)->first();
        $inspection = $key ? CloudSshKey::inspect($key->public_key) : ['ok' => false];
        $expected = $inspection['ok'] ? 'ssh:'.hash('sha256', $inspection['normalized']) : null;
        if ($expected === null || $references !== [$expected]) throw new \DomainException('ssh_key_not_owned');
        return [$inspection['normalized']];
    }
}
