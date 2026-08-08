<?php

namespace App\Console\Commands;

use App\Services\Crm\InboxScanner;
use Illuminate\Console\Command;

/**
 * خواندنِ صندوقِ فروش: جواب، درخواستِ عدمِ تماس، و بانس.
 *
 * 🔴 مهم‌ترین کارش ایستاندنِ فالوآپ است. فالوآپِ خودکاری که سه روز بعد از
 * جوابِ «قیمت چند؟» می‌رود، همان سرنخ را از دست می‌دهد.
 */
class CrmInbox extends Command
{
    protected $signature = 'crm:inbox {--days=14 : چند روزِ گذشته بررسی شود}';

    protected $description = 'بررسی صندوق ورودی فروش و ثبت جواب‌ها، لغوها و بانس‌ها';

    public function handle(InboxScanner $scanner): int
    {
        $r = $scanner->scan((int) $this->option('days'));

        if (($r['error'] ?? null) === 'not_configured') {
            $this->warn('CRM_IMAP_HOST / CRM_IMAP_PASS تنظیم نشده — جواب‌ها خوانده نمی‌شوند.');

            return self::SUCCESS;
        }

        if (isset($r['error'])) {
            $this->error('خطا: '.$r['error']);

            return self::FAILURE;
        }

        $this->info("بررسی‌شده: {$r['seen']} · جواب: {$r['replies']} · لغو: {$r['optouts']} · بانس: {$r['bounces']}");

        return self::SUCCESS;
    }
}
