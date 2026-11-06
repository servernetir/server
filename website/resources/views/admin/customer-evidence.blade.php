@extends('admin.layout')
@section('title', __('bale-evidence.title'))
@section('nav_customers', 'on')
@section('content')
<p><a href="{{ url('/admin/customers/'.$c->id) }}">{{ __('bale-evidence.back_to_profile', ['name' => $c->displayName(), 'code' => $c->code]) }}</a></p>
<div class="ad-panel">
  <div class="ad-panel-h"><h2>{{ __('bale-evidence.identity_history') }}</h2></div>
  <table class="ad-table"><thead><tr><th>{{ __('bale-evidence.profile') }}</th><th>{{ __('bale-evidence.document') }}</th><th>{{ __('bale-evidence.status') }}</th><th>{{ __('bale-evidence.submitted') }}</th></tr></thead><tbody>
  @forelse($documents as $doc)
    <tr><td>{{ $doc->profile?->displayName() ?: '—' }}</td>
      <td><a href="{{ route('admin.verification.doc', ['profile' => $doc->customer_profile_id, 'document' => $doc->id]) }}">{{ $doc->original_name ?: $doc->kind }}</a></td>
      <td>{{ trans()->has('bale-evidence.status_'.$doc->status) ? __('bale-evidence.status_'.$doc->status) : $doc->status }}</td><td>{{ stime($doc->uploaded_at) }}</td></tr>
  @empty <tr><td colspan="4">{{ __('bale-evidence.no_documents') }}</td></tr> @endforelse
  </tbody></table>
</div>
{{ $documents->links() }}
@if($receipts)
<div class="ad-panel">
  <div class="ad-panel-h"><h2>{{ __('bale-evidence.receipts') }}</h2></div>
  <table class="ad-table"><thead><tr><th>{{ __('bale-evidence.receipt') }}</th><th>{{ __('bale-evidence.status') }}</th><th>{{ __('bale-evidence.submitted') }}</th></tr></thead><tbody>
  @forelse($receipts as $receipt)
    <tr><td><a href="{{ route('admin.bank-transfers.evidence', $receipt) }}">#{{ $receipt->id }} · {{ $receipt->evidence_name }}</a></td>
      <td>{{ trans()->has('bale-evidence.status_'.$receipt->status) ? __('bale-evidence.status_'.$receipt->status) : $receipt->status }}</td><td>{{ stime($receipt->created_at) }}</td></tr>
  @empty <tr><td colspan="3">{{ __('bale-evidence.no_receipts') }}</td></tr> @endforelse
  </tbody></table>
</div>
{{ $receipts->links() }}
@endif
@endsection
