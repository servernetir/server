<?php

namespace App\Services\Provisioning;

use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Support\ErrorTracker;

/** کفِ فروشِ فضای اشتراکی بر پایهٔ هزینهٔ واقعی Gateway و ظرفیتِ قابل‌فروش. */
class RcloneStorageCosts
{
    public function floorForProduct(Product $product, int $months): int
    {
        if (! $product->server_id) {
            return 0;
        }

        return $this->floor($product->server, (string) $product->plan, $months);
    }

    /** null یعنی آمادهٔ فروش است؛ متن یعنی فروش باید پیش از دریافت پول بسته شود. */
    public function configurationError(Product $product): ?string
    {
        if (! $product->server_id || $product->server?->type !== 'rclone_storage') {
            return null;
        }

        $server = $product->server;
        $quota = config('provisioning.rclone_storage.plans.'.(string) $product->plan);
        $capacity = (int) config('provisioning.rclone_storage.capacity_bytes', 0);
        $reservePct = min(95.0, max(0.0, (float) config('provisioning.rclone_storage.reserve_pct', 20)));
        $sellable = (int) floor($capacity * (100 - $reservePct) / 100);

        if (! is_numeric($quota) || (int) $quota <= 0) {
            return 'سهمیهٔ این پلن برای Gateway تعریف نشده است.';
        }
        if ($sellable < (int) $quota) {
            return 'ظرفیت قابل‌فروش Gateway تعریف نشده یا از سهمیهٔ این پلن کمتر است.';
        }
        if ($server->monthly_cost === null) {
            return 'هزینهٔ ماهانهٔ Gateway ثبت نشده است.';
        }
        if (blank($server->hostname) || blank($server->api_token)) {
            return 'آدرس یا کلید مدیریت Gateway ثبت نشده است.';
        }

        return null;
    }

    public function floorForService(Service $service, int $months): int
    {
        return $this->floor($service->server, (string) $service->plan, $months);
    }

    private function floor(?Server $server, string $plan, int $months): int
    {
        if ($months <= 0 || ! $server || $server->type !== 'rclone_storage') {
            return 0;
        }

        $quota = config('provisioning.rclone_storage.plans.'.$plan);
        $capacity = (int) config('provisioning.rclone_storage.capacity_bytes', 0);
        $reservePct = min(95.0, max(0.0, (float) config('provisioning.rclone_storage.reserve_pct', 20)));
        $sellable = (int) floor($capacity * (100 - $reservePct) / 100);
        $monthlyCost = $server->monthlyCostToman();

        if (! is_numeric($quota) || (int) $quota <= 0 || $sellable <= 0 || $monthlyCost === null) {
            ErrorTracker::noteOnce('pricing',
                'کفِ قیمت Gateway «'.$server->name.'» محاسبه نشد: هزینه، ظرفیت یا سهمیهٔ پلن کامل نیست.', 3600);

            return 0;
        }

        $allocatedMonthly = (int) ceil($monthlyCost * (int) $quota / $sellable);
        $margin = max(0.0, (float) config('provisioning.rclone_storage.min_margin_pct', 20));

        return (int) ceil($allocatedMonthly * $months * (1 + $margin / 100));
    }
}
