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
    'btn_fullscreen'      => __('ui.vnc_btn_fullscreen'),
    'btn_exit_fullscreen' => __('ui.vnc_btn_exit_fullscreen'),
  ];
@endphp

<script>window.T = @json($vncT);</script>

<style>
  #vnc-wrap{ position:relative; background:#0b0f14; overflow:hidden; border-radius:0 0 12px 12px }

  /* ── آی‌پی و مکان ── */
  .vnc-meta{ display:flex; flex-wrap:wrap; gap:8px; margin-top:10px }
  .vnc-chip{
    display:inline-flex; align-items:center; gap:7px; padding:6px 12px;
    border-radius:999px; font-size:12.5px; line-height:1;
    background:var(--bg2); border:1px solid var(--line); color:inherit;
    font-family:inherit; cursor:pointer;
  }
  .vnc-chip.is-static{ cursor:default }
  .vnc-chip .icon{ width:13px; height:13px; opacity:.6 }
  #vnc-ip:hover{ border-color:var(--accent) }
  /* بازخوردِ کپی با **متن**، نه فقط رنگ — رنگ به‌تنهایی برای کاربر کوررنگ کافی نیست */
  #vnc-ip.is-copied{ border-color:var(--accent); color:var(--accent) }
  #vnc-ip.is-copied::after{ content:'✓'; font-weight:700 }

  .vnc-head-actions{ display:flex; align-items:center; gap:10px; flex-wrap:wrap }

  /* ── کادرِ چسباندن ── */
  #vnc-paste-box{
    position:absolute; inset-inline-start:50%; top:50%; transform:translate(-50%,-50%);
    z-index:30; width:min(520px, calc(100% - 32px)); padding:18px;
    border-radius:14px; background:var(--bg); border:1px solid var(--line);
    box-shadow:0 18px 50px rgba(0,0,0,.4);
  }
  html[dir="rtl"] #vnc-paste-box{ transform:translate(50%,-50%) }
  #vnc-paste-box label{ display:block; font-size:13px; font-weight:600; margin-bottom:8px }
  #vnc-paste-text{
    width:100%; padding:10px 12px; border-radius:10px; font-size:13px;
    font-family:ui-monospace,Menlo,Consolas,monospace; resize:vertical;
    background:var(--bg2); color:inherit; border:1px solid var(--line);
  }
  #vnc-paste-box p{ margin:9px 0 0; font-size:11.5px; line-height:1.9; color:var(--muted) }
  .vnc-paste-actions{ display:flex; gap:8px; justify-content:flex-end; margin-top:14px }
  .pnl-btn.is-primary{ background:var(--accent); color:#fff; border-color:var(--accent) }

  /* ارتفاعِ عادی با ویوپورت بالا می‌آید تا روی نمایشگرِ بزرگ هم کنسول کوچک
     نمانَد، ولی روی لپ‌تاپِ کوتاه از ۴۶۰ پایین‌تر نرود. */
  #vnc-screen{ width:100%; height:clamp(460px, 68vh, 900px) }

  /* ⚠️ هر کدام قاعدهٔ جدا — اگر با کاما بنویسی، مرورگری که یکی از این دو
     سلکتور را نشناسد **کلِ قاعده** را دور می‌ریزد و تمام‌صفحه بی‌صدا می‌شکند. */
  #vnc-wrap:fullscreen{ border-radius:0; width:100vw; height:100vh }
  #vnc-wrap:fullscreen #vnc-screen{ height:100vh }

  #vnc-wrap:-webkit-full-screen{ border-radius:0; width:100vw; height:100vh }
  #vnc-wrap:-webkit-full-screen #vnc-screen{ height:100vh }
</style>

<div class="pnl-head">
  <div>
    <nav class="blog-crumbs" style="margin-bottom:8px">
      {{-- ⚠️ `lroute` و نه `route` — وگرنه مشتریِ /en و /tr به پنلِ فارسی می‌رود --}}
      <a href="{{ lroute('account.home') }}">{{ __('ui.vnc_crumb_panel') }}</a><span>/</span>
      <a href="{{ lroute('account.servers') }}">{{ __('ui.vnc_crumb_services') }}</a><span>/</span>
      <a href="{{ route('account.cloud.show', $service) }}">{{ $service->name }}</a><span>/</span>
      <span>{{ __('ui.vnc_crumb_console') }}</span>
    </nav>
    <h1>{{ __('ui.vnc_h1') }}</h1>
    <p>{{ __('ui.vnc_lead') }}</p>

    {{-- 🔴 آی‌پی و مکان بالای صفحه: کاربر معمولاً چند کنسول را هم‌زمان باز
         دارد و تنها چیزی که آن‌ها را از هم جدا می‌کند همین دو است. بدونش،
         دستورِ اشتباه روی سرورِ اشتباه اجرا می‌شود. --}}
    @php $vncLoc = $instance?->location(); @endphp
    <div class="vnc-meta">
      @if($instance?->ipv4)
        <button type="button" class="vnc-chip" id="vnc-ip"
                data-ip="{{ $instance->ipv4 }}" title="{{ __('ui.vnc_copy_ip') }}">
          <svg class="icon"><use href="#i-globe"/></svg>
          <span dir="ltr">{{ $instance->ipv4 }}</span>
        </button>
      @endif
      @if($vncLoc)
        <span class="vnc-chip is-static">
          @include('partials.flag', ['flagSrc' => $vncLoc->flagSvg(), 'flagEmoji' => $vncLoc->flagEmoji(), 'flagSize' => 18, 'flagEager' => true])
          <span>{{ $vncLoc->label() }}</span>
        </span>
      @endif
    </div>
  </div>

  <div class="vnc-head-actions">
    <a class="pnl-btn" href="{{ route('account.cloud.show', $service) }}">
      <svg class="icon dir"><use href="#i-arrow"/></svg>{{ __('ui.vnc_back') }}
    </a>
    <span class="pnl-pill" id="vnc-state" style="font-size:12.5px;padding:7px 15px">{{ __('ui.vnc_state_connecting') }}</span>
  </div>
</div>

<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>{{ __('ui.vnc_sec_screen') }}</h2>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <button class="pnl-btn" id="vnc-paste" type="button" style="font-size:12px;padding:6px 11px">
        <svg class="icon"><use href="#i-paperclip"/></svg>{{ __('ui.vnc_btn_paste') }}
      </button>
      <button class="pnl-btn" id="vnc-cad" type="button" style="font-size:12px;padding:6px 11px">
        <svg class="icon"><use href="#i-key"/></svg>Ctrl+Alt+Del
      </button>
      <button class="pnl-btn" id="vnc-full" type="button" style="font-size:12px;padding:6px 11px">
        <svg class="icon"><use href="#i-monitor"/></svg><span>{{ __('ui.vnc_btn_fullscreen') }}</span>
      </button>
      <button class="pnl-btn" id="vnc-again" type="button" style="font-size:12px;padding:6px 11px;display:none">
        <svg class="icon"><use href="#i-restore"/></svg>{{ __('ui.vnc_btn_reconnect') }}
      </button>
    </div>
  </div>

  <div class="pnl-sec-b" style="padding:0">
    {{-- ظرفِ کنسول: پس‌زمینهٔ تیره ثابت است چون صفحهٔ خودِ سرور تیره است و
         در حالتِ روشنِ سایت هم باید خوانا بماند.

         ⚠️ ارتفاع عمداً **inline نیست**. قبلاً `height:460px` روی #vnc-screen
         inline بود و چون استایلِ inline را هیچ قاعدهٔ CSS نمی‌شکند، در حالتِ
         تمام‌صفحه ظرف بزرگ می‌شد ولی خودِ صفحه ۴۶۰ پیکسل می‌ماند و کنسول
         وسطِ یک زمینهٔ سیاهِ بزرگ کوچک دیده می‌شد. --}}
    <div id="vnc-wrap">
      <div id="vnc-screen"></div>

      <div id="vnc-msg" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px;color:#cbd5e1;font-size:13.5px;line-height:2">
        <div>
          <div style="font-size:30px;margin-bottom:10px">🖥️</div>
          <div id="vnc-msg-text">{{ __('ui.vnc_overlay_connecting') }}</div>
        </div>
      </div>

      {{-- ⚠️ چسباندن با **تایپِ شبیه‌سازی‌شده** انجام می‌شود، نه کلیپ‌بوردِ VNC.
           کلیپ‌بورد فقط وقتی کار می‌کند که مهمان agent داشته باشد؛ روی سرورِ
           تازه‌نصب یا صفحهٔ نجات هیچ agentی نیست و دقیقاً همان‌جاست که کاربر
           بیشترین نیاز را به چسباندن دارد (کلیدِ SSH، دستورِ بلند، رمز).

           و چرا textarea به‌جای خواندنِ مستقیمِ کلیپ‌بورد: `navigator.clipboard.readText`
           اجازه می‌خواهد، در فایرفاکس عملاً بسته است، و در context غیرامن اصلاً
           وجود ندارد. کادرِ متن همه‌جا کار می‌کند. --}}
      <div id="vnc-paste-box" hidden>
        <label for="vnc-paste-text">{{ __('ui.vnc_paste_title') }}</label>
        <textarea id="vnc-paste-text" rows="4" spellcheck="false" dir="ltr"
                  placeholder="{{ __('ui.vnc_paste_ph') }}"></textarea>
        <p>{{ __('ui.vnc_paste_hint') }}</p>
        <div class="vnc-paste-actions">
          <button type="button" class="pnl-btn" id="vnc-paste-cancel">{{ __('ui.vnc_paste_cancel') }}</button>
          <button type="button" class="pnl-btn is-primary" id="vnc-paste-send">{{ __('ui.vnc_paste_send') }}</button>
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

  /* ───────────── کپیِ آی‌پی ─────────────
   * ⚠️ `navigator.clipboard` در context غیرامن **وجود ندارد**، پس نبودنش
   * نباید خطای جاوااسکریپت بدهد و بقیهٔ صفحه را بخواباند.
   */
  var ipBtn = document.getElementById('vnc-ip');
  if (ipBtn) {
    ipBtn.onclick = function(){
      var ip = ipBtn.getAttribute('data-ip') || '';
      var done = function(){
        ipBtn.classList.add('is-copied');
        setTimeout(function(){ ipBtn.classList.remove('is-copied'); }, 1400);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ip).then(done, function(){});
      }
    };
  }

  /* ───────────── چسباندنِ متن ─────────────
   * با **تایپِ شبیه‌سازی‌شده**، نه کلیپ‌بوردِ VNC: کلیپ‌بورد به agentِ مهمان
   * نیاز دارد و روی سرورِ تازه‌نصب یا صفحهٔ نجات هیچ agentی نیست — دقیقاً
   * همان‌جایی که کاربر بیشترین نیاز را به چسباندن دارد.
   */
  var pasteBox = document.getElementById('vnc-paste-box');
  var pasteText = document.getElementById('vnc-paste-text');

  document.getElementById('vnc-paste').onclick = function(){
    pasteBox.hidden = !pasteBox.hidden;
    if (!pasteBox.hidden) { pasteText.focus(); }
  };
  document.getElementById('vnc-paste-cancel').onclick = function(){ pasteBox.hidden = true; };

  /* ───────────── چیدمانِ US: کدام کاراکتر Shift می‌خواهد ─────────────
   * 🔴 چرا اصلاً لازم است: سرورِ VNC (QEMU) **خودش Shift را نمی‌زند**. در
   * `key_event` حتی حروفِ A–Z را عمداً به کوچک تبدیل می‌کند و انتظار دارد
   * کلاینت مثلِ یک صفحه‌کلیدِ واقعی، Shift_L را خودش نگه دارد. مرورگر این
   * کار را در تایپِ دستی می‌کند (رویدادِ فیزیکیِ Shift جداگانه می‌رود)، ولی
   * `sendKey` که ما در چسباندن صدا می‌زنیم هیچ مدیفایری نمی‌فرستد.
   *
   * نتیجهٔ نبودش: هر کاراکترِ شیفت‌دار به کلیدِ پایه‌اش می‌افتاد —
   *   $→4 ، _→- ، :→; ، }→] ، G→g
   * که دقیقاً همان چیزی است که مشتری گزارش کرد.
   */
  var SHIFTED = '~!@#$%^&*()_+{}|:"<>?';
  var SHIFT_L = 0xFFE1;              // keysymِ X11 برای Shift چپ

  function needsShift(ch){
    return (ch >= 'A' && ch <= 'Z') || SHIFTED.indexOf(ch) !== -1;
  }

  // ⚠️ دو بار زدنِ «ارسال» نباید دو حلقهٔ موازی بسازد؛ وضعیتِ Shift مشترک است
  // و در‌هم‌رفتنشان یعنی کاراکترهای تصادفیِ بزرگ/کوچک.
  var pasting = false;

  document.getElementById('vnc-paste-send').onclick = function(){
    if (!rfb || pasting) { return; }
    var text = pasteText.value;
    if (!text) { pasteBox.hidden = true; return; }

    var i = 0;
    var chars = Array.from(text);        // نه split('') — تا حروفِ خارج از BMP نشکنند
    var shiftDown = false;
    pasting = true;

    // Shift را برای یک **رشتهٔ پیوسته** از کاراکترهای شیفت‌دار نگه می‌داریم،
    // نه تک‌تک: هم پیام‌های کمتری روی سیم می‌رود، هم دقیقاً مثلِ تایپِ آدمی است.
    function shift(on){
      if (on === shiftDown) { return; }
      rfb.sendKey(SHIFT_L, null, on);
      shiftDown = on;
    }

    function finish(){
      shift(false);                      // 🔴 رها نکردنش = Shift گیرکرده در مهمان
      pasting = false;
      pasteBox.hidden = true;
      pasteText.value = '';
    }

    /* آهنگِ ارسال: دسته‌های کوچک، با همان نرخِ میانگینِ قبل.
     *
     * ۸ کلید هر ۹۶ms یعنی همان ۸۳ کلید بر ثانیهٔ «یک کلید هر ۱۲ms»، پس
     * فشاری که به صفِ ورودیِ مهمان می‌آید عوض نشده و دستهٔ ۸تایی حاشیهٔ
     * امنی تا ظرفیتِ صفِ PS/2 دارد. دسته‌ای‌بودن فقط تعدادِ تایمرها را
     * کم می‌کند؛ کارِ اصلی را مکثِ پایین انجام می‌دهد.
     *
     * تاریخچه: اول فکر کردم بزرگ‌کردنِ دسته (۳۲/۳۸۴ms) مسئلهٔ تبِ
     * پنهان را حل می‌کند. نکرد: در تستِ زنده شکافِ ۵۰ ثانیه‌ای درآمد و
     * ۴۸۷ کاراکتر می‌شد ربعِ ساعت. با تایمر نمی‌شود با این کلامپ جنگید.
     */
    var CHUNK = 8, GAP = 96;

    (function step(){
      /* 🔴 تبِ پنهان را با تایمر نمی‌شود شکست — می‌ایستیم.
       *
       * کروم در تبِ پنهان setTimeout را به ۱ ثانیه می‌کشد و پس از ۵ دقیقه
       * به ۱ در دقیقه (intensive throttling). در تستِ زنده روی سرورِ هتزنر
       * شکافِ ۵۰ ثانیه‌ای دیدیم. یعنی هر چقدر هم دسته را بزرگ کنیم،
       * یک کلیدِ SSH می‌تواند ربعِ ساعت طول بکشد و تمامِ این مدت نیمهٔ یک
       * دستور روی پرامپتِ سرور معلق بماند.
       *
       * پس به‌جای چکه‌کردن، کار را نگه می‌داریم و دقیقاً وقتی کاربر
       * برمی‌گردد ادامه می‌دهیم. `pasting` هم تا آن لحظه true می‌ماند،
       * پس کلیکِ دوباره دو حلقهٔ درهم‌رفته نمی‌سازد.
       */
      if (document.hidden) {
        shift(false);        // 🔴 Shift نباید در تمامِ مدتِ مکث پایین بماند
        document.addEventListener('visibilitychange', function onVis(){
          document.removeEventListener('visibilitychange', onVis);
          step();            // خودِ step دوباره hidden را می‌سنجد
        });
        return;
      }
      var end = Math.min(i + CHUNK, chars.length);

      for (; i < end; i++) {
        var ch = chars[i];

        if (ch === '\n' || ch === '\r') {
          shift(false);
          rfb.sendKey(0xFF0D, 'Enter');    // keysymِ X11 برای Return
        } else if (ch === '\t') {
          shift(false);
          rfb.sendKey(0xFF09, 'Tab');
        } else {
          shift(needsShift(ch));
          var cp = ch.codePointAt(0);
          // Latin-1 مستقیم؛ بقیه با آفستِ یونیکدِ X11
          rfb.sendKey(cp < 0x100 ? cp : 0x01000000 + cp, null);
        }
      }

      if (i >= chars.length) { return finish(); }
      setTimeout(step, GAP);
    })();
  };

  var wrap = document.getElementById('vnc-wrap');
  var fullBtn = document.getElementById('vnc-full');
  var fullLabel = fullBtn.querySelector('span');

  function fsElement(){
    return document.fullscreenElement || document.webkitFullscreenElement || null;
  }

  fullBtn.onclick = function(){
    if (fsElement()) {
      (document.exitFullscreen || document.webkitExitFullscreen).call(document);
    } else {
      var req = wrap.requestFullscreen || wrap.webkitRequestFullscreen;
      if (req) { req.call(wrap); }
    }
  };

  // بعد از تغییرِ اندازه، noVNC باید دوباره مقیاس بگیرد. `scaleViewport` به
  // resizeِ پنجره گوش می‌دهد، ولی ورود/خروجِ تمام‌صفحه همیشه و همه‌جا آن رویداد
  // را به‌موقع نمی‌دهد؛ پس صریح می‌گوییم. rAF لازم است چون در لحظهٔ رویداد،
  // مرورگر هنوز ابعادِ تازه را اعمال نکرده و مقیاس با اندازهٔ قدیمی حساب می‌شود.
  function rescale(){
    requestAnimationFrame(function(){
      window.dispatchEvent(new Event('resize'));
      if (rfb) { rfb.scaleViewport = false; rfb.scaleViewport = true; }
    });
  }

  function onFsChange(){
    var on = !!fsElement();
    fullLabel.textContent = on ? window.T.btn_exit_fullscreen : window.T.btn_fullscreen;
    rescale();
  }

  document.addEventListener('fullscreenchange', onFsChange);
  document.addEventListener('webkitfullscreenchange', onFsChange);

  // چرخاندنِ گوشی یا تغییرِ اندازهٔ پنجره هم باید کنسول را دوباره جا بیندازد
  window.addEventListener('orientationchange', rescale);

  againBtn.onclick = function(){ window.location.href = reopenUrl; };

  // خروج از صفحه = بستنِ تمیزِ نشست، تا سوکتِ باز روی سرور نمانَد
  window.addEventListener('beforeunload', function(){
    if (rfb) { try { rfb.disconnect(); } catch (e) {} }
  });
})();
</script>
@endsection
