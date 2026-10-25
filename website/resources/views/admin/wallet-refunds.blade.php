@extends('admin.layout')
@section('title', __('refund.queue'))
@section('nav_wallet_refunds','on')
@section('content')
<h2>{{ __('refund.queue') }}</h2>
<p>{{ __('refund.accounting_note') }}</p>
<form method="get" action="{{ route('admin.wallet-refunds') }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-bottom:18px">
  @if(request('customer'))<input type="hidden" name="customer" value="{{ request('customer') }}">@endif
  <label>{{ __('refund.status') }}<select class="ad-input" name="status"><option value="all">{{ __('refund.all') }}</option>
  @foreach(['requested','approved','paid','rejected','cancelled'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ __('refund.status_'.$status) }}</option>@endforeach</select></label>
  <label>{{ __('refund.from_requested') }}<x-admin-jdate class="ad-input" name="from" :value="request('from','')" /></label>
  <label>{{ __('refund.to_requested') }}<x-admin-jdate class="ad-input" name="to" :value="request('to','')" /></label>
  <label>{{ __('refund.search') }}<input class="ad-input" name="q" maxlength="150" value="{{ request('q') }}"></label>
  <button class="btn btn-primary">{{ __('refund.filter') }}</button>
  <a class="btn btn-glass" href="{{ route('admin.wallet-refunds.export', request()->only(['customer','status','from','to','q'])) }}">{{ __('refund.export') }}</a>
</form>
<div class="ad-panel" style="overflow-x:auto"><table class="ad-table">
<thead><tr><th>{{ __('refund.customer') }}</th><th>{{ __('refund.amount') }}</th><th>{{ __('refund.status') }}</th><th>{{ __('refund.date') }}</th><th>{{ __('refund.reference') }}</th><th></th></tr></thead>
<tbody>@forelse($refunds as $r)<tr>
<td><a href="/admin/customers/{{ $r->customer_id }}#finance">{{ $r->customer->code }}</a></td>
<td>{{ number_format($r->amount_irt) }} {{ __('refund.toman') }}</td><td>{{ __('refund.status_'.$r->status) }}</td>
<td>{{ sdate($r->created_at) }}</td><td dir="ltr">{{ $r->bank_reference ?: '—' }}</td>
<td><a href="{{ route('admin.wallet-refunds.show', $r) }}">{{ __('refund.manage') }}</a></td>
</tr>@empty<tr><td colspan="6">{{ __('refund.empty') }}</td></tr>@endforelse</tbody></table></div>
{{ $refunds->links() }}
@endsection
