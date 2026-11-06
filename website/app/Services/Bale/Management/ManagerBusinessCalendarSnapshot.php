<?php

namespace App\Services\Bale\Management;

use App\Services\Calendar\CalendarItem;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\Providers\ContentPostProvider;
use App\Services\Calendar\Providers\DomainRenewalProvider;
use App\Services\Calendar\Providers\HostingRenewalProvider;
use App\Services\Calendar\Providers\ManualEventProvider;
use App\Services\Calendar\Providers\PaymentDueProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/** Organization calendar only; personal Google grants and auth state are never read. */
class ManagerBusinessCalendarSnapshot
{
    private const SOURCE_TABLES = [
        DomainRenewalProvider::class => 'domains',
        HostingRenewalProvider::class => 'services',
        PaymentDueProvider::class => 'invoices',
        ContentPostProvider::class => 'posts',
        ManualEventProvider::class => 'calendar_events',
    ];

    public function __construct(private readonly CalendarService $calendar) {}

    public function snapshot(): array
    {
        $metadata = (array) config('calendar.layers', []);
        // Page chip defaults are a display preference, not the scope of the business report.
        $layers = array_values(array_filter(array_keys($metadata), fn ($key) => is_string($key) && $key !== 'google'));
        $labels = static fn (array $keys) => array_values(array_map(
            static fn ($key) => (string) ($metadata[$key]['label'] ?? $key), $keys));
        $from = Carbon::now((string) config('calendar.display_timezone', 'Asia/Tehran'))->startOfDay();
        $twoDayEnd = $from->copy()->addDay()->endOfDay();
        $end = $from->copy()->addDays(6)->endOfDay();
        $providers = $this->calendar->providers();
        $missing = [];
        foreach ($layers as $layer) {
            if (! isset($providers[$layer])) { $missing[] = $layer; continue; }
            $table = self::SOURCE_TABLES[get_class($providers[$layer])] ?? null;
            if ($table !== null && ! Schema::hasTable($table)) { $missing[] = $layer; }
        }

        $items = $this->calendar->events($from, $end, $layers)
            ->reject(fn (CalendarItem $item) => in_array($item->status, ['done', 'cancelled'], true))
            ->filter(fn (CalendarItem $item) => $item->at->betweenIncluded($from, $end))
            ->values();
        $failed = array_keys($this->calendar->failures());
        $truncated = $this->calendar->truncatedLayers();
        $partial = $missing !== [] || $failed !== [] || $truncated !== [];
        $near = $items->filter(fn (CalendarItem $item) => $item->at->lte($twoDayEnd));
        $content = $items->where('type', 'social_post')->count();
        $actions = $items->reject(fn (CalendarItem $item) => $item->type === 'social_post')
            ->sort(fn (CalendarItem $a, CalendarItem $b) => [
                $a->at->getTimestamp(), $this->priority($a->type), $a->title,
            ] <=> [
                $b->at->getTimestamp(), $this->priority($b->type), $b->title,
            ])->values();
        $typeCounts = array_fill_keys($layers, 0);
        foreach ($items->countBy('type') as $type => $count) { $typeCounts[$type] = $count; }

        return [
            'available' => $layers !== [],
            'partial' => $partial,
            // Incomplete coverage must not become a reassuring zero or an exact total.
            'count' => $partial ? null : $near->count(),
            'observed_count' => $near->count(),
            'count_7d' => $partial ? null : $items->count(),
            'observed_count_7d' => $items->count(),
            'days' => 2, 'lookahead_days' => 7,
            'from_at' => $from->toIso8601String(), 'to_at' => $twoDayEnd->toIso8601String(),
            'lookahead_from_at' => $from->toIso8601String(), 'lookahead_to_at' => $end->toIso8601String(),
            'type_counts' => $typeCounts,
            'type_labels' => array_combine($layers, $labels($layers)) ?: [],
            'coverage_labels' => $labels($layers),
            'failed_layers' => $labels($failed), 'truncated_layers' => $labels($truncated),
            'missing_layers' => $labels($missing),
            'content_count' => $content,
            'omitted_count' => max(0, $actions->count() - 10),
            'items' => $actions->take(10)->map(static fn (CalendarItem $item) => [
                'title' => $item->title, 'at' => $item->at->toIso8601String(),
                'money' => $item->money(), 'type' => $item->type,
                'type_label' => (string) ($metadata[$item->type]['label'] ?? $item->type),
                'source' => $item->source, 'url' => $item->url, 'status' => $item->status,
            ])->all(),
            'basis' => 'all configured organization layers; today/tomorrow and next seven calendar days; personal Google excluded',
        ];
    }

    private function priority(string $type): int
    {
        return match ($type) { 'payment_due' => 0, 'hosting_renewal' => 1, 'domain_renewal' => 2, 'task' => 3, default => 4 };
    }
}
