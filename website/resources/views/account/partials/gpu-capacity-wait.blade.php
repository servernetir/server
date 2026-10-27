{{-- Funding information is read-only; expiry is decided by the existing server queue. --}}
@php
  $fundingWindow = \App\Services\Cloud\GpuCapacityOrders::fundingWindow($service);
  $requestNeedsFunding = $fundingWindow && ! $fundingWindow['funded'];
  $requiredCredit = $fundingWindow['required_credit'] ?? ($capacityOrder['minimum_credit_irt'] ?? 0);
@endphp
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
          <dd><bdi class="pnl-num">{{ cloud_price($requiredCredit) }}</bdi></dd>
        </div>
      @endif
    </dl>

    @if($requestNeedsFunding)
      @php
        $requestDeadline = $fundingWindow['deadline']->copy()->utc();
        $remainingMinutes = max(0, (int) ceil(($requestDeadline->getTimestampMs() - now()->getTimestampMs()) / 60000));
      @endphp
      <section class="cr-deadline" data-gpu-request-expiry
               data-deadline-ms="{{ $requestDeadline->getTimestampMs() }}"
               data-server-now-ms="{{ now()->getTimestampMs() }}"
               data-locale="{{ app()->getLocale() }}"
               data-remaining-format="{{ __('ui.gpu_request_deadline_remaining') }}"
               data-passed-label="{{ __('ui.gpu_request_deadline_passed') }}"
               aria-labelledby="gpu-request-deadline-heading">
        <div class="cr-deadline-heading">
          <svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg>
          <h3 id="gpu-request-deadline-heading">{{ __('ui.gpu_request_deadline_heading') }}</h3>
        </div>
        <p>{{ __('ui.gpu_request_deadline_note', ['hours' => fa_num($fundingWindow['hours'])]) }}</p>
        <dl class="cr-deadline-details">
          <div>
            <dt>{{ __('ui.gpu_request_deadline_at') }}</dt>
            <dd><time datetime="{{ $requestDeadline->toIso8601String() }}">{{ stime($requestDeadline) }}</time><span>{{ __('ui.gpu_request_deadline_timezone') }}</span></dd>
          </div>
          @if(($capacityOrder['state'] ?? '') !== 'insufficient_credit')
            <div>
              <dt>{{ __('ui.gpu_request_required_credit') }}</dt>
              <dd><bdi class="pnl-num">{{ cloud_price($requiredCredit) }}</bdi></dd>
            </div>
          @endif
        </dl>
        <p class="cr-deadline-countdown" role="timer" aria-live="off" data-expiry-countdown>{{ $remainingMinutes > 0 ? __('ui.gpu_request_deadline_remaining', ['hours' => fa_num(intdiv($remainingMinutes, 60)), 'minutes' => fa_num($remainingMinutes % 60)]) : __('ui.gpu_request_deadline_passed') }}</p>
      </section>
    @endif

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

    @if($service->billing_mode === 'hourly')
      <section class="cr-wallet-policy" data-hourly-wallet-policy aria-labelledby="gpu-wallet-policy-heading">
        <h3 id="gpu-wallet-policy-heading"><svg class="icon" aria-hidden="true"><use href="#i-coins"/></svg>{{ __('ui.gpu_request_wallet_heading') }}</h3>
        <ul role="list">
          <li>
            <strong>{{ __('ui.gpu_request_wallet_start_heading') }}</strong>
            <p>{{ __('ui.gpu_request_wallet_start', ['hours' => fa_num(\App\Models\CloudPlan::HOURLY_START_MIN_HOURS)]) }}</p>
          </li>
          <li>
            <strong>{{ __('ui.gpu_request_wallet_billing_heading') }}</strong>
            <p>{{ __('ui.gpu_request_wallet_billing') }}</p>
          </li>
          <li>
            <strong>{{ __('ui.gpu_request_wallet_reuse_heading') }}</strong>
            <p>{{ __('ui.gpu_request_wallet_reuse') }}</p>
          </li>
        </ul>
      </section>
    @endif

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
