<?php

namespace App\Services\Provisioning;

use App\Models\Service;

/**
 * درایورِ DirectAdmin — ساختِ خودکارِ حسابِ هاست روی سرورِ DirectAdmin.
 *
 * idempotent مثلِ درایورِ WHM: اگر کاربر از قبل باشد، دوباره نمی‌سازد.
 */
class DirectAdminProvisioner implements Provisioner
{
    public function slug(): string
    {
        return 'directadmin';
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
            return ProvisionResult::fail('نام‌کاربری یا دامنه مشخص نیست.');
        }

        $client = new DirectAdminClient($server);
        $panelUrl = 'https://'.$server->hostname.':'.$server->effectivePort();
        $reseller = (bool) $service->is_reseller;

        if ($client->userExists($user)) {
            return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl, ['reused' => true]);
        }

        $params = [
            'username' => $user,
            'email'    => (string) ($service->customer->email ?? ''),
            'passwd'   => (string) $service->password,
            'passwd2'  => (string) $service->password,
            'domain'   => $domain,
            'package'  => $service->plan ?: '',
        ];
        // ⚠️ `ip` فقط برای کاربرِ عادی از سرور می‌آید؛ `createReseller()` خودش
        // `ip=shared` می‌گذارد و IPِ نودِ ما آن‌جا معنای دیگری دارد.
        if ($server->server_ip && ! $reseller) {
            $params['ip'] = $server->server_ip;
        }

        $res = $reseller ? $client->createReseller($params) : $client->createAccount($params);

        if (! $res['ok']) {
            /*
            | 🔴 همان تعمیرِ zhina.shop، این‌بار روی DirectAdmin: بعد از شکست
            | یک بار دیگر از سرور بپرس. تایم‌اوت/قطعیِ گذرا حساب را می‌سازد و
            | ما به مشتری «ناموفق» می‌گفتیم در حالی که پنلش زنده بود.
            */
            if ($client->userExists($user)) {
                return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl, [
                    'reused'               => true,
                    'verified_after_error' => mb_substr((string) $res['reason'], 0, 160),
                ]);
            }

            return ProvisionResult::fail($res['reason'] ?: 'ساختِ حساب روی DirectAdmin ناموفق بود.', ['raw' => $res['raw']]);
        }

        return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl,
            (array) $res['data'] + ($reseller ? ['reseller' => true] : []));
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

        $client = new DirectAdminClient($server);
        $res = match ($action) {
            'suspend'   => $client->suspend($user),
            'unsuspend' => $client->unsuspend($user),
            'terminate' => $client->terminate($user),
        };

        if (! $res['ok'] && $action === 'terminate' && ! $client->userExists($user)) {
            return ProvisionResult::success($user, null, null, ['already_gone' => true]);
        }

        return $res['ok']
            ? ProvisionResult::success($user, null, null, $res['data'])
            : ProvisionResult::fail($res['reason']);
    }
}
