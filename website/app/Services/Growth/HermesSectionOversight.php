<?php

namespace App\Services\Growth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only coverage of the six owner-scoped sections.
 * Counts are observations, not SEO scores, completed tasks, revenue or approval.
 * No mail bodies, comment text, customer identities or credentials are returned.
 */
final class HermesSectionOversight
{
    public function snapshot(): array
    {
        $rules = config('hermes_growth.sections');
        $required = ['ai_growth', 'blog', 'kb', 'comments', 'marketing', 'mail'];
        if (! is_array($rules) || array_keys($rules) !== $required) {
            return ['state' => 'unavailable', 'reason' => 'curriculum_unavailable',
                'sections' => [], 'coverage_count' => 0, 'priority' => [],
                'sources_need_review' => true, 'learning_assessed' => false,
                'financial_operations_enabled' => false, 'executed_actions' => []];
        }
        $sections = [];
        foreach ($rules as $section => $rule) {
            $observation = $this->observe($section);
            $available = $observation['state'] === 'observed';
            $sections[$section] = [
                'path' => $rule['path'], 'observation' => $observation,
                'work_key' => 'hermes-section:'.now('Asia/Tehran')->toDateString().':'.$section,
                'next_action' => $available ? $rule['routine'] : 'restore_section_evidence',
                'verification_required' => $rule['verify'],
                'adapter_required' => $rule['adapter'],
                // This collector never certifies a separately deployed adapter.
                'execution_verified' => false, 'completion_evidence' => null,
            ];
        }
        $sourceAt = (string) config('hermes_growth.reviewed_at', '');
        $validSourceDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $sourceAt) === 1;
        $sourceDue = $validSourceDate
            ? \Illuminate\Support\Carbon::parse($sourceAt, 'Asia/Tehran')->addDays((int) config('hermes_growth.source_review_days', 30))
            : null;

        return [
            'state' => 'observed', 'version' => config('hermes_growth.version'),
            'observed_at' => now('UTC')->toIso8601String(),
            'sections' => $sections, 'coverage_count' => count($sections),
            'priority' => $this->priority($sections),
            'source_review_due' => $sourceDue?->toDateString(),
            'sources_need_review' => ! $sourceDue || $sourceDue->isPast(),
            'learning_assessed' => false,
            'search_performance_evidence' => 'not_collected_by_this_adapter',
            'financial_operations_enabled' => false,
            'executed_actions' => [],
        ];
    }

    private function observe(string $section): array
    {
        try {
            return match ($section) {
                'ai_growth' => $this->growth(),
                'blog', 'kb' => $this->posts($section),
                'comments' => $this->comments(),
                'marketing' => $this->marketing(),
                'mail' => $this->mail(),
                default => ['state' => 'unavailable', 'reason' => 'unknown_section', 'counts' => null],
            };
        } catch (Throwable) {
            // A failed observation is never a healthy zero or a public raw error.
            return ['state' => 'unavailable', 'reason' => 'read_failed', 'counts' => null];
        }
    }

    private function ready(array $tables): bool
    {
        foreach ($tables as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return false;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function missing(): array
    {
        return ['state' => 'unavailable', 'reason' => 'schema_unavailable', 'counts' => null];
    }

    private function growth(): array
    {
        if (! $this->ready(['growth_contents' => ['status'], 'growth_jobs' => ['status', 'max_cost_irt', 'actual_cost_irt']])) {
            return $this->missing();
        }

        return ['state' => 'observed', 'counts' => [
            'awaiting_review' => DB::table('growth_contents')->whereIn('status', ['draft', 'review'])->count(),
            'scheduled' => DB::table('growth_contents')->where('status', 'scheduled')->count(),
            'failed_jobs' => DB::table('growth_jobs')->where('status', 'failed')->count(),
            'uncertain_cost_jobs' => DB::table('growth_jobs')->whereIn('status', ['processing', 'failed', 'completed'])
                ->where('max_cost_irt', '>', 0)->whereNull('actual_cost_irt')->count(),
        ]];
    }

    private function posts(string $type): array
    {
        if (! $this->ready(['posts' => ['type', 'status', 'updated_at'], 'post_translations' => ['post_id', 'locale', 'title', 'excerpt']])) {
            return $this->missing();
        }
        $query = DB::table('posts')->where('type', $type);
        // Age is a review trigger; it does not establish that a page is wrong.
        return ['state' => 'observed', 'counts' => [
            'published' => (clone $query)->where('status', 'published')->count(),
            'unpublished' => (clone $query)->where('status', '!=', 'published')->count(),
            'review_due_by_age' => (clone $query)->where('status', 'published')->where('updated_at', '<', now()->subDays(90))->count(),
            'missing_title_or_excerpt' => DB::table('post_translations')->join('posts', 'posts.id', '=', 'post_translations.post_id')
                ->where('posts.type', $type)->where(function ($q) {
                    $q->whereNull('post_translations.title')->orWhere('post_translations.title', '')
                        ->orWhereNull('post_translations.excerpt')->orWhere('post_translations.excerpt', '');
                })->count(),
        ]];
    }

    private function comments(): array
    {
        if (! $this->ready(['comments' => ['approved', 'reply']])) {
            return $this->missing();
        }

        return ['state' => 'observed', 'counts' => [
            'pending_moderation' => DB::table('comments')->where('approved', false)->count(),
            // A comment without a reply may be praise/spam; classify before replying.
            'approved_without_reply' => DB::table('comments')->where('approved', true)->whereNull('reply')->count(),
        ]];
    }

    private function marketing(): array
    {
        if (! $this->ready(['crm_leads' => ['stage'], 'crm_messages' => ['direction', 'status', 'updated_at']])) {
            return $this->missing();
        }

        return ['state' => 'observed', 'counts' => [
            'replied_leads' => DB::table('crm_leads')->where('stage', 'replied')->count(),
            'queued_messages' => DB::table('crm_messages')->where('direction', 'out')->where('status', 'queued')->count(),
            'results_requiring_reconciliation' => DB::table('crm_messages')->where('direction', 'out')
                ->where(function ($q) {
                    $q->where('status', 'unknown')->orWhere(function ($stalled) {
                        $stalled->where('status', 'sending')->where('updated_at', '<=', now()->subMinutes(20));
                    });
                })->count(),
        ]];
    }

    private function mail(): array
    {
        if (! $this->ready(['mailbox_messages' => ['account', 'category', 'is_system', 'needs_reply', 'handled_at', 'received_at']])) {
            return $this->missing();
        }
        $query = DB::table('mailbox_messages')
            ->whereIn('account', ['ceo', 'support', 'info'])
            ->whereIn('category', ['sales', 'support'])
            ->where('is_system', false)->where('needs_reply', true)->whereNull('handled_at');

        return ['state' => 'observed', 'counts' => [
            'business_threads_needing_reply' => (clone $query)->count(),
            'business_threads_over_one_day' => (clone $query)->where('received_at', '<', now()->subDay())->count(),
        ]];
    }

    private function priority(array $sections): array
    {
        $rank = [];
        foreach ($sections as $section => $value) {
            $counts = $value['observation']['counts'] ?? [];
            $score = match ($section) {
                'mail' => ($counts['business_threads_needing_reply'] ?? 0) > 0 ? 100 : 20,
                'marketing' => ($counts['results_requiring_reconciliation'] ?? 0) > 0 ? 110
                    : (($counts['replied_leads'] ?? 0) > 0 ? 95 : 25),
                'ai_growth' => ($counts['uncertain_cost_jobs'] ?? 0) > 0 ? 105 : 50,
                'comments' => ($counts['pending_moderation'] ?? 0) > 0 ? 70 : 30,
                'kb' => 45,
                default => 40,
            };
            $rank[] = ['section' => $section, 'priority' => $score];
        }
        usort($rank, fn ($a, $b) => ($b['priority'] <=> $a['priority']) ?: strcmp($a['section'], $b['section']));

        return array_column($rank, 'section');
    }
}
