{{-- امضای «جستجوی زنده دامنه» — همان کامپوننت صفحه اصلی --}}
<div class="sig-panel reveal">
  <div class="section-head" style="margin-bottom:28px">
    <span class="badge">{{ __('ui.hp_sig_badge') }}</span>
    <h2>{{ lc($sig)['t'] }}</h2>
    <p>{{ lc($sig)['d'] }}</p>
  </div>
  <div class="domain-box" style="max-width:600px;margin:0 auto">
    <form class="domain-search" id="domain-form"
          data-endpoint="{{ route($routePrefix.'domain.check') }}"
          action="{{ whmcs_url('cart.php') }}" method="get" target="_blank" rel="noopener">
      <input type="hidden" name="a" value="add">
      <input type="hidden" name="domain" value="register">
      <svg class="icon"><use href="#i-search"/></svg>
      <input name="query" id="domain-input" type="text" placeholder="{{ __('ui.domain_ph') }}" aria-label="Domain" required
             autocomplete="off" spellcheck="false"
             data-i18n-checking="{{ __('ui.domain_checking') }}"
             data-i18n-free="{{ __('ui.domain_free') }}"
             data-i18n-taken="{{ __('ui.domain_taken') }}"
             data-i18n-suggest="{{ __('ui.domain_suggest') }}"
             data-i18n-cart="{{ __('ui.domain_cart') }}"
             data-i18n-year="{{ __('ui.domain_year') }}"
             data-i18n-error="{{ __('ui.domain_error') }}">
      <button class="btn btn-primary" type="submit">{{ __('ui.domain_btn') }}</button>
    </form>
    <div class="domain-result" id="domain-result" hidden></div>
    <div class="tld-strip" id="tld-strip">
      @foreach($tlds as $t)
      <button type="button" class="tld-chip" data-tld="{{ $t['tld'] }}"><b>{{ $t['tld'] }}</b><i>{{ $t['display'] ?? site_price($t) }}</i></button>
      @endforeach
    </div>
  </div>
</div>
