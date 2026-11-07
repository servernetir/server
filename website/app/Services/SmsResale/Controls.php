<?php

namespace App\Services\SmsResale;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class Controls
{
    public function ready(): bool { return Schema::hasTable('sms_subscriptions'); }
    public function get(string $key, ?string $fallback = null): ?string { return Setting::get('sms_resale_'.$key, $fallback); }
    public function token(): ?string { return Setting::getSecret('sms_resale_api_key'); }

    public function runtime(): bool
    {
        return $this->ready() && $this->get('automation_enabled') === '1'
            && filled($this->token()) && (int) $this->get('parent_id', '0') > 0;
    }

    public function panelUrl(): string
    {
        // Fixed company-controlled destination; never accept an upstream redirect or customer URL.
        return (string) config('sms_resale.panel_url');
    }

    public function plan(string $key): array
    {
        $base = config('sms_resale.plans.'.$key);
        abort_unless(is_array($base), 404);
        return $base + ['key' => $key, 'acl_id' => (int) $this->get($key.'_acl', '0')];
    }

    public function sellable(string $key): bool
    {
        $p = $this->plan($key);
        if (! $this->runtime() || $this->get('sales_enabled') !== '1' || $p['acl_id'] <= 0
            || blank($this->get('commercial_evidence')) || blank($this->get('native_billing_evidence'))
            || blank($this->get('automation_evidence'))) { return false; }
        try {
            $checked = Carbon::parse($this->get('verified_at', '1970-01-01'));
            $until = Carbon::parse($this->get('valid_until', '1970-01-01'));
            if ($checked->isFuture() || $until->isPast() || $until->gt($checked->copy()->addDays(7))) { return false; }
        } catch (\Throwable) { return false; }
        $cost = (int) $this->get('cost_irr', '0');
        return $cost > 0 && $p['rate_irr'] >= self::floor($cost, $p['margin_bps'])
            && $this->get($key.'_tariff_verified') === '1';
    }

    public static function floor(int $costIrr, int $marginBps): int
    {
        if ($costIrr <= 0 || $costIrr > 1000000000 || $marginBps < 0 || $marginBps >= 10000) {
            throw new \InvalidArgumentException('Invalid SMS price inputs.');
        }
        $denominator = 10000 - $marginBps;
        return intdiv($costIrr * 10000 + $denominator - 1, $denominator);
    }

    public function publicPlans(): array
    {
        return array_map(function (string $key) {
            $p = $this->plan($key);
            unset($p['acl_id']);
            return $p + ['available' => $this->sellable($key)];
        }, array_keys(config('sms_resale.plans')));
    }
}
