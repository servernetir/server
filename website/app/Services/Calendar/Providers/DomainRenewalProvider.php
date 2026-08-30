<?php

namespace App\Services\Calendar\Providers;

use App\Models\Domain;
use App\Services\Calendar\CalendarEventProvider;
use App\Services\Calendar\CalendarItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * تاریخِ انقضای دامنه‌ها — از جدولِ `domains`.
 *
 * ⚠️ فقط دامنهٔ **زنده**: `scopeAlive` همان اسکوپی است که خودِ مدل برای
 * «دامنه‌ای که هنوز مالِ ماست» تعریف کرده (`cancelled` و `transferred_away` و
 * `expired` بیرون‌اند). شرطِ دست‌نویسِ موازی یعنی روزی که وضعیتِ تازه‌ای اضافه
 * شود، تقویم بی‌صدا کهنه می‌شود — همان درسِ `SystemHealth` که چکِ صف را مجبور
 * کرد از همان اسکوپی بپرسد که کرون برمی‌دارد.
 */
class DomainRenewalProvider implements CalendarEventProvider
{
    use CapsLayerRows;

    public function getEvents(Carbon $from, Carbon $to): Collection
    {
        if (! Schema::hasTable('domains')) {
            return collect();
        }

        return Domain::query()
            ->alive()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$from, $to])
            ->orderBy('expires_at')
            ->limit($this->rowCap())
            ->get()
            ->map(fn (Domain $domain) => new CalendarItem(
                type: 'domain_renewal',
                source: 'domain',
                sourceId: $domain->id,
                title: $domain->domain,
                description: $this->describe($domain),
                at: $domain->expires_at,
                status: 'pending',
                meta: [
                    'domain_id'   => $domain->id,
                    'customer_id' => $domain->customer_id,
                    'auto_renew'  => (bool) $domain->auto_renew,
                    // مبلغِ **فروش** است نه بهایِ تمام‌شده — `cost_amount` در
                    // `$hidden` مدل است و نباید از هیچ راهی به JSON برسد.
                    'renew_toman' => (int) ($domain->renew_toman ?? 0),
                ],
                /*
                 * پروندهٔ مشتری، نه فهرستِ دامنه‌ها: از تقویم که روی یک ردیف
                 * کلیک می‌شود، کارِ بعدی «تماس با این مشتری» است نه «دیدنِ همهٔ
                 * دامنه‌های در حالِ انقضا». اگر دامنه مشتری ندارد (ردیفِ یتیم)
                 * به فیلترِ واقعیِ `f=expiring` می‌رود — نه یک لینکِ شکسته.
                 */
                url: $domain->customer_id
                    ? '/admin/customers/'.$domain->customer_id
                    : '/admin/domains?f=expiring',
                editable: false,
            ));
    }

    private function describe(Domain $domain): string
    {
        $parts = [$domain->auto_renew ? 'تمدید خودکار روشن' : 'تمدید خودکار خاموش'];

        if ($domain->renew_toman) {
            $parts[] = 'هزینهٔ تمدید: '.cloud_price((int) $domain->renew_toman);
        }

        return implode(' — ', $parts);
    }
}
