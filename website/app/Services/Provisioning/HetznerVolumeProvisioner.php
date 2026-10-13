<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Services\Cloud\HetznerClient;

class HetznerVolumeProvisioner implements Provisioner
{
    public function slug(): string { return 'hetzner_volume'; }

    public static function volumeName(Service $service): string { return 'sn-volume-'.$service->id; }

    public function create(Service $service): ProvisionResult
    {
        $offer = (array) data_get($service->provision_meta, 'volume_offer', []);
        $expected = app(HetznerVolumeCosts::class)->metadataForPlan((string) $service->plan);
        $size = (int) ($offer['size_gb'] ?? $expected['size_gb'] ?? 0);
        $location = (string) ($offer['location'] ?? $expected['location'] ?? '');
        if (! in_array($size, HetznerVolumeCosts::SIZES, true) || ! in_array($location, HetznerVolumeCosts::LOCATIONS, true)) {
            return ProvisionResult::manual('اندازه یا منطقهٔ Volume با کاتالوگ تطابق ندارد.');
        }
        $client = app(HetznerClient::class); $name = self::volumeName($service);
        $state = $client->findVolumeByName($name);
        if (! $state['ok']) return ProvisionResult::fail('وضعیت Volume موجود خوانده نشد؛ ساخت دوباره متوقف شد.');
        $volume = $state['volume']; $reused = is_array($volume);
        if (! $volume) {
            $res = $client->createVolume(['name' => $name, 'size' => $size, 'location' => $location,
                'labels' => ['service' => (string) $service->id, 'customer' => (string) $service->customer_id, 'source' => 'servernet']]);
            if (! $res['ok'] || ! is_array($res['volume'])) return ProvisionResult::fail('ساخت Block Storage ناموفق بود: '.$res['message']);
            $volume = $res['volume']; $reused = (bool) ($res['reused'] ?? false);
        }
        if ((int) ($volume['size'] ?? 0) !== $size || (string) data_get($volume, 'location.name') !== $location) {
            return ProvisionResult::manual('Volume موجود با سفارش تطابق ندارد؛ تغییری انجام نشد.');
        }
        $ref = (string) ($volume['id'] ?? ''); $device = (string) ($volume['linux_device'] ?? '');
        if ($ref === '' || $device === '') return ProvisionResult::fail('Volume ساخته شد ولی هنوز شناسهٔ اتصال کامل نگرفته است.');
        return ProvisionResult::success($ref, null, null, ['driver' => $this->slug(), 'volume_ref' => $ref,
            'linux_device' => $device, 'size_gb' => $size, 'location' => $location,
            'attached_server_ref' => data_get($volume, 'server'), 'reused' => $reused]);
    }

    public function suspend(Service $service): ProvisionResult
    {
        return ProvisionResult::success((string) $service->username ?: null, null, null,
            ['billing_suspend' => true, 'volume_retained' => true]);
    }

    public function unsuspend(Service $service): ProvisionResult
    {
        return ProvisionResult::success((string) $service->username ?: null, null, null, ['volume_retained' => true]);
    }

    public function terminate(Service $service): ProvisionResult
    {
        $ref = (string) data_get($service->provision_meta, 'volume_ref', $service->username);
        if ($ref === '') return ProvisionResult::fail('شناسهٔ Volume ثبت نشده است.');
        $r = app(HetznerClient::class)->deleteVolume($ref);
        return $r['ok'] ? ProvisionResult::success(null, null, null, ['deleted' => true])
            : ProvisionResult::fail('حذف Volume نزد هتزنر تأیید نشد: '.$r['message']);
    }
}
