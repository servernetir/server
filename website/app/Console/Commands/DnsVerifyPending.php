<?php

namespace App\Console\Commands;

use App\Models\CdnZone;
use App\Services\Dns\ZoneService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * سنجشِ دوره‌ایِ تفویض + انقضای zoneهای رهاشده.
 *
 * چرا کرون و نه فقط دکمه: انتشارِ تغییرِ نیم‌سرور ساعت‌ها طول می‌کشد و
 * مشتری بعد از ذخیره در پنلِ رجیسترار برنمی‌گردد دکمه را بزند. بی‌این،
 * دامنه‌اش تا وقتی یادش بیفتد «در انتظار» می‌مانَد.
 *
 * دو کارِ دیگر هم این‌جاست چون هر دو دربارهٔ «تفویض واقعاً کجاست» هستند:
 *  • zoneِ فعال هم روزی یک بار سنجیده می‌شود تا اگر مشتری نیم‌سرور را
 *    برگرداند، پنل به‌جای «فعال»ِ دروغ، هشدار نشان دهد.
 *  • zoneِ در انتظارِ اثبات‌نشده پس از `edge.pending_days` حذف می‌شود —
 *    قفلِ سومِ «رزروِ zone» (بالای `ZoneService` را بخوان).
 */
class DnsVerifyPending extends Command
{
    protected $signature = 'dns:verify-pending {--limit=200}';

    protected $description = 'تفویضِ دامنه‌های DNSِ مدیریت‌شده را می‌سنجد و zoneهای رهاشده را پاک می‌کند';

    public function handle(ZoneService $zones): int
    {
        // موفق روی نصبِ مهاجرت‌نخورده: کدِ ناموفق در کرون هر ساعت سروصدا می‌کند
        if (! Schema::hasTable('cdn_zones')) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $activated = 0;
        $checked = 0;

        /*
         * در انتظارها هر اجرا؛ فعال‌ها فقط اگر ۲۴ ساعت از سنجشِ قبلی گذشته.
         * قدیمی‌ترین سنجش اول — تا با سقفِ `limit` هیچ zoneی همیشه جا نماند.
         */
        $due = CdnZone::query()
            ->where('mode', 'ns')
            ->where(function ($q) {
                $q->where('status', 'pending')
                    ->orWhere(fn ($q) => $q->where('status', 'active')
                        ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subDay())));
            })
            ->orderByRaw('checked_at IS NOT NULL, checked_at')
            ->limit($limit)
            ->get();

        foreach ($due as $zone) {
            $was = $zone->status;
            $res = $zones->verify($zone);
            $checked++;

            if ($res['ok'] && $was === 'pending') {
                $activated++;
            }
        }

        $expired = $zones->expirePending();

        $this->info("سنجیده: {$checked} · فعال‌شده: {$activated} · منقضی‌شده: {$expired}");

        return self::SUCCESS;
    }
}
