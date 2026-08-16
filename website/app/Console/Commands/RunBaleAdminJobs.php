<?php

namespace App\Console\Commands;

use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Bale\Admin\AdminBaleWorker;
use Illuminate\Console\Command;

/**
 * کارهای **سنگینِ** کنسولِ بله را بیرون از وب‌هوک اجرا می‌کند.
 *
 * ═══ چرا این فرمان لازم است و نمی‌شد داخلِ وب‌هوک ماند ═══
 *
 * 🔴 تأییدِ رسیدِ بانکی زنجیرهٔ `PaymentService::applyPaid` را راه می‌اندازد:
 * تسویهٔ فاکتور، فعال‌کردنِ سرویس، و صفِ تحویل — که خودش ممکن است دقایقی بعد
 * سرورِ واقعی بخرد. خاتمهٔ سرویس هم با زیرساخت تماس می‌گیرد و `createacct`ِ
 * WHM تا ۱۸۰ ثانیه طول می‌کشد.
 *
 * مهلتِ وب‌هوکِ بله از هیچ‌کدام بیشتر نیست. اگر داخلِ وب‌هوک بمانند:
 *   • بله مهلتش تمام می‌شود و آپدیت را **دوباره** می‌فرستد
 *   • آن ترافیکِ تکراری در همان سطلِ throttleای می‌نشیند که
 *     `pre_checkout_query`ِ پرداختِ مشتری هم در آن است
 *   • و کارفرما هیچ‌وقت نمی‌فهمد کار انجام شد یا نه
 *
 * پس دکمه فقط **کار را در صف می‌گذارد** و بلافاصله جواب می‌دهد؛ این فرمان هر
 * دقیقه صف را خالی می‌کند و نتیجه را در همان چت گزارش می‌دهد.
 *
 * ⚠️ صف عمداً **یک‌تایی** است (یک خانه در وضعیت، نه جدول): مهاجرت‌های
 * پروداکشن دستی اجرا می‌شوند، و کارفرما هم روی گوشی یک کار را در یک لحظه
 * انجام می‌دهد. کارِ دوم پیش از خالی‌شدنِ صف رد می‌شود و صریح گفته می‌شود.
 */
class RunBaleAdminJobs extends Command
{
    protected $signature = 'bale:work';

    protected $description = 'کارهای سنگینِ کنسولِ بله (تأییدِ رسید، خاتمهٔ سرویس) را اجرا می‌کند';

    public function handle(AdminBaleGate $gate, AdminBaleWorker $worker): int
    {
        try {
            $job = $gate->takeJob();

            if ($job === null) {
                return self::SUCCESS;
            }

            $this->line('اجرای کار: '.($job['verb'] ?? '?'));

            $worker->run($job);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            /*
            | ⚠️ هرگز کدِ خطا نمی‌دهد. این فرمان هر دقیقه داخلِ `schedule:run`
            | می‌دود و یک استثنا کلِ آن دقیقهٔ کرون را می‌کشد: تحویلِ سرویس،
            | ثبتِ دامنه، مترِ ساعتی، همه.
            */
            \App\Support\ErrorTracker::note('bale-admin', $e, ['cmd' => 'bale:work']);

            return self::SUCCESS;
        }
    }
}
