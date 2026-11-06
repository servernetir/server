<?php

namespace App\Services\Marketing;

use App\Models\Customer;
use App\Models\GiftCoupon;
use App\Models\Invoice;
use App\Models\MarketingCampaign;
use App\Models\MarketingDelivery;
use App\Models\MarketingGrant;
use App\Models\MarketingRedemption;
use App\Models\MarketingRun;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CampaignRunner
{
    public function issueDue(MarketingCampaign $campaign, Customer $customer, bool $dry = false): bool
    {
        if ($campaign->trigger === 'birthday' && app(\App\Services\Birthday\BirthdayProgram::class)->enabled()) {
            return false; // The automatic at-cost window replaces new birthday coupons.
        }
        $audience = app(CampaignAudience::class);
        if (! $audience->matches($customer, $campaign->audience) || ! $audience->due($campaign, $customer)) {
            return false;
        }
        $cycle = $campaign->trigger === 'birthday'
            ? 'birthday:'.$customer->id.':'.now()->setTimezone($campaign->timezone)->year
            : $campaign->id.':'.$customer->id.':'.$campaign->trigger;
        $runKey = hash('sha256', $cycle);
        $already = fn () => MarketingRun::where('run_key', $runKey)->exists() ||
            ($campaign->trigger === 'birthday' && (
                MarketingRun::where('customer_id', $customer->id)->where('trigger', 'birthday')
                    ->where('occurred_at', '>', now()->subDays(330))->exists() ||
                GiftCoupon::where('customer_id', $customer->id)->where('reason', 'birthday')
                    ->where('created_at', '>', now()->subDays(330))->exists()));
        if ($already()) {
            return false;
        }
        if ($dry) {
            return true;
        }

        return DB::transaction(function () use ($campaign, $customer, $runKey, $already) {
            // Serializes birthday issuance across campaigns, including changed calendars/dates.
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($already()) {
                return false;
            }
            MarketingRun::create(['run_key' => $runKey, 'campaign_id' => $campaign->id,
                'customer_id' => $customer->id, 'trigger' => $campaign->trigger, 'occurred_at' => now()]);
            app(CampaignManager::class)->issue($campaign, $customer, $runKey);

            return true;
        }, 5);
    }

    public function tick(bool $dry = false): array
    {
        $stats = ['eligible' => 0, 'issued' => 0, 'blocked' => 0];
        if (! Schema::hasTable('marketing_campaigns')) {
            return $stats;
        }
        if (! $dry) {
            MarketingRedemption::where('status', 'reserved')->where('expires_at', '<=', now())
                ->chunkById(100, function ($rows) {
                    foreach ($rows as $r) {
                        app(DiscountEngine::class)->sync(Invoice::findOrFail($r->invoice_id));
                    }
                });
            app(DiscountEngine::class)->expireGrants();
            MarketingDelivery::where('status', 'processing')->where('attempted_at', '<', now()->subMinutes(10))
                ->update(['status' => 'unknown', 'reason' => 'interrupted_worker', 'updated_at' => now()]);
        }
        foreach (MarketingCampaign::where('status', 'active')->where('trigger', '!=', 'manual')->cursor() as $campaign) {
            if ($campaign->starts_at?->isFuture() || $campaign->ends_at?->isPast()) {
                continue;
            }
            $liability = min($campaign->rule['max_discount'], $campaign->rule['type'] === 'fixed' ? $campaign->rule['value'] : PHP_INT_MAX);
            if ($campaign->budget - $campaign->held - $campaign->spent < $liability) {
                continue;
            }
            $limit = config('marketing.scheduler_batch');
            $customers = Customer::where('status', 'active')->where('id', '>', $campaign->scheduler_cursor)
                ->orderBy('id')->limit($limit)->get();
            foreach ($customers as $customer) {
                try {
                    if ($this->issueDue($campaign, $customer, $dry)) {
                        $stats['eligible']++;
                        $stats['issued'] += $dry ? 0 : 1;
                    }
                } catch (ValidationException) {
                    $stats['blocked']++;
                }
            }
            if (! $dry) {
                $campaign->update(['scheduler_cursor' => $customers->count() < $limit ? 0 : $customers->last()->id]);
            }
        }
        if (! $dry) {
            foreach (MarketingDelivery::where('status', 'scheduled')->where('scheduled_at', '<=', now())
                ->orderBy('id')->limit(config('marketing.scheduler_batch'))->get() as $delivery) {
                app(CampaignMessages::class)->dispatch($delivery);
            }
            Setting::put('marketing_last_tick', now()->toIso8601String());
        }

        return $stats;
    }

    public function preview(MarketingCampaign $campaign): array
    {
        $count = 0;
        $sample = [];
        foreach (Customer::where('status', 'active')->orderBy('id')->lazyById(200) as $customer) {
            if (! app(CampaignAudience::class)->matches($customer, $campaign->audience)) {
                continue;
            }
            $count++;
            if (count($sample) < 5) {
                $sample[] = $customer->code; // no phone, name, email, or tracking data
            }
        }
        $sampleCustomer = new Customer(['locale' => 'fa']);
        $g = new MarketingGrant(['code' => 'SN-PREVIEW', 'track_token' => 'PREVIEW',
            'expires_at' => now()->addHours($campaign->automation['valid_hours']),
            'snapshot' => ['rule' => $campaign->rule, 'message' => $campaign->message, 'timezone' => $campaign->timezone]]);
        $text = app(CampaignMessages::class)->text($g, $sampleCustomer);

        return ['audience' => $count, 'sample' => $sample, 'text' => $text,
            'estimate' => app(CampaignMessages::class)->estimate($text),
            'maximum_commitment' => min($count, $campaign->max_uses) * $campaign->rule['max_discount']];
    }
}
