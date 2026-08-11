<?php

namespace App\Console\Commands;

use App\Models\MailboxMessage;
use App\Services\Mail\MailboxDigest;
use App\Services\Mail\MailboxSync;
use App\Services\Mail\MailboxTriage;
use Illuminate\Console\Command;

/**
 * چرخهٔ کاملِ گزارش: بخوان → دسته‌بندی کن → یک پیام در بله بفرست.
 *
 * ترتیب مهم است و عمدی: اول سینک (تا نامه‌ای جا نماند)، بعد دسته‌بندیِ هرچه
 * دسته نخورده، و آخر گزارش. اگر دسته‌بندی شکست بخورد، گزارش هم نمی‌رود و
 * نامه‌ها گزارش‌نشده می‌مانند — دیر رسیدنِ گزارش قابلِ تحمل است، گم شدنِ نامه نه.
 */
class MailboxDigestCommand extends Command
{
    protected $signature = 'mailbox:digest
        {--skip-sync : بدونِ خواندنِ دوبارهٔ صندوق‌ها}
        {--dry : فقط متنِ گزارش را چاپ کن، نه بله بفرست نه چیزی را مهر بزن}';

    protected $description = 'دسته‌بندی نامه‌های تازه و ارسال گزارش به ربات بله';

    public function handle(MailboxSync $sync, MailboxTriage $triage, MailboxDigest $digest): int
    {
        if (! $this->option('skip-sync')) {
            foreach ($sync->run() as $key => $r) {
                $this->line(isset($r['error']) ? "✗ {$key}: {$r['error']}" : "✓ {$key}: {$r['new']} تازه");
            }
        }

        // فقط چیزی که هنوز دسته نخورده — تماسِ مدل تکراری هزینهٔ بی‌دلیل است.
        $fresh = MailboxMessage::unreported()
            ->whereNull('category')
            ->orderByDesc('received_at')
            ->limit(60)
            ->get();

        if ($fresh->isNotEmpty()) {
            $done = $triage->classify($fresh);
            $this->line("دسته‌بندی: {$done} از {$fresh->count()}");

            if ($done === 0) {
                $this->error('مدل جواب نداد — گزارش فرستاده نمی‌شود و نامه‌ها برای اجرای بعدی می‌مانند.');

                return self::FAILURE;
            }
        }

        $r = $digest->send((bool) $this->option('dry'));

        if ($this->option('dry')) {
            $this->line('');
            $this->line($r['reason'] ?? '(چیزی برای گزارش نیست)');

            return self::SUCCESS;
        }

        if (! $r['sent']) {
            $this->line(match ($r['reason'] ?? '') {
                'nothing_new'           => 'چیزِ تازه‌ای نیست.',
                'nothing_worth_saying'  => 'فقط خبرنامه و تبلیغات بود — گزارشِ خالی فرستاده نمی‌شود.',
                'no_phone'              => 'شمارهٔ بله تنظیم نشده (MAILBOX_DIGEST_PHONE یا شمارهٔ اعلانِ مدیر).',
                'bale_failed'           => 'بله جواب نداد. هیچ نامه‌ای مهر نخورد؛ اجرای بعدی دوباره تلاش می‌کند.',
                default                 => (string) ($r['reason'] ?? ''),
            });

            return ($r['reason'] ?? '') === 'bale_failed' ? self::FAILURE : self::SUCCESS;
        }

        $this->info("گزارش رفت: {$r['reported']} مورد · {$r['skipped']} بی‌صدا رد شد");

        return self::SUCCESS;
    }
}
