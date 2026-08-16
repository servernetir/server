{{-- سازنده سایت با هوش مصنوعی — پیش‌نمایش تمام‌عرض + چتِ شناورِ جابه‌جاشدنی --}}
@php
    $plans = $product['plans'];
    $defaultPlan = collect($plans)->firstWhere('popular', true) ?? $plans[0];
@endphp

<section class="section aib-section" id="ai-builder" style="padding-top:20px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge"><span class="pulse"></span>{{ __('ui.aib_badge') }}</span>
      <h2>{{ __('ui.aib_title') }}</h2>
      <p>{{ __('ui.aib_sub') }}</p>
    </div>

    {{-- ⚠️ data-stream با گاردِ Route::has: این ویو لحظهٔ آپلود زنده می‌شود ولی
         روتِ PHP تا ریستِ opcache ممکن است هنوز قدیمی باشد؛ بی‌گارد یعنی ۵۰۰
         روی کلِ صفحه در همان پنجره. مقدارِ خالی = builder.js خودش به مسیرِ
         JSONِ قدیمی برمی‌گردد. --}}
    {{-- data-checkout: تسویهٔ خودِ کنسول (هاست+دامنه+استقرارِ خودکار). گاردِ
         Route::has مثل data-stream؛ و اگر پکیجِ فعالِ سایت‌ساز در DB نباشد هم
         خالی می‌ماند تا builder.js به سبدِ WHMCS برگردد — دکمهٔ مرده ممنوع. --}}
    @php
      $sbCheckout = '';
      try {
          if (\Illuminate\Support\Facades\Route::has($routePrefix.'account.builder.checkout')
              && \Illuminate\Support\Facades\Schema::hasTable('products')
              && \App\Models\Product::where('group', 'site-builder')->where('is_active', true)->exists()) {
              $sbCheckout = route($routePrefix.'account.builder.checkout');
          }
      } catch (\Throwable $e) {
          $sbCheckout = '';
      }
    @endphp
    <div class="aib aib-wide reveal"
         data-chat="{{ route($routePrefix.'builder.chat') }}"
         data-stream="{{ \Illuminate\Support\Facades\Route::has($routePrefix.'builder.stream') ? route($routePrefix.'builder.stream') : '' }}"
         data-save="{{ route($routePrefix.'builder.save') }}"
         data-publish="{{ \Illuminate\Support\Facades\Route::has($routePrefix.'builder.publish') ? route($routePrefix.'builder.publish') : '' }}"
         data-domaincheck="{{ route($routePrefix.'domain.check') }}"
         data-checkout="{{ $sbCheckout }}"
         data-cart="{{ whmcs_url('cart.php') }}">

      {{-- پیش‌نمایش — حالا تمامِ عرض؛ چت رویش شناور است --}}
      <div class="aib-preview aib-preview-full">
        <div class="aib-bar">
          <div class="aib-dots"><i></i><i></i><i></i></div>
          <div class="aib-devices">
            <button type="button" class="aib-dev active" data-w="100%" aria-label="Desktop"><svg class="icon"><use href="#i-monitor"/></svg></button>
            <button type="button" class="aib-dev" data-w="390px" aria-label="Mobile"><svg class="icon"><use href="#i-smartphone"/></svg></button>
          </div>
          <div class="aib-actions">
            <button type="button" class="aib-icon" id="aib-refresh" title="{{ __('ui.aib_reset') }}"><svg class="icon"><use href="#i-restore"/></svg></button>
            <button type="button" class="aib-icon" id="aib-publish" title="{{ __('ui.aib_publish') }}" disabled><svg class="icon"><use href="#i-globe"/></svg></button>
            <button type="button" class="aib-icon" id="aib-full-btn" title="{{ __('ui.aib_full') }}" aria-pressed="false"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M16 3h3a2 2 0 0 1 2 2v3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/><path d="M8 21H5a2 2 0 0 1-2-2v-3"/></svg></button>
            <button type="button" class="aib-icon" id="aib-download" title="{{ __('ui.aib_download') }}" disabled><svg class="icon"><use href="#i-arrow"/></svg></button>
          </div>
        </div>
        <div class="aib-frame-wrap">
          <div class="aib-empty" id="aib-empty">
            <span class="aib-orb"><svg class="icon"><use href="#i-sparkles"/></svg></span>
            <p>{{ __('ui.aib_empty') }}</p>
          </div>
          <div class="aib-loading" id="aib-loading" hidden>
            <span class="aib-orb building"><svg class="icon"><use href="#i-sparkles"/></svg></span>
            <p id="aib-loading-txt">{{ __('ui.aib_building') }}</p>
            <div class="aib-progress"><span id="aib-progress-bar"></span></div>
            <div class="aib-progress-pct" id="aib-progress-pct">۰٪</div>
            <p class="aib-reassure" id="aib-reassure" hidden>{{ __('ui.aib_reassure') }}</p>
          </div>
          <iframe id="aib-frame" title="preview" sandbox="allow-same-origin" hidden></iframe>
        </div>
      </div>

      {{-- چتِ شناور: درگ با هدر، دکمهٔ کوچک‌سازی؛ builder.js مدیریتش می‌کند --}}
      <div class="aib-pop" id="aib-pop">
        <div class="aib-pop-head" id="aib-pop-head">
          <span class="aib-pop-title"><svg class="icon"><use href="#i-sparkles"/></svg>{{ __('ui.aib_pop_t') }}</span>
          <button type="button" class="aib-pop-min" id="aib-pop-min" title="{{ __('ui.aib_min') }}" aria-label="{{ __('ui.aib_min') }}">—</button>
        </div>
        <div class="aib-chat">
          <div class="aib-messages" id="aib-messages">
            <div class="aib-msg bot">{{ __('ui.aib_hello') }}</div>
          </div>
          <form class="aib-input" id="aib-form">
            <textarea id="aib-text" rows="1" placeholder="{{ __('ui.aib_ph') }}" maxlength="2000"></textarea>
            <button class="btn btn-primary" type="submit" aria-label="{{ __('ui.chat_send') }}">
              <svg class="icon dir" style="width:18px;height:18px"><use href="#i-send"/></svg>
            </button>
          </form>
          <div class="aib-hint">
            <label class="aib-pro"><input type="checkbox" id="aib-pro"><span>{{ __('ui.aib_pro') }}</span></label>
            <span class="aib-left" id="aib-left"></span>
          </div>
        </div>
      </div>

      {{-- دکمهٔ بازگردانی وقتی چت کوچک شده --}}
      <button type="button" class="aib-fab" id="aib-fab" hidden>
        <svg class="icon"><use href="#i-sparkles"/></svg><span>{{ __('ui.aib_fab') }}</span>
      </button>
    </div>

    {{-- نوار استقرار (بعد از ساخت سایت ظاهر می‌شود) --}}
    <div class="aib-deploy reveal" id="aib-deploy" hidden>
      <div class="aib-deploy-head">
        <span class="dc-icon"><svg class="icon"><use href="#i-rocket"/></svg></span>
        <div><h3>{{ __('ui.aib_deploy_t') }}</h3><p>{{ __('ui.aib_deploy_d') }}</p></div>
      </div>
      <div class="aib-deploy-grid">
        <label class="aib-field">
          <span>{{ __('ui.aib_deploy_domain') }}</span>
          <input type="text" id="aib-domain" dir="ltr" placeholder="mysite.com" autocomplete="off" spellcheck="false">
          <small id="aib-domain-price"></small>
        </label>
        <label class="aib-field">
          <span>{{ __('ui.aib_deploy_plan') }}</span>
          <select id="aib-plan">
            @foreach($plans as $p)
            {{-- data-sb = slugِ پکیجِ واقعی در DB (SeedBuilderProducts) — value همان pid کهنهٔ WHMCS برای fallback --}}
            <option value="{{ $p['pid'] }}" data-sb="site-builder-{{ $loop->iteration }}" data-irt="{{ $p['irt'] }}" data-eur="{{ $p['eur'] }}" @if(($p['popular'] ?? false)) selected @endif>
              {{ $p['name'] }} — {{ site_price($p) }} {{ __('ui.mo') }}
            </option>
            @endforeach
          </select>
        </label>
        <div class="aib-total">
          <span>{{ __('ui.aib_deploy_total') }}</span>
          <b id="aib-total-val">—</b>
          <small>{{ __('ui.aib_deploy_note') }}</small>
        </div>
      </div>
      <div class="aib-deploy-cta">
        <button class="btn btn-primary" id="aib-deploy-btn"><span>{{ __('ui.aib_deploy_btn') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></button>
        <p class="aib-deploy-ref" id="aib-deploy-ref" hidden></p>
      </div>
    </div>
  </div>
</section>

<script>
window.AIB_I18N = {
  fa: {{ $isFa ? 'true' : 'false' }},
  building: @json(__('ui.aib_building')), thinking: @json(__('ui.aib_thinking')),
  steps: [@json(__('ui.aib_step1')), @json(__('ui.aib_step2')), @json(__('ui.aib_step3')), @json(__('ui.aib_step4')), @json(__('ui.aib_step5'))],
  err: @json(__('ui.aib_err')), notConfigured: @json(__('ui.aib_not_configured')),
  limit: @json(__('ui.aib_limit')), busy: @json(__('ui.aib_busy')), left: @json(__('ui.aib_left')),
  domainChecking: @json(__('ui.domain_checking')), domainFree: @json(__('ui.domain_free')),
  domainTaken: @json(__('ui.domain_taken')), perMo: @json(__('ui.mo')), perYr: @json(__('ui.domain_year')),
  saved: @json(__('ui.aib_saved')), download: @json(__('ui.aib_download')),
  writing: @json(__('ui.aib_writing')),
  domainUnknown: @json(__('ui.aib_dom_unknown')), noIr: @json(__('ui.aib_no_ir')),
  needDomain: @json(__('ui.aib_need_domain')),
  done: @json(__('ui.aib_done')),
  pubDone: @json(__('ui.aib_pub_done')), pubErr: @json(__('ui.aib_pub_err')),
  fullT: @json(__('ui.aib_full')), fullExit: @json(__('ui.aib_full_exit')),
  qs: [@json(__('ui.aib_q_name')), @json(__('ui.aib_q_field')), @json(__('ui.aib_q_services')), @json(__('ui.aib_q_contact')), @json(__('ui.aib_q_color')), @json(__('ui.aib_q_extra'))],
  skip: @json(__('ui.aib_skip')),
  colors: [@json(__('ui.aib_c1')), @json(__('ui.aib_c2')), @json(__('ui.aib_c3')), @json(__('ui.aib_c4')), @json(__('ui.aib_c5'))],
  sum: @json(__('ui.aib_sum')),
  {{-- ⚠️ @json نه {{ }} — رشتهٔ کوتیشن‌دار داخل {{ }} به &#039; تبدیل می‌شود و
       کل این بلوک SyntaxError می‌گیرد؛ یعنی AIB_I18N ساخته نمی‌شود و builder.js
       روی I.fa می‌میرد — چت، استعلام دامنه و دپلوی هر سه بی‌صدا از کار می‌افتند. --}}
  currency: @json($isFa ? 'تومان' : '€'), faNum: {{ $isFa ? 'true' : 'false' }},
  unsold: @json(\App\Services\Domain\DomainSearch::UNSOLD_TLDS),
};
</script>
<script src="{{ asset_ver('assets/js/builder.js') }}" defer></script>
