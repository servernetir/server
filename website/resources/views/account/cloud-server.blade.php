@extends('panel.layout')
@section('title', __('ui.cs_title'))

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

  /* ═══ 🔴 «آماده» یک تعریف دارد و از مدل می‌آید ═══
     قبلاً این صفحه فقط `status === 'building'` را «در حالِ ساخت» می‌شمرد. ولی
     زیرساختِ دوم در حینِ ساخت `activating` می‌گوید و نگاشتِ ما هر رشتهٔ ناشناخته
     را `unknown` می‌کند — که در آن شرط **نمی‌افتاد**. نتیجه همان چیزی بود که
     کارفرما دید: پنل چیدمانِ «تحویل‌شده» را با `IP: —` نشان می‌داد در حالی که
     ماشین هنوز ساخته نشده بود.
     حالا وارونه است: صفحه فقط وقتی چیدمانِ تحویل را نشان می‌دهد که مدل بگوید
     تحویل شده (وضعیتِ زندهٔ زیرساخت + وجودِ IP). «نمی‌دانم» ⇒ در حالِ ساخت. */
  $ready    = (bool) $inst?->isDelivered();
  $stage    = $inst?->stage() ?? 'ordered';
  $stageIdx = $inst?->stageIndex() ?? 0;

  /* مرحله‌ها — هر چهار مرحله از یک واقعیتِ قابلِ اثبات می‌آیند.
     🔴 عمداً هیچ درصدی نیست. مشتری‌ای که روی «۷۰٪» گیر کند نتیجه می‌گیرد سایت
     خراب است؛ همان قاعدهٔ صفحهٔ /status این پروژه. */
  $steps = [
    ['k' => 'ordered',   't' => __('ui.cs_stage_ordered'),   'd' => __('ui.cs_stage_ordered_d')],
    ['k' => 'building',  't' => __('ui.cs_stage_building'),  'd' => __('ui.cs_stage_building_d')],
    ['k' => 'finishing', 't' => __('ui.cs_stage_finishing'), 'd' => __('ui.cs_stage_finishing_d')],
    ['k' => 'ready',     't' => __('ui.cs_stage_ready'),     'd' => __('ui.cs_stage_ready_d')],
  ];
@endphp

{{-- ═══ رشته‌های JS سه‌زبانه ═══
     پیام‌های داینامیکِ اسکریپتِ پایینِ صفحه از window.T خوانده می‌شوند تا هیچ
     متنِ فارسیِ سخت‌کدی در JS نماند. آرایه را در یک بلوکِ php می‌سازیم (نه
     درون‌خطی در دستورِ json) چون آرایهٔ درون‌خطی پارسرِ Blade را می‌شکند.

     🔴 و نامِ آن دستورها را این‌جا **با علامتِ @ ننویس** — حتی داخلِ همین کامنت.

     نسخهٔ قبلیِ همین توضیح، کلِ سربرگِ صفحه را خورد. Blade پیش از هر کاری
     بلوک‌های خامِ php را بیرون می‌کشد و آن جستجو **داخلِ کامنت‌ها را هم
     می‌بیند**؛ پس نامِ دستور در متنِ توضیح، یک «شروعِ بلوک»ِ واقعی شمرده شد و به
     پایان‌بندِ چند خط پایین‌تر جفت خورد. هرچه بینشان بود بی‌صدا حذف شد: بلوکِ
     رشته‌های JS، اسکریپتِ window.T، و کلِ `<div class="pnl-head">`.

     صفحه ۲۰۰ می‌داد و هیچ خطایی نبود. تنها بازمانده یک `</div>`ِ یتیم بود که
     `.pnl-main` را زودتر می‌بست؛ در نتیجه `</div>`ِ بعدیِ layout به‌جای آن
     `.pnl-layout` را می‌بست و `<aside class="pnl-side">` از گرید بیرون می‌افتاد
     و به تهِ صفحه سُر می‌خورد. کارفرما: «کاملاً بهم خورده».
     نگهبانش: tests/Feature/CloudServerPageLayoutTest.php --}}
@php
  $T = [
    'copied'            => __('ui.cs_js_copied'),
    'pw_show'           => __('ui.cs_pw_show'),
    'pw_hide'           => __('ui.cs_pw_hide'),
    'last_check_now'    => __('ui.cs_js_last_check_now'),
    'traffic_month'     => __('ui.cs_js_traffic_month'),
    'chart_aria'        => __('ui.cs_js_chart_aria'),
    'chart_none'        => __('ui.cs_js_chart_none'),
    'chart_unavailable' => __('ui.cs_js_chart_unavailable'),
    'last_value'        => __('ui.cs_js_last_value'),
    'pct'               => __('ui.cs_js_pct'),
  ];
@endphp
<script>window.T = @json($T);</script>

<div class="pnl-head">
  <div>
    <nav class="blog-crumbs" style="margin-bottom:8px">
      {{-- ⚠️ `lroute` و نه `route`: نسخهٔ خام، مشتریِ /en و /tr را به پنلِ
           فارسی می‌انداخت. --}}
      <a href="{{ lroute('account.home') }}">{{ __('ui.cs_crumb_panel') }}</a><span>/</span>
      <a href="{{ lroute('account.servers') }}">{{ __('ui.cs_crumb_services') }}</a><span>/</span>
      <span>{{ __('ui.cs_crumb_cloud') }}</span>
    </nav>
    <h1>{{ $service->name }}</h1>
    <p>
      @if($inst?->ipv4)<span dir="ltr">{{ $inst->ipv4 }}</span> · @endif
      {{ $loc?->label() ?? '—' }} · {{ $osLbl }}
    </p>
  </div>
  <span class="pnl-pill {{ $inst?->status === 'running' ? 'ok' : '' }}" id="st-pill"
        style="font-size:12.5px;padding:7px 15px;color:{{ $inst?->statusColor() ?? 'var(--dim)' }}">
    {{-- تا پیش از تحویل، نشانگر **مرحله** را می‌گوید نه رشتهٔ خامِ زیرساخت؛
         وگرنه مشتری روی سرورِ در حالِ ساخت کلمهٔ «نامشخص» می‌دید. --}}
    {{ $ready ? $inst->statusLabel() : ($inst ? __('ui.cs_stage_'.$stage) : __('ui.cs_status_preparing')) }}
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

{{-- ═══ سرور در حالِ ساخت — تجربهٔ زنده ═══
     مشتری پول داده و چیزی نمی‌بیند؛ اگر این حالت را صریح نگوییم، فکر می‌کند
     خرید ناموفق بوده و تیکت می‌زند.

     ⚠️ همان الگوی پنلِ پرداختِ رمزارز (`cy-box` در account/invoice.blade.php):
     صفحه **خودش تصمیم نمی‌گیرد** آماده شده یا نه — فقط وضعیتی را که سرور
     می‌گوید نشان می‌دهد. حکم مالِ زیرساخت است. الگوی دوم نمی‌سازیم. --}}
@if(! $ready && $service->provision_status === 'manual')
  {{-- ═══ 🔴 نگه‌داشته‌شده برای بازبینی ═══

       چهار جملهٔ بلوکِ «در حالِ ساخت» برای این حالت **وعدهٔ دروغ** بودند:
       «کمتر از دو دقیقه»، «وضعیت زنده به‌روز می‌شود»، «به‌محضِ آماده شدن ایمیل
       می‌رود»، «می‌توانید صفحه را ببندید». محافظ پیش از هر تماسی برمی‌گردد، پس
       هیچ‌کدام اتفاق نمی‌افتد و هیچ‌چیز هم زنده به‌روز نمی‌شود.

       ⚠️ علتِ محافظ این‌جا **نوشته نمی‌شود** — عددِ سقف نباید به بیرون درز کند.
       فقط مدیر آن را در اعلان و در تاریخچهٔ سرویس می‌بیند. --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h">
      <h2>{{ __('ui.cs_hold_h') }}</h2>
    </div>
    <div class="pnl-sec-b">
      <p class="cb-lead">{{ __('ui.cs_hold_p') }}</p>
      <p class="cb-foot">{{ __('ui.cs_hold_next') }}</p>
    </div>
  </section>
@elseif(! $ready)
  <section class="pnl-sec">
    <div class="pnl-sec-h">
      <h2>{{ __('ui.cs_building_h') }}</h2>
      <span class="cb-live"><i></i>{{ __('ui.cs_build_live') }}</span>
    </div>
    <div class="pnl-sec-b">
      <p class="cb-lead">{!! __('ui.cs_building_p') !!}</p>

      <ol class="cb-steps" id="cb-steps" data-stage="{{ $stageIdx }}"
          data-status-url="{{ route('account.cloud.status', $service) }}">
        @foreach($steps as $i => $st)
          @php
            /* حالتِ اولیه در سمتِ سرور ساخته می‌شود، نه با جاوااسکریپت: اگر JS
               نرسد یا بلاک شود، مشتری باید همین حالا مرحلهٔ درست را ببیند. */
            $cls = $i < $stageIdx ? 'is-done' : ($i === $stageIdx ? 'is-now' : 'is-todo');
          @endphp
          <li class="cb-step {{ $cls }}" data-i="{{ $i }}">
            <span class="cb-dot" aria-hidden="true"></span>
            <span class="cb-txt">
              <b>{{ $st['t'] }}</b>
              <small>{{ $st['d'] }}</small>
            </span>
          </li>
        @endforeach
      </ol>

      <p class="cb-foot">{{ __('ui.cs_build_leave') }}</p>

      @if($inst?->last_error)
        <p class="cb-warn">{{ __('ui.cs_building_delay') }}</p>
      @endif
    </div>
  </section>
@else

{{-- ═══ دسترسی ═══ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.cs_access_h') }}</h2></div>
  <div class="pnl-sec-b">
    <div class="cs-grid">
      <div class="cs-kv"><small>IPv4</small><b dir="ltr" class="cs-copy" data-copy="{{ $inst->ipv4 }}">{{ $inst->ipv4 ?: '—' }}</b></div>
      @if($inst->ipv6)
        <div class="cs-kv"><small>IPv6</small><b dir="ltr" class="cs-copy" data-copy="{{ $inst->ipv6 }}">{{ $inst->ipv6 }}</b></div>
      @endif
      <div class="cs-kv"><small>{{ __('ui.cs_user') }}</small><b dir="ltr">root</b></div>
      <div class="cs-kv"><small>{{ __('ui.cs_location') }}</small><b>@if($loc)@include('partials.flag', ['flagSrc' => $loc->flagSvg(), 'flagEmoji' => $loc->flagEmoji(), 'flagSize' => 18]) @endif{{ $loc?->label() ?? '—' }}</b></div>
    </div>

    @if($inst->ipv4)
      {{-- ⚠️ رشته را در PHP می‌سازیم، نه در قالب. اگر «root» و «{{» با یک @
           به هم بچسبند، Blade آن را **دستورِ فرار** می‌فهمد و به‌جای IP، خودِ
           عبارتِ آکولادی را چاپ می‌کند — همان تلهٔ آشنای این پروژه. --}}
      @php $sshCmd = 'ssh root'.'@'.$inst->ipv4; @endphp
      <div class="cs-ssh">
        <small>{{ __('ui.cs_ssh_label') }}</small>
        <code dir="ltr" class="cs-copy" data-copy="{{ $sshCmd }}">{{ $sshCmd }}</code>
      </div>
    @endif

    {{-- رمز فقط **یک بار** نشان داده می‌شود. صفحهٔ همیشه‌بازِ پنل روی لپ‌تاپِ
         مشترک، رمزِ root را به هر رهگذری می‌دهد. --}}
    @if($password)
      {{--
        رمز **پنهان** نشان داده می‌شود؛ چشم بازش می‌کند، کپی می‌بردش.

        ⚠️ چرا پنهانِ پیش‌فرض: همان دلیلی که «یک بار» را ساخت — صفحهٔ بازِ پنل
        روی لپ‌تاپِ مشترک یا وسطِ اشتراکِ صفحه، رمزِ root را به هر بیننده‌ای
        می‌دهد. حالا کاربر می‌تواند کپی کند **بی‌آنکه** رمز روی صفحه دیده شود.

        ⚠️ `data-copy` روی خودِ عنصر می‌مانَد (نه فقط متنِ دیده‌شده) تا کپی
        وقتی رمز پنهان است هم کار کند.
      --}}
      <div class="cs-pw">
        <div style="min-width:0">
          <small>{{ __('ui.cs_pw_label') }}</small>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <code dir="ltr" class="cs-pw-val is-masked" data-pw="{{ $password }}">••••••••••••</code>

            <button type="button" class="cs-pw-btn" data-pw-eye aria-label="{{ __('ui.cs_pw_show') }}" title="{{ __('ui.cs_pw_show') }}">
              <svg class="icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>
              </svg>
            </button>

            <button type="button" class="cs-pw-btn cs-copy" data-copy="{{ $password }}" aria-label="{{ __('ui.cs_pw_copy') }}" title="{{ __('ui.cs_pw_copy') }}">
              <svg class="icon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>
              </svg>
            </button>
          </div>
        </div>
        <span>{!! __('ui.cs_pw_once') !!}</span>
      </div>

      {{--
        ⚠️ مشتری رمز را گرفت ولی ممکن است نداند با آن چه کند. لینکِ آموزش
        همین‌جا، نه در گوشهٔ صفحه: لحظهٔ نیاز همین لحظه است.

        ⚠️ `lroute('docs', …)` و نه `route`: مقاله در هر سه زبان وجود دارد
        (`/docs/…`، `/en/docs/…`، `/tr/docs/…` — هر سه ۲۰۰ تأیید شد) و
        `route`ِ خام مشتریِ انگلیسی را به نسخهٔ فارسی می‌انداخت.

        ⚠️ اسلاگ **راستی‌آزمایی شده** است، نه حدس. نسخهٔ اولِ همین خط
        `docs.show` را صدا می‌زد که اصلاً نامِ روتِ موجود نیست، و اسلاگی داشت
        که هیچ مقاله‌ای با آن نبود — یعنی یک لینکِ ۴۰۴ در لحظه‌ای که مشتری
        بیشترین نیاز را به راهنما دارد. تستِ همراهش همین را قفل می‌کند.
      --}}
      <p style="margin:10px 0 0;font-size:12.5px;line-height:1.9">
        <a href="{{ lroute('docs', 'connecting-to-linux-server-ssh') }}" style="color:#22d3ee">{{ __('ui.cs_ssh_guide') }}</a>
      </p>
    @elseif($canReveal ?? false)
      {{--
        🔴 دکمه، نه نمایشِ خودکار.

        تا امروز رمز با **بارگذاریِ صفحه** نشان داده و همان‌جا سوزانده می‌شد.
        یعنی یک رفرش، یک prefetchِ مرورگر، یا ورودِ مدیر به پنلِ مشتری برای
        عیب‌یابی، پرچم را روشن می‌کرد و مشتری هیچ‌وقت چیزی نمی‌دید — سرور
        داشت و راهی به داخلش نداشت.

        قاعدهٔ «یک بار» سرِ جایش است؛ فقط لحظه‌اش را حالا کاربر انتخاب می‌کند.
      --}}
      <form method="post" action="{{ lroute('account.cloud.reveal', $service) }}" style="margin:14px 0 0">
        @csrf
        <button class="btn btn-glass" type="submit">{{ __('ui.cs_pw_reveal') }}</button>
        <p style="margin:8px 0 0;font-size:12px;color:var(--dim);line-height:1.9">{!! __('ui.cs_pw_once') !!}</p>
      </form>
    @elseif($inst->hasPassword())
      <p style="margin:14px 0 0;font-size:12.5px;color:var(--dim);line-height:1.9">
        {{ __('ui.cs_pw_hidden') }}
      </p>
    @elseif(($caps['reset_password'] ?? false) && blank($service->cloud_ssh_key_id))
      {{-- سروری که هیچ رمزی ندارد و کلیدِ SSH هم انتخاب نشده: مشتری سرور دارد و
           راهی به داخلش ندارد. سکوت این‌جا یعنی تیکتِ «سرورم کار نمی‌کند» —
           ایمیلِ تحویل هم عمداً رمز ندارد، پس این تنها راهنمای اوست. --}}
      <p style="margin:14px 0 0;font-size:12.5px;color:var(--warn);line-height:1.9">
        {{ __('ui.cs_pw_missing') }}
      </p>
    @endif
  </div>
</section>

{{-- ═══ کنترل ═══ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>{{ __('ui.cs_ctrl_h') }}</h2>
    <span style="font-size:12px;color:var(--dim)" id="st-seen">
      @if($inst->synced_at) {{ __('ui.cs_last_check') }} {{ $inst->synced_at->diffForHumans() }} @endif
    </span>
  </div>
  <div class="pnl-sec-b">
    <div class="pnl-acts">
      @if($inst->status !== 'running')
        <form method="post" action="{{ route('account.cloud.power', $service) }}">
          @csrf<input type="hidden" name="action" value="on">
          <button class="pnl-btn"><svg class="icon"><use href="#i-zap"/></svg>{{ __('ui.cs_power_on') }}</button>
        </form>
      @else
        <form method="post" action="{{ route('account.cloud.power', $service) }}">
          @csrf<input type="hidden" name="action" value="reboot">
          <button class="pnl-btn"><svg class="icon"><use href="#i-restore"/></svg>{{ __('ui.cs_reboot') }}</button>
        </form>
        <form method="post" action="{{ route('account.cloud.power', $service) }}"
              data-confirm="{{ __('ui.cs_confirm_off') }}" data-confirm-danger>
          @csrf<input type="hidden" name="action" value="off">
          <button class="pnl-btn danger"><svg class="icon"><use href="#i-zap"/></svg>{{ __('ui.cs_power_off') }}</button>
        </form>
      @endif

      @if($caps['console'] ?? false)
        <form method="post" action="{{ route('account.cloud.console', $service) }}">
          @csrf<button class="pnl-btn"><svg class="icon"><use href="#i-monitor"/></svg>{{ __('ui.cs_console') }}</button>
        </form>
      @endif

      @if($caps['reset_password'] ?? false)
        <form method="post" action="{{ route('account.cloud.password', $service) }}"
              data-confirm="{{ __('ui.cs_confirm_pw') }}">
          @csrf<button class="pnl-btn"><svg class="icon"><use href="#i-key"/></svg>{{ __('ui.cs_new_pw') }}</button>
        </form>
      @endif
    </div>

    <p style="margin-top:14px;font-size:12.5px;color:var(--dim);line-height:1.9">
      {{ __('ui.cs_ctrl_note') }}
    </p>
  </div>
</section>

{{-- ═══ کشورِ خروج (فازِ A) — فقط اگر سرور اکسیتِ کشوری داشته باشد ═══ --}}
@if($exitCapable ?? false)
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>کشورِ خروجِ اینترنت</h2></div>
  <div class="pnl-sec-b">
    <p style="margin:0 0 12px;font-size:12.5px;color:var(--dim);line-height:1.9">
      انتخاب کنید ترافیکِ خروجیِ این سرور از کدام کشور خارج شود. دسترسیِ ورودی
      (اتصال به سرور) از همان آدرسِ فعلی باقی می‌ماند؛ فقط مسیرِ خروجی عوض می‌شود
      و چند دقیقه بعد اعمال می‌گردد.
    </p>
    <form method="post" action="{{ route('account.cloud.exit-country', $service) }}"
          style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      @csrf
      <select name="country" dir="ltr"
              style="min-width:210px;padding:9px 12px;border-radius:10px;background:rgba(148,163,184,.10);color:var(--text);border:1px solid rgba(148,163,184,.28);font-size:13px">
        @foreach($exitOptions as $opt)
          <option value="{{ $opt['code'] }}" @selected(($exitCurrent ?? '') === $opt['code'])>{{ $opt['flag'] }} {{ $opt['name'] }}</option>
        @endforeach
      </select>
      <button type="submit" class="pnl-btn">تغییرِ کشور</button>
    </form>
  </div>
</section>
@endif

{{-- ═══ مشخصات ═══ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.cs_specs_h') }}</h2></div>
  <div class="pnl-sec-b">
    <div class="cs-grid">
      <div class="cs-kv"><small>{{ __('ui.cs_cpu') }}</small><b>{{ fa_num($specs['vcpu'] ?? '—') }} {{ __('ui.cs_cores') }}</b></div>
      <div class="cs-kv"><small>{{ __('ui.cs_ram') }}</small><b dir="ltr">{{ isset($specs['ram_mb']) ? fa_num(round($specs['ram_mb'] / 1024, 1)).' GB' : '—' }}</b></div>
      <div class="cs-kv"><small>{{ __('ui.cs_disk') }}</small><b dir="ltr">{{ fa_num($specs['disk_gb'] ?? '—') }} GB {{ strtoupper($specs['disk_type'] ?? '') }}</b></div>
      <div class="cs-kv"><small>{{ __('ui.cs_traffic') }}</small><b dir="ltr" id="tr-used">
        {{ ($specs['traffic_gb'] ?? 0) > 0 ? fa_num(round($specs['traffic_gb'] / 1024, 1)).' TB' : __('ui.cs_traffic_fair') }}
      </b></div>
    </div>
    <p style="margin:14px 0 0;font-size:12.5px;color:var(--dim);line-height:1.9">
      {{ __('ui.cs_next_due') }} <b>{{ $service->next_due_at ? sdate($service->next_due_at) : '—' }}</b>
      · <a href="{{ route('account.invoices') }}" style="color:var(--info)">{{ __('ui.cs_invoices') }}</a>
    </p>
  </div>
</section>

{{-- ═══ نمودارِ مصرف ═══ فقط اگر واقعاً در دسترس باشد --}}
@if($caps['metrics'] ?? false)
<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>{{ __('ui.cs_cpu_usage_h') }}</h2>
    <span style="font-size:12px;color:var(--dim)">{{ __('ui.cs_last_24h') }}</span>
  </div>
  <div class="pnl-sec-b">
    <div id="cpu-wrap" style="min-height:96px">
      <p style="color:var(--dim);font-size:12.5px;margin:0">{{ __('ui.cs_chart_loading') }}</p>
    </div>
  </div>
</section>
@endif

{{-- ═══ نصبِ دوباره ═══
     عملِ برگشت‌ناپذیر، عمداً **آخرِ صفحه** و جدا از دکمه‌های عادی، بسته در
     details و با تأییدِ تایپی. یک کلیکِ اشتباه نباید دادهٔ مشتری را ببرد. --}}
@if($caps['rebuild'] ?? false)
<section class="pnl-sec cs-danger">
  <div class="pnl-sec-h"><h2>{{ __('ui.cs_rebuild_h') }}</h2></div>
  <div class="pnl-sec-b">
    <p style="font-size:13px;color:var(--warn);line-height:1.9;margin:0 0 12px">
      {!! __('ui.cs_rebuild_warn') !!}
    </p>

    <details class="cs-rb">
      <summary>{{ __('ui.cs_rebuild_summary') }}</summary>

      <form method="post" action="{{ route('account.cloud.rebuild', $service) }}" class="cs-rb-f">
        @csrf

        <label>{{ __('ui.cs_os') }}
          <select name="image" required>
            <optgroup label="{{ __('ui.cs_os') }}">
              @foreach($osList as $os)
                <option value="{{ $os->key }}" @selected($os->key === $inst->image_key)>
                  {{ $os->icon() }} {{ $os->label }}
                </option>
              @endforeach
            </optgroup>
            @if($appList->isNotEmpty())
              <optgroup label="{{ __('ui.cs_os_app_group') }}">
                @foreach($appList as $app)
                  <option value="{{ $app->key }}" @selected($app->key === $inst->image_key)>
                    {{ $app->icon() }} {{ $app->label }}
                  </option>
                @endforeach
              </optgroup>
            @endif
          </select>
        </label>

        <label>{{ __('ui.cs_confirm_type_pre') }} <b dir="ltr">DELETE</b> {{ __('ui.cs_confirm_type_post') }}
          <input type="text" name="confirm" required dir="ltr" autocomplete="off" placeholder="DELETE">
        </label>

        <button class="pnl-btn danger" type="submit">
          <svg class="icon"><use href="#i-restore"/></svg>{{ __('ui.cs_rebuild_submit') }}
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
.cs-pw-val{ font-family:ui-monospace,monospace; letter-spacing:.5px; user-select:all }
.cs-pw-val.is-masked{ letter-spacing:2px; user-select:none }
.cs-pw-btn{ display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px;
  border:1px solid var(--line); border-radius:8px; background:var(--bg2); color:var(--muted); cursor:pointer }
.cs-pw-btn:hover{ color:var(--text) }
.cs-pw-btn.is-done{ color:#34d399; border-color:rgba(52,211,153,.5) }
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

  var T = window.T || {};

  // ── کپی با یک کلیک ──
  document.querySelectorAll('.cs-copy').forEach(function(el){
    el.addEventListener('click', function(){
      var v = el.getAttribute('data-copy') || el.textContent.trim();
      if (!navigator.clipboard) { return; }
      navigator.clipboard.writeText(v).then(function(){
        /* ⚠️ دکمهٔ آیکون‌دار متن ندارد؛ عوض‌کردنِ textContent آیکنش را پاک
         * می‌کرد و دکمه برای همیشه خالی می‌مانْد. پس بازخورد روی **کلاس**
         * است، نه روی محتوا. */
        if (el.querySelector('svg')) {
          el.classList.add('is-done');
          setTimeout(function(){ el.classList.remove('is-done'); }, 1100);

          return;
        }

        var old = el.textContent;
        el.textContent = T.copied;
        setTimeout(function(){ el.textContent = old; }, 1100);
      });
    });
  });

  /* ── چشم: رمز پیش‌فرض پنهان است ──
   *
   * مقدار در `data-pw` می‌مانَد و متنِ دیده‌شده نقاب است؛ پس کپی حتی وقتی رمز
   * پنهان است هم کار می‌کند و کاربر می‌تواند بدونِ نمایشِ رمز روی صفحه، آن را
   * بردارد — همان چیزی که وسطِ اشتراکِ صفحه اهمیت دارد. */
  document.querySelectorAll('[data-pw-eye]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var val = btn.parentNode.querySelector('.cs-pw-val');
      if (!val) { return; }

      var masked = val.classList.toggle('is-masked');
      val.textContent = masked ? '••••••••••••' : (val.getAttribute('data-pw') || '');
      btn.setAttribute('aria-label', masked ? T.pw_show : T.pw_hide);
      btn.title = masked ? T.pw_show : T.pw_hide;
    });
  });

  var pill = document.getElementById('st-pill');
  if (!pill) { return; }

  var statusUrl = {{ Illuminate\Support\Js::from(route('account.cloud.status', $service)) }};
  var building  = {{ $ready ? 'false' : 'true' }};
  var steps     = document.getElementById('cb-steps');

  /* ── نوارِ مرحله‌ها ──
     🔴 هیچ عددی این‌جا ساخته نمی‌شود. تنها کاری که می‌کند این است که کلاسِ
     مرحله‌ها را با شمارهٔ مرحله‌ای که **سرور** گفته هم‌تراز کند. اگر روزی
     مرحله‌ای اضافه شد، فقط سمتِ سرور عوض می‌شود. */
  function paintStage(idx){
    if (!steps || typeof idx !== 'number') { return; }
    steps.dataset.stage = String(idx);

    steps.querySelectorAll('.cb-step').forEach(function(li){
      var i = parseInt(li.dataset.i, 10);
      li.classList.remove('is-done', 'is-now', 'is-todo');
      li.classList.add(i < idx ? 'is-done' : (i === idx ? 'is-now' : 'is-todo'));
    });
  }

  // ── وضعیتِ زنده ──
  // در حالتِ «در حالِ ساخت» تندتر می‌پرسیم، چون مشتری منتظرِ همان است؛ بعد از
  // آماده شدن، آرام‌تر تا سهمیهٔ API زیرساخت بی‌دلیل خرج نشود.
  // ⚠️ ۵ ثانیه بی‌خطر است: پاسخِ وضعیت سمتِ سرور ۲۰ ثانیه کش می‌شود، پس تندتر
  // پرسیدن سهمیهٔ زیرساخت را خرج نمی‌کند.
  var tick = building ? 5000 : 30000;
  var tries = 0;

  function poll(){
    if (++tries > 120) { return; }              // سقفِ ایمنی: تبِ رهاشده تا ابد نپرسد

    fetch(statusUrl, { headers: { 'Accept': 'application/json' } })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d) { return; }

        if (d.label) { pill.textContent = d.label; }
        if (d.color) { pill.style.color = d.color; }

        var seen = document.getElementById('st-seen');
        if (seen) { seen.textContent = T.last_check_now; }

        paintStage(d.stage_index);

        /* تازه آماده شد → یک بار بازخوانی تا رمز (که فقط یک بار نشان داده
           می‌شود) و مشخصاتِ کامل بیاید.
           ⚠️ شرط `d.ready` است، نه «دیگر building نیست». پیش از این هر وضعیتِ
           غیرِ building (از جمله `unknown`ِ حاصلِ `activating`) صفحه را بازخوانی
           می‌کرد و مشتری چیدمانِ تحویل‌شده را با IPِ خالی می‌دید. */
        if (building && d.ready === true) {
          window.location.reload();
          return;
        }

        var tr = document.getElementById('tr-used');
        if (tr && d.traffic !== null && typeof d.traffic !== 'undefined') {
          tr.setAttribute('title', T.traffic_month + ' ' + d.traffic + ' GB');
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
        wrap.innerHTML = '<p style="color:var(--dim);font-size:12.5px;margin:0">' + T.chart_none + '</p>';
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
                'style="width:100%;height:96px;display:block" role="img" aria-label="' + T.chart_aria + '">' +
                '<path d="' + path + '" fill="none" stroke="#22d3ee" stroke-width="2" ' +
                'stroke-linejoin="round" stroke-linecap="round"/></svg>' +
                '<p style="margin:8px 0 0;font-size:12.5px;color:var(--muted)">' + T.last_value + ' ' + last + T.pct + '</p>';

      wrap.innerHTML = svg;
    })
    .catch(function(){
      wrap.innerHTML = '<p style="color:var(--dim);font-size:12.5px;margin:0">' + T.chart_unavailable + '</p>';
    });
})();
</script>
@endsection
