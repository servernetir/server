<?php

namespace App\Console\Commands;

use App\Models\CdnZone;
use App\Services\Dns\OwnershipService;
use App\Services\Dns\ZoneService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * تفویض از دیدِ والد + فعال‌سازی، و انقضای ادعاهای کهنه — قرارداد §5.2/§5.3. ساعتی.
 *
 * zoneِ در انتظار هر ساعت؛ zoneِ فعال روزی یک بار (تا اگر مشتری NS را برگرداند،
 * پنل به‌جای «فعال»ِ دروغ هشدار نشان دهد). هیچ zoneی به خاطرِ `lookup_failed`
 * یا از‌دست‌دادنِ تفویض غیرفعال یا حذف نمی‌شود.
 */
class DnsCheckDelegation extends Command
{
    protected $signature = 'dns:check-delegation {--zone=} {--limit=200}';

    protected $description = 'Managed DNS: check delegation at the parent, activate zones, expire stale ownership claims';

    public function handle(ZoneService $zones, OwnershipService $ownership): int
    {
        if (! Schema::hasTable('cdn_zones') || ! Schema::hasColumn('cdn_zones', 'delegation_state')) {
            return self::SUCCESS;
        }

        $expired = Schema::hasTable('dns_ownership_claims') ? $ownership->expireStale() : 0;

        $q = CdnZone::query()->where('mode', 'ns');
        if ($only = $this->option('zone')) {
            $q->where('domain', strtolower(rtrim((string) $only, '.')));
        } else {
            $q->where(function ($w) {
                $w->where('status', 'pending')
                    ->orWhere(fn ($a) => $a->where('status', 'active')
                        ->where(fn ($t) => $t->whereNull('delegation_checked_at')->orWhere('delegation_checked_at', '<', now()->subDay())));
            });
        }

        $activated = 0;
        $checked = 0;
        $q->orderByRaw('delegation_checked_at IS NOT NULL, delegation_checked_at')
            ->limit(max(1, (int) $this->option('limit')))->get()
            ->each(function (CdnZone $z) use ($zones, &$activated, &$checked) {
                $was = $z->status;
                $r = $zones->checkDelegation($z);
                $checked++;
                if ($was === 'pending' && $r['active']) {
                    $activated++;
                }
            });

        $this->info("checked: $checked · activated: $activated · claims expired: $expired");

        return self::SUCCESS;
    }
}
