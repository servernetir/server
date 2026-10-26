{{-- Presentation of the existing unpaid request; amounts and actions retain their original sources. --}}
<section class="pnl-sec cr-request" data-gpu-capacity-wait aria-labelledby="gpu-capacity-heading">
  <div class="cr-request-head">
    <span class="cr-eyebrow">{{ __('ui.gpu_request_status') }}</span>
    <span class="cr-assurance"><svg class="icon" aria-hidden="true"><use href="#i-shield"/></svg>{{ __('ui.gpu_request_unbilled') }}</span>
  </div>

  <div class="cr-request-body">
    <div class="cr-intro">
      <span class="cr-state-icon" aria-hidden="true"><svg class="icon"><use href="#i-clock"/></svg></span>
      <div>
        <h2 id="gpu-capacity-heading">{{ __('ui.gpu_order_'.$capacityDisplayState) }}</h2>
        <p class="cr-lead">{{ __('ui.gpu_wait_no_charge') }}</p>
      </div>
    </div>

    <dl class="cr-summary">
      <div class="cr-fact">
        <dt>{{ __('ui.gpu_approved_price') }}</dt>
        <dd><bdi class="pnl-num">{{ cloud_price($service->billing_mode === 'hourly' ? $service->hourly_rate_irt : $service->price) }}</bdi>@if($service->billing_mode === 'hourly')<span class="cr-unit">{{ __('ui.cvb_hourly_per') }}</span>@endif</dd>
      </div>
      @if(($capacityOrder['state'] ?? '') === 'insufficient_credit')
        <div class="cr-fact cr-fact-required">
          <dt>{{ __('ui.gpu_request_required_credit') }}</dt>
          <dd><bdi class="pnl-num">{{ cloud_price($capacityOrder['minimum_credit_irt'] ?? 0) }}</bdi></dd>
        </div>
      @endif
    </dl>

    @if($capacityDisplayState === 'waiting_capacity')
      <p class="cr-notice">{{ __('ui.gpu_wait_delayed') }}</p>
    @endif
    <div class="cr-next">
      <span class="cr-next-icon" aria-hidden="true"><svg class="icon"><use href="#i-server"/></svg></span>
      <div>
        <h3>{{ __('ui.gpu_request_next') }}</h3>
        <p>{{ __($service->billing_mode === 'hourly' ? 'ui.gpu_wait_auto' : 'ui.gpu_wait_cycle') }}</p>
      </div>
    </div>

    @if(($capacityOrder['state'] ?? '') === 'price_changed' && ($capacityOrder['new_price_irt'] ?? 0) > 0)
      <form class="cr-price-review" method="post" action="{{ lroute('account.cloud.gpu-price', $service) }}">
        @csrf
        <input type="hidden" name="quoted_price_irt" value="{{ (int) $capacityOrder['new_price_irt'] }}">
        <div>
          <h3>{{ __('ui.gpu_request_new_price') }}</h3>
          <p>{{ __('ui.gpu_price_change_note') }}</p>
          <div class="cr-new-price"><bdi class="pnl-num">{{ cloud_price($capacityOrder['new_price_irt']) }}</bdi>@if($service->billing_mode === 'hourly')<span class="cr-unit">{{ __('ui.cvb_hourly_per') }}</span>@endif</div>
        </div>
        <button class="pnl-btn primary cr-primary" type="submit">{{ __('ui.gpu_confirm_price') }}</button>
      </form>
    @endif

    @if(($capacityOrder['state'] ?? '') === 'insufficient_credit')
      <div class="cr-actions">
        <a class="pnl-btn primary cr-primary" href="{{ lroute('account.topup') }}"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>{{ __('ui.gpu_topup') }}</a>
        <p>{{ __('ui.gpu_request_credit_hint') }}</p>
      </div>
    @endif
  </div>

  <div class="cr-request-foot">
    <svg class="icon" aria-hidden="true"><use href="#i-info"/></svg>
    <p>{{ __('ui.gpu_wait_leave') }}</p>
  </div>
</section>
