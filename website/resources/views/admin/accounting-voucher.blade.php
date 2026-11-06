@extends('admin.layout')
@section('title', __('finance.accounting').' #'.$voucher->id)
@section('nav_finance','on')
@section('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/site-select.css') }}">
<link rel="stylesheet" href="{{ asset_ver('assets/css/finance-funding.css') }}">
@endsection
@section('scripts')
<script src="{{ asset_ver('assets/js/site-select.js') }}" defer></script>
@endsection
@section('content')
@php
  $postedDetails = $postedDetails ?? [];
  $accountSnapshots = $accountSnapshots ?? ['funding' => null, 'target' => null];
  $currentAccountIdentities = $currentAccountIdentities ?? [];
  $relatedVoucher = $relatedVoucher ?? null;
@endphp
@if($errors->any())<p class="ad-note">{{ $errors->first() }}</p>@endif
@if(session('ok'))<p class="ad-note">{{ session('ok') }}</p>@endif
<p><a href="{{ route('admin.accounting') }}">{{ __('finance.accounting') }}</a></p>
<div class="ad-panel ff-voucher" style="padding:20px">
<h2>#{{ $voucher->id }} · {{ __('finance.'.$voucher->type) }}</h2>
<div class="ff-voucher-chain">
  @if(!empty($postedDetails['funding_mode']))<p><strong>{{ __('finance.funding_mode') }}:</strong> {{ __('finance.funding_mode_'.$postedDetails['funding_mode']) }}</p>@endif
  @if($relatedVoucher)<p><strong>{{ __('finance.related_voucher') }}:</strong> <a href="{{ route('admin.accounting.voucher', $relatedVoucher) }}">#{{ $relatedVoucher->id }} · {{ __('finance.'.$relatedVoucher->type) }}</a></p>@endif
  @if(!empty($postedDetails['business_entry_id']))<p>{{ __('finance.existing_entry') }}: <bdi dir="ltr">#{{ $postedDetails['business_entry_id'] }}</bdi></p>@endif
</div>
<div class="ff-identity-grid">
@foreach(['funding','target'] as $role)
  @php $identity = $accountSnapshots[$role] ?? $currentAccountIdentities[$role] ?? null; $historical = !empty($accountSnapshots[$role]); @endphp
  @if($identity)
    <section class="ff-identity-card" data-voucher-account="{{ $role }}" data-account-evidence="{{ $historical ? 'snapshot' : 'current' }}">
      <h3>{{ __('finance.'.($role === 'funding' ? 'funding_account' : 'target_account')) }}</h3>
      <p><strong>{{ $identity['name'] }}</strong> · #<bdi>{{ $identity['id'] }}</bdi></p>
      <p>{{ __('finance.'.$identity['type']) }} · <bdi dir="ltr">{{ $identity['currency_code'] }}</bdi></p>
      @if(!empty($identity['owner_name']))<p>{{ __('finance.owner_name') }}: {{ $identity['owner_name'] }}</p>@endif
      @if(!empty($identity['partner_key']))<p>{{ __('finance.partner_key') }}: <bdi dir="ltr">{{ $identity['partner_key'] }}</bdi></p>@endif
      @if(!empty($identity['bank_name']))<p>{{ __('finance.bank_name') }}: {{ $identity['bank_name'] }}</p>@endif
      @if(!empty($identity['card_last4']))<p>{{ __('finance.bank_card') }}: <bdi dir="ltr">•••• {{ $identity['card_last4'] }}</bdi></p>@endif
      @if(!empty($identity['iban_last4']))<p>{{ __('finance.bank_iban') }}: <bdi dir="ltr">{{ $identity['iban_country'] ?? '' }}•••• {{ $identity['iban_last4'] }}</bdi></p>@endif
      <small>{{ __('finance.'.($historical ? 'account_snapshot_help' : 'account_current_help')) }}</small>
    </section>
  @endif
@endforeach
</div>
<table class="ad-table">@foreach(['occurred_at','service_line','vendor','invoice_number','bank_reference','fx_basis','note','tax_status','tax_reference','business_entry_id','reversal_of'] as $key)<tr><th>{{ __('finance.'.match($key){'occurred_at'=>'date','vendor'=>'vendor_name','business_entry_id'=>'existing_entry','reversal_of'=>'reversal',default=>$key}) }}</th><td>{{ $key==='occurred_at'?\App\Support\FinanceDates::display($voucher->occurred_at->toDateString()):$voucher->$key }}</td></tr>@endforeach<tr><th>{{ __('finance.amount_irt') }}</th><td dir="ltr">{{ number_format($voucher->amount_irt) }} IRT</td></tr><tr><th>{{ __('finance.original_amount') }}</th><td dir="ltr">{{ \App\Services\Finance\AccountingMoney::display($voucher->original_minor,$voucher->original_currency) }}</td></tr><tr><th>{{ __('finance.settlement_amount') }}</th><td dir="ltr">{{ $voucher->settlement_currency?\App\Services\Finance\AccountingMoney::display((int)$voucher->settlement_minor,$voucher->settlement_currency):'—' }}</td></tr><tr><th>{{ __('finance.input_vat') }}</th><td dir="ltr">{{ number_format($voucher->input_vat_irt) }} IRT</td></tr></table>
<h3>{{ __('finance.lines') }}</h3><div style="overflow:auto"><table class="ad-table"><thead><tr><th>{{ __('finance.code') }}</th><th>#{{ __('finance.accounts') }}</th><th>{{ __('finance.debit') }}</th><th>{{ __('finance.credit') }}</th></tr></thead><tbody>@foreach($lines as $line)<tr><td>{{ $line->code }}</td><td>{{ $line->account_id??'—' }}</td><td dir="ltr">{{ number_format($line->debit_irt) }}</td><td dir="ltr">{{ number_format($line->credit_irt) }}</td></tr>@endforeach</tbody></table></div>
<h3>{{ __('finance.evidence') }}</h3><p class="ff-evidence-help">{{ __('finance.evidence_chain_help') }}</p>@foreach($documents as $doc)<p><a href="{{ route('admin.accounting.download',$doc) }}">{{ $doc->name }}</a> · {{ number_format($doc->size) }} bytes<br><small dir="ltr">SHA-256: {{ $doc->sha256 }}</small></p>@endforeach
<form method="post" enctype="multipart/form-data" action="{{ route('admin.accounting.evidence',$voucher) }}">@csrf<label>{{ __('finance.upload') }}<input type="file" name="evidence" accept="application/pdf,image/jpeg,image/png" required></label><button class="ad-btn">{{ __('finance.save') }}</button></form>
@if(!$voucher->reversal_of && in_array($voucher->type,['expense','cost_adjustment','asset_purchase','depreciation'],true))
<h3>{{ __('finance.tax_status') }}</h3>@foreach($reviews as $review)<p>{{ $review->created_at }} · {{ __('finance.'.$review->status) }} · {{ $review->reference }}</p>@endforeach
<form method="post" action="{{ route('admin.accounting.tax-review',$voucher) }}">@csrf<label>{{ __('finance.tax_status') }}<select name="status" class="ad-input">@foreach(['pending','eligible','rejected'] as $status)<option value="{{ $status }}">{{ __('finance.'.$status) }}</option>@endforeach</select></label><label>{{ __('finance.tax_reference') }}<input name="reference" class="ad-input" minlength="10" maxlength="255" required></label><button class="ad-btn">{{ __('finance.save') }}</button></form>
@endif
@if($voucher->type === 'wallet_refund_payout')<p>{{ __('refund.paid_no_reverse') }}</p>@endif
@if($voucher->type !== 'wallet_refund_payout' && !$voucher->reversal_of && !$reversal)<details style="margin-top:25px"><summary>{{ __('finance.reverse') }}</summary><form method="post" action="{{ route('admin.accounting.reverse',$voucher) }}" data-confirm="{{ __('finance.reverse') }}?" data-confirm-danger>@csrf<label>{{ __('finance.date') }}<x-admin-jdate name="occurred_at" class="ad-input" :value="now('Asia/Tehran')->toDateString()" required /></label><label>{{ __('finance.reason') }}<input name="reason" class="ad-input" minlength="5" maxlength="2000" required></label><button class="ad-btn">{{ __('finance.reverse') }}</button></form></details>@elseif($reversal)<p><a href="{{ route('admin.accounting.voucher',$reversal) }}">{{ __('finance.reversal') }} #{{ $reversal->id }}</a></p>@endif
</div>
@endsection
