<?php

namespace App\Services\Madar;

use App\Models\MadarParticipation;
use App\Models\MarketingCampaign;
use App\Models\MarketingGrant;
use App\Models\User;
use App\Services\Marketing\CampaignManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Bounded, non-cash invoice rewards reuse the existing marketing ledger and margin guard. */
final class MadarRewards
{
    public function __construct(private MadarHub $hub, private CampaignManager $campaigns) {}

    public function offer(MadarParticipation $p, User $actor, int $campaignId, int $workDays, string $body): void
    {
        Gate::forUser($actor)->authorize('marketing.publish');
        DB::transaction(function () use ($p, $actor, $campaignId, $workDays, $body) {
            // Marketing's global lock order starts with campaign, then grant.
            $c = MarketingCampaign::whereKey($campaignId)->lockForUpdate()->firstOrFail();
            $fresh = MadarParticipation::whereKey($p->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status === 'offered' && (int) ($fresh->reward_snapshot['campaign_id'] ?? 0) === $c->id) {
                $this->hub->check((int) ($fresh->reward_snapshot['work_days'] ?? 0) === $workDays
                    && $fresh->messages()->where('event', 'offered')->value('body') === $body, 'retry_mismatch');

                return; // Repeated offer never reserves another reward.
            }
            $this->hub->check($fresh->status === 'requested' && ! $fresh->grant_id, 'state');
            $this->hub->check($c->trigger === 'manual' && $c->currency_code === 'IRT'
                && $c->rule['type'] === 'fixed' && ! ($c->rule['subsidy_approved'] ?? false)
                && ! ($c->message['enabled'] ?? true) && ! empty($c->rule['families'])
                && $c->automation['valid_hours'] >= ($workDays + 7) * 24, 'reward_campaign');
            foreach (['product_ids', 'providers', 'countries', 'plans'] as $filter) {
                // All restrictions must be representable in the visible offer; no hidden provider-specific scope.
                $this->hub->check(empty($c->rule[$filter]), 'reward_campaign');
            }
            $grant = $this->campaigns->issue($c, $fresh->customer, 'madar:'.$fresh->public_id);
            $due = now()->addDays($workDays);
            $this->hub->check($grant->expires_at->greaterThanOrEqualTo($due->copy()->addDays(7)), 'reward_campaign');
            $fresh->forceFill(['status' => 'offered', 'grant_id' => $grant->id, 'work_due_at' => $due,
                'reward_snapshot' => ['campaign_id' => $c->id, 'amount_irt' => $grant->liability,
                    'rule' => $grant->snapshot['rule'], 'expires_at' => $grant->expires_at->toIso8601String(),
                    'work_days' => $workDays], 'customer_read_at' => null, 'staff_read_at' => now()])->save();
            $fresh->messages()->create(['sender_type' => 'staff', 'sender_id' => $actor->id, 'body' => $body, 'event' => 'offered']);
            $this->campaigns->audit($c, $actor, 'madar_offer', 'Reserved before work starts', ['participation' => $fresh->public_id, 'grant_id' => $grant->id]);
        }, 5);
    }

    public function accept(MadarParticipation $p): void
    {
        DB::transaction(function () use ($p) {
            $fresh = MadarParticipation::whereKey($p->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status === 'working') {
                return;
            }
            $this->hub->check($fresh->status === 'offered' && $fresh->work_due_at?->isFuture() === true
                && $fresh->grant && ! $fresh->grant->revoked_at && $fresh->grant->expires_at->isFuture(), 'deadline');
            $fresh->forceFill(['status' => 'working', 'accepted_at' => now(), 'staff_read_at' => null, 'customer_read_at' => now()])->save();
            $fresh->messages()->create(['sender_type' => 'system', 'event' => 'accepted']);
        }, 3);
    }

    public function extend(MadarParticipation $p, User $actor, int $workDays, string $body): void
    {
        Gate::forUser($actor)->authorize('marketing.publish');
        DB::transaction(function () use ($p, $actor, $workDays, $body) {
            $this->hub->check($p->grant_id !== null, 'state');
            $ref = MarketingGrant::findOrFail($p->grant_id);
            MarketingCampaign::whereKey($ref->campaign_id)->lockForUpdate()->firstOrFail();
            $fresh = MadarParticipation::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $grant = MarketingGrant::whereKey($fresh->grant_id)->lockForUpdate()->firstOrFail();
            $due = now()->addDays($workDays);
            $this->hub->check(in_array($fresh->status, ['offered', 'working', 'submitted', 'changes_requested'], true)
                && ! $grant->revoked_at && $due->greaterThan($fresh->work_due_at)
                && $grant->expires_at->greaterThanOrEqualTo($due->copy()->addDays(7)), 'deadline');
            $fresh->forceFill(['work_due_at' => $due, 'customer_read_at' => null, 'staff_read_at' => now()])->save();
            $fresh->messages()->create(['sender_type' => 'staff', 'sender_id' => $actor->id,
                'event' => 'deadline_extended', 'body' => $body]);
        }, 5);
    }

    public function review(MadarParticipation $p, User $actor, string $action, string $body): void
    {
        Gate::forUser($actor)->authorize('marketing.publish');
        DB::transaction(function () use ($p, $actor, $action, $body) {
            $this->hub->check($p->grant_id !== null, 'state');
            $ref = MarketingGrant::findOrFail($p->grant_id);
            $c = MarketingCampaign::whereKey($ref->campaign_id)->lockForUpdate()->firstOrFail();
            $fresh = MadarParticipation::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $g = MarketingGrant::whereKey($fresh->grant_id)->lockForUpdate()->firstOrFail();
            if ($fresh->status === 'rewarded' && $action === 'approve') {
                return;
            }
            $this->hub->check($fresh->status === 'submitted', 'state');
            $this->hub->check(! $g->revoked_at && $g->expires_at->isFuture(), 'expired');
            $proof = $fresh->messages()->where('is_submission', true)->latest('id')->first();
            $this->hub->check($proof !== null && $proof->rights_confirmed, 'evidence');
            $public = $fresh->reward_snapshot['mission']['mission']['public_content'] ?? config('madar.campaigns.'.$fresh->campaign_key.'.public_content');
            $this->hub->check(! $public || $proof->disclosure_confirmed, 'disclosure');
            $state = $action === 'approve' ? 'rewarded' : 'changes_requested';
            $this->hub->check($action === 'approve' || $fresh->work_due_at?->isFuture() === true, 'deadline');
            $fresh->forceFill(['status' => $state, 'reviewed_at' => now(), 'reviewed_by' => $actor->id,
                'customer_read_at' => null, 'staff_read_at' => now()])->save();
            $fresh->messages()->create(['sender_type' => 'staff', 'sender_id' => $actor->id,
                'body' => $body, 'event' => $state]);
            $this->campaigns->audit($c, $actor, 'madar_'.$state, $body, ['participation' => $fresh->public_id, 'grant_id' => $g->id]);
        }, 5);
    }

    public function close(MadarParticipation $p, string $status, string $body, string $role, int $actorId): void
    {
        DB::transaction(function () use ($p, $status, $body, $role, $actorId) {
            $c = null;
            if ($p->grant_id) {
                $ref = MarketingGrant::findOrFail($p->grant_id);
                $c = MarketingCampaign::whereKey($ref->campaign_id)->lockForUpdate()->firstOrFail();
            }
            $fresh = MadarParticipation::whereKey($p->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status === $status) {
                return;
            }
            $this->hub->check(in_array($fresh->status, MadarParticipation::OPEN, true), 'state');
            if ($fresh->grant_id) {
                // If an offer arrived after the initial read, retry with the campaign lock first.
                $this->hub->check($c !== null, 'state');
                $g = MarketingGrant::whereKey($fresh->grant_id)->lockForUpdate()->firstOrFail();
                $g->forceFill(['revoked_at' => now(), 'revoke_reason' => 'Madar '.$status])->save();
                if ($g->budget_held) {
                    $c->decrement('held', $g->liability);
                    $g->forceFill(['budget_held' => false])->save();
                }
            }
            $fresh->forceFill(['status' => $status, 'customer_read_at' => $role === 'customer' ? now() : null,
                'staff_read_at' => $role === 'staff' ? now() : null])->save();
            $fresh->messages()->create(['sender_type' => $role, 'sender_id' => $actorId, 'event' => $status, 'body' => $body]);
        }, 5);
    }
}
