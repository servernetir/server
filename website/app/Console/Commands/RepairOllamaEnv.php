<?php

namespace App\Console\Commands;

use App\Models\CloudInstance;
use App\Models\Service;
use App\Services\Cloud\CloudManager;
use Illuminate\Console\Command;

/**
 * `cloud:ollama-repair` — سرورهای Ollamaای که بی‌`OLLAMA_MODEL_NAME` ساخته شدند را درجا تعمیر می‌کند.
 *
 * ═══ رخداد (مهر ۱۴۰۵) ═══
 * entrypointِ ایمیجِ Ollama بی‌این متغیر `exit 1` می‌کند و متغیر در یک دیپلو
 * برداشته شده بود. هر سرورِ Ollamaی که در آن فاصله ساخته شد در حلقهٔ ری‌استارت
 * است و دیپلویِ کدِ درست نجاتش نمی‌دهد — env فقط لحظهٔ ساخت فرستاده می‌شود.
 *
 * این فرمان هر گروهِ زنده را می‌پرسد و فقط اگر ایمیج Ollama و مدل خالی باشد،
 * env را PATCH می‌کند (جزئیات و تلهٔ «جایگزینی نه ادغام» در
 * `SaladOperations::ensureOllamaModelEnv()`). هیچ‌چیز نمی‌سازد و نمی‌خرد؛ نامِ
 * دروازه و توکنِ مشتری همان می‌مانند.
 *
 * ⚠️ یک‌بارمصرف است و عمداً در `routes/console.php` نیست: سرورِ تازه از کدِ
 *    اصلاح‌شده درست ساخته می‌شود و چیزی برای تعمیرِ دوره‌ای نمی‌ماند.
 *
 *   php artisan cloud:ollama-repair --dry-run    فقط بگو کدام‌ها خراب‌اند
 *   php artisan cloud:ollama-repair              تعمیر کن
 */
class RepairOllamaEnv extends Command
{
    protected $signature = 'cloud:ollama-repair {--dry-run : فقط گزارش بده، چیزی را عوض نکن}';

    protected $description = 'تعمیرِ env سرورهای Ollamaای که بی‌OLLAMA_MODEL_NAME ساخته شدند';

    public function handle(CloudManager $manager): int
    {
        $driver = $manager->driver('salad');

        if ($driver === null || ! $driver->isConfigured()) {
            $this->warn('زیرساختِ GPU تنظیم نشده است.');

            return self::SUCCESS;
        }

        $rows = CloudInstance::query()
            ->where('provider', 'salad')
            ->where('image_key', 'gpu-ollama')
            ->whereNotNull('provider_ref')->where('provider_ref', '!=', '')
            ->where('status', '!=', 'deleted')
            ->whereHas('service', fn ($q) => $q->whereNotIn('status', Service::DEAD_STATUSES))
            ->orderBy('id')
            ->get();

        $dry = (bool) $this->option('dry-run');
        $fixed = 0;
        $failed = 0;

        foreach ($rows as $i) {
            $r = $driver->ensureOllamaModelEnv((string) $i->provider_ref, $dry);
            $line = 'سرویس '.$i->service_id.' ('.$i->provider_ref.'): '.$r['message'];

            if (! $r['ok']) {
                $failed++;
                $this->error('✗ '.$line);
            } elseif ($r['changed']) {
                $fixed++;
                $this->info('✓ '.$line.'تعمیر شد.');
            } else {
                $this->line('· '.$line);
            }
        }

        $this->newLine();
        $this->info($rows->count().' سرورِ Ollama بررسی شد · '.$fixed.' تعمیر شد · '.$failed.' خطا'
            .($dry ? ' (اجرای آزمایشی — چیزی عوض نشد)' : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
