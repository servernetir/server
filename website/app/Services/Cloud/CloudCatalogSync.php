<?php

namespace App\Services\Cloud;

use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * همگام‌سازیِ کاتالوگِ ابری از همهٔ ارائه‌دهنده‌ها.
 *
 * سه قاعدهٔ سخت که از تجربهٔ همین پروژه آمده‌اند:
 *
 * ۱) **هیچ‌وقت حذف نکن، غیرفعال کن.** اگر ارائه‌دهنده پلنی را برداشت و ما ردیف
 *    را DELETE کنیم، سرویسِ فعالِ مشتری به پلنِ ناموجود اشاره می‌کند و صفحهٔ
 *    پنلش می‌شکند. `is_active=false` هم فروش را می‌بندد هم تاریخ را نگه می‌دارد.
 *
 * ۲) **برچسبِ دستیِ مدیر را بازنویسی نکن.** اگر مدیر برای «آلمان — فرانکفورت»
 *    نامِ فارسیِ دلخواه گذاشت، سینکِ بعدی نباید پاکش کند.
 *
 * ۳) **قیمتِ صفر ذخیره نکن.** اگر نرخِ یورو در دسترس نبود، `price_irt` صفر
 *    می‌ماند و `scopeSellable` خودش پلن را از فروشگاه بیرون می‌گذارد — بهتر از
 *    فروختنِ سرورِ ۵۰ یورویی به قیمتِ صفر.
 */
class CloudCatalogSync
{
    public function __construct(
        private CloudManager $manager,
        private CloudPricing $pricing,
    ) {}

    /**
     * @return array{ok:bool,providers:array<string,array{ok:bool,message:string,locations:int,plans:int,images:int}>,rate:int}
     */
    public function sync(?string $only = null): array
    {
        if (! Schema::hasTable('cloud_plans')) {
            return ['ok' => false, 'providers' => [], 'rate' => 0, 'message' => 'جدول‌های ابری ساخته نشده‌اند — اول مهاجرت را بزنید.'];
        }

        $rate = $this->pricing->eurToToman();
        $report = [];

        foreach ($this->manager->configured() as $slug => $driver) {
            if ($only !== null && $only !== $slug) {
                continue;
            }

            try {
                $report[$slug] = $this->syncOne($slug, $driver);
            } catch (\Throwable $e) {
                // یک ارائه‌دهندهٔ خراب نباید سینکِ دیگری را بخواباند
                Log::error('cloud.sync.failed', ['provider' => $slug, 'err' => $e->getMessage()]);

                $report[$slug] = [
                    'ok' => false, 'message' => 'خطای غیرمنتظره: '.$e->getMessage(),
                    'locations' => 0, 'plans' => 0, 'images' => 0,
                ];
            }
        }

        return [
            'ok'        => $report !== [] && collect($report)->contains('ok', true),
            'providers' => $report,
            'rate'      => $rate,
        ];
    }

    /** @return array{ok:bool,message:string,locations:int,plans:int,images:int} */
    private function syncOne(string $provider, CloudProvider $driver): array
    {
        $cat = $driver->fetchCatalog();

        if (! ($cat['ok'] ?? false)) {
            return [
                'ok' => false, 'message' => (string) ($cat['message'] ?? 'کاتالوگ خوانده نشد.'),
                'locations' => 0, 'plans' => 0, 'images' => 0,
            ];
        }

        $locations = $this->syncLocations((array) $cat['locations']);
        $images    = $this->syncImages($provider, (array) $cat['images']);
        $plans     = $this->syncPlans($provider, (array) $cat['plans']);

        return [
            'ok' => true, 'message' => '',
            'locations' => $locations, 'plans' => $plans, 'images' => $images,
        ];
    }

    // ───────────────────────── مکان‌ها ─────────────────────────

    private function syncLocations(array $rows): int
    {
        $n = 0;

        foreach ($rows as $r) {
            $code = (string) ($r['code'] ?? '');

            if ($code === '') {
                continue;
            }

            $loc = CloudLocation::firstOrNew(['code' => $code]);

            // ستون‌های واقعی همیشه تازه می‌شوند…
            $loc->country = (string) ($r['country'] ?? $loc->country ?? '');
            $loc->city = $r['city'] ?? $loc->city;
            $loc->latitude = $r['latitude'] ?? $loc->latitude;
            $loc->longitude = $r['longitude'] ?? $loc->longitude;

            // …ولی برچسبِ دستیِ مدیر و پرچم دست نمی‌خورند
            if (! $loc->exists) {
                $meta = CloudLocation::COUNTRIES[strtoupper((string) $loc->country)] ?? null;
                $loc->flag = $meta['flag'] ?? null;
                $loc->is_active = true;
                $loc->sort = 0;
            }

            $loc->save();
            $n++;
        }

        return $n;
    }

    // ───────────────────────── ایمیج‌ها ─────────────────────────

    private function syncImages(string $provider, array $rows): int
    {
        $seen = [];
        $n = 0;

        foreach ($rows as $r) {
            $ref = (string) ($r['provider_ref'] ?? '');
            $key = (string) ($r['key'] ?? '');

            if ($ref === '' || $key === '') {
                continue;
            }

            CloudImage::updateOrCreate(
                ['provider' => $provider, 'provider_ref' => $ref],
                [
                    'key'         => $key,
                    'kind'        => (string) ($r['kind'] ?? 'os'),
                    'family'      => $r['family'] ?? null,
                    'version'     => $r['version'] ?? null,
                    'label'       => (string) ($r['label'] ?? $key),
                    'arch'        => (string) ($r['arch'] ?? 'x86'),
                    'min_disk_gb' => (int) ($r['min_disk_gb'] ?? 0),
                    'is_active'   => true,
                ]
            );

            $seen[] = $ref;
            $n++;
        }

        // ایمیجِ برداشته‌شده: غیرفعال، نه حذف (ممکن است سرورِ فعالی رویش باشد)
        if ($seen !== []) {
            CloudImage::where('provider', $provider)
                ->whereNotIn('provider_ref', $seen)
                ->update(['is_active' => false]);
        }

        return $n;
    }

    // ───────────────────────── پلن‌ها ─────────────────────────

    private function syncPlans(string $provider, array $rows): int
    {
        $seen = [];
        $n = 0;

        foreach ($rows as $r) {
            $ref = (string) ($r['provider_ref'] ?? '');
            $code = (string) ($r['location_code'] ?? '');
            $cost = (int) ($r['cost_eur_cents'] ?? 0);

            if ($ref === '' || $code === '' || $cost <= 0) {
                continue;
            }

            $vcpu = max(1, (int) ($r['vcpu'] ?? 1));
            $ram = max(128, (int) ($r['ram_mb'] ?? 1024));
            $disk = max(1, (int) ($r['disk_gb'] ?? 10));
            $cpuKind = ($r['cpu_kind'] ?? 'shared') === 'dedicated' ? 'dedicated' : 'shared';

            $price = $this->pricing->priceFor($cost);

            CloudPlan::updateOrCreate(
                ['provider' => $provider, 'provider_ref' => $ref, 'location_code' => $code],
                [
                    'provider_location' => $r['provider_location'] ?? null,
                    'public_name'       => CloudNaming::planName($vcpu, $ram, $cpuKind),
                    'slug'              => CloudNaming::planSlug($vcpu, $ram, $disk, $code, $cpuKind),
                    'vcpu'              => $vcpu,
                    'ram_mb'            => $ram,
                    'disk_gb'           => $disk,
                    'disk_type'         => (string) ($r['disk_type'] ?? 'nvme'),
                    'traffic_gb'        => max(0, (int) ($r['traffic_gb'] ?? 0)),
                    'cpu_kind'          => $cpuKind,
                    'arch'              => (string) ($r['arch'] ?? 'x86'),
                    'cost_eur_cents'    => $cost,
                    'price_eur_cents'   => $price['eur_cents'],
                    'price_irt'         => $price['irt'],
                    'is_active'         => true,
                    'in_stock'          => (bool) ($r['in_stock'] ?? true),
                    // مرتب‌سازیِ طبیعی: کوچک به بزرگ. ۱۰۰۰ ضربِ ثابت است تا
                    // پلنِ ۸هسته/۱۶گیگ بعد از ۸هسته/۸گیگ بیاید.
                    'sort'              => min(65535, $vcpu * 1000 + (int) ($ram / 1024)),
                    'synced_at'         => now(),
                ]
            );

            $seen[] = $ref.'@'.$code;
            $n++;
        }

        // پلنی که این بار نیامد = برداشته شده. غیرفعال می‌شود تا از فروشگاه
        // بیرون برود، ولی ردیفش می‌ماند چون سرویسِ فعالِ مشتری به آن اشاره دارد.
        if ($seen !== []) {
            CloudPlan::where('provider', $provider)
                ->get(['id', 'provider_ref', 'location_code', 'is_active'])
                ->each(function (CloudPlan $p) use ($seen) {
                    if (! in_array($p->provider_ref.'@'.$p->location_code, $seen, true) && $p->is_active) {
                        $p->update(['is_active' => false]);
                    }
                });
        }

        return $n;
    }

    /**
     * بازمحاسبهٔ قیمت بی‌تماس با API — برای وقتی نرخِ یورو عوض شده ولی
     * بهایِ تمام‌شده همان است. سریع و بی‌هزینه.
     */
    public function reprice(): int
    {
        if (! Schema::hasTable('cloud_plans')) {
            return 0;
        }

        $n = 0;

        CloudPlan::query()->where('cost_eur_cents', '>', 0)->chunkById(200, function ($plans) use (&$n) {
            foreach ($plans as $plan) {
                $price = $this->pricing->priceFor((int) $plan->cost_eur_cents);

                if ($price['eur_cents'] !== (int) $plan->price_eur_cents || $price['irt'] !== (int) $plan->price_irt) {
                    $plan->update([
                        'price_eur_cents' => $price['eur_cents'],
                        'price_irt'       => $price['irt'],
                    ]);
                    $n++;
                }
            }
        });

        return $n;
    }
}
