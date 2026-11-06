<?php

namespace App\Services\Madar;

use App\Models\Customer;
use App\Models\MadarMission;
use App\Models\MarketingCampaign;
use App\Models\MarketingGrant;
use App\Models\MarketingRedemption;
use App\Models\User;
use App\Services\Marketing\CampaignAudience;
use App\Services\Marketing\CampaignManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;

/** Public missions freeze reward and acceptance terms before any customer creates content. */
final class MadarPublishing
{
    public function __construct(private MadarHub $hub) {}

    public function validProgram(MarketingCampaign $c, int $amount): bool
    {
        $rule = $c->rule;

        return $c->status === 'active' && $c->approved_at && ! $c->starts_at?->isFuture() && ! $c->ends_at?->isPast()
            && $c->trigger === 'manual' && $c->currency_code === 'IRT' && $rule['type'] === 'fixed'
            && $rule['value'] === $amount && $rule['max_discount'] === $amount && $rule['min_purchase'] >= $amount
            && ! ($rule['subsidy_approved'] ?? false) && ! ($c->message['enabled'] ?? true)
            && ! empty($rule['families']) && $c->automation['valid_hours'] >= (config('madar.review_days') + 30) * 24
            && empty($rule['product_ids']) && empty($rule['providers']) && empty($rule['countries']) && empty($rule['plans']);
    }

    public function capacity(MarketingCampaign $c, int $amount): int
    {
        if ($amount <= 0 || ! $this->validProgram($c, $amount)) {
            return 0;
        }
        $uses = MarketingRedemption::where('campaign_id', $c->id)->whereIn('status', ['reserved', 'committed', 'reconciliation', 'refunded']);
        $held = MarketingGrant::where('campaign_id', $c->id)->where('budget_held', true)
            ->whereNotIn('id', (clone $uses)->select('grant_id'))->count();

        return max(0, min(intdiv(max(0, $c->budget - $c->held - $c->spent), $amount), $c->max_uses - $uses->count() - $held));
    }

    public function eligible(MarketingCampaign $c, Customer $customer, int $amount): bool
    {
        if (! $customer->isActive() || ! in_array($customer->locale ?: 'fa', $c->rule['locales'], true)
            || ! app(CampaignAudience::class)->matches($customer, $c->audience)) {
            return false;
        }
        $uses = MarketingRedemption::where('campaign_id', $c->id)->where('customer_id', $customer->id)
            ->whereIn('status', ['reserved', 'committed', 'reconciliation', 'refunded']);
        $promises = MarketingGrant::where('campaign_id', $c->id)->where('customer_id', $customer->id)
            ->where('budget_held', true)->whereNotIn('id', (clone $uses)->select('grant_id'));

        return (clone $uses)->count() + (clone $promises)->count() < $c->max_per_customer
            && (clone $uses)->sum('amount') + (clone $promises)->sum('liability') + $amount <= $c->rule['per_customer_cap'];
    }

    public function publish(string $key, MarketingCampaign $campaign, int $amount, User $actor): MadarMission
    {
        Gate::forUser($actor)->authorize('marketing.publish');
        $this->hub->check($this->hub->catalogReady() && isset(config('madar.campaigns')[$key]), 'closed');

        return DB::transaction(function () use ($key, $campaign, $amount, $actor) {
            $c = MarketingCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $control = DB::table('madar_campaigns')->where('campaign_key', $key)->lockForUpdate()->firstOrFail();
            $this->hub->check($this->validProgram($c, $amount) && $this->capacity($c, $amount) > 0, 'fixed_program');
            $copy = [];
            $terms = [];
            foreach (['fa', 'en', 'tr'] as $locale) {
                $copy[$locale] = Lang::get('madar.campaigns.'.$key, [], $locale);
                $terms[$locale] = array_map(fn ($name) => Lang::get('madar.'.$name, [], $locale),
                    ['terms_text', 'limits', 'sponsorship', 'hourly_notice', 'margin', 'capacity_note']);
            }
            $snapshot = ['copy' => $copy, 'terms_copy' => $terms, 'mission' => array_replace(config('madar.campaigns.'.$key), ['reward_irt' => $amount]),
                'rule' => $c->rule, 'validity_hours' => $c->automation['valid_hours'], 'terms_version' => config('madar.terms_version'),
                'review_days' => config('madar.review_days'), 'weekly_cap' => config('madar.weekly_claim_cap'), 'repeat_days' => config('madar.repeat_days')];
            $current = $control->active_mission_id ? MadarMission::find($control->active_mission_id) : null;
            if ($current && $current->reward_campaign_id === $c->id && $current->reward_amount_irt === $amount && $current->submissions_until->isFuture() && $current->snapshot == $snapshot) {
                DB::table('madar_campaigns')->where('id', $control->id)->update(['open' => true, 'updated_by' => $actor->id, 'updated_at' => now()]);

                return $current;
            }
            $until = now()->addDays(30);
            if ($c->ends_at && $c->ends_at->copy()->subHours($c->automation['valid_hours'])->lessThan($until)) {
                $until = $c->ends_at->copy()->subHours($c->automation['valid_hours']);
            }
            $this->hub->check($until->greaterThan(now()->addDay()), 'fixed_program');
            $mission = MadarMission::create(['campaign_key' => $key, 'version' => MadarMission::where('campaign_key', $key)->max('version') + 1,
                'reward_campaign_id' => $c->id, 'reward_amount_irt' => $amount, 'published_at' => now(), 'submissions_until' => $until,
                'published_by' => $actor->id, 'snapshot' => $snapshot]);
            DB::table('madar_campaigns')->where('id', $control->id)->update(['active_mission_id' => $mission->id, 'reward_campaign_id' => $c->id,
                'open' => true, 'updated_by' => $actor->id, 'updated_at' => now()]);
            app(CampaignManager::class)->audit($c, $actor, 'madar_publish', 'Fixed public reward published', ['mission_id' => $mission->id, 'amount_irt' => $amount]);

            return $mission;
        }, 5);
    }
}
