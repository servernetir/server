<?php

namespace App\Services\Birthday;

use App\Models\BirthdayReward;
use App\Models\Customer;
use App\Models\MarketingPreference;
use App\Models\Setting;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SupportsPatterns;
use Illuminate\Support\Facades\DB;

class BirthdayMessages
{
    public function configured(): bool
    {
        $reference = trim((string) Setting::get('birthday_pattern_reference', ''));
        $configured = trim((string) config('birthday_rewards.pattern_reference', ''));
        if (Setting::get('birthday_pattern_approved', '0') !== '1'
            || $reference === '' || $configured === '' || ! hash_equals($configured, $reference)) {
            return false;
        }
        $sender = app(SmsSender::class);

        return $sender->enabled() && $sender instanceof SupportsPatterns && $sender->hasPattern('birthday_surprise');
    }

    public function ready(): bool
    {
        return app(BirthdayProgram::class)->enabled()
            && Setting::get('birthday_sms_enabled', '0') === '1' && $this->configured();
    }

    public function dispatch(BirthdayReward $reward): string
    {
        if (! $this->ready()) {
            return 'pending_pattern';
        }
        $hour = now()->setTimezone(config('birthday_rewards.timezone'))->hour;
        if ($hour < config('birthday_rewards.sms_from_hour', 9) || $hour >= config('birthday_rewards.sms_until_hour', 21)) {
            return 'waiting_hours';
        }
        $payload = DB::transaction(function () use ($reward) {
            // Same customer-before-reward lock order as reward issuance.
            $customer = Customer::whereKey($reward->customer_id)->lockForUpdate()->firstOrFail();
            $r = BirthdayReward::whereKey($reward->id)->lockForUpdate()->firstOrFail();
            if (! $this->ready() || $r->sms_attempted_at || ! in_array($r->sms_status, ['pending_pattern', 'scheduled'], true)) {
                return null;
            }
            if ($r->revoked_at || ! $r->expires_at->greaterThan(now()->addMinutes(30))) {
                $r->update(['sms_status' => 'expired']);
                return null;
            }
            $preference = MarketingPreference::where('customer_id', $customer->id)->first();
            if (! $customer->isActive() || ! $customer->phone_verified_at
                || ! preg_match('/^(?:\+98|0098|0)9\d{9}$/D', (string) $customer->phone)
                || ! $preference || $preference->consent !== 'granted' || $preference->suppressed) {
                $r->update(['sms_status' => 'blocked_consent']);
                return null;
            }
            $r->update(['sms_status' => 'processing', 'sms_attempted_at' => now(),
                'sms_pattern_reference' => Setting::get('birthday_pattern_reference')]);

            return [$r->id, $customer->id];
        }, 5);
        if (! $payload) {
            return 'blocked';
        }
        [$id, $customerId] = $payload;
        try {
            // Recheck opt-out immediately before transport. No free-text fallback and no blind retry.
            $customer = Customer::findOrFail($customerId);
            $preference = MarketingPreference::where('customer_id', $customerId)->first();
            if (! $this->ready() || ! $customer->isActive() || ! $preference
                || $preference->consent !== 'granted' || $preference->suppressed) {
                BirthdayReward::whereKey($id)->update(['sms_status' => 'blocked_consent']);
                return 'blocked_consent';
            }
            $sender = app(SmsSender::class);
            $accepted = $sender->sendPattern($customer->phone, 'birthday_surprise',
                ['name' => app(BirthdayProgram::class)->firstName($customer)]);
            $status = $accepted === true ? ($sender->name() === 'queue' ? 'queued' : 'accepted') : 'unknown';
            BirthdayReward::whereKey($id)->update(['sms_status' => $status, 'sms_accepted_at' => $accepted === true ? now() : null]);

            return $status;
        } catch (\Throwable) {
            BirthdayReward::whereKey($id)->update(['sms_status' => 'unknown']);
            return 'unknown';
        }
    }
}
