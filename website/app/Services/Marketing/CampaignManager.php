<?php

namespace App\Services\Marketing;

use App\Models\Customer;
use App\Models\MadarMission;
use App\Models\MarketingCampaign;
use App\Models\MarketingGrant;
use App\Models\MarketingRedemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CampaignManager
{
    public function validate(array $data): array
    {
        $data = Validator::make($data, [
            'name' => 'required|string|max:190', 'currency_code' => ['required', Rule::in(['IRT', 'IRR', 'EUR', 'USD', 'TRY'])],
            'trigger' => ['required', Rule::in(['manual', 'birthday', 'signup', 'first_purchase', 'inactive', 'scheduled'])],
            'timezone' => 'required|timezone', 'budget' => 'required|integer|min:1|max:1000000000000000',
            'max_uses' => 'required|integer|min:1|max:1000000', 'max_per_customer' => 'required|integer|min:1|max:100',
            'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after:starts_at',
            'rule' => 'required|array:type,value,max_discount,min_purchase,locales,operations,product_ids,families,providers,countries,plans,stackable,min_margin_bp,subsidy_approved,subsidy_reason,per_customer_cap',
            'rule.type' => ['required', Rule::in(['percent', 'fixed'])], 'rule.value' => 'required|integer|min:1|max:1000000000000',
            'rule.max_discount' => 'required|integer|min:1|max:1000000000000',
            'rule.min_purchase' => 'required|integer|min:0|max:1000000000000',
            'rule.per_customer_cap' => 'required|integer|min:1|max:1000000000000',
            'rule.locales' => 'required|array|min:1', 'rule.locales.*' => Rule::in(['fa', 'en', 'tr']),
            'rule.operations' => 'required|array|min:1', 'rule.operations.*' => Rule::in(config('marketing.supported_operations')),
            'rule.product_ids' => 'sometimes|array', 'rule.product_ids.*' => 'integer|exists:products,id',
            'rule.families' => 'sometimes|array', 'rule.families.*' => 'string|max:40',
            'rule.providers' => 'sometimes|array', 'rule.providers.*' => 'string|max:60',
            'rule.countries' => 'sometimes|array', 'rule.countries.*' => 'string|size:2',
            'rule.plans' => 'sometimes|array', 'rule.plans.*' => 'string|max:190',
            'rule.stackable' => 'required|boolean', 'rule.min_margin_bp' => 'required|integer|min:0|max:10000',
            'rule.subsidy_approved' => 'required|boolean', 'rule.subsidy_reason' => 'nullable|string|max:190',
            'audience' => 'present|array:customer_ids,locales,tags,active_service,min_purchases,max_purchases,service_plans,inactive_days',
            'audience.customer_ids' => 'sometimes|array', 'audience.customer_ids.*' => 'integer|exists:customers,id',
            'audience.locales' => 'sometimes|array', 'audience.locales.*' => Rule::in(['fa', 'en', 'tr']),
            'audience.tags' => 'sometimes|array', 'audience.tags.*' => 'string|max:60',
            'audience.active_service' => 'nullable|boolean',
            'audience.min_purchases' => 'nullable|integer|min:0', 'audience.max_purchases' => 'nullable|integer|min:0',
            'audience.service_plans' => 'sometimes|array', 'audience.service_plans.*' => 'string|max:190',
            'audience.inactive_days' => 'nullable|integer|min:1|max:3650',
            'automation' => 'required|array:valid_hours,at,reminder_hours,inactive_days',
            'automation.valid_hours' => 'required|integer|min:1|max:2160',
            'automation.at' => 'required|date_format:H:i', 'automation.reminder_hours' => 'nullable|integer|min:1|max:2160',
            'automation.inactive_days' => 'nullable|integer|min:1|max:3650',
            'message' => 'required|array:enabled,template,landing_path,service_name,limit,budget',
            'message.enabled' => 'required|boolean', 'message.template' => 'nullable|string|max:1000',
            'message.landing_path' => 'required|string|max:190', 'message.service_name' => 'required|string|max:100',
            'message.limit' => 'required|integer|min:0|max:100000', 'message.budget' => 'required|integer|min:0|max:1000000000000',
        ])->validate();
        $errors = [];
        if ($data['rule']['type'] === 'percent' && $data['rule']['value'] > 10000) {
            $errors['rule.value'] = 'Percent must be between 1 and 10000 basis points.';
        }
        if ($data['rule']['subsidy_approved'] && blank($data['rule']['subsidy_reason'] ?? null)) {
            $errors['rule.subsidy_reason'] = 'A reason is required for a bounded subsidy or unknown supplier cost.';
        }
        if (($data['automation']['reminder_hours'] ?? 0) >= $data['automation']['valid_hours']) {
            $errors['automation.reminder_hours'] = 'Reminder must fall within validity.';
        }
        $path = $data['message']['landing_path'];
        if (! preg_match('~^/(?:en/|tr/)?account/(?:store|cloud-store|order/[a-zA-Z0-9_-]+)$~D', $path)) {
            $errors['message.landing_path'] = 'Choose an internal account store URL.';
        }
        preg_match_all('/{{\s*([^{}]+)\s*}}/u', $data['message']['template'] ?? '', $matches);
        $allowed = ['first_name', 'discount_label', 'service_name', 'coupon_code', 'expires_at_local', 'redeem_url', 'unsubscribe_instructions'];
        if (array_diff(array_map('trim', $matches[1]), $allowed)) {
            $errors['message.template'] = 'Unknown template variable.';
        }
        if ($data['message']['enabled']) {
            foreach (['coupon_code', 'discount_label', 'service_name', 'expires_at_local', 'redeem_url', 'unsubscribe_instructions'] as $variable) {
                if (! in_array($variable, array_map('trim', $matches[1]), true)) {
                    $errors['message.template'] = 'Template must include the code, amount/cap, service, expiry, link and opt-out.';
                }
            }
        }
        if ($data['rule']['max_discount'] > $data['rule']['per_customer_cap']) {
            $errors['rule.per_customer_cap'] = 'Customer cap must cover a guaranteed personal offer.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $data['rule']['currency'] = $data['currency_code'];

        return $data;
    }

    public function save(array $data, User $actor, ?MarketingCampaign $campaign = null): MarketingCampaign
    {
        $data = $this->validate($data);

        return DB::transaction(function () use ($data, $actor, $campaign) {
            if ($campaign) {
                $campaign = MarketingCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                if (MarketingGrant::where('campaign_id', $campaign->id)->exists()
                    || (Schema::hasTable('madar_missions')
                        && MadarMission::where('reward_campaign_id', $campaign->id)->exists())) {
                    throw ValidationException::withMessages(['campaign' => 'Issued promises are immutable. Create a new campaign version.']);
                }
                $campaign->fill($data)->forceFill(['status' => 'draft', 'approved_at' => null, 'approved_by' => null,
                    'version' => $campaign->version + 1, 'messages_paused' => true])->save();
            } else {
                $campaign = MarketingCampaign::create($data);
            }
            $this->audit($campaign, $actor, 'save', 'Campaign draft saved');

            return $campaign;
        });
    }

    public function transition(MarketingCampaign $campaign, User $actor, string $action, string $reason): void
    {
        DB::transaction(function () use ($campaign, $actor, $action, $reason) {
            $c = MarketingCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($action === 'activate') {
                if ($c->ends_at?->isPast() || $c->budget < $c->spent + $c->held) {
                    throw ValidationException::withMessages(['campaign' => 'Campaign dates or budget are invalid.']);
                }
                $c->forceFill(['status' => 'active', 'approved_at' => now(), 'approved_by' => $actor->id]);
            } elseif ($action === 'pause') {
                $c->status = 'paused'; // existing grants retain their snapshot
            } elseif (in_array($action, ['pause_messages', 'resume_messages'], true)) {
                $c->messages_paused = $action === 'pause_messages';
            } else {
                throw ValidationException::withMessages(['action' => 'Unsupported campaign action.']);
            }
            $c->save();
            $this->audit($c, $actor, $action, $reason);
        }, 5);
    }

    public function issue(MarketingCampaign $campaign, ?Customer $customer, string $key, bool $public = false): MarketingGrant
    {
        return DB::transaction(function () use ($campaign, $customer, $key, $public) {
            $c = MarketingCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $issueKey = hash('sha256', $c->id.':'.($public ? 'public' : $customer?->id).':'.$key);
            if ($existing = MarketingGrant::where('issue_key', $issueKey)->first()) {
                return $existing;
            }
            if ($c->status !== 'active' || ! $c->approved_at || $c->starts_at?->isFuture() || $c->ends_at?->isPast()) {
                throw ValidationException::withMessages(['campaign' => __('marketing.inactive')]);
            }
            if (! $public && (! $customer || ! app(CampaignAudience::class)->matches($customer, $c->audience))) {
                throw ValidationException::withMessages(['campaign' => __('marketing.scope')]);
            }
            if ($public && $c->trigger !== 'manual') {
                throw ValidationException::withMessages(['campaign' => 'Public codes require a manual campaign.']);
            }
            // Personal guarantees reserve their maximum commitment at issuance.
            $liability = min($c->rule['max_discount'], $c->rule['type'] === 'fixed' ? $c->rule['value'] : PHP_INT_MAX);
            if (! $public) {
                $customerUses = MarketingRedemption::where('campaign_id', $c->id)
                    ->where('customer_id', $customer->id)->whereIn('status', ['reserved', 'committed', 'reconciliation', 'refunded']);
                $customerPromises = MarketingGrant::where('campaign_id', $c->id)->where('customer_id', $customer->id)
                    ->where('budget_held', true);
                if ($c->trigger === 'birthday') {
                    $customerUses->where('created_at', '>', now()->subDays(330));
                    $customerPromises->where('starts_at', '>', now()->subDays(330));
                }
                $customerPromises->whereNotIn('id', (clone $customerUses)->select('grant_id'));
                if ((clone $customerUses)->count() + (clone $customerPromises)->count() >= $c->max_per_customer ||
                    (clone $customerUses)->sum('amount') + (clone $customerPromises)->sum('liability') + $liability > $c->rule['per_customer_cap']) {
                    throw ValidationException::withMessages(['campaign' => __('marketing.capacity')]);
                }
            }
            if (! $public) {
                $uses = MarketingRedemption::where('campaign_id', $c->id)
                    ->whereIn('status', ['reserved', 'committed', 'reconciliation', 'refunded']);
                $personalCount = (clone $uses)->count() + MarketingGrant::where('campaign_id', $c->id)
                    ->where('budget_held', true)->whereNotIn('id', (clone $uses)->select('grant_id'))->count();
                if ($personalCount >= $c->max_uses || $c->held + $c->spent + $liability > $c->budget) {
                    throw ValidationException::withMessages(['campaign' => __('marketing.budget')]);
                }
                $c->increment('held', $liability);
            }
            $expires = now()->addHours($c->automation['valid_hours']);
            if ($c->ends_at && $c->ends_at->lessThan($expires)) {
                $expires = $c->ends_at->copy();
            }
            $snapshot = ['version' => $c->version, 'rule' => $c->rule, 'audience' => $c->audience,
                'max_uses' => $public ? $c->max_uses : 1, 'max_per_customer' => $public ? $c->max_per_customer : 1,
                'message' => $c->message, 'automation' => $c->automation, 'timezone' => $c->timezone];
            $grant = MarketingGrant::create(['campaign_id' => $c->id, 'customer_id' => $public ? null : $customer->id,
                'code' => 'SN-'.strtoupper(bin2hex(random_bytes(10))), 'issue_key' => $issueKey,
                'track_token' => bin2hex(random_bytes(24)), 'snapshot' => $snapshot, 'liability' => $liability,
                'budget_held' => ! $public, 'starts_at' => now(), 'expires_at' => $expires]);
            if (! $public) {
                app(CampaignMessages::class)->queue($grant, $customer);
            }

            return $grant;
        }, 5);
    }

    public function audit(?MarketingCampaign $campaign, ?User $actor, string $action, string $reason, array $details = []): void
    {
        DB::table('marketing_audits')->insert(['campaign_id' => $campaign?->id, 'actor_id' => $actor?->id,
            'action' => $action, 'reason' => mb_substr($reason, 0, 255),
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
