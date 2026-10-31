@extends('panel.layout')
@section('title', __('refund.title'))

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/wallet-refunds.css') }}">
@endpush

@section('panel')
<div class="wr-workspace">
  <header class="wr-hero">
    <div class="wr-hero-copy">
      <span class="wr-eyebrow">{{ __('refund.workspace_eyebrow') }}</span>
      <h1>{{ __('refund.title') }}</h1>
      <p>{{ __('refund.workspace_intro') }}</p>
      <a class="wr-text-link" href="#wr-history">{{ __('refund.history') }} <span aria-hidden="true">↓</span></a>
    </div>
    <div class="wr-hero-icon" aria-hidden="true">
      <svg viewBox="0 0 48 48" fill="none"><path d="M10 13h28v26H10a4 4 0 0 1-4-4V13a4 4 0 0 1 4-4h24v4M38 22h5v10H29a5 5 0 0 1 0-10h9Z" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="30" cy="27" r="1.5" fill="currentColor"/></svg>
    </div>
  </header>

  @if($errors->any())
    <div class="wr-alert wr-alert-error" role="alert">
      <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
  @endif
  @if(session('ok'))
    <div class="wr-alert wr-alert-success" role="status">{{ session('ok') }}</div>
  @endif

  <div class="wr-metrics">
    <section class="wr-metric" aria-label="{{ __('refund.balance') }}">
      <span class="wr-metric-label">{{ __('refund.balance') }}</span>
      <div class="wr-money"><bdi dir="ltr">{{ number_format($balance) }}</bdi><small>{{ __('refund.toman') }}</small></div>
      <p>{{ __('refund.workspace_balance_hint') }}</p>
    </section>
    <section class="wr-metric wr-metric-available" aria-label="{{ __('refund.available') }}">
      <span class="wr-metric-label"><i aria-hidden="true"></i>{{ __('refund.available') }}</span>
      <div class="wr-money"><bdi dir="ltr">{{ number_format(max(0, $available)) }}</bdi><small>{{ __('refund.toman') }}</small></div>
      <p>{{ __('refund.workspace_available_hint') }}</p>
    </section>
  </div>

  <div class="wr-layout">
    <section class="wr-card wr-request" aria-labelledby="wr-request-title">
      <div class="wr-card-head">
        <span class="wr-step-mark" aria-hidden="true">01</span>
        <div><h2 id="wr-request-title">{{ __('refund.workspace_request') }}</h2><p>{{ __('refund.workspace_request_intro') }}</p></div>
      </div>
      @if(!$enabled)
        <div class="wr-state" data-refund-state="disabled">
          <span class="wr-state-symbol" aria-hidden="true">—</span>
          <h3>{{ __('refund.disabled') }}</h3>
          <p>{{ __('refund.permission_help') }}</p>
        </div>
      @elseif($pending)
        <div class="wr-state wr-state-pending" data-refund-state="pending">
          <span class="wr-state-symbol" aria-hidden="true">…</span>
          <h3>{{ __('refund.workspace_open_request') }}</h3>
          <p>{{ __('refund.pending_help') }}</p>
          <a class="pnl-btn" href="#wr-history">{{ __('refund.history') }}</a>
        </div>
      @elseif($banks->isEmpty())
        <div class="wr-state" data-refund-state="no-bank">
          <span class="wr-state-symbol" aria-hidden="true">+</span>
          <h3>{{ __('refund.bank') }}</h3>
          <p>{{ __('refund.no_bank') }}</p>
          <a class="pnl-btn primary" href="{{ lroute('account.bank') }}">{{ __('refund.bank_manage') }}</a>
        </div>
      @else
        <form class="wr-request-form" data-refund-state="form" method="post" action="{{ lroute('account.wallet-refunds.store') }}">
          @csrf
          <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
          <div class="wr-field">
            <label for="refund-amount">{{ __('refund.amount') }}</label>
            <input class="wr-input wr-amount-input" id="refund-amount" name="amount_irt" inputmode="numeric" dir="ltr" required maxlength="24" value="{{ old('amount_irt') }}"
                   aria-describedby="refund-amount-hint{{ $errors->has('amount_irt') ? ' refund-amount-error' : '' }}"
                   @if($errors->has('amount_irt')) aria-invalid="true" @endif>
            <small id="refund-amount-hint">{{ __('refund.workspace_amount_hint') }}</small>
            @error('amount_irt')<small class="wr-field-error" id="refund-amount-error">{{ $message }}</small>@enderror
          </div>
          <div class="wr-field">
            <label for="refund-bank">{{ __('refund.bank') }}</label>
            <select class="wr-input" id="refund-bank" name="bank_account_id" required
                    @error('bank_account_id') aria-invalid="true" aria-describedby="refund-bank-error" @enderror>
              <option value="">{{ __('refund.workspace_bank_placeholder') }}</option>
              @foreach($banks as $bank)
                <option value="{{ $bank->id }}" @selected(old('bank_account_id') == $bank->id)>{{ $bank->bankLabel() }} · {{ $bank->iban }} · {{ $bank->owner_name }}</option>
              @endforeach
            </select>
            @error('bank_account_id')<small class="wr-field-error" id="refund-bank-error">{{ $message }}</small>@enderror
            <a class="wr-text-link wr-bank-link" href="{{ lroute('account.bank') }}">{{ __('refund.bank_manage') }} <span aria-hidden="true">↗</span></a>
          </div>
          <div class="wr-field">
            <label for="refund-note">{{ __('refund.note') }}</label>
            <textarea class="wr-input" id="refund-note" name="note" required minlength="5" maxlength="2000" rows="4"
                      aria-describedby="refund-note-hint{{ $errors->has('note') ? ' refund-note-error' : '' }}"
                      @if($errors->has('note')) aria-invalid="true" @endif>{{ old('note') }}</textarea>
            <small id="refund-note-hint">{{ __('refund.workspace_note_hint') }}</small>
            @error('note')<small class="wr-field-error" id="refund-note-error">{{ $message }}</small>@enderror
          </div>
          <div>
            <label class="wr-consent" for="refund-ack">
              <input id="refund-ack" type="checkbox" name="acknowledge" value="1" required @checked(old('acknowledge'))
                     @error('acknowledge') aria-invalid="true" aria-describedby="refund-ack-error" @enderror>
              <span>{{ __('refund.acknowledge') }}</span>
            </label>
            @error('acknowledge')<small class="wr-field-error" id="refund-ack-error">{{ $message }}</small>@enderror
          </div>
          <button type="submit" class="pnl-btn primary wr-submit">{{ __('refund.submit') }} <span aria-hidden="true">←</span></button>
        </form>
      @endif
    </section>

    <aside class="wr-card wr-guide" aria-labelledby="wr-guide-title">
      <h2 id="wr-guide-title">{{ __('refund.workspace_process') }}</h2>
      <ol class="wr-steps">
        <li><span aria-hidden="true">1</span><div><h3>{{ __('refund.submit') }}</h3><p>{{ __('refund.workspace_step_request') }}</p></div></li>
        <li><span aria-hidden="true">2</span><div><h3>{{ __('refund.status_requested') }}</h3><p>{{ __('refund.workspace_step_review') }}</p></div></li>
        <li><span aria-hidden="true">3</span><div><h3>{{ __('refund.status_paid') }}</h3><p>{{ __('refund.workspace_step_paid') }}</p></div></li>
      </ol>
      <div class="wr-policy"><h3>{{ __('refund.workspace_conditions') }}</h3><p>{{ __('refund.help') }}</p></div>
    </aside>
  </div>

  <section class="wr-history" id="wr-history" aria-labelledby="wr-history-title">
    <div class="wr-section-head"><h2 id="wr-history-title">{{ __('refund.history') }}</h2><span class="wr-history-line" aria-hidden="true"></span></div>
    <div class="wr-history-list">
      @forelse($refunds as $r)
        @php
          $statusTone = match ($r->status) {
            'requested' => 'pending', 'approved' => 'approved', 'paid' => 'paid',
            'rejected' => 'rejected', default => 'neutral',
          };
        @endphp
        <article class="wr-history-card" data-status="{{ $r->status }}">
          <div class="wr-history-top">
            <div class="wr-money wr-history-money"><bdi dir="ltr">{{ number_format($r->amount_irt) }}</bdi><small>{{ __('refund.toman') }}</small></div>
            <span class="wr-status wr-status-{{ $statusTone }}"><i aria-hidden="true"></i>{{ __('refund.status_'.$r->status) }}</span>
          </div>
          <dl class="wr-details">
            <div><dt>{{ __('refund.request_id') }}</dt><dd><bdi dir="ltr">{{ $r->public_id }}</bdi></dd></div>
            <div><dt>{{ __('refund.date') }}</dt><dd>{{ sdate($r->created_at) }}</dd></div>
            <div class="wr-detail-wide"><dt>{{ __('refund.bank') }}</dt><dd><bdi dir="ltr">{{ $r->bank_snapshot['iban'] }}</bdi></dd></div>
          </dl>
          @if($r->review_note)
            <div class="wr-review-note"><h3>{{ __('refund.review_note') }}</h3><p>{{ $r->review_note }}</p></div>
          @endif
          @if($r->status === 'requested')
            <div class="wr-card-footer">
              <form method="post" action="{{ lroute('account.wallet-refunds.cancel', $r) }}">@csrf<button type="submit" class="pnl-btn wr-cancel">{{ __('refund.cancel') }}</button></form>
            </div>
          @endif
          @if($r->status === 'paid')
            <dl class="wr-details wr-payout">
              <div><dt>{{ __('refund.reference') }}</dt><dd><bdi dir="ltr">{{ $r->bank_reference }}</bdi></dd></div>
              <div><dt>{{ __('refund.payout_date') }}</dt><dd>{{ sdate($r->payout_date) }}</dd></div>
            </dl>
            <div class="wr-card-footer"><a class="pnl-btn wr-receipt" href="{{ lroute('account.wallet-refunds.receipt', $r) }}">{{ __('refund.receipt') }} <span aria-hidden="true">↓</span></a></div>
          @endif
        </article>
      @empty
        <div class="wr-card wr-empty"><span class="wr-empty-icon" aria-hidden="true">↗</span><h3>{{ __('refund.empty') }}</h3><p>{{ __('refund.workspace_empty_hint') }}</p></div>
      @endforelse
    </div>
    <div class="wr-pagination">{{ $refunds->links() }}</div>
  </section>
</div>
@endsection
