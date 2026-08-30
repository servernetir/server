@php
  /*
  |----------------------------------------------------------------------------
  | مرجعِ APIِ تونلِ سرورِ اکسیت — سه‌زبانه
  |----------------------------------------------------------------------------
  |
  | 🔴 چرا صفحهٔ جداست: `/developers` عنوانش «مرجع API نمایندگی دامنه» است.
  | چهار ردیفِ تونل مدتی داخلِ جدولِ endpointهای همان صفحه بود و از نظرِ فنی
  | «مستند» بود — ولی مشتری‌ای که برای ساختِ کاربرِ WireGuard می‌رفت، چهار خط
  | میانِ چهارده خطِ دامنه می‌دید و هیچ توضیحی نه از جریان، نه از فیلدها، نه از
  | ایجنتِ روتر. **فهرست‌شدن مستندسازی نیست.**
  |
  | ⚠️ هیچ عددی در این فایل تایپ نشده: سقفِ اکانت از `TunnelProfile::MAX_PEERS`،
  | فاصلهٔ پیمایش از `TunnelAgentScript::INTERVAL`، و دسترسی‌ها از
  | `CustomerApiToken::ABILITIES` می‌آیند. عددِ دستی، اولین باری که تنظیمات عوض
  | شود دروغ می‌گوید.
  |
  | ⚠️ نثر در `resources/content/developers-tunnel.php` است (سه زبان) — به همان
  | دلیلی که در `developers.blade.php` نوشته شده: `/en/…` و `/tr/…` از قبل
  | ساخته می‌شوند و صفحهٔ فارسی‌تنها از نبودِ صفحه بدتر است.
  */
  $c   = require resource_path('content/developers-tunnel.php');
  $L   = fn (string $k) => lc($c[$k] ?? []);
  $isFa = app()->getLocale() === 'fa';
  $n   = fn ($v) => $isFa ? fa_num((string) $v) : (string) $v;

  $print = (bool) request()->boolean('print');
  $base  = url('/api/v1');

  $maxPeers = \App\Support\TunnelProfile::MAX_PEERS;
  $interval = \App\Support\TunnelAgentScript::INTERVAL;

  $endpoints = [
      ['GET',   '/tunnel/servers',                  'tunnel:read',  ['fa'=>'سرورهای اکسیت که تونل TCP دارند','en'=>'exit servers with a TCP tunnel','tr'=>'TCP tunelli cikis sunuculari']],
      ['GET',   '/tunnel/{service}/accounts',       'tunnel:read',  ['fa'=>'فهرست کاربران و وضعیتشان','en'=>'users and their state','tr'=>'kullanicilar ve durumlari']],
      ['POST',  '/tunnel/{service}/accounts',       'tunnel:write', ['fa'=>'ساخت کاربر — کلید خصوصی فقط یک بار','en'=>'create user — private key returned once','tr'=>'kullanici olustur — anahtar bir kez']],
      ['DELETE','/tunnel/{service}/accounts/{name}','tunnel:write', ['fa'=>'حذف کاربر','en'=>'delete user','tr'=>'kullanici sil']],
      ['GET',   '/tunnel/{service}/agent',          'tunnel:read',  ['fa'=>'وضعیت اجرای خودکار روی روتر','en'=>'auto-apply status','tr'=>'otomatik uygulama durumu']],
      ['POST',  '/tunnel/{service}/agent',          'tunnel:write', ['fa'=>'فعال‌سازی اجرای خودکار — توکن فقط یک بار','en'=>'enable auto-apply — token returned once','tr'=>'otomatik uygulamayi ac']],
  ];

  $toc = ['s1','s2','s3','s4','s5','s6','s7'];
@endphp

@extends('layouts.site')

@section('title', $L('title').' — '.__('ui.brand'))
@section('description', $L('meta_desc'))

@section('content')

@php
  $sdSteps = [];
  foreach ((array) $L('s1_steps') as $i => $st) {
      $sdSteps[] = ['@type' => 'HowToStep', 'position' => $i + 1, 'text' => $st];
  }
@endphp
<script type="application/ld+json">{!! schema_ld(['name' => $L('title'), 'step' => $sdSteps], 'HowTo') !!}</script>
<script type="application/ld+json">{!! schema_ld(['itemListElement' => [
  ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => lroute('home')],
  ['@type' => 'ListItem', 'position' => 2, 'name' => $L('back'), 'item' => lroute('developers')],
  ['@type' => 'ListItem', 'position' => 3, 'name' => $L('title'), 'item' => url()->current()],
]], 'BreadcrumbList') !!}</script>

<section class="hero hero-sub dev-hero">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ $L('badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ $L('title') }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ $L('lead') }}</p>
      <p class="dev-printurl">{{ url()->current() }} · {{ sdate(now()) }}</p>
      <div class="hero-ctas reveal" style="transition-delay:.24s">
        <a class="btn btn-primary" href="#s1"><span>{{ lc($c['s1']) }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        <a class="btn btn-glass" href="{{ url()->current() }}?print=1" target="_blank" rel="noopener" title="{{ $L('print_hint') }}">
          <svg class="icon" style="width:16px;height:16px"><use href="#i-file"/></svg>{{ $L('print') }}
        </a>
      </div>
    </div>
  </div>
</section>

<section class="section dev-wrap">
  <div class="container dev-grid">

    <aside class="dev-toc" aria-label="{{ $L('toc_title') }}">
      <b>{{ $L('toc_title') }}</b>
      <ol>
        @foreach($toc as $i => $k)
          <li><a href="#{{ $k }}"><span class="dev-toc-n">{{ $n($i + 1) }}</span>{{ lc($c[$k]) }}</a></li>
        @endforeach
      </ol>
    </aside>

    <div class="dev-doc">

      {{-- فهرستِ مسیرها، بالای صفحه: خواننده اول باید بداند کلاً چه چیزی هست --}}
      <div class="dev-eps">
        @foreach($endpoints as [$m, $path, $scope, $desc])
          <div class="dev-ep">
            <span class="dev-m dev-m-{{ strtolower($m) }}">{{ $m }}</span>
            <code class="dev-ep-p" dir="ltr">/api/v1{{ $path }}</code>
            <span class="dev-ep-d">{{ lc($desc) }}</span>
            <code class="dev-ep-s" dir="ltr">{{ $scope }}</code>
          </div>
        @endforeach
      </div>

      {{-- ═════════ ۱ ═════════ --}}
      <h2 id="s1"><span class="dev-h-n">{{ $n(1) }}</span>{{ lc($c['s1']) }}</h2>
      <p>{{ $L('s1_p') }}</p>
      <ol class="dev-steps">
        @foreach((array) $L('s1_steps') as $st)<li>{{ $st }}</li>@endforeach
      </ol>
      <pre dir="ltr" class="dev-code" data-copy>curl -H "Authorization: Bearer sn_xxxxxxxx" \
     {{ $base }}/tunnel/servers</pre>
      <div class="dev-note dev-warn">{{ $L('s1_warn') }}</div>

      {{-- ═════════ ۲ ═════════ --}}
      <h2 id="s2"><span class="dev-h-n">{{ $n(2) }}</span>{{ lc($c['s2']) }}</h2>
      <p>{{ $L('s2_p') }}</p>
      <pre dir="ltr" class="dev-code" data-copy>GET {{ $base }}/tunnel/servers

{"ok": true, "data": [{
  "service_id": 49,
  "name": "blackwood-vip-1",
  "status": "active", "writable": true,
  "host": "sn-571100.servernet.cloud", "port": 8443,
  "subnet": "10.77.0.0/24", "next_ip": "10.77.0.5",
  "accounts": 7, "max": {{ $maxPeers }},
  "agent": {"installed": true, "alive": true,
            "last_seen_at": "2026-08-24T08:16:18+00:00",
            "pending_jobs": 0}
}]}</pre>

      <div class="dev-tablewrap"><table class="dev-table">
        <tbody>
          @foreach((array) $L('s2_fields') as $k => $v)
            <tr><td><code dir="ltr">{{ $k }}</code></td><td>{{ $v }}</td></tr>
          @endforeach
        </tbody>
      </table></div>
      <div class="dev-note dev-warn">{{ $L('s2_warn') }}</div>

      {{-- ═════════ ۳ ═════════ --}}
      <h2 id="s3"><span class="dev-h-n">{{ $n(3) }}</span>{{ lc($c['s3']) }}</h2>
      <p>{{ $L('s3_p') }}</p>
      <ol class="dev-steps">
        @foreach((array) $L('s3_steps') as $st)<li>{{ $st }}</li>@endforeach
      </ol>
      <pre dir="ltr" class="dev-code" data-copy>POST {{ $base }}/tunnel/49/agent

{"ok": true, "data": {
  "token": "sna_49_xxxxxxxx",
  "replaced": false,
  "install": [
    "/tool fetch url=\"{{ url('/agent/tunnel') }}/install\" http-header-field=\"X-Agent-Token: sna_49_xxxxxxxx\" dst-path=snet-agent.rsc",
    "/import file-name=snet-agent.rsc"
  ]
}}</pre>
      <div class="dev-note dev-warn">{{ $L('s3_warn') }}</div>

      <p>{{ $L('s3_alive') }}</p>
      <pre dir="ltr" class="dev-code" data-copy>GET {{ $base }}/tunnel/49/agent

{"ok": true, "data": {
  "installed": true, "alive": true,
  "last_seen_at": "2026-08-24T08:16:18+00:00",
  "pending_jobs": 0
}}</pre>
      <p class="dev-sub">{{ $L('s3_safe') }}</p>

      {{-- ═════════ ۴ ═════════ --}}
      <h2 id="s4"><span class="dev-h-n">{{ $n(4) }}</span>{{ lc($c['s4']) }}</h2>
      <pre dir="ltr" class="dev-code" data-copy>POST {{ $base }}/tunnel/49/accounts
{"name": "ali-mobile"}

{"ok": true, "data": {
  "name": "ali-mobile", "ip": "10.77.0.5",
  "public_key": "...", "private_key": "...",
  "delivery": {"mode": "agent", "status": "pending",
               "job_id": 41, "agent_alive": true},
  "router_command": "/interface/wireguard/peers/add ...",
  "config": { ... sing-box ... }
}}</pre>

      <h3>{{ $L('s4_in') }}</h3>
      <div class="dev-tablewrap"><table class="dev-table">
        <tbody>
          @foreach((array) $L('s4_fields') as $k => $v)
            <tr><td><code dir="ltr">{{ $k }}</code></td><td>{{ $v }}</td></tr>
          @endforeach
        </tbody>
      </table></div>

      <div class="dev-note dev-warn">{{ $L('s4_key') }}</div>
      <p>{{ $L('s4_cfg') }}</p>
      <p>{{ $L('s4_delivery') }}</p>
      <div class="dev-note dev-warn">{{ $L('s4_201') }}</div>

      {{-- ═════════ ۵ ═════════ --}}
      <h2 id="s5"><span class="dev-h-n">{{ $n(5) }}</span>{{ lc($c['s5']) }}</h2>
      <p>{{ $L('s5_p') }}</p>
      <pre dir="ltr" class="dev-code" data-copy>GET {{ $base }}/tunnel/49/accounts

{"ok": true, "data": {
  "service_id": 49, "next_ip": "10.77.0.6",
  "agent": {"installed": true, "alive": true, "pending_jobs": 0},
  "accounts": [
    {"name": "ali-mobile", "ip": "10.77.0.5",
     "public_key": "...", "issued_at": "...",
     "state": "active"}
  ]
}}</pre>
      <div class="dev-states">
        @foreach((array) $L('s5_states') as $k => $v)
          <span><code dir="ltr">{{ $k }}</code> {{ $v }}</span>
        @endforeach
      </div>
      <p class="dev-sub">{{ $L('s5_legacy') }}</p>

      {{-- ═════════ ۶ ═════════ --}}
      <h2 id="s6"><span class="dev-h-n">{{ $n(6) }}</span>{{ lc($c['s6']) }}</h2>
      <pre dir="ltr" class="dev-code" data-copy>DELETE {{ $base }}/tunnel/49/accounts/ali-mobile

{"ok": true, "data": {
  "name": "ali-mobile",
  "delivery": {"mode": "agent", "status": "pending"},
  "router_command": "/interface/wireguard/peers/remove [find name=\"ali-mobile\"]"
}}</pre>
      <div class="dev-note dev-warn">{{ $L('s6_p') }}</div>

      {{-- ═════════ ۷ ═════════ --}}
      <h2 id="s7"><span class="dev-h-n">{{ $n(7) }}</span>{{ lc($c['s7']) }}</h2>
      <p>{{ $L('s7_p') }}</p>
      <div class="dev-tablewrap"><table class="dev-table">
        <tbody>
          @foreach((array) $L('s7_errors') as $k => $v)
            <tr><td><code dir="ltr">{{ $k }}</code></td><td>{{ $v }}</td></tr>
          @endforeach
        </tbody>
      </table></div>

      @php($rows = (array) $L('s7_rows'))
      <h3>{{ $L('s7_limits') }}</h3>
      <div class="dev-tablewrap"><table class="dev-table">
        <tbody>
          <tr><td>{{ $rows['read'] ?? '' }}</td><td dir="ltr">{{ $n(120) }} / {{ $n(1) }} {{ $rows['min'] ?? '' }}</td></tr>
          <tr><td>{{ $rows['write'] ?? '' }}</td><td dir="ltr">{{ $n(20) }} / {{ $n(1) }} {{ $rows['min'] ?? '' }}</td></tr>
          <tr><td>{{ $rows['agent'] ?? '' }}</td><td dir="ltr">{{ $n(5) }} / {{ $n(1) }} {{ $rows['min'] ?? '' }}</td></tr>
          <tr><td>{{ $rows['peers'] ?? '' }}</td><td dir="ltr">{{ $n($maxPeers) }}</td></tr>
        </tbody>
      </table></div>

      <p class="dev-sub" style="margin-top:26px">
        <a href="{{ lroute('developers') }}">← {{ $L('back') }}</a>
      </p>

    </div>
  </div>
</section>

{{-- همان بلوکِ کپی/چاپِ صفحهٔ مرجعِ دامنه — یک تعریف، دو صفحه. --}}
@include('partials.dev-copy')
@endsection
