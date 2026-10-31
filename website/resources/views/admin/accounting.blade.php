@extends('admin.layout')
@section('title', __('finance.accounting'))
@section('nav_finance','on')
@section('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/site-select.css') }}">
<link rel="stylesheet" href="{{ asset_ver('assets/css/finance-funding.css') }}">
@endsection
@section('content')
@include('admin.partials.finance-periods')
@php
  $money=fn($n)=>$n===null?__('finance.unconfigured'):number_format((int)$n).' IRT';
  $r=$report; $s=$r['summary'];
@endphp
<style>
.acc-nav{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 18px}.acc-nav a{padding:9px 14px;background:var(--card,rgba(90,120,160,.1));border:1px solid var(--line,#334155);border-radius:10px;color:var(--text,#d9e7ff);text-decoration:none}.acc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}.acc-panel{padding:20px;margin:16px 0;border:1px solid var(--line,#334155);border-radius:14px;background:var(--card,rgba(90,120,160,.05))}.acc-panel h2{font-size:17px;margin:0 0 16px}.acc-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.acc-form label{display:flex;flex-direction:column;gap:7px;font-size:13px}.acc-form input,.acc-form select,.acc-form textarea{width:100%;box-sizing:border-box}.acc-wide{grid-column:1/-1}.acc-muted{color:var(--dim,#94a3b8);line-height:1.9;font-size:13px}.acc-card{padding:18px;border:1px solid var(--line,#334155);border-radius:14px}.acc-card small{display:block;min-height:38px;color:var(--dim,#94a3b8)}.acc-card b{display:block;margin-top:9px;font-size:22px;font-variant-numeric:tabular-nums}.acc-scroll{overflow-x:auto}.acc-table{width:100%;border-collapse:collapse;font-size:13px}.acc-table th,.acc-table td{padding:12px;text-align:start;border-bottom:1px solid var(--line,#334155);vertical-align:top}.acc-tag{font-size:12px;padding:4px 8px;border-radius:7px;background:rgba(95,130,180,.14)}.acc-btn{padding:10px 18px;border-radius:9px;border:1px solid var(--line,#334155);color:inherit;background:rgba(34,160,200,.16);cursor:pointer}.acc-num{direction:ltr;white-space:nowrap;font-variant-numeric:tabular-nums}.acc-warn{border-inline-start:4px solid #eab308;padding:12px 16px;background:rgba(234,179,8,.07);border-radius:8px;line-height:1.9}.acc-form details{grid-column:1/-1}.acc-form summary{cursor:pointer;padding:12px}.acc-form details .acc-form{padding-top:12px}@media(max-width:600px){.acc-panel{padding:14px}.acc-card b{font-size:18px}}@media print{.acc-nav,.acc-form,.acc-btn{display:none}.acc-panel,.acc-card{break-inside:avoid}a{color:inherit}}
</style>
@if($errors->any())<div class="acc-warn" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(session('ok'))<p class="ad-note">{{ session('ok') }}</p>@endif
<div class="acc-nav"><a href="{{ route('admin.accounting.bundle',array_filter(['from'=>$filters['from']??null,'to'=>$filters['to']??null])) }}">{{ __('finance.bundle') }}</a><a href="{{ route('admin.bank-reconciliation') }}">{{ __('finance.bank_reconciliation') }}</a><a href="{{ route('admin.finance') }}">{{ __('finance.back_finance') }}</a>@foreach(['overview','accounts','funding_guide','new_voucher','supplier_debit','assets','tax','analytics','register'] as $section)<a href="#{{ $section }}">{{ __('finance.'.$section) }}</a>@endforeach<a href="{{ route('admin.accounting.export',array_filter(['from'=>$filters['from']??null,'to'=>$filters['to']??null,'range'=>$filters['range']??null])) }}">{{ __('finance.export') }}</a></div>
@if(!$ready)<p class="acc-warn">{{ __('finance.not_ready') }}</p>@endif
<form method="get" action="{{ route('admin.accounting') }}" class="acc-form acc-panel">
<label>{{ __('finance.from') }}<x-admin-jdate class="ad-input" name="from" :value="$filters['from']??''" /></label>
<label>{{ __('finance.to') }}<x-admin-jdate class="ad-input" name="to" :value="$filters['to']??''" /></label>
<label>{{ __('finance.search') }}<input name="q" class="ad-input" value="{{ $filters['q']??'' }}" maxlength="150"></label>
<label>{{ __('finance.service_line') }}<select class="ad-input" name="service_line"><option value="">{{ __('finance.all') }}</option>@foreach(\App\Services\Finance\DocumentedAccounting::SERVICES as $line)<option value="{{ $line }}" @selected(request('service_line')===$line)>{{ __('finance.'.$line) }}</option>@endforeach</select></label>
<button class="acc-btn">{{ __('finance.apply') }}</button><a href="{{ route('admin.accounting') }}">{{ __('finance.reset') }}</a>
<p class="acc-muted acc-wide">{{ __('finance.date_scope') }}</p>
</form>
<section id="overview">
<p class="acc-warn">{{ __('finance.report_limits') }}</p>
<div class="acc-grid">@foreach(['revenue'=>$s['revenue'],'expense'=>$s['expense'],'profit'=>$s['net_profit'],'other_income'=>$s['other_income']??0,'partner_receivables_irt'=>$r['partner_receivables_irt']??0,'partner_payables_irt'=>$r['partner_payables_irt']??0,'eligible_costs'=>$r['eligible_costs'],'pending_costs'=>$r['pending_costs'],'income_tax_estimate'=>$r['income_tax_estimate'],'income_tax_due'=>$r['income_tax_due'],'vat_movement'=>$r['vat_movement'],'vat_due'=>$r['vat_due'],'vat_credit'=>$r['vat_credit']] as $key=>$value)<div class="acc-card"><small>{{ __('finance.'.$key) }}</small><b class="acc-num">{{ $money($value) }}</b></div>@endforeach</div>
<div class="acc-panel"><h2>{{ __('finance.overview') }}</h2><table class="acc-table">@foreach($r['payments'] as $k=>$v)<tr><td>{{ __('finance.'.$k) }}</td><td class="acc-num">{{ $money($v) }}</td></tr>@endforeach<tr><td>{{ __('finance.vat_output_reviewed') }}</td><td class="acc-num">{{ $money($r['vat_output_reviewed']) }}</td></tr><tr><td>{{ __('finance.input_vat') }}</td><td class="acc-num">{{ $money($r['input_vat']) }}</td></tr><tr><td>{{ __('finance.taxable_income') }}</td><td class="acc-num">{{ $money($r['taxable_income']) }}</td></tr></table></div>
<div class="acc-panel"><h2>{{ __('finance.coverage') }}</h2><table class="acc-table">@foreach($r['coverage'] as $k=>$v)<tr><td>{{ __('finance.'.$k) }}</td><td class="acc-num">{{ number_format($v) }}</td></tr>@endforeach</table>@if($r['unconverted'])<p class="acc-warn">{{ __('finance.unconverted') }}</p><table class="acc-table">@foreach($r['unconverted'] as $code=>$kinds)@foreach($kinds as $kind=>$value)<tr><td>{{ $kind }}</td><td class="acc-num">{{ \App\Services\Finance\AccountingMoney::display($value,$code) }}</td></tr>@endforeach @endforeach</table>@endif</div>
@if(!empty($r['services']))<div class="acc-panel"><h2>{{ __('finance.service_profit') }}</h2><p class="acc-muted">{{ __('finance.service_profit_help') }}</p><div class="acc-scroll"><table class="acc-table"><thead><tr><th>{{ __('finance.service_line') }}</th><th>{{ __('finance.revenue') }}</th><th>{{ __('finance.expense') }}</th><th>{{ __('finance.profit') }}</th></tr></thead><tbody>@foreach($r['services'] as $line=>$row)<tr><td>{{ __('finance.'.$line) }}</td><td class="acc-num">{{ $money($row['revenue']) }}</td><td class="acc-num">{{ $money($row['expense']) }}</td><td class="acc-num">{{ $money($row['revenue']+($row['other_income']??0)-$row['expense']) }}</td></tr>@endforeach</tbody></table></div></div>@endif
</section>
@if($ready)
<section class="acc-panel" id="accounts">
<h2>{{ __('finance.accounts') }}</h2>
<p class="acc-muted">{{ __('finance.partner_hint') }}</p>
<div class="acc-scroll"><table class="acc-table"><thead><tr><th>#</th><th>{{ __('finance.account_name') }}</th><th>{{ __('finance.account_type') }}</th><th>{{ __('finance.recorded_balance') }}</th><th>{{ __('finance.base_balance') }}</th><th>{{ __('finance.account_edit') }}</th></tr></thead><tbody>
@forelse($r['accounts'] as $row)
  @php $account = $row['account']; $identity = $account->maskedBankIdentity(); $editing = (string)old('account_id') === (string)$account->id; @endphp
  <tr data-finance-account="{{ $account->id }}">
    <td>{{ $account->id }}</td>
    <td><strong>{{ $account->name }}</strong><br><small>{{ $account->owner_name }}</small>
      <div class="ff-masked" data-masked-bank-identity>
        @if(!empty($identity['bank_name']))<span>{{ $identity['bank_name'] }}</span>@endif
        @if(!empty($identity['partner_key']))<span>{{ __('finance.partner_key') }}: <bdi dir="ltr">{{ $identity['partner_key'] }}</bdi></span>@endif
        @if(!empty($identity['card_last4']))<span>{{ __('finance.bank_card') }}: <bdi dir="ltr">•••• {{ $identity['card_last4'] }}</bdi></span>@endif
        @if(!empty($identity['iban_last4']))<span>{{ __('finance.bank_iban') }}: <bdi dir="ltr">{{ $identity['iban_country'] ?? '' }}•••• {{ $identity['iban_last4'] }}</bdi></span>@endif
      </div>
    </td>
    <td>{{ __('finance.'.$account->type) }}</td>
    <td class="acc-num">{{ \App\Services\Finance\AccountingMoney::display($row['balance']['native'],$account->currency_code) }}</td>
    <td class="acc-num">{{ $money($row['balance']['irt']) }}</td>
    <td><details class="ff-account-edit" @if($editing && $errors->any()) open @endif><summary>{{ __('finance.account_edit') }}</summary>
      <p class="acc-muted">{{ __('finance.account_edit_help') }}</p>
      <form method="post" action="{{ route('admin.accounting.accounts') }}" class="acc-form ff-account-form" data-account-update>
        @csrf
        <input type="hidden" name="account_id" value="{{ $account->id }}">
        <input type="hidden" name="type" value="{{ $account->type }}">
        <input type="hidden" name="currency_code" value="{{ $account->currency_code }}">
        <label>{{ __('finance.account_name') }}<input name="name" class="ad-input" maxlength="150" required value="{{ $editing ? old('name', $account->name) : $account->name }}"></label>
        <label>{{ __('finance.owner_name') }}<input name="owner_name" class="ad-input" maxlength="150" value="{{ $editing ? old('owner_name', $account->owner_name) : $account->owner_name }}"></label>
        <label>{{ __('finance.partner_key') }}<input name="partner_key" class="ad-input" maxlength="80" dir="ltr" value="{{ $editing ? old('partner_key', $account->partner_key) : $account->partner_key }}"><small>{{ __('finance.partner_key_help') }}</small></label>
        <label>{{ __('finance.bank_name') }}<input name="bank_name" class="ad-input" maxlength="150" value="{{ $editing ? old('bank_name', $identity['bank_name'] ?? '') : ($identity['bank_name'] ?? '') }}"></label>
        <label>{{ __('finance.bank_iban') }}<input name="iban" class="ad-input" maxlength="80" dir="ltr" autocomplete="off" data-private-bank-input></label>
        <label>{{ __('finance.bank_card') }}<input name="card_number" class="ad-input" maxlength="80" dir="ltr" inputmode="numeric" autocomplete="off" data-private-bank-input></label>
        <p class="acc-muted acc-wide">{{ __('finance.bank_identity_edit_help') }}</p>
        <button class="acc-btn" type="submit">{{ __('finance.save') }}</button>
      </form>
    </details></td>
  </tr>
@empty<tr><td colspan="6">{{ __('finance.no_records') }}</td></tr>@endforelse
</tbody></table></div>
<details class="ff-account-create" @if(!old('account_id')) open @endif><summary>{{ __('finance.account_create') }}</summary>
<form method="post" action="{{ route('admin.accounting.accounts') }}" class="acc-form" data-account-create>@csrf
<label>{{ __('finance.account_name') }}<input name="name" class="ad-input" maxlength="150" required value="{{ old('account_id') ? '' : old('name') }}"></label>
<label>{{ __('finance.account_type') }}<select name="type" class="ad-input">@foreach(['partner','bank','vendor'] as $type)<option value="{{ $type }}" @selected(!old('account_id') && old('type','partner') === $type)>{{ __('finance.'.$type) }}</option>@endforeach</select></label>
<label>{{ __('finance.currency') }}<select name="currency_code" class="ad-input">@foreach(array_keys(\App\Services\Finance\AccountingMoney::EXPONENTS) as $code)<option @selected(!old('account_id') && old('currency_code','IRT') === $code)>{{ $code }}</option>@endforeach</select></label>
<label>{{ __('finance.owner_name') }}<input name="owner_name" class="ad-input" maxlength="150" value="{{ old('account_id') ? '' : old('owner_name') }}"></label>
<label>{{ __('finance.partner_key') }}<input name="partner_key" class="ad-input" maxlength="80" dir="ltr" value="{{ old('account_id') ? '' : old('partner_key') }}"><small>{{ __('finance.partner_key_help') }}</small></label>
<label>{{ __('finance.bank_name') }}<input name="bank_name" class="ad-input" maxlength="150" value="{{ old('account_id') ? '' : old('bank_name') }}"></label>
<label>{{ __('finance.bank_iban') }}<input name="iban" class="ad-input" maxlength="80" dir="ltr" autocomplete="off" data-private-bank-input></label>
<label>{{ __('finance.bank_card') }}<input name="card_number" class="ad-input" maxlength="80" dir="ltr" inputmode="numeric" autocomplete="off" data-private-bank-input></label>
<p class="acc-muted acc-wide">{{ __('finance.bank_identity_help') }}</p>
<button class="acc-btn" type="submit">{{ __('finance.account_create') }}</button>
</form></details>
</section>

<section class="acc-panel ff-guide" id="funding_guide">
<h2>{{ __('finance.funding_guide') }}</h2>
<p class="acc-muted">{{ __('finance.funding_guide_intro') }}</p>
<div class="ff-guide-grid">
  <article><span class="ff-step">01</span><h3>{{ __('finance.guide_partner_title') }}</h3><p>{{ __('finance.guide_partner_body') }}</p></article>
  <article><span class="ff-step">02</span><h3>{{ __('finance.guide_supplier_title') }}</h3><p>{{ __('finance.guide_supplier_body') }}</p></article>
  <article><span class="ff-step">03</span><h3>{{ __('finance.guide_conversion_title') }}</h3><p>{{ __('finance.guide_conversion_body') }}</p></article>
</div>
<p class="ff-guide-note">{{ __('finance.guide_gateway_body') }}</p>
</section>
<section class="acc-panel" id="new_voucher"><h2>{{ __('finance.new_voucher') }}</h2><p class="acc-muted">{{ __('finance.purchase_help') }}</p><p class="ff-guide-note">{{ __('finance.advance_workflow_help') }}</p>
<form method="post" action="{{ route('admin.accounting.store') }}" class="acc-form" data-finance-voucher>@csrf<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',(string)\Illuminate\Support\Str::uuid()) }}">
<label>{{ __('finance.type') }}<select name="type" class="ad-input">@foreach(array_diff(\App\Services\Finance\DocumentedAccounting::TYPES,['bank_collection']) as $type)<option value="{{ $type }}" @selected(old('type')===$type)>{{ __('finance.'.$type) }}</option>@endforeach</select></label>
<label>{{ __('finance.date') }}<x-admin-jdate name="occurred_at" class="ad-input" :value="old('occurred_at',now('Asia/Tehran')->toDateString())" required /></label>
<label>{{ __('finance.service_line') }}<select name="service_line" class="ad-input">@foreach(\App\Services\Finance\DocumentedAccounting::SERVICES as $line)<option value="{{ $line }}" @selected(old('service_line')===$line)>{{ __('finance.'.$line) }}</option>@endforeach</select></label>
<label>{{ __('finance.category') }}<select name="category" class="ad-input">@foreach(\App\Services\Finance\BusinessLedger::EXPENSE_CATEGORIES as $cat)<option value="{{ $cat }}" @selected(old('category')===$cat)>{{ \App\Services\Finance\BusinessLedger::categoryLabel($cat) }}</option>@endforeach</select></label>
<label>{{ __('finance.amount_irt') }}<input name="amount_irt" class="ad-input" dir="ltr" inputmode="numeric" value="{{ old('amount_irt') }}" required></label>
<label>{{ __('finance.original_currency') }}<select name="original_currency" class="ad-input">@foreach(array_keys(\App\Services\Finance\AccountingMoney::EXPONENTS) as $code)<option @selected(old('original_currency')===$code)>{{ $code }}</option>@endforeach</select></label>
<label>{{ __('finance.original_amount') }}<input name="original_amount" class="ad-input" dir="ltr" inputmode="decimal" value="{{ old('original_amount') }}" required></label>
<label>{{ __('finance.funding_account') }}<select name="funding_account_id" class="ad-input"><option value="">—</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected((string)old('funding_account_id')===(string)$a->id)>{{ __('finance.'.$a->type) }} · #{{ $a->id }} · {{ $a->name }}@if($a->owner_name) · {{ $a->owner_name }}@endif · {{ $a->currency_code }}</option>@endforeach</select></label>
<label>{{ __('finance.funding_mode') }}<select name="funding_mode" class="ad-input"><option value="personal" @selected(old('funding_mode','personal') === 'personal')>{{ __('finance.funding_mode_personal') }}</option><option value="advance" @selected(old('funding_mode') === 'advance')>{{ __('finance.funding_mode_advance') }}</option></select><small>{{ __('finance.funding_mode_help') }}</small></label>
<label>{{ __('finance.related_voucher') }}<input name="related_voucher_id" class="ad-input" type="number" min="1" dir="ltr" value="{{ old('related_voucher_id') }}"><small>{{ __('finance.related_voucher_help') }}</small></label>
<label>{{ __('finance.target_account') }}<select name="target_account_id" class="ad-input"><option value="">—</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected((string)old('target_account_id')===(string)$a->id)>{{ __('finance.'.$a->type) }} · #{{ $a->id }} · {{ $a->name }}@if($a->owner_name) · {{ $a->owner_name }}@endif · {{ $a->currency_code }}</option>@endforeach</select></label>
<label>{{ __('finance.settlement_currency') }}<select name="settlement_currency" class="ad-input">@foreach(array_keys(\App\Services\Finance\AccountingMoney::EXPONENTS) as $code)<option @selected(old('settlement_currency')===$code)>{{ $code }}</option>@endforeach</select></label>
<label>{{ __('finance.settlement_amount') }}<input name="settlement_amount" class="ad-input" dir="ltr" inputmode="decimal" value="{{ old('settlement_amount') }}"></label>
@foreach(['vendor','invoice_number','bank_reference','fx_basis','business_entry_id'] as $field)<label>{{ __('finance.'.($field==='vendor'?'vendor_name':($field==='business_entry_id'?'existing_entry':$field))) }}<input name="{{ $field }}" class="ad-input" value="{{ old($field) }}" maxlength="{{ $field==='fx_basis'?255:150 }}" @if($field==='business_entry_id') inputmode="numeric" @endif></label>@endforeach
<label class="acc-wide">{{ __('finance.note') }}<textarea name="note" class="ad-input" maxlength="2000" rows="3" required>{{ old('note') }}</textarea></label>
<details open><summary>{{ __('finance.tax') }}</summary><div class="acc-form"><label>{{ __('finance.tax_status') }}<select name="tax_status" class="ad-input">@foreach(['pending','rejected'] as $status)<option value="{{ $status }}" @selected(old('tax_status','pending')===$status)>{{ __('finance.'.$status) }}</option>@endforeach</select></label><label>{{ __('finance.tax_reference') }}<input name="tax_reference" class="ad-input" maxlength="255" value="{{ old('tax_reference') }}"></label><label>{{ __('finance.input_vat') }} (IRT)<input name="input_vat_irt" class="ad-input" dir="ltr" value="{{ old('input_vat_irt','0') }}" inputmode="numeric"></label><label>{{ __('finance.vat_status') }}<select name="vat_status" class="ad-input">@foreach(['none','eligible'] as $status)<option value="{{ $status }}" @selected(old('vat_status','none')===$status)>{{ __('finance.'.$status) }}</option>@endforeach</select></label><p class="acc-muted acc-wide">{{ __('finance.vat_help') }}</p></div></details>
<details><summary>{{ __('finance.assets') }}</summary><div class="acc-form">@foreach(['asset_name','asset_serial','in_service_at','useful_life_months','residual_irt'] as $field)<label>{{ __('finance.'.$field) }}@if($field==='in_service_at')<x-admin-jdate :name="$field" :value="old($field)" />@else<input name="{{ $field }}" class="ad-input" type="text" value="{{ old($field,$field==='residual_irt'?'0':'') }}">@endif</label>@endforeach<p class="acc-muted acc-wide">{{ __('finance.asset_help') }}</p></div></details>
<button class="acc-btn" type="submit">{{ __('finance.save') }}</button>
</form></section>

<section class="acc-panel ff-supplier" id="supplier_debit">
<h2>{{ __('finance.supplier_debit') }}</h2>
<p class="acc-muted">{{ __('finance.supplier_debit_help') }}</p>
<form method="post" action="{{ route('admin.accounting.store') }}" enctype="multipart/form-data" class="acc-form" data-supplier-debit>
@csrf
<input type="hidden" name="workflow" value="supplier_debit">
<input type="hidden" name="idempotency_key" value="{{ old('workflow') === 'supplier_debit' ? old('idempotency_key', (string)\Illuminate\Support\Str::uuid()) : (string)\Illuminate\Support\Str::uuid() }}">
<label>{{ __('finance.supplier_account') }}<select name="funding_account_id" class="ad-input" required><option value="">{{ __('finance.select_account') }}</option>@foreach($accounts->where('type','vendor') as $a)<option value="{{ $a->id }}" @selected(old('workflow') === 'supplier_debit' && (string)old('funding_account_id') === (string)$a->id)>#{{ $a->id }} · {{ $a->name }} · {{ $a->currency_code }}</option>@endforeach</select></label>
<label>{{ __('finance.existing_entry') }}<input name="business_entry_id" class="ad-input" type="number" min="1" required dir="ltr" value="{{ old('workflow') === 'supplier_debit' ? old('business_entry_id') : '' }}"><small>{{ __('finance.supplier_existing_entry_help') }}</small></label>
<label>{{ __('finance.date') }}<x-admin-jdate name="occurred_at" class="ad-input" :value="old('workflow') === 'supplier_debit' ? old('occurred_at', now('Asia/Tehran')->toDateString()) : now('Asia/Tehran')->toDateString()" required /></label>
<label>{{ __('finance.service_line') }}<select name="service_line" class="ad-input" required>@foreach(\App\Services\Finance\DocumentedAccounting::SERVICES as $line)<option value="{{ $line }}" @selected(old('workflow') === 'supplier_debit' && old('service_line') === $line)>{{ __('finance.'.$line) }}</option>@endforeach</select></label>
<label>{{ __('finance.supplier_debit_currency') }}<select name="original_currency" class="ad-input" required>@foreach(array_keys(\App\Services\Finance\AccountingMoney::EXPONENTS) as $code)<option @selected(old('workflow') === 'supplier_debit' && old('original_currency') === $code)>{{ $code }}</option>@endforeach</select></label>
<label>{{ __('finance.supplier_debit_amount') }}<input name="original_amount" class="ad-input" dir="ltr" inputmode="decimal" required maxlength="32" value="{{ old('workflow') === 'supplier_debit' ? old('original_amount') : '' }}"></label>
<label>{{ __('finance.supplier_debit_reference') }}<input name="bank_reference" class="ad-input" dir="ltr" minlength="4" maxlength="150" required value="{{ old('workflow') === 'supplier_debit' ? old('bank_reference') : '' }}"></label>
<label>{{ __('finance.related_voucher') }}<input name="related_voucher_id" class="ad-input" type="number" min="1" dir="ltr" value="{{ old('workflow') === 'supplier_debit' ? old('related_voucher_id') : '' }}"><small>{{ __('finance.supplier_related_help') }}</small></label>
@foreach(['vendor','invoice_number'] as $field)<label>{{ __('finance.'.($field === 'vendor' ? 'vendor_name' : $field)) }}<input name="{{ $field }}" class="ad-input" maxlength="150" value="{{ old('workflow') === 'supplier_debit' ? old($field) : '' }}"></label>@endforeach
<label class="acc-wide">{{ __('finance.note') }}<textarea name="note" class="ad-input" maxlength="2000" rows="3">{{ old('workflow') === 'supplier_debit' ? old('note') : '' }}</textarea></label>
<label class="acc-wide">{{ __('finance.supplier_debit_receipt') }}<input name="evidence" class="ad-input" type="file" accept=".pdf,.jpg,.jpeg,.png" required><small>{{ __('finance.supplier_debit_receipt_help') }}</small></label>
<button class="acc-btn" type="submit">{{ __('finance.supplier_debit_submit') }}</button>
</form>
</section>
<section class="acc-panel" id="assets"><h2>{{ __('finance.assets') }}</h2><p class="acc-muted">{{ __('finance.asset_help') }}</p><div class="acc-scroll"><table class="acc-table"><thead><tr><th>{{ __('finance.asset_name') }}</th><th>{{ __('finance.cost') }}</th><th>{{ __('finance.in_service_at') }}</th><th>{{ __('finance.depreciation') }}</th></tr></thead><tbody>@forelse($assets as $asset)<tr><td>{{ $asset->name }}<br>{{ $asset->serial }}</td><td class="acc-num">{{ $money($asset->cost_irt) }}</td><td class="acc-num">{{ \App\Support\FinanceDates::display($asset->in_service_at->toDateString()) }}</td><td><form method="post" action="{{ route('admin.accounting.depreciate',$asset) }}">@csrf<label>{{ __('finance.period') }}<x-admin-jdate name="period_date" :value="old('period_date')" required /><small>{{ __('finance.depreciation_date_help') }}</small></label><button class="acc-btn">{{ __('finance.save') }}</button></form></td></tr>@empty<tr><td colspan="4">{{ __('finance.no_records') }}</td></tr>@endforelse</tbody></table></div>{{ $assets->links() }}</section>
<section class="acc-panel" id="tax"><h2>{{ __('finance.tax') }}</h2><p class="acc-muted">{{ __('finance.profile_help') }}</p>@if($r['profile'])<p class="acc-tag">{{ $r['profile']->legal_name }} · {{ \App\Support\FinanceDates::display($r['profile']->from_date->toDateString()) }} — {{ \App\Support\FinanceDates::display($r['profile']->to_date->toDateString()) }} · {{ $r['profile']->income_rate_bp/100 }}% · {{ __('finance.'.($r['profile']->reviewed?'reviewed':'pending')) }}</p>@endif
<form method="post" action="{{ route('admin.accounting.tax-profile') }}" class="acc-form">@csrf
@foreach(['from_date','to_date','legal_name','income_rate','loss_relief_irt','other_addbacks_irt','vat_carry_irt','legal_basis'] as $field)<label>{{ __('finance.'.($field==='from_date'?'from':($field==='to_date'?'to':$field))) }}@if(in_array($field,['from_date','to_date']))<x-admin-jdate :name="$field" :value="old($field)" required />@else<input name="{{ $field }}" type="text" class="ad-input" value="{{ old($field,in_array($field,['loss_relief_irt','other_addbacks_irt','vat_carry_irt'])?'0':'') }}" required>@endif</label>@endforeach
<label>{{ __('finance.vat_output_reviewed') }} (IRT)<input name="vat_output_irt" class="ad-input" dir="ltr" value="{{ old('vat_output_irt') }}" inputmode="numeric"><small>{{ __('finance.vat_output_help') }}</small></label><label>{{ __('finance.entity_type') }}<select name="entity_type" class="ad-input"><option value="corporate">{{ __('finance.corporate') }}</option><option value="individual">{{ __('finance.individual') }}</option></select></label><label><span><input type="checkbox" name="reviewed" value="1" style="width:auto"> {{ __('finance.reviewed') }}</span></label><button class="acc-btn">{{ __('finance.save') }}</button></form>
<details style="margin-top:20px"><summary>{{ __('finance.close_period') }}</summary><p class="acc-muted">{{ __('finance.lock_help') }} @if($closedThrough) · {{ \App\Support\FinanceDates::display($closedThrough) }} @endif</p><form method="post" action="{{ route('admin.accounting.close-period') }}" class="acc-form" data-confirm="{{ __('finance.close_period') }}?">@csrf<label>{{ __('finance.through') }}<x-admin-jdate name="through" class="ad-input" required /></label><label>{{ __('finance.reason') }}<input name="reason" class="ad-input" minlength="5" maxlength="255" required></label><button class="acc-btn">{{ __('finance.close_period') }}</button></form></details>
</section>
@endif
<section class="acc-panel" id="analytics"><h2>{{ __('finance.analytics') }} — ROI / NPV / IRR</h2><p class="acc-muted">{{ __('finance.analytics_help') }}</p>
<form method="post" action="{{ route('admin.accounting.investment') }}" class="acc-form">@csrf<label>{{ __('finance.discount_rate') }}<input name="discount_rate" class="ad-input" type="number" min="-99.99" max="10000" step="0.01" value="{{ old('discount_rate','0') }}" required></label><label>{{ __('finance.currency') }}<select name="currency" class="ad-input">@foreach(array_keys(\App\Services\Finance\AccountingMoney::EXPONENTS) as $code)<option @selected(old('currency')===$code)>{{ $code }}</option>@endforeach</select></label><div class="acc-wide acc-form" data-flow-builder><label>{{ __('finance.date') }}<x-admin-jdate name="flow_date" /></label><label>{{ __('finance.flow_amount') }}<input class="ad-input" data-flow-amount dir="ltr" inputmode="decimal"></label><button type="button" class="acc-btn" data-flow-add>{{ __('finance.add_flow') }}</button><span data-flow-error role="status" class="acc-muted"></span></div><label class="acc-wide">{{ __('finance.cash_flows') }}<textarea name="cash_flows" class="ad-input" rows="6" dir="ltr" placeholder="1404/10/11,-1000.00&#10;1405/10/11,1200.00" required>{{ old('cash_flows') }}</textarea></label><button class="acc-btn">{{ __('finance.calculate') }}</button></form>
@if(session('investment'))@php $i=session('investment'); $ex=\App\Services\Finance\AccountingMoney::EXPONENTS[$i['currency']]; @endphp<div class="acc-grid" style="margin-top:16px"><div class="acc-card"><small>ROI</small><b>{{ $i['roi_percent']===null?__('finance.undefined'):$i['roi_percent'].'%' }}</b></div><div class="acc-card"><small>XNPV</small><b class="acc-num">{{ $i['npv']===null?__('finance.undefined'):number_format($i['npv']/(10**$ex),$ex).' '.$i['currency'] }}</b></div><div class="acc-card"><small>XIRR</small><b>{{ $i['irr_percent']===null?__('finance.undefined'):$i['irr_percent'].'%' }}</b></div></div>@endif
</section>
<section class="acc-panel" id="register"><h2>{{ __('finance.register') }}</h2><div class="acc-scroll"><table class="acc-table"><thead><tr><th>#</th><th>{{ __('finance.date') }}</th><th>{{ __('finance.type') }}</th><th>{{ __('finance.vendor_name') }}</th><th>{{ __('finance.service_line') }}</th><th>{{ __('finance.amount_irt') }}</th><th>{{ __('finance.tax_status') }}</th><th></th></tr></thead><tbody>@forelse($vouchers as $v)<tr><td>{{ $v->id }}</td><td class="acc-num">{{ \App\Support\FinanceDates::display($v->occurred_at->toDateString()) }}</td><td>{{ __('finance.'.$v->type) }}@if($v->reversal_of)<br><small>{{ __('finance.reversal') }} #{{ $v->reversal_of }}</small>@endif</td><td>{{ $v->vendor }}<br><small>{{ $v->invoice_number }}</small></td><td>{{ __('finance.'.$v->service_line) }}</td><td class="acc-num">{{ $money($v->amount_irt) }}<br><small>{{ \App\Services\Finance\AccountingMoney::display($v->original_minor,$v->original_currency) }}</small></td><td><span class="acc-tag">{{ __('finance.'.$v->tax_status) }}</span></td><td><a href="{{ route('admin.accounting.voucher',$v) }}">{{ __('finance.view') }}</a></td></tr>@empty<tr><td colspan="8">{{ __('finance.no_records') }}</td></tr>@endforelse</tbody></table></div>@if($ready){{ $vouchers->links() }}@endif</section>
@endsection

@section('scripts')
<script src="{{ asset_ver('assets/js/finance-dates.js') }}" defer></script>
<script src="{{ asset_ver('assets/js/site-select.js') }}" defer></script>
@endsection
