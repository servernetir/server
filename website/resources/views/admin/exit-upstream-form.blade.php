@extends('admin.layout')
@section('title', $upstream->exists ? 'ویرایشِ آپ‌استریم' : 'افزودنِ آپ‌استریم')
@section('nav_exit_upstreams', 'on')
@section('content')

@php
  $editing = $upstream->exists;
  $action  = $editing
    ? route('admin.exit-upstreams.update', $upstream->id)
    : route('admin.exit-upstreams.store');
  // استایلِ مشترکِ ورودی‌ها (هم‌خانواده‌ی selectِ صفحه‌ی زیرساختِ اکسیت)
  $inp = 'width:100%;background:rgba(148,163,184,.10);color:var(--text);border:1px solid rgba(148,163,184,.3);border-radius:9px;padding:9px 11px;font-size:13px';
  $lbl = 'display:block;margin:0 0 6px;font-size:12.5px;color:var(--muted)';
  $curRole = old('role', $upstream->role ?: 'relay');
  $curType = old('type', $upstream->type ?: 'ssh');
@endphp

<div style="margin-bottom:14px">
  <a href="{{ route('admin.exit-upstreams') }}" class="ad-badge"
     style="background:rgba(148,163,184,.14);color:var(--muted);padding:8px 14px;text-decoration:none">← بازگشت به فهرست</a>
</div>

<div class="ad-panel" style="max-width:720px">
  <div class="ad-panel-h"><h2>{{ $editing ? 'ویرایشِ «'.$upstream->name.'»' : 'افزودنِ رله / نودِ اکسیت' }}</h2></div>

  <form method="post" action="{{ $action }}" style="padding:6px 18px 20px;display:grid;gap:15px" id="up-form">
    @csrf

    <div>
      <label style="{{ $lbl }}">نام (برچسبِ داخلی)</label>
      <input type="text" name="name" required maxlength="160" dir="auto"
             value="{{ old('name', $upstream->name) }}" placeholder="مثلاً: رله‌ی Hetzner DE #۱"
             style="{{ $inp }}">
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px">
      <div>
        <label style="{{ $lbl }}">نقش</label>
        <select name="role" id="up-role" dir="rtl" style="{{ $inp }}">
          <option value="relay" @selected($curRole === 'relay')>رله (آپ‌لینک — بی‌کشور)</option>
          <option value="exit"  @selected($curRole === 'exit')>اکسیتِ کشوری (اختصاصی)</option>
        </select>
      </div>
      <div data-group="country" @if($curRole !== 'exit') style="display:none" @endif>
        <label style="{{ $lbl }}">کشورِ اکسیت</label>
        <select name="country_code" dir="ltr" style="{{ $inp }}">
          @foreach($exitOptions as $opt)
            <option value="{{ $opt['code'] }}" @selected(old('country_code', $upstream->country_code) === $opt['code'])>{{ $opt['flag'] }} {{ $opt['name'] }} ({{ $opt['code'] }})</option>
          @endforeach
        </select>
      </div>
    </div>

    <div>
      <label style="{{ $lbl }}">نوع (پروتکل)</label>
      <select name="type" id="up-type" dir="ltr" style="{{ $inp }}">
        @foreach($types as $t)
          <option value="{{ $t }}" @selected($curType === $t)>{{ strtoupper($t) }}</option>
        @endforeach
      </select>
      <p style="margin:7px 2px 0;font-size:11.5px;color:var(--dim);line-height:1.8">
        SSH و SOCKS و WireGuard به host و port نیاز دارند. VLESS و Trojan خودشان
        یک لینکِ کامل‌اند (لینک را در «اعتبارنامه» بگذار).
      </p>
    </div>

    {{-- فیلدهای host-محور: ssh / socks / wireguard --}}
    <div data-group="host" @if(! in_array($curType, ['ssh','socks','wireguard'], true)) style="display:none" @endif>
      <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:15px">
        <div>
          <label style="{{ $lbl }}">host (دامنه یا IP)</label>
          <input type="text" name="host" dir="ltr" maxlength="190"
                 value="{{ old('host', $upstream->host) }}" placeholder="de1.example.com"
                 style="{{ $inp }}">
        </div>
        <div>
          <label style="{{ $lbl }}">port</label>
          <input type="number" name="port" dir="ltr" min="1" max="65535"
                 value="{{ old('port', $upstream->port) }}" placeholder="22"
                 style="{{ $inp }}">
        </div>
        <div>
          <label style="{{ $lbl }}">کاربر (SSH)</label>
          <input type="text" name="username" dir="ltr" maxlength="64"
                 value="{{ old('username', $upstream->username) }}" placeholder="root"
                 style="{{ $inp }}">
        </div>
      </div>
    </div>

    {{-- SNI فقط برای vless/trojan --}}
    <div data-group="sni" @if(! in_array($curType, ['vless','trojan'], true)) style="display:none" @endif>
      <label style="{{ $lbl }}">SNI (اختیاری — برای REALITY/TLS)</label>
      <input type="text" name="sni" dir="ltr" maxlength="190"
             value="{{ old('sni', $upstream->sni) }}" placeholder="dl.google.com"
             style="{{ $inp }}">
    </div>

    {{-- اعتبارنامه — write-only --}}
    <div>
      <label style="{{ $lbl }}">
        اعتبارنامه
        <span data-hint="ssh"    @if($curType !== 'ssh') style="display:none" @endif>— کلیدِ خصوصیِ SSH (PEM) یا رمز</span>
        <span data-hint="link"   @if(! in_array($curType, ['vless','trojan'], true)) style="display:none" @endif>— لینکِ کاملِ اتصال (vless://… یا trojan://…)</span>
        <span data-hint="socks"  @if(! in_array($curType, ['socks','wireguard'], true)) style="display:none" @endif>— رمز/کلید (اختیاری)</span>
      </label>
      {{-- 🔴 عمداً هرگز repopulate نمی‌شود (حتی old): اعتبارنامه هیچ‌وقت در HTML
           نمی‌آید. اگر فرم خطا خورد، فقط همین یک فیلد دوباره وارد می‌شود. --}}
      <textarea name="secret" rows="4" dir="ltr" spellcheck="false" autocomplete="off"
                placeholder="{{ $editing ? 'برای حفظِ مقدارِ فعلی خالی بگذار' : 'این‌جا مقدار را بگذار' }}"
                style="{{ $inp }};font-family:ui-monospace,monospace;font-size:12px;resize:vertical"></textarea>
      @if($editing)
        <p style="margin:6px 2px 0;font-size:11.5px;color:var(--dim)">
          🔒 اعتبارنامه‌ی ذخیره‌شده هرگز نشان داده نمی‌شود.
          {{ $upstream->hasSecret() ? 'مقدار ذخیره شده' : 'هنوز چیزی ذخیره نشده' }} —
          فقط اگر می‌خواهی عوضش کنی این‌جا مقدارِ تازه بگذار.
        </p>
      @endif
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;align-items:end">
      <div>
        <label style="{{ $lbl }}">اولویت (کوچک‌تر = مقدم‌تر)</label>
        <input type="number" name="priority" dir="ltr" min="1" max="65535"
               value="{{ old('priority', $upstream->priority ?: 100) }}" style="{{ $inp }}">
      </div>
      <div>
        <label style="display:flex;align-items:center;gap:9px;font-size:13px;color:var(--text);cursor:pointer;padding:9px 0">
          <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $upstream->enabled ?? true))>
          فعال باشد
        </label>
      </div>
    </div>

    <div>
      <label style="{{ $lbl }}">یادداشت (اختیاری)</label>
      <input type="text" name="note" dir="auto" maxlength="2000"
             value="{{ old('note', $upstream->note) }}" style="{{ $inp }}">
    </div>

    <div style="display:flex;gap:10px;align-items:center;margin-top:4px">
      <button type="submit" class="ad-badge"
              style="background:rgba(34,211,238,.20);color:var(--text);border:0;cursor:pointer;font-size:13.5px;padding:10px 22px">
        {{ $editing ? 'ذخیره‌ی تغییرات' : 'افزودن' }}
      </button>
      <a href="{{ route('admin.exit-upstreams') }}" style="color:var(--muted);font-size:13px;text-decoration:none">انصراف</a>
    </div>
  </form>
</div>

{{-- نمایش/پنهانِ فیلدها بر اساسِ نقش و نوع — بی‌هیچ مقدارِ Bladeی داخلِ اسکریپت --}}
<script>
(function () {
  var role = document.getElementById('up-role');
  var type = document.getElementById('up-type');
  if (!role || !type) { return; }

  var HOST = ['ssh', 'socks', 'wireguard'];
  var LINK = ['vless', 'trojan'];

  function show(el, on) { if (el) { el.style.display = on ? '' : 'none'; } }
  function inList(list, v) { return list.indexOf(v) !== -1; }

  function sync() {
    var r = role.value, t = type.value;
    show(document.querySelector('[data-group="country"]'), r === 'exit');
    show(document.querySelector('[data-group="host"]'), inList(HOST, t));
    show(document.querySelector('[data-group="sni"]'), inList(LINK, t));
    show(document.querySelector('[data-hint="ssh"]'), t === 'ssh');
    show(document.querySelector('[data-hint="link"]'), inList(LINK, t));
    show(document.querySelector('[data-hint="socks"]'), t === 'socks' || t === 'wireguard');
  }

  role.addEventListener('change', sync);
  type.addEventListener('change', sync);
  sync();
})();
</script>
@endsection
