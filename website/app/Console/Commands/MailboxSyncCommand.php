<?php

namespace App\Console\Commands;

use App\Services\Mail\MailboxSync;
use App\Support\ErrorTracker;
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

        $failed = [];

        foreach ($results as $key => $r) {
            if (isset($r['error'])) {
                $this->error("✗ {$key}: {$r['error']}");
                $failed[] = "{$key}: {$r['error']}";

                continue;
            }

            $this->line("✓ {$key}: {$r['new']} تازه از {$r['seen']} بررسی‌شده");
        }

        if ($failed !== []) {
            /*
            | 🔴 ردیابِ خطا، نه فقط `laravel.log`.
            |
            | تا امروز تنها ردِ این شکست یک خط در لاگی بود که ۱۰ مگابایت است و
            | از پنل خوانده نمی‌شود — یعنی برای مدیر عملاً نامرئی. حالا متنِ
            | **واقعیِ** خطا در `/admin/errors` می‌نشیند و رفعش به SSH گره
            | نمی‌خورد.
            |
            | ⚠️ گلوگاهِ ۶ ساعته عمدی است و همان درسِ سیلِ ۴۰۴ در CLAUDE.md:
            | پنجرهٔ ردیاب ۴۰۰ خط است و این کرون ساعتی می‌دود. رمزِ باطل هفته‌ها
            | همان می‌مانَد، پس بی‌گلوگاه روزی ۲۴ خطِ تکراری بقیهٔ خطاها را
            | بیرون می‌انداخت. سکوت بینِ دو شلیک بی‌خطر است: `SystemHealth`
            | همین وضعیت را **دائمی** قرمز نگه می‌دارد و به هیچ گلوگاهی بند نیست.
            */
            ErrorTracker::noteOnce('mail', 'صندوق خوانده نشد — '.implode(' · ', $failed), 21600);
        }

        return $failed !== [] ? self::FAILURE : self::SUCCESS;
    }
}
