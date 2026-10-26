@if(($checkoutRecovery ?? collect())->isNotEmpty())
<section class="pnl-sec" aria-label="{{ __('conversion.checkout_recovery.title') }}">
  <div class="pnl-sec-h"><h2>{{ __('conversion.checkout_recovery.title') }}</h2></div>
  <div class="pnl-sec-b"><p style="margin:0;color:var(--muted);font-size:13px;line-height:1.9">{{ __('conversion.checkout_recovery.intro') }}</p></div>
  <ul class="pnl-todo">
    @foreach($checkoutRecovery as $order)
      <li>
        <span class="pnl-todo-ic"><svg class="icon"><use href="#i-box"/></svg></span>
        <span class="pnl-todo-t">
          <b>{{ $order['service_name'] }}</b>
          <small>{{ $order['cycle_label'] }} · <span dir="ltr">{{ $order['number'] }}</span> · {{ invoice_money($order['total'], $order['currency_code']) }}</small>
          @if($order['failed_payment'])<small>{{ __('conversion.checkout_recovery.failed_payment') }}</small>@endif
        </span>
        <a class="pnl-btn primary" href="{{ lroute('account.invoice', $order['invoice_id']) }}">{{ __('conversion.checkout_recovery.resume') }}</a>
      </li>
    @endforeach
  </ul>
  <div class="pnl-sec-b"><p style="margin:0;color:var(--muted);font-size:12px;line-height:1.8">{{ __('conversion.checkout_recovery.note') }}</p></div>
</section>
@endif
