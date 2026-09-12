<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تخصیصِ پورتِ عمومیِ پایدار به ماشین‌های میزبانِ ایران.
 *
 * ═══ چرا این کلاس ساخته شد ═══
 *
 * 🔴 تا امروز تخصیص **داخلِ `PullController::portForwards()`** انجام می‌شد —
 * یعنی یک درخواستِ **GET** از عاملِ هاست، رکوردِ دیتابیس را عوض می‌کرد
 * (`$inst->meta[...] = $port; $inst->save();`). سه پیامد داشت که هیچ‌کدام
 * خطا تولید نمی‌کردند:
 *
 *   ۱ هر ادعای «مشاهده هیچ اثری ندارد» دروغ بود؛ کافی بود عامل یک بار بپرسد.
 *   ۲ دو عاملِ هم‌زمان (یا یک عامل با تلاشِ دوباره) می‌توانستند دو پورت بگیرند،
 *     چون هیچ قفلی در کار نبود و «پورتهای مصرف‌شده» بیرونِ تراکنش خوانده می‌شد.
 *   ۳ ثبتِ هر ردیف در `cloud_instances` به‌تنهایی یک تغییرِ شبکه می‌ساخت —
 *     پس «فقط موجودی را ببینیم» عملاً ممکن نبود.
 *
 * حالا تخصیص فقط از مسیرهای **نوشتنی** صدا زده می‌شود (ثبت، اتصال به مشتری،
 * فرمانِ همگام‌سازی، دکمهٔ مدیر) و همیشه زیرِ یک تراکنش با قفل.
 *
 * ⚠️ قیدِ یکتاییِ دیتابیسی نداریم چون پورت داخلِ `meta` (JSON) است. پس قفل
 * **تنها** محافظ است: هر تخصیصی باید از همین‌جا برود، نه با دست‌کاریِ مستقیمِ
 * `meta['public_port']`.
 */
class PublicPortAllocator
{
    /** وضعیت‌هایی که «زنده» شمرده می‌شوند و سزاوارِ پورت‌اند. */
    public const LIVE_STATES = ['building', 'running', 'off'];

    public function min(): int
    {
        return (int) config('servernet.exit.sale_port_min', 20000);
    }

    public function max(): int
    {
        return (int) config('servernet.exit.sale_port_max', 20999);
    }

    /**
     * ماشین‌هایی که باید پورت داشته باشند.
     *
     * ⚠️ `off` عمداً داخل است. فیلترِ قبلی فقط `building|running` بود، یعنی
     * ماشینِ خاموش پورتش را از خروجیِ عامل از دست می‌داد و روشن‌شدنِ دوباره
     * می‌توانست پورتِ تازه بگیرد — یعنی مشتری با یک ری‌استارت آدرسِ اتصالش عوض
     * می‌شد. تخصیص باید از چرخهٔ روشن/خاموش مستقل باشد.
     *
     * @return Collection<int, CloudInstance>
     */
    public function eligible(): Collection
    {
        return CloudInstance::query()
            ->where('provider', 'proxmox')
            ->whereIn('status', self::LIVE_STATES)
            ->whereNotNull('ipv4')
            ->where('ipv4', '!=', '')
            ->get();
    }

    /**
     * ماشین‌های سزاوارِ پورت که هنوز پورت ندارند — همان چیزی که پیش از این
     * بی‌صدا در یک GET درست می‌شد و حالا باید دیده شود.
     *
     * @return Collection<int, CloudInstance>
     */
    public function missing(): Collection
    {
        return $this->eligible()->filter(fn (CloudInstance $i) => $i->publicPort() <= 0)->values();
    }

    /**
     * تخصیصِ پورت به یک ماشین. اگر از قبل داشته باشد همان برمی‌گردد (idempotent).
     * `null` یعنی محدوده پر است.
     */
    public function allocate(CloudInstance $instance): ?int
    {
        return DB::transaction(function () use ($instance) {
            // داخلِ تراکنش دوباره می‌خوانیم: ممکن است بینِ تصمیم و قفل، کسِ
            // دیگری همین ماشین را تخصیص داده باشد.
            $fresh = CloudInstance::query()->whereKey($instance->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return null;
            }

            if (($port = $fresh->publicPort()) > 0) {
                $instance->setRawAttributes($fresh->getAttributes(), true);

                return $port;
            }

            $port = $this->lowestFree($this->usedPorts());

            if ($port === null) {
                return null;
            }

            $fresh->meta = array_merge($fresh->meta ?? [], ['public_port' => $port]);
            $fresh->save();

            $instance->setRawAttributes($fresh->getAttributes(), true);

            return $port;
        });
    }

    /**
     * تخصیص به همهٔ ماشین‌های بی‌پورت.
     *
     * @return array{allocated:int, exhausted:int, ports:array<int,int>}
     */
    public function syncMissing(): array
    {
        $allocated = 0;
        $exhausted = 0;
        $ports = [];

        foreach ($this->missing() as $inst) {
            $port = $this->allocate($inst);

            if ($port === null) {
                $exhausted++;

                continue;
            }

            $allocated++;
            $ports[$inst->id] = $port;
        }

        return ['allocated' => $allocated, 'exhausted' => $exhausted, 'ports' => $ports];
    }

    /**
     * پورتهای مصرف‌شده روی **هر** نمونه، نه فقط زنده‌ها — ماشینِ خاموش یا
     * حذف‌شده هم تا وقتی رکوردش هست پورتش رزرو می‌مانَد، وگرنه پورت به ماشینِ
     * دیگری می‌رسد و قاعدهٔ قدیمیِ هاست ترافیک را به مقصدِ اشتباه می‌برد.
     *
     * @return array<int,bool>
     */
    private function usedPorts(): array
    {
        $used = [];

        foreach (CloudInstance::query()->whereNotNull('meta')->get(['id', 'meta']) as $row) {
            $p = (int) (($row->meta ?? [])['public_port'] ?? 0);

            if ($p > 0) {
                $used[$p] = true;
            }
        }

        return $used;
    }

    /** @param  array<int,bool>  $used */
    private function lowestFree(array $used): ?int
    {
        for ($p = $this->min(); $p <= $this->max(); $p++) {
            if (! isset($used[$p])) {
                return $p;
            }
        }

        return null;
    }

    /** آی‌پیِ عمومیِ پیکربندی‌شده (برای ساختنِ آدرسِ نهایی). */
    public function publicIp(): string
    {
        return (string) (Setting::get('public_ip') ?: config('servernet.exit.public_ip', ''));
    }
}
