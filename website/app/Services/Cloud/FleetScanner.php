<?php

namespace App\Services\Cloud;

use App\Models\CloudPlan;
use App\Models\InfraAsset;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;

/**
 * اسکنِ ناوگان: عکسِ زندهٔ همهٔ زیرساخت‌ها را می‌گیرد و در `infra_assets` می‌نشاند.
 *
 * `CloudInventory` می‌گوید «الان چه می‌بینم». این کلاس آن را **ماندگار** می‌کند و
 * سه چیز اضافه می‌کند که بی‌آنها گزارش قابلِ استناد نیست:
 *
 * ۱. **زمان.** «از کِی بی‌صاحب است» و در نتیجه «تا حالا چقدر پول سوخته». همان
 *    عددی که تصمیمِ حذف را از حدس به حساب تبدیل می‌کند.
 * ۲. **حالتِ صریح به‌جای پرچم.** ماشینی که سرویسش خاتمه یافته ولی خودش زنده
 *    مانده، در گزارشِ زنده یک پرچمِ `service_dead` روی ردیفِ «متصل» است. این‌جا
 *    یک **حالتِ مستقل** (`zombie`) می‌شود، چون باید بشود مثلِ بقیهٔ حالت‌ها
 *    رویش فیلتر و مرتب‌سازی کرد و سنِ رهاشدگی‌اش را شمرد — همان نشتی‌ای که
 *    کارفرما گزارش کرد: مشتری سرویسش را حذف می‌کند، سمتِ زیرساخت باز می‌ماند.
 *    ⚠️ یک تفاوتِ عمدی با پرچمِ گزارشِ زنده: ماشینی که ردیفِ سرویسش **اصلاً
 *    وجود ندارد** هم این‌جا zombie است. آن‌جا `service_dead=false` می‌شود چون
 *    `$ci->service` نال است؛ ولی سرویسِ ناموجود قطعاً درآمدی ندارد.
 * ۳. **پول.** بهایِ تمام‌شدهٔ ماهانهٔ هر ماشین از کاتالوگ، تا جمعِ نشتی یک عددِ
 *    یورویی باشد نه یک تعداد.
 *
 * 🔴 **قاعدهٔ ایمنیِ به‌ارث‌رسیده:** زیرساختی که پاسخ نداد، هیچ ردیفی از آن
 * دست‌کاری نمی‌شود. توکنِ منقضی نباید ناوگان را «ناپدید» اعلام کند. این‌جا خطرش
 * از گزارشِ زنده بیشتر است، چون نتیجه **نوشته** می‌شود و اشتباه ماندگار می‌ماند.
 */
class FleetScanner
{
    public function __construct(
        private CloudInventory $inventory,
        private CloudManager $manager,
    ) {}

    /** کلیدِ کش برای «آخرین اسکن کِی بود و چه گفت» */
    public const LAST_SCAN_KEY = 'fleet.last_scan';

    /**
     * یک دورِ کامل: پرسیدن، طبقه‌بندی، نوشتن.
     *
     * @param  array<int,string>|null  $providers  خالی = همهٔ زیرساخت‌های تنظیم‌شده
     * @return array{
     *   ok: bool, checked: array<int,string>, errors: array<string,string>,
     *   counts: array<string,int>, seen: int, removed: int, at: string
     * }
     */
    public function scan(?array $providers = null): array
    {
        $report = $this->inventory->reconcile($providers);

        // زیرساخت‌هایی که هم پرسیده شدند و هم بی‌خطا جواب دادند. فقط ردیف‌های
        // این‌ها حق دارند «ناپدید» یا «حذف» شوند.
        $trusted = array_values(array_diff($report['checked'], array_keys($report['errors'])));

        $desired = $this->classify($report);
        $now = now();
        $seenKeys = [];
        $counts = [
            InfraAsset::STATE_ATTACHED => 0,
            InfraAsset::STATE_ORPHAN   => 0,
            InfraAsset::STATE_ZOMBIE   => 0,
            InfraAsset::STATE_GHOST    => 0,
        ];

        foreach ($desired as $row) {
            $seenKeys[$row['provider'].'|'.$row['provider_ref']] = true;
            $counts[$row['link_state']] = ($counts[$row['link_state']] ?? 0) + 1;
            $this->persist($row, $now);
        }

        $removed = $this->forgetVanished($trusted, $seenKeys);

        $result = [
            'ok'      => $report['errors'] === [],
            'checked' => $report['checked'],
            'errors'  => $report['errors'],
            'counts'  => $counts,
            'seen'    => count($desired),
            'removed' => $removed,
            'at'      => $now->toIso8601String(),
        ];

        Cache::forever(self::LAST_SCAN_KEY, $result);

        return $result;
    }

    /** آخرین اسکن — برای نوارِ بالای صفحه. null یعنی هرگز اسکن نشده. */
    public static function lastScan(): ?array
    {
        $v = Cache::get(self::LAST_SCAN_KEY);

        return is_array($v) ? $v : null;
    }

    // ───────────────────────── طبقه‌بندی ─────────────────────────

    /**
     * خروجیِ `reconcile` → ردیف‌های آمادهٔ نوشتن.
     *
     * @return array<int,array<string,mixed>>
     */
    private function classify(array $report): array
    {
        // سرویس‌ها یک‌جا خوانده می‌شوند: صد ماشین یعنی صد پرس‌وجو اگر تک‌تک بخوانیم.
        $serviceIds = collect($report['attached'])->pluck('service_id')
            ->merge(collect($report['ghosts'])->pluck('service_id'))
            ->filter()->unique()->all();

        $services = Service::query()
            ->whereIn('id', $serviceIds)
            ->get(['id', 'customer_id', 'name', 'status', 'provision_status', 'cloud_plan_id'])
            ->keyBy('id');

        $planCost = $this->planCostIndex();
        $out = [];

        foreach ($report['attached'] as $a) {
            $service = $services[$a['service_id'] ?? null] ?? null;

            // ⚠️ مرزِ zombie این‌جا است و عمداً از خودِ `Service::isDead()`
            // می‌آید نه از یک فهرستِ رشته‌ایِ محلی. اگر روزی وضعیتِ مردهٔ تازه‌ای
            // اضافه شود، این‌جا هم خودبه‌خود درست می‌ماند.
            $dead = $service === null || $service->isDead();

            $out[] = $this->row($a, [
                'link_state'     => $dead ? InfraAsset::STATE_ZOMBIE : InfraAsset::STATE_ATTACHED,
                'service_id'     => $a['service_id'] ?? null,
                'customer_id'    => $service?->customer_id,
                'service_status' => $service?->status,
                'ip_mismatch'    => (bool) ($a['ip_mismatch'] ?? false),
            ], $planCost, $service);
        }

        foreach ($report['orphans'] as $o) {
            $out[] = $this->row($o, ['link_state' => InfraAsset::STATE_ORPHAN], $planCost, null);
        }

        foreach ($report['ghosts'] as $g) {
            $service = $services[$g['service_id'] ?? null] ?? null;

            $out[] = $this->row([
                'provider' => $g['provider'],
                'ref'      => $g['ref'],
                'name'     => $g['service_name'] ?? null,
                'ipv4'     => $g['ipv4'] ?? null,
                'status'   => 'deleted',
            ], [
                'link_state'     => InfraAsset::STATE_GHOST,
                'service_id'     => $g['service_id'] ?? null,
                'customer_id'    => $service?->customer_id,
                'service_status' => $service?->status ?? ($g['service_status'] ?? null),
            ], $planCost, $service);
        }

        return $out;
    }

    /**
     * یک ردیفِ خام از زیرساخت + طبقه‌بندی → آرایهٔ ستون‌ها.
     *
     * @param  array<string,int>  $planCost  «provider|plan_ref» → سنتِ یورو
     */
    private function row(array $src, array $class, array $planCost, ?Service $service): array
    {
        $provider = (string) ($src['provider'] ?? '');
        $planRef = $src['plan'] ?? null;

        // بها: اول از پلنِ خودِ سرویس (دقیق‌ترین)، بعد از نگاشتِ پلنِ زیرساخت
        // (برای یتیمی که سرویسی ندارد)، و در نهایت «نمی‌دانم».
        $cost = 0;
        $costSource = 'unknown';

        if ($service?->cloud_plan_id !== null && isset($planCost['#'.$service->cloud_plan_id])) {
            $cost = $planCost['#'.$service->cloud_plan_id];
            $costSource = 'service';
        } elseif ($planRef !== null && isset($planCost[$provider.'|'.strtolower((string) $planRef)])) {
            $cost = $planCost[$provider.'|'.strtolower((string) $planRef)];
            $costSource = 'plan';
        }

        return array_merge([
            'provider'            => $provider,
            'provider_ref'        => (string) ($src['ref'] ?? ''),
            'name'                => $src['name'] ?? null,
            'ipv4'                => $src['ipv4'] ?? null,
            'ipv6'                => $src['ipv6'] ?? null,
            'plan_ref'            => $planRef,
            'location_ref'        => $src['location'] ?? null,
            'provider_status'     => (string) ($src['status'] ?? 'unknown'),
            'provider_created_at' => $this->parseDate($src['created'] ?? null),
            'service_id'          => null,
            'customer_id'         => null,
            'service_status'      => null,
            'ip_mismatch'         => false,
            'cost_eur_cents'      => $cost,
            'cost_source'         => $costSource,
        ], $class);
    }

    /**
     * نگاشتِ بهایِ ماهانه.
     *
     * دو کلیدِ متفاوت در یک آرایه: `#۱۲` برای شناسهٔ پلنِ خودمان و
     * `hetzner|cx22` برای شناسهٔ پلن نزدِ زیرساخت. دومی همان چیزی است که
     * `listServers` برمی‌گرداند و تنها راهِ قیمت‌گذاریِ سرورِ یتیم است.
     *
     * ⚠️ اگر دو ردیفِ پلن با یک `provider_ref` باشند (یک پلن در دو مکان)،
     * **گران‌ترین** برنده می‌شود. تخمینِ محافظه‌کارانه در جهتِ درست: عددِ کمتر،
     * نشتی را کوچک‌تر از واقع نشان می‌دهد و تصمیمِ حذف را عقب می‌اندازد.
     *
     * @return array<string,int>
     */
    private function planCostIndex(): array
    {
        $out = [];

        CloudPlan::query()
            ->get(['id', 'provider', 'provider_ref', 'cost_eur_cents'])
            ->each(function (CloudPlan $p) use (&$out) {
                $cents = (int) $p->cost_eur_cents;
                $out['#'.$p->id] = $cents;

                $key = $p->provider.'|'.strtolower((string) $p->provider_ref);
                $out[$key] = max($out[$key] ?? 0, $cents);
            });

        return $out;
    }

    private function parseDate(?string $raw): ?\Illuminate\Support\Carbon
    {
        if (blank($raw)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    // ───────────────────────── نوشتن ─────────────────────────

    /**
     * ردیف را می‌نشاند و **حافظهٔ زمانی** را نگه می‌دارد.
     *
     * ⚠️ ستون‌هایی که مدیر پر می‌کند (`role`, `note`, `acknowledged_at`) هرگز
     * از اسکن بازنویسی نمی‌شوند. اسکنی که یادداشتِ مدیر را پاک کند، بارِ دوم
     * دیگر استفاده نمی‌شود.
     */
    private function persist(array $row, \Illuminate\Support\Carbon $now): void
    {
        $asset = InfraAsset::firstOrNew([
            'provider'     => $row['provider'],
            'provider_ref' => $row['provider_ref'],
        ]);

        $isLeaking = in_array($row['link_state'], InfraAsset::LEAKING_STATES, true);

        /*
        | ⚠️ ردیفِ شبح مشخصاتِ ماشین را ندارد — از `cloud_instances` ساخته شده،
        | پس `name`ِ آن نامِ **سرویس** است نه هاست‌نیمِ ماشین، و پلن/مکان اصلاً
        | ندارد. اگر همین‌ها روی ردیفِ موجود بنشینند، ماشینی که تا دیروز
        | `sn-svc-101` بود و پلن و مکان داشت، به‌محضِ ناپدیدشدن هویتش را از دست
        | می‌دهد — درست همان لحظه‌ای که مدیر می‌خواهد بداند چه چیزی گم شده.
        */
        if ($row['link_state'] === InfraAsset::STATE_GHOST && $asset->exists) {
            foreach (['name', 'plan_ref', 'location_ref', 'provider_created_at'] as $keep) {
                if (filled($asset->{$keep})) {
                    unset($row[$keep]);
                }
            }
        }

        $asset->fill($row);

        if (! $asset->exists) {
            $asset->first_seen_at = $now;
        }

        if ($row['link_state'] === InfraAsset::STATE_GHOST) {
            // شبح یعنی زیرساخت نمی‌شناسدش؛ «آخرین بار دیده شد» را جلو نمی‌بریم.
            $asset->missing_since ??= $now;
        } else {
            $asset->last_seen_at = $now;
            $asset->missing_since = null;
        }

        if ($isLeaking) {
            // شروعِ بی‌صاحبی فقط **یک بار** ثبت می‌شود. اگر هر اسکن تازه‌اش
            // می‌کرد، «چند روز است رها شده» همیشه صفر می‌ماند و کلِ محاسبهٔ
            // ضرر بی‌معنا می‌شد. (ردیفی که بی‌صاحب نبود، حتماً `unlinked_since`
            // خالی دارد — شاخهٔ `else` پاکش می‌کند.)
            $asset->unlinked_since = $asset->unlinked_since ?? $now;
        } else {
            $asset->unlinked_since = null;

            // بازگشت به «متصل» یعنی تصمیمِ قبلی موضوعیت ندارد؛ تأییدِ کهنه
            // نباید نشتیِ بعدیِ همین ماشین را خاموش نگه دارد.
            $asset->acknowledged_at = null;
            $asset->acknowledged_by = null;
        }

        $asset->save();
    }

    /**
     * ردیف‌هایی که دیگر نه نزدِ زیرساخت‌اند و نه سرویسی ادعاشان می‌کند: پاک.
     *
     * ⚠️ فقط برای زیرساخت‌های **مطمئن**. ردیفِ زیرساختی که پاسخ نداد دست‌نخورده
     * می‌ماند — وگرنه یک قطعیِ گذرای API، کلِ ناوگانِ آن زیرساخت را پاک می‌کرد و
     * اسکنِ بعدی همه را «تازه‌کشف‌شده» می‌ساخت، یعنی سنِ بی‌صاحبی صفر می‌شد و
     * دقیقاً همان چیزی که این ابزار می‌سنجد از بین می‌رفت.
     *
     * @param  array<int,string>  $trusted
     * @param  array<string,bool>  $seenKeys
     */
    private function forgetVanished(array $trusted, array $seenKeys): int
    {
        if ($trusted === []) {
            return 0;
        }

        $doomed = InfraAsset::query()
            ->whereIn('provider', $trusted)
            ->get(['id', 'provider', 'provider_ref'])
            ->reject(fn (InfraAsset $a) => isset($seenKeys[$a->provider.'|'.$a->provider_ref]))
            ->pluck('id');

        if ($doomed->isEmpty()) {
            return 0;
        }

        return InfraAsset::whereIn('id', $doomed)->delete();
    }
}
