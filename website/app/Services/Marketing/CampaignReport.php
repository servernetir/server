<?php

namespace App\Services\Marketing;

use App\Models\Invoice;
use App\Models\MarketingCampaign;
use App\Models\MarketingDelivery;
use App\Models\MarketingGrant;
use App\Models\MarketingRedemption;
use Illuminate\Support\Facades\DB;

class CampaignReport
{
    public function for(MarketingCampaign $c): array
    {
        $reds = MarketingRedemption::where('campaign_id', $c->id)->get();
        $paid = $reds->where('status', 'committed');
        $ids = $paid->pluck('invoice_id');
        $deliveries = MarketingDelivery::where('campaign_id', $c->id);
        $before = $paid->sum(fn ($r) => $r->snapshot['original']['total']);
        $after = (int) Invoice::whereIn('id', $ids)->where('status', 'paid')->sum('paid');
        $unknownCosts = $paid->filter(fn ($r) => $r->snapshot['quote']['cost'] === null)->count();
        $sms = (clone $deliveries)->whereNotNull('attempted_at');
        $unknownSms = (clone $sms)->whereNull('estimated_cost')->count();

        return [
            'currency' => $c->currency_code, 'issued' => MarketingGrant::where('campaign_id', $c->id)->count(),
            'expired' => MarketingGrant::where('campaign_id', $c->id)->where('expires_at', '<=', now())->whereNull('revoked_at')
                ->whereNotIn('id', MarketingRedemption::where('campaign_id', $c->id)->whereIn('status', ['committed', 'refunded'])->select('grant_id'))->count(),
            'revoked' => MarketingGrant::where('campaign_id', $c->id)->whereNotNull('revoked_at')->count(),
            'redemptions' => $reds->countBy('status')->all(), 'paid_orders' => $paid->count(),
            'sales_before' => $before, 'sales_after' => $after, 'discount_used' => $paid->sum('amount'),
            'tax_reduction' => $paid->sum('tax_reduction'),
            'delivery_states' => (clone $deliveries)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
            'sms_estimate_irt' => $unknownSms ? null : (int) (clone $sms)->sum('estimated_cost'),
            'clicks' => DB::table('marketing_events')->where('campaign_id', $c->id)->where('kind', 'click')->count(),
            'checkout_starts' => DB::table('marketing_events')->where('campaign_id', $c->id)->where('kind', 'checkout')->count(),
            'held' => $c->held, 'spent' => $c->spent, 'available' => $c->budget - $c->held - $c->spent,
            // Supplier estimate is pretax, SMS is IRT only. Never silently mix currencies.
            'estimated_profit' => $unknownCosts || $unknownSms || $c->currency_code !== 'IRT' ? null
                : $paid->sum(fn ($r) => $r->snapshot['quote']['profit']) - (int) (clone $sms)->sum('estimated_cost'),
            'unknown_supplier_cost_orders' => $unknownCosts,
            'attribution' => 'One campaign per invoice. Authenticated landing opens are deduplicated per grant/account/UTC day. Attributed sales are not incremental sales.',
        ];
    }
}
