@extends('panel.layout')
@section('title', 'مدیریت سرور — سرورنت کلاود')

{{-- نسخهٔ واقعی و داده‌محورِ همان طرحی که در panel/server.blade.php پیش‌نمایش
     شده بود. عمداً از همان کلاس‌های CSS استفاده می‌کند (pnl-sec، pnl-acts،
     pnl-btn، pnl-res، pnl-bar) تا نه استایلِ تازه لازم شود نه ظاهر عوض شود.

     قاعدهٔ سفیدبرچسبی: در کلِ این فایل هیچ‌جا نامِ زیرساخت نیست. --}}

@section('panel')

@php
  $inst  = $instance;
  $specs = (array) ($inst->specs ?? []);
  $loc   = $inst?->location();
  $osLbl = $osList->firstWhere('key', $inst?->image_key)?->label
           ?? $appList->firstWhere('key', $inst?->image_key)?->label
           ?? ($inst->image_key ?? '—');
@endphp

<div class="pnl-head">
  <div>
    <nav class="blog-crumbs" style="margin-bottom:8px">
      <a href="{{ route('account.home') }}">پنل</a><span>/</span>
      <a href="{{ route('account.services') }}">سرویس‌ها</a><span>/</span>
      <span>سرور ابری</span>
    </nav>
    <h1>{{ $service->name }}</h1>
    <p>
      @if($inst?->ipv4)<span dir="ltr">{{ $inst->ipv4 }}</span> · @endif
      {{ $loc?->label() ?? '—' }} · {{ $osLbl }}
    </p>
  </div>
  <span class="pnl-pill {{ $inst?->status === 'running' ? 'ok' : '' }}" id="st-pill"
        style="font-size:12.5px;padding:7px 15px;color:{{ $inst?->statusColor() ?? 'var(--dim)' }}">
    {{ $inst?->statusLabel() ?? 'در حالِ آماده‌سازی' }}
  </span>
</div>

{{-- همان قالبِ اعلانِ بقیهٔ صفحاتِ پنل (pnl-sec با رنگِ حاشیه) — کلاسِ تازه
     نمی‌سازیم چون کلاسِ نبود، بی‌هیچ خطایی بی‌استایل رندر می‌شود. --}}
@if(session('ok'))
  <div class="pnl-sec" style="border-color:var(--ok-line)">
    <div class="pnl-sec-b" style="color:var(--ok);font-size:13.5px">{{ session('ok') }}</div>
  </div>
@endif
@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)">
    <div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">{{ $errors->first() }}</div>
  </div>
@endif

{{-- ═══ سرور در حالِ ساخت ═══
     مشتری پول داده و چیزی نمی‌بیند؛ اگر این حالت را صریح نگوییم، فکر می‌کند
     خرید ناموفق بوده و تیکت می‌زند. صفحه هر ۱۰ ثانیه خودش وضعیت را می‌پرسد. --}}
@if(! $inst || $inst->status === 'building')
  <section class="pnl-sec">
    <div class="pnl-sec-b" style="text-align:center;padding:34px 20px">
      <div style="font-size:34px;margin-bottom:10px">⚙️</div>
      <h2 style="font-size:17px;margin:0 0 8px">سرورِ شما در حالِ آماده‌سازی است</h2>
      <p style="color:var(--muted);font-size:13.5px;line-height:2;margin:0">
        این کار معمولاً <b>کمتر از دو دقیقه</b> طول می‌کشد. لازم نیست صفحه را ببندید —
        به‌محضِ آماده شدن، مشخصاتِ ورود همین‌جا نشان داده می‌شود و ایمیل هم برایتان می‌رود.
      </p>
      @if($inst?->last_error)
        <p style="margin-top:14px;color:var(--warn);font-size:12.5px">
          آماده‌سازی با تأخیر روبه‌روست. تیمِ ما خودکار مطلع شده و دوباره تلاش می‌کند.
        </p>
      @endif
    </div>
  </section>
@else

{{-- ═══ دسترسی ═══ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>دسترسی به سرور</h2></div>
  <div class="pnl-sec-b">
    <div class="cs-grid">
      <div class="cs-kv"><small>IPv4</small><b dir="ltr" class="cs-copy" data-copy="{{ $inst->ipv4 }}">{{ $inst->ipv4 ?: '—' }}</b></div>
      @if($inst->ipv6)
        <div class="cs-kv"><small>IPv6</small><b dir="ltr" class="cs-copy" data-copy="{{ $inst->ipv6 }}">{{ $inst->ipv6 }}</b></div>
      @endif
      <div class="cs-kv"><small>کاربر</small><b dir="ltr">root</b></div>
      <div class="cs-kv"><small>مکان</small><b>{{ $loc?->flagEmoji() }} {{ $loc?->label() ?? '—' }}</b></div>
    </div>

    @if($inst->ipv4)
      {{-- ⚠️ رشته را در PHP می‌سازیم، نه در قالب. اگر «root» و «{{» با یک @
           به هم بچسبند، Blade آن را **دستورِ فرار** می‌فهمد و به‌جای IP، خودِ
           عبارتِ آکولادی را چاپ می‌کند — همان تلهٔ آشنای این پروژه. --}}
      @php $sshCmd = 'ssh root'.'@'.$inst->ipv4; @endphp
      <div class="cs-ssh">
        <small>اتصال با SSH</small>
        <code dir="ltr" class="cs-copy" data-copy="{{ $sshCmd }}">{{ $sshCmd }}</code>
      </div>
    @endif

    {{-- رمز فقط **یک بار** نشان داده می‌شود. صفحهٔ همیشه‌بازِ پنل روی لپ‌تاپِ
         مشترک، رمزِ root را به هر رهگذری می‌دهد. --}}
    @if($password)
      <div class="cs-pw">
        <div>
          <small>رمزِ root — همین حالا جایی امن ذخیره‌اش کنید</small>
          <code dir="ltr" class="cs-copy" data-copy="{{ $password }}">{{ $password }}</code>
        </div>
        <span>این رمز <b>دیگر نمایش داده نمی‌شود</b>. اگر گمش کردید، از پایین رمزِ تازه بسازید.</span>
      </div>
    @elseif($inst->hasPassword())
      <p style="margin:14px 0 0;font-size:12.5px;color:var(--dim);line-height:1.9">
        رمزِ root قبلاً یک‌بار نشان داده شده و به‌دلیلِ امنیت دیگر نمایش داده نمی‌شود.
        اگر در دسترس ندارید، از بخشِ «کنترلِ سرور» رمزِ تازه بسازید.
      </p>
    @endif
  </div>
</section>

{{-- ═══ کنترل ═══ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>کنترلِ سرور</h2>
    <span style="font-size:12px;color:var(--dim)" id="st-seen">
      @if($inst->synced_at) آخرین بررسی: {{ $inst->synced_at->diffForHumans() }} @endif
    </span>
  </div>
  <div class="pnl-sec-b">
    <div class="pnl-acts">
      @if($inst->status !== 'running')
        <form method="post" action="{{ route('account.cloud.power', $service) }}">
          @csrf<input type="hidden" name="action" value="on">
          <button class="pnl-btn"><svg class="icon"><use href="#i-zap"/></svg>روشن کردن</button>
        </form>
      @else
        <form method="post" action="{{ route('account.cloud.power', $service) }}">
          @csrf<input type="hidden" name="action" value="reboot">
          <button class="pnl-btn"><svg class="icon"><use href="#i-restore"/></svg>راه‌اندازی مجدد</button>
        </form>
        <form method="post" action="{{ route('account.cloud.power', $service) }}"
              onsubmit="return confirm('سرور خاموش شود؟ سرویس‌های رویش تا روشن‌شدنِ دوباره از دسترس خارج می‌شوند.')">
          @csrf<input type="hidden" name="action" value="off">
          <button class="pnl-btn danger"><svg class="icon"><use href="#i-zap"/></svg>خاموش کردن</button>
        </form>
      @endif

      @if($caps['console'] ?? false)
        <form method="post" action="{{ route('account.cloud.console', $service) }}">
          @csrf<button class="pnl-btn"><svg class="icon"><use href="#i-monitor"/></svg>کنسولِ تحتِ وب</button>
        </form>
      @endif

      @if($caps['reset_password'] ?? false)
        <form method="post" action="{{ route('account.cloud.password', $service) }}"
              onsubmit="return confirm('رمزِ تازه ساخته شود؟ رمزِ فعلی از کار می‌افتد.')">
          @csrf<button class="pnl-btn"><svg class="icon"><use href="#i-key"/></svg>رمزِ تازهٔ root</button>
        </form>
      @endif
    </div>

    <p style="margin-top:14px;font-size:12.5px;color:var(--dim);line-height:1.9">
      «خاموش کردن» فرمانِ نرم می‌فرستد تا سیستم درست بسته شود و داده‌ای خراب نشود.
      وضعیت هر چند ثانیه خودکار به‌روز می‌شود.
    </p>
  </div>
</section>

{{-- ═══ مشخصات ═══ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>مشخصاتِ سرور</h2></div>
  <div class="pnl-sec-b">
    <div class="cs-grid">
      <div class="cs-kv"><small>پردازنده</small><b>{{ fa_num($specs['vcpu'] ?? '—') }} هسته</b></div>
      <div class="cs-kv"><small>حافظه</small><b dir="ltr">{{ isset($specs['ram_mb']) ? fa_num(round($specs['ram_mb'] / 1024, 1)).' GB' : '—' }}</b></div>
      <div class="cs-kv"><small>دیسک</small><b dir="ltr">{{ fa_num($specs['disk_gb'] ?? '—') }} GB {{ strtoupper($specs['disk_type'] ?? '') }}</b></div>
      <div class="cs-kv"><small>ترافیک</small><b dir="ltr" id="tr-used">
        {{ ($specs['traffic_gb'] ?? 0) > 0 ? fa_num(round($specs['traffic_gb'] / 1024, 1)).' TB' : 'منصفانه' }}
      </b></div>
    </div>
    <p style="margin:14px 0 0;font-size:12.5px;color:var(--dim);line-height:1.9">
      سررسیدِ بعدی: <b>{{ $service->next_due_at ? sdate($service->next_due_at) : '—' }}</b>
      · <a href="{{ route('account.invoices') }}" style="color:var(--info)">فاکتورها</a>
    </p>
  </div>
</section>

{{-- ═══ نمودارِ مصرف ═══ فقط اگر واقعاً در دسترس باشد --}}
@if($caps['metrics'] ?? false)
<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>مصرفِ پردازنده</h2>
    <span style="font-size:12px;color:var(--dim)">۲۴ ساعتِ گذشته</span>
  </div>
  <div class="pnl-sec-b">
    <div id="cpu-wrap" style="min-height:96px">
      <p style="color:var(--dim);font-size:12.5px;margin:0">در حالِ خواندنِ نمودار…</p>
    </div>
  </div>
</section>
@endif

{{-- ═══ نصبِ دوباره ═══
     عملِ برگشت‌ناپذیر، عمداً **آخرِ صفحه** و جدا از دکمه‌های عادی، بسته در
     details و با تأییدِ تایپی. یک کلیکِ اشتباه نباید دادهٔ مشتری را ببرد. --}}
@if($caps['rebuild'] ?? false)
<section class="pnl-sec cs-danger">
  <div class="pnl-sec-h"><h2>نصبِ دوبارهٔ سیستم‌عامل</h2></div>
  <div class="pnl-sec-b">
    <p style="font-size:13px;color:var(--warn);line-height:1.9;margin:0 0 12px">
      <b>هشدار:</b> با نصبِ دوباره، <b>همهٔ داده‌های سرور برای همیشه پاک می‌شود</b> —
      فایل‌ها، دیتابیس‌ها و تنظیمات. اگر پشتیبان ندارید، اول پشتیبان بگیرید.
    </p>

    <details class="cs-rb">
      <summary>می‌خواهم سیستم‌عامل را دوباره نصب کنم</summary>

      <form method="post" action="{{ route('account.cloud.rebuild', $service) }}" class="cs-rb-f">
        @csrf

        <label>سیستم‌عامل
          <select name="image" required>
            <optgroup label="سیستم‌عامل">
              @foreach($osList as $os)
                <option value="{{ $os->key }}" @selected($os->key === $inst->image_key)>
                  {{ $os->icon() }} {{ $os->label }}
                </option>
              @endforeach
            </optgroup>
            @if($appList->isNotEmpty())
              <optgroup label="نرم‌افزارِ آماده (روی سیستم‌عاملِ پایه نصب می‌شود)">
                @foreach($appList as $app)
                  <option value="{{ $app->key }}" @selected($app->key === $inst->image_key)>
                    {{ $app->icon() }} {{ $app->label }}
                  </option>
                @endforeach
              </optgroup>
            @endif
          </select>
        </label>

        <label>برای تأیید، عبارتِ <b dir="ltr">DELETE</b> را تایپ کنید
          <input type="text" name="confirm" required dir="ltr" autocomplete="off" placeholder="DELETE">
        </label>

        <button class="pnl-btn danger" type="submit">
          <svg class="icon"><use href="#i-restore"/></svg>پاک کردن و نصبِ دوباره
        </button>
      </form>
    </details>
  </div>
</section>
@endif

@endif {{-- پایانِ حالتِ آماده --}}

<style>
.cs-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px }
.cs-kv{ background:var(--bg2); border:1px solid var(--line); border-radius:12px; padding:12px 14px }
.cs-kv small{ display:block; color:var(--dim); font-size:11.5px; margin-bottom:5px }
.cs-kv b{ font-size:14px }
.cs-copy{ cursor:pointer; border-bottom:1px dashed var(--line) }
.cs-copy:hover{ color:var(--info) }
.cs-ssh{ margin-top:14px; background:var(--bg2); border:1px solid var(--line); border-radius:12px; padding:12px 14px }
.cs-ssh small{ display:block; color:var(--dim); font-size:11.5px; margin-bottom:6px }
.cs-ssh code{ font-size:13px; display:inline-block }
.cs-pw{ margin-top:14px; background:rgba(52,211,153,.08); border:1px solid rgba(52,211,153,.28); border-radius:12px; padding:14px }
.cs-pw small{ display:block; color:#34d399; font-size:12px; margin-bottom:7px }
.cs-pw code{ font-size:15px; letter-spacing:.4px }
.cs-pw span{ display:block; margin-top:9px; font-size:12px; color:var(--muted); line-height:1.8 }
.cs-danger{ border-color:rgba(255,107,107,.3) }
.cs-rb summary{ cursor:pointer; font-size:13px; color:#ff6b6b; padding:6px 0 }
.cs-rb summary::-webkit-details-marker{ display:none }
.cs-rb-f{ display:grid; gap:13px; max-width:460px; margin-top:12px }
.cs-rb-f label{ display:flex; flex-direction:column; gap:6px; font-size:12.5px; color:var(--muted) }
.cs-rb-f select, .cs-rb-f input{ background:var(--bg2); border:1px solid var(--line); border-radius:10px; color:var(--text); padding:10px 12px; font:inherit; font-size:13px }
.pnl-acts form{ display:inline }
</style>

<script>
(function(){
  'use strict';

  // ── کپی با یک کلیک ──
  document.querySelectorAll('.cs-copy').forEach(function(el){
    el.addEventListener('click', function(){
      var v = el.getAttribute('data-copy') || el.textContent.trim();
      if (!navigator.clipboard) { return; }
      navigator.clipboard.writeText(v).then(function(){
        var old = el.textContent;
        el.textContent = 'کپی شد';
        setTimeout(function(){ el.textContent = old; }, 1100);
      });
    });
  });

  var pill = document.getElementById('st-pill');
  if (!pill) { return; }

  var statusUrl = {{ Illuminate\Support\Js::from(route('account.cloud.status', $service)) }};
  var building  = {{ (! $instance || $instance->status === 'building') ? 'true' : 'false' }};

  // ── وضعیتِ زنده ──
  // در حالتِ «در حالِ ساخت» تندتر می‌پرسیم، چون مشتری منتظرِ همان است؛ بعد از
  // آماده شدن، آرام‌تر تا سهمیهٔ API زیرساخت بی‌دلیل خرج نشود.
  var tick = building ? 10000 : 30000;
  var tries = 0;

  function poll(){
    if (++tries > 60) { return; }              // سقفِ ایمنی: تبِ رهاشده تا ابد نپرسد

    fetch(statusUrl, { headers: { 'Accept': 'application/json' } })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.label) { return; }

        pill.textContent = d.label;
        if (d.color) { pill.style.color = d.color; }

        var seen = document.getElementById('st-seen');
        if (seen) { seen.textContent = 'آخرین بررسی: چند لحظه پیش'; }

        // تازه آماده شد → صفحه را یک بار بازخوانی کن تا رمز و مشخصات بیاید
        if (building && d.status && d.status !== 'building') {
          window.location.reload();
          return;
        }

        var tr = document.getElementById('tr-used');
        if (tr && d.traffic !== null && typeof d.traffic !== 'undefined') {
          tr.setAttribute('title', 'مصرفِ این ماه: ' + d.traffic + ' GB');
        }

        setTimeout(poll, tick);
      })
      .catch(function(){ setTimeout(poll, tick * 2); });
  }

  setTimeout(poll, tick);

  // ── نمودارِ پردازنده ── SVG ساده، بی‌کتابخانه (CSP هر منبعِ خارجی را می‌بندد)
  var wrap = document.getElementById('cpu-wrap');
  if (!wrap) { return; }

  fetch({{ Illuminate\Support\Js::from(route('account.cloud.metrics', $service)) }} + '?window=24h',
        { headers: { 'Accept': 'application/json' } })
    .then(function(r){ return r.json(); })
    .then(function(d){
      var pts = (d && d.ok && d.series && d.series.cpu) ? d.series.cpu : [];

      if (!pts.length) {
        wrap.innerHTML = '<p style="color:var(--dim);font-size:12.5px;margin:0">نمودار برای این سرور در دسترس نیست.</p>';
        return;
      }

      var W = 640, H = 96, max = 100, n = pts.length;
      var path = '';

      for (var i = 0; i < n; i++) {
        var x = (i / (n - 1 || 1)) * W;
        var v = Math.max(0, Math.min(max, Number(pts[i][1]) || 0));
        var y = H - (v / max) * (H - 8) - 4;
        path += (i === 0 ? 'M' : 'L') + x.toFixed(1) + ' ' + y.toFixed(1);
      }

      var last = Math.round(Number(pts[n - 1][1]) || 0);

      var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" ' +
                'style="width:100%;height:96px;display:block" role="img" aria-label="نمودار مصرف پردازنده">' +
                '<path d="' + path + '" fill="none" stroke="#22d3ee" stroke-width="2" ' +
                'stroke-linejoin="round" stroke-linecap="round"/></svg>' +
                '<p style="margin:8px 0 0;font-size:12.5px;color:var(--muted)">آخرین مقدار: ' + last + '٪</p>';

      wrap.innerHTML = svg;
    })
    .catch(function(){
      wrap.innerHTML = '<p style="color:var(--dim);font-size:12.5px;margin:0">نمودار در دسترس نیست.</p>';
    });
})();
</script>
@endsection
