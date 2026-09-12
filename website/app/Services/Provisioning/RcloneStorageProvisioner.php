<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Support\ErrorTracker;

/** تحویلِ فضای بکاپ چندمستاجری از طریق Gateway مستقلِ rclone. */
class RcloneStorageProvisioner implements Provisioner
{
    public function slug(): string
    {
        return 'rclone_storage';
    }

    public static function tenantId(Service $service): string
    {
        return 'sn-svc-'.$service->id;
    }

    public function create(Service $service): ProvisionResult
    {
        $server = $service->server;
        if (! $server) {
            return ProvisionResult::fail('Gateway این سرویس مشخص نیست.');
        }

        $quota = $this->quotaForPlan((string) $service->plan);
        if ($quota === null) {
            ErrorTracker::noteOnce('provision', 'سهمیهٔ rclone برای پلن «'.$service->plan.'» تعریف نشده — سرویس '.$service->id);

            return ProvisionResult::manual('سهمیهٔ پلن «'.$service->plan.'» در تنظیمات Gateway تعریف نشده است.');
        }

        $client = new RcloneStorageClient($server);
        $tenantId = self::tenantId($service);
        $existing = $client->tenantState($tenantId);

        if ($existing === null) {
            return ProvisionResult::fail('وضعیت فضای موجود خوانده نشد؛ برای جلوگیری از تحویل دوگانه متوقف شد.');
        }

        if (is_array($existing)) {
            $knownPassword = (string) ($service->provision_meta['gateway_password'] ?? '');

            if ($knownPassword === '') {
                $knownPassword = $this->makePassword();
                $rotated = $client->rotatePassword($tenantId, $knownPassword);
                if (! $rotated['ok']) {
                    return ProvisionResult::fail('فضای قبلی پیدا شد اما بازیابیِ رمز آن ناموفق بود: '.$rotated['reason']);
                }
            }

            return $this->adopt($service, $existing, true, $knownPassword);
        }

        $password = $this->makePassword();
        $result = $client->createTenant($tenantId, [
            'username' => 'sn'.$service->id,
            'password' => $password,
            'quota_bytes' => $quota,
            'pool' => (string) config('provisioning.rclone_storage.pool', 'google'),
            'customer_ref' => (string) $service->customer_id,
        ]);

        if (! $result['ok']) {
            if ($result['transport']) {
                $after = $client->tenantState($tenantId);
                if (is_array($after)) {
                    return $this->adopt($service, $after, true, $password);
                }
            }

            return ProvisionResult::fail('ساخت فضای بکاپ ناموفق بود: '.$result['reason']);
        }

        $tenant = $result['data']['tenant'] ?? null;
        if (! is_array($tenant) || blank($tenant['username'] ?? null) || blank($tenant['endpoint'] ?? null)) {
            return ProvisionResult::fail('Gateway پاسخ موفق داد اما اطلاعات اتصال کامل نبود.');
        }

        return $this->adopt($service, $tenant, false, $password);
    }

    public function suspend(Service $service): ProvisionResult
    {
        return $this->toggle($service, true);
    }

    public function unsuspend(Service $service): ProvisionResult
    {
        return $this->toggle($service, false);
    }

    public function terminate(Service $service): ProvisionResult
    {
        if (! $service->server) {
            return ProvisionResult::fail('Gateway این سرویس مشخص نیست.');
        }

        $days = max(1, (int) config('provisioning.rclone_storage.termination_retention_days', 30));
        $r = (new RcloneStorageClient($service->server))->retireTenant(self::tenantId($service), $days);

        if ($r['ok'] || $r['status'] === 404) {
            return ProvisionResult::success(null, null, null, ['retired' => true, 'retention_days' => $days]);
        }

        return ProvisionResult::fail('قرنطینه‌کردن فضای بکاپ ناموفق بود: '.$r['reason']);
    }

    private function toggle(Service $service, bool $suspended): ProvisionResult
    {
        if (! $service->server) {
            return ProvisionResult::fail('Gateway این سرویس مشخص نیست.');
        }

        $r = (new RcloneStorageClient($service->server))->setSuspended(self::tenantId($service), $suspended);

        return $r['ok']
            ? ProvisionResult::success((string) $service->username ?: null, null, null, ['suspended' => $suspended])
            : ProvisionResult::fail(($suspended ? 'تعلیق' : 'رفع تعلیق').' فضای بکاپ ناموفق بود: '.$r['reason']);
    }

    private function adopt(Service $service, array $tenant, bool $reused, ?string $password = null): ProvisionResult
    {
        $password ??= (string) ($service->provision_meta['gateway_password'] ?? '');
        $endpoint = (string) ($tenant['endpoint'] ?? '');
        $username = (string) ($tenant['username'] ?? '');

        if ($endpoint === '' || $username === '' || $password === '') {
            return ProvisionResult::fail('اطلاعات اتصال Gateway کامل نیست؛ سرویس تحویل‌شده اعلام نشد.');
        }

        $port = (int) ($tenant['port'] ?? 2022);

        return ProvisionResult::success($username, $password, 'sftp://'.$endpoint.':'.$port, [
            'driver' => $this->slug(),
            'rclone_tenant_id' => (string) ($tenant['id'] ?? self::tenantId($service)),
            'gateway_password' => $password,
            'quota_bytes' => (int) ($tenant['quota_bytes'] ?? $this->quotaForPlan((string) $service->plan)),
            'used_bytes' => (int) ($tenant['used_bytes'] ?? 0),
            'pool' => (string) ($tenant['pool'] ?? config('provisioning.rclone_storage.pool', 'google')),
            'protocol' => 'sftp',
            'host' => $endpoint,
            'port' => $port,
            'reused' => $reused,
        ]);
    }

    private function quotaForPlan(string $plan): ?int
    {
        $quota = config('provisioning.rclone_storage.plans.'.$plan);

        return is_numeric($quota) && (int) $quota > 0 ? (int) $quota : null;
    }

    private function makePassword(int $len = 28): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
