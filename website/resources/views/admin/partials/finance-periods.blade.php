@php
  $today=now('Asia/Tehran');
  [$fiscalYear]=\App\Support\Jalali::fromGregorian((int)$today->year,(int)$today->month,(int)$today->day);
@endphp
<div style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0">
@foreach([$fiscalYear,$fiscalYear-1] as $year)
  <span>{{ $year }}:</span>@foreach([0,1,2,3,4] as $qtr)<a href="{{ url()->current() }}?{{ http_build_query(['fiscal_year'=>$year,'quarter'=>$qtr]) }}">{{ __('finance.quarter_'.$qtr) }}</a>@endforeach
@endforeach
</div>
