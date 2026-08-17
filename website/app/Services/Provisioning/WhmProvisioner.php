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
        $reseller = (bool) $service->is_reseller;

        // نماینده وارد **WHM** می‌شود (۲۰۸۷)، نه cPanel (۲۰۸۳). آدرسِ غلط یعنی
        // مشتری‌ای که پنلِ نمایندگی خریده و صفحهٔ یک هاستِ ساده می‌بیند.
        $panelUrl = 'https://'.$server->hostname.':'.($reseller ? '2087' : '2083');

        // idempotency: اگر حساب هست، دوباره نساز
        if ($client->accountState($user, $domain) === true) {
            /*
            | ⚠️ «حساب هست» برای نماینده کافی **نیست**. اگر تلاشِ قبلی بینِ
            | `createacct` و `setresellerlimits` نصفه مانده باشد، حساب هست ولی
            | نماینده نیست یا بی‌سقف است — و ما «استفادهٔ دوباره» ثبت می‌کردیم
            | و برای همیشه از کنارش رد می‌شدیم. پس گام‌های نمایندگی دوباره
            | اجرا می‌شوند؛ هر سه idempotentاند.
            */
            $meta = ['reused' => true];
            if ($reseller) {
                $meta += $this->applyResellerSetup($client, $service, $user);
            }

            return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl, $meta);
        }

        /*
        | ⚠️ `null` (نتوانستیم بپرسیم) جلوی ساخت را **نمی‌گیرد**: یک قطعیِ گذرا
        | در لحظهٔ استعلام نباید فروش را بخواباند. بی‌خطر است چون نام‌کاربری
        | قطعی و از پیش ذخیره‌شده است، پس اگر حساب واقعاً باشد WHM خودش
        | «این نام از قبل هست» می‌دهد و شاخهٔ پایین همان را می‌گیرد.
        */
        $res = $client->createAccount(array_filter([
            'username'     => $user,
            'domain'       => $domain,
            'plan'         => $service->plan ?: 'default',
            'password'     => (string) $service->password,
            'contactemail' => (string) ($service->customer->email ?? ''),
            // `reseller=1` فقط بیتِ نمایندگی را می‌گذارد؛ ACL و سقف دو تماسِ
            // جدا هستند و در applyResellerSetup() می‌آیند.
            'reseller'     => $reseller ? 1 : null,
        ], fn ($v) => $v !== null));

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
                $meta = [
                    'reused'               => true,
                    'verified_after_error' => mb_substr((string) $res['reason'], 0, 160),
                ];
                // حساب ساخته شده ولی تایم‌اوت نگذاشت گام‌های نمایندگی اجرا
                // شوند — دقیقاً همان حالتی که «نمایندهٔ بی‌ACL و بی‌سقف» از آن
                // درمی‌آید. این‌جا جبرانش می‌کنیم، نه در اجرای بعدیِ کرون.
                if ($reseller) {
                    $meta += $this->applyResellerSetup($client, $service, $user);
                }

                return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl, $meta);
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

        $meta = (array) $res['data'];
        if ($reseller) {
            $meta += $this->applyResellerSetup($client, $service, $user);
        }

        return ProvisionResult::success($user, (string) $service->password ?: null, $panelUrl, $meta);
    }

    /**
     * دو گامِ بعد از `createacct`: مجوزها و سقفِ منابع.
     *
     * ═══ 🔴 چرا شکستِ این دو، تحویل را شکست‌خورده **نمی‌کند** ═══
     *
     * در این نقطه حسابِ نماینده روی سرور **ساخته شده** و رمزش دستِ ماست. اگر
     * این‌جا `fail` برگردانیم، مشتری «تحویل ناموفق» می‌بیند و می‌تواند لغو کند
     * و پولش را پس بگیرد — در حالی که حسابش زنده روی نود است. همان الگوی
     * دقیقِ رخدادِ zhina.shop، فقط یک گام جلوتر.
     *
     * ⚠️ ولی سکوت هم جواب نیست: نمایندهٔ بی‌سقف می‌تواند نود را پر کند.
     * نتیجه در `provision_meta` می‌نشیند و شکست علاوه بر آن در ردیابِ خطا
     * ثبت می‌شود، پس در `/admin/errors` دیده می‌شود بی‌آنکه فروش بخوابد.
     *
     * @return array<string,mixed>
     */
    private function applyResellerSetup(WhmClient $client, Service $service, string $user): array
    {
        $meta = [];

        // ── مجوزها ─────────────────────────────────────────────────────────
        $acl = (string) (config('provisioning.reseller_acl') ?: '');
        if ($acl !== '') {
            $aclRes = $client->setResellerAcl($user, $acl);
            $meta['reseller_acl'] = $aclRes['ok'] ? $acl : 'failed: '.mb_substr((string) $aclRes['reason'], 0, 120);
            if (! $aclRes['ok']) {
                $this->shout($service, 'setacls', $user, (string) $aclRes['reason']);
            }
        } else {
            // بی‌ACL، نماینده وارد WHM می‌شود و هیچ دکمه‌ای ندارد. این را
            // نمی‌شود بی‌صدا رد کرد.
            $meta['reseller_acl'] = 'not-configured';
            $this->shout($service, 'setacls', $user, 'provisioning.reseller_acl تنظیم نشده — نماینده بدونِ مجوز ساخته شد.');
        }

        // ── سقفِ منابع ──────────────────────────────────────────────────────
        $limits = ResellerLimits::forService($service);
        $meta['reseller_limits_source'] = $limits['source'];

        if ($limits['source'] === 'unknown') {
            // «ندانستیم» ≠ «نامحدود». سقف نمی‌گذاریم چون عددی نداریم، ولی
            // فریاد می‌زنیم تا مدیر دستی ببندد.
            $meta['reseller_limits'] = 'unknown';
            $this->shout($service, 'setresellerlimits', $user,
                'سقفِ نمایندگی از مشخصاتِ پکیج «'.$service->plan.'» درنیامد — نماینده بی‌سقف ماند.');

            return $meta;
        }

        // 0 در خروجیِ ResellerLimits یعنی «نامحدودِ صریح» ⇒ سقف نگذار (null)
        $accounts = ($limits['accounts'] ?? 0) > 0 ? $limits['accounts'] : null;
        $disk = ($limits['disk_mb'] ?? 0) > 0 ? $limits['disk_mb'] : null;
        $bw = ($limits['bw_mb'] ?? 0) > 0 ? $limits['bw_mb'] : null;

        $limRes = $client->setResellerLimits($user, $accounts, $disk, $bw);
        $meta['reseller_limits'] = $limRes['ok']
            ? ['accounts' => $accounts, 'disk_mb' => $disk, 'bw_mb' => $bw]
            : 'failed: '.mb_substr((string) $limRes['reason'], 0, 120);

        if (! $limRes['ok']) {
            $this->shout($service, 'setresellerlimits', $user, (string) $limRes['reason']);
        }

        return $meta;
    }

    /**
     * ثبتِ خرابیِ نیمه‌کارهٔ نمایندگی — تحویل را نمی‌خواباند، ولی ساکت هم نمی‌ماند.
     *
     * ⚠️ شناسهٔ سرویس **داخلِ خودِ متن** است، چون `noteOnce` کلیدِ گلوگاه را از
     * md5ِ همین متن می‌سازد. بی‌آن، نمایندهٔ دومی که ده دقیقه بعد خراب شود پشتِ
     * گلوگاهِ اولی می‌ماند و هیچ ردی نمی‌گذارد — همان درسِ امضای وضعیت.
     */
    private function shout(Service $service, string $step, string $user, string $reason): void
    {
        \App\Support\ErrorTracker::noteOnce('provision',
            'گامِ نمایندگی «'.$step.'» برای سرویس #'.$service->id.' (کاربر '.$user.') انجام نشد: '
            .mb_substr($reason, 0, 200));
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
