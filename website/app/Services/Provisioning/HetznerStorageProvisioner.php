<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Support\ErrorTracker;

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
        $type = $this->typeForPlan($plan);

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

        // ── ساخت
        $password = $this->makePassword();

        $res = $client->createBox([
            'name'            => $name,
            'type'            => $type,
            'location'        => $this->location(),
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
            if ($res['transport']) {
                $after = $client->boxState($name);
                if (is_array($after)) {
                    return $this->adopt($service, $client, $after, reused: true, password: $password);
                }
            }

            return ProvisionResult::fail('ساختِ فضای بکاپ ناموفق بود: '.$res['reason']);
        }

        $box = $res['data']['storage_box'] ?? [];

        /*
        | ⚠️ باکسِ تازه ممکن است هنوز `username`/`server` نداشته باشد (اسپک هر
        | دو را nullable گفته و «تا آماده‌شدن در دسترس نیست»). در آن حالت
        | تحویل را موفق **نمی‌گیریم**: مشتری بی‌نام‌کاربری و بی‌آدرس، سرویسی
        | دارد که راهی به داخلش نیست — همان «تحویلِ موفقِ دروغین».
        */
        if (blank($box['username'] ?? null) || blank($box['server'] ?? null)) {
            $service->forceFill([
                'provision_meta' => array_merge((array) $service->provision_meta, [
                    'hetzner_box_id' => $box['id'] ?? null,
                    'password'       => $password,
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
        $password ??= (string) ($meta['password'] ?? '') ?: null;
        $password ??= (string) $service->password ?: null;

        if ($password === null && ! blank($box['id'] ?? null)) {
            $fresh = $this->makePassword();
            $r = $client->resetPassword($box['id'], $fresh);
            if ($r['ok']) {
                $password = $fresh;
            }
        }

        $host = (string) ($box['server'] ?? '');
        $user = (string) ($box['username'] ?? '');

        /*
        | 🔴 رمز همیشه در meta می‌نشیند، نه فقط روی ستونِ `password`.
        |
        | `ProvisioningService::ensureCredentials()` **پیش از** درایور یک رمزِ
        | تصادفی روی سرویس می‌گذارد (WHM دامنه و رمز می‌خواهد). پس اگر مسیرِ
        | پذیرشِ باکسِ موجود مجبور شود به `$service->password` برگردد، ممکن
        | است همان رمزِ ساختگی را به مشتری بدهد — رمزی که روی باکس کار نمی‌کند،
        | بی‌هیچ خطایی. با نوشتنِ رمز در meta، مرجعِ درست همیشه در دست است.
        */
        return ProvisionResult::success($user, $password, $host !== '' ? 'https://'.$host : null, [
            'reused'         => $reused,
            'password'       => $password,
            'hetzner_box_id' => $box['id'] ?? null,
            'host'           => $host,
            'location'       => $box['location']['name'] ?? null,
            'box_type'       => $box['storage_box_type']['name'] ?? null,
            'driver'         => $this->slug(),
        ]);
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

        return $r['ok']
            ? ProvisionResult::success((string) $service->username ?: null, null, null, ['access' => $open ? 'open' : 'closed'])
            : ProvisionResult::fail(($open ? 'بازکردن' : 'بستن').'ِ دسترسیِ باکس ناموفق بود: '.$r['reason']);
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

        return $r['ok']
            ? ProvisionResult::success(null, null, null, ['deleted' => true])
            : ProvisionResult::fail('حذفِ باکس ناموفق بود: '.$r['reason']);
    }

    private function boxId(Service $service): int|string|null
    {
        $meta = (array) $service->provision_meta;

        return $meta['hetzner_box_id'] ?? null;
    }

    /** نگاشتِ پلنِ ما → نوعِ هتزنر. نبودش عمداً `null` است، نه پیش‌فرض. */
    private function typeForPlan(string $plan): ?string
    {
        $map = (array) config('provisioning.hetzner_storage.plans', []);
        $type = $map[$plan] ?? null;

        return filled($type) ? (string) $type : null;
    }

    private function location(): string
    {
        return (string) config('provisioning.hetzner_storage.location', 'fsn1');
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
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';

        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
