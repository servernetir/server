<?php

namespace App\Console\Commands;

use App\Models\CdnEvent;
use App\Models\CdnRecord;
use App\Models\CdnZone;
use App\Models\Setting;
use App\Services\Dns\DnsConfig;
use App\Services\Dns\PowerDnsClient;
use App\Services\Dns\ZonePublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * تشخیصِ drift میانِ دیتابیس (حقیقت) و primary — قرارداد §4.5. ساعتی.
 *
 * zoneِ منتشرشده‌ای که روی primary با وضعیتِ مطلوب نمی‌خوانَد (کسی دستی تغییر
 * داده، بازگردانیِ پشتیبانِ کهنه، …) ثبت و دوباره منتشر می‌شود. zoneی که روی
 * primary هست و در دیتابیس نیست «یتیم» است: فقط گزارش می‌شود، **هرگز** خودکار
 * پاک نمی‌شود — شاید zoneِ کسی است که هنوز به ما تفویض شده.
 */
class DnsReconcile extends Command
{
    protected $signature = 'dns:reconcile {--zone= : فقط یک دامنه}';

    public const REPORT_KEY = 'dns_reconcile_report';

    protected $description = 'Managed DNS: detect drift between DB and the PowerDNS primary; report orphans';

    public function handle(PowerDnsClient $pdns, ZonePublisher $publisher): int
    {
        if (! Schema::hasTable('cdn_zones') || ! Schema::hasColumn('cdn_zones', 'desired_version')) {
            return self::SUCCESS;
        }
        if (! $pdns->isConfigured()) {
            $this->line('not configured');

            return self::SUCCESS;
        }

        $q = CdnZone::query()->where('mode', 'ns')->where('publish_state', 'published')->whereIn('status', ['pending', 'active']);
        if ($only = $this->option('zone')) {
            $q->where('domain', strtolower(rtrim((string) $only, '.')));
        }

        $drift = 0;
        $failed = 0;
        $q->orderBy('id')->each(function (CdnZone $zone) use ($pdns, $publisher, &$drift, &$failed) {
            $live = $pdns->getZone($zone->domain);
            if (! $live['ok'] && $live['status'] === 404) {
                // drift است، نه شکست: دوباره ساخته می‌شود
                $drift++;
                CdnEvent::audit('drift_detected', $zone, ['changes' => 'missing_on_primary'], 'system');
                $zone->bumpVersion();
                $this->line("~ {$zone->domain}: missing on primary — republish queued");

                return;
            }
            if (! $live['ok']) {
                $failed++;
                $this->line("✗ {$zone->domain}: ".$live['error']);

                return;
            }
            $records = CdnRecord::query()->where('cdn_zone_id', $zone->id)->where('disabled', false)->orderBy('id')->get();
            $diff = $publisher->diff($publisher->desired($zone->domain, $records), (array) ($live['data']['rrsets'] ?? []));
            if ($diff !== []) {
                $drift++;
                CdnEvent::audit('drift_detected', $zone, ['changes' => count($diff)], 'system');
                $zone->bumpVersion();
                $this->line("~ {$zone->domain}: ".count($diff).' rrset(s) differ — republish queued');
            }
        });

        $orphans = [];
        $list = $pdns->listZones();
        if ($list['ok']) {
            $known = CdnZone::query()->pluck('domain')->all();
            $orphans = array_values(array_diff($list['names'], $known, [rtrim(DnsConfig::catalog(), '.')]));
            foreach ($orphans as $o) {
                $this->line("? orphan on primary: $o");
            }
        }

        $this->info("drift: {$drift} · read failures: {$failed} · orphans: ".count($orphans));

        // گزارش برای /admin/dns — خروجیِ کرون را کسی نمی‌خواند (§4.5).
        // ⚠️ یتیم‌ها فقط گزارش می‌شوند و هرگز خودکار حذف نمی‌شوند.
        if ($this->option('zone') === null) {
            Setting::put(self::REPORT_KEY, json_encode([
                'at' => now()->toIso8601String(), 'drift' => $drift, 'failed' => $failed,
                'orphans' => array_slice($orphans, 0, 100), 'orphan_count' => count($orphans),
                'listed' => $list['ok'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // یافته شکستِ فرمان نیست (ScheduledFindingIsNotAFailureTest): خطای خواندن
        // backoff ندارد ولی در گزارشِ بالا و در /admin/dns دیده می‌شود.
        return self::SUCCESS;
    }
}
