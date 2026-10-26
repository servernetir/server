@extends('admin.layout')
@section('title', __('refund.title'))
@section('nav_wallet_refunds','on')
@section('content')
<h2>{{ __('refund.title') }} · <a href="/admin/customers/{{ $refund->customer_id }}#finance">{{ $refund->customer->code }}</a></h2>
@if($errors->any())<p class="ad-note err" role="alert">{{ $errors->first() }}</p>@endif
@if(session('ok'))<p class="ad-note">{{ session('ok') }}</p>@endif
<style>.wr-panel{padding:20px;margin:16px 0;border:1px solid var(--line);border-radius:12px}.wr-form{display:grid;gap:14px;max-width:720px}.wr-form label{display:grid;gap:6px}.wr-form .wr-check{display:flex;gap:8px;align-items:flex-start}.wr-form .ad-input{width:100%;box-sizing:border-box}.wr-panel p{overflow-wrap:anywhere}</style>
<div class="wr-panel">
  <p><b>{{ number_format($refund->amount_irt) }} {{ __('refund.toman') }}</b> · {{ __('refund.status_'.$refund->status) }}</p>
  <p>{{ __('refund.request_id') }}: <bdi dir="ltr">{{ $refund->public_id }}</bdi></p>
  <p>{{ __('refund.bank') }}: {{ $refund->bank_snapshot['bank_name'] }} · {{ $refund->bank_snapshot['owner_name'] }} · <bdi dir="ltr" style="user-select:all">{{ $refund->bank_snapshot['iban'] }}</bdi></p>
  <p>{{ __('refund.date') }}: {{ sdate($refund->created_at) }}</p>
  <p style="white-space:pre-wrap">{{ $refund->customer_note }}</p>
  @if($refund->review_note)<p>{{ __('refund.review_note') }}: {{ $refund->review_note }}</p>@endif
  @if($refund->source_note)<p>{{ __('refund.source_note') }}: {{ $refund->source_note }}</p>@endif
  <p>{{ __('refund.accounting_note') }}</p>
</div>
@if($refund->status === 'requested')
<div class="wr-panel"><h3>{{ __('refund.approve') }}</h3>
<form method="post" action="{{ route('admin.wallet-refunds.approve', $refund) }}" class="wr-form">@csrf
  <label>{{ __('refund.review_note') }}<textarea class="ad-input" name="note" required minlength="5" maxlength="2000">{{ old('note') }}</textarea></label>
  <label>{{ __('refund.source_payment') }}<select class="ad-input" name="source_payment_id"><option value="">—</option>
    @foreach($payments as $payment)<option value="{{ $payment->id }}" @selected(old('source_payment_id') == $payment->id)>#{{ $payment->id }} · {{ number_format($payment->amount) }} IRT · {{ $payment->gateway }}</option>@endforeach
  </select></label>
  <label>{{ __('refund.source_note') }}<textarea class="ad-input" name="source_note" required minlength="10" maxlength="2000">{{ old('source_note') }}</textarea></label>
  <small>{{ __('refund.source_help') }}</small>
  <div><button class="btn btn-primary">{{ __('refund.approve') }}</button></div>
</form></div>
@endif
@if($refund->status === 'approved')
<div class="wr-panel"><h3>{{ __('refund.record_paid') }}</h3>
<p>{{ __('refund.no_transfer_api') }}</p>
<form method="post" action="{{ route('admin.wallet-refunds.paid', $refund) }}" enctype="multipart/form-data" class="wr-form"
      data-confirm="{{ __('refund.transferred') }}" data-confirm-danger>@csrf
  <label>{{ __('refund.funding_account') }}<select class="ad-input" name="funding_account_id" required><option value="">—</option>
    @foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }} · {{ $account->currency_code }}</option>@endforeach
  </select></label>
  <label>{{ __('refund.payout_date') }}<x-admin-jdate class="ad-input" name="payout_date" :value="old('payout_date',now('Asia/Tehran')->toDateString())" required /></label>
  <label>{{ __('refund.reference') }}<input class="ad-input" name="bank_reference" required minlength="4" maxlength="150" dir="ltr" value="{{ old('bank_reference') }}"></label>
  <label>{{ __('refund.bank_row') }}<input class="ad-input" name="bank_transaction_id" type="number" min="1" value="{{ old('bank_transaction_id') }}"></label>
  <label>{{ __('refund.receipt_upload') }}<input class="ad-input" name="evidence" type="file" accept=".pdf,.jpg,.jpeg,.png" required></label>
  <label class="wr-check"><input type="checkbox" name="transferred" value="1" required>{{ __('refund.transferred') }}</label>
  <div><button class="btn btn-primary">{{ __('refund.record_paid') }}</button></div>
</form></div>
@endif
@if(in_array($refund->status, \App\Models\WalletRefundRequest::OPEN, true))
<div class="wr-panel"><h3>{{ __('refund.reject') }}</h3>
<form method="post" action="{{ route('admin.wallet-refunds.reject', $refund) }}" class="wr-form">@csrf
  <label>{{ __('refund.review_note') }}<textarea class="ad-input" name="note" required minlength="5" maxlength="2000"></textarea></label>
  <label class="wr-check"><input type="checkbox" name="not_transferred" value="1" required>{{ __('refund.not_transferred') }}</label>
  <div><button class="btn btn-glass">{{ __('refund.reject') }}</button></div>
</form></div>
@endif
@if($refund->status === 'paid')
<div class="wr-panel">
<p>{{ __('refund.reference') }}: <bdi dir="ltr">{{ $refund->bank_reference }}</bdi> · {{ sdate($refund->payout_date) }}</p>
<a href="{{ route('admin.accounting.voucher', $refund->voucher_id) }}">{{ __('refund.voucher') }}</a>
<a href="{{ route('admin.accounting.download', $refund->document_id) }}">{{ __('refund.receipt') }}</a>
</div>
@endif
<div class="wr-panel"><h3>{{ __('refund.audit') }}</h3>
@foreach($events as $event)
  @php
    $eventDetails = json_decode($event->details, true) ?: [];
  @endphp
  <details style="padding:10px 0;border-bottom:1px solid var(--line)">
    <summary>{{ sdate($event->created_at) }} · {{ $event->action === 'permission_changed' ? __('refund.permission') : __('refund.status_'.$event->action) }} · {{ $event->actor_name }}</summary>
    @if(isset($eventDetails['enabled']))<p>{{ $eventDetails['enabled'] ? __('refund.enabled') : __('refund.off') }}</p>@endif
    @foreach(['note','reason','source_note'] as $detail)
      @if(!empty($eventDetails[$detail]))
        <p style="white-space:pre-wrap">{{ $eventDetails[$detail] }}</p>
      @endif
    @endforeach
  </details>
@endforeach
</div>
@endsection
