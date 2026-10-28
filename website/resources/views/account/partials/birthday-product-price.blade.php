@php
  $birthdayPrice = auth('customer')->user()
      ? app(\App\Services\Birthday\BirthdayCatalog::class)->product($birthdayProduct, auth('customer')->user())
      : null;
@endphp
@if($birthdayPrice)
<div class="birthday-price" data-birthday-product-price>
  <span>{{ __('birthday.base_price') }}</span>
  <s>{{ invoice_money($birthdayPrice['regular'], $birthdayPrice['currency']) }}</s>
  <strong>{{ invoice_money($birthdayPrice['price'], $birthdayPrice['currency']) }}</strong>
  <small>{{ \App\Models\Service::labelFor($birthdayPrice['cycle']) }} · {{ __('birthday.tax_separate') }}</small>
</div>
@endif
