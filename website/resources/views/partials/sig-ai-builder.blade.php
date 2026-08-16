{{-- سازنده سایت با هوش مصنوعی --}}
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

    <div class="aib reveal"
         data-chat="{{ route($routePrefix.'builder.chat') }}"
         data-save="{{ route($routePrefix.'builder.save') }}"
         data-domaincheck="{{ route($routePrefix.'domain.check') }}"
         data-cart="{{ whmcs_url('cart.php') }}">
      {{-- ستون گفتگو --}}
      <div class="aib-chat">
        <div class="aib-messages" id="aib-messages">
          <div class="aib-msg bot">{{ __('ui.aib_hello') }}</div>
          <div class="aib-examples" id="aib-examples">
            @foreach(['aib_ex1', 'aib_ex2', 'aib_ex3'] as $ex)
            <button type="button" class="aib-chip">{{ __('ui.'.$ex) }}</button>
            @endforeach
          </div>
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

      {{-- ستون پیش‌نمایش --}}
      <div class="aib-preview">
        <div class="aib-bar">
          <div class="aib-dots"><i></i><i></i><i></i></div>
          <div class="aib-devices">
            <button type="button" class="aib-dev active" data-w="100%" aria-label="Desktop"><svg class="icon"><use href="#i-monitor"/></svg></button>
            <button type="button" class="aib-dev" data-w="390px" aria-label="Mobile"><svg class="icon"><use href="#i-smartphone"/></svg></button>
          </div>
          <div class="aib-actions">
            <button type="button" class="aib-icon" id="aib-refresh" title="{{ __('ui.aib_reset') }}"><svg class="icon"><use href="#i-restore"/></svg></button>
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
          </div>
          <iframe id="aib-frame" title="preview" sandbox="allow-same-origin" hidden></iframe>
        </div>
      </div>
    </div>

    {{-- نوار دپلوی (بعد از ساخت سایت ظاهر می‌شود) --}}
    <div class="aib-deploy reveal" id="aib-deploy" hidden>
      <div class="aib-deploy-head">
        <span class="dc-icon"><svg class="icon"><use href="#i-rocket"/></svg></span>
        <div><h3>{{ __('ui.aib_deploy_t') }}</h3><p>{{ __('ui.aib_deploy_d') }}</p></div>
      </div>
      <div class="aib-deploy-grid">
        <label class="aib-field">
          <span>{{ __('ui.aib_deploy_domain') }}</span>
          <input type="text" id="aib-domain" dir="ltr" placeholder="mysite.ir" autocomplete="off" spellcheck="false">
          <small id="aib-domain-price"></small>
        </label>
        <label class="aib-field">
          <span>{{ __('ui.aib_deploy_plan') }}</span>
          <select id="aib-plan">
            @foreach($plans as $p)
            <option value="{{ $p['pid'] }}" data-irt="{{ $p['irt'] }}" data-eur="{{ $p['eur'] }}" @if(($p['popular'] ?? false)) selected @endif>
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
  {{-- ⚠️ @json نه {{ }} — رشتهٔ کوتیشن‌دار داخل {{ }} به &#039; تبدیل می‌شود و
       کل این بلوک SyntaxError می‌گیرد؛ یعنی AIB_I18N ساخته نمی‌شود و builder.js
       روی I.fa می‌میرد — چت، استعلام دامنه و دپلوی هر سه بی‌صدا از کار می‌افتند. --}}
  currency: @json($isFa ? 'تومان' : '€'), faNum: {{ $isFa ? 'true' : 'false' }},
};
</script>
<script src="{{ asset_ver('assets/js/builder.js') }}" defer></script>
