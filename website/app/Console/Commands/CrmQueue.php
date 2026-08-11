<?php

namespace App\Console\Commands;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Services\Crm\OutreachComposer;
use Illuminate\Console\Command;

/**
 * نوشتنِ پیامِ بعدی برای سرنخ‌هایی که نوبتشان رسیده و گذاشتنش در صف.
 *
 * ⚠️ این فرمان هیچ چیزی نمی‌فرستد. نوشتن و فرستادن عمداً دو کارِ جدا هستند:
 * تا وقتی `CRM_AUTOPILOT` روشن نشده، خروجیِ این فرمان در پنل می‌ماند تا خودت
 * بخوانی و تأیید کنی. اولین ده پیش‌نویس را باید یک انسان ببیند.
 */
class CrmQueue extends Command
{
    protected $signature = 'crm:queue {--limit=10 : چند پیام در این اجرا}';

    protected $description = 'ساخت پیام برای سرنخ‌های سررسیده و افزودن به صف';

    public function handle(OutreachComposer $composer): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $leads = CrmLead::query()
            ->whereNotNull('email')
            ->whereNotNull('observation')
            ->whereNotIn('stage', ['won', 'lost', 'replied'])
            // سرنخی که همین حالا پیامی در صف دارد، پیامِ دوم نمی‌گیرد.
            ->whereNotExists(fn ($q) => $q->selectRaw(1)
                ->from('crm_messages')
                ->whereColumn('crm_messages.lead_id', 'crm_leads.id')
                ->where('crm_messages.direction', 'out')
                ->whereIn('crm_messages.status', ['queued', 'sending']))
            ->where(fn ($q) => $q
                ->where('stage', 'new')
                ->orWhere(fn ($w) => $w
                    ->whereNotNull('next_action_at')
                    ->whereDate('next_action_at', '<=', now()->toDateString())))
            ->orderBy('next_action_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($leads->isEmpty()) {
            $this->line('سرنخِ سررسیده‌ای نیست.');

            return self::SUCCESS;
        }

        $made = 0;

        foreach ($leads as $lead) {
            $message = $composer->compose($lead);

            if ($message instanceof CrmMessage) {
                $made++;
                $this->line('✓ '.$lead->company.'  «'.$message->subject.'»');
            } else {
                $this->line('· رد شد: '.$lead->company.' (دلیل در لاگ)');
            }
        }

        $this->info("پیامِ ساخته‌شده: {$made} از {$leads->count()}");

        if ($made > 0 && ! config('crm.autopilot')) {
            $this->warn('خلبانِ خودکار خاموش است — پیام‌ها در /admin/crm منتظرِ تأییدِ تو هستند.');
        }

        return self::SUCCESS;
    }
}
