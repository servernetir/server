<?php

namespace App\Services\Birthday;

use App\Models\BirthdayReward;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\Marketing\CampaignAudience;
use App\Support\Jalali;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BirthdayProgram
{
    public function enabled(): bool
    {
        return Setting::get('birthday_offer_enabled', config('birthday_rewards.enabled') ? '1' : '0') === '1'
            && Schema::hasTable('birthday_rewards') && Schema::hasTable('birthday_redemptions');
    }

    public function current(Customer $customer): ?BirthdayReward
    {
        if (! $this->enabled() || ! $customer->isActive()) {
            return null;
        }

        return BirthdayReward::where('customer_id', $customer->id)->whereNull('revoked_at')
            ->where('starts_at', '<=', now())->where('expires_at', '>', now())->latest('id')->first();
    }

    /** One 24-hour window, never extended by login, locale changes or repeated scheduler ticks. */
    public function ensure(Customer $customer): ?BirthdayReward
    {
        if (! $this->enabled() || ! $customer->isActive()) {
            return null;
        }
        if ($current = $this->current($customer)) {
            return $current;
        }
        $local = now()->setTimezone(config('birthday_rewards.timezone', 'Asia/Tehran'));
        if (! app(CampaignAudience::class)->birthday($customer, $local)) {
            return null;
        }

        return DB::transaction(function () use ($customer, $local) {
            $fresh = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if (! $fresh->isActive() || ! app(CampaignAudience::class)->birthday($fresh, $local)) {
                return null;
            }
            if ($current = $this->current($fresh)) {
                return $current;
            }
            // Keep the annual guard across calendar/profile changes and earlier birthday programs.
            if (BirthdayReward::where('customer_id', $fresh->id)->where('starts_at', '>', now()->subDays(330))->exists()
                || (Schema::hasTable('gift_coupons') && DB::table('gift_coupons')->where('customer_id', $fresh->id)
                    ->where('reason', 'birthday')->where('created_at', '>', now()->subDays(330))->exists())
                || (Schema::hasTable('marketing_runs') && DB::table('marketing_runs')->where('customer_id', $fresh->id)
                    ->where('trigger', 'birthday')->where('occurred_at', '>', now()->subDays(330))->exists())) {
                return null;
            }
            $calendar = $fresh->locale === 'fa' ? 'jalali' : 'gregorian';
            $year = $calendar === 'jalali' ? Jalali::ofMoment($local, $local->timezoneName)[0] : $local->year;
            if (BirthdayReward::where('customer_id', $fresh->id)->where('cycle_year', $year)->exists()) {
                return null;
            }
            $profile = $fresh->defaultProfile();
            if (! $profile?->birth_date) {
                return null;
            }

            return BirthdayReward::create([
                'customer_id' => $fresh->id, 'cycle_year' => $year, 'calendar' => $calendar,
                'profile_fingerprint' => hash('sha256', $profile->id.':'.$profile->birth_date->toDateString()),
                'starts_at' => now(), 'expires_at' => now()->addHours(24), 'sms_status' => 'pending_pattern',
            ]);
        }, 5);
    }

    public function firstName(Customer $customer): string
    {
        $name = $customer->identityVerification?->first_name ?: $customer->defaultProfile()?->first_name ?: __('birthday.friend');
        return mb_substr(trim(preg_replace('/[\r\n\t]+/u', ' ', (string) $name)), 0, 60);
    }

    public function message(Customer $customer): string
    {
        return str_replace('{{name}}', $this->firstName($customer), (string) config('birthday_rewards.message'));
    }
}
