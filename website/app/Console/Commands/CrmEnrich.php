<?php

namespace App\Console\Commands;

use App\Models\CrmLead;
use App\Services\Crm\OutreachComposer;
use Illuminate\Console\Command;

/**
 * ممیزیِ سایتِ سرنخ‌های تازه + یافتنِ نشانیِ تماس + استخراجِ یک مشاهدهٔ مشخص.
 *
 * سنگین‌ترین مرحله است (چند درخواستِ HTTP + یک تماسِ مدل به ازای هر سرنخ)، پس
 * دسته‌ای و کوچک اجرا می‌شود. سرنخی که مشاهده‌ای برایش پیدا نشود همین‌جا
 * می‌ماند و هرگز پیامی نمی‌گیرد — و این ویژگی است، نه نقص.
 */
class CrmEnrich extends Command
{
    protected $signature = 'crm:enrich {--limit=5 : چند سرنخ در این اجرا}';

    protected $description = 'بررسی سایتِ سرنخ‌ها و استخراج مشاهده برای پیام‌سازی';

    public function handle(OutreachComposer $composer): int
    {
        $leads = CrmLead::whereNull('observation')
            ->whereNotIn('stage', ['won', 'lost'])
            ->whereNotNull('website')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($leads->isEmpty()) {
            $this->line('سرنخِ بررسی‌نشده‌ای نیست.');

            return self::SUCCESS;
        }

        $ok = 0;

        foreach ($leads as $lead) {
            $done = $composer->enrich($lead);
            $ok += $done ? 1 : 0;

            $this->line(($done ? '✓' : '·').' '.$lead->company.'  '.($lead->email ?: 'بدونِ نشانی'));
        }

        $this->info("بررسی‌شده: {$leads->count()} · آمادهٔ پیام: {$ok}");

        return self::SUCCESS;
    }
}
