{{--
  کارتِ هاست — «مترِ مصرف».

  چیزی که مشتریِ هاست برای دیدنش این صفحه را باز می‌کند یک عدد است: چقدر فضا
  مانده. پس همان قهرمانِ کارت است، و بقیه دورش می‌نشیند.

  ورودی: $s (Service)
--}}
@php
  $type      = $s->server?->type;
  $delivered = $s->provision_status === 'done';
  $suspended = $s->status === 'suspended';
  $panelUrl  = $s->panel_url;

  /*
   | 🔴 دکمهٔ ورود فقط وقتی که واقعاً کار می‌کند.
   |
   | `ServiceController::cpanel()` برای هر چیزی جز `done` + سرور + نام‌کاربری
   | خطا برمی‌گرداند، و برای غیرِ WHM بدونِ `panel_url` هم. ردیفِ قبلی دکمه را
   | با تمامِ کدرشدگی نشان می‌داد و مشتریِ معلق روی آن کلیک می‌کرد و به یک خطای
   | WHM می‌رسید — دکمه‌ای که تضمین‌شده شکست می‌خورد، بدتر از نبودِ دکمه است.
   */
  $canLogin = $delivered && ! $suspended && filled($s->username);
  $isWhm    = $type === 'whm';
@endphp

<article class="svc-card {{ $suspended ? 'is-susp' : '' }}">

  <header class="svc-card-h">
    <span class="pnl-svc-ic"><svg class="icon"><use href="#i-hdd"/></svg></span>
    <span class="svc-card-t">
      <b>{{ $s->name }}</b>
      @if($s->domain)<small dir="ltr">{{ $s->domain }}</small>@endif
    </span>
    @include('account.partials.status-pill', ['s' => $s])
  </header>

  @if($suspended)
    <p class="svc-note warn"><b>{{ __('ui.hst_suspended_h') }}</b> — {{ __('ui.hst_suspended_p') }}</p>
  @endif

  @if(! $delivered)
    {{-- هنوز تحویل نشده: نه متر، نه رمز، نه دکمهٔ ورود --}}
    <p class="svc-note {{ $s->provision_status === 'failed' ? 'warn' : '' }}">
      {{ $s->provision_status === 'failed' ? __('ui.svc_provision_failed') : __('ui.svc_provision_pending') }}
    </p>
  @else

    {{-- ── مترِ مصرف ──
         🔴 دقیقاً همان شرطِ `ServiceController::stats()` (whm + done + نام‌کاربری).
         ویو تا امروز فقط `provision_status==='done'` را می‌سنجید، پس یک ردیفِ
         DirectAdmin کارتی می‌ساخت که هرگز پر نمی‌شد **و** یکی از ۶۰ درخواستِ
         سقف‌دارِ دقیقه را می‌سوزاند. --}}
    @if($s->hasLiveUsage())
      <div class="svc-usage" data-stats="{{ lroute('account.services.stats', $s) }}">
        <div class="svc-usage-load">{{ __('ui.svc_loading_stats') }}</div>
      </div>
    @else
      <p class="svc-note">{{ __('ui.hst_stats_na') }}</p>
    @endif

    {{-- ── حقایقِ سرویس ── --}}
    <div class="svc-facts">
      <div class="svc-fact"><small>{{ __('ui.hst_panel_type') }}</small><b>{{ $s->server?->typeLabel() ?? '—' }}</b></div>
      @if($s->username)
        <div class="svc-fact"><small>{{ __('ui.svc_cred_username') }}</small><b dir="ltr" class="copyable" title="{{ __('ui.svc_copy_title') }}">{{ $s->username }}</b></div>
      @endif
      @if(! $s->isHourly())
        <div class="svc-fact"><small>{{ __('ui.svc_th_due') }}</small><b>{{ sdate($s->next_due_at) }}</b></div>
        <div class="svc-fact"><small>{{ __('ui.svc_th_amount') }}</small><b>{{ invoice_money($s->total(), $s->currency_code) }} <em>{{ $s->cycleLabel() }}</em></b></div>
      @endif
    </div>

    {{-- ── اطلاعاتِ ورود ──
         ⚠️ این بلوک هرگز روی کارتِ سرورِ ابری نمی‌آید: `CloudProvisioner` رمزِ
         rootِ سرور را روی همین ستونِ `services.password` می‌نویسد، پس یک قالبِ
         مشترک آن را در هر بار بارگذاریِ صفحه چاپ می‌کرد و قاعدهٔ «فقط یک بار»
         (`CloudInstance::password_seen`) بی‌صدا دور زده می‌شد. --}}
    <div class="svc-cred">
      <div><span>{{ __('ui.svc_cred_panel_url') }}</span>
        @if($panelUrl)<a href="{{ $panelUrl }}" target="_blank" rel="noopener" dir="ltr">{{ $panelUrl }}</a>
        @elseif($s->server?->hostname)<b dir="ltr">{{ $s->server->hostname }}</b>
        @else<b>—</b>@endif</div>
      @if($s->username)<div><span>{{ __('ui.svc_cred_username') }}</span><b dir="ltr" class="copyable" title="{{ __('ui.svc_copy_title') }}">{{ $s->username }}</b></div>@endif
      @if($s->password)<div><span>{{ __('ui.svc_cred_password') }}</span>
        <b dir="ltr" class="svc-pw"><span class="pw-mask">••••••••••</span><span class="pw-val" hidden>{{ $s->password }}</span>
          <button type="button" class="pw-eye" data-show="{{ __('ui.svc_show') }}" data-hide="{{ __('ui.svc_hide') }}">{{ __('ui.svc_show') }}</button></b></div>@endif
      @if($s->domain)<div><span>{{ __('ui.svc_cred_domain') }}</span><b dir="ltr">{{ $s->domain }}</b></div>@endif
    </div>

    {{-- ═══ اتصالِ دامنه — نیم‌سرور، آی‌پی، و چراغِ وضعیت ═══

         🔴 دلیلِ وجودش یک عدد است: پرتکرارترین تیکتِ این شرکت «نیم‌سرورم را چه
         بزنم؟» بود. تا امروز این اطلاعات **هیچ‌جا** به مشتری نشان داده نمی‌شد —
         نه در پنل، نه در ایمیلِ تحویل — پس تنها راهش تیکت بود.

         ⚠️ چراغ async بارگذاری می‌شود (`data-dns`) دقیقاً مثلِ مترِ مصرف: یک
         پرس‌وجوی DNS داخلِ رندر یعنی صفحه‌ای که با DNSِ کند ثانیه‌ها معطل
         می‌مانَد، و کارتِ هر سرویس یک بار دیگر. خودِ نیم‌سرورها اما همیشه و
         بی‌شرط چاپ می‌شوند — جوابِ «چه بزنم» نباید به موفقیتِ یک کوئری بند باشد.
    --}}
    @php $ns = $s->server?->nameserverList() ?? []; $srvIp = $s->server?->publicIp(); @endphp
    @if($ns !== [])
      <div class="svc-dns" @if($s->domain) data-dns="{{ lroute('account.services.dns', $s) }}" @endif>
        <div class="svc-dns-h">
          <span class="svc-dns-t">{{ __('ui.dns_connect_h') }}</span>
          <span class="dns-pill is-load">{{ __('ui.dns_checking') }}</span>
        </div>

        <div class="svc-dns-rows">
          @foreach($ns as $i => $n)
            <div><span>{{ __('ui.dns_ns_n', ['n' => fa_num($i + 1)]) }}</span>
              <b dir="ltr" class="copyable" title="{{ __('ui.svc_copy_title') }}">{{ $n }}</b></div>
          @endforeach
          @if($srvIp)
            <div><span>{{ __('ui.dns_server_ip') }}</span>
              <b dir="ltr" class="copyable" title="{{ __('ui.svc_copy_title') }}">{{ $srvIp }}</b></div>
          @endif
        </div>

        <p class="svc-dns-msg">{{ __('ui.dns_hint') }}</p>

        @if($s->domain)
          <a class="svc-dns-check" href="{{ lroute('tools', 'whois') }}?domain={{ urlencode($s->domain) }}"
             target="_blank" rel="noopener">
            <svg class="icon"><use href="#i-search"/></svg><span>{{ __('ui.dns_verify_link') }}</span>
          </a>
        @endif
      </div>
    @endif

    {{-- ── دسترسیِ سریع ──
         پنج لینکِ عمیق فقط برای WHM معنا دارند؛ برای Plesk/DirectAdmin کدِ
         غیرقابلِ اجرا بودند. «وب‌میل» نشستِ جداگانه (`webmaild`) می‌گیرد و
         ویرایشگرِ DNS عمداً لینکِ عمیق است نه رابطِ خودمان — رکوردهای زیردامنهٔ
         رایگان روی Cloudflare می‌نشینند نه در zoneِ WHM. --}}
    @if($canLogin && $isWhm)
      <div class="svc-quick">
        <a class="svc-qbtn primary" href="{{ lroute('account.services.cpanel', $s) }}" target="_blank" rel="noopener"><svg class="icon"><use href="#i-key"/></svg><span>{{ __('ui.svc_cpanel_login') }}</span></a>
        <a class="svc-qbtn" href="{{ lroute('account.services.cpanel', $s) }}?app=files" target="_blank" rel="noopener"><svg class="icon"><use href="#i-file"/></svg><span>{{ __('ui.svc_file_manager') }}</span></a>
        <a class="svc-qbtn" href="{{ lroute('account.services.cpanel', $s) }}?app=db" target="_blank" rel="noopener"><svg class="icon"><use href="#i-db"/></svg><span>{{ __('ui.svc_databases') }}</span></a>
        <a class="svc-qbtn" href="{{ lroute('account.services.cpanel', $s) }}?app=email" target="_blank" rel="noopener"><svg class="icon"><use href="#i-mail"/></svg><span>{{ __('ui.svc_emails') }}</span></a>
        <a class="svc-qbtn" href="{{ lroute('account.services.cpanel', $s) }}?app=webmail" target="_blank" rel="noopener"><svg class="icon"><use href="#i-send"/></svg><span>{{ __('ui.svc_webmail') }}</span></a>
        <a class="svc-qbtn" href="{{ lroute('account.services.cpanel', $s) }}?app=dns" target="_blank" rel="noopener"><svg class="icon"><use href="#i-globe"/></svg><span>{{ __('ui.svc_dns') }}</span></a>
      </div>
    @elseif($canLogin && $panelUrl)
      <div class="svc-quick">
        <a class="svc-qbtn primary" href="{{ $panelUrl }}" target="_blank" rel="noopener"><svg class="icon"><use href="#i-key"/></svg><span>{{ __('ui.hst_login_panel') }}</span></a>
      </div>
    @elseif($canLogin)
      <p class="svc-note warn">{{ __('ui.hst_no_panel_url') }}</p>
    @endif
  @endif

  @include('account.partials.svc-actions', ['s' => $s, 'kind' => 'hosting'])
</article>
