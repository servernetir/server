@extends('admin.layout')
@section('title', __('email-marketing.title'))
@section('nav_email_marketing', 'on')
@section('content')
<h2>{{ __('email-marketing.title') }}</h2>
<p>{{ __('email-marketing.admin_intro') }}</p>
@if(config('email-marketing.evidence_review_enabled'))
  <p><a class="btn btn-glass" href="{{ route('admin.email-marketing.evidence.index') }}">{{ __('email-evidence-review.title') }}</a></p>
@endif

@if(session('ok'))<p role="status">{{ session('ok') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:18px 0">
  @foreach(['all','pending','granted','blocked'] as $metric)
    <section class="ad-panel" style="padding:16px;min-width:0">
      <div>{{ __('email-marketing.metric_'.$metric) }}</div>
      <strong style="font-size:26px;display:block;margin-top:8px">{{ number_format($counts[$metric]) }}</strong>
    </section>
  @endforeach
</div>
<p style="margin-bottom:18px">{{ __('email-marketing.metrics_note') }}</p>

<section class="ad-panel" style="padding:20px;margin-bottom:20px">
  <h3>{{ __('email-marketing.stage_title') }}</h3>
  <p>{{ __('email-marketing.stage_help') }}</p>
  <p><a class="btn btn-glass" href="{{ route('admin.email-marketing.sample') }}">{{ __('email-marketing.sample') }}</a></p>
  <form method="post" action="{{ route('admin.email-marketing.stage') }}" enctype="multipart/form-data">
    @csrf
    <label style="display:block;margin-bottom:12px">{{ __('email-marketing.file') }}<input class="ad-input" type="file" name="file" accept=".csv,text/csv" required></label>
    <label style="display:flex;gap:8px;align-items:start;margin:12px 0"><input type="checkbox" name="acknowledge" value="1" required><span>{{ __('email-marketing.acknowledge') }}</span></label>
    <button class="btn btn-primary" type="submit">{{ __('email-marketing.stage_button') }}</button>
  </form>
</section>

@if(is_array(session('stage_result')))
  <section class="ad-panel" style="padding:18px;margin-bottom:20px" role="status">
    <h3>{{ __('email-marketing.stage_result') }}</h3>
    <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
      @foreach(session('stage_result') as $key => $value)
        <div><dt>{{ __('email-marketing.count_'.$key) }}</dt><dd style="font-weight:700;margin:4px 0">{{ number_format($value) }}</dd></div>
      @endforeach
    </dl>
    <p>{{ __('email-marketing.no_message') }}</p>
  </section>
@endif

<form method="get" action="{{ route('admin.email-marketing.index') }}" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
  <label>{{ __('email-marketing.state') }} <select class="ad-input" name="state">
    @foreach(['all','pending','granted','withdrawn','suppressed'] as $option)
      <option value="{{ $option }}" @selected($state === $option)>{{ __('email-marketing.state_'.$option) }}</option>
    @endforeach
  </select></label>
  <button class="btn btn-glass" type="submit">{{ __('email-marketing.filter') }}</button>
</form>
<div class="ad-panel" style="overflow-x:auto"><table class="ad-table">
  <thead><tr>
    <th>{{ __('email-marketing.customer') }}</th><th>{{ __('email-marketing.address') }}</th>
    <th>{{ __('email-marketing.state') }}</th><th>{{ __('email-marketing.eligibility') }}</th>
    <th>{{ __('email-marketing.consent_date') }}</th><th>{{ __('email-marketing.action') }}</th>
  </tr></thead>
  <tbody>
    @forelse($records as $record)
      <tr>
        <td>{{ $record->customer?->code ?? '—' }}</td><td dir="ltr">{{ $masked[$record->id] }}</td>
        <td>{{ __('email-marketing.state_'.($record->suppressed_at ? 'suppressed' : $record->state)) }}</td>
        <td><strong>{{ __('email-marketing.'.(($eligible[$record->id] ?? false) ? 'allowed' : 'blocked')) }}</strong></td>
        <td>{{ $record->consented_at ? sdate($record->consented_at) : '—' }}</td>
        <td>@if(!$record->suppressed_at && $record->state !== 'withdrawn')
          <form method="post" action="{{ route('admin.email-marketing.suppress', $record) }}">@csrf
            <button class="btn btn-glass" type="submit">{{ __('email-marketing.suppress') }}</button>
          </form>
        @else — @endif</td>
      </tr>
    @empty<tr><td colspan="6">{{ __('email-marketing.empty') }}</td></tr>@endforelse
  </tbody>
</table></div>
{{ $records->links() }}
@endsection
