{{--
  کارتِ سرور — «هویتِ شبکه».

  یک سرور را با نشانی‌اش می‌شناسند، نه با مبلغش. پس IPv4/IPv6 و خطِ SSH قهرمانِ
  کارت‌اند و هر سه در جعبهٔ اسکرول‌شوندهٔ **خودشان** می‌نشینند.

  🔴 و این خودش رفعِ باگِ موبایل است: تا امروز همین سه مقدار داخلِ
  `<td colspan="6">` و در دلِ `.pnl-tw{overflow-x:auto}` بودند، پس مشتری روی
  گوشی باید کلِ جدول را افقی می‌کشید تا IP خودش را ببیند.

  ورودی: $s (Service)، $secBalance (موجودیِ اعتبار، یک‌بار محاسبه‌شده)
--}}
@php
  $ci    = $s->cloudInstance;
  $cloud = $s->isCloud();

  /* ⚠️ «تحویل‌شده» **یک** تعریف دارد و از مدل می‌آید: وضعیتِ زندهٔ زیرساخت
     به‌علاوهٔ IP. شرطِ فقط-IP یک بار باعث شد فهرست بگوید «تحویل شد» و صفحهٔ
     مدیریتِ همان سرور بگوید «در حالِ ساخت» — دو حقیقت در یک پنل. */
  $ready = (bool) $ci?->isDelivered();

  /* سفارشی که محافظِ سوءاستفاده نگهش داشته: نه شکست‌خورده، نه در حالِ ساخت.
     هیچ تماسی با زیرساخت نرفته و تا تصمیمِ یک آدم هم نمی‌رود. */
  $onHold = $s->provision_status === 'manual';

  $loc   = $ci?->location();
  $specs = (array) ($ci->specs ?? []);

  /* 🔴 `root@{{ ... }}` در Blade دستورِ **فرار** است و بی‌هیچ خطایی بدونِ IP
     چاپ می‌شود. رشته این‌جا ساخته می‌شود. تستی هم هست که مقدارِ واقعی را
     می‌سنجد، نه صرفاً کدِ ۲۰۰. */
  /* ⚠️ و آدرس از `address()` می‌آید نه از ستونِ خام: ماشینِ پشتِ NAT آدرسِ
     خصوصی دارد و آنچه به کار می‌آید «IP عمومی : پورتِ فورواردشده» است.
     همان تیکتِ «آی‌پی خصوصی است» — یک بار در صفحهٔ مدیریتِ سرور رفع شد ولی
     این کارت جا ماند و مشتری دوباره همان را در داشبورد دید. */
  $sshCmd = $ready ? $ci->sshCommand() : null;
  $addr   = $ci?->address();

  $stageIdx = $ci?->stageIndex() ?? 0;
  $steps = [
    ['t' => __('ui.cs_stage_ordered'),   'd' => __('ui.cs_stage_ordered_d')],
    ['t' => __('ui.cs_stage_building'),  'd' => __('ui.cs_stage_building_d')],
    ['t' => __('ui.cs_stage_finishing'), 'd' => __('ui.cs_stage_finishing_d')],
    ['t' => __('ui.cs_stage_ready'),     'd' => __('ui.cs_stage_ready_d')],
  ];

  /* ساعتِ باقی‌مانده از موجودیِ **یک‌بار خوانده‌شده** مشتق می‌شود.
     `Service::hoursLeft()` هر بار یک SUM روی دفترِ اعتبار می‌زند؛ صدا زدنش در
     حلقه یعنی یک پرس‌وجو به‌ازای هر سرورِ ساعتی. */
  $hours = $s->isHourly()
      ? \App\Support\PanelSections::hoursLeft($s, (int) ($secBalance ?? 0))
      : null;
@endphp

<article class="svc-card svc-card-srv {{ $ready ? '' : 'is-wait' }}">

  <header class="svc-card-h">
    <span class="pnl-svc-ic"><svg class="icon"><use href="#i-{{ $cloud ? 'cloud' : 'server' }}"/></svg></span>
    <span class="svc-card-t">
      <b>{{ $s->name }}</b>
      {{-- 🔴 نشانی از accessHost — hostnameِ خام نامِ زیرساخت را لو می‌داد
           (گزارشِ کارفرما: «…salad.cloud» در فهرستِ سرویس‌های مشتری) --}}
      @php $cardHost = $ci?->accessHost() ?? $ci?->hostname; @endphp
      @if($cardHost)<small dir="ltr">{{ $cardHost }}</small>
      @elseif($s->server?->hostname)<small dir="ltr">{{ $s->server->hostname }}</small>@endif
    </span>
    @include('account.partials.status-pill', ['s' => $s])
  </header>

  {{-- ⚠️ مکان همیشه از `CloudLocation` می‌آید. `provider`، `provider_ref` و
       `provider_location` عمداً `$hidden`اند و هرگز رندر نمی‌شوند. --}}
  <div class="svc-chips">
    @if($loc)<span class="pnl-pill mute">@include('partials.flag', ['flagSrc' => $loc->flagSvg(), 'flagEmoji' => $loc->flagEmoji(), 'flagSize' => 18]) {{ $loc->label() }}</span>@endif
    @if($s->isHourly())<span class="pnl-pill info">{{ __('ui.srv_hourly') }}</span>@endif
    @if($ci && $ready)<span class="pnl-pill {{ $ci->status === 'running' ? 'ok' : 'mute' }}">{{ $ci->statusLabel() }}</span>@endif
  </div>

  @if($cloud && $onHold)
    {{-- ── نگه‌داشته‌شده برای بازبینی ──
         🔴 فهرستِ چهارمرحله‌ای این‌جا **دروغ** بود: مرحلهٔ جاری‌اش می‌گفت
         «سفارشِ شما نزدِ زیرساخت ثبت شد» در حالی که محافظ پیش از هر تماسی
         برگشته و هیچ سفارشی نزدِ هیچ زیرساختی ثبت نشده. --}}
    <p class="cb-warn">{{ __('ui.cs_hold_p') }}</p>
  @elseif($cloud && ! $ready)
    {{-- ── در حالِ ساخت ──
         چهار مرحلهٔ **گسسته**، بی‌هیچ درصدِ ساختگی: مشتری‌ای که روی «۷۰٪» گیر
         می‌کند نتیجه می‌گیرد سایت خراب است. --}}
    <ol class="cb-steps">
      @foreach($steps as $i => $st)
        <li class="cb-step {{ $i < $stageIdx ? 'is-done' : ($i === $stageIdx ? 'is-now' : 'is-todo') }}">
          <span class="cb-dot" aria-hidden="true"></span>
          <span class="cb-txt"><b>{{ $st['t'] }}</b><small>{{ $st['d'] }}</small></span>
        </li>
      @endforeach
    </ol>
  @elseif($cloud)
    {{-- ── نشانیِ شبکه — هرکدام جعبهٔ اسکرولِ خودش، پس صفحه هرگز افقی نمی‌رود --}}
    <div class="svc-net">
      <div class="svc-net-r"><small>{{ $ci->hasPrivateIp() ? __('ui.cs_address') : 'IPv4' }}</small><code dir="ltr" class="copyable" title="{{ __('ui.svc_copy_title') }}">{{ $addr ?: '—' }}</code></div>
      @if($ci->ipv6)
        <div class="svc-net-r"><small>IPv6</small><code dir="ltr" class="copyable" title="{{ __('ui.svc_copy_title') }}">{{ $ci->ipv6 }}</code></div>
      @endif
      <div class="svc-net-r"><small>{{ __('ui.cs_ssh_label') }}</small><code dir="ltr" class="copyable" title="{{ __('ui.svc_copy_title') }}">{{ $sshCmd }}</code></div>
    </div>
  @else
    {{-- VPS/اختصاصیِ دستی: هیچ کنترلی نداریم که وانمود کنیم داریم --}}
    <p class="svc-note">{{ __('ui.srv_manual_note') }}</p>
  @endif

  @if($specs !== [])
    <div class="svc-facts">
      <div class="svc-fact"><small>{{ __('ui.cs_cpu') }}</small><b>{{ fa_num($specs['vcpu'] ?? '—') }} {{ __('ui.cs_cores') }}</b></div>
      <div class="svc-fact"><small>{{ __('ui.cs_ram') }}</small><b dir="ltr">{{ isset($specs['ram_mb']) ? fa_num(round($specs['ram_mb'] / 1024, 1)).' GB' : '—' }}</b></div>
      <div class="svc-fact"><small>{{ __('ui.cs_disk') }}</small><b dir="ltr">{{ fa_num($specs['disk_gb'] ?? '—') }} GB {{ strtoupper($specs['disk_type'] ?? '') }}</b></div>
    </div>
  @endif

  {{-- ── صورت‌حساب — به همان مدلی که واقعاً هست، نه به زورِ ستونِ ماهانه ── --}}
  <div class="svc-facts">
    @if($s->isHourly())
      <div class="svc-fact"><small>{{ __('ui.cvb_hourly_t') }}</small><b>{{ cloud_hourly_price((int) $s->hourly_rate_irt) }}{{ __('ui.cvb_hourly_per') }}</b></div>
      <div class="svc-fact {{ $hours !== null && $hours < 24 ? 'is-warn' : '' }}">
        <small>{{ __('ui.svc_hours_left') }}</small><b>~{{ fa_num($hours) }} {{ __('ui.srv_credit_hours') }}</b>
      </div>
    @else
      <div class="svc-fact"><small>{{ __('ui.svc_th_due') }}</small><b>{{ sdate($s->next_due_at) }}</b></div>
      <div class="svc-fact"><small>{{ __('ui.svc_th_amount') }}</small><b>{{ invoice_money($s->total(), $s->currency_code) }} <em>{{ $s->cycleLabel() }}</em></b></div>
    @endif
  </div>

  {{-- ── یک عملِ اصلی، و بس ──
       سه دکمهٔ «کنسول/روشن-خاموش/نصبِ دوباره»ی قبلی به `#console`، `#power` و
       `#rebuild` می‌رفتند — و هیچ‌کدام از آن لنگرها روی صفحهٔ مقصد وجود ندارد.
       یعنی هر چهار دکمه در عمل یک کار می‌کردند. کنترل‌ها همان‌جا می‌مانند،
       جایی که `denyIfNotWritable()` صاحبشان است. --}}
  <div class="pnl-acts">
    @if($cloud)
      <a class="pnl-btn primary" href="{{ lroute('account.cloud.show', $s) }}">
        <svg class="icon"><use href="#i-server"/></svg>{{ __('ui.svc_manage_server') }}
      </a>
    @elseif($s->panel_url)
      <a class="pnl-btn primary" href="{{ $s->panel_url }}" target="_blank" rel="noopener">
        <svg class="icon"><use href="#i-key"/></svg>{{ __('ui.hst_login_panel') }}
      </a>
    @else
      <a class="pnl-btn" href="{{ lroute('account.tickets') }}">
        <svg class="icon"><use href="#i-lifebuoy"/></svg>{{ __('ui.srv_open_ticket') }}
      </a>
    @endif
  </div>

  @include('account.partials.svc-actions', ['s' => $s, 'kind' => 'server'])
</article>
