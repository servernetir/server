<?php

namespace App\Services\Marketing;

use App\Models\Customer;
use App\Models\MarketingCampaign;
use App\Models\MarketingPreference;
use App\Models\Service;
use App\Support\Jalali;
use Illuminate\Support\Carbon;

class CampaignAudience
{
    public function matches(Customer $customer, array $audience): bool
    {
        if (! $customer->isActive()) {
            return false;
        }
        if (! empty($audience['customer_ids']) && ! in_array($customer->id, $audience['customer_ids'], true)) {
            return false;
        }
        if (! empty($audience['locales']) && ! in_array($customer->locale, $audience['locales'], true)) {
            return false;
        }
        if (! empty($audience['tags'])) {
            $tags = MarketingPreference::where('customer_id', $customer->id)->value('tags');
            $tags = is_array($tags) ? $tags : json_decode((string) $tags, true);
            if (array_diff($audience['tags'], $tags ?: [])) {
                return false;
            }
        }
        if (array_key_exists('active_service', $audience) && $audience['active_service'] !== null) {
            if ($customer->services()->whereIn('status', Service::ACTIVE_STATUSES)->exists()
                !== (bool) $audience['active_service']) {
                return false;
            }
        }
        $paid = $customer->invoices()->where('status', 'paid')->where('kind', '!=', 'topup');
        if (isset($audience['min_purchases']) && (clone $paid)->count() < $audience['min_purchases']) {
            return false;
        }
        if (isset($audience['max_purchases']) && (clone $paid)->count() > $audience['max_purchases']) {
            return false;
        }
        if (! empty($audience['service_plans']) &&
            ! $customer->services()->whereIn('plan', $audience['service_plans'])->exists()) {
            return false;
        }
        if (isset($audience['inactive_days'])) {
            $last = (clone $paid)->max('paid_at');
            if ((! $last && $customer->created_at->greaterThan(now()->subDays($audience['inactive_days']))) ||
                ($last && Carbon::parse($last)->greaterThan(now()->subDays($audience['inactive_days'])))) {
                return false;
            }
        }

        return true;
    }

    public function birthday(Customer $customer, Carbon $local): bool
    {
        $p = MarketingPreference::where('customer_id', $customer->id)->first();
        $calendar = $p?->birth_calendar;
        $month = $p?->birth_month;
        $day = $p?->birth_day;
        if (! $calendar || ! $month || ! $day) {
            // Existing identity data is already Gregorian; Persian customers use its Jalali anniversary.
            $date = $customer->defaultProfile()?->birth_date;
            if (! $date) {
                return false;
            }
            $calendar = $customer->locale === 'fa' ? 'jalali' : 'gregorian';
            if ($calendar === 'jalali') {
                [, $month, $day] = Jalali::fromGregorian((int) $date->format('Y'), (int) $date->format('m'), (int) $date->format('d'));
            } else {
                [$month, $day] = [(int) $date->format('m'), (int) $date->format('d')];
            }
        }
        if ($calendar === 'jalali') {
            [$y, $m, $d] = Jalali::ofMoment($local, $local->timezoneName);

            return $month === $m && ($day === $d ||
                ($month === 12 && $day === 30 && $d === 29 && ! Jalali::isLeap($y)));
        }

        // Gregorian leap-day policy: February 28 in non-leap years.
        return $month === $local->month && ($day === $local->day ||
            ($month === 2 && $day === 29 && $local->day === 28 && ! $local->isLeapYear()));
    }

    public function due(MarketingCampaign $campaign, Customer $customer): bool
    {
        $local = now()->setTimezone($campaign->timezone);
        if ($local->format('H:i') < ($campaign->automation['at'] ?? '09:00')) {
            return false;
        }

        return match ($campaign->trigger) {
            'birthday' => $this->birthday($customer, $local),
            'signup' => $customer->created_at->greaterThan(now()->subHours(24)),
            'first_purchase' => $customer->invoices()->where('status', 'paid')->where('kind', '!=', 'topup')->count() === 1
                && $customer->invoices()->where('status', 'paid')->where('kind', '!=', 'topup')->where('paid_at', '>=', now()->subHours(24))->exists(),
            'inactive' => $this->matches($customer, ['inactive_days' => $campaign->automation['inactive_days'] ?? 30]),
            'scheduled' => true,
            // Missing reliable cart/usage/renewal event data is never guessed.
            default => false,
        };
    }
}
