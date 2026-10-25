<?php

namespace App\Services\Finance;

use App\Support\FinanceDates;
use App\Support\Jalali;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class FinanceFilter
{
    public function __construct(public array $values, public ?Carbon $from, public ?Carbon $to) {}

    public static function fromRequest(Request $request): self
    {
        FinanceDates::normalize($request, ['from', 'to']);
        $v = $request->validate([
            'fiscal_year' => ['nullable', 'integer', 'min:1300', 'max:1600'],
            'quarter' => ['nullable', 'integer', 'min:0', 'max:4'],
            'range' => ['nullable', 'in:all,month,year,custom'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:150'],
            'kind' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', 'string', 'max:32'],
            'currency' => ['nullable', 'in:IRT,IRR,USD,EUR,TRY,USDT,GBP'],
            'origin' => ['nullable', 'in:all,auto,manual'],
        ]);
        $range = $v['range'] ?? 'all';
        $now = now('Asia/Tehran');
        [$jy, $jm] = Jalali::ofMoment($now, 'Asia/Tehran');
        [$from, $to] = match ($range) {
            'month' => [Jalali::startOfDay($jy, $jm, 1, 'Asia/Tehran'), Jalali::startOfDay($jy, $jm, Jalali::daysInMonth($jy, $jm), 'Asia/Tehran')->endOfDay()],
            'year' => FiscalPeriods::window($jy),
            default => [null, null],
        };
        if (! empty($v['from']) || ! empty($v['to'])) {
            $range = 'custom';
            $from = empty($v['from']) ? null : Carbon::parse($v['from'], 'Asia/Tehran');
            $to = empty($v['to']) ? null : Carbon::parse($v['to'], 'Asia/Tehran');
        }
        if (! empty($v['fiscal_year']) && empty($v['from']) && empty($v['to'])) {
            [$from,$to] = FiscalPeriods::window((int) $v['fiscal_year'], (int) ($v['quarter'] ?? 0));
            $range = 'custom';
            $v['from'] = $from->toDateString();
            $v['to'] = $to->toDateString();
        }
        $v['range'] = $range;

        return new self($v, $from, $to);
    }

    public function apply(Builder $q): Builder
    {
        if ($this->from) {
            $q->whereDate('occurred_at', '>=', $this->from->toDateString());
        }
        if ($this->to) {
            $q->whereDate('occurred_at', '<=', $this->to->toDateString());
        }
        foreach (['kind' => 'kind', 'category' => 'category', 'currency' => 'currency_code'] as $key => $column) {
            if (! empty($this->values[$key])) {
                $q->where($column, $this->values[$key]);
            }
        }
        if (($this->values['origin'] ?? '') === 'auto') {
            $q->whereNull('created_by');
        }
        if (($this->values['origin'] ?? '') === 'manual') {
            $q->whereNotNull('created_by');
        }
        $term = trim($this->values['q'] ?? '');
        if ($term !== '') {
            $q->where(function ($w) use ($term) {
                $w->where('note', 'like', '%'.$term.'%')->orWhere('category', 'like', '%'.$term.'%')
                    ->orWhere('source_type', 'like', '%'.$term.'%');
                if (ctype_digit($term)) {
                    $w->orWhere('id', (int) $term)->orWhere('source_id', (int) $term);
                }
            });
        }

        return $q;
    }
}
