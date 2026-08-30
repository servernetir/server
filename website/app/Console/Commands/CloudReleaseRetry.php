<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * تلاشِ دوبارهٔ **آزادسازیِ** سرویس‌هایی که مشتری بسته و زیرساخت تأیید نکرده.
 *
 * ═══ چرا این فرمان وجود دارد ═══
 *
 * کارفرما: «درستش هرطوری هست همانگونه انجام بده، نه ما ضرر کنیم نه مشتری.»
 *
 * دو راهِ ساده هر دو یک طرف را می‌سوزانند:
 *   · «به‌هرحال خاتمه‌یافته بنویس» ⇒ ماشین شاید نزدِ زیرساخت زنده است و
 *     اجاره‌اش را **ما** می‌دهیم، بی‌مشتری و بی‌درآمد.
 *   · «فعال بگذار و متر کن» ⇒ **مشتری** بابتِ سروری که خواسته پاک شود پول
 *     می‌دهد — یعنی خرابیِ ما را او می‌پردازد.
 *
 * راهِ سوم: صورت‌حسابِ مشتری همان لحظهٔ درخواست بسته می‌شود (`status` مرده)، ولی
 * ماشین تا **تأییدِ واقعیِ زیرساخت** «آزادشده» اعلام نمی‌شود
 * (`provision_status='releasing'`). فاصله‌اش هزینهٔ ماست، و این فرمان آن فاصله را
 * هر ساعت کوتاه می‌کند تا صفر شود.
 *
 * ⚠️ **این فرمان هرگز چیزی نمی‌خرد.** پرس‌وجویش صریحاً روی وضعیتِ **مرده** قفل
 * است و `releaseAndTrack()` فقط حذف می‌کند. هیچ مسیری از این‌جا به
 * `provision_status='pending'` نمی‌رسد.
 *
 * ⚠️ **ابری و غیرابری، هر دو.** `releaseServer()` صدا زده می‌شود نه
 * `CloudProvisioner::terminate()`. شاخه‌زدن روی `isCloud()` و فراموش‌کردنِ نیمهٔ
 * دیگر، باگِ مستندشدهٔ همین حوزه است (حسابِ cPanel که هرگز پاک نمی‌شد).
 */
class CloudReleaseRetry extends Command
{
    protected $signature = 'cloud:release-retry {--limit=25}';

    protected $description = 'تلاشِ دوبارهٔ حذفِ سرورهایی که خاتمه‌شان نزدِ زیرساخت تأیید نشده';

    public function handle(ProvisioningService $prov): int
    {
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'provision_status')) {
            return self::SUCCESS;
        }

        $rows = Service::query()
            ->awaitingRelease()
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($rows->isEmpty()) {
            $this->info('صفِ آزادسازی خالی است.');

            return self::SUCCESS;
        }

        $done = 0;
        $still = 0;

        foreach ($rows as $service) {
            $r = $prov->releaseAndTrack($service);

            if ($r->ok || $r->manual) {
                $done++;

                try {
                    ActivityLog::forService($service, 'terminate',
                        'حذفِ سرور نزدِ زیرساخت در تلاشِ دوبارهٔ خودکار انجام شد', 'system');
                } catch (\Throwable) {
                }

                continue;
            }

            $still++;
        }

        $this->info("آزادسازی: {$done} بسته شد، {$still} هنوز باز است.");

        return self::SUCCESS;
    }
}
