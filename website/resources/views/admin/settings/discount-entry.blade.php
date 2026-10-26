@php($discountEntryPolicy = app(\App\Services\Marketing\DiscountEntryVisibility::class))
<section class="ad-panel" id="discount-entry">
  <div class="ad-panel-h"><h2>{{ __('marketing.visibility_title') }}</h2></div>
  <form method="post" action="/admin/settings/discount-entry" style="padding:0 18px 18px">
    @csrf
    <input type="hidden" name="enabled" value="0">
    <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
      <input type="checkbox" name="enabled" value="1" @checked((bool) old('enabled', $discountEntryPolicy->defaultEnabled()))>
      <span>{{ __('marketing.visibility_default') }}</span>
    </label>
    <p class="set-lead" style="padding:12px 0">{{ __('marketing.visibility_help') }}</p>
    <button class="btn btn-primary" type="submit">{{ __('marketing.visibility_save') }}</button>
  </form>
</section>
