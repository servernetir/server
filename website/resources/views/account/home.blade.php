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
    <b class="pnl-num">{{ fa_num(number_format($credit)) }}</b>
    <small>{{ __('ui.pnl_toman') }}</small>
  </div>
  <div class="pnl-stat">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-server"/></svg>{{ __('ui.pnl_services') }}</div>
    <b class="pnl-num">{{ fa_num(0) }}</b>
    <small>—</small>
  </div>
  <div class="pnl-stat {{ $openInvoices->count() ? 'is-warn' : '' }}">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-box"/></svg>{{ __('ui.pnl_invoices_open') }}</div>
    <b class="pnl-num">{{ fa_num($openInvoices->count()) }}</b>
    <small>@if($openInvoices->count()){{ fa_num(number_format($openInvoices->sum(fn ($i) => $i->due()))) }} {{ __('ui.pnl_toman') }}@else—@endif</small>
  </div>
  <div class="pnl-stat">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-lifebuoy"/></svg>{{ __('ui.pnl_tickets_open') }}</div>
    <b class="pnl-num">{{ fa_num(0) }}</b>
    <small>—</small>
  </div>
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
                <td>{{ fa_num($p->created_at->format('Y/m/d H:i')) }}</td>
                <td>{{ $p->description() }}</td>
                <td>
                  @if($p->status === 'paid')<span class="pnl-pill ok">{{ __('ui.pnl_paid') }}</span>
                  @elseif($p->status === 'canceled')<span class="pnl-pill mute">—</span>
                  @elseif($p->status === 'failed')<span class="pnl-pill danger">✕</span>
                  @else<span class="pnl-pill warn">…</span>@endif
                </td>
                <td class="num pnl-num">{{ fa_num(number_format($p->amount)) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif
</section>

<style>
/* عنوان داشبورد عمداً کوچک‌تر از قبل — اسم کاربر در سایدبار هست و
   تکرارش با فونت بزرگ فقط فضا می‌خورد */
.dash-h{ font-size:clamp(17px,2.4vw,21px) !important; }

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
