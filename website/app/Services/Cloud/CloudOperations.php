<?php

namespace App\Services\Cloud;

use App\Models\ActivityLog;
use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * عملیاتِ پیشرفتهٔ «سرورمجازی‌ساز» — لایه‌ای نازک روی CloudManager.
 *
 * چرا کلاسِ جدا و نه افزودن به قراردادِ `CloudProvider`:
 * قرارداد **کمینه** است و هر متدِ تازه‌اش باید در **همهٔ** درایورها پیاده شود،
 * وگرنه کلاس با خطای «متدِ پیاده‌نشده» بالا نمی‌آید. اسنپ‌شات و حالتِ نجات و
 * رکورد معکوس، امروز روی همهٔ زیرساخت‌ها موجود نیستند. پس این‌جا با
 * `method_exists()` می‌سنجیم و اگر درایور آن متد را نداشت، **پیامِ خنثا** می‌دهیم.
 * روزی که درایور متد را گرفت، همین کد بی‌هیچ تغییری شروع می‌کند به کار کردن.
 *
 * ═══ چهار قاعدهٔ ثابتِ این کلاس ═══
 *
 * ۱) **هیچ متدی throw نمی‌کند.** خطا در آرایهٔ برگشتی می‌نشیند
 *    (`['ok'=>false,'message'=>…]`). یک زیرساختِ از کار افتاده نباید پنلِ مشتری
 *    را ۵۰۰ کند.
 *
 * ۲) **سفیدبرچسبی.** هیچ پیامی نامِ زیرساخت/پلنِ بومی را نمی‌گوید. پیامِ خامِ
 *    ارائه‌دهنده هم از `scrub()` می‌گذرد تا اگر روزی `cx22` یا `fsn1` در متنِ
 *    خطا آمد، به مشتری نرسد.
 *
 * ۳) **قابلیت را پیش از تماس بسنج.** `gate()` اول توانایی را از
 *    `CloudManager::capabilitiesFor()` می‌خواند و بعد وجودِ متدِ درایور را؛ اگر
 *    نبود، **هیچ درخواستِ شبکه‌ای فرستاده نمی‌شود**. این‌جا هم سهمیهٔ API را
 *    نمی‌سوزانیم و هم پیام سریع و قطعی است.
 *
 * ۴) **اعتبارسنجیِ محلی قبل از تماس.** ورودیِ غلط (نامِ میزبانِ بدشکل، تنزلِ
 *    دیسک، پلنِ مکانِ دیگر) محلی رد می‌شود؛ نه پولِ تماس می‌دهیم نه انتظارِ
 *    بی‌جا می‌سازیم.
 *
 * ═══ متدهایی که بهتر است بعداً به قرارداد و درایورها اضافه شوند ═══
 *
 * (مسیرهای واقعیِ Hetzner Cloud API v1 — همان‌ها که این کلاس انتظار دارد)
 *
 *   createSnapshot(string $ref, ?string $label): array{ok,message,ref:?string,size_gb:?float}
 *       POST /servers/{id}/actions/create_image
 *            {"type":"snapshot","description":"…","labels":{"snet-service":"12"}}
 *       → body.image.id  (شناسهٔ اسنپ‌شات)
 *
 *   listSnapshots(string $ref): array{ok,message,items:array<int,array{ref:string,label:string,size_gb:?float,created_at:?string}>}
 *       GET /images?type=snapshot&sort=created:desc&label_selector=snet-service%3D%3D{serviceId}
 *       (برچسبِ `snet-service` را create_image می‌گذارد؛ بی‌آن نمی‌شود اسنپ‌شاتِ
 *        این مشتری را از اسنپ‌شاتِ مشتریِ دیگر جدا کرد — پروژهٔ API یکی است.)
 *
 *   restoreSnapshot(string $ref, string $snapshotRef): array{ok,message,root_password:?string}
 *       POST /servers/{id}/actions/rebuild  {"image": <snapshot_id>}
 *       ⚠️ کلِ دیسک را پاک می‌کند.
 *
 *   deleteSnapshot(string $snapshotRef): array{ok,message}
 *       DELETE /images/{id}
 *
 *   enableRescue(string $ref, string $type = 'linux64'): array{ok,message,root_password:?string}
 *       POST /servers/{id}/actions/enable_rescue  {"type":"linux64","ssh_keys":[]}
 *       → body.root_password  (یک‌بارمصرف؛ تا راه‌اندازیِ دوبارهٔ سرور فعال نمی‌شود)
 *
 *   disableRescue(string $ref): array{ok,message}
 *       POST /servers/{id}/actions/disable_rescue
 *
 *   reverseDns(string $ref, string $ip, string $ptr): array{ok,message}
 *       POST /servers/{id}/actions/change_dns_ptr  {"ip":"203.0.113.7","dns_ptr":"mail.example.com"}
 *       (برای پاک کردن، `dns_ptr` را null بفرست.)
 *
 *   changeType — از قبل در قرارداد است (`resize()`):
 *       POST /servers/{id}/actions/change_type  {"server_type":"cx32","upgrade_disk":true}
 *       ⚠️ سرور باید **خاموش** باشد. `upgrade_disk=true` یک‌طرفه است: دیسکِ
 *          بزرگ‌شده دیگر کوچک نمی‌شود.
 */
class CloudOperations
{
    /** سقفِ اسنپ‌شاتِ هر سرویس — اسنپ‌شات نزدِ زیرساخت ماهانه پول دارد */
    public const MAX_SNAPSHOTS = 5;

    private const NOT_READY = 'سرور هنوز آماده نیست.';

    /**
     * پیامِ خنثای «نداریم» — به تفکیکِ عملیات، ولی همه با همان عبارتِ
     * قراردادیِ پروژه: «برای این سرور در دسترس نیست».
     */
    private const UNAVAILABLE = [
        'resize'   => 'تغییرِ پلن برای این سرور در دسترس نیست.',
        'snapshot' => 'نسخهٔ پشتیبانِ لحظه‌ای (اسنپ‌شات) برای این سرور در دسترس نیست.',
        'rescue'   => 'حالتِ نجات برای این سرور در دسترس نیست.',
        'rdns'     => 'تنظیمِ رکوردِ معکوس (PTR) برای این سرور در دسترس نیست.',
        'rebuild'  => 'نصبِ دوبارهٔ سیستم‌عامل برای این سرور در دسترس نیست.',
        'traffic'  => 'گزارشِ مصرفِ ترافیک برای این سرور در دسترس نیست.',
    ];

    /**
     * نگاشتِ عملیات → کلیدِ توانایی در `capabilities()`.
     *
     * `null` یعنی این عملیات روی متدهای **قراردادِ پایه** سوار است و هر درایوری
     * داردش (مصرفِ ترافیک از `serverStatus()` می‌آید)، پس کلیدِ توانایی ندارد.
     */
    private const CAPABILITY = [
        'resize'   => 'resize',
        'snapshot' => 'snapshot',
        'rescue'   => 'rescue',
        'rdns'     => 'rdns',        // هنوز هیچ درایوری اعلامش نکرده → خنثا
        'rebuild'  => 'rebuild',
        'traffic'  => null,
    ];

    public function __construct(private CloudManager $manager) {}

    // ═══════════════════════ ارتقا / تنزلِ پلن ═══════════════════════

    /**
     * تغییرِ پلنِ سرور به عرضهٔ `$targetSlug` (اسلاگِ عمومیِ ما، نه نامِ بومی).
     *
     * قواعد — به همین ترتیب، چون هر کدام یک تماسِ بی‌فایده را حذف می‌کند:
     *
     *  ۱ توانایی (بی‌تماس)
     *  ۲ پلنِ هدف باید روی **همان زیرساخت و همان مکان** باشد؛ وگرنه تغییرِ پلن
     *    یعنی ساختنِ سرورِ تازه و کوچ دادنِ داده → «خودکار انجام نمی‌شود».
     *  ۳ **تنزلِ دیسک ممکن نیست** — نه در ما، نه در هیچ زیرساختی. دیسکِ
     *    بزرگ‌شده کوچک نمی‌شود و اگر بگذاریم مشتری پلنِ کوچک‌تر بخرد، پول
     *    می‌گیریم و بعد شکست می‌خوریم.
     *  ۴ سرور باید **خاموش** باشد (وضعیتِ زنده، نه وضعیتِ ذخیره‌شده که ممکن است
     *    کهنه باشد).
     *  ۵ بعد از موفقیت، **قیمتِ سرویس** به قیمتِ پلنِ تازه می‌رود.
     *
     * ⚠️ این متد فاکتورِ تفاضل (pro-rate) صادر نمی‌کند؛ قیمتِ تازه از دورهٔ بعد
     * اثر می‌گذارد. صدورِ فاکتورِ مابه‌التفاوت کارِ لایهٔ صورت‌حساب است.
     *
     * @return array{ok:bool,message:string,plan?:string,price?:int,currency?:string,upgraded_disk?:bool}
     */
    public function resize(Service $service, string $targetSlug): array
    {
        $g = $this->gate($service, 'resize');

        if (! $g['ok']) {
            return ['ok' => false, 'message' => $g['message']];
        }

        /** @var CloudInstance $instance */
        $instance = $g['instance'];
        /** @var CloudProvider $driver */
        $driver = $g['driver'];

        $slug = trim($targetSlug);
        $currentPlan = $service->cloudPlan;
        $currentDisk = (int) ($instance->specs['disk_gb'] ?? $currentPlan?->disk_gb ?? 0);

        if ($slug === '') {
            return ['ok' => false, 'message' => 'پلنِ هدف انتخاب نشده است.'];
        }

        if ($currentPlan !== null && $slug === (string) $currentPlan->slug) {
            return ['ok' => false, 'message' => 'سرور از قبل روی همین پلن است.'];
        }

        // ── ۲) هم‌زیرساخت و هم‌مکان بودن ──
        $target = CloudPlan::query()->sellable()
            ->where('slug', $slug)
            ->where('provider', $instance->provider)
            ->where('location_code', $instance->location_code)
            ->orderBy('cost_eur_cents')
            ->first();

        if ($target === null) {
            // اسلاگ وجود دارد ولی نه این‌جا: یعنی مکان یا زیرساختِ دیگری است.
            // ⚠️ پیام برای هر دو حالت **یکی** است؛ اگر بگوییم «زیرساختِ دیگر»،
            // مشتری می‌فهمد چند تأمین‌کننده داریم و کدام سرورش کجاست.
            $existsElsewhere = CloudPlan::query()->sellable()->where('slug', $slug)->exists();

            return ['ok' => false, 'message' => $existsElsewhere
                ? 'این پلن برای سرورِ فعلی در دسترس نیست؛ تغییر به آن نیاز به مهاجرتِ سرور دارد و «خودکار» انجام نمی‌شود. برای انتقالِ داده با پشتیبانی تماس بگیرید.'
                : 'این پلن در دسترس نیست.',
            ];
        }

        // ── ۳) تنزلِ دیسک ──
        if ((int) $target->disk_gb < $currentDisk) {
            return ['ok' => false, 'message' => 'کوچک‌کردنِ فضای دیسک ممکن نیست. فقط پلنی با دیسکِ برابر یا بزرگ‌تر از '
                .fa_num($currentDisk).' گیگابایت قابلِ انتخاب است.'];
        }

        // ── قیمت: پیش از تماس، تا سرویسِ «رایگان» نسازیم ──
        $price = $this->priceFor($service, $target);

        if ($price <= 0) {
            return ['ok' => false, 'message' => 'قیمتِ این پلن هنوز مشخص نیست؛ چند دقیقهٔ دیگر تلاش کنید.'];
        }

        // ── ۴) وضعیتِ زنده باید خاموش باشد ──
        $live = $this->invoke($driver, 'serverStatus', [$g['ref']]);

        if (! ($live['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'وضعیتِ سرور خوانده نشد؛ چند لحظه بعد دوباره تلاش کنید.'];
        }

        $liveStatus = (string) ($live['status'] ?? 'unknown');
        $instance->update(['status' => $liveStatus, 'synced_at' => now()]);

        if ($liveStatus !== 'off') {
            return ['ok' => false, 'message' => 'برای تغییرِ پلن باید سرور خاموش باشد. اول از همین صفحه خاموشش کنید و بعد دوباره تلاش کنید.'];
        }

        // `upgrade_disk` یک‌طرفه است؛ فقط وقتی دیسک واقعاً بزرگ‌تر است می‌فرستیم
        // تا امکانِ تنزلِ پردازنده/حافظه در آینده از دست نرود.
        $upgradeDisk = (int) $target->disk_gb > $currentDisk;

        $r = $this->invoke($driver, 'resize', [$g['ref'], (string) $target->provider_ref, $upgradeDisk]);

        if (! ($r['ok'] ?? false)) {
            $instance->update(['last_error' => mb_substr($this->scrub((string) ($r['message'] ?? ''), $instance, $target), 0, 500)]);

            return ['ok' => false, 'message' => 'تغییرِ پلن انجام نشد: '
                .$this->scrub((string) ($r['message'] ?? '—'), $instance, $target)];
        }

        // ── ۵) پول و مشخصات ──
        DB::transaction(function () use ($service, $instance, $target, $price) {
            $service->forceFill([
                'cloud_plan_id'  => $target->id,
                'price'          => $price,
                'provision_meta' => array_replace((array) ($service->provision_meta ?? []), [
                    'plan' => $target->public_name,
                ]),
            ])->save();

            $instance->fill([
                'specs' => array_replace((array) ($instance->specs ?? []), [
                    'vcpu'       => (int) $target->vcpu,
                    'ram_mb'     => (int) $target->ram_mb,
                    'disk_gb'    => (int) $target->disk_gb,
                    'disk_type'  => (string) $target->disk_type,
                    'traffic_gb' => (int) $target->traffic_gb,
                    'cpu_kind'   => (string) $target->cpu_kind,
                    'plan_name'  => (string) $target->public_name,
                ]),
                'meta' => array_replace((array) ($instance->meta ?? []), [
                    'resized_at' => now()->toIso8601String(),
                ]),
                'last_error' => null,
                'synced_at'  => now(),
            ])->save();
        });

        $this->log($service, 'پلنِ سرور به '.$target->public_name.' تغییر کرد.');

        return [
            'ok'            => true,
            'message'       => 'پلنِ سرور به '.$target->public_name.' تغییر کرد. سرور را روشن کنید تا با منابعِ تازه بالا بیاید.',
            'plan'          => (string) $target->public_name,
            'price'         => $price,
            'currency'      => strtoupper((string) ($service->currency_code ?: 'IRT')),
            'upgraded_disk' => $upgradeDisk,
        ];
    }

    // ═══════════════════════ اسنپ‌شات ═══════════════════════

    /**
     * گرفتنِ نسخهٔ پشتیبانِ لحظه‌ای.
     *
     * ⚠️ اسنپ‌شات نزدِ زیرساخت **ماهانه پول** دارد (به ازای گیگابایت). پس سقف
     * دارد؛ بی‌سقف، یک مشتری با کلیک‌های پیاپی هزینهٔ ثابتِ ما را بالا می‌برد.
     *
     * @return array{ok:bool,message:string,ref?:?string}
     */
    public function snapshot(Service $service, ?string $label = null): array
    {
        $g = $this->gate($service, 'snapshot', 'createSnapshot');

        if (! $g['ok']) {
            return ['ok' => false, 'message' => $g['message']];
        }

        /** @var CloudInstance $instance */
        $instance = $g['instance'];
        /** @var CloudProvider $driver */
        $driver = $g['driver'];

        // سقف — فقط اگر درایور بتواند فهرست بدهد (وگرنه شمارش ممکن نیست)
        if (method_exists($driver, 'listSnapshots')) {
            $list = $this->invoke($driver, 'listSnapshots', [$g['ref']]);

            if (($list['ok'] ?? false) && count((array) ($list['items'] ?? [])) >= self::MAX_SNAPSHOTS) {
                return ['ok' => false, 'message' => 'سقفِ '.fa_num(self::MAX_SNAPSHOTS)
                    .' نسخهٔ پشتیبان پر شده است. یکی را حذف کنید و دوباره تلاش کنید.'];
            }
        }

        $clean = $this->snapshotLabel($service, $label);
        $r = $this->invoke($driver, 'createSnapshot', [$g['ref'], $clean]);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'نسخهٔ پشتیبان گرفته نشد: '
                .$this->scrub((string) ($r['message'] ?? '—'), $instance), 'ref' => null];
        }

        $this->log($service, 'نسخهٔ پشتیبان گرفته شد: '.$clean);

        return [
            'ok'      => true,
            'message' => 'نسخهٔ پشتیبان در حالِ ساخته شدن است. چند دقیقه بعد در فهرست ظاهر می‌شود.',
            'ref'     => isset($r['ref']) ? (string) $r['ref'] : null,
        ];
    }

    /**
     * فهرستِ نسخه‌های پشتیبان.
     *
     * @return array{ok:bool,message:string,items:array<int,array{ref:string,label:string,size_gb:?float,created_at:?string}>}
     */
    public function listSnapshots(Service $service): array
    {
        $g = $this->gate($service, 'snapshot', 'listSnapshots');

        if (! $g['ok']) {
            return ['ok' => false, 'message' => $g['message'], 'items' => []];
        }

        $r = $this->invoke($g['driver'], 'listSnapshots', [$g['ref']]);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'فهرستِ نسخه‌های پشتیبان خوانده نشد.', 'items' => []];
        }

        $items = [];

        foreach ((array) ($r['items'] ?? []) as $row) {
            $ref = (string) (is_array($row) ? ($row['ref'] ?? '') : '');

            if ($ref === '') {
                continue;
            }

            $items[] = [
                'ref'        => $ref,
                'label'      => (string) ($row['label'] ?? $ref),
                'size_gb'    => isset($row['size_gb']) ? (float) $row['size_gb'] : null,
                'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            ];
        }

        return ['ok' => true, 'message' => '', 'items' => $items];
    }

    /**
     * بازگردانیِ سرور از یک نسخهٔ پشتیبان.
     *
     * ⚠️ **پاک‌کنندهٔ داده** — همان قاعدهٔ نصبِ دوباره: تأییدِ صریح لازم است.
     * و شناسهٔ اسنپ‌شات باید در فهرستِ **همین سرور** باشد؛ ورودیِ دلخواهِ کاربر
     * مستقیم به API نمی‌رود (وگرنه با حدسِ شناسه، اسنپ‌شاتِ مشتریِ دیگری روی
     * سرورِ خودش می‌نشیند — پروژهٔ API نزدِ زیرساخت یکی است).
     *
     * @return array{ok:bool,message:string,root_password?:?string}
     */
    public function restoreSnapshot(Service $service, string $snapshotRef, bool $confirmed = false): array
    {
        $g = $this->gate($service, 'snapshot', 'restoreSnapshot');

        if (! $g['ok']) {
            return ['ok' => false, 'message' => $g['message']];
        }

        if (! $confirmed) {
            return ['ok' => false, 'message' => 'بازگردانیِ نسخهٔ پشتیبان همهٔ دادهٔ فعلیِ سرور را پاک می‌کند و تأییدِ صریح می‌خواهد.'];
        }

        $ref = trim($snapshotRef);

        if ($ref === '' || ! $this->ownsSnapshot($service, $ref)) {
            return ['ok' => false, 'message' => 'این نسخهٔ پشتیبان برای این سرور پیدا نشد.'];
        }

        /** @var CloudInstance $instance */
        $instance = $g['instance'];

        $r = $this->invoke($g['driver'], 'restoreSnapshot', [$g['ref'], $ref]);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'بازگردانی انجام نشد: '
                .$this->scrub((string) ($r['message'] ?? '—'), $instance)];
        }

        $instance->fill(['status' => 'building', 'last_error' => null]);

        if (filled($r['root_password'] ?? null)) {
            $instance->setPassword((string) $r['root_password']);   // password_seen → false
        }

        $instance->save();

        $this->log($service, 'سرور از نسخهٔ پشتیبان بازگردانی شد.');

        return [
            'ok'            => true,
            'message'       => 'بازگردانی آغاز شد. چند دقیقه بعد سرور با دادهٔ آن نسخه بالا می‌آید.',
            'root_password' => $r['root_password'] ?? null,
        ];
    }

    /** @return array{ok:bool,message:string} */
    public function deleteSnapshot(Service $service, string $snapshotRef): array
    {
        $g = $this->gate($service, 'snapshot', 'deleteSnapshot');

        if (! $g['ok']) {
            return ['ok' => false, 'message' => $g['message']];
        }

        $ref = trim($snapshotRef);

        if ($ref === '' || ! $this->ownsSnapshot($service, $ref)) {
            return ['ok' => false, 'message' => 'این نسخهٔ پشتیبان برای این سرور پیدا نشد.'];
        }

        $r = $this->invoke($g['driver'], 'deleteSnapshot', [$ref]);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'حذف انجام نشد: '
                .$this->scrub((string) ($r['message'] ?? '—'), $g['instance'])];
        }

        $this->log($service, 'نسخهٔ پشتیبان حذف شد.');

        return ['ok' => true, 'message' => 'نسخهٔ پشتیبان حذف شد.'];
    }

    /** آیا این شناسه در فهرستِ اسنپ‌شات‌های همین سرور است؟ */
    private function ownsSnapshot(Service $service, string $ref): bool
    {
        $list = $this->listSnapshots($service);

        if (! ($list['ok'] ?? false)) {
            return false;                     // نمی‌دانیم = اجازه نمی‌دهیم
        }

        foreach ($list['items'] as $item) {
            if ((string) $item['ref'] === $ref) {
                return true;
            }
        }

        return false;
    }

    // ═══════════════════════ حالتِ نجات ═══════════════════════

    /**
     * حالتِ نجات: بوتِ سیستمِ کوچکِ رمزی برای وقتی سیستم‌عاملِ اصلی بالا نمی‌آید.
     *
     * دو نکتهٔ عملی:
     *  • رمزِ نجات **یک‌بارمصرف** است و ذخیره‌اش نمی‌کنیم. نگه‌داشتنِ رمزی که به
     *    کلِ دیسک دسترسیِ خام می‌دهد، در JSONِ متادیتا، ارزشش را ندارد.
     *  • تا سرور **راه‌اندازیِ دوباره** نشود، حالتِ نجات بالا نمی‌آید. پس خودمان
     *    ری‌بوت می‌کنیم؛ وگرنه مشتری رمز را دارد و سرور همان سیستمِ خرابِ قبلی
     *    را بوت می‌کند و فکر می‌کند قابلیت خراب است.
     *
     * @return array{ok:bool,message:string,password?:?string,rebooted?:bool}
     */
    public function rescue(Service $service): array
    {
        $g = $this->gate($service, 'rescue', 'enableRescue');

        if (! $g['ok']) {
            return ['ok' => false, 'message' => $g['message'], 'password' => null];
        }

        /** @var CloudInstance $instance */
        $instance = $g['instance'];

        $r = $this->invoke($g['driver'], 'enableRescue', [$g['ref'], 'linux64']);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'حالتِ نجات فعال نشد: '
                .$this->scrub((string) ($r['message'] ?? '—'), $instance), 'password' => null];
        }

        $boot = $this->invoke($g['driver'], 'power', [$g['ref'], $instance->status === 'off' ? 'on' : 'reset']);

        $instance->fill([
            'status' => 'building',
            'meta'   => array_replace((array) ($instance->meta ?? []), [
                'rescue' => ['enabled_at' => now()->toIso8601String()],
            ]),
            'last_error' => null,
        ])->save();

        $this->log($service, 'حالتِ نجات فعال شد.');

        return [
            'ok'       => true,
            'message'  => 'حالتِ نجات فعال شد و سرور در حالِ راه‌اندازیِ دوباره است. رمزِ زیر یک‌بارمصرف است و دوباره نشان داده نمی‌شود.',
            'password' => $r['root_password'] ?? null,
            'rebooted' => (bool) ($boot['ok'] ?? false),
        ];
    }

    /** @return array{ok:bool,message:string} */
    public function disableRescue(Service $service): array
    {
        $g = $this->gate($service, 'rescue', 'disableRescue');

        if (! $g['ok']) {
            return ['ok' => false, 'message' => $g['message']];
        }

        /** @var CloudInstance $instance */
        $instance = $g['instance'];

        $r = $this->invoke($g['driver'], 'disableRescue', [$g['ref']]);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'حالتِ نجات غیرفعال نشد: '
                .$this->scrub((string) ($r['message'] ?? '—'), $instance)];
        }

        $meta = (array) ($instance->meta ?? []);
        unset($meta['rescue']);
        $instance->fill(['meta' => $meta, 'last_error' => null])->save();

        $this->log($service, 'حالتِ نجات غیرفعال شد.');

        return ['ok' => true, 'message' => 'حالتِ نجات غیرفعال شد. با راه‌اندازیِ دوبارهٔ سرور، سیستم‌عاملِ اصلی بالا می‌آید.'];
    }

    // ═══════════════════════ رکوردِ معکوس (PTR) ═══════════════════════

    /**
     * رکوردِ معکوسِ IP سرور.
     *
     * ⚠️ ترتیب مهم است: **اول اعتبارسنجیِ محلی، بعد سنجشِ قابلیت**. اگر برعکس
     * بود، مشتری روی زیرساختی که PTR ندارد پیامِ «در دسترس نیست» می‌گرفت و
     * هرگز نمی‌فهمید نامی که تایپ کرده هم غلط بوده. هر دو مسیر بی‌تماسِ شبکه‌اند.
     *
     * چرا PTR مهم است: بی‌آن، ایمیلِ خروجیِ سرور تقریباً همیشه اسپم می‌خورد.
     *
     * @return array{ok:bool,message:string,ptr?:string}
     */
    public function reverseDns(Service $service, string $ptr): array
    {
        $host = $this->normalizeHostname($ptr);

        if ($host === null) {
            return ['ok' => false, 'message' => 'نامِ میزبان معتبر نیست. یک نامِ کاملِ دامنه مثل mail.example.com بنویسید (بی‌فاصله، بی‌کاراکترِ ویژه، با پسوندِ دامنه).'];
        }

        $g = $this->gate($service, 'rdns', 'reverseDns');

        if (! $g['ok']) {
            return ['ok' => false, 'message' => $g['message']];
        }

        /** @var CloudInstance $instance */
        $instance = $g['instance'];
        $ip = (string) ($instance->ipv4 ?: '');

        if ($ip === '') {
            return ['ok' => false, 'message' => 'برای این سرور هنوز IP ثبت نشده است.'];
        }

        $r = $this->invoke($g['driver'], 'reverseDns', [$g['ref'], $ip, $host]);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'رکوردِ معکوس ثبت نشد: '
                .$this->scrub((string) ($r['message'] ?? '—'), $instance)];
        }

        $instance->fill([
            'meta' => array_replace((array) ($instance->meta ?? []), ['rdns' => $host]),
        ])->save();

        $this->log($service, 'رکوردِ معکوسِ IP روی '.$host.' تنظیم شد.');

        return ['ok' => true, 'message' => 'رکوردِ معکوس روی '.$host.' تنظیم شد. انتشارِ کاملش تا چند ساعت طول می‌کشد.', 'ptr' => $host];
    }

    /**
     * نامِ میزبانِ معتبر یا null.
     *
     * قواعد: حروفِ کوچکِ لاتین/رقم/خط تیره، هر برچسب ۱..۶۳ و بی‌خط تیره در
     * ابتدا و انتها، کلِ نام تا ۲۵۳، دستِ‌کم دو برچسب، و پسوندِ حرفی. نقطهٔ
     * پایانی (شکلِ مطلقِ DNS) پذیرفته و حذف می‌شود.
     */
    private function normalizeHostname(string $raw): ?string
    {
        $h = strtolower(trim($raw));
        $h = rtrim($h, '.');

        if ($h === '' || strlen($h) > 253 || ! str_contains($h, '.')) {
            return null;
        }

        $labels = explode('.', $h);

        if (count($labels) < 2) {
            return null;
        }

        foreach ($labels as $label) {
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return null;
            }
        }

        return preg_match('/^[a-z]{2,}$/', (string) end($labels)) ? $h : null;
    }

    // ═══════════════════════ مصرفِ ترافیک ═══════════════════════

    /**
     * مصرفِ ترافیکِ ماهِ جاری و درصدِ سهمیه.
     *
     * از `serverStatus()` می‌آید که در قراردادِ پایه است، پس روی هر زیرساختی
     * کار می‌کند. سهمیه از عکسِ لحظه‌ایِ مشخصاتِ نمونه خوانده می‌شود (نه از پلنِ
     * فعلی)، چون پلن ممکن است بعداً عوض شده باشد.
     *
     * درصد **رو به بالا** گرد می‌شود: در هشدارِ مصرف، کم‌نمایی خطرِ واقعی دارد.
     *
     * @return array{ok:bool,message:string,used_gb:?float,quota_gb:?int,percent:?int,unlimited:bool,remaining_gb?:?float,used_label?:string,quota_label?:string}
     */
    public function trafficUsage(Service $service): array
    {
        $g = $this->gate($service, 'traffic');

        if (! $g['ok']) {
            return ['ok' => false, 'message' => $g['message'], 'used_gb' => null,
                'quota_gb' => null, 'percent' => null, 'unlimited' => false];
        }

        /** @var CloudInstance $instance */
        $instance = $g['instance'];

        $r = $this->invoke($g['driver'], 'serverStatus', [$g['ref']]);

        if (! ($r['ok'] ?? false)) {
            return ['ok' => false, 'message' => 'مصرفِ ترافیک خوانده نشد؛ چند لحظه بعد تلاش کنید.',
                'used_gb' => null, 'quota_gb' => null, 'percent' => null, 'unlimited' => false];
        }

        // همین‌جا وضعیت را هم تازه می‌کنیم — تماس را دو بار نمی‌دهیم
        $instance->update([
            'status'    => (string) ($r['status'] ?? $instance->status),
            'ipv4'      => ($r['ipv4'] ?? null) ?: $instance->ipv4,
            'ipv6'      => ($r['ipv6'] ?? null) ?: $instance->ipv6,
            'synced_at' => now(),
        ]);

        $used = round((float) ($r['traffic_used_gb'] ?? 0), 2);
        $quota = (int) ($instance->specs['traffic_gb'] ?? $service->cloudPlan?->traffic_gb ?? 0);
        $unlimited = $quota <= 0;

        return [
            'ok'           => true,
            'message'      => '',
            'used_gb'      => $used,
            'quota_gb'     => $quota,
            'percent'      => $unlimited ? null : (int) min(100, ceil($used / $quota * 100)),
            'unlimited'    => $unlimited,
            'remaining_gb' => $unlimited ? null : max(0, round($quota - $used, 2)),
            'used_label'   => $this->gbLabel($used),
            'quota_label'  => $unlimited ? '—' : $this->gbLabel($quota),
        ];
    }

    /** گیگابایت → «۱٫۵ TB» / «۵۱۲ GB» (عددِ لاتین؛ ویو خودش fa_num می‌زند) */
    private function gbLabel(float $gb): string
    {
        return $gb >= 1024
            ? rtrim(rtrim(number_format($gb / 1024, 1, '.', ''), '0'), '.').' TB'
            : rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.').' GB';
    }

    // ═══════════════════════ ایمیج‌های قابلِ نصب ═══════════════════════

    /**
     * ایمیج‌هایی که **واقعاً** روی این سرور نصب می‌شوند.
     *
     * سه فیلتر که اگر نباشند، مشتری گزینه‌ای می‌بیند که تحویلش شکست می‌خورد:
     *  • همان زیرساختِ سرور (کلیدِ یکسان، شناسهٔ بومیِ متفاوت)
     *  • معماری (x86 ≠ arm — ایمیجِ arm روی سرورِ x86 بوت نمی‌شود)
     *  • حداقلِ دیسک (ایمیجِ ۶۰گیگی روی دیسکِ ۴۰گیگی جا نمی‌شود)
     *
     * بی‌هیچ تماسِ شبکه‌ای — از کاتالوگِ همگام‌شدهٔ محلی می‌خوانَد.
     *
     * @return array{ok:bool,message:string,images:array<int,array<string,mixed>>,count:int}
     */
    public function rebuildableImages(Service $service): array
    {
        $g = $this->gate($service, 'rebuild');

        if (! $g['ok']) {
            return ['ok' => false, 'message' => $g['message'], 'images' => [], 'count' => 0];
        }

        /** @var CloudInstance $instance */
        $instance = $g['instance'];

        $plan = $service->cloudPlan;
        $disk = (int) ($instance->specs['disk_gb'] ?? $plan?->disk_gb ?? 0);
        $arch = (string) ($instance->specs['arch'] ?? $plan?->arch ?? '');

        $images = CloudImage::query()
            ->usable()
            ->where('provider', $instance->provider)
            ->when($arch !== '', fn ($q) => $q->where('arch', $arch))
            ->orderBy('kind')
            ->orderBy('family')
            ->orderByDesc('version')
            ->get()
            ->filter(fn (CloudImage $i) => $disk <= 0 || (int) $i->min_disk_gb <= $disk)
            ->unique('key')
            ->values()
            ->map(fn (CloudImage $i) => [
                'key'     => (string) $i->key,
                'label'   => (string) $i->label,
                'kind'    => (string) $i->kind,
                'family'  => $i->family,
                'version' => $i->version,
                'icon'    => $i->icon(),
                'current' => (string) $i->key === (string) $instance->image_key,
            ])
            ->all();

        return ['ok' => true, 'message' => '', 'images' => $images, 'count' => count($images)];
    }

    // ═══════════════════════ زیرساختِ درونی ═══════════════════════

    /**
     * دروازهٔ همهٔ عملیات: نمونه + درایور + توانایی + وجودِ متد.
     *
     * ⚠️ هیچ تماسِ شبکه‌ای در این متد نیست — نکتهٔ اصلیِ همین است: قابلیتِ نبود
     * باید **بی‌تماس** پیامِ خنثا بدهد.
     *
     * @return array{ok:bool,message:string,instance:?CloudInstance,driver:?CloudProvider,ref:string}
     */
    private function gate(Service $service, string $op, ?string $driverMethod = null): array
    {
        $unavailable = self::UNAVAILABLE[$op] ?? 'این عملیات برای این سرور در دسترس نیست.';
        $fail = fn (string $m): array => ['ok' => false, 'message' => $m, 'instance' => null, 'driver' => null, 'ref' => ''];

        try {
            if (! $service->isCloud()) {
                return $fail($unavailable);
            }

            $instance = CloudInstance::where('service_id', $service->id)->first();

            if ($instance === null || blank($instance->provider_ref)) {
                return $fail(self::NOT_READY);
            }

            if ($instance->status === 'deleted') {
                return $fail('این سرور حذف شده است.');
            }

            $driver = $this->manager->forInstance($instance);

            if ($driver === null || ! $driver->isConfigured()) {
                return $fail($unavailable);
            }

            // ── توانایی، پیش از هر تماس ──
            $capKey = array_key_exists($op, self::CAPABILITY) ? self::CAPABILITY[$op] : $op;

            if ($capKey !== null && ! ($this->manager->capabilitiesFor($instance)[$capKey] ?? false)) {
                return $fail($unavailable);
            }

            // ── متدِ بیرون از قرارداد؟ اگر درایور نداشت، همان پیامِ خنثا ──
            if ($driverMethod !== null && ! method_exists($driver, $driverMethod)) {
                return $fail($unavailable);
            }

            return ['ok' => true, 'message' => '', 'instance' => $instance,
                'driver' => $driver, 'ref' => (string) $instance->provider_ref];
        } catch (\Throwable $e) {
            Log::warning('cloud.ops.gate', ['service' => $service->id, 'op' => $op, 'err' => $e->getMessage()]);

            return $fail($unavailable);
        }
    }

    /**
     * صدا زدنِ متدِ درایور — هرگز throw نمی‌کند و همیشه آرایه می‌دهد.
     *
     * متدهای بیرونِ قرارداد به‌صورتِ پویا صدا زده می‌شوند، پس یک استثنای
     * غیرمنتظره (`BadMethodCall`، خطای نوع) نباید از این لایه بیرون بزند.
     *
     * @return array<string,mixed>
     */
    private function invoke(CloudProvider $driver, string $method, array $args): array
    {
        try {
            /** @var mixed $r */
            $r = $driver->{$method}(...$args);
        } catch (\Throwable $e) {
            Log::warning('cloud.ops.invoke', ['method' => $method, 'err' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'انجام نشد؛ چند لحظه بعد تلاش کنید.'];
        }

        return is_array($r) ? $r : ['ok' => false, 'message' => 'انجام نشد؛ چند لحظه بعد تلاش کنید.'];
    }

    /**
     * پاک‌سازیِ پیامِ خامِ زیرساخت از هر نشانهٔ هویتی.
     *
     * پیامِ خطای ارائه‌دهنده برای عیب‌یابی ارزشمند است، ولی گاهی نامِ بومیِ پلن
     * («cx22 is not available») یا نامِ برند در آن هست. اگر مستقیم به مشتری
     * برود، همان یک خط کلِ سفیدبرچسبی را می‌شکند. پس همان‌جا که پیام از لایهٔ
     * درایور بیرون می‌آید، نشانه‌ها با «—» جانشین می‌شوند.
     */
    public function scrub(string $message, ?CloudInstance $instance = null, ?CloudPlan $plan = null): string
    {
        $needles = array_keys(CloudManager::DRIVERS);
        $needles[] = 'api.hetzner.cloud';
        $needles[] = 'my.aeza.net';
        $needles[] = 'aezagroup';

        foreach ([$plan, $instance?->service?->cloudPlan] as $p) {
            if ($p instanceof CloudPlan) {
                $needles[] = (string) $p->provider_ref;
                $needles[] = (string) $p->provider_location;
            }
        }

        // نامِ بومیِ پلن‌های همین زیرساخت هم می‌تواند در متنِ خطا باشد
        if ($instance !== null) {
            foreach (CloudPlan::query()->where('provider', $instance->provider)->pluck('provider_ref') as $ref) {
                $needles[] = (string) $ref;
            }

            foreach (CloudPlan::query()->where('provider', $instance->provider)->pluck('provider_location') as $loc) {
                $needles[] = (string) $loc;
            }
        }

        $out = $message;

        foreach (array_unique(array_filter($needles, fn ($n) => is_string($n) && strlen($n) >= 3)) as $needle) {
            // ⚠️ اگر `preg_replace` روی بایتِ نامعتبرِ UTF-8 شکست بخورد null می‌دهد؛
            // کستِ ساده به رشته، **کلِ پیام را پاک می‌کرد**. پس نتیجه را می‌سنجیم.
            $replaced = preg_replace('/'.preg_quote($needle, '/').'/iu', '—', $out);

            if (is_string($replaced)) {
                $out = $replaced;
            }
        }

        return trim($out) !== '' ? $out : '—';
    }

    /** قیمتِ سرویس در ارزِ خودش — عددِ صحیح در واحدِ فرعی، از خودِ پلن */
    private function priceFor(Service $service, CloudPlan $plan): int
    {
        $cycle = (string) ($service->cycle ?: 'monthly');
        $isIrt = strtoupper((string) ($service->currency_code ?: 'IRT')) === 'IRT';

        // 🔴 اشتباهِ قبلی و گرانیِ آن: این‌جا قیمتِ **ماهانه** برگردانده می‌شد،
        // ولی `services.price` مبلغِ **یک دورهٔ کامل** است. روی سرویسِ سالانه،
        // ارتقا به پلنِ بزرگ‌تر قیمت را از «۱۲ ماه پلنِ کوچک» به «۱ ماه پلنِ
        // بزرگ» می‌شکست — یعنی ارتقای پلن به تخفیفِ ~۹۲٪ تبدیل می‌شد، و فاکتورِ
        // تمدیدِ سالِ بعد هم همان عددِ غلط را می‌گرفت. تست‌های موجود نگرفتنش چون
        // همه سرویسِ ماهانه بودند و ۱ ماه با ۱ ماه یکی است.
        if ($isIrt) {
            // از همان منبعِ یگانهٔ سرورساز می‌خوانیم تا تخفیفِ دوره و افزودنی‌ها
            // هم یک‌جا و یکسان حساب شوند.
            return \App\Http\Controllers\Account\CloudStoreController::priceForCycle(
                $plan,
                $cycle,
                app(CloudAddons::class)->sanitize($service->cloud_addons)
            );
        }

        // مسیرِ یورویی: همان قاعده، ولی گردکردن به پلهٔ ۱۰ سنت
        $months = Service::monthsIn($cycle);

        if ($months <= 0) {
            return (int) $plan->price_eur_cents;      // «یک‌بار» دوره ندارد
        }

        $discount = max(0, min(90, (int) (config('billing.cycles.'.$cycle.'.discount_pct') ?? 0)));
        $raw = (int) $plan->price_eur_cents * $months * (100 - $discount) / 100;

        return \App\Models\Product::roundUpEur((int) ceil($raw));
    }

    /** برچسبِ اسنپ‌شات: ورودیِ کاربر پاک‌سازی‌شده، یا نامِ قطعیِ پیش‌فرض */
    private function snapshotLabel(Service $service, ?string $label): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s._-]+/u', '', trim((string) $label)) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        return $clean !== ''
            ? mb_substr($clean, 0, 60)
            : 'snap-'.$service->id.'-'.now()->format('Ymd-Hi');
    }

    private function log(Service $service, string $text): void
    {
        try {
            ActivityLog::record($service->customer_id, 'service',
                'سرورِ ابری #'.$service->id.' — '.$text, null, 'customer');
        } catch (\Throwable) {
            // لاگ نباید عملیات را بشکند
        }
    }
}
