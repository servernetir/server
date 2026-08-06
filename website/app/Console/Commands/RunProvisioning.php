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

        // ⚠️ 'running' هم بازپس‌گیری می‌شود، ولی **فقط اگر کهنه باشد**.
        // قفلِ وضعیتی سرویس را به 'running' می‌برد؛ اگر پروسه در همان لحظه کشته
        // شود (دپلوی، ری‌استارتِ PHP-FPM، max_execution_time، OOM)، سرویس تا ابد
        // در 'running' گیر می‌کرد: کرون نمی‌دیدش، خطایی هم تولید نمی‌شد، و پولِ
        // مشتری گرفته‌شده بود. کرانِ ۱۵ دقیقه‌ای امن است چون تحویلِ سالم چند ثانیه
        // طول می‌کشد، و ساختِ دوباره با نامِ قطعیِ سرور (idempotency) پوشش دارد.
        $staleLock = now()->subMinutes(15);

        /*
        | 🔴 سرویسِ مرده هرگز تحویل نمی‌شود.
        |
        | این کرون فقط `provision_status` را می‌دید و `status` را نه. پس یک
        | سفارشِ **لغوشده و وجه‌برگشته** که پرچمش روی `pending` مانده بود،
        | دقیقهٔ بعد برداشته می‌شد و سرور واقعاً خریده می‌شد.
        |
        | ⚠️ لغو حالا خودش پرچم را پاک می‌کند، ولی این لایهٔ دوم عمدی است: کرون
        | تنها درِ ورود نیست (دکمهٔ «تلاش دوباره»ی مدیر هم هست)، و هزینهٔ اشتباه
        | این‌جا یک سرورِ خریداری‌شده است.
        */
        $due = Service::whereNotIn('status', Service::DEAD_STATUSES)
            ->where(function ($q) use ($staleLock) {
                $q->where('provision_status', 'pending')
                    ->orWhere(fn ($s) => $s->where('provision_status', 'running')
                        ->where('updated_at', '<', $staleLock));
            })
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
