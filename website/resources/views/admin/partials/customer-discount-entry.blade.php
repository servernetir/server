@if(auth()->user()?->isAdmin())
@php
  $discountEntryPolicy = app(\App\Services\Marketing\DiscountEntryVisibility::class);
  $discountEntryMode = old('visibility', $c->getAttribute('discount_entry_visibility') ?: 'inherit');
@endphp
<section class="ad-panel" id="customer-discount-entry">
  <div class="ad-panel-h"><h3>{{ __('marketing.visibility_title') }}</h3></div>
  <form method="post" action="/admin/customers/{{ $c->id }}/discount-entry" style="padding:0 18px 18px">
    @csrf
    <fieldset style="border:0;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:12px 22px">
      <legend style="font-size:12px;color:var(--muted);margin-bottom:12px">{{ __('marketing.visibility_customer_help') }}</legend>
      @foreach(\App\Services\Marketing\DiscountEntryVisibility::MODES as $mode)
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="radio" name="visibility" value="{{ $mode }}" @checked($discountEntryMode === $mode)>
          <span>{{ __('marketing.visibility_'.$mode) }}</span>
        </label>
      @endforeach
    </fieldset>
    <p style="font-size:12px;color:var(--muted);margin:14px 0">{{ __('marketing.visibility_effective') }}: <b>{{ $discountEntryPolicy->enabled($c) ? __('marketing.visibility_enabled') : __('marketing.visibility_disabled') }}</b></p>
    <button class="btn btn-primary" type="submit">{{ __('marketing.visibility_save') }}</button>
  </form>
</section>
@endif
