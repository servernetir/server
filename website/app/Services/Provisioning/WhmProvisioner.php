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
        if ($client->accountState($user, $domain) === true) {
            return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl, ['reused' => true]);
        }

        /*
        | ⚠️ `null` (نتوانستیم بپرسیم) جلوی ساخت را **نمی‌گیرد**: یک قطعیِ گذرا
        | در لحظهٔ استعلام نباید فروش را بخواباند. بی‌خطر است چون نام‌کاربری
        | قطعی و از پیش ذخیره‌شده است، پس اگر حساب واقعاً باشد WHM خودش
        | «این نام از قبل هست» می‌دهد و شاخهٔ پایین همان را می‌گیرد.
        */
        $res = $client->createAccount([
            'username'     => $user,
            'domain'       => $domain,
            'plan'         => $service->plan ?: 'default',
            'password'     => (string) $service->password,
            'contactemail' => (string) ($service->customer->email ?? ''),
        ]);

        if (! $res['ok']) {
            /*
            | 🔴 نیمهٔ گم‌شده‌ای که رخدادِ zhina.shop از نبودش آمد.
            |
            | `createacct` روی نودِ شلوغ از ۳۰ ثانیه رد می‌شود؛ ما تایم‌اوت
            | می‌خوردیم، WHM حساب را **می‌ساخت**، و ما به مشتری می‌گفتیم تحویل
            | ناموفق بوده و می‌تواند لغو و پولش را پس بگیرد — در حالی که
            | cPanelش زنده روی سرور بود.
            |
            | علتِ ریشه‌ای «تایم‌اوت» نبود؛ این بود که **بعد از شکست هیچ‌وقت
            | دوباره از سرور نمی‌پرسیدیم**. همان تعمیری که `terminate()` ده خط
            | پایین‌تر از قبل داشت (حذفِ حسابِ ازقبل‌نبوده = موفق) و `create`
            | نداشت. یک GETِ بی‌عارضه، و «نمی‌دانم» به «می‌دانم» تبدیل می‌شود.
            |
            | ⚠️ ترتیب مهم است: اول تطبیق (نام + دامنه + معلق‌نبودن)، بعد ادعا.
            */
            $after = $client->accountState($user, $domain);

            if ($after === true) {
                return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl, [
                    'reused'               => true,
                    'verified_after_error' => mb_substr((string) $res['reason'], 0, 160),
                ]);
            }

            if ($after === null) {
                /*
                | نه ساختیم نه مطمئنیم که نساختیم. این حالت **نباید** به مشتری
                | «ناموفق» بگوید (شاید حسابش آماده باشد) و نباید هم «تحویل شد»
                | بگوید. صفِ دستیِ مدیر تنها جای صادقانه است: مشتری «در حالِ
                | آماده‌سازی» می‌بیند، و `provision:verify-failed` هر چند دقیقه
                | دوباره می‌پرسد تا خودش حل شود.
                */
                return ProvisionResult::manual(
                    'وضعیتِ حساب روی سرور قابلِ استعلام نبود؛ تا پاسخِ سرور در صفِ بررسی می‌مانَد. '
                    .mb_substr((string) $res['reason'], 0, 120)
                );
            }

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
