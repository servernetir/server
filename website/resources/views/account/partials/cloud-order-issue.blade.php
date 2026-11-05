{{-- Only a safe issue code enters this view; never print infrastructure errors. --}}
@once
  @push('head')
    <link rel="stylesheet" href="{{ asset_ver('assets/css/cloud-order-issue.css') }}">
  @endpush
@endonce
@php
  $issueCapacity = $orderIssue === 'capacity';
  $issuePrefix = $issueCapacity ? 'cloud-order.capacity' : 'cloud-order.review';
  $issueStoreUrl = lroute('account.cloud.store').($issueService->isHourly() ? '?billing_mode=hourly' : '');
@endphp
<section class="co-issue {{ ($compact ?? false) ? 'co-issue-compact' : '' }}" data-cloud-order-issue="{{ $orderIssue }}" role="status" aria-live="polite" aria-atomic="true">
  <div class="co-issue-icon" aria-hidden="true">
    <svg class="icon"><use href="#i-{{ $issueCapacity ? 'cloud' : 'lifebuoy' }}"/></svg>
  </div>
  <div class="co-issue-content">
    <span class="co-issue-eyebrow">{{ __('cloud-order.eyebrow') }}</span>
    <h2>{{ __($issuePrefix.'.title') }}</h2>
    <p>{{ __($issuePrefix.'.body') }}</p>
    <div class="co-issue-next">
      <b>{{ __('cloud-order.next') }}</b>
      <p>{{ __($issuePrefix.'.next') }}</p>
    </div>
    @if($issueService->isHourly())
      <p class="co-issue-assurance"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg>{{ __('cloud-order.hourly') }}</p>
    @endif
    <div class="co-issue-actions">
      @if($issueCapacity)
        <a class="pnl-btn primary" href="{{ $issueStoreUrl }}" data-cloud-choose-another><svg class="icon" aria-hidden="true"><use href="#i-server"/></svg>{{ __('cloud-order.choose') }}</a>
        <a class="co-issue-support" href="{{ lroute('account.tickets') }}">{{ __('cloud-order.support') }}</a>
      @else
        <a class="pnl-btn" href="{{ lroute('account.tickets') }}">{{ __('cloud-order.support') }}</a>
      @endif
    </div>
  </div>
</section>
