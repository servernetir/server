<?php

namespace App\Services\Marketing;

use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Models\MarketingDelivery;
use App\Models\MarketingGrant;
use App\Models\MarketingPreference;
use App\Models\MarketingRedemption;
use App\Models\Setting;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\DB;

class CampaignMessages
{
    public function label(array $rule, string $locale = 'fa'): string
    {
        $amount = fn ($n) => number_format($n).' '.$rule['currency'].($rule['currency'] === 'IRT' ? ' (تومان)' : '');
        $label = $rule['type'] === 'percent' ? ($rule['value'] / 100).'% / '.$amount($rule['max_discount']) : $amount($rule['value']);

        return $label.' · '.__('marketing.minimum', [], $locale).': '.$amount($rule['min_purchase']);
    }

    public function text(MarketingGrant $g, Customer $customer): string
    {
        $locale = $customer->locale ?: 'fa';
        $name = $customer->defaultProfile()?->first_name ?: __('marketing.friend', [], $locale);
        $rule = $g->snapshot['rule'];
        $vars = ['first_name' => mb_substr($name, 0, 60), 'coupon_code' => $g->code,
            'discount_label' => $this->label($rule, $locale), 'service_name' => $g->snapshot['message']['service_name'],
            'expires_at_local' => $g->expires_at->setTimezone($g->snapshot['timezone'])->format('Y-m-d H:i').' '.$g->snapshot['timezone'],
            'redeem_url' => 'https://console.servernet.cloud/m/'.$g->track_token,
            'unsubscribe_instructions' => __('marketing.optout_sms', [], $locale).' https://console.servernet.cloud'.($locale === 'fa' ? '' : '/'.$locale).'/account/marketing/unsubscribe'];

        return preg_replace_callback('/{{\s*([^{}]+)\s*}}/u', fn ($m) => $vars[trim($m[1])] ?? '', $g->snapshot['message']['template']);
    }

    public function estimate(string $body): array
    {
        // GSM-7 extension characters cost two septets; Unicode uses UTF-16 units.
        $gsm = '@£$¥èéùìòÇ'."\n".'Øø'."\r".'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
        $extension = '^{}\\[~]|€';
        $units = 0;
        $unicode = false;
        foreach (mb_str_split($body) as $char) {
            if (str_contains($gsm, $char)) {
                $units++;
            } elseif (str_contains($extension, $char)) {
                $units += 2;
            } else {
                $unicode = true;
                break;
            }
        }
        if ($unicode) {
            $units = intdiv(strlen(mb_convert_encoding($body, 'UTF-16BE', 'UTF-8')), 2);
        }
        $segments = $units <= ($unicode ? 70 : 160) ? 1 : (int) ceil($units / ($unicode ? 67 : 153));
        $price = Setting::get('marketing_sms_segment_cost_irt');
        $price = $price === null || $price === '' ? config('marketing.sms_segment_cost_irt') : $price;

        return ['segments' => $segments, 'cost' => $price === null ? null : $segments * (int) $price];
    }

    public function queue(MarketingGrant $g, Customer $customer): void
    {
        if (! ($g->snapshot['message']['enabled'] ?? false)) {
            return;
        }
        $text = $this->text($g, $customer);
        $estimate = $this->estimate($text);
        $policy = $this->consentReason($customer);
        foreach (['offer' => now(), 'reminder' => $g->expires_at->copy()->subHours($g->snapshot['automation']['reminder_hours'] ?? 0)] as $kind => $at) {
            if ($kind === 'reminder' && empty($g->snapshot['automation']['reminder_hours'])) {
                continue;
            }
            MarketingDelivery::firstOrCreate(['delivery_key' => hash('sha256', $g->id.':'.$kind)], [
                'campaign_id' => $g->campaign_id, 'grant_id' => $g->id, 'customer_id' => $customer->id,
                'kind' => $kind, 'status' => $policy ? 'blocked' : 'scheduled', 'reason' => $policy,
                'body' => $text, 'segments' => $estimate['segments'], 'estimated_cost' => $estimate['cost'],
                'scheduled_at' => $at,
            ]);
        }
    }

    private function consentReason(Customer $c): ?string
    {
        $pref = MarketingPreference::where('customer_id', $c->id)->first();
        if (! $c->isActive() || ! $c->phone_verified_at || ! preg_match('/^(?:\+98|0098|0)9\d{9}$/D', (string) $c->phone)) {
            return 'account_or_verified_phone';
        }
        if (! $pref || $pref->consent !== 'granted' || $pref->suppressed) {
            return 'marketing_consent';
        }

        return null;
    }

    public function dispatch(MarketingDelivery $delivery): void
    {
        // Commit processing BEFORE network I/O. Crashes/timeouts become unknown, never blind retries.
        $ready = DB::transaction(function () use ($delivery) {
            // Singleton DB row serializes global and customer quotas, including multiple campaigns.
            DB::table('marketing_dispatch_locks')->where('id', 1)->lockForUpdate()->first();
            $d = MarketingDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($d->status !== 'scheduled' || $d->scheduled_at->isFuture()) {
                return null;
            }
            $c = MarketingCampaign::findOrFail($d->campaign_id);
            $g = MarketingGrant::findOrFail($d->grant_id);
            $customer = Customer::findOrFail($d->customer_id);
            $used = MarketingRedemption::where('grant_id', $g->id)->whereIn('status', ['reserved', 'committed', 'refunded', 'reconciliation'])->exists();
            $reason = $this->consentReason($customer);
            if ($g->revoked_at || ! $g->expires_at->isFuture() || $used) {
                $reason = 'offer_unavailable';
            }
            if ($reason) {
                $d->update(['status' => 'blocked', 'reason' => $reason]);

                return null;
            }
            $enabled = filter_var(DB::table('settings')->where('key', 'marketing_sms_enabled')->value('value') ?? config('marketing.sms_enabled'), FILTER_VALIDATE_BOOLEAN);
            $reviewed = filter_var(DB::table('settings')->where('key', 'marketing_sms_country_reviewed')->value('value') ?? config('marketing.sms_country_reviewed'), FILTER_VALIDATE_BOOLEAN);
            $sender = app(SmsSender::class);
            if (! $enabled || ! $reviewed || $c->messages_paused || ! $sender->enabled() ||
                ! in_array($sender->name(), config('marketing.sms_drivers'), true)) {
                return null; // parked until configuration/approval; expires safely
            }
            $tz = $customer->timezone ?: $g->snapshot['timezone'];
            try {
                $hour = now()->setTimezone($tz)->hour;
            } catch (\Throwable) {
                $d->update(['status' => 'blocked', 'reason' => 'invalid_timezone']);

                return null;
            }
            [$from, $to] = config('marketing.sms_hours');
            if ($hour < $from || $hour >= $to) {
                return null;
            }
            $estimate = $this->estimate($d->body);
            if ($estimate['cost'] === null || $estimate['cost'] <= 0) {
                $d->update(['status' => 'blocked', 'reason' => 'unknown_tariff']);

                return null;
            }
            $attempts = MarketingDelivery::whereNotNull('attempted_at');
            $dayStart = now()->setTimezone('Asia/Tehran')->startOfDay()->utc();
            $daily = (clone $attempts)->where('attempted_at', '>=', $dayStart);
            $campaignAttempts = (clone $attempts)->where('campaign_id', $c->id);
            $customerAttempts = (clone $attempts)->where('customer_id', $customer->id);
            if ((clone $daily)->count() >= (int) (DB::table('settings')->where('key', 'marketing_sms_daily_limit')->value('value') ?? config('marketing.sms_daily_limit')) ||
                (clone $daily)->sum('estimated_cost') + $estimate['cost'] > (int) (DB::table('settings')->where('key', 'marketing_sms_daily_budget')->value('value') ?? config('marketing.sms_daily_budget')) ||
                (clone $campaignAttempts)->count() >= $c->message['limit'] ||
                (clone $campaignAttempts)->sum('estimated_cost') + $estimate['cost'] > $c->message['budget'] ||
                (clone $customerAttempts)->where('attempted_at', '>=', now()->subDays(7))->count() >= config('marketing.sms_per_customer_7d') ||
                (clone $customerAttempts)->where('attempted_at', '>', now()->subHours(config('marketing.sms_gap_hours')))->exists()) {
                return null;
            }
            $d->update(['status' => 'processing', 'attempted_at' => now(), 'estimated_cost' => $estimate['cost'], 'reason' => null]);

            return [$d->id, $customer->phone, $d->body];
        }, 5);
        if (! $ready) {
            return;
        }
        [$id, $phone, $body] = $ready;
        $latest = MarketingDelivery::findOrFail($id);
        if ($this->consentReason(Customer::findOrFail($latest->customer_id)) ||
            ! filter_var(DB::table('settings')->where('key', 'marketing_sms_enabled')->value('value') ?? config('marketing.sms_enabled'), FILTER_VALIDATE_BOOLEAN) ||
            MarketingCampaign::findOrFail($latest->campaign_id)->messages_paused) {
            $latest->update(['status' => 'blocked', 'reason' => 'policy_changed', 'attempted_at' => null]);

            return;
        }
        try {
            $accepted = app(SmsSender::class)->send($phone, $body);
            MarketingDelivery::whereKey($id)->where('status', 'processing')->update([
                'status' => $accepted ? 'accepted' : 'unknown', 'accepted_at' => $accepted ? now() : null,
                'reason' => $accepted ? null : 'provider_result_unconfirmed', 'updated_at' => now()]);
        } catch (\Throwable) {
            MarketingDelivery::whereKey($id)->where('status', 'processing')
                ->update(['status' => 'unknown', 'reason' => 'transport_result_unconfirmed', 'updated_at' => now()]);
        }
    }
}
