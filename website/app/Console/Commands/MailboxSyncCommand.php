<?php

namespace App\Console\Commands;

use App\Services\Mail\MailboxSync;
use Illuminate\Console\Command;

/**
 * خواندنِ صندوق‌های مدیریتی و ثبتشان.
 *
 * جدا از گزارش اجرا می‌شود و مکرر‌تر: خواندن ارزان است، گزارش گران (تماسِ
 * مدل + پیامِ بله). اینکه پنل همیشه تازه باشد نباید به دورهٔ گزارش گره بخورد.
 */
class MailboxSyncCommand extends Command
{
    protected $signature = 'mailbox:sync {--account= : فقط یک صندوق (ceo/support/info)}';

    protected $description = 'خواندن صندوق‌های مدیریتی از IMAP و ثبت نامه‌های تازه';

    public function handle(MailboxSync $sync): int
    {
        $results = $sync->run($this->option('account') ?: null);

        if ($results === []) {
            $this->warn('هیچ صندوقی پیکربندی نشده. رمزها را در .env بگذار (MAILBOX_*_PASS).');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($results as $key => $r) {
            if (isset($r['error'])) {
                $this->error("✗ {$key}: {$r['error']}");
                $failed = true;

                continue;
            }

            $this->line("✓ {$key}: {$r['new']} تازه از {$r['seen']} بررسی‌شده");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
