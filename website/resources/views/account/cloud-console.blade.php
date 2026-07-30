@extends('panel.layout')
@section('title', __('ui.vnc_title'))

{{-- کنسولِ زندهٔ سرور، **داخلِ پنلِ خودمان**.

     noVNC خودمیزبان است (public/assets/js/novnc) چون CSP هر منبعِ بیرونی را
     بی‌صدا بلاک می‌کند. آدرسِ اتصال در HTML نیست؛ جاوااسکریپت آن را از یک
     پاسخِ JSON same-origin و **یک‌بارمصرف** می‌گیرد. --}}

@section('panel')

@php
  $specs = (array) ($instance->specs ?? []);

  // لینکِ «صفحهٔ سرور» برای متنِ نکته‌ها؛ در @php ساخته می‌شود تا route داخلِ
  // رشتهٔ زبان نرود و {!! !!} امن بماند.
  $serverPageLink = '<a href="'.e(route('account.cloud.show', $service)).'" style="color:var(--info)">'.e(__('ui.vnc_link_server_page')).'</a>';

  // متونِ داینامیکِ JS (پیام‌های وضعیت/خطای اتصال) — از همین‌جا سرور-رندر و به
  // window.T داده می‌شوند تا هیچ متنِ فارسیِ سخت‌کد در جاوااسکریپت نماند.
  $vncT = [
    'disconnected' => __('ui.vnc_state_disconnected'),
    'connected'    => __('ui.vnc_state_connected'),
    'err_expired'  => __('ui.vnc_err_expired'),
    'err_connect'  => __('ui.vnc_err_connect'),
    'msg_closed'   => __('ui.vnc_msg_closed'),
    'err_dropped'  => __('ui.vnc_err_dropped'),
    'err_auth'     => __('ui.vnc_err_auth'),
  ];
@endphp

<script>window.T = @json($vncT);</script>

<div class="pnl-head">
  <div>
    <nav class="blog-crumbs" style="margin-bottom:8px">
      <a href="{{ route('account.home') }}">{{ __('ui.vnc_crumb_panel') }}</a><span>/</span>
      <a href="{{ route('account.services') }}">{{ __('ui.vnc_crumb_services') }}</a><span>/</span>
      <a href="{{ route('account.cloud.show', $service) }}">{{ $service->name }}</a><span>/</span>
      <span>{{ __('ui.vnc_crumb_console') }}</span>
    </nav>
    <h1>{{ __('ui.vnc_h1') }}</h1>
    <p>
      {{ __('ui.vnc_lead') }}
      @if($instance?->ipv4)<span dir="ltr">{{ $instance->ipv4 }}</span>@endif
    </p>
  </div>
  <span class="pnl-pill" id="vnc-state" style="font-size:12.5px;padding:7px 15px">{{ __('ui.vnc_state_connecting') }}</span>
</div>

<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>{{ __('ui.vnc_sec_screen') }}</h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <button class="pnl-btn" id="vnc-cad" type="button" style="font-size:12px;padding:6px 11px">
        <svg class="icon"><use href="#i-key"/></svg>Ctrl+Alt+Del
      </button>
      <button class="pnl-btn" id="vnc-full" type="button" style="font-size:12px;padding:6px 11px">
        <svg class="icon"><use href="#i-monitor"/></svg>{{ __('ui.vnc_btn_fullscreen') }}
      </button>
      <button class="pnl-btn" id="vnc-again" type="button" style="font-size:12px;padding:6px 11px;display:none">
        <svg class="icon"><use href="#i-restore"/></svg>{{ __('ui.vnc_btn_reconnect') }}
      </button>
    </div>
  </div>

  <div class="pnl-sec-b" style="padding:0">
    {{-- ظرفِ کنسول: پس‌زمینهٔ تیره ثابت است چون صفحهٔ خودِ سرور تیره است و
         در حالتِ روشنِ سایت هم باید خوانا بماند. --}}
    <div id="vnc-wrap" style="position:relative;background:#0b0f14;min-height:460px;border-radius:0 0 12px 12px;overflow:hidden">
      <div id="vnc-screen" style="width:100%;height:460px"></div>

      <div id="vnc-msg" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px;color:#cbd5e1;font-size:13.5px;line-height:2">
        <div>
          <div style="font-size:30px;margin-bottom:10px">🖥️</div>
          <div id="vnc-msg-text">{{ __('ui.vnc_overlay_connecting') }}</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pnl-sec">
  <div class="pnl-sec-b" style="font-size:12.5px;color:var(--muted);line-height:2">
    <b>{{ __('ui.vnc_notes_label') }}</b>
    {{ __('ui.vnc_notes_1') }}
    <br>{!! __('ui.vnc_notes_2') !!}
    <br>{!! __('ui.vnc_notes_3', ['link' => $serverPageLink]) !!}
  </div>
</section>

<script type="module">
import RFB from '{{ asset('assets/js/novnc/core/rfb.js') }}';

(function(){
  'use strict';

  var ticketUrl = {{ Illuminate\Support\Js::from(route('account.cloud.console.ticket', [$service, 't' => $ticket])) }};
  var reopenUrl = {{ Illuminate\Support\Js::from(route('account.cloud.show', $service)) }};

  var pill   = document.getElementById('vnc-state');
  var msg    = document.getElementById('vnc-msg');
  var msgTxt = document.getElementById('vnc-msg-text');
  var screenEl = document.getElementById('vnc-screen');
  var againBtn = document.getElementById('vnc-again');

  var rfb = null;

  function state(text, color, showOverlay, overlayText){
    pill.textContent = text;
    pill.style.color = color;

    if (showOverlay) {
      msg.style.display = 'flex';
      if (overlayText) { msgTxt.textContent = overlayText; }
    } else {
      msg.style.display = 'none';
    }
  }

  function fail(text){
    state(window.T.disconnected, '#ff6b6b', true, text);
    againBtn.style.display = '';
  }

  // بلیت یک‌بارمصرف است، پس فقط یک بار خوانده می‌شود؛ برای اتصالِ دوباره
  // کاربر به صفحهٔ سرور برمی‌گردد و بلیتِ تازه می‌گیرد.
  fetch(ticketUrl, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
    .then(function(r){ return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function(d){
      if (!d || !d.ok || !d.url) { return Promise.reject('no-url'); }
      connect(d.url, d.password);
    })
    .catch(function(){
      fail(window.T.err_expired);
    });

  function connect(url, password){
    try {
      rfb = new RFB(screenEl, url, { credentials: { password: password || '' } });
    } catch (e) {
      fail(window.T.err_connect);
      return;
    }

    rfb.scaleViewport = true;
    rfb.resizeSession = false;
    rfb.clipViewport = false;
    rfb.background = '#0b0f14';

    rfb.addEventListener('connect', function(){
      state(window.T.connected, '#34d399', false);
      againBtn.style.display = 'none';
    });

    rfb.addEventListener('disconnect', function(e){
      fail(e && e.detail && e.detail.clean
        ? window.T.msg_closed
        : window.T.err_dropped);
    });

    rfb.addEventListener('securityfailure', function(){
      fail(window.T.err_auth);
    });
  }

  document.getElementById('vnc-cad').onclick = function(){
    if (rfb) { rfb.sendCtrlAltDel(); }
  };

  document.getElementById('vnc-full').onclick = function(){
    var el = document.getElementById('vnc-wrap');

    if (document.fullscreenElement) {
      document.exitFullscreen();
    } else if (el.requestFullscreen) {
      el.requestFullscreen();
    }
  };

  againBtn.onclick = function(){ window.location.href = reopenUrl; };

  // خروج از صفحه = بستنِ تمیزِ نشست، تا سوکتِ باز روی سرور نمانَد
  window.addEventListener('beforeunload', function(){
    if (rfb) { try { rfb.disconnect(); } catch (e) {} }
  });
})();
</script>
@endsection
