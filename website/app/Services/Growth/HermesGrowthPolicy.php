<?php

namespace App\Services\Growth;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Owner-authorized growth boundaries. A decision is not a dispatch adapter.
 * Inputs must be current private CRM evidence; missing evidence blocks sending.
 * No network, database, credentials, model calls or human-review impersonation.
 */
final class HermesGrowthPolicy
{
    public const VERSION = 'hermes-growth-2026-v1';

    private const THEMES = [
        'iranian_site_health', 'wordpress_speed', 'backup_continuity', 'web_agency_partnerships',
        'ecommerce_health', 'managed_vps_migration', 'b2b_transactional_email', 'dotnet_hosting',
        'gpu_ready_apps', 'ai_gateway', 'hosting_resellers', 'year_one_retention',
        'site_health_v2', 'english_b2b', 'turkish_b2b', 'backup_recovery',
        'agency_technical_clinic', 'useful_tools', 'industrial_companies', 'developers_saas',
        'approved_case_studies', 'customer_referrals', 'repeat_profitable_campaigns', 'two_year_review',
    ];

    public function context(DateTimeImmutable $now): array
    {
        $local = $now->setTimezone(new DateTimeZone('Asia/Tehran'));
        $date = $local->format('Y-m-d');
        $active = $date >= '2026-10-26' && $date <= '2028-10-26';
        $months = ((int) $local->format('Y') - 2026) * 12 + (int) $local->format('n') - 10;
        $month = $months + ((int) $local->format('j') >= 26 ? 1 : 0);
        $month = max(1, min(24, $month));

        return [
            'policy' => self::VERSION, 'active' => $active,
            'daily_key' => 'hermes-growth:'.$date, 'timezone' => 'Asia/Tehran',
            'month' => $active ? $month : null,
            'theme' => $active ? self::THEMES[$month - 1] : null,
            'new_contact_cap' => 5, 'followup_cap' => 2, 'max_followups' => 1,
            'followup_min_business_days' => 7, 'no_reply_cooldown_days' => 90,
            'weekly_partnership_opportunities' => 2, 'max_pages_per_domain' => 5,
            'daily_ai_irt' => 50000, 'monthly_ai_irt' => 1000000,
            'new_ad_link_tool_spend_irt' => 0, 'reuse_native_content_production' => true,
            'human_review_may_be_impersonated' => false,
        ];
    }

    /** Amounts are integer IRT, and usage includes reservations/uncertain costs. */
    public function outreachDecision(DateTimeImmutable $now, array $evidence): array
    {
        $context = $this->context($now);
        $blocked = [];
        if (! $context['active']) {
            $blocked[] = 'outside_program_dates';
        }
        foreach ([
            'operation_claimed', 'history_verified', 'recipient_verified', 'contact_basis_verified',
            'sender_verified', 'optout_available', 'suppression_checked', 'report_accessible',
            'inbox_current', 'receipt_adapter_verified', 'campaign_segment_active', 'cooldown_clear',
            'organization_history_verified',
        ] as $key) {
            if (($evidence[$key] ?? null) !== true) {
                $blocked[] = $key;
            }
        }
        // These must be explicitly false, not absent, "0", or a model's guess.
        foreach (['suppressed', 'bounced', 'complaint', 'unknown_outcome', 'emergency_pause'] as $key) {
            if (($evidence[$key] ?? null) !== false) {
                $blocked[] = $key;
            }
        }
        foreach (['new_today', 'followups_today', 'daily_ai_irt', 'monthly_ai_irt', 'followups_sent'] as $key) {
            if (! isset($evidence[$key]) || ! is_int($evidence[$key]) || $evidence[$key] < 0) {
                $blocked[] = $key;
            }
        }
        if (is_int($evidence['daily_ai_irt'] ?? null) && $evidence['daily_ai_irt'] > 50000) {
            $blocked[] = 'daily_budget_exceeded';
        }
        if (is_int($evidence['monthly_ai_irt'] ?? null) && $evidence['monthly_ai_irt'] > 1000000) {
            $blocked[] = 'monthly_budget_exceeded';
        }
        $stage = $evidence['stage'] ?? null;
        if ($stage === 'new') {
            if (($evidence['new_today'] ?? 5) >= 5 || ($evidence['followups_sent'] ?? 1) !== 0) {
                $blocked[] = 'new_contact_cap_or_stage';
            }
        } elseif ($stage === 'followup') {
            if (($evidence['followups_today'] ?? 2) >= 2 || ($evidence['followups_sent'] ?? 1) !== 0) {
                $blocked[] = 'followup_cap';
            }
            if (($evidence['prior_send_receipt_verified'] ?? null) !== true
                || ($evidence['no_reply_verified'] ?? null) !== true
                || ! is_int($evidence['elapsed_business_days'] ?? null)
                || $evidence['elapsed_business_days'] < 7) {
                $blocked[] = 'followup_not_due';
            }
        } else {
            $blocked[] = 'invalid_stage';
        }

        return ['policy' => self::VERSION, 'eligible' => $blocked === [],
            'blockers' => array_values(array_unique($blocked)),
            'daily_key' => $context['daily_key'], 'executes_action' => false];
    }
}
