@extends('panel.layout')
@section('title', 'کنسول سرور — سرورنت کلاود')

{{-- کنسولِ زندهٔ سرور، **داخلِ پنلِ خودمان**.

     noVNC خودمیزبان است (public/assets/js/novnc) چون CSP هر منبعِ بیرونی را
     بی‌صدا بلاک می‌کند. آدرسِ اتصال در HTML نیست؛ جاوااسکریپت آن را از یک
     پاسخِ JSON same-origin و **یک‌بارمصرف** می‌گیرد. --}}

@section('panel')

@php
  $specs = (array) ($instance->specs ?? []);
@endphp

<div class="pnl-head">
  <div>
    <nav class="blog-crumbs" style="margin-bottom:8px">
      <a href="{{ route('account.home') }}">پنل</a><span>/</span>
      <a href="{{ route('account.services') }}">سرویس‌ها</a><span>/</span>
      <a href="{{ route('account.cloud.show', $service) }}">{{ $service->name }}</a><span>/</span>
      <span>کنسول</span>
    </nav>
    <h1>کنسولِ زندهٔ سرور</h1>
    <p>
      مثلِ نشستن پشتِ خودِ سرور — حتی اگر شبکه یا فایروالش خراب باشد.
      @if($instance?->ipv4)<span dir="ltr">{{ $instance->ipv4 }}</span>@endif
    </p>
  </div>
  <span class="pnl-pill" id="vnc-state" style="font-size:12.5px;padding:7px 15px">در حالِ اتصال…</span>
</div>

<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>صفحهٔ سرور</h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <button class="pnl-btn" id="vnc-cad" type="button" style="font-size:12px;padding:6px 11px">
        <svg class="icon"><use href="#i-key"/></svg>Ctrl+Alt+Del
      </button>
      <button class="pnl-btn" id="vnc-full" type="button" style="font-size:12px;padding:6px 11px">
        <svg class="icon"><use href="#i-monitor"/></svg>تمام‌صفحه
      </button>
      <button class="pnl-btn" id="vnc-again" type="button" style="font-size:12px;padding:6px 11px;display:none">
        <svg class="icon"><use href="#i-restore"/></svg>اتصالِ دوباره
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
          <div id="vnc-msg-text">در حالِ برقراریِ اتصالِ امن به سرور…</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="pnl-sec">
  <div class="pnl-sec-b" style="font-size:12.5px;color:var(--muted);line-height:2">
    <b>چند نکته:</b>
    کنسول برای وقتی است که SSH کار نمی‌کند — فایروالِ اشتباه، رمزِ گم‌شده، یا سیستمی که بالا نمی‌آید.
    <br>کلیدهای ترکیبیِ مرورگر (مثلِ <span dir="ltr">Ctrl+W</span>) به سرور نمی‌روند؛ برای ورود به سیستم از دکمهٔ
    <span dir="ltr">Ctrl+Alt+Del</span> بالا استفاده کنید.
    <br>نشستِ کنسول کوتاه‌عمر است. اگر قطع شد، «اتصالِ دوباره» را بزنید یا از
    <a href="{{ route('account.cloud.show', $service) }}" style="color:var(--info)">صفحهٔ سرور</a> دوباره بازش کنید.
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
    state('قطع', '#ff6b6b', true, text);
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
      fail('نشستِ کنسول منقضی شده است. برای بازکردنِ دوباره به صفحهٔ سرور برگردید.');
    });

  function connect(url, password){
    try {
      rfb = new RFB(screenEl, url, { credentials: { password: password || '' } });
    } catch (e) {
      fail('اتصال برقرار نشد. چند لحظه بعد دوباره تلاش کنید.');
      return;
    }

    rfb.scaleViewport = true;
    rfb.resizeSession = false;
    rfb.clipViewport = false;
    rfb.background = '#0b0f14';

    rfb.addEventListener('connect', function(){
      state('متصل', '#34d399', false);
      againBtn.style.display = 'none';
    });

    rfb.addEventListener('disconnect', function(e){
      fail(e && e.detail && e.detail.clean
        ? 'اتصال بسته شد.'
        : 'اتصال قطع شد. ممکن است سرور خاموش باشد یا نشست منقضی شده باشد.');
    });

    rfb.addEventListener('securityfailure', function(){
      fail('احراز هویتِ کنسول رد شد. از صفحهٔ سرور دوباره بازش کنید.');
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
