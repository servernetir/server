<?php

namespace App\Services\Provisioning;

use App\Models\Service;

/**
 * درایورِ Plesk — ساختِ خودکارِ نماینده یا مشتری روی سرورِ Plesk.
 *
 * ⚠️ **آزمایش‌نشده روی سرورِ Pleskِ واقعی.** تا وقتی
 * `provisioning.plesk_auto` روشن نشود، `Server::isAutoProvisioned()` سرورِ
 * Plesk را دستی می‌شمارد و این کلاس اصلاً صدا زده نمی‌شود. پیش از روشن‌کردن،
 * یک خریدِ آزمایشی روی سرورِ واقعی بزنید.
 *
 * idempotent مثلِ درایورهای WHM و DirectAdmin، با همان قاعدهٔ سه‌حالته:
 * «هست» / «نیست» / «نتوانستیم بپرسیم» — و سومی هرگز به دومی ترجمه نمی‌شود.
 */
class PleskProvisioner implements Provisioner
{
    public function slug(): string
    {
        return 'plesk';
    }

    public function create(Service $service): ProvisionResult
    {
        $server = $service->server;
        if (! $server) {
            return ProvisionResult::fail('سروری برای این سرویس تعیین نشده است.');
        }

        $user = (string) $service->username;
        if ($user === '') {
            return ProvisionResult::fail('نام‌کاربری برای ساختِ حساب مشخص نیست.');
        }

        $client = new PleskClient($server);
        $reseller = (bool) $service->is_reseller;
        $panelUrl = 'https://'.$server->hostname.':'.$server->effectivePort();

        // ── idempotency ────────────────────────────────────────────────────
        $state = $client->loginExists($user, $reseller);

        if ($state === true) {
            return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl, ['reused' => true]);
        }

        $customer = $service->customer;
        $params = [
            'login'    => $user,
            'password' => (string) $service->password,
            'email'    => (string) ($customer->email ?? ''),
            'name'     => (string) ($customer?->displayName() ?: $user),
            'plan'     => $reseller ? (string) $service->plan : null,
        ];

        $res = $reseller ? $client->createReseller($params) : $client->createCustomer($params);

        if (! $res['ok']) {
            // همان تعمیرِ zhina.shop: بعد از شکست دوباره بپرس.
            $after = $client->loginExists($user, $reseller);

            if ($after === true) {
                return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl, [
                    'reused'               => true,
                    'verified_after_error' => mb_substr((string) $res['reason'], 0, 160),
                ]);
            }

            if ($after === null) {
                return ProvisionResult::manual(
                    'وضعیتِ حساب روی Plesk قابلِ استعلام نبود؛ تا پاسخِ سرور در صفِ بررسی می‌مانَد. '
                    .mb_substr((string) $res['reason'], 0, 120)
                );
            }

            return ProvisionResult::fail($res['reason'] ?: 'ساختِ حساب روی Plesk ناموفق بود.');
        }

        return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl,
            $reseller ? ['reseller' => true, 'plan' => $service->plan] : []);
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

        $client = new PleskClient($server);
        $reseller = (bool) $service->is_reseller;

        $res = match ($action) {
            'suspend'   => $client->setStatus($user, $reseller, false),
            'unsuspend' => $client->setStatus($user, $reseller, true),
            'terminate' => $client->delete($user, $reseller),
        };

        // حذفِ حسابِ ازقبل‌نبوده idempotent است — ولی فقط وقتی **مطمئن** باشیم
        // که نیست. `null` (نتوانستیم بپرسیم) نباید «موفق» خوانده شود، وگرنه
        // سرویس بسته می‌شود در حالی که حسابِ نماینده زنده روی سرور مانده.
        if (! $res['ok'] && $action === 'terminate' && $client->loginExists($user, $reseller) === false) {
            return ProvisionResult::success($user, null, null, ['already_gone' => true]);
        }

        return $res['ok']
            ? ProvisionResult::success($user, null, null, (array) $res['data'])
            : ProvisionResult::fail($res['reason']);
    }
}
