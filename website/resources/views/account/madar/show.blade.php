@extends('panel.layout')
@section('title', $participation->title.' — '.__('madar.nav'))
@push('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/madar.css') }}">@endpush
@section('panel')
<div class="mdr">
  <a class="mdr-text-link" href="{{ lroute('account.madar') }}">{{ __('madar.back') }}</a>
  <header class="mdr-case-head"><span class="mdr-eyebrow">{{ __('madar.campaigns.'.$participation->campaign_key.'.title') }}</span><h1>{{ $participation->title }}</h1><span class="mdr-badge is-open">{{ __('madar.statuses.'.$participation->status) }}</span></header>
  @include('madar.feedback')
  <div class="mdr-workspace">
    <div>
      @include('madar.mission')
    @include('madar.thread', ['admin' => false])
      @if(in_array($participation->status, ['working', 'changes_requested']))
      <section class="mdr-form-card mdr-single"><h2>{{ __('madar.evidence') }}</h2><p>{{ __('madar.submit_hint') }}</p>
        <form method="post" enctype="multipart/form-data" action="{{ lroute('account.madar.submit', $participation) }}">

@csrf
<label>{{ __('madar.body') }}<textarea name="body" required minlength="5" maxlength="5000" rows="4">{{ old('body') }}</textarea></label>
          <label>{{ __('madar.evidence_url') }}<input type="url" name="evidence_url" dir="ltr" maxlength="1500" placeholder="https://" value="{{ old('evidence_url') }}"></label>
          @if((int) $participation->flow_version === 2)
            <label>{{ __('madar.distribution_url') }}<input type="url" name="distribution_url" dir="ltr" maxlength="1500" placeholder="https://" value="{{ old('distribution_url') }}"><small>{{ __('madar.distribution_hint') }}</small></label>
          @endif
          <label>{{ __('madar.attachment') }}<input type="file" name="attachment" accept="image/jpeg,image/png,image/webp"><small>{{ __('madar.attachment_hint') }}</small></label>
          <label class="mdr-check"><input type="checkbox" name="rights_confirmed" value="1" required><span>{{ __('madar.rights') }}</span></label>
          @if($participation->reward_snapshot['mission']['mission']['public_content'] ?? config('madar.campaigns.'.$participation->campaign_key.'.public_content'))<label class="mdr-check"><input type="checkbox" name="disclosure_confirmed" value="1" required><span>{{ __('madar.disclosure') }}</span></label>
@endif

          <p class="mdr-small">{{ __('madar.reuse') }}</p>
          <button class="pnl-btn primary" type="submit">{{ __('madar.submit') }}</button>
        </form>
      </section>

@endif

    </div>
    <aside class="mdr-case-aside">
      @include('madar.reward')
      @if($participation->status === 'requested')<div class="mdr-notice">{{ __('madar.reward_hint') }} {{ __('madar.proposal_only') }}</div>
@endif

      @if($participation->status === 'offered')
      <form class="mdr-action-card" method="post" action="{{ lroute('account.madar.accept', $participation) }}">
@csrf

        @if($participation->work_due_at?->isFuture())<label class="mdr-check"><input type="checkbox" name="accept_offer" value="1" required><span>{{ __('madar.accept_offer') }}</span></label><button class="pnl-btn primary" type="submit">{{ __('madar.accept') }}</button>@else<p>{{ __('madar.expired') }}</p>
@endif

      </form>

@endif

      @if($participation->status === 'rewarded')
      <section class="mdr-action-card"><h2>{{ __('madar.redeem') }}</h2>
        @if($redemption && in_array($redemption->status, ['reserved', 'committed', 'refunded', 'reconciliation']))
          <p>{{ __('madar.redemptions.'.$redemption->status) }}</p><a class="pnl-btn" href="{{ lroute('account.invoice', $redemption->invoice_id) }}">{{ __('madar.invoice') }}</a>
        @elseif($participation->grant?->expires_at?->isFuture() && ! $participation->grant?->revoked_at)
          @if($invoices->isNotEmpty())<form method="post" action="{{ lroute('account.madar.redeem', $participation) }}">
@csrf
<label>{{ __('madar.choose_invoice') }}<select name="invoice_id" required>@foreach($invoices as $invoice)<option value="{{ $invoice->id }}">{{ $invoice->number }} · {{ invoice_money($invoice->total, $invoice->currency_code) }}</option>
@endforeach
</select></label><button class="pnl-btn primary" type="submit">{{ __('madar.apply_reward') }}</button></form>@else<p>{{ __('madar.no_invoice') }}</p>
@endif

        @else<p>{{ __('madar.errors.expired') }}</p>
@endif

      </section>

@endif

      @if(in_array($participation->status, \App\Models\MadarParticipation::OPEN))
      <details class="mdr-action-card"><summary>{{ __('madar.cancel') }}</summary><p>{{ __('madar.cancel_hint') }}</p><form method="post" action="{{ lroute('account.madar.cancel', $participation) }}">
@csrf
<label>{{ __('madar.cancel_reason') }}<textarea name="body" rows="2" required minlength="5" maxlength="1000"></textarea></label><button class="pnl-btn" type="submit">{{ __('madar.cancel') }}</button></form></details>

@endif

      <small>{{ __('madar.terms_version', ['version' => $participation->terms_version]) }}</small>
    </aside>
  </div>
</div>
@endsection
