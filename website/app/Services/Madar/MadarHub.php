<?php

namespace App\Services\Madar;

use App\Models\Customer;
use App\Models\MadarMission;
use App\Models\MadarParticipation;
use App\Models\MarketingCampaign;
use App\Services\Marketing\CampaignAudience;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MadarHub
{
    public function ready(): bool
    {
        foreach (['madar_campaigns', 'madar_participations', 'madar_messages', 'madar_artifacts'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    public function catalogReady(): bool
    {
        return $this->ready() && Schema::hasTable('madar_missions')
            && Schema::hasColumn('madar_campaigns', 'active_mission_id')
            && Schema::hasColumn('madar_participations', 'flow_version')
            && Schema::hasColumn('madar_participations', 'mission_id')
            && Schema::hasColumn('madar_participations', 'submission_hash')
            && Schema::hasColumn('madar_messages', 'distribution_url');
    }

    public function campaigns(?Customer $customer = null): array
    {
        $controls = $this->ready() ? DB::table('madar_campaigns')->get()->keyBy('campaign_key') : collect();
        $rewards = Schema::hasTable('marketing_campaigns')
            ? MarketingCampaign::whereIn('id', $controls->pluck('reward_campaign_id')->filter())->get()->keyBy('id') : collect();
        $missions = $this->catalogReady() ? MadarMission::whereIn('id', $controls->pluck('active_mission_id')->filter())->get()->keyBy('id') : collect();
        $maxRepeat = max(config('madar.repeat_days'), $missions->max(fn ($m) => $m->snapshot['repeat_days']) ?? 0);
        $claims = $customer && $this->catalogReady() ? MadarParticipation::where('customer_id', $customer->id)->where('flow_version', 2)
            ->whereNotIn('status', ['rejected', 'cancelled'])->where('terms_accepted_at', '>=', now()->subDays(max(7, $maxRepeat)))->get(['campaign_key', 'terms_accepted_at']) : collect();
        $week = now('Asia/Tehran')->startOfWeek(CarbonInterface::MONDAY)->utc();
        $out = [];
        foreach (config('madar.campaigns') as $key => $mission) {
            $control = $controls->get($key);
            $published = $missions->get($control?->active_mission_id);
            $program = $rewards->get($published?->reward_campaign_id ?? $control?->reward_campaign_id);
            $amount = $published?->reward_amount_irt ?? $mission['reward_irt'];
            $capacity = $published && $program ? app(MadarPublishing::class)->capacity($program, $amount) : 0;
            $eligible = ! $customer || ! $program || app(CampaignAudience::class)->matches($customer, $program->audience);
            $repeatSince = now()->subDays($published?->snapshot['repeat_days'] ?? config('madar.repeat_days'));
            $eligible = $eligible && ! $claims->contains(fn ($p) => $p->campaign_key === $key && $p->terms_accepted_at->gte($repeatSince))
                && $claims->filter(fn ($p) => $p->terms_accepted_at->gte($week))->count() < ($published?->snapshot['weekly_cap'] ?? config('madar.weekly_claim_cap'));
            $out[$key] = array_replace($mission, $published?->snapshot['mission'] ?? []) + ['key' => $key, 'accepting' => $control && $control->open,
                'open' => $control && $control->open && $published && $published->published_at->lte(now())
                    && $published->submissions_until->isFuture() && $capacity > 0 && $eligible,
                'published' => $published, 'capacity' => $capacity, 'amount_irt' => $amount,
                'copy' => $published?->snapshot['copy'][app()->getLocale()] ?? __('madar.campaigns.'.$key),
                'share_url' => $customer ? $this->shareUrl($key, $customer, $published?->version ?? 0) : null,
                'reward_campaign_id' => $control?->reward_campaign_id,
                'reward' => $program];
        }

        return $out;
    }

    public function shareUrl(string $key, Customer $customer, int $version): string
    {
        $locale = app()->getLocale();
        $path = ($locale === 'fa' ? '' : '/'.$locale).config('madar.campaigns.'.$key.'.target_path');

        return rtrim(config('madar.public_base_url'), '/').$path.'?'.http_build_query([
            'utm_source' => 'community', 'utm_medium' => $key === 'referral' ? 'referral' : 'creator',
            'utm_campaign' => 'madar_'.str_replace('-', '_', $key).'_v'.$version,
            'utm_content' => substr(hash_hmac('sha256', $key.':'.$customer->id, config('app.key')), 0, 20),
        ]);
    }

    public function apply(Customer $customer, array $data): MadarParticipation
    {
        abort_unless($this->ready(), 503);

        return DB::transaction(function () use ($customer, $data) {
            $fresh = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $control = DB::table('madar_campaigns')->where('campaign_key', $data['campaign'])->lockForUpdate()->first();
            $this->check($fresh->isActive() && $control && $control->open, 'closed');
            $this->check(isset(config('madar.campaigns')[$data['campaign']]), 'closed');
            // Idempotent retry of the same application; a different project is explicit.
            $existing = MadarParticipation::where('public_id', $data['request_key'])->first();
            if ($existing) {
                $this->check((int) $existing->customer_id === $fresh->id && $existing->campaign_key === $data['campaign']
                    && $existing->title === $data['title']
                    && $existing->messages()->where('sender_type', 'customer')->oldest('id')->value('body') === $data['body'], 'retry_mismatch');

                return $existing;
            }
            $this->check(MadarParticipation::where('customer_id', $fresh->id)->whereIn('status', MadarParticipation::OPEN)->count()
                < config('madar.max_open_per_customer'), 'open_limit');
            $p = MadarParticipation::create(['public_id' => $data['request_key'], 'customer_id' => $fresh->id,
                'campaign_key' => $data['campaign'], 'title' => $data['title'], 'locale' => app()->getLocale(),
                'status' => 'requested', 'terms_version' => config('madar.terms_version'), 'terms_accepted_at' => now(),
                'customer_read_at' => now()]);
            $p->messages()->create(['sender_type' => 'customer', 'sender_id' => $fresh->id, 'body' => $data['body']]);

            return $p;
        }, 3);
    }

    public function check(bool $ok, string $key): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['madar' => __('madar.errors.'.$key)]);
        }
    }

    public function newKey(): string
    {
        return (string) Str::uuid();
    }
}
