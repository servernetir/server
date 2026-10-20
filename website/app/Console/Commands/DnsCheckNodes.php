<?php

namespace App\Console\Commands;

use App\Models\CdnZone;
use App\Models\DnsZoneNode;
use App\Services\Dns\NodeVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * سنجشِ مستقیمِ هر نیم‌سرور برای zoneهای منتشرشده — قرارداد §4.3. هر دو دقیقه.
 *
 * تازه‌منتشرشده‌ها (۲۴ ساعتِ اخیر) و آن‌هایی که روی همهٔ نودها in_sync نیستند هر بار؛
 * بقیه ساعتی (با قدیمی‌ترین بررسی اول، تا هیچ zoneی همیشه جا نماند).
 */
class DnsCheckNodes extends Command
{
    protected $signature = 'dns:check-nodes {--zone=} {--limit=200}';

    protected $description = 'Managed DNS: query each public nameserver directly and record per-node sync state';

    public function handle(NodeVerifier $verifier): int
    {
        // مهاجرتِ نیمه‌کاره روی MariaDB (DDL تراکنشی نیست): جدولِ نودها هست ولی ستون‌های cdn_zones نه
        if (! Schema::hasTable('dns_zone_nodes') || ! Schema::hasTable('cdn_zones') || ! Schema::hasColumn('cdn_zones', 'primary_serial')) {
            return self::SUCCESS;
        }

        $q = CdnZone::query()->where('mode', 'ns')->whereIn('status', ['pending', 'active'])->whereNotNull('primary_serial');
        if ($only = $this->option('zone')) {
            $q->where('domain', strtolower(rtrim((string) $only, '.')));
        } else {
            $hourAgo = now()->subHour();
            $q->where(function ($w) use ($hourAgo) {
                $w->where('published_at', '>=', now()->subDay())
                    ->orWhereHas('nodes', fn ($n) => $n->where('state', '!=', DnsZoneNode::IN_SYNC))
                    ->orWhereDoesntHave('nodes')
                    ->orWhereHas('nodes', fn ($n) => $n->where('checked_at', '<', $hourAgo));
            });
        }

        $n = 0;
        // قدیمی‌ترین بررسی اول (بی‌بررسی‌ها پیش از همه)، تا با سقفِ limit هیچ zoneی همیشه جا نماند
        $oldest = '(select min(n.checked_at) from dns_zone_nodes n where n.cdn_zone_id = cdn_zones.id)';
        $q->orderByRaw("$oldest is not null")->orderByRaw($oldest)->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))->get()
            ->each(function (CdnZone $z) use ($verifier, &$n) {
                $verifier->check($z);
                $n++;
            });

        $this->info("checked: $n");

        return self::SUCCESS;
    }
}
