@extends('panel.layout')
@section('title', __('ui.pnl_dash_hi').' — ServerNet')

@section('panel')

{{--
  داشبورد.

  سلام بزرگ حذف شد: اسم کاربر در سایدبار هست و تکرارش با فونت ۳۰ پیکسلی،
  بالای صفحه را می‌خورَد بدون اینکه چیزی بگوید. جای آن، نوار وضعیت — چیزی
  که کاربر واقعاً برای یک نگاه سریع می‌خواهد.

  ترتیب عمدی است: اول کاری که باید انجام دهد، بعد اعداد، بعد تاریخچه.
  اگر کاری معلق نباشد، آن بخش اصلاً رندر نمی‌شود.
--}}

<div class="pnl-head">
  <div>
    <h1 class="dash-h">{{ __('ui.pnl_dash_hi') }}@if($identity?->first_name)، {{ $identity->first_name }}@endif</h1>
    <p>
      <span dir="ltr">{{ $customer->code }}</span>
      @if($identity?->status === 'verified')
        <span class="pnl-pill ok" style="font-size:10.5px">{{ __('ui.pnl_identity_ok') }}</span>
      @else
        <span class="pnl-pill warn" style="font-size:10.5px">{{ __('ui.pnl_identity_no') }}</span>
      @endif
    </p>
  </div>
  <div class="pnl-acts">
    <a class="pnl-btn primary" href="{{ lroute('account.topup') }}">
      <svg class="icon"><use href="#i-coins"/></svg>{{ __('ui.pnl_add_credit') }}
    </a>
  </div>
</div>

@if(session('ok'))
  <div class="pnl-sec" style="border-color:var(--ok-line)">
    <div class="pnl-sec-b" style="color:var(--ok);font-size:13.5px">{{ session('ok') }}</div>
  </div>
@endif

{{-- ══ اعداد کلیدی ══ --}}
<div class="pnl-stats">
  <div class="pnl-stat {{ $credit > 0 ? 'is-ok' : '' }}">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-coins"/></svg>{{ __('ui.pnl_credit') }}</div>
    <b class="pnl-num">{{ invoice_money($credit) }}</b>
  </div>
  <a class="pnl-stat" href="{{ lroute('account.services') }}" style="text-decoration:none;color:inherit">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-server"/></svg>{{ __('ui.pnl_services') }}</div>
    <b class="pnl-num">{{ fa_num($serviceCount) }}</b>
    <small>@if($serviceCount){{ __('ui.ahome_svc_active') }}@else—@endif</small>
  </a>
  <a class="pnl-stat {{ $openInvoices->count() ? 'is-warn' : '' }}" href="{{ lroute('account.invoices') }}" style="text-decoration:none;color:inherit">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-box"/></svg>{{ __('ui.pnl_invoices_open') }}</div>
    <b class="pnl-num">{{ fa_num($openInvoices->count()) }}</b>
    <small>@if($openInvoices->count()){{ invoice_money($openInvoices->sum(fn ($i) => $i->due())) }}@else—@endif</small>
  </a>
  <a class="pnl-stat" href="{{ lroute('account.tickets') }}" style="text-decoration:none;color:inherit">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-lifebuoy"/></svg>{{ __('ui.pnl_tickets_open') }}</div>
    <b class="pnl-num">{{ fa_num($ticketOpen) }}</b>
    <small>@if($ticketOpen){{ __('ui.ahome_ticket_open') }}@else—@endif</small>
  </a>
</div>

{{-- ══ کارهای معلق — فقط وقتی واقعاً کاری هست ══ --}}
@if($todo)
<section class="pnl-sec pnl-alert">
  <div class="pnl-sec-h"><h2>{{ __('ui.pnl_todo') }}</h2></div>
  <ul class="pnl-todo">
    @foreach($todo as $t)
      <li>
        <span class="pnl-todo-ic {{ $t['tone'] }}"><svg class="icon"><use href="#i-{{ $t['icon'] }}"/></svg></span>
        <span class="pnl-todo-t"><b>{{ $t['title'] }}</b><small>{{ $t['note'] }}</small></span>
        <a class="pnl-btn {{ $t['tone'] === 'd' ? 'primary' : '' }}" href="{{ $t['url'] }}">{{ __('ui.pnl_open') }}</a>
      </li>
    @endforeach
  </ul>
</section>
@endif

{{-- ══ دسترسی سریع ══ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.pnl_quick') }}</h2></div>
  <div class="pnl-sec-b">
    <div class="dash-quick">
      <a href="{{ lroute('domain.search') }}">
        <svg class="icon"><use href="#i-globe"/></svg>
        <b>{{ __('ui.pnl_new_domain') }}</b>
      </a>
      <a href="{{ lroute('home') }}">
        <svg class="icon"><use href="#i-server"/></svg>
        <b>{{ __('ui.pnl_new_service') }}</b>
      </a>
      <a href="{{ lroute('account.topup') }}">
        <svg class="icon"><use href="#i-coins"/></svg>
        <b>{{ __('ui.pnl_add_credit') }}</b>
      </a>
      <a href="{{ lroute('account.profile') }}">
        <svg class="icon"><use href="#i-user"/></svg>
        <b>{{ __('ui.auth_s_identity') }}</b>
      </a>
    </div>
  </div>
</section>

{{-- ══ آخرین رویدادها ══ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>{{ __('ui.pnl_activity') }}</h2>
    @if($recent->isNotEmpty())<a class="pnl-more" href="{{ lroute('account.invoices') }}">{{ __('ui.pnl_all') }}</a>@endif
  </div>

  @if($recent->isEmpty())
    <div class="pnl-sec-b">
      <p style="margin:0;font-size:13.5px;color:var(--muted);line-height:2">{{ __('ui.pnl_none_yet') }}</p>
    </div>
  @else
    <div class="pnl-sec-b flush">
      <div class="pnl-tw">
        <table class="pnl-table">
          <tbody>
            @foreach($recent as $p)
              <tr>
                <td>{{ stime($p->created_at) }}</td>
                <td>{{ $p->description() }}</td>
                <td>
                  @if($p->status === 'paid')<span class="pnl-pill ok">{{ __('ui.pnl_paid') }}</span>
                  @elseif($p->status === 'canceled')<span class="pnl-pill mute">—</span>
                  @elseif($p->status === 'failed')<span class="pnl-pill danger">✕</span>
                  @else<span class="pnl-pill warn">…</span>@endif
                </td>
                <td class="num pnl-num">{{ invoice_money($p->amount, $p->currency_code) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif
</section>

{{-- ══ فعالیت و امنیت حساب ══ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.ahome_sec_title') }}</h2></div>

  {{-- نوار جلسه: ساعت زندهٔ شمسی + IP و مکانِ فعلی + دستگاه + آخرین ورود --}}
  @php
    $cgeo = app(\App\Services\GeoIp::class)->locate($currentIp);
    $cdev = ua_parse(request()->userAgent());
  @endphp
  <div class="pnl-sec-b">
    <div class="sess-bar">
      <div class="sess-tile sess-clock">
        <span class="sess-ic"><svg class="icon"><use href="#i-clock"/></svg></span>
        <div class="sess-t">
          <b id="sess-time">—</b>
          <span id="sess-date">—</span>
        </div>
      </div>
      <div class="sess-tile">
        <span class="sess-ic"><svg class="icon"><use href="#i-globe"/></svg></span>
        <div class="sess-t">
          <span>{{ __('ui.ahome_your_ip') }}</span>
          <b dir="ltr">{{ $currentIp }}</b>
          @if($cgeo)<span>@include('partials.flag', ['flagSrc' => \App\Models\CloudLocation::flagSvgFor($cgeo['cc'] ?? null), 'flagEmoji' => $cgeo['flag'], 'flagSize' => 18]) {{ $cgeo['country'] }}{{ $cgeo['region'] ? '، '.$cgeo['region'] : '' }}</span>@endif
        </div>
      </div>
      <div class="sess-tile">
        <span class="sess-ic"><svg class="icon"><use href="#{{ $cdev['icon'] }}"/></svg></span>
        <div class="sess-t">
          <span>{{ __('ui.ahome_current_device') }}</span>
          <b>{{ $cdev['label'] !== '—' ? $cdev['label'] : __('ui.ahome_unknown') }}</b>
        </div>
      </div>
      <div class="sess-tile">
        <span class="sess-ic ok"><svg class="icon"><use href="#i-shield"/></svg></span>
        <div class="sess-t">
          <span>{{ __('ui.ahome_last_login') }}</span>
          <b>{{ $customer->last_login_at ? stime($customer->last_login_at) : '—' }}</b>
          @if($customer->last_login_ip)<span dir="ltr">{{ __('ui.ahome_from') }} {{ $customer->last_login_ip }}</span>@endif
        </div>
      </div>
    </div>
  </div>

  @if($activity->isNotEmpty())
  <div class="pnl-sec-b flush" style="border-top:1px solid var(--line)">
    <ul class="act-list">
      @foreach($activity as $a)
        <li>
          <span class="act-ic"><svg class="icon"><use href="#{{ $a->icon() }}"/></svg></span>
          <span class="act-body">
            <b>{{ $a->description }}</b>
            <small dir="ltr">{{ stime($a->created_at) }}@if($a->ip) · {{ $a->ip }}@endif</small>
            @php $dev = $a->device(); $geo = $a->geoLabel(); @endphp
            @if($dev['label'] !== '—' || $geo)
              <small class="act-meta">
                @if($dev['label'] !== '—')<svg class="icon"><use href="#{{ $dev['icon'] }}"/></svg><span>{{ $dev['label'] }}</span>@endif
                @if($geo)<span class="act-geo">{{ $geo }}</span>@endif
              </small>
            @endif
          </span>
          @if($a->actor === 'staff')<span class="pnl-pill mute">{{ __('ui.ahome_staff') }}</span>@endif
        </li>
      @endforeach
    </ul>
  </div>
  @endif
</section>

<script>
(function () {
  var elT = document.getElementById('sess-time'), elD = document.getElementById('sess-date');
  if (!elT) return;
  var isFa = @json(app()->getLocale()) === 'fa';
  var loc = isFa ? 'fa-IR-u-ca-persian-nu-arabext' : (@json(app()->getLocale()) === 'tr' ? 'tr-TR' : 'en-GB');
  var tz = isFa ? 'Asia/Tehran' : undefined;
  var tf, df;
  try {
    tf = new Intl.DateTimeFormat(loc, { hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false, timeZone:tz });
    df = new Intl.DateTimeFormat(loc, { weekday:'long', day:'numeric', month:'long', year:'numeric', timeZone:tz });
  } catch(e){ return; }
  function tick(){ var n = new Date(); elT.textContent = tf.format(n); elD.textContent = df.format(n); }
  tick(); setInterval(tick, 1000);
})();
</script>

<style>
/* عنوان داشبورد عمداً کوچک‌تر از قبل — اسم کاربر در سایدبار هست و
   تکرارش با فونت بزرگ فقط فضا می‌خورد */
.dash-h{ font-size:clamp(17px,2.4vw,21px) !important; }

/* نوار جلسه — ساعت زنده + IP/مکان + دستگاه + آخرین ورود، حرفه‌ای و کارتی */
.sess-bar{ display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; }
@media(max-width:520px){ .sess-bar{ grid-template-columns:1fr; } }
.sess-tile{ display:flex; align-items:center; gap:12px; padding:14px 16px;
  border:1px solid var(--line); border-radius:14px; background:var(--surface-2); }
.sess-tile.sess-clock{ background:linear-gradient(135deg, rgba(34,211,238,.10), var(--surface-2)); border-color:var(--info-line); }
.sess-ic{ width:38px; height:38px; border-radius:11px; display:grid; place-items:center; flex:0 0 auto;
  background:var(--surface); border:1px solid var(--line); }
.sess-ic .icon{ width:19px; height:19px; color:var(--info); }
.sess-ic.ok .icon{ color:var(--ok); }
.sess-t{ display:flex; flex-direction:column; gap:1px; min-width:0; line-height:1.5; }
.sess-t > span{ font-size:11px; color:var(--muted); }
.sess-t > b{ font-size:15px; font-weight:700; color:var(--text); font-variant-numeric:tabular-nums;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sess-clock #sess-time{ font-size:20px; letter-spacing:1px; }
.sess-clock #sess-date{ font-size:12px; color:var(--muted); }

.act-list{ list-style:none; margin:0; padding:0; }
.act-list li{ display:flex; align-items:center; gap:12px; padding:12px 16px; border-bottom:1px solid var(--line); }
.act-list li:last-child{ border-bottom:0; }
.act-ic{ width:32px; height:32px; border-radius:9px; display:grid; place-items:center;
  background:var(--surface-2); border:1px solid var(--line); flex:0 0 auto; }
.act-ic .icon{ width:16px; height:16px; color:var(--muted); }
.act-body{ display:flex; flex-direction:column; gap:2px; flex:1; min-width:0; }
.act-body b{ font-size:13px; font-weight:600; color:var(--text); }
.act-body small{ font-size:11px; color:var(--dim); font-variant-numeric:tabular-nums; }
.act-meta{ display:flex; align-items:center; gap:6px; flex-wrap:wrap; color:var(--muted) !important; }
.act-meta .icon{ width:13px; height:13px; color:var(--muted); flex:0 0 auto; }
.act-meta .act-geo{ padding-inline-start:6px; border-inline-start:1px solid var(--line); }

.dash-quick{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; }
.dash-quick a{
  display:flex; flex-direction:column; gap:10px; padding:16px 15px;
  border:1px solid var(--line); border-radius:14px; background:var(--surface-2);
  color:var(--text); text-decoration:none;
  transition:border-color .18s var(--ease), transform .18s var(--ease), background .18s var(--ease);
}
.dash-quick a:hover{ border-color:var(--line-2); transform:translateY(-2px); background:var(--surface); }
.dash-quick .icon{ width:19px; height:19px; color:var(--muted); }
.dash-quick a:hover .icon{ color:var(--text); }
.dash-quick b{ font-size:13px; font-weight:600; }
@media(prefers-reduced-motion:reduce){ .dash-quick a{ transition:none } .dash-quick a:hover{ transform:none } }
</style>

@endsection
