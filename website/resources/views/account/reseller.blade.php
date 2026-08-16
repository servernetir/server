@extends('panel.layout')
@section('title', 'نمایندگی دامنه — ServerNet')

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">نمایندگی دامنه</h1>
    <p>ثبت و تمدید دامنه از WHMCS خودتان، با قیمت سطح نمایندگی‌تان.</p>
  </div>
</div>

@if(! $isReseller)

  {{--
    حالتِ «هنوز نماینده نیستی».
    ⚠️ صفحه ۴۰۴ نمی‌شود: لینکِ این صفحه در صفحات بازاریابی می‌آید و مشتریِ
    علاقه‌مند باید ببیند چه چیزی می‌گیرد، نه یک درِ بسته.
  --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>هنوز فعال نشده</h2></div>
    <div class="pnl-sec-b">
      <p class="sec-note">
        برنامهٔ نمایندگی دامنه برای حساب شما فعال نیست. با پشتیبانی تماس بگیرید تا
        بررسی و فعال شود. پس از فعال‌سازی، اینجا سطح، قیمت‌ها و افزونهٔ WHMCS را می‌بینید.
      </p>

      <div class="rs-levels" style="margin-top:16px">
        @foreach($levels as $l)
          <div class="rs-level">
            <b>{{ lc($l['name'] ?? []) ?: $l['key'] }}</b>
            <span class="rs-off">{{ fa_num((string) ($l['discount_pct'] ?? 0)) }}٪ تخفیف</span>
            <small>از {{ fa_num(number_format((int) ($l['min_spend_irt'] ?? 0))) }} تومان خرید
              و {{ fa_num((string) ($l['min_active_domains'] ?? 0)) }} دامنهٔ فعال</small>
          </div>
        @endforeach
      </div>

      <p class="sec-note" style="margin-top:14px">
        <a href="{{ lroute('account.ticket.new') }}" class="pnl-btn primary">درخواست فعال‌سازی</a>
      </p>
    </div>
  </section>

@else

  {{-- ══ سطح و پیشرفت ══ --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h">
      <h2>سطح شما: {{ lc($progress['level']['name'] ?? []) ?: $progress['level']['key'] }}</h2>
    </div>
    <div class="pnl-sec-b">

      <div class="rs-stats">
        <div class="rs-stat">
          <span>تخفیف فعلی</span>
          <b>{{ fa_num((string) $progress['discount_pct']) }}٪</b>
          @if($progress['bonus_pct'] > 0)
            <small>شامل {{ fa_num((string) $progress['bonus_pct']) }}٪ تخفیف توافقی</small>
          @endif
        </div>
        <div class="rs-stat">
          <span>خرید ۱۲ ماه گذشته</span>
          <b>{{ invoice_money($progress['spend'], 'IRT') }}</b>
        </div>
        <div class="rs-stat">
          <span>دامنهٔ فعال</span>
          <b>{{ fa_num((string) $progress['active_domains']) }}</b>
        </div>
        <div class="rs-stat">
          <span>اعتبار حساب</span>
          <b>{{ invoice_money($credit, 'IRT') }}</b>
          @if($credit <= 0)<small class="rs-warn">بدون اعتبار، ثبت انجام نمی‌شود</small>@endif
        </div>
      </div>

      @if($progress['next'])
        @php $next = $progress['next']; @endphp
        <div class="rs-progress">
          <div class="rs-bar"><i style="width:{{ $progress['percent'] }}%"></i></div>
          <small>
            تا سطح «{{ lc($next['name'] ?? []) ?: $next['key'] }}»
            ({{ fa_num((string) ($next['discount_pct'] ?? 0)) }}٪ تخفیف):
            {{-- ⚠️ هر دو شرط نشان داده می‌شوند. نمایشِ فقط یکی یعنی نماینده‌ای
                 که مبلغش رسیده ولی دامنهٔ فعالش کم است، نوار پر می‌بیند و
                 ارتقا نمی‌گیرد — و ما را بدقول می‌داند. --}}
            {{ invoice_money(max(0, (int) $next['min_spend_irt'] - $progress['spend']), 'IRT') }} خرید
            و {{ fa_num((string) max(0, (int) $next['min_active_domains'] - $progress['active_domains'])) }} دامنهٔ فعال دیگر
          </small>
        </div>
      @else
        <p class="sec-note" style="margin-top:12px">شما در بالاترین سطح هستید.</p>
      @endif

      @if($progress['grace_until'])
        <p class="rs-grace">
          حجم خرید شما زیر آستانهٔ این سطح آمده است. تا
          {{ stime($progress['grace_until']) }} فرصت دارید؛ تا آن زمان سطح و تخفیفتان
          دست‌نخورده می‌مانَد.
        </p>
      @endif

      @if($dailyCap > 0)
        <p class="sec-note" style="margin-top:10px">
          سقف خرج روزانهٔ API: {{ invoice_money($dailyCap, 'IRT') }} ·
          امروز: {{ invoice_money($spentToday, 'IRT') }}
        </p>
      @endif
    </div>
  </section>

  {{-- ══ نردبان ══ --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>نردبان سطح‌ها</h2></div>
    <div class="pnl-sec-b">
      <div class="rs-levels">
        @foreach($levels as $l)
          <div class="rs-level @if(($progress['level']['key'] ?? '') === $l['key']) is-current @endif">
            <b>{{ lc($l['name'] ?? []) ?: $l['key'] }}</b>
            <span class="rs-off">{{ fa_num((string) ($l['discount_pct'] ?? 0)) }}٪</span>
            <small>{{ fa_num(number_format((int) ($l['min_spend_irt'] ?? 0))) }} تومان
              · {{ fa_num((string) ($l['min_active_domains'] ?? 0)) }} دامنه</small>
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
        <b>یک نکتهٔ صادقانه:</b> سود ما روی هر پسوند متفاوت است. روی پسوندهای
        کم‌حاشیه (معمولاً پرفروش‌ترین‌ها) تخفیف سطح شما تا جایی اعمال می‌شود که
        قیمت زیر بهای تمام‌شدهٔ ما نرود. هر جا این اتفاق بیفتد، در پاسخ API با
        <code dir="ltr">price_floored</code> علامت می‌خورد — پنهانش نمی‌کنیم.
      </p>
    </div>
  </section>

  {{-- ══ افزونهٔ WHMCS ══ --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>افزونهٔ WHMCS</h2></div>
    <div class="pnl-sec-b">
      <p class="sec-note">
        افزونه را دانلود کنید، در <code dir="ltr">modules/registrars/servernet/</code> بگذارید،
        و در WHMCS از مسیر Domain Registrars فعالش کنید.
      </p>

      <ol class="rs-steps">
        <li>در <a href="{{ lroute('account.security') }}#sec-api">صفحهٔ امنیت</a> توکنی با دسترسی
          <code dir="ltr">domains:write</code> و <code dir="ltr">domains:manage</code> بسازید.</li>
        <li>حتماً <b>IP سرور WHMCS خود</b> را در فهرست مجاز توکن بگذارید.</li>
        <li>حساب را شارژ کنید — ثبت و تمدید از اعتبار کسر می‌شود.</li>
        <li>در WHMCS دکمهٔ Test Connection را بزنید.</li>
      </ol>

      <p style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
        <a href="{{ lroute('account.reseller.module', ['kind' => 'whmcs']) }}" class="pnl-btn primary">
          افزونهٔ WHMCS (نسخهٔ {{ fa_num((string) config('domain_reseller.whmcs.version')) }})
        </a>
        <a href="{{ lroute('account.reseller.module', ['kind' => 'wordpress']) }}" class="pnl-btn">
          افزونهٔ وردپرس / ووکامرس (نسخهٔ {{ fa_num((string) config('domain_reseller.wordpress.version')) }})
        </a>
        <a href="{{ url('/developers') }}" class="pnl-btn">مستندات API</a>
      </p>

      <p class="sec-note" style="margin-top:12px">
        <b>افزونهٔ وردپرس برای چه کسی است:</b> اگر ووکامرس دارید، بازدیدکننده
        دامنه را روی سایت شما جستجو می‌کند، به سبد می‌افزاید و از <b>درگاه
        خودتان</b> پرداخت می‌کند؛ ثبت پس از پرداخت خودکار انجام می‌شود.
        بدون ووکامرس هم جعبهٔ جستجو و نمایش قیمت کار می‌کند.
      </p>

      @if($tokens->isEmpty())
        <p class="rs-warn" style="margin-top:12px">
          هنوز هیچ توکن فعالی ندارید. بدون توکن، افزونه کار نمی‌کند.
        </p>
      @endif
    </div>
  </section>

  {{-- ══ آخرین تماس‌های API ══ --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>آخرین تماس‌های API</h2></div>
    <div class="pnl-sec-b">
      @if($logs->isEmpty())
        <p class="sec-note">هنوز تماسی ثبت نشده است.</p>
      @else
        <div class="rs-logs">
          @foreach($logs as $log)
            <div class="rs-log @if(! $log->ok) is-err @endif">
              <span class="rs-log-a" dir="ltr">{{ $log->action }}</span>
              <span class="rs-log-d" dir="ltr">{{ $log->domain ?: '—' }}</span>
              <span class="rs-log-s">
                @if($log->ok)
                  موفق
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
