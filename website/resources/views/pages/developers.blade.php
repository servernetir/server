@php
  /*
  |----------------------------------------------------------------------------
  | مستنداتِ APIِ نمایندگیِ دامنه — سه‌زبانه، و اعداد همه از config
  |----------------------------------------------------------------------------
  |
  | 🔴 هیچ عددی در این فایل تایپ نشده. سقف‌ها، دسترسی‌ها، پله‌ها و فهرستِ
  | عملیاتِ ممنوع از `config()` می‌آیند. مستنداتی که عددش دستی نوشته شود،
  | اولین باری که تنظیمات عوض شود دروغ می‌گوید — و نماینده‌ای که بر اساسش کد
  | نوشته، خرابی‌اش را ماه‌ها بعد کشف می‌کند.
  |
  | ⚠️ نثر در `resources/content/developers.php` است (سه زبان). نسخهٔ اول این
  | صفحه فارسی‌تنها بود در حالی که `/en/developers` و `/tr/developers` از قبل
  | ساخته می‌شدند — پس بازدیدکنندهٔ انگلیسی یک صفحهٔ کاملاً فارسی می‌دید، که
  | از نبودِ صفحه بدتر است: به‌نظر می‌رسد سایت خراب است نه اینکه ترجمه ندارد.
  |
  | ⚠️ `$print` نسخهٔ چاپی/PDF است. پروژه کتابخانهٔ PDF ندارد و الگوی جاافتاده‌اش
  | همان `invoice-print.blade.php` است: HTMLِ بهینه برای چاپ + «ذخیره به PDF»
  | خودِ مرورگر. افزودنِ وابستگیِ composer روی این پروداکشن (بی‌SSH، دیپلویِ
  | فایل‌به‌فایل) عملاً ممکن نیست.
  */
  $c   = require resource_path('content/developers.php');
  $L   = fn (string $k) => lc($c[$k] ?? []);
  $loc = app()->getLocale();
  $isFa = $loc === 'fa';
  $n   = fn ($v) => $isFa ? fa_num((string) $v) : (string) $v;

  $print     = (bool) request()->boolean('print');
  $rate      = (array) config('domain_reseller.limits.rate', []);
  $maxYears  = (int) config('domain_reseller.limits.max_years', 10);
  $panelOnly = (array) config('domain_reseller.panel_only_operations', []);
  $abilities = \App\Models\CustomerApiToken::ABILITIES;
  $maxTokens = \App\Models\CustomerApiToken::MAX_ACTIVE;
  $base      = url('/api/v1');

  // ⚠️ `ABILITIES` و `panel_only_operations` هر دو فقط متنِ فارسی دارند (در کد
  //    و config). فهرست از همان‌ها می‌آید — پس موردِ تازه هرگز از مستندات جا
  //    نمی‌مانَد — و متن اگر ترجمه داشته باشد از content می‌آید، وگرنه همان
  //    فارسی. «ترجمه‌نشده» بهتر از «نامرئی» است.
  $scopeTxt = (array) $L('s2_scope_desc');
  $onlyTxt  = (array) $L('s9_desc');
  // `**…**` در متنِ مدل برای پنل است؛ در جدولِ مستندات فقط سروصدا می‌کند
  $plain = fn (string $s) => str_replace('**', '', $s);

  // «۱۲۰,۱» → ۱۲۰ در هر ۱ دقیقه
  $rateOf = function (string $k, string $fallback) use ($rate, $n, $L) {
      [$hits, $mins] = array_pad(explode(',', (string) ($rate[$k] ?? $fallback)), 2, '1');
      return $n($hits).' / '.$n($mins).' '.($L('s8_rows')['min'] ?? 'min');
  };

  $endpoints = [
      ['GET',  '/ping',                          'read',           ['fa'=>'آزمون اتصال، سطح و اعتبار','en'=>'connection test, tier and credit','tr'=>'baglanti testi, kademe ve kredi']],
      ['GET',  '/tlds',                          'domains:read',   ['fa'=>'قیمت پسوندها (ثبت/تمدید/انتقال)','en'=>'per-TLD prices (register/renew/transfer)','tr'=>'uzanti fiyatlari']],
      ['POST', '/domains/check',                 'domains:read',   ['fa'=>'استعلام موجودی و قیمت','en'=>'availability and price','tr'=>'uygunluk ve fiyat']],
      ['GET',  '/domains',                       'domains:read',   ['fa'=>'فهرست دامنه‌های شما','en'=>'your domains','tr'=>'alan adlariniz']],
      ['GET',  '/domains/{domain}',              'domains:read',   ['fa'=>'جزئیات، انقضا، وضعیت','en'=>'details, expiry, status','tr'=>'ayrinti, bitis, durum']],
      ['POST', '/domains',                       'domains:write',  ['fa'=>'ثبت — از اعتبار کسر می‌شود','en'=>'register — deducted from credit','tr'=>'kayit — krediden dusulur']],
      ['POST', '/domains/{domain}/renew',        'domains:write',  ['fa'=>'تمدید — از اعتبار کسر می‌شود','en'=>'renew — deducted from credit','tr'=>'yenileme — krediden dusulur']],
      ['PUT',  '/domains/{domain}/nameservers',  'domains:manage', ['fa'=>'تغییر نام‌سرور','en'=>'set nameservers','tr'=>'ad sunucu ayarla']],
      ['POST', '/domains/{domain}/lock',         'domains:manage', ['fa'=>'روشن کردن قفل انتقال','en'=>'turn transfer lock on','tr'=>'transfer kilidini ac']],
      ['POST', '/domains/{domain}/auto-renew',   'domains:manage', ['fa'=>'تمدید خودکار','en'=>'auto-renew flag','tr'=>'otomatik yenileme']],
  ];

  $toc = ['s1','s2','s3','s4','s5','s6','s7','s8','s9','s10','s11'];
@endphp

@extends('layouts.site')

@section('title', $L('title').' — '.__('ui.brand'))
@section('description', $L('meta_desc'))

@section('content')

@php
  // HowTo برای نتیجهٔ غنی — از همان چهار قدمِ متنِ صفحه ساخته می‌شود، نه جدا،
  // وگرنه روزی متن عوض می‌شود و نشانه‌گذاری همان حرفِ قدیمی را می‌زند.
  $sdSteps = [];
  foreach ((array) $L('s1_steps') as $i => $st) {
      $sdSteps[] = ['@type' => 'HowToStep', 'position' => $i + 1, 'text' => $st];
  }
@endphp
<script type="application/ld+json">{!! schema_ld(['name' => $L('title'), 'step' => $sdSteps], 'HowTo') !!}</script>
<script type="application/ld+json">{!! schema_ld(['itemListElement' => [
  ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => lroute('home')],
  ['@type' => 'ListItem', 'position' => 2, 'name' => $L('title'), 'item' => url()->current()],
]], 'BreadcrumbList') !!}</script>

<section class="hero hero-sub dev-hero">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ $L('badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ $L('title') }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ $L('lead') }}</p>
      {{-- فقط در چاپ دیده می‌شود (CSS). نسخهٔ PDF بدونِ نشانیِ مرجع، ماه‌ها بعد
           به‌عنوانِ «مستنداتِ فعلی» دست‌به‌دست می‌شود. --}}
      <p class="dev-printurl">{{ url()->current() }} · {{ sdate(now()) }}</p>
      <div class="hero-ctas reveal" style="transition-delay:.24s">
        <a class="btn btn-primary" href="#s1"><span>{{ lc($c['s1']) }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        {{-- نسخهٔ چاپی: مرورگر خودش «ذخیره به PDF» دارد --}}
        <a class="btn btn-glass" href="{{ url()->current() }}?print=1" target="_blank" rel="noopener" title="{{ $L('print_hint') }}">
          <svg class="icon" style="width:16px;height:16px"><use href="#i-file"/></svg>{{ $L('print') }}
        </a>
      </div>
    </div>
  </div>
</section>

<section class="section dev-wrap">
  <div class="container dev-grid">

    {{-- ══ فهرست چسبان ══
         روی موبایل به یک نوار افقیِ قابلِ اسکرول تبدیل می‌شود (CSS)، نه اینکه
         ۱۰ ردیف بالای محتوا بنشیند و صفحه را دو برابر کند. --}}
    <aside class="dev-toc" aria-label="{{ $L('toc_title') }}">
      <b>{{ $L('toc_title') }}</b>
      <ol>
        @foreach($toc as $i => $k)
          <li><a href="#{{ $k }}"><span class="dev-toc-n">{{ $n($i + 1) }}</span>{{ lc($c[$k]) }}</a></li>
        @endforeach
      </ol>
    </aside>

    <div class="dev-doc">

      {{-- ═════════ ۱ ═════════ --}}
      <h2 id="s1"><span class="dev-h-n">{{ $n(1) }}</span>{{ lc($c['s1']) }}</h2>
      <ol class="dev-steps">
        @foreach((array) $L('s1_steps') as $st)<li>{{ $st }}</li>@endforeach
      </ol>
      <div class="dev-note dev-warn">{{ $L('s1_warn') }}</div>

      {{-- ═════════ ۲ ═════════ --}}
      <h2 id="s2"><span class="dev-h-n">{{ $n(2) }}</span>{{ lc($c['s2']) }}</h2>
      <p>{{ $L('s2_p') }}</p>
      <pre dir="ltr" class="dev-code" data-copy>curl -H "Authorization: Bearer sn_xxxxxxxx" \
     {{ $base }}/ping</pre>

      <h3>{{ $L('s2_scopes') }}</h3>
      <div class="dev-tablewrap"><table class="dev-table">
        <tbody>
          @foreach($abilities as $key => $desc)
            <tr><td><code dir="ltr">{{ $key }}</code></td><td>{{ $plain($scopeTxt[$key] ?? $desc) }}</td></tr>
          @endforeach
        </tbody>
      </table></div>
      <p class="dev-sub">{{ $L('s2_note') }}</p>

      {{-- ═════════ ۳ ═════════ --}}
      <h2 id="s3"><span class="dev-h-n">{{ $n(3) }}</span>{{ lc($c['s3']) }}</h2>
      <p>{{ $L('s3_p') }}</p>
      <pre dir="ltr" class="dev-code" data-copy>{"ok": true,  "data": { ... }}
{"ok": false, "error": "insufficient_credit", "message": "..."}</pre>
      <div class="dev-note dev-warn">{{ $L('s3_warn') }}</div>

      {{-- ═════════ ۴ ═════════ --}}
      <h2 id="s4"><span class="dev-h-n">{{ $n(4) }}</span>{{ lc($c['s4']) }}</h2>
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

      <h3>{{ $L('s4_check') }}</h3>
      <pre dir="ltr" class="dev-code" data-copy>POST {{ $base }}/domains/check
{"domain": "example.com", "tlds": ["com", "net"]}

{"ok": true, "data": [{
  "domain": "example.com", "state": "free", "available": true,
  "currency": "IRT",
  "price": {"register": 1150000, "renew": 1250000, "retail": 1320000},
  "discount_pct": 12.88, "price_floored": false
}]}</pre>

      <div class="dev-note dev-warn">
        <p style="margin:0 0 8px">{{ $L('s4_state_warn') }}</p>
        <div class="dev-states">
          @foreach((array) $L('s4_states') as $k => $v)
            <span><code dir="ltr">{{ $k }}</code> {{ $v }}</span>
          @endforeach
        </div>
      </div>
      <p class="dev-sub">{{ $L('s4_floor_note') }}</p>

      <h3>{{ $L('s4_register') }}</h3>
      <pre dir="ltr" class="dev-code" data-copy>POST {{ $base }}/domains
Idempotency-Key: your-order-12345
{"domain": "example.com", "years": 1,
 "nameservers": ["ns1.you.com", "ns2.you.com"]}

{"ok": true, "data": {
  "domain": "example.com", "status": "pending",
  "order_state": "registered", "registrant": "reseller",
  "charged": 1265000, "currency": "IRT"
}}</pre>

      <div class="dev-tablewrap"><table class="dev-table">
        <thead><tr><th><code dir="ltr">order_state</code></th><th></th><th></th></tr></thead>
        <tbody>
          @foreach((array) $L('s4_order_states') as $k => $pair)
            <tr><td><code dir="ltr">{{ $k }}</code></td><td>{{ $pair[0] }}</td><td class="dev-sub">{{ $pair[1] }}</td></tr>
          @endforeach
        </tbody>
      </table></div>
      <div class="dev-note dev-warn">{{ $L('s4_pending_warn') }}</div>

      {{-- ═════════ ۵ ═════════ --}}
      <h2 id="s5"><span class="dev-h-n">{{ $n(5) }}</span>{{ lc($c['s5']) }}</h2>
      <p>{{ $L('s5_p') }}</p>
      <div class="dev-note dev-warn">
        <b>{{ $L('s5_warn_title') }}</b>
        <p style="margin:8px 0 0">{{ $L('s5_warn') }}</p>
        <pre dir="ltr" class="dev-code" style="margin:10px 0 0" data-copy>sha256("renew|example.com|2027-01-01|1")</pre>
      </div>
      <p class="dev-sub">{{ $L('s5_note') }}</p>

      {{-- ═════════ ۶ ═════════ --}}
      <h2 id="s6"><span class="dev-h-n">{{ $n(6) }}</span>{{ lc($c['s6']) }}</h2>
      <div class="dev-tablewrap"><table class="dev-table">
        <tbody>
          @foreach((array) $L('s6_rows') as $code => $meaning)
            <tr><td><code dir="ltr">{{ $code }}</code></td><td>{{ $meaning }}</td></tr>
          @endforeach
        </tbody>
      </table></div>

      {{-- ═════════ ۷ ═════════ --}}
      <h2 id="s7"><span class="dev-h-n">{{ $n(7) }}</span>{{ lc($c['s7']) }}</h2>
      <p>{{ $L('s7_p') }}</p>
      <ul class="dev-list">
        @foreach((array) $L('s7_bullets') as $b)<li>{{ $b }}</li>@endforeach
      </ul>
      <h3>{{ $L('s7_floor_title') }}</h3>
      <p>{{ $L('s7_floor') }}</p>
      <p class="dev-sub">{{ $L('s7_floor_why') }}</p>

      {{-- ═════════ ۸ ═════════ --}}
      <h2 id="s8"><span class="dev-h-n">{{ $n(8) }}</span>{{ lc($c['s8']) }}</h2>
      @php $r8 = (array) $L('s8_rows'); @endphp
      <div class="dev-tablewrap"><table class="dev-table">
        <tbody>
          <tr><td>{{ $r8['read'] }}</td><td dir="ltr">{{ $rateOf('read', '120,1') }}</td></tr>
          <tr><td>{{ $r8['check'] }}</td><td dir="ltr">{{ $rateOf('check', '60,1') }}</td></tr>
          <tr><td>{{ $r8['write'] }}</td><td dir="ltr">{{ $rateOf('write', '20,1') }}</td></tr>
          <tr><td>{{ $r8['years'] }}</td><td>{{ $n($maxYears) }}</td></tr>
          <tr><td>{{ $r8['tokens'] }}</td><td>{{ $n($maxTokens) }}</td></tr>
        </tbody>
      </table></div>
      <p class="dev-sub">{{ $L('s8_note') }}</p>

      {{-- ═════════ ۹ ═════════ --}}
      <h2 id="s9"><span class="dev-h-n">{{ $n(9) }}</span>{{ lc($c['s9']) }}</h2>
      <p>{{ $L('s9_p') }}</p>
      <div class="dev-tablewrap"><table class="dev-table">
        <tbody>
          {{-- ⚠️ از همان آرایهٔ config می‌آید، پس اگر روزی عملیاتی اضافه شود
               مستندات خودبه‌خود راست می‌گوید. --}}
          @foreach($panelOnly as $key => $why)
            <tr><td><code dir="ltr">{{ $key }}</code></td><td>{{ $onlyTxt[$key] ?? $why }}</td></tr>
          @endforeach
          @foreach((array) $L('s9_extra') as $key => $why)
            <tr><td><code dir="ltr">{{ $key }}</code></td><td>{{ $why }}</td></tr>
          @endforeach
        </tbody>
      </table></div>
      <div class="dev-note dev-warn">{{ $L('s9_registrant') }}</div>
      <div class="dev-note">{{ $L('s9_ir') }}</div>

      {{-- ═════════ ۱۰ ═════════ --}}
      <h2 id="s10"><span class="dev-h-n">{{ $n(10) }}</span>{{ lc($c['s10']) }}</h2>
      <p>{{ $L('s10_p') }}</p>
      <div class="dev-plugins">
        <div class="dev-plugin">
          <b>WHMCS</b>
          <code dir="ltr">modules/registrars/servernet/</code>
          <p>{{ $L('s10_whmcs') }}</p>
        </div>
        <div class="dev-plugin">
          <b>WordPress + WooCommerce</b>
          <code dir="ltr">wp-content/plugins/servernet-domains/</code>
          <p>{{ $L('s10_wp') }}</p>
        </div>
      </div>
      <div class="dev-note dev-warn">
        <b>{{ $L('s10_guards_title') }}</b>
        <ol style="margin:8px 0 0;padding-inline-start:20px;line-height:2.1">
          @foreach((array) $L('s10_guards') as $g)<li>{{ $g }}</li>@endforeach
        </ol>
      </div>

      {{-- ═════════ ۱۱ ═════════
           ⚠️ «برنامه‌ریزی‌شده» صریح اعلام می‌شود، نه با سکوت. یکپارچه‌سازی‌ای
           که نداند انتقالِ دامنه در راه است، معماری‌اش را طوری می‌چیند که بعداً
           جا نمی‌شود — و نبودِ یک قابلیت را «هرگز» می‌خوانَد نه «هنوز». --}}
      <h2 id="s11"><span class="dev-h-n">{{ $n(11) }}</span>{{ lc($c['s11']) }}</h2>
      <p>{{ $L('s11_p') }}</p>
      <div class="dev-tablewrap"><table class="dev-table">
        <tbody>
          @foreach((array) $L('s11_rows') as $key => $what)
            <tr><td><code dir="ltr">{{ $key }}</code></td><td>{{ $what }}</td></tr>
          @endforeach
        </tbody>
      </table></div>

      <p style="margin-top:34px">
        <a class="btn btn-primary" href="{{ url('/account/reseller') }}">{{ __('ui.nav_dash') }}</a>
        <a class="btn btn-glass" href="{{ url()->current() }}?print=1" target="_blank" rel="noopener">{{ $L('print') }}</a>
      </p>
    </div>
  </div>
</section>

{{-- کپیِ بلوک‌های کد.
     ⚠️ هیچ کتابخانه‌ای؛ CSP این پروژه هر منبعِ خارجی را بی‌صدا بلاک می‌کند. --}}
<script>
(function () {
  var LBL = @json(['copy' => __('ui.sec_copied')]);
  document.querySelectorAll('pre[data-copy]').forEach(function (pre) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'dev-copy';
    b.textContent = '⧉';
    b.setAttribute('aria-label', LBL.copy);
    b.addEventListener('click', function () {
      navigator.clipboard.writeText(pre.textContent).then(function () {
        b.textContent = '✓';
        setTimeout(function () { b.textContent = '⧉'; }, 1400);
      });
    });
    pre.appendChild(b);
  });

  @if($print)
  window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
  @endif
})();
</script>
@endsection
