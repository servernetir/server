@extends('admin.layout')
@section('title', __('email-evidence-review.title'))
@section('nav_email_marketing', 'on')
@section('content')
<p><a class="btn btn-glass" href="{{ route('admin.email-marketing.index') }}">{{ __('email-evidence-review.back') }}</a></p>
<h2>{{ __('email-evidence-review.title') }}</h2>
<p>{{ __('email-evidence-review.intro') }}</p>

@if($errors->any())
  <p role="alert">{{ __('email-evidence-review.invalid_input') }}</p>
@endif

<section class="ad-panel" style="padding:20px;margin:18px 0">
  <h3>{{ __('email-evidence-review.stage_title') }}</h3>
  <p>{{ __('email-evidence-review.stage_help') }}</p>
  <p dir="ltr"><code>email,consent_date,consent_reference</code></p>
  <p>{{ __('email-evidence-review.identifier_note') }}</p>
  <p><a class="btn btn-glass" href="{{ route('admin.email-marketing.evidence.sample') }}">{{ __('email-evidence-review.sample') }}</a></p>
  <form method="post" action="{{ route('admin.email-marketing.evidence.stage') }}" enctype="multipart/form-data">
    @csrf
    <label style="display:block;margin-bottom:12px">{{ __('email-evidence-review.file') }}<input class="ad-input" type="file" name="file" accept=".csv,text/csv" required></label>
    <label style="display:flex;gap:8px;align-items:start;margin:12px 0"><input type="checkbox" name="acknowledge" value="1" required><span>{{ __('email-evidence-review.acknowledge') }}</span></label>
    <button class="btn btn-primary" type="submit">{{ __('email-evidence-review.stage_button') }}</button>
  </form>
</section>

@if(is_array(session('evidence_result')))
  <section class="ad-panel" style="padding:18px;margin-bottom:20px" role="status">
    <h3>{{ __('email-evidence-review.stage_result') }}</h3>
    <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">
      @foreach(['rows','added','duplicates'] as $metric)
        <div><dt>{{ __('email-evidence-review.count_'.$metric) }}</dt><dd style="font-weight:700;margin:4px 0">{{ number_format((int) (session('evidence_result')[$metric] ?? 0)) }}</dd></div>
      @endforeach
    </dl>
    <p>{{ __('email-evidence-review.no_message') }}</p>
  </section>
@endif

<div class="ad-panel" style="overflow-x:auto"><table class="ad-table">
  <thead><tr>
    <th>{{ __('email-evidence-review.contact_id') }}</th>
    <th>{{ __('email-evidence-review.consent_date') }}</th>
    <th>{{ __('email-evidence-review.imported_at') }}</th>
    <th>{{ __('email-evidence-review.state') }}</th>
  </tr></thead>
  <tbody>
    @forelse($records as $record)
      <tr>
        <td dir="ltr">{{ $record->contact_id }}</td>
        <td dir="ltr">{{ $record->consent_date->format('Y-m-d') }}</td>
        <td>{{ sdate($record->created_at) }}</td>
        <td><strong>{{ __('email-evidence-review.review_only') }}</strong></td>
      </tr>
    @empty<tr><td colspan="4">{{ __('email-evidence-review.empty') }}</td></tr>@endforelse
  </tbody>
</table></div>
{{ $records->links() }}
@endsection
