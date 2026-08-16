@extends('panel.layout')
@section('title', __('ui.sec_h').' — ServerNet')

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">{{ __('ui.sec_h') }}</h1>
    <p>{{ __('ui.sec_sub') }}</p>
  </div>
</div>

@if(session('ok'))
  <div class="pnl-sec" style="border-color:var(--ok-line)">
    <div class="pnl-sec-b" style="color:var(--ok);font-size:13.5px;line-height:2">{{ session('ok') }}</div>
  </div>
@endif
@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)">
    <div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">
      @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
  </div>
@endif

{{-- ══ رمز عبور ══ --}}
<section class="pnl-sec" id="sec-pw">
  <div class="pnl-sec-h"><h2>{{ __('ui.sec_pw_h') }}</h2></div>
  <div class="pnl-sec-b">
    <p class="sec-note">{{ __('ui.sec_pw_note') }}</p>

    @if($pwReady)
      <form method="POST" action="{{ lroute('account.security.pw') }}" class="sec-form">
        @csrf
        <label>{{ __('ui.sec_pw_code') }}
          <input type="text" name="code" dir="ltr" inputmode="numeric" required autocomplete="one-time-code" placeholder="{{ __('ui.auth_code') }}">
        </label>
        <label>{{ __('ui.sec_pw_new') }}
          <input type="password" name="password" required minlength="8" placeholder="{{ __('ui.sec_min8') }}">
        </label>
        <label>{{ __('ui.sec_pw_repeat') }}
          <input type="password" name="password_confirmation" required>
        </label>
        <button class="pnl-btn primary" style="justify-content:center">{{ __('ui.sec_pw_submit') }}</button>
      </form>
    @else
      <form method="POST" action="{{ lroute('account.security.pw.start') }}">
        @csrf
        <p class="sec-note" style="margin-bottom:12px">{{ __('ui.sec_pw_sent', ['dest' => $customer->phone ? __('ui.sec_dest_mobile') : __('ui.sec_dest_email')]) }}</p>
        <button class="pnl-btn primary" style="justify-content:center">{{ $hasPassword ? __('ui.sec_pw_change') : __('ui.sec_pw_set') }}</button>
      </form>
    @endif
  </div>
</section>

{{-- ══ محدودسازی IP ══ --}}
<section class="pnl-sec" id="sec-ip">
  <div class="pnl-sec-h"><h2>{{ __('ui.sec_ip_h') }}</h2></div>
  <div class="pnl-sec-b">
    <p class="sec-note">{{ __('ui.sec_ip_note') }} {{ __('ui.sec_ip_current') }}: <b dir="ltr">{{ $currentIp }}</b></p>

    <form method="POST" action="{{ lroute('account.security.ipmode') }}">
      @csrf
      <div class="sec-modes">
        @foreach(['off'=>[__('ui.sec_ip_off'),__('ui.sec_ip_off_d')],'warn'=>[__('ui.sec_ip_warnl'),__('ui.sec_ip_warn_d')],'enforce'=>[__('ui.sec_ip_enf'),__('ui.sec_ip_enf_d')]] as $m => $info)
          <label class="sec-mode-opt {{ $ipMode === $m ? 'on' : '' }}">
            <input type="radio" name="mode" value="{{ $m }}" {{ $ipMode === $m ? 'checked' : '' }} onchange="this.form.submit()">
            <b>{{ $info[0] }}</b><small>{{ $info[1] }}</small>
          </label>
        @endforeach
      </div>
    </form>

    @if($ipMode === 'enforce')
      <p class="sec-warn">⚠️ {{ __('ui.sec_ip_enf_warn', ['ip' => $currentIp]) }}</p>
    @endif

    @if($ipRules->isNotEmpty())
      <div class="sec-rules">
        @foreach($ipRules as $r)
          <div class="sec-rule">
            <span class="sec-badge {{ $r->action === 'deny' ? 'deny' : 'allow' }}">{{ $r->action === 'deny' ? __('ui.sec_deny') : __('ui.sec_allow') }}</span>
            <span dir="ltr" class="sec-cidr">{{ $r->cidr }}</span>
            @if($r->label)<span class="sec-lbl">{{ $r->label }}</span>@endif
            <form method="POST" action="{{ lroute('account.security.ip.delete', $r) }}" data-confirm="{{ __('ui.sec_ip_del') }}" data-confirm-danger style="margin-inline-start:auto;display:flex">
              @csrf<button type="submit" class="sec-x" title="{{ __('ui.sec_deny') }}"><svg class="icon"><use href="#i-x"/></svg></button>
            </form>
          </div>
        @endforeach
      </div>
    @else
      <p class="sec-note" style="margin-top:12px">{{ __('ui.sec_ip_none') }}</p>
    @endif

    <form method="POST" action="{{ lroute('account.security.ip') }}" class="sec-form sec-inline">
      @csrf
      <label>{{ __('ui.sec_ip_field') }}
        <input type="text" name="cidr" dir="ltr" placeholder="1.2.3.4 / 1.2.3.0/24" required>
      </label>
      <label>{{ __('ui.sec_type') }}
        <select name="action"><option value="allow">{{ __('ui.sec_allow_opt') }}</option><option value="deny">{{ __('ui.sec_deny_opt') }}</option></select>
      </label>
      <label>{{ __('ui.sec_label') }}
        <input type="text" name="label" placeholder="{{ __('ui.sec_label_ph') }}" maxlength="64">
      </label>
      <button class="pnl-btn" style="justify-content:center">{{ __('ui.sec_add') }}</button>
    </form>
  </div>
</section>

{{-- ══ دسترسی API ══ --}}
<section class="pnl-sec" id="sec-api">
  <div class="pnl-sec-h"><h2>{{ __('ui.sec_api_h') }}</h2></div>
  <div class="pnl-sec-b">
    <p class="sec-note">{{ __('ui.sec_api_note') }}</p>

    @if(session('new_token'))
      <div class="sec-newtok">
        <b>{{ __('ui.sec_api_new') }}</b>
        <code dir="ltr" class="copyable" title="{{ __('ui.sec_copied') }}">{{ session('new_token') }}</code>
      </div>
    @endif

    @if($apiTokens->isNotEmpty())
      <div class="sec-tokens">
        @foreach($apiTokens as $t)
          <div class="sec-token">
            <div class="sec-token-t">
              <b>{{ $t->name }}</b>
              <small>{{ __('ui.sec_api_created') }} {{ stime($t->created_at) }}@if($t->last_used_at) · {{ __('ui.sec_api_lastuse') }} {{ stime($t->last_used_at) }}@else · {{ __('ui.sec_api_neveruse') }} @endif</small>
              @php
                /* دسترسی‌ها و محافظ‌ها روی خودِ کارت — بی‌این، کاربر نمی‌داند
                   کدام توکن اجازهٔ خرج‌کردن دارد و کدام محدود به IP است. */
                $tAb = array_values((array) ($t->abilities ?? []));
                $tCidr = array_values(array_filter((array) ($t->allowed_cidrs ?? [])));
              @endphp
              <small class="sec-token-meta">
                @foreach($tAb as $ab)
                  <span class="sec-chip @if(str_contains($ab, 'write')) danger @endif" dir="ltr">{{ $ab }}</span>
                @endforeach
                @if($tCidr)
                  <span class="sec-chip ok">IP: {{ implode(' ', array_slice($tCidr, 0, 3)) }}{{ count($tCidr) > 3 ? ' …' : '' }}</span>
                @else
                  <span class="sec-chip warn">{{ __('ui.sec_api_anyip') }}</span>
                @endif
                @if($t->expires_at)
                  <span class="sec-chip">{{ __('ui.sec_api_expires') }} {{ stime($t->expires_at) }}</span>
                @endif
              </small>
            </div>
            <form method="POST" action="{{ lroute('account.security.token.delete', $t) }}" data-confirm="{{ __('ui.sec_api_revoke_c') }}" data-confirm-danger style="margin-inline-start:auto">
              @csrf<button type="submit" class="sec-revoke">{{ __('ui.sec_api_revoke') }}</button>
            </form>
          </div>
        @endforeach
      </div>
    @else
      <p class="sec-note" style="margin-top:12px">{{ __('ui.sec_api_none') }}</p>
    @endif

    <form method="POST" action="{{ lroute('account.security.token') }}" class="sec-form">
      @csrf
      <label>{{ __('ui.sec_api_name') }}
        <input type="text" name="name" placeholder="{{ __('ui.sec_api_name_ph') }}" maxlength="80" required>
      </label>

      {{--
        دسترسی‌ها: هیچ‌کدام پیش‌فرض تیک نخورده‌اند و نبودِ انتخاب یعنی «فقط
        خواندن». دسترسی‌ای که پول خرج می‌کند باید **انتخاب** شود، نه اینکه
        کسی ناخواسته صاحبش شود.
      --}}
      <div class="sec-abilities">
        <span class="sec-lbl">{{ __('ui.sec_api_scopes') }}</span>
        @foreach(\App\Models\CustomerApiToken::ABILITIES as $key => $desc)
          <label class="sec-chk">
            <input type="checkbox" name="abilities[]" value="{{ $key }}" @checked($key === 'read')>
            <span><code dir="ltr">{{ $key }}</code> — {{ $desc }}</span>
          </label>
        @endforeach
      </div>

      <label>{{ __('ui.sec_api_cidrs') }}
        <input type="text" name="cidrs" dir="ltr" placeholder="185.10.20.30, 2001:db8::/64" maxlength="500">
        <small class="sec-note">{{ __('ui.sec_api_cidrs_h') }}</small>
      </label>

      <label>{{ __('ui.sec_api_expiry') }}
        <input type="number" name="expires_days" min="1" max="1825"
               value="{{ (int) config('domain_reseller.limits.token_default_days', 365) }}">
        <small class="sec-note">{{ __('ui.sec_api_expiry_h') }}</small>
      </label>

      <button class="pnl-btn primary" style="justify-content:center">{{ __('ui.sec_api_create') }}</button>
    </form>

    <details class="sec-doc">
      <summary>{{ __('ui.sec_api_example') }}</summary>
      <pre dir="ltr">curl -H "Authorization: Bearer sn_..." \
     https://servernet.cloud/api/v1/me</pre>
      <p class="sec-note">{{ __('ui.sec_api_endpoints') }} <code dir="ltr">/api/v1/me</code> · <code dir="ltr">/api/v1/services</code> · <code dir="ltr">/api/v1/invoices</code> · <code dir="ltr">/api/v1/credit</code></p>
    </details>
  </div>
</section>

<style>
.sec-note{ font-size:13px; color:var(--muted); line-height:2; margin:0 0 6px }
.sec-form{ display:flex; flex-direction:column; gap:12px; margin-top:14px; max-width:420px }
.sec-form.sec-inline{ flex-flow:row wrap; align-items:flex-end; max-width:none }
.sec-form label{ display:flex; flex-direction:column; gap:6px; font-size:12.5px; color:var(--muted); flex:1; min-width:150px }
.sec-form input, .sec-form select{ background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:10px 12px; font:inherit; font-size:13px; color:var(--text) }
.sec-warn{ font-size:12.5px; color:var(--warn); background:var(--warn-bg); border:1px solid var(--warn-line); border-radius:11px; padding:11px 14px; line-height:2; margin:12px 0 }
.sec-modes{ display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin:12px 0 }
@media(max-width:560px){ .sec-modes{ grid-template-columns:1fr } }
.sec-mode-opt{ cursor:pointer; border:1.5px solid var(--line); border-radius:13px; padding:12px 14px; display:flex; flex-direction:column; gap:3px; transition:.16s }
.sec-mode-opt input{ display:none }
.sec-mode-opt b{ font-size:13px; color:var(--text) }
.sec-mode-opt small{ font-size:11px; color:var(--muted) }
.sec-mode-opt.on{ border-color:var(--info); background:var(--info-bg) }
.sec-rules{ display:flex; flex-direction:column; gap:8px; margin:12px 0 }
.sec-rule{ display:flex; align-items:center; gap:10px; border:1px solid var(--line); border-radius:11px; padding:9px 12px; background:var(--surface) }
.sec-badge{ font-size:11px; font-weight:700; border-radius:20px; padding:3px 10px; flex:none }
.sec-badge.allow{ color:var(--ok); background:var(--ok-bg) }
.sec-badge.deny{ color:var(--danger); background:var(--danger-bg) }
.sec-cidr{ font-size:13px; color:var(--text); font-variant-numeric:tabular-nums }
.sec-lbl{ font-size:12px; color:var(--muted) }
.sec-x{ background:var(--danger-bg); border:1px solid var(--danger-line); color:var(--danger); border-radius:8px; padding:6px 8px; cursor:pointer; line-height:0; display:grid; place-items:center }
.sec-x .icon{ width:14px; height:14px }
.sec-newtok{ border:1px solid var(--ok-line); background:var(--ok-bg); border-radius:12px; padding:13px 15px; margin:12px 0; display:flex; flex-direction:column; gap:8px }
.sec-newtok b{ font-size:12.5px; color:var(--ok) }
.sec-newtok code{ direction:ltr; background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:9px 12px; font-size:12.5px; word-break:break-all; cursor:copy; color:var(--text) }
.sec-tokens{ display:flex; flex-direction:column; gap:8px; margin:12px 0 }
.sec-token{ display:flex; align-items:center; gap:12px; border:1px solid var(--line); border-radius:11px; padding:11px 14px; background:var(--surface) }
.sec-token-t{ display:flex; flex-direction:column; gap:2px; min-width:0 }
.sec-token-t b{ font-size:13px; color:var(--text) }
.sec-token-t small{ font-size:11px; color:var(--dim) }
.sec-revoke{ background:var(--danger-bg); border:1px solid var(--danger-line); color:var(--danger); border-radius:9px; padding:8px 13px; font:inherit; font-size:12.5px; font-weight:600; cursor:pointer; flex:none }
.sec-doc{ margin-top:16px; border-top:1px solid var(--line); padding-top:12px }
.sec-doc summary{ cursor:pointer; font-size:13px; color:var(--info) }
.sec-doc pre{ direction:ltr; background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:12px 14px; font-size:12px; overflow-x:auto; margin:10px 0; color:var(--text) }
.copyable{ cursor:copy }
</style>
<script>
document.querySelectorAll('.copyable').forEach(function (el) {
  el.addEventListener('click', function () {
    var t = (this.textContent || '').trim();
    if (navigator.clipboard) navigator.clipboard.writeText(t);
    if (window.snToast) snToast(@json(__('ui.sec_copied')), 'ok');
  });
});
</script>
@endsection
