<?php

namespace App\Services\Madar;

use App\Models\Customer;
use App\Models\MadarParticipation;
use App\Models\MarketingCampaign;
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

    public function campaigns(): array
    {
        $controls = $this->ready() ? DB::table('madar_campaigns')->get()->keyBy('campaign_key') : collect();
        $rewards = Schema::hasTable('marketing_campaigns')
            ? MarketingCampaign::whereIn('id', $controls->pluck('reward_campaign_id')->filter())->get()->keyBy('id') : collect();
        $out = [];
        foreach (config('madar.campaigns') as $key => $mission) {
            $control = $controls->get($key);
            $out[$key] = $mission + ['key' => $key, 'open' => $control && $control->open,
                'reward_campaign_id' => $control?->reward_campaign_id,
                'reward' => $rewards->get($control?->reward_campaign_id)];
        }

        return $out;
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
