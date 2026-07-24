<?php

namespace App\Services\Provisioning;

use App\Models\Service;

/**
 * درایورِ WHM/cPanel — ساختِ خودکارِ حسابِ هاست روی سرورِ WHM.
 *
 * idempotent: اگر حساب با همان نام‌کاربری از قبل روی سرور باشد، دوباره
 * نمی‌سازد و همان را موفق می‌شمارد (تا اجرای دوبارهٔ کرون یا پرداختِ تکراری،
 * حسابِ دوتایی نسازد).
 */
class WhmProvisioner implements Provisioner
{
    public function slug(): string
    {
        return 'whm';
    }

    public function create(Service $service): ProvisionResult
    {
        $server = $service->server;
        if (! $server) {
            return ProvisionResult::fail('سروری برای این سرویس تعیین نشده است.');
        }

        $user = (string) $service->username;
        $domain = (string) $service->domain;

        if ($user === '' || $domain === '') {
            return ProvisionResult::fail('نام‌کاربری یا دامنه برای ساختِ حساب مشخص نیست.');
        }

        $client = new WhmClient($server);
        $panelUrl = 'https://'.$server->hostname.':2083';

        // idempotency: اگر حساب هست، دوباره نساز
        if ($client->accountExists($user)) {
            return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl, ['reused' => true]);
        }

        $res = $client->createAccount([
            'username'     => $user,
            'domain'       => $domain,
            'plan'         => $service->plan ?: 'default',
            'password'     => (string) $service->password,
            'contactemail' => (string) ($service->customer->email ?? ''),
        ]);

        if (! $res['ok']) {
            return ProvisionResult::fail($res['reason'] ?: 'ساختِ حساب روی WHM ناموفق بود.', $res['raw']);
        }

        return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl, $res['data']);
    }

    public function suspend(Service $service): ProvisionResult
    {
        return $this->lifecycle($service, 'suspend');
    }

    public function unsuspend(Service $service): ProvisionResult
    {
        return $this->lifecycle($service, 'unsuspend');
    }

    public function terminate(Service $service): ProvisionResult
    {
        return $this->lifecycle($service, 'terminate');
    }

    private function lifecycle(Service $service, string $action): ProvisionResult
    {
        $server = $service->server;
        $user = (string) $service->username;

        if (! $server || $user === '') {
            return ProvisionResult::fail('سرور یا نام‌کاربری مشخص نیست.');
        }

        $client = new WhmClient($server);
        $res = match ($action) {
            'suspend'   => $client->suspend($user, 'suspended via ServerNet panel'),
            'unsuspend' => $client->unsuspend($user),
            'terminate' => $client->terminate($user),
        };

        // terminate روی حسابِ ازقبل‌نبوده هم قابلِ قبول است (idempotent)
        if (! $res['ok'] && $action === 'terminate' && ! $client->accountExists($user)) {
            return ProvisionResult::success($user, null, null, ['already_gone' => true]);
        }

        return $res['ok']
            ? ProvisionResult::success($user, null, null, $res['data'])
            : ProvisionResult::fail($res['reason']);
    }
}
