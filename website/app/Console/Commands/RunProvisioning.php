<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * پردازشِ صفِ تحویل — سرویس‌هایِ «در انتظارِ تحویل» را می‌سازد.
 *
 * چون روی cPanel واحدِ کارگرِ صف (queue worker) نداریم، تحویل را همین دستور
 * انجام می‌دهد و از طریقِ زمان‌بندِ لاراول هر دقیقه اجرا می‌شود. تماسِ شبکه‌ای
 * این‌جاست، جدا از درخواستِ پرداخت، پس وب‌هوکِ درگاه کند/تایم‌اوت نمی‌شود.
 */
class RunProvisioning extends Command
{
    protected $signature = 'provision:run {--limit=10 : بیشینهٔ سرویس در هر اجرا}';

    protected $description = 'ساختِ خودکارِ سرویس‌هایِ در انتظارِ تحویل روی سرورها';

    public function handle(ProvisioningService $prov): int
    {
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'provision_status')) {
            $this->warn('جدول/ستونِ تحویل هنوز ساخته نشده.');

            return self::SUCCESS;
        }

        // ⚠️ سرورِ ابری `server_id` ندارد (پیش از خرید وجود ندارد)، پس شرطِ
        // `whereNotNull('server_id')` تنها، هر سرویسِ ابری را بی‌صدا رد می‌کرد:
        // مشتری پول می‌داد و سرورش **هرگز** ساخته نمی‌شد. همان کلاسِ باگی که
        // یک‌بار با نامِ اشتباهِ package رخ داد — «تحویل شکست نمی‌خورد، فقط
        // اتفاق نمی‌افتد» بدترین نوعِ خرابی است، چون هیچ خطایی تولید نمی‌کند.
        $hasCloud = Schema::hasColumn('services', 'cloud_plan_id');

        $due = Service::where('provision_status', 'pending')
            ->where(function ($q) use ($hasCloud) {
                $q->whereNotNull('server_id');

                if ($hasCloud) {
                    $q->orWhereNotNull('cloud_plan_id');
                }
            })
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $ok = 0;
        foreach ($due as $service) {
            if ($prov->provision($service)) {
                $ok++;
            }
        }

        $this->info("تحویل: {$ok}/{$due->count()} موفق.");

        return self::SUCCESS;
    }
}
