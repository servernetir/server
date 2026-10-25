<?php

namespace App\Console\Commands;

use App\Models\AiUsage;
use App\Models\GrowthContent;
use App\Models\GrowthDocument;
use App\Models\GrowthJob;
use App\Services\Growth\ContentWorkflow;
use App\Services\Growth\GrowthAudit;
use App\Services\Growth\GrowthJobs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RunGrowthCenter extends Command
{
    protected $signature = 'growth:run {--limit=5 : Maximum queued AI jobs}';

    protected $description = 'Process approved Growth jobs and due CMS publications; never repeats uncertain paid calls.';

    public function handle(GrowthJobs $jobs, ContentWorkflow $workflow): int
    {
        if (! Schema::hasTable('growth_controls')) {
            $this->warn('Growth migrations are not installed.');

            return self::SUCCESS;
        }
        $lock = Cache::lock('growth-center-run', 900);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            $control = DB::table('growth_controls')->find(1);
            // Reconcile receipts even when the kill switch is off. No upstream inference is triggered here.
            foreach (GrowthJob::whereIn('status', ['processing', 'failed', 'completed'])->where(function ($q) {
                $q->whereNull('actual_cost_irt')->orWhere('created_at', '>', now()->subDays(8));
            })->orderBy('id')->cursor() as $job) {
                $usage = $job->ai_usage_id ? AiUsage::find($job->ai_usage_id) : AiUsage::where('idempotency_key', 'growth-'.$job->request_key)->first();
                if ($usage && ! $job->ai_usage_id) {
                    $job->update(['ai_usage_id' => $usage->id]);
                }
                if ($usage && in_array($usage->status, AiUsage::CHARGED, true)) {
                    $job->update(['actual_cost_irt' => max(0, (int) $usage->charged_irt - (int) $usage->refunded_irt)]);
                } elseif ($usage?->status === 'released') {
                    $job->update(['actual_cost_irt' => 0]);
                }
            }
            if (! $control?->enabled) {
                $this->info('Growth is paused.');

                return self::SUCCESS;
            }
            // A worker lost after sending may have incurred cost. Flag it, keep its ceiling, do not resend.
            GrowthJob::where('status', 'processing')->where('started_at', '<', now()->subMinutes(20))
                ->update(['status' => 'failed', 'error_code' => 'worker_lost_review_receipt', 'finished_at' => now()]);
            $failures = GrowthJob::where('status', 'failed')->where('finished_at', '>', now()->subMinutes(10))->count();
            if ($failures < 5) {
                foreach (GrowthJob::where('status', 'queued')->orderBy('id')->limit(max(1, min(20, (int) $this->option('limit'))))->get() as $job) {
                    $jobs->run($job);
                }
            } else {
                $this->warn('Circuit open: five failures in ten minutes. No new paid calls.');
            }
            foreach (GrowthContent::where('status', 'scheduled')->where('channel', 'blog')->where('scheduled_at', '<=', now())->limit(20)->get() as $item) {
                try {
                    $workflow->publishBlog($item->id);
                } catch (\Throwable) {
                    $item->update(['status' => 'failed']);
                    GrowthAudit::record('publication.failed', 'content:'.$item->id);
                }
            }
            GrowthDocument::where('status', 'verified')->where('review_at', '<=', now())->update(['status' => 'outdated']);
            if ($control->daily_tasks) {
                $this->daily();
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function daily(): void
    {
        $local = now('Asia/Tehran');
        $hour = (int) $local->format('G');
        $slot = match (true) {
            $hour >= 21 => 'night',$hour >= 17 => 'evening',$hour >= 12 => 'noon', $hour >= 8 => 'morning',default => null
        };
        if (! $slot) {
            return;
        }
        $key = $local->toDateString().':'.$slot;
        $summary = ['slot' => $slot, 'review' => GrowthContent::where('status', 'review')->count(),
            'due' => GrowthContent::where('status', 'scheduled')->where('scheduled_at', '<=', now())->count(),
            'stale_knowledge' => GrowthDocument::where('status', 'outdated')->count(),
            'action' => match ($slot) {
                'morning' => 'Review dated official research sources; choose topics.','noon' => 'Generate drafts after cost approval.',
                'evening' => 'Review content and publish approved items.','night' => 'Review costs, failures and actual analytics.'
            }];
        if (DB::table('growth_automation_runs')->insertOrIgnore(['run_key' => $key, 'summary' => json_encode($summary, JSON_THROW_ON_ERROR), 'created_at' => now()])) {
            GrowthAudit::record('automation.digest',$key,null,$summary);
        }
    }
}
