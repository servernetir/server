<?php

namespace App\Console\Commands;

use App\Services\Crm\OutreachMailer;
use Illuminate\Console\Command;

/**
 * تخلیهٔ صفِ ارسال.
 *
 * 🔴 اگر `CRM_AUTOPILOT` روشن نباشد این فرمان **هیچ چیزی نمی‌فرستد** و همین را
 * می‌گوید. `--force` فقط برای اجرای دستیِ خودت است، نه برای کرون.
 */
class CrmSend extends Command
{
    protected $signature = 'crm:send
        {--limit= : حداکثر ارسال در این اجرا}
        {--force : نادیده‌گرفتنِ خلبانِ خودکار و پنجرهٔ زمانی (اجرای دستی)}';

    protected $description = 'ارسال پیام‌های صف‌شده با رعایتِ سقفِ روزانه و پنجرهٔ زمانی';

    public function handle(OutreachMailer $mailer): int
    {
        $r = $mailer->drain(
            $this->option('limit') ? (int) $this->option('limit') : null,
            (bool) $this->option('force'),
        );

        if (isset($r['halted'])) {
            $this->line(match ($r['halted']) {
                'autopilot_off'   => 'خلبانِ خودکار خاموش است. ارسال از پنل یا با --force.',
                'outside_window'  => 'خارج از پنجرهٔ ارسال (ساعتِ کاریِ مقصد یا آخرِ هفته).',
                'daily_cap'       => 'سقفِ امروز پر شده: '.$mailer->sentToday().' از '.$mailer->dailyCap().'.',
                default           => $r['halted'],
            });

            return self::SUCCESS;
        }

        $this->info("ارسال‌شده: {$r['sent']} · ناموفق: {$r['failed']} · ردشده: {$r['skipped']}");
        $this->line('سقفِ امروز: '.$mailer->sentToday().' از '.$mailer->dailyCap());

        return self::SUCCESS;
    }
}
