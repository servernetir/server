<?php

namespace App\Services\Calendar\Providers;

use App\Models\Service;
use App\Services\Calendar\CalendarEventProvider;
use App\Services\Calendar\CalendarItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * سررسیدِ تمدیدِ سرویس‌ها (هاست، سرورِ اختصاصی، سرورِ ابریِ ماهانه) —
 * از ستونِ `services.next_due_at`.
 *
 * ⚠️ سرویسِ **ساعتی** این‌جا نیست. `billing_mode = hourly` سررسید ندارد؛
 * از کیفِ پول کسر می‌شود و `next_due_at` برایش بی‌معنی است. اگر واردِ تقویم
 * می‌شد، مدیر هر ماه یک «سررسید»ِ ساختگی می‌دید که هیچ فاکتوری پشتش نیست.
 */
class HostingRenewalProvider implements CalendarEventProvider
{
    use CapsLayerRows;

    public function getEvents(Carbon $from, Carbon $to): Collection
    {
        if (! Schema::hasTable('services')) {
            return collect();
        }

        return Service::query()
            /*
             * از همان تعریفی می‌پرسیم که خودِ مدل برای «سرویسِ مرده» دارد.
             * شرطِ دست‌نویسِ موازی یعنی افزودنِ یک وضعیتِ مرده در آینده، این
             * صفحه را بی‌صدا کهنه می‌کند و سرویسِ خاتمه‌یافته تا ابد سررسید
             * نشان می‌دهد.
             */
            ->whereNotIn('status', Service::DEAD_STATUSES)
            ->whereNotNull('next_due_at')
            ->when(
                Schema::hasColumn('services', 'billing_mode'),
                fn ($q) => $q->where(fn ($w) => $w
                    ->whereNull('billing_mode')
                    ->orWhere('billing_mode', '!=', 'hourly')),
            )
            ->whereBetween('next_due_at', [$from->toDateString(), $to->toDateString()])
            ->orderBy('next_due_at')
            ->limit($this->rowCap())
            ->get()
            ->map(fn (Service $service) => new CalendarItem(
                type: 'hosting_renewal',
                source: 'service',
                sourceId: $service->id,
                title: $service->name,
                description: $this->describe($service),
                /*
                 * `next_due_at` از نوعِ `date` است، پس Carbon نیمه‌شبِ UTC
                 * می‌دهد. صریح به نیمه‌شبِ تهران تبدیلش می‌کنیم تا در شبکهٔ
                 * شمسی سرِ روزِ درست بنشیند.
                 */
                at: Carbon::parse($service->next_due_at->toDateString(), $this->timezone()),
                /*
                 * وضعیتِ **رویدادِ تقویم** همیشه `pending` است، چون سررسید تا
                 * وقتی نرسیده کاری است که هنوز انجام نشده. وضعیتِ خودِ سرویس
                 * (`active`/`suspended`) در `meta.state` می‌رود و رابط کاربری
                 * نشانش می‌دهد — قاتیِ این دو یعنی سرویسِ معلق از تقویم غیب
                 * شود، دقیقاً وقتی بیشترین توجه را لازم دارد.
                 */
                status: 'pending',
                meta: [
                    'service_id'  => $service->id,
                    'customer_id' => $service->customer_id,
                    'cycle'       => $service->cycle,
                    'cycle_label' => Service::labelFor((string) $service->cycle),
                    'price'       => (int) $service->price,
                    'currency'    => $service->currency_code,
                    'state'       => $service->status,
                ],
                url: '/admin/services/'.$service->id.'/history',
                editable: false,
            ));
    }

    private function describe(Service $service): string
    {
        $parts = [];

        if ($label = Service::labelFor((string) $service->cycle)) {
            $parts[] = $label;
        }

        if ($service->price) {
            $parts[] = invoice_money((int) $service->price, (string) ($service->currency_code ?: 'IRT'));
        }

        if ($service->status === 'suspended') {
            $parts[] = 'در حالِ تعلیق';
        }

        return implode(' — ', $parts);
    }

    private function timezone(): string
    {
        return (string) config('calendar.display_timezone', 'Asia/Tehran');
    }
}
