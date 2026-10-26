@if(auth()->user()->isAdmin())
@php
  $refundManager = app(\App\Services\Finance\WalletRefunds::class);
  $refundReady = $refundManager->ready();
  $refundEnabled = $refundReady && $refundManager->enabled($c->id);
  $customerRefunds = $refundReady ? \App\Models\WalletRefundRequest::where('customer_id', $c->id)->latest('id')->limit(10)->get() : collect();
@endphp
<div class="ad-panel" id="wallet-refund-permission">
  <div class="ad-panel-h"><h3>{{ __('refund.permission') }}</h3></div>
  <div style="padding:16px;display:grid;gap:12px">
    @if($refundReady)
      <form method="post" action="{{ route('admin.wallet-refunds.permission', $c) }}" style="display:grid;gap:12px">
        @csrf
        <input type="hidden" name="_wallet_refund_permission" value="1">
        @if($errors->getBag('walletRefundPermission')->any())<p class="ad-note err" role="alert">{{ $errors->getBag('walletRefundPermission')->first() }}</p>@endif
        <fieldset style="display:flex;gap:18px;flex-wrap:wrap;border:0;padding:0;margin:0">
          <legend style="font-size:12px;color:var(--muted)">{{ __('refund.permission') }}</legend>
          <label><input type="radio" name="enabled" value="0" @checked(!$refundEnabled)> {{ __('refund.off') }}</label>
          <label><input type="radio" name="enabled" value="1" @checked($refundEnabled)> {{ __('refund.enabled') }}</label>
        </fieldset>
        <label>{{ __('refund.permission_reason') }}<input class="ad-input" name="reason" required minlength="5" maxlength="1000"></label>
        <div><button class="btn btn-primary">{{ __('refund.save') }}</button></div>
      </form>
      <small style="color:var(--muted)">{{ __('refund.permission_help') }}</small>
      <a href="{{ route('admin.wallet-refunds', ['customer' => $c->id]) }}">{{ __('refund.queue') }}</a>
      @foreach($customerRefunds as $r)
        <div style="border-top:1px solid var(--line);padding-top:9px">
          {{ number_format($r->amount_irt) }} {{ __('refund.toman') }} · {{ __('refund.status_'.$r->status) }}
          <a href="{{ route('admin.wallet-refunds.show', $r) }}">{{ __('refund.manage') }}</a>
        </div>
      @endforeach
    @else
      <p>{{ __('refund.not_ready') }}</p>
    @endif
  </div>
</div>
@endif
