@extends('admin.layout')
@section('title', __('bale-reporting.title'))
@section('nav_bale', 'on')
@section('content')
<div class="ad-panel" style="padding:20px;max-width:1000px;margin-bottom:20px">
  <h1>{{ __('bale-reporting.title') }}</h1>
  <p>{{ __('bale-reporting.intro') }}</p>
  <p>{{ __('bale-reporting.scope_note') }}</p>
  <p><strong>{{ $connection['connected'] ? __('bale-reporting.connected') : __('bale-reporting.not_connected') }}</strong>
    @if($connection['email']) · <span dir="ltr">{{ $connection['email'] }}</span>@endif
  </p>
  @if(!$connection['configured'])
    <p>{{ __('bale-reporting.credentials_missing') }}</p>
  @else
    <a class="btn" href="{{ url('/admin/bale/reporting/connect') }}">{{ __('bale-reporting.connect') }}</a>
  @endif
  @if($connection['connected'])
    <form method="post" action="{{ url('/admin/bale/reporting/disconnect') }}" style="display:inline-block;margin:8px">
      @csrf
      <button class="btn" type="submit">{{ __('bale-reporting.disconnect') }}</button>
    </form>
  @endif
  <p><small>{{ __('bale-reporting.refresh_note') }}</small></p>
</div>

<div class="ad-panel" style="padding:20px;max-width:1000px;margin-bottom:20px">
  <h2>{{ __('bale-reporting.sources') }}</h2>
  <form method="post" action="{{ url('/admin/bale/reporting/configure') }}" style="display:grid;gap:12px;max-width:600px">
    @csrf
    <label for="reporting-property">{{ __('bale-reporting.property') }}</label>
    <input id="reporting-property" name="property_id" inputmode="numeric" maxlength="12" dir="ltr" value="{{ old('property_id', $configuration['property_id']) }}" required>
    @error('property_id')<p role="alert">{{ $message }}</p>@enderror
    <label for="reporting-site">{{ __('bale-reporting.site') }}</label>
    <input id="reporting-site" name="site" maxlength="500" dir="ltr" value="{{ old('site', $configuration['site']) }}" required>
    @error('site')<p role="alert">{{ $message }}</p>@enderror
    <small>{{ __('bale-reporting.source_note') }}</small>
    <button class="btn" type="submit">{{ __('bale-reporting.save') }}</button>
  </form>
</div>

<div class="ad-panel" style="padding:20px;max-width:1000px;overflow:auto">
  <h2>{{ __('bale-reporting.observations') }}</h2>
  <p>{{ __('bale-reporting.unique_note') }}</p>
  <p>{{ __('bale-reporting.lag_note') }}</p>
  <p>{{ __('bale-reporting.observed_at') }}: <span dir="ltr">{{ $snapshot['as_of'] ?? __('bale-reporting.unknown') }}</span></p>
  @php
    $explain = fn ($code) => \Illuminate\Support\Facades\Lang::has('bale-reporting.reasons.'.($code ?? ''))
      ? __('bale-reporting.reasons.'.$code) : __('bale-reporting.reasons.no_reported_data');
  @endphp
  <table style="width:100%;min-width:650px;text-align:start">
    <thead><tr><th>{{ __('bale-reporting.source') }}</th><th>{{ __('bale-reporting.period') }}</th><th>{{ __('bale-reporting.metrics') }}</th><th>{{ __('bale-reporting.status') }}</th></tr></thead>
    <tbody>
    @foreach(['ga4', 'gsc'] as $source)
      @foreach($snapshot[$source]['periods'] as $period => $data)
        <tr>
          <td>{{ $source === 'ga4' ? 'Google Analytics 4' : 'Google Search Console' }}</td>
          <td>{{ __('bale-reporting.'.$period) }}<br><span dir="ltr">{{ $data['start_date'] }} – {{ $data['end_date'] }}</span><br><small dir="ltr">{{ $data['timezone'] ?? __('bale-reporting.unknown') }}</small></td>
          <td>
            @if($data['status'] === 'known')
              @if($source === 'ga4')
                {{ __('bale-reporting.users') }}: {{ number_format($data['total_users']) }}<br>{{ __('bale-reporting.sessions') }}: {{ number_format($data['sessions']) }}
              @else
                {{ __('bale-reporting.clicks') }}: {{ number_format($data['clicks']) }}<br>{{ __('bale-reporting.impressions') }}: {{ number_format($data['impressions']) }}
              @endif
            @else
              {{ __('bale-reporting.unknown') }}
            @endif
          </td>
          <td>{{ $data['status'] === 'known' ? ($data['preliminary'] ? __('bale-reporting.preliminary') : __('bale-reporting.reported')) : $explain($data['reason']) }}</td>
        </tr>
      @endforeach
    @endforeach
    </tbody>
  </table>
</div>
@endsection
