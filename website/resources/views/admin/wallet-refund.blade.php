@extends('admin.layout')
@section('title', __('refund.admin_title'))
@section('nav_wallet_refunds','on')
@section('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/site-select.css') }}">
<link rel="stylesheet" href="{{ asset_ver('assets/css/admin-wallet-refund.css') }}">
@endsection
@section('scripts')
<script src="{{ asset_ver('assets/js/site-select.js') }}" defer></script>
@endsection
@section('content')
@php
  $snapshot = $refund->bank_snapshot ?? [];
  $fundingSnapshot = $fundingSnapshot ?? null;
  $fundingAccount = $fundingAccount ?? null;
  $receiptDocument = $receiptDocument ?? null;
  $fundingLegacyFallback = $fundingLegacyFallback ?? ($refund->status === 'paid' && !$fundingSnapshot);
  $displayFunding = $fundingSnapshot ?: ($fundingAccount ? $fundingAccount->only(['id','name','type','currency_code','owner_name']) : null);
  $isOpen = in_array($refund->status, \App\Models\WalletRefundRequest::OPEN, true);
  $step = $refund->status === 'paid' ? 3 : ($refund->status === 'approved' ? 2 : 1);
  $oldForm = old('_refund_form', 'approve');
@endphp
<div class="ar-workspace" data-refund-status="{{ $refund->status }}">
  <header class="ar-header">
    <div>
      <nav class="ar-breadcrumb" aria-label="{{ __('refund.admin_navigation') }}">
        <a href="{{ route('admin.wallet-refunds') }}">{{ __('refund.queue') }}</a>
        <span aria-hidden="true">/</span>
        <span>{{ __('refund.manage') }}</span>
      </nav>
      <p class="ar-eyebrow">{{ __('refund.admin_eyebrow') }}</p>
      <h1>{{ __('refund.admin_title') }}</h1>
      <p class="ar-intro">{{ __('refund.admin_intro') }}</p>
    </div>
    <a class="btn btn-glass ar-customer-link" href="/admin/customers/{{ $refund->customer_id }}#finance">
      <svg class="icon" aria-hidden="true"><use href="#i-user"/></svg>
      {{ __('refund.customer') }} <bdi>{{ $refund->customer->code }}</bdi>
    </a>
  </header>

  @if($errors->any())
    <div class="ar-alert ar-alert-error" role="alert">
      <strong>{{ __('refund.admin_validation') }}</strong>
      <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
  @endif
  @if(session('ok'))<p class="ar-alert ar-alert-success" role="status">{{ session('ok') }}</p>@endif

  <section class="ar-summary" aria-label="{{ __('refund.admin_summary') }}">
    <div class="ar-amount">
      <span class="ar-label">{{ __('refund.amount') }}</span>
      <p><bdi>{{ number_format($refund->amount_irt) }}</bdi><small>{{ __('refund.toman') }}</small></p>
      <span class="ar-status ar-status-{{ $refund->status }}"><i aria-hidden="true"></i>{{ __('refund.status_'.$refund->status) }}</span>
    </div>
    <dl class="ar-summary-meta">
      <div><dt>{{ __('refund.request_id') }}</dt><dd><bdi class="ar-mono" dir="ltr">{{ $refund->public_id }}</bdi></dd></div>
      <div><dt>{{ __('refund.date') }}</dt><dd>{{ sdate($refund->created_at) }}</dd></div>
    </dl>
    <ol class="ar-steps" aria-label="{{ __('refund.workspace_process') }}">
      @foreach(['admin_step_request','admin_step_review','admin_step_transfer'] as $index => $stepKey)
        <li class="{{ $step > $index + 1 ? 'is-complete' : ($step === $index + 1 ? 'is-current' : '') }}" @if($step === $index + 1) aria-current="step" @endif>
          <span class="ar-step-mark" aria-hidden="true">@if($step > $index + 1)<svg class="icon"><use href="#i-check"/></svg>@else{{ $index + 1 }}@endif</span>
          <span>{{ __('refund.'.$stepKey) }}</span>
        </li>
      @endforeach
    </ol>
  </section>

  <div class="ar-columns">
    <div class="ar-main">
      @if($refund->status === 'requested')
        <section class="ar-card" id="ar-provenance" aria-labelledby="ar-review-title">
          <div class="ar-card-heading">
            <span class="ar-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-shield"/></svg></span>
            <div><p class="ar-eyebrow">{{ __('refund.admin_step_review') }}</p><h2 id="ar-review-title">{{ __('refund.admin_review_title') }}</h2></div>
          </div>
          <p class="ar-help">{{ __('refund.admin_review_help') }}</p>
          <form method="post" action="{{ route('admin.wallet-refunds.approve', $refund) }}" class="ar-form" id="ar-approve-form" data-refund-form="approve">
            @csrf
            <input type="hidden" name="_refund_form" value="approve">
            <div class="ar-field">
              <label for="ar-review-note">{{ __('refund.review_note') }} <span class="ar-required" aria-hidden="true">*</span></label>
              <textarea class="ad-input" id="ar-review-note" name="note" rows="3" required minlength="5" maxlength="2000" aria-describedby="ar-review-note-help">{{ $oldForm === 'approve' ? old('note') : '' }}</textarea>
              <small id="ar-review-note-help">{{ __('refund.admin_review_note_help') }}</small>
            </div>
            <fieldset class="ar-fieldset">
              <legend>{{ __('refund.admin_incoming_title') }}</legend>
              <p class="ar-help" id="ar-source-help">{{ __('refund.admin_incoming_help') }}</p>
              <div class="ar-field">
                <label for="ar-source-payment">{{ __('refund.source_payment') }}</label>
                <select class="ad-input" id="ar-source-payment" name="source_payment_id" aria-describedby="ar-source-help">
                  <option value="">{{ __('refund.admin_source_placeholder') }}</option>
                  @foreach($payments as $payment)
                    <option value="{{ $payment->id }}" @selected((string)old('source_payment_id') === (string)$payment->id)>#{{ $payment->id }} · {{ number_format($payment->amount) }} IRT · {{ $payment->gateway }}</option>
                  @endforeach
                </select>
              </div>
              <div class="ar-field">
                <label for="ar-source-note">{{ __('refund.source_note') }} <span class="ar-required" aria-hidden="true">*</span></label>
                <textarea class="ad-input" id="ar-source-note" name="source_note" rows="4" required minlength="10" maxlength="2000" aria-describedby="ar-source-note-help">{{ old('source_note') }}</textarea>
                <small id="ar-source-note-help">{{ __('refund.source_help') }}</small>
              </div>
            </fieldset>
            <div class="ar-form-footer"><p>{{ __('refund.admin_approval_hint') }}</p><button class="btn btn-primary" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('refund.approve') }}</button></div>
          </form>
        </section>
      @endif

      @if($refund->status === 'approved')
        <section class="ar-card ar-card-primary" id="ar-payout" aria-labelledby="ar-payout-title">
          <div class="ar-card-heading">
            <span class="ar-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-coins"/></svg></span>
            <div><p class="ar-eyebrow">{{ __('refund.admin_step_transfer') }}</p><h2 id="ar-payout-title">{{ __('refund.record_paid') }}</h2></div>
          </div>
          <p class="ar-callout"><svg class="icon" aria-hidden="true"><use href="#i-info"/></svg><span>{{ __('refund.no_transfer_api') }}</span></p>
          <form method="post" action="{{ route('admin.wallet-refunds.paid', $refund) }}" enctype="multipart/form-data" class="ar-form" id="ar-paid-form" data-refund-form="paid" data-confirm="{{ __('refund.transferred') }}" data-confirm-danger>
            @csrf
            <input type="hidden" name="_refund_form" value="paid">
            <fieldset class="ar-fieldset">
              <legend>{{ __('refund.admin_outgoing_title') }}</legend>
              <div class="ar-field">
                <label for="ar-funding-account">{{ __('refund.funding_account') }} <span class="ar-required" aria-hidden="true">*</span></label>
                <select class="ad-input" id="ar-funding-account" name="funding_account_id" required aria-describedby="ar-funding-help ar-funding-types">
                  <option value="">{{ __('refund.admin_funding_placeholder') }}</option>
                  @foreach(['bank','partner'] as $accountType)
                    @if($accounts->where('type', $accountType)->isNotEmpty())
                      <optgroup label="{{ __('refund.admin_funding_'.$accountType) }}">
                        @foreach($accounts->where('type', $accountType) as $account)
                          <option value="{{ $account->id }}" @selected((string)old('funding_account_id') === (string)$account->id)>#{{ $account->id }} · {{ $account->name }}@if($account->owner_name) · {{ $account->owner_name }}@endif · {{ $account->currency_code }}</option>
                        @endforeach
                      </optgroup>
                    @endif
                  @endforeach
                </select>
                <small id="ar-funding-help">{{ __('refund.admin_funding_help') }}</small>
              </div>
              <div class="ar-funding-types" id="ar-funding-types">
                <div><strong>{{ __('refund.admin_funding_bank') }}</strong><p>{{ __('refund.admin_bank_help') }}</p></div>
                <div><strong>{{ __('refund.admin_funding_partner') }}</strong><p>{{ __('refund.admin_partner_help') }}</p></div>
              </div>
              <p class="ar-account-link">@if($accounts->isEmpty())<strong>{{ __('refund.admin_no_accounts') }}</strong> @endif<a href="{{ route('admin.accounting') }}#accounts">{{ __('refund.admin_manage_accounts') }} <span aria-hidden="true">↗</span></a><span>{{ __('refund.admin_account_examples') }}</span></p>
            </fieldset>
            <div class="ar-field-grid">
              <div class="ar-field">
                <label for="ar-payout-date">{{ __('refund.payout_date') }} <span class="ar-required" aria-hidden="true">*</span></label>
                <x-admin-jdate id="ar-payout-date" class="ad-input" name="payout_date" :value="old('payout_date', now('Asia/Tehran')->toDateString())" required />
              </div>
              <div class="ar-field">
                <label for="ar-bank-reference">{{ __('refund.reference') }} <span class="ar-required" aria-hidden="true">*</span></label>
                <input class="ad-input" id="ar-bank-reference" name="bank_reference" required minlength="4" maxlength="150" dir="ltr" value="{{ old('bank_reference') }}" autocomplete="off">
              </div>
            </div>
            <div class="ar-field">
              <label for="ar-bank-row">{{ __('refund.bank_row') }}</label>
              <input class="ad-input ar-short-input" id="ar-bank-row" name="bank_transaction_id" type="number" min="1" dir="ltr" value="{{ old('bank_transaction_id') }}" aria-describedby="ar-bank-row-help">
              <small id="ar-bank-row-help">{{ __('refund.admin_bank_row_help') }}</small>
            </div>
            <div class="ar-upload">
              <span class="ar-upload-icon" aria-hidden="true"><svg class="icon"><use href="#i-paperclip"/></svg></span>
              <div class="ar-field">
                <label for="ar-evidence">{{ __('refund.receipt_upload') }} <span class="ar-required" aria-hidden="true">*</span></label>
                <input class="ad-input" id="ar-evidence" name="evidence" type="file" accept=".pdf,.jpg,.jpeg,.png" required aria-describedby="ar-evidence-help">
                <small id="ar-evidence-help">{{ __('refund.admin_receipt_help') }}</small>
              </div>
            </div>
            <label class="ar-check" for="ar-transferred"><input id="ar-transferred" type="checkbox" name="transferred" value="1" required @checked(old('transferred'))><span>{{ __('refund.transferred') }}</span></label>
            <div class="ar-form-footer"><p>{{ __('refund.admin_payout_hint') }}</p><button class="btn btn-primary" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('refund.record_paid') }}</button></div>
          </form>
        </section>
      @endif

      @if($refund->status === 'paid')
        <section class="ar-card ar-card-paid" id="ar-documents" aria-labelledby="ar-documents-title" data-funding-evidence="{{ $fundingSnapshot ? 'snapshot' : ($displayFunding ? 'legacy' : 'missing') }}">
          <div class="ar-card-heading"><span class="ar-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-check"/></svg></span><div><p class="ar-eyebrow">{{ __('refund.status_paid') }}</p><h2 id="ar-documents-title">{{ __('refund.admin_documents_title') }}</h2></div></div>
          <p class="ar-help">{{ __('refund.admin_documents_help') }}</p>
          <dl class="ar-facts ar-evidence-facts">
            <div><dt>{{ __('refund.funding_account') }}</dt><dd>@if($displayFunding)<strong>{{ $displayFunding['name'] }}</strong>@if(!empty($displayFunding['owner_name']))<span>{{ $displayFunding['owner_name'] }}</span>@endif<span class="ar-meta-line">{{ __('refund.admin_funding_'.($displayFunding['type'] === 'partner' ? 'partner' : 'bank')) }} · <bdi dir="ltr">{{ $displayFunding['currency_code'] }}</bdi> · #<bdi>{{ $displayFunding['id'] }}</bdi></span>@else{{ __('refund.admin_funding_missing') }}@endif</dd></div>
            <div><dt>{{ __('refund.reference') }}</dt><dd><bdi class="ar-mono" dir="ltr">{{ $refund->bank_reference }}</bdi></dd></div>
            <div><dt>{{ __('refund.payout_date') }}</dt><dd>{{ sdate($refund->payout_date) }}</dd></div>
            <div><dt>{{ __('refund.admin_recorded_at') }}</dt><dd>{{ $refund->paid_at ? sdate($refund->paid_at) : '—' }}</dd></div>
            @if($receiptDocument)<div class="ar-full"><dt>{{ __('refund.admin_document_fingerprint') }}</dt><dd><bdi class="ar-hash ar-mono" dir="ltr" data-receipt-sha>{{ $receiptDocument->sha256 }}</bdi><small>{{ $receiptDocument->mime }} · {{ number_format($receiptDocument->size) }} B</small></dd></div>@endif
          </dl>
          @if($fundingLegacyFallback)<p class="ar-callout ar-callout-warning"><svg class="icon" aria-hidden="true"><use href="#i-info"/></svg><span>{{ __('refund.admin_legacy_funding') }}</span></p>@else<p class="ar-evidence-caption"><svg class="icon" aria-hidden="true"><use href="#i-lock"/></svg>{{ __('refund.admin_snapshot_help') }}</p>@endif
          <div class="ar-document-links">
            @if($refund->voucher_id)<a class="btn btn-primary" href="{{ route('admin.accounting.voucher', $refund->voucher_id) }}"><svg class="icon" aria-hidden="true"><use href="#i-book"/></svg>{{ __('refund.voucher') }}</a>@endif
            @if($refund->document_id)<a class="btn btn-glass" href="{{ route('admin.accounting.download', $refund->document_id) }}"><svg class="icon" aria-hidden="true"><use href="#i-file"/></svg>{{ __('refund.receipt') }}</a>@endif
          </div>
        </section>
      @endif

      @if($refund->status !== 'requested')
        <section class="ar-card" id="ar-provenance" aria-labelledby="ar-provenance-title">
          <div class="ar-card-heading"><span class="ar-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-shield"/></svg></span><div><h2 id="ar-provenance-title">{{ __('refund.admin_review_record') }}</h2><p class="ar-help">{{ __('refund.admin_incoming_help') }}</p></div></div>
          <dl class="ar-facts">
            <div><dt>{{ __('refund.source_payment') }}</dt><dd>@if($refund->source_payment_id)<bdi dir="ltr">#{{ $refund->source_payment_id }}</bdi>@else{{ __('refund.admin_no_source_payment') }}@endif</dd></div>
            @if($refund->reviewed_at)<div><dt>{{ __('refund.admin_reviewed_at') }}</dt><dd>{{ sdate($refund->reviewed_at) }}</dd></div>@endif
            @if($refund->review_note)<div class="ar-full"><dt>{{ __('refund.review_note') }}</dt><dd class="ar-prewrap">{{ $refund->review_note }}</dd></div>@endif
            @if($refund->source_note)<div class="ar-full"><dt>{{ __('refund.source_note') }}</dt><dd class="ar-prewrap">{{ $refund->source_note }}</dd></div>@endif
          </dl>
        </section>
      @endif

      @if($isOpen)
        <details class="ar-card ar-reject" @if($oldForm === 'reject' && $errors->any()) open @endif>
          <summary><span>{{ __('refund.reject') }}</span><small>{{ __('refund.admin_reject_help') }}</small></summary>
          <form method="post" action="{{ route('admin.wallet-refunds.reject', $refund) }}" class="ar-form" id="ar-reject-form" data-refund-form="reject">
            @csrf
            <input type="hidden" name="_refund_form" value="reject">
            <div class="ar-field"><label for="ar-reject-note">{{ __('refund.review_note') }} <span class="ar-required" aria-hidden="true">*</span></label><textarea class="ad-input" id="ar-reject-note" name="note" rows="3" required minlength="5" maxlength="2000">{{ $oldForm === 'reject' ? old('note') : '' }}</textarea></div>
            <label class="ar-check" for="ar-not-transferred"><input id="ar-not-transferred" type="checkbox" name="not_transferred" value="1" required @checked(old('not_transferred'))><span>{{ __('refund.not_transferred') }}</span></label>
            <div><button class="btn btn-danger" type="submit">{{ __('refund.reject') }}</button></div>
          </form>
        </details>
      @endif
    </div>

    <aside class="ar-sidebar">
      <section class="ar-card" id="ar-destination" aria-labelledby="ar-destination-title">
        <div class="ar-card-heading"><span class="ar-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-user"/></svg></span><div><p class="ar-eyebrow">{{ __('refund.admin_recipient') }}</p><h2 id="ar-destination-title">{{ __('refund.bank') }}</h2></div></div>
        <dl class="ar-facts ar-facts-single">
          <div><dt>{{ __('refund.admin_bank_name') }}</dt><dd>{{ $snapshot['bank_name'] ?? '—' }}</dd></div>
          <div><dt>{{ __('refund.admin_account_owner') }}</dt><dd>{{ $snapshot['owner_name'] ?? '—' }}</dd></div>
          <div><dt>{{ __('refund.admin_iban') }}</dt><dd class="ar-iban"><bdi class="ar-mono" dir="ltr">{{ $snapshot['iban'] ?? '—' }}</bdi></dd></div>
          @if($refund->customer_note)<div><dt>{{ __('refund.note') }}</dt><dd class="ar-prewrap">{{ $refund->customer_note }}</dd></div>@endif
        </dl>
      </section>
      <section class="ar-card ar-accounting-help" aria-labelledby="ar-accounting-title">
        <span class="ar-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-book"/></svg></span>
        <h2 id="ar-accounting-title">{{ __('refund.admin_accounting_title') }}</h2>
        <p>{{ __('refund.accounting_note') }}</p>
        <p>{{ __('refund.admin_accounting_partner') }}</p>
      </section>
    </aside>
  </div>

  <section class="ar-card ar-audit" id="ar-audit" aria-labelledby="ar-audit-title">
    <div class="ar-card-heading"><span class="ar-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-clock"/></svg></span><div><p class="ar-eyebrow">{{ __('refund.admin_evidence_trail') }}</p><h2 id="ar-audit-title">{{ __('refund.audit') }}</h2></div></div>
    <div class="ar-timeline">
      @forelse($events as $event)
        @php $eventDetails = json_decode($event->details, true) ?: []; @endphp
        <details class="ar-event">
          <summary><span class="ar-event-dot" aria-hidden="true"></span><strong>{{ $event->action === 'permission_changed' ? __('refund.permission') : __('refund.status_'.$event->action) }}</strong><time>{{ sdate($event->created_at) }}</time><span class="ar-event-actor">{{ $event->actor_name }}</span></summary>
          <div class="ar-event-body">
            @if(isset($eventDetails['enabled']))<p>{{ $eventDetails['enabled'] ? __('refund.enabled') : __('refund.off') }}</p>@endif
            @foreach(['note','reason','source_note'] as $detail)
              @if(!empty($eventDetails[$detail]))<p class="ar-prewrap">{{ $eventDetails[$detail] }}</p>@endif
            @endforeach
            @if(!empty($eventDetails['bank_reference']))<p>{{ __('refund.reference') }}: <bdi class="ar-mono" dir="ltr">{{ $eventDetails['bank_reference'] }}</bdi></p>@endif
            @if(!empty($eventDetails['funding_account_snapshot']))<p>{{ __('refund.funding_account') }}: {{ $eventDetails['funding_account_snapshot']['name'] ?? '' }} · {{ $eventDetails['funding_account_snapshot']['owner_name'] ?? '' }}</p>@endif
          </div>
        </details>
      @empty
        <p class="ar-help">{{ __('refund.admin_no_events') }}</p>
      @endforelse
    </div>
  </section>
</div>
@endsection
