<?php

namespace App\Services\Growth;

use App\Models\GrowthContent;
use App\Support\Jalali;
use Illuminate\Support\Carbon;

final class GrowthCalendar
{
    public function month(?string $iso = null, string $timezone = 'Asia/Tehran'): array
    {
        $anchor = $iso ? Carbon::createFromFormat('!Y-m-d', $iso, $timezone) : now($timezone)->startOfDay();
        $isFa = app()->getLocale() === 'fa';
        if ($isFa) {
            [$jy,$jm] = Jalali::ofMoment($anchor, $timezone);
            $start = Jalali::startOfDay($jy, $jm, 1, $timezone);
            [$ny,$nm] = Jalali::addMonths($jy, $jm, 1, 1);
            $next = Jalali::startOfDay($ny, $nm, 1, $timezone);
            [$py,$pm] = Jalali::addMonths($jy, $jm, 1, -1);
            $prev = Jalali::startOfDay($py, $pm, 1, $timezone);
            $label = Jalali::monthName($jm).' '.$jy;
            $offset = Jalali::weekdayIndex($start);
            $weekdays = Jalali::WEEKDAY_NAMES;
        } else {
            $start = $anchor->copy()->startOfMonth();
            $next = $start->copy()->addMonth();
            $prev = $start->copy()->subMonth();
            $label = $start->copy()->locale(app()->getLocale())->translatedFormat('F Y');
            $offset = $start->isoWeekday() - 1;
            $weekdays = app()->getLocale() === 'tr' ? ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'] : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        }
        $items = GrowthContent::whereNotNull('scheduled_at')->where('scheduled_at', '>=', $start->copy()->utc())
            ->where('scheduled_at', '<', $next->copy()->utc())->orderBy('scheduled_at')->get();
        $grouped = $items->groupBy(fn ($item) => $item->scheduled_at->copy()->setTimezone($timezone)->toDateString());
        $days = [];
        for ($date = $start->copy(); $date->lt($next); $date->addDay()) {
            $key = $date->toDateString();
            $days[] = ['date' => $key, 'number' => $isFa ? Jalali::ofMoment($date, $timezone)[2] : $date->day,
                'today' => $date->isToday(), 'items' => $grouped->get($key, collect())];
        }

        return ['label' => $label, 'days' => $days, 'weekdays' => $weekdays, 'offset' => $offset, 'timezone' => $timezone,
            'previous' => $prev->toDateString(), 'next' => $next->toDateString(), 'anchor' => $anchor->toDateString()];
    }
}
