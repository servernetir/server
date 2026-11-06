<?php

namespace App\Services\Growth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bounded native supervision. Knowledge expiry is the only executable action.
 * This complements the separately deployed native Hermes Operations module.
 * It is not a shell, a publication approver or an outbound-message sender.
 */
final class HermesSupervisor
{
    public const POLICY = 'hermes-supervisor-v1';

    private const RUN_PREFIX = 'hermes-supervisor:';

    private const EXPIRY_LIMIT = 100;

    public function status(): array
    {
        if (! $this->available()) {
            return ['policy' => self::POLICY, 'enabled' => false, 'active' => false,
                'delegated_actor_valid' => false, 'state' => 'unavailable', 'observed' => [],
                'latest_run' => null, 'executed_actions' => []];
        }

        // Setting::get() can populate a database-backed cache. Status and dry-run
        // must not write even when the application cache is completely empty.
        $settings = $this->settings();
        $actorValid = $this->actorValid($settings);
        $enabled = ($settings['hermes_supervisor_enabled'] ?? '') === '1';
        $growthEnabled = (bool) DB::table('growth_controls')->where('id', 1)->value('enabled');
        $state = ! $enabled || ! $growthEnabled ? 'paused' : (! $actorValid ? 'delegation_missing' : 'ready');
        $latest = DB::table('growth_automation_runs')->where('run_key', 'like', self::RUN_PREFIX.'%')->orderByDesc('id')->first();
        $receipt = $latest ? json_decode($latest->summary, true) : null;

        return ['policy' => self::POLICY, 'enabled' => $enabled, 'active' => $enabled && $growthEnabled && $actorValid,
            'delegated_actor_valid' => $actorValid, 'state' => $state, 'observed' => $this->observe(),
            'latest_run' => is_array($receipt) ? $receipt : null, 'executed_actions' => []];
    }

    public function tick(bool $dryRun = false): array
    {
        $key = self::RUN_PREFIX.now('UTC')->format('Y-m-d\TH');
        $status = $this->status();
        if ($status['state'] === 'unavailable') {
            return $this->receipt($key, $dryRun, 'unavailable', []);
        }
        if ($dryRun) {
            $receipt = $this->receipt($key, true, $status['active'] ? 'dry_run' : $status['state'], $status['observed']);
            $receipt['proposed_actions'] = $status['observed']['knowledge_expired_verified'] > 0
                ? [['action' => 'knowledge_expire', 'count' => min(self::EXPIRY_LIMIT, $status['observed']['knowledge_expired_verified'])]] : [];

            return $receipt;
        }
        if (! $status['active']) {
            return $this->receipt($key, false, $status['state'], $status['observed']);
        }

        return DB::transaction(function () use ($key) {
            // This existing singleton is also used by budgeted Growth workers.
            // No network or model calls happen while the lock is held.
            $control = DB::table('growth_controls')->where('id', 1)->lockForUpdate()->first();
            $settings = $this->settings(true);
            if (! $control?->enabled || ($settings['hermes_supervisor_enabled'] ?? '') !== '1') {
                return $this->receipt($key, false, 'paused', $this->observe());
            }
            $actor = (int) ($settings['growth_autopilot_actor_id'] ?? 0);
            $delegated = DB::table('users')->where('id', $actor)->lockForUpdate()->first(['id', 'role']);
            if (! $delegated || $delegated->role !== 'admin') {
                return $this->receipt($key, false, 'delegation_missing', $this->observe());
            }
            $old = DB::table('growth_automation_runs')->where('run_key', $key)->first();
            if ($old) {
                $receipt = json_decode($old->summary, true);
                $receipt = is_array($receipt) ? $receipt : ['policy' => self::POLICY, 'run_key' => $key];

                return array_replace($receipt, ['prior_status' => $receipt['status'] ?? 'unknown',
                    'status' => 'already_processed', 'executed_actions' => [], 'dry_run' => false]);
            }

            $before = $this->observe();
            $receipt = $this->receipt($key, false, 'completed', $before);
            $ids = $this->expiredQuery()->orderBy('id')->limit(self::EXPIRY_LIMIT)->lockForUpdate()->pluck('id')->all();
            if ($ids) {
                $count = $this->expiredQuery()->whereIn('id', $ids)->update(['status' => 'outdated', 'updated_at' => now()]);
                $receipt['executed_actions'][] = ['action' => 'knowledge_expire', 'count' => $count];
                GrowthAudit::record('supervisor.knowledge_expired', $key, $actor, ['count' => $count, 'policy' => self::POLICY]);
            }
            $after = $this->observe();
            $expired = array_sum(array_column($receipt['executed_actions'], 'count'));
            $receipt['observed_after'] = $after;
            $verifiedChanged = $ids ? DB::table('growth_documents')->whereIn('id', $ids)->where('status', 'outdated')->count() : 0;
            $receipt['verification'] = ['knowledge_expired_remaining' => $after['knowledge_expired_verified'],
                // Verify the exact locked targets. Another native worker may
                // expire different rows between aggregate before/after reads.
                'knowledge_changed_verified' => $verifiedChanged,
                'count_matches' => $expired === count($ids) && $verifiedChanged === count($ids),
                'performed' => true];
            $receipt['findings'] = $this->findings($after);
            if ($receipt['findings'] || ! $receipt['verification']['count_matches']) {
                $receipt['status'] = 'needs_attention';
            }
            DB::table('growth_automation_runs')->insert(['run_key' => $key, 'summary' => json_encode($receipt, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            GrowthAudit::record('supervisor.completed', $key, $actor, ['policy' => self::POLICY,
                'status' => $receipt['status'], 'finding_codes' => array_column($receipt['findings'], 'code'),
                'executed_actions' => $receipt['executed_actions']]);

            return $receipt;
        });
    }

    private function available(): bool
    {
        foreach (['settings', 'users', 'growth_controls', 'growth_jobs', 'growth_contents', 'growth_documents', 'growth_automation_runs', 'growth_audits'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return DB::table('growth_controls')->where('id', 1)->exists();
    }

    private function settings(bool $lock = false): array
    {
        $query = DB::table('settings')->whereIn('key', ['hermes_supervisor_enabled', 'growth_autopilot_enabled', 'growth_autopilot_actor_id']);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->pluck('value', 'key')->all();
    }

    private function actorValid(array $settings): bool
    {
        return DB::table('users')->where('id', (int) ($settings['growth_autopilot_actor_id'] ?? 0))->where('role', 'admin')->exists();
    }

    private function expiredQuery()
    {
        return DB::table('growth_documents')->where('status', 'verified')->where('review_at', '<=', now());
    }

    private function observe(): array
    {
        $settings = $this->settings();

        return ['growth_enabled' => (bool) DB::table('growth_controls')->where('id', 1)->value('enabled'),
            'autopilot_enabled' => ($settings['growth_autopilot_enabled'] ?? '') === '1',
            'job_failed' => DB::table('growth_jobs')->where('status', 'failed')->count(),
            'job_uncertain_cost' => DB::table('growth_jobs')->whereIn('status', ['processing', 'failed', 'completed'])
                ->where('max_cost_irt', '>', 0)->whereNull('actual_cost_irt')->count(),
            'job_stalled' => DB::table('growth_jobs')->where('status', 'processing')->where(function ($query) {
                $query->whereNull('started_at')->orWhere('started_at', '<=', now()->subMinutes(20));
            })->count(),
            'review_backlog' => DB::table('growth_contents')->whereIn('status', ['draft', 'review'])->count(),
            'knowledge_candidates' => DB::table('growth_documents')->where('status', 'candidate')->count(),
            'knowledge_outdated' => DB::table('growth_documents')->where('status', 'outdated')->count(),
            'knowledge_expired_verified' => $this->expiredQuery()->count(),
            'knowledge_missing_review' => DB::table('growth_documents')->where('status', 'verified')->whereNull('review_at')->count(),
            'crm_autopilot_enabled' => (bool) config('crm.autopilot', false)];
    }

    private function findings(array $observed): array
    {
        $checks = [
            'job_failed' => ['inspect_job_receipts', 'failed_jobs_reviewed'],
            'job_uncertain_cost' => ['reconcile_existing_receipts_without_retry', 'uncertain_receipts_reconciled'],
            'job_stalled' => ['inspect_stalled_jobs_without_retry', 'worker_and_receipt_state_known'],
            'knowledge_candidates' => ['request_knowledge_review', 'human_review_recorded'],
            'knowledge_outdated' => ['review_outdated_sources', 'current_verified_sources_available'],
            'knowledge_expired_verified' => ['expire_stale_verified_knowledge', 'expired_verified_count_zero'],
            'knowledge_missing_review' => ['review_sources_without_expiry', 'review_date_recorded'],
        ];
        $findings = [];
        foreach ($checks as $code => [$action, $verification]) {
            if (($observed[$code] ?? 0) > 0) {
                $findings[] = ['code' => $code, 'count' => $observed[$code], 'proposed_action' => $action, 'verification' => $verification];
            }
        }
        if (($observed['review_backlog'] ?? 0) >= 7) {
            $findings[] = ['code' => 'review_backlog', 'count' => $observed['review_backlog'],
                'proposed_action' => 'review_existing_content', 'verification' => 'review_backlog_below_limit'];
        }
        if (! ($observed['crm_autopilot_enabled'] ?? false)) {
            $findings[] = ['code' => 'crm_paused', 'count' => 1, 'proposed_action' => 'review_outreach_readiness',
                'verification' => 'sender_audience_and_receipts_reviewed'];
        }

        return $findings;
    }

    private function receipt(string $key, bool $dryRun, string $state, array $observed): array
    {
        return ['policy' => self::POLICY, 'run_key' => $key, 'dry_run' => $dryRun, 'status' => $state,
            'observed_at' => now('UTC')->toIso8601String(), 'observed_before' => $observed,
            'observed_after' => $observed, 'findings' => $this->findings($observed), 'executed_actions' => [],
            'campaign_policy' => (new HermesGrowthPolicy)->context(\DateTimeImmutable::createFromInterface(now('UTC'))),
            'section_oversight' => (new HermesSectionOversight)->snapshot(),
            'verification' => ['performed' => false], 'scope' => 'knowledge_expiry_and_aggregate_supervision_only'];
    }
}
