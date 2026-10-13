<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\DB;

/**
 * تحویلِ خودکارِ فضای بکاپ/دانلود روی Storage Boxِ هتزنر.
 *
 * جایگزینِ تحویلِ دستیِ همان پکیج‌ها از Proxmoxِ خودمان — تا وقتی سرورِ تازه
 * خریده شود. یک باکس به‌ازای هر سرویس (قیمت‌گذاریِ هتزنر هم per-box است).
 *
 * سه محافظِ «دو بار نخر»، همان سه‌تایی که برای سرورِ ابری نوشته شد:
 *   ۱) نامِ **قطعی** `sn-svc-{id}` — نه تصادفی
 *   ۲) پیش از هر ساخت، `boxState()` می‌پرسد و باکسِ موجود را می‌پذیرد
 *   ۳) «نپرسیدیم» (`null`) هرگز «نیست» خوانده نمی‌شود
 */
class HetznerStorageProvisioner implements Provisioner
{
    public function slug(): string
    {
        return 'hetzner_storage';
    }

    /** نامِ قطعیِ باکس — تنها چیزی که تلاشِ دوباره را از خریدِ دوباره جدا می‌کند */
    public static function boxName(Service $service): string
    {
        return 'sn-svc-'.$service->id;
    }

    public function create(Service $service): ProvisionResult
    {
        $server = $service->server;
        if (! $server) {
            return ProvisionResult::fail('سروری برای این سرویس تعیین نشده است.');
        }

        $plan = (string) $service->plan;
        $soldOffer = (array) ($service->provision_meta['storage_box_offer'] ?? []);
        $type = $soldOffer['type'] ?? $this->typeForPlan($plan);
        $location = $soldOffer['location'] ?? $this->location($plan);

        /*
        | 🔴 «نمی‌دانیم کدام نوع» هرگز نباید به یک نوعِ پیش‌فرض سقوط کند.
        |
        | نوعِ اشتباه یعنی اندازهٔ اشتباه و بهایِ تمام‌شدهٔ اشتباه: یا به مشتری
        | فضای کمتر از خریدش می‌دهیم، یا باکسی چند برابرِ گران‌تر می‌خریم و
        | تفاوتش را تا ماه‌ها کسی نمی‌بیند. همان قاعدهٔ `ResellerLimits`:
        | «ندانستیم» ≠ «پیش‌فرض».
        */
        if ($type === null) {
            ErrorTracker::noteOnce('provision',
                'نگاشتِ Storage Box برای پلنِ «'.$plan.'» در config/provisioning.php نیست — سرویس '.$service->id);

            return ProvisionResult::manual('نوعِ Storage Boxِ متناظر با پلنِ «'.$plan.'» تعریف نشده است.');
        }

        $client = new HetznerStorageClient($server);
        $name = self::boxName($service);

        // ── محافظ ۱ و ۲: باکسی با همین نام از قبل هست؟
        $existing = $client->boxState($name);

        /*
        | ⚠️ `null` یعنی نتوانستیم بپرسیم. برخلافِ WHM، این‌جا ادامه **نمی‌دهیم**:
        | آن‌جا نام‌کاربری یکتاست و خودِ WHM «از قبل هست» می‌دهد، ولی هتزنر نامِ
        | تکراری را رد نمی‌کند و یک باکسِ دومِ پولی می‌سازد. پس اگر نتوانستیم
        | بپرسیم، دست نگه می‌داریم و کرون دقیقهٔ بعد دوباره تلاش می‌کند.
        */
        if ($existing === null) {
            return ProvisionResult::fail('وضعیتِ باکسِ موجود خوانده نشد؛ برای جلوگیری از ساختِ باکسِ تکراری متوقف شد.');
        }

        if (is_array($existing)) {
            return $this->adopt($service, $client, $existing, reused: true);
        }

        if (! empty($service->provision_meta['hetzner_create_attempted_at'])) {
            return ProvisionResult::manual('نتیجه تلاش قبلی ساخت باکس قطعی نیست؛ پیش از خرید دوباره موجودی پروژه باید بررسی شود.');
        }
        if ($error = $this->validateCatalogueOffer($service, $client)) {
            return ProvisionResult::manual($error);
        }

        // ── ساخت
        $password = $this->makePassword();
        // Persist before the paid POST. A crash, malformed reply or HTTP 5xx must
        // not permit a second purchase on the next cron run. Password is encrypted.
        $claimed = DB::transaction(function () use ($service, $password, $type, $location) {
            $fresh = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            $meta = (array) $fresh->provision_meta;
            if (! empty($meta['hetzner_create_attempted_at'])) return false;
            unset($meta['password']);
            $fresh->forceFill(['password' => $password, 'provision_meta' => $meta + [
                'hetzner_create_attempted_at' => now()->toIso8601String(),
                'hetzner_password_known' => true, 'box_type' => $type, 'location' => $location,
            ]])->save();
            return true;
        });
        $service->refresh();
        if (! $claimed) return ProvisionResult::manual('تلاش ساخت باکس از قبل ثبت شده؛ خرید دوباره انجام نشد.');

        $res = $client->createBox([
            'name'            => $name,
            'type'            => $type,
            'location'        => $location,
            'password'        => $password,
            'labels'          => ['service' => (string) $service->id, 'source' => 'servernet'],
            'access_settings' => $this->accessSettings(),
        ]);

        if (! $res['ok']) {
            /*
            | 🔴 تایم‌اوت ⇒ ممکن است باکس **ساخته شده باشد**. پس پیش از اعلامِ
            | شکست، دوباره می‌پرسیم — همان تعمیری که برای WHM نوشته شد و
            | `create` نداشت.
            */
            if ($res['transport'] || $res['status'] >= 500 || $res['status'] < 400) {
                $after = $client->boxState($name);
                if (is_array($after)) {
                    return $this->adopt($service, $client, $after, reused: true, password: $password);
                }
            }

            return ProvisionResult::fail('ساختِ فضای بکاپ ناموفق بود: '.$res['reason']);
        }

        $created = $res['data']['storage_box'] ?? [];
        $box = is_array($created) ? $created : [];
        // The API may return only the created resource reference while the box
        // is initializing. Preserve that id so cancellation can still delete
        // it and the recovery job can adopt it once list/get becomes complete.
        $createdId = $box['id'] ?? (is_scalar($created) ? $created : null);
        $action = $res['data']['action'] ?? null;
        if (is_array($action) && ($action['status'] ?? null) !== 'success') {
            $meta = (array) $service->provision_meta;
            $service->forceFill(['provision_meta' => $meta + [
                'hetzner_box_id' => $createdId, 'hetzner_pending_action' => $action['id'] ?? null,
            ]])->save();
            return ProvisionResult::fail('ساخت باکس هنوز به‌صورت موفق تکمیل نشده؛ نتیجه عملیات بررسی خواهد شد.');
        }

        /*
        | ⚠️ باکسِ تازه ممکن است هنوز `username`/`server` نداشته باشد (اسپک هر
        | دو را nullable گفته و «تا آماده‌شدن در دسترس نیست»). در آن حالت
        | تحویل را موفق **نمی‌گیریم**: مشتری بی‌نام‌کاربری و بی‌آدرس، سرویسی
        | دارد که راهی به داخلش نیست — همان «تحویلِ موفقِ دروغین».
        */
        if (blank($box['username'] ?? null) || blank($box['server'] ?? null)) {
            $service->forceFill([
                'provision_meta' => array_merge((array) $service->provision_meta, [
                    'hetzner_box_id' => $createdId,
                ]),
            ])->save();

            return ProvisionResult::fail('باکس ساخته شد ولی هنوز نام‌کاربری/آدرس نگرفته؛ اجرای بعدی تکمیلش می‌کند.');
        }

        return $this->adopt($service, $client, $box, reused: false, password: $password);
    }

    /**
     * پذیرشِ یک باکسِ موجود (تازه‌ساخته یا بازمانده از تلاشِ قبلی).
     *
     * اگر رمز را نداریم (تلاشِ قبلی نصفه مانده) رمزِ تازه ست می‌کنیم — وگرنه
     * مشتری باکسی دارد که رمزش دستِ هیچ‌کس نیست.
     */
    private function adopt(Service $service, HetznerStorageClient $client, array $box, bool $reused, ?string $password = null): ProvisionResult
    {
        $meta = (array) $service->provision_meta;
        if (isset($meta['password'])) {
            // Migrate only this service's legacy raw credential while handling it.
            $legacyPassword = (string) $meta['password'];
            unset($meta['password']);
            if ($legacyPassword !== '') {
                $meta['hetzner_password_known'] = true;
                $service->password = $legacyPassword;
            }
            $service->forceFill(['provision_meta' => $meta])->save();
        }
        if (($box['status'] ?? null) !== 'active' || blank($box['id'] ?? null)
            || blank($box['username'] ?? null) || blank($box['server'] ?? null)) {
            return ProvisionResult::fail('باکس هنوز فعال و آماده اتصال نیست.');
        }
        $expectedType = $meta['box_type'] ?? $meta['storage_box_offer']['type'] ?? $this->typeForPlan((string) $service->plan);
        $expectedLocation = $meta['location'] ?? $meta['storage_box_offer']['location'] ?? $this->location((string) $service->plan);
        if (($box['storage_box_type']['name'] ?? null) !== $expectedType
            || ($box['location']['name'] ?? null) !== $expectedLocation) {
            return ProvisionResult::manual('نوع یا منطقه باکس موجود با سفارش تطابق ندارد؛ تغییری انجام نشد.');
        }
        if (! empty($meta['hetzner_pending_action'])) {
            $r = $client->action($meta['hetzner_pending_action']);
            if (! $r['ok'] || ($r['data']['action']['status'] ?? null) !== 'success') {
                return ProvisionResult::fail('عملیات هتزنر هنوز با موفقیت تکمیل نشده است.');
            }
            unset($meta['hetzner_pending_action']);
        }
        if ($password === null && ! empty($meta['hetzner_password_known'])) {
            $password = (string) $service->password ?: null;
        }

        if ($password === null && ! blank($box['id'] ?? null)) {
            $fresh = $this->makePassword();
            $r = $client->resetPassword($box['id'], $fresh);
            if (! $r['ok'] || ! in_array($r['data']['action']['status'] ?? null, ['running', 'success'], true)) {
                return ProvisionResult::fail('بازیابی رمز باکس تأیید نشد؛ اطلاعات اتصال تحویل داده نشد.');
            }
            $password = $fresh;
            $meta['hetzner_password_known'] = true;
            if ($r['data']['action']['status'] === 'running') {
                if (empty($r['data']['action']['id'])) return ProvisionResult::fail('شناسه عملیات بازیابی رمز دریافت نشد.');
                $meta['hetzner_pending_action'] = $r['data']['action']['id'];
                $service->forceFill(['password' => $fresh, 'provision_meta' => $meta])->save();
                return ProvisionResult::fail('بازیابی رمز باکس در حال انجام است.');
            }
        }

        $host = (string) ($box['server'] ?? '');
        $user = (string) ($box['username'] ?? '');

        if (blank($password)) return ProvisionResult::fail('رمز معتبر باکس در دسترس نیست.');
        $meta['hetzner_password_known'] = true;
        $service->forceFill(['password' => $password, 'provision_meta' => $meta])->save();
        return ProvisionResult::success($user, $password, $host !== '' ? 'https://'.$host : null, array_merge(
            array_intersect_key($meta, array_flip(['storage_box_offer', 'hetzner_create_attempted_at'])), [
            'reused'         => $reused,
            'hetzner_password_known' => true,
            'hetzner_box_id' => $box['id'] ?? null,
            'host'           => $host,
            'location'       => $box['location']['name'] ?? null,
            'box_type'       => $box['storage_box_type']['name'] ?? null,
            'driver'         => $this->slug(),
        ]));
    }

    /**
     * تعلیق = بستنِ دسترسیِ بیرونی، نه حذف.
     *
     * دادهٔ مشتریِ بدهکار پاک نمی‌شود و ما هنوز اجارهٔ باکس را می‌دهیم؛ حذف
     * تصمیمِ آگاهانهٔ مدیر است. همان قاعدهٔ چرخهٔ عمر در §۱۰٫۵.
     */
    public function suspend(Service $service): ProvisionResult
    {
        return $this->toggleAccess($service, false);
    }

    public function unsuspend(Service $service): ProvisionResult
    {
        return $this->toggleAccess($service, true);
    }

    private function toggleAccess(Service $service, bool $open): ProvisionResult
    {
        $server = $service->server;
        $id = $this->boxId($service);

        if (! $server || $id === null) {
            return ProvisionResult::fail('شناسهٔ باکس برای این سرویس ثبت نشده است.');
        }

        $client = new HetznerStorageClient($server);

        $settings = $open
            ? $this->accessSettings()
            : ['reachable_externally' => false, 'ssh_enabled' => false, 'samba_enabled' => false, 'webdav_enabled' => false];

        $r = $client->updateAccessSettings($id, $settings);

        $complete = $r['ok'] && ($r['data']['action']['status'] ?? null) === 'success';
        return $complete
            ? ProvisionResult::success((string) $service->username ?: null, null, null, ['access' => $open ? 'open' : 'closed'])
            : ProvisionResult::fail(($open ? 'بازکردن' : 'بستن').'ِ دسترسیِ باکس هنوز تأیید نشده: '.$r['reason']);
    }

    /** خاتمه = حذفِ واقعی نزدِ هتزنر، وگرنه اجارهٔ باکسِ بی‌مشتری را ما می‌دهیم */
    public function terminate(Service $service): ProvisionResult
    {
        $server = $service->server;
        $id = $this->boxId($service);

        if (! $server) {
            return ProvisionResult::fail('سروری برای این سرویس تعیین نشده است.');
        }

        $client = new HetznerStorageClient($server);

        /*
        | شناسه را نداریم؟ با نامِ قطعی پیدایش کن. بی‌این، یک تحویلِ نصفه‌مانده
        | باکسی می‌گذاشت که هیچ‌وقت حذف نمی‌شد و اجاره‌اش تا ابد پای ماست.
        */
        if ($id === null) {
            $found = $client->boxState(self::boxName($service));

            if ($found === null) {
                return ProvisionResult::fail('وضعیتِ باکس خوانده نشد؛ حذف انجام نشد.');
            }

            if ($found === false) {
                return ProvisionResult::success(null, null, null, ['already_gone' => true]);
            }

            $id = $found['id'] ?? null;
        }

        $r = $client->deleteBox((string) $id);

        $complete = $r['ok'] && ($r['status'] === 404 || ($r['data']['action']['status'] ?? null) === 'success');
        return $complete
            ? ProvisionResult::success(null, null, null, ['deleted' => true])
            : ProvisionResult::fail('حذفِ باکس هنوز تأیید نشده: '.$r['reason']);
    }

    private function boxId(Service $service): int|string|null
    {
        $meta = (array) $service->provision_meta;

        return $meta['hetzner_box_id'] ?? null;
    }

    /** نگاشتِ پلنِ ما → نوعِ هتزنر. نبودش عمداً `null` است، نه پیش‌فرض. */
    private function typeForPlan(string $plan): ?string
    {
        return app(HetznerStorageCosts::class)->typeFor($plan);
    }

    private function location(string $plan): string
    {
        $meta = app(HetznerStorageCosts::class)->metadataForPlan($plan);
        if ($meta) return (string) $meta['location'];
        return (string) config('provisioning.hetzner_storage.location', 'fsn1');
    }

    /** Revalidate the exact sold offer before spending, never silently buy a substitute. */
    private function validateCatalogueOffer(Service $service, HetznerStorageClient $client): ?string
    {
        $costs = app(HetznerStorageCosts::class);
        if (! $costs->isCatalogPlan((string) $service->plan)) return null;
        $meta = $service->provision_meta['storage_box_offer'] ?? null;
        if (! is_array($meta) || (int) ($meta['server_id'] ?? 0) !== (int) $service->server_id
            || ($meta['currency'] ?? null) !== 'EUR' || empty($meta['type']) || empty($meta['location'])
            || (int) ($meta['size'] ?? 0) <= 0 || (int) ($meta['net_monthly_cents'] ?? 0) <= 0
            || ! isset($meta['net_setup_cents']) || (int) $meta['net_setup_cents'] < 0) {
            return 'مشخصات و بهای ثبت‌شده هنگام سفارش قابل تأیید نیست؛ بررسی دستی لازم است و خرید انجام نشد.';
        }
        $r = $client->types();
        if (! $r['ok']) return 'اعتبارسنجی زنده پلن هتزنر ناموفق بود؛ خرید انجام نشد.';
        foreach ($r['data']['storage_box_types'] as $type) {
            if (($type['name'] ?? null) !== $meta['type']) continue;
            if (! empty($type['deprecation']['unavailable_after'])) {
                try {
                    if (\Carbon\Carbon::parse($type['deprecation']['unavailable_after'])->lte(now())) {
                        return 'عرضه نوع انتخاب‌شده متوقف شده؛ خرید انجام نشد.';
                    }
                } catch (\Throwable) { return 'وضعیت عرضه نوع انتخاب‌شده تأیید نشد.'; }
            }
            if ((int) ($type['size'] ?? 0) !== (int) $meta['size']) return 'ظرفیت پلن تغییر کرده؛ خرید انجام نشد.';
            foreach (['subaccounts_limit', 'snapshot_limit', 'automatic_snapshot_limit'] as $limit) {
                if (! array_key_exists($limit, $meta) || ! array_key_exists($limit, $type) || $type[$limit] !== $meta[$limit]) {
                    return 'محدودیت‌های پلن با مشخصات ثبت‌شده سفارش تطابق ندارد؛ بررسی دستی لازم است.';
                }
            }
            foreach ($type['prices'] ?? [] as $p) {
                if (($p['location'] ?? null) !== $meta['location']) continue;
                if (strtoupper((string) ($p['currency'] ?? 'EUR')) !== 'EUR') return 'ارز بهای زیرساخت تأیید نشد.';
                foreach (['price_monthly' => 'net_monthly_cents', 'setup_fee' => 'net_setup_cents'] as $field => $key) {
                    $net = $p[$field]['net'] ?? null;
                    if (! is_string($net) || ! preg_match('/^\d+(?:\.\d+)?$/D', $net)
                        || (int) ceil((float) $net * 100 - 0.0000001) > (int) $meta[$key]) {
                        return 'بهای زیرساخت تغییر کرده یا نامعتبر است؛ خرید متوقف شد.';
                    }
                }
                return null;
            }
        }
        return 'نوع و منطقه سفارش در کاتالوگ زنده موجود نیست؛ خرید انجام نشد.';
    }

    private function accessSettings(): array
    {
        $d = (array) config('provisioning.hetzner_storage.access', []);

        return [
            // بی‌این، مشتری از ماشینِ خودش اصلاً به باکس نمی‌رسد.
            'reachable_externally' => (bool) ($d['reachable_externally'] ?? true),
            'ssh_enabled'          => (bool) ($d['ssh_enabled'] ?? true),
            'samba_enabled'        => (bool) ($d['samba_enabled'] ?? false),
            'webdav_enabled'       => (bool) ($d['webdav_enabled'] ?? true),
        ];
    }

    /**
     * رمزِ باکس.
     *
     * ⚠️ کاراکترهای مبهم عمداً نیستند: این رمز را مشتری در کلاینتِ FTP دستی
     * تایپ می‌کند و «I/l/1» و «O/0» یعنی تیکتِ «رمز اشتباه است».
     */
    private function makePassword(int $len = 24): string
    {
        $len = max(12, $len);
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $specials = '!@#$%_-';
        $out = '';

        // Hetzner requires at least one special character. Generate it
        // explicitly instead of relying on chance, then insert it at a random
        // position so every paid create request satisfies the current policy.
        for ($i = 0; $i < $len - 1; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $special = $specials[random_int(0, strlen($specials) - 1)];
        $position = random_int(0, strlen($out));

        return substr($out, 0, $position).$special.substr($out, $position);
    }
}
