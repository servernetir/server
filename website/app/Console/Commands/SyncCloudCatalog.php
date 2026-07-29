<?php

namespace App\Console\Commands;

use App\Services\Cloud\CloudCatalogSync;
use Illuminate\Console\Command;

/**
 * `cloud:sync` — کاتالوگِ سرورِ ابری را از ارائه‌دهنده‌ها تازه می‌کند.
 *
 * کارفرما: «دو روز یک‌بار قیمت‌ها آپدیت شود». دو حالت دارد:
 *
 *   cloud:sync            → تماس با API، مشخصات + بهایِ تمام‌شده + قیمتِ فروش
 *   cloud:sync --prices   → فقط بازمحاسبهٔ قیمت با نرخِ روزِ یورو (بی‌تماسِ API)
 *
 * حالتِ دوم ارزان است و می‌تواند روزانه بدود؛ حالتِ اول دو روز یک‌بار.
 */
class SyncCloudCatalog extends Command
{
    protected $signature = 'cloud:sync
                            {--provider= : فقط یک ارائه‌دهنده (hetzner|aeza)}
                            {--prices : فقط بازمحاسبهٔ قیمت، بدونِ تماس با API}';

    protected $description = 'همگام‌سازیِ کاتالوگِ سرورِ ابری (پلن، مکان، سیستم‌عامل) و قیمت‌گذاری';

    public function handle(CloudCatalogSync $sync): int
    {
        if ($this->option('prices')) {
            $n = $sync->reprice();
            $this->info("قیمتِ {$n} پلن بازمحاسبه شد.");

            return self::SUCCESS;
        }

        $report = $sync->sync($this->option('provider') ?: null);

        if (isset($report['message'])) {
            $this->warn($report['message']);

            return self::SUCCESS;
        }

        if ($report['providers'] === []) {
            $this->warn('هیچ ارائه‌دهندهٔ ابری تنظیم نشده — توکن را در /admin/settings وارد کنید.');

            return self::SUCCESS;
        }

        $rate = $report['rate'];
        $this->line($rate > 0 ? "نرخِ یورو: ".number_format($rate)." تومان" : 'نرخِ یورو در دسترس نیست — قیمتِ تومانی ساخته نشد.');

        foreach ($report['providers'] as $slug => $r) {
            if ($r['ok']) {
                $this->info(sprintf(
                    '%s: %d مکان، %d پلن، %d ایمیج.',
                    $slug, $r['locations'], $r['plans'], $r['images']
                ));
            } else {
                $this->error("{$slug}: {$r['message']}");
            }
        }

        return self::SUCCESS;
    }
}
