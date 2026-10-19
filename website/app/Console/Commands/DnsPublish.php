<?php

namespace App\Console\Commands;

use App\Models\CdnZone;
use App\Services\Dns\ZonePublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * انتشارِ zoneهای در انتظار/ناموفق/در حالِ حذف — قرارداد §4.1. هر دقیقه.
 *
 * پروژه worker صف ندارد؛ این فرمان همان «صف» است: هر zone با backoffِ خودش
 * (`next_publish_at`) برداشته می‌شود و قفلِ zone جلوی اجرای هم‌زمان را می‌گیرد.
 * `--all` همه را یک بار می‌فرستد (پس از بازسازیِ سرورِ DNS — ناشر فقط diff می‌فرستد).
 */
class DnsPublish extends Command
{
    protected $signature = 'dns:publish {--zone= : فقط یک دامنه} {--all : همهٔ zoneهای pending/active} {--limit=100}';

    protected $description = 'Managed DNS: publish pending zones to the PowerDNS primary';

    public function handle(ZonePublisher $publisher): int
    {
        if (! Schema::hasTable('cdn_zones') || ! Schema::hasColumn('cdn_zones', 'desired_version')) {
            return self::SUCCESS;
        }

        $q = CdnZone::query()->where('mode', 'ns');
        if ($only = $this->option('zone')) {
            $q->where('domain', strtolower(rtrim((string) $only, '.')));
        } elseif ($this->option('all')) {
            $q->whereIn('status', ['pending', 'active', 'suspended', 'deleting']);
        } else {
            $q->where(function ($w) {
                $w->whereIn('publish_state', ['pending', 'failed', 'deleting'])
                    ->orWhereColumn('applied_version', '<', 'desired_version');
            })->where(fn ($w) => $w->whereNull('next_publish_at')->orWhere('next_publish_at', '<=', now()));
        }

        $done = ['ok' => 0, 'fail' => 0];
        $q->orderByRaw('next_publish_at IS NOT NULL, next_publish_at')->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))->get()
            ->each(function (CdnZone $z) use ($publisher, &$done) {
                $r = $publisher->publish($z);
                $r->ok ? $done['ok']++ : $done['fail']++;
                if (! $r->ok && $r->code !== 'locked') {
                    $this->line("✗ {$z->domain}: {$r->code}");
                }
            });

        $this->info("published/ok: {$done['ok']} · failed: {$done['fail']}");

        // شکستِ انتشار خطای فرمان نیست — backoff دارد و مدیر در /admin/dns می‌بیند
        return self::SUCCESS;
    }
}
