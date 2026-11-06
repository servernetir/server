@php
  $birthdayCustomer = auth('customer')->user();
  $birthdayReward = $birthdayCustomer ? app(\App\Services\Birthday\BirthdayProgram::class)->current($birthdayCustomer) : null;
@endphp
@if($birthdayReward)
<aside class="birthday-notice" data-birthday-notice="{{ $birthdayReward->id }}" role="status" aria-labelledby="birthday-title">
  <span class="birthday-notice-icon" aria-hidden="true">🎂</span>
  <div class="birthday-notice-body">
    <h2 id="birthday-title">{{ __('birthday.title', ['name' => app(\App\Services\Birthday\BirthdayProgram::class)->firstName($birthdayCustomer)]) }}</h2>
    <p>{{ __('birthday.notice') }}</p>
    <small>{{ __('birthday.valid_until') }}
      <time datetime="{{ $birthdayReward->expires_at->toIso8601String() }}">{{ blog_date($birthdayReward->expires_at->copy()->setTimezone('Asia/Tehran')->toDateString()) }} · {{ $birthdayReward->expires_at->copy()->setTimezone('Asia/Tehran')->format('H:i') }}</time>
    </small>
  </div>
  <a class="pnl-btn" href="{{ lroute('account.store') }}">{{ __('birthday.shop') }}</a>
</aside>
@endif
