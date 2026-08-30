@extends('panel.layout')
@section('title', __('ui.rsl_title'))

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">{{ __('ui.rsl_h') }}</h1>
    <p>{{ __('ui.rsl_sub') }}</p>
  </div>
</div>

@if(! $isReseller)

  {{--
    حالتِ «هنوز نماینده نیستی».
    ⚠️ صفحه ۴۰۴ نمی‌شود: لینکِ این صفحه در صفحات بازاریابی می‌آید و مشتریِ
    علاقه‌مند باید ببیند چه چیزی می‌گیرد، نه یک درِ بسته.
  --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>{{ __('ui.rsl_inactive_h') }}</h2></div>
    <div class="pnl-sec-b">
      <p class="sec-note">
        {{ __('ui.rsl_inactive_note') }}
      </p>

      <div class="rs-levels" style="margin-top:16px">
        @foreach($levels as $l)
          <div class="rs-level">
            <b>{{ lc($l['name'] ?? []) ?: $l['key'] }}</b>
            <span class="rs-off">{{ fa_num((string) ($l['discount_pct'] ?? 0)) }}{{ __('ui.rsl_discount_sfx') }}</span>
            <small>{{ __('ui.rsl_tier_req', ['spend' => invoice_money((int) ($l['min_spend_irt'] ?? 0), 'IRT'), 'n' => fa_num((string) ($l['min_active_domains'] ?? 0))] ) }}</small>
          </div>
        @endforeach
      </div>

      <p class="sec-note" style="margin-top:14px">
        <a href="{{ lroute('account.ticket.new') }}" class="pnl-btn primary">{{ __('ui.rsl_activate_btn') }}</a>
      </p>
    </div>
  </section>

@else

  {{-- ══ سطح و پیشرفت ══ --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h">
      <h2>{{ __('ui.rsl_your_level') }} {{ lc($progress['level']['name'] ?? []) ?: $progress['level']['key'] }}</h2>
    </div>
    <div class="pnl-sec-b">

      <div class="rs-stats">
        <div class="rs-stat">
          <span>{{ __('ui.rsl_cur_discount') }}</span>
          <b>{{ fa_num((string) $progress['discount_pct']) }}٪</b>
          @if($progress['bonus_pct'] > 0)
            <small>{{ __('ui.rsl_bonus_incl', ['p' => fa_num((string) $progress['bonus_pct'])]) }}</small>
          @endif
        </div>
        <div class="rs-stat">
          <span>{{ __('ui.rsl_spend_12m') }}</span>
          <b>{{ invoice_money($progress['spend'], 'IRT') }}</b>
        </div>
        <div class="rs-stat">
          <span>{{ __('ui.rsl_active_domains') }}</span>
          <b>{{ fa_num((string) $progress['active_domains']) }}</b>
        </div>
        <div class="rs-stat">
          <span>{{ __('ui.rsl_credit') }}</span>
          <b>{{ invoice_money($credit, 'IRT') }}</b>
          @if($credit <= 0)<small class="rs-warn">{{ __('ui.rsl_no_credit') }}</small>@endif
        </div>
      </div>

      @if($progress['next'])
        @php $next = $progress['next']; @endphp
        <div class="rs-progress">
          <div class="rs-bar"><i style="width:{{ $progress['percent'] }}%"></i></div>
          <small>
            {{ __('ui.rsl_to_level', ['name' => lc($next['name'] ?? []) ?: $next['key'], 'p' => fa_num((string) ($next['discount_pct'] ?? 0))]) }}
            {{-- ⚠️ هر دو شرط نشان داده می‌شوند. نمایشِ فقط یکی یعنی نماینده‌ای
                 که مبلغش رسیده ولی دامنهٔ فعالش کم است، نوار پر می‌بیند و
                 ارتقا نمی‌گیرد — و ما را بدقول می‌داند. --}}
            {{ __('ui.rsl_need_more', ['spend' => invoice_money(max(0, (int) $next['min_spend_irt'] - $progress['spend']), 'IRT'), 'n' => fa_num((string) max(0, (int) $next['min_active_domains'] - $progress['active_domains']))]) }}
          </small>
        </div>
      @else
        <p class="sec-note" style="margin-top:12px">{{ __('ui.rsl_top_level') }}</p>
      @endif

      @if($progress['grace_until'])
        <p class="rs-grace">
          {{ __('ui.rsl_grace', ['until' => stime($progress['grace_until'])]) }}
        </p>
      @endif

      @if($dailyCap > 0)
        <p class="sec-note" style="margin-top:10px">
          {{ __('ui.rsl_daily_cap', ['cap' => invoice_money($dailyCap, 'IRT'), 'today' => invoice_money($spentToday, 'IRT')]) }}
        </p>
      @endif
    </div>
  </section>

  {{-- ══ نردبان ══ --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>{{ __('ui.rsl_ladder_h') }}</h2></div>
    <div class="pnl-sec-b">
      <div class="rs-levels">
        @foreach($levels as $l)
          <div class="rs-level @if(($progress['level']['key'] ?? '') === $l['key']) is-current @endif">
            <b>{{ lc($l['name'] ?? []) ?: $l['key'] }}</b>
            <span class="rs-off">{{ fa_num((string) ($l['discount_pct'] ?? 0)) }}٪</span>
            <small>{{ __('ui.rsl_ladder_req', ['spend' => invoice_money((int) ($l['min_spend_irt'] ?? 0), 'IRT'), 'n' => fa_num((string) ($l['min_active_domains'] ?? 0))]) }}</small>
          </div>
        @endforeach
      </div>

      {{--
        🔴 کفِ قیمت صریح اعلام می‌شود.
        تخفیفی که وعده‌اش ۱۵٪ باشد و در فاکتور ۴٪ بنشیند، اگر بی‌توضیح بماند
        برنامهٔ وفاداری را به سند بی‌اعتمادی تبدیل می‌کند — و اول از همه
        سطح‌بالاترها که باارزش‌ترین‌اند می‌فهمند.
      --}}
      <p class="sec-note" style="margin-top:14px">
        <b>{{ __('ui.rsl_honest_h') }}</b> {{ __('ui.rsl_honest_b') }}
        <code dir="ltr">price_floored</code> {{ __('ui.rsl_honest_tail') }}
      </p>
    </div>
  </section>

  {{-- ══ افزونهٔ WHMCS ══ --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>{{ __('ui.rsl_whmcs_h') }}</h2></div>
    <div class="pnl-sec-b">
      <p class="sec-note">
        {{ __('ui.rsl_whmcs_install') }} <code dir="ltr">modules/registrars/servernet/</code>
        {{ __('ui.rsl_whmcs_install2') }}
      </p>

      <ol class="rs-steps">
        <li>{{ __('ui.rsl_step_token_a') }} <a href="{{ lroute('account.security') }}#sec-api">{{ __('ui.rsl_step_token_page') }}</a> {{ __('ui.rsl_step_token_b') }}
          <code dir="ltr">domains:write</code> · <code dir="ltr">domains:manage</code> {{ __('ui.rsl_step_token_c') }}</li>
        <li>{{ __('ui.rsl_step_ip') }}</li>
        <li>{{ __('ui.rsl_step_credit') }}</li>
        <li>{{ __('ui.rsl_step_test') }}</li>
      </ol>

      <p style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
        <a href="{{ lroute('account.reseller.module', ['kind' => 'whmcs']) }}" class="pnl-btn primary">
          {{ __('ui.rsl_dl_whmcs', ['v' => fa_num((string) config('domain_reseller.whmcs.version'))]) }}
        </a>
        <a href="{{ lroute('account.reseller.module', ['kind' => 'wordpress']) }}" class="pnl-btn">
          {{ __('ui.rsl_dl_wp', ['v' => fa_num((string) config('domain_reseller.wordpress.version'))]) }}
        </a>
        <a href="{{ url('/developers') }}" class="pnl-btn">{{ __('ui.rsl_api_docs') }}</a>
      </p>

      <p class="sec-note" style="margin-top:12px">
        <b>{{ __('ui.rsl_wp_who_h') }}</b> {{ __('ui.rsl_wp_who_b') }}
      </p>

      @if($tokens->isEmpty())
        <p class="rs-warn" style="margin-top:12px">
          {{ __('ui.rsl_no_token') }}
        </p>
      @endif
    </div>
  </section>

  {{-- ══ آخرین تماس‌های API ══ --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>{{ __('ui.rsl_logs_h') }}</h2></div>
    <div class="pnl-sec-b">
      @if($logs->isEmpty())
        <p class="sec-note">{{ __('ui.rsl_no_logs') }}</p>
      @else
        <div class="rs-logs">
          @foreach($logs as $log)
            <div class="rs-log @if(! $log->ok) is-err @endif">
              <span class="rs-log-a" dir="ltr">{{ $log->action }}</span>
              <span class="rs-log-d" dir="ltr">{{ $log->domain ?: '—' }}</span>
              <span class="rs-log-s">
                @if($log->ok)
                  {{ __('ui.rsl_ok') }}
                @else
                  {{-- کد ماشین‌خوان عمداً نشان داده می‌شود: نماینده همان را در
                       جدول عیب‌یابی README پیدا می‌کند. --}}
                  <code dir="ltr">{{ $log->error_code ?: 'error' }}</code>
                @endif
              </span>
              <span class="rs-log-m">@if($log->amount_irt > 0){{ invoice_money($log->amount_irt, 'IRT') }}@endif</span>
              <span class="rs-log-t">{{ stime($log->created_at) }}</span>
            </div>
          @endforeach
        </div>
      @endif
    </div>
  </section>

@endif

@endsection
