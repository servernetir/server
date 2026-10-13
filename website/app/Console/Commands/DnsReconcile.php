<?php

namespace App\Console\Commands;

use App\Models\CdnZone;
use App\Services\Dns\PowerDnsClient;
use App\Services\Dns\ZoneService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * آشتیِ دیتابیس ↔ PowerDNS.
 *
 * هر ویرایشِ رکورد بلافاصله فشار داده می‌شود، ولی آن فشار می‌تواند شکست
 * بخورد (سرورِ DNS لحظه‌ای در دسترس نبود) و تنها نشانه‌اش «سایتِ مشتری
 * بالا نمی‌آید» است. این فرمان zoneهای `dirty` را دوباره می‌فرستد.
 *
 * `--all` همه را می‌فرستد — برای بعد از بازسازیِ سرورِ DNS یا عوض‌کردنِ
 * نیم‌سرورها در تنظیمات (NSِ apex و SOAِ همهٔ zoneها باید عوض شوند).
 * چون `syncZone` فقط تفاوت را می‌فرستد، `--all` روی zoneِ سالم بی‌هزینه است.
 */
class DnsReconcile extends Command
{
    protected $signature = 'dns:reconcile {--all : همهٔ zoneها، نه فقط تغییرکرده‌ها} {--zone= : فقط یک دامنه}';

    protected $description = 'zoneهای DNSِ مدیریت‌شده را با سرورِ PowerDNS همگام می‌کند';

    public function handle(ZoneService $zones, PowerDnsClient $pdns): int
    {
        if (! Schema::hasTable('cdn_zones')) {
            return self::SUCCESS;
        }

        if (! $pdns->isConfigured()) {
            // «هنوز راه نیفتاده» خرابی نیست؛ SystemHealth جای دیگری گزارشش می‌دهد
            $this->warn('سرورِ DNS پیکربندی نشده؛ چیزی فرستاده نشد.');

            return self::SUCCESS;
        }

        $q = CdnZone::query()->where('mode', 'ns');

        if ($only = $this->option('zone')) {
            $q->where('domain', CdnZone::normalizeDomain($only));
        } elseif (! $this->option('all')) {
            $q->where('dirty', true);
        }

        $ok = 0;
        $fail = 0;

        $q->orderBy('id')->each(function (CdnZone $zone) use ($zones, &$ok, &$fail) {
            $res = $zones->sync($zone);

            if ($res['ok']) {
                $ok++;
            } else {
                $fail++;
                $this->line("✗ {$zone->domain}: {$res['reason']}");
            }
        });

        $this->info("همگام: {$ok} · ناموفق: {$fail}");

        // ناموفق = کدِ خطا، تا `schedule:run` و SystemHealth ببینندش
        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
