@extends('admin.layout')
@section('title', 'گزارشِ کسب‌وکار')
@section('nav_reports', 'on')
@section('content')

@php
  $t   = fn ($n) => fa_num(number_format((int) $n)).' ت';
  $in  = $forecast['incoming'];
  $out = $forecast['outgoing'];

  // جمعِ پولِ در راه — تمدیدِ سرویس + تمدیدِ دامنه. معوق جداست چون
  // «در راه» نیست، «عقب‌افتاده» است و رفتارِ متفاوتی می‌خواهد.
  $expectedIn = (int) $in['renewals']['amount'] + (int) $in['domains']['amount'];
  $rent = $out['servers'];
  /*
  | 🔴 «جا مانده» = بی‌قیمت **به‌علاوهٔ** تبدیل‌نشده.
  |
  | پیش از این فقط `unpriced` سنجیده می‌شد، پس وقتی نرخِ ارز در دسترس نبود
  | صفحه هم‌زمان «۰ تومان» نشان می‌داد و ادعا می‌کرد جمع کامل است — در حالی
  | که کلِ اجارهٔ ارزی از قلم افتاده بود.
  */
  $rentMissing = (int) ($rent['unpriced'] ?? 0) + (int) ($rent['unconvertible'] ?? 0);
  $expectedOut = (int) $out['domains']['toman'] + (int) ($rent['toman'] ?? 0);

  $maxTrend = max(1, collect($trend)->flatMap(fn ($m) => [$m['revenue'], $m['expense']])->max() ?: 1);
  $maxCust  = max(1, collect($customers['trend'])->max('count') ?: 1);
@endphp

{{-- ══ بازهٔ پیش‌بینی ══ --}}
<div class="ad-toolbar">
  <div class="ad-tabs">
    @foreach($windows as $w)
      <a href="/admin/reports?days={{ $w }}" class="{{ $days === $w ? 'on' : '' }}">{{ fa_num($w) }} روزِ آینده</a>
    @endforeach
  </div>
  {{-- ⚠️ `.ad-tabs` و نه یک کلاسِ تازه: کلاسِ CSSِ نبود، بی‌هیچ خطایی
       بی‌استایل رندر می‌شود و فقط با نگاه‌کردن به صفحه پیدا می‌شود. --}}
  <div class="ad-tabs"><a href="/admin/finance">دفترِ مالی ←</a></div>
</div>

{{-- ══ چهار عددِ اصلی ══ --}}
<div class="fin-kpis">
  <div class="fin-kpi">
    <span class="fin-kpi-l">درآمدِ در راه</span>
    <b class="fin-kpi-v" style="color:#34d399">{{ $t($expectedIn) }}</b>
    <small>فاکتورهایی که تا {{ fa_num($days) }} روزِ آینده صادر می‌شوند</small>
  </div>
  <div class="fin-kpi">
    <span class="fin-kpi-l">هزینهٔ در راه</span>
    <b class="fin-kpi-v" style="color:#fbbf24">{{ $t($expectedOut) }}</b>
    <small>
      اجارهٔ سرور + تمدیدِ دامنه
      @if($rentMissing > 0)
        · <span style="color:#ff6b6b">{{ fa_num($rentMissing) }} سرور در این جمع نیست</span>
      @endif
    </small>
  </div>
  <div class="fin-kpi">
    <span class="fin-kpi-l">طلبِ وصول‌نشده</span>
    <b class="fin-kpi-v" style="color:{{ $in['overdue']['amount'] > 0 ? '#ff6b6b' : '#34d399' }}">
      {{ $t($in['overdue']['amount']) }}
    </b>
    <small>{{ fa_num($in['overdue']['count']) }} فاکتورِ باز · {{ fa_num($in['overdue']['stale_count']) }} بیش از {{ fa_num($in['overdue']['stale_days']) }} روز راکد</small>
  </div>
  <div class="fin-kpi">
    <span class="fin-kpi-l">مشتری</span>
    <b class="fin-kpi-v" style="color:#22d3ee">{{ fa_num($customers['total']) }}</b>
    <small>{{ fa_num($customers['paying']) }} پولی · {{ fa_num($customers['new_30']) }} تازه در ۳۰ روز</small>
  </div>
</div>

<div class="fin-cols">
  <div>

    {{-- ══ پولِ در راه ══ --}}
    <div class="ad-panel">
      <div class="ad-panel-h"><h2>{{ fa_num($days) }} روزِ آینده</h2></div>
      <table class="fin-pl">
        <tr>
          <td>تمدیدِ سرویس‌ها</td>
          <td class="fin-num" style="color:#34d399">+ {{ $t($in['renewals']['amount']) }}</td>
          <td class="fin-src">{{ fa_num($in['renewals']['count']) }} سرویس</td>
        </tr>
        <tr>
          <td>تمدیدِ دامنه‌ها (صورت‌حسابِ مشتری)</td>
          <td class="fin-num" style="color:#34d399">+ {{ $t($in['domains']['amount']) }}</td>
          <td class="fin-src">{{ fa_num($in['domains']['count']) }} دامنه</td>
        </tr>
        <tr>
          <td>
            اجارهٔ سرورها
            @if($rentMissing > 0)
              <br><small style="color:#ff6b6b">
                {{ fa_num($rentMissing) }} سرور در این جمع نیست
                @if(($rent['unconvertible'] ?? 0) > 0)(نرخِ ارز در دسترس نبود)@endif
              </small>
            @endif
          </td>
          <td class="fin-num" style="color:#ff6b6b">− {{ $t($rent['toman'] ?? 0) }}</td>
          <td class="fin-src">
            @if($rent['ready'] ?? false)
              ماهی {{ $t($rent['monthly']) }}
            @else
              ستون‌ها ساخته نشده
            @endif
          </td>
        </tr>
        <tr>
          <td>تمدیدِ دامنه‌ها (پرداخت به رجیسترار)<br><small style="color:var(--dim)">بر پایهٔ قیمتِ ثبتِ اولیه — تمدید معمولاً گران‌تر است</small></td>
          <td class="fin-num" style="color:#ff6b6b">− {{ $t($out['domains']['toman']) }}</td>
          <td class="fin-src">
            @if($out['domains']['rate'] > 0)
              {{ fa_num(number_format($out['domains']['eur'] / 100, 2)) }} یورو × نرخِ امروز
            @else
              نرخِ یورو در دسترس نبود
            @endif
          </td>
        </tr>
        <tr class="fin-total">
          <td>خالصِ در راه</td>
          <td class="fin-num" style="color:{{ ($expectedIn - $expectedOut) >= 0 ? '#34d399' : '#ff6b6b' }}">
            {{ $t($expectedIn - $expectedOut) }}
          </td>
          <td class="fin-src">
            @if($rentMissing > 0)
              ناقص — {{ fa_num($rentMissing) }} سرور جا مانده
            @else
              اجاره + دامنه
            @endif
          </td>
        </tr>
      </table>

      @if($in['renewals']['rows']->isNotEmpty())
        <div class="fin-cat">
          <div class="fin-cat-h">نزدیک‌ترین تمدیدها</div>
          <table class="ad-table">
            <thead><tr><th>سررسید</th><th>سرویس</th><th>مشتری</th><th class="fin-num">مبلغ</th></tr></thead>
            <tbody>
              @foreach($in['renewals']['rows'] as $s)
                <tr>
                  <td dir="ltr">{{ sdate($s->next_due_at) }}</td>
                  <td>{{ $s->name }}</td>
                  <td>
                    @if($s->customer)
                      <a href="/admin/customers/{{ $s->customer_id }}">{{ $s->customer->displayName() }}</a>
                    @else — @endif
                  </td>
                  <td class="fin-num">{{ $t((int) $s->price + $s->taxAmount()) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>

    {{-- ══ درآمدی که در دفترِ مالی نیست ══
         🔴 سرورِ ساعتی نه فاکتور می‌سازد نه ردیفِ پرداخت؛ مستقیم از اعتبار کم
         می‌شود. پس در «مالی و سود» و هر سودی که از دفتر بخواند دیده نمی‌شود.
         جدا نشان داده می‌شود تا با درآمدِ دفتر جمع نشود. --}}
    @if($in['hourly']['has_any'])
      <div class="ad-panel" style="margin-top:16px">
        <div class="ad-panel-h"><h2>درآمدِ سرورِ ساعتی</h2></div>
        <table class="fin-pl">
          <tr>
            <td>این ماه</td>
            <td class="fin-num" style="color:#34d399">{{ $t($in['hourly']['month']) }}</td>
            <td class="fin-src">کسر از اعتبارِ مشتریان</td>
          </tr>
          <tr>
            <td>{{ fa_num($in['hourly']['months']) }} ماهِ گذشته</td>
            <td class="fin-num" style="color:#34d399">{{ $t($in['hourly']['total']) }}</td>
            <td class="fin-src">—</td>
          </tr>
        </table>
        <div class="fin-cat">
          <div class="fin-cat-h" style="color:#fbbf24">
            ⚠️ این مبلغ در «مالی و سود» **نیست**. سرورِ ساعتی فاکتور و ردیفِ پرداخت
            نمی‌سازد و دفترِ مالی فقط از روی پرداخت درآمد ثبت می‌کند. پس سودی که
            آن‌جا می‌بینید به اندازهٔ همین عدد کم‌تر از واقع است.
          </div>
        </div>
      </div>
    @endif

    {{-- ══ رشدِ مشتری ══ --}}
    <div class="ad-panel" style="margin-top:16px">
      <div class="ad-panel-h">
        <h2>مشتریانِ تازه</h2>
        <span style="font-size:12px;color:var(--dim)">۱۲ ماهِ گذشته</span>
      </div>
      <div class="fin-cat">
        @foreach($customers['trend'] as $m)
          <div class="fin-cat-row">
            <span dir="ltr">{{ $m['label'] }}</span>
            <div class="fin-cat-bar"><i style="width:{{ (int) round($m['count'] / $maxCust * 100) }}%"></i></div>
            <span class="fin-num">{{ fa_num($m['count']) }}</span>
          </div>
        @endforeach
      </div>

      <table class="fin-pl">
        <tr>
          <td>سرویسِ زنده</td>
          <td class="fin-num">{{ fa_num($customers['active_services']) }}</td>
          <td class="fin-src">فعال، معلق یا در انتظارِ تحویل</td>
        </tr>
        <tr>
          <td>ثبت‌نامِ نیمه‌کاره</td>
          <td class="fin-num" style="color:{{ $customers['abandoned'] > 0 ? '#fbbf24' : 'inherit' }}">
            {{ fa_num($customers['abandoned']) }}
          </td>
          <td class="fin-src">
            @if($customers['abandoned'] > 0)
              ≈ {{ $t($customers['abandoned_cost']) }} استعلامِ سوخته
            @else
              — @endif
          </td>
        </tr>
        <tr>
          <td>سرویسِ بسته‌شده در ۳۰ روز</td>
          <td class="fin-num" style="color:{{ $customers['churn_30'] > 0 ? '#fbbf24' : 'inherit' }}">
            {{ fa_num($customers['churn_30']) }}
          </td>
          <td class="fin-src"><a href="/admin/finance">دلیل‌ها در دفترِ مالی</a></td>
        </tr>
      </table>
    </div>

  </div>

  <div>

    {{-- ══ این ماه، از دفترِ مالی ══ --}}
    <div class="ad-panel">
      <div class="ad-panel-h"><h2>این ماه تا امروز</h2></div>
      @unless($ledgerReady)
        <p style="padding:20px;color:var(--dim)">دفترِ مالی روی این سرور ساخته نشده؛ اعداد صفرند.</p>
      @endunless
      <table class="fin-pl">
        <tr>
          <td>درآمد</td>
          <td class="fin-num" style="color:#34d399">{{ $t($month['revenue']) }}</td>
          <td class="fin-src">{{ fa_num($month['revenue_count']) }} پرداخت</td>
        </tr>
        <tr>
          <td>هزینه</td>
          <td class="fin-num" style="color:#ff6b6b">− {{ $t($month['expense']) }}</td>
          <td class="fin-src">{{ fa_num($month['expense_count']) }} ردیف</td>
        </tr>
        <tr class="fin-total">
          <td>سود</td>
          <td class="fin-num" style="color:{{ $month['net_profit'] >= 0 ? '#34d399' : '#ff6b6b' }}">
            {{ $t($month['net_profit']) }}
          </td>
          <td class="fin-src">حاشیه {{ fa_num($month['margin']) }}٪</td>
        </tr>
      </table>

      <div class="fin-cat">
        <div class="fin-cat-h">۶ ماهِ گذشته</div>
        @foreach($trend as $m)
          <div class="fin-cat-row">
            <span dir="ltr">{{ $m['label'] }}</span>
            <div class="fin-cat-bar"><i style="width:{{ (int) round($m['revenue'] / $maxTrend * 100) }}%"></i></div>
            <span class="fin-num" style="color:{{ $m['profit'] >= 0 ? '#34d399' : '#ff6b6b' }}">{{ $t($m['profit']) }}</span>
          </div>
        @endforeach
      </div>
    </div>

    {{-- ══ زیرساخت ══ --}}
    <div class="ad-panel" style="margin-top:16px">
      <div class="ad-panel-h">
        <h2>ظرفیتِ زیرساخت</h2>
        @if($infra['stuck'] > 0)
          <span class="ad-pill">{{ fa_num($infra['stuck']) }} تحویلِ گیرکرده</span>
        @endif
      </div>

      @if($infra['servers'] === [])
        <p style="padding:20px;color:var(--dim)">سروری ثبت نشده. از <a href="/admin/servers">سرورهای تحویل</a> اضافه کنید.</p>
      @else
        <div class="fin-cat">
          <div class="fin-cat-h">درصدها **ظرفیتِ حساب** است، نه پر بودنِ دیسک.</div>
          @foreach($infra['servers'] as $s)
            <div class="fin-cat-row">
              <span>
                {{ $s['name'] }}
                <small style="color:var(--dim)">{{ $s['type'] }} · {{ $s['country'] }}</small>
              </span>
              <div class="fin-cat-bar">
                @if($s['pct'] !== null)
                  <i style="width:{{ $s['pct'] }}%;background:{{ $s['pct'] >= 90 ? '#ff6b6b' : ($s['pct'] >= 70 ? '#fbbf24' : '') }}"></i>
                @endif
              </div>
              <span class="fin-num">
                @if($s['max'] !== null)
                  {{ fa_num($s['used']) }}/{{ fa_num($s['max']) }}
                @else
                  {{ fa_num($s['used']) }} <small style="color:var(--dim)">نامحدود</small>
                @endif
                @if($s['full'])<span style="color:#ff6b6b"> پر</span>@endif
                <br>
                @if($s['cost'] !== null)
                  <small style="color:var(--dim)">{{ $t($s['cost']) }}/ماه</small>
                @elseif($s['cost_unknown'] ?? true)
                  <small style="color:#ff6b6b">اجاره وارد نشده</small>
                @else
                  <small style="color:#fbbf24">نرخِ ارز در دسترس نبود</small>
                @endif
              </span>
            </div>
          @endforeach
        </div>
      @endif

      @if($infra['cloud']['total'] > 0)
        <table class="fin-pl">
          <tr>
            <td>سرورِ ابری</td>
            <td class="fin-num">{{ fa_num($infra['cloud']['total']) }}</td>
            <td class="fin-src">
              {{ fa_num($infra['cloud']['running']) }} روشن ·
              {{ fa_num($infra['cloud']['off']) }} خاموش
              @if($infra['cloud']['error'] > 0) · <span style="color:#ff6b6b">{{ fa_num($infra['cloud']['error']) }} خطا</span>@endif
            </td>
          </tr>
          <tr>
            <td>منابعِ تخصیص‌یافته</td>
            <td class="fin-num">{{ fa_num($infra['cloud']['vcpu']) }} vCPU</td>
            <td class="fin-src">
              {{ fa_num($infra['cloud']['ram_gb']) }} گیگ رم · {{ fa_num($infra['cloud']['disk_gb']) }} گیگ دیسک
            </td>
          </tr>
        </table>
      @endif
    </div>

  </div>
</div>

{{-- ══ آنچه این صفحه نمی‌داند ══
     🔴 عمداً پایینِ صفحه و نه پنهان: گزارشی که فقط دانستنی‌ها را نشان دهد،
     ناخواسته ادعا می‌کند بقیه صفر است. --}}
<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h2>آنچه این اعداد نمی‌گویند</h2></div>
  <div class="fin-cat">
    @foreach($blindSpots as $b)
      <p style="margin:0 0 12px;font-size:12.5px;line-height:2">
        <b style="color:#fbbf24">{{ $b['title'] }}</b><br>
        <span style="color:var(--dim)">{{ $b['why'] }}</span>
      </p>
    @endforeach
  </div>
</div>

@include('admin.partials.finance-styles')

@endsection
