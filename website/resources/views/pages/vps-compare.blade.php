{{--
  «کدام کشور برای سرور مجازی خارج؟» — /vps/compare-locations (fa / en / tr).

  ساختار: پاسخِ مستقیم (lead) ← پیشنهاددهندهٔ دوسؤالی ← جدولِ زنده ← «هر کاربرد،
  کدام کشور» (نسخهٔ بی‌JSِ همان منطق، برای خزنده و موتورهای پاسخ) ← چک‌لیست ←
  پرسش‌ها ← لینک‌های داخلی.

  ⚠️ همهٔ عددها از ForeignVpsFinderController می‌آیند؛ متن از config/foreign_vps.php.
  ⚠️ دادهٔ JS فقط از دستورِ json با متغیرِ ازپیش‌ساخته (نه آرایهٔ درون‌خطی) — CLAUDE.md §۳.
     (نامِ دستورهای Blade را با @ در همین کامنت ننویس: پارسر جفتشان می‌کند.)
  ⚠️ نتیجهٔ پیشنهاد با textContent ساخته می‌شود، نه innerHTML.
  ⚠️ اسکرول فقط پس از کلیکِ کاربر، با scroll-margin-top از --header-h.
  ⚠️ استایل درجا با پیشوندِ vc- (همان الگوی vps-hourly).
--}}
@extends('layouts.site')

@php
  $vcNum = fn ($n) => $isFa ? fa_num($n) : (string) $n;
  $vcSelf = url()->current();
  $vcIntl = lroute('catalog', ['category' => 'vps', 'slug' => 'international']);

  $vcData = [
      'rows' => array_map(fn ($r) => [
          'iso' => $r['iso'], 'label' => $r['label'], 'url' => $r['url'], 'plans' => $r['plans'],
          'plansTxt' => $vcNum($r['plans']), 'price' => $r['price'], 'priceRank' => $r['price_rank'],
          'plansRank' => $r['plans_rank'], 'region' => $r['region'], 'uses' => (object) $r['uses'], 'why' => $r['why'],
          'flag' => $r['flag_svg'],
      ], $rows),
      'ui' => [
          'rank' => $ui['res_rank'], 'plans' => $ui['res_plans'], 'from' => $ui['res_from'],
          'go' => $ui['res_go'], 'hint' => $ui['quiz_hint'], 'nums' => $isFa ? '۰۱۲۳۴۵۶۷۸۹' : '0123456789',
      ],
  ];

  $vcFaqLd = [];
  foreach ($faq as $q) {
      $vcFaqLd[] = ['@type' => 'Question', 'name' => $q['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['a']]];
  }
  $vcCrumbs = ['itemListElement' => [
      ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => $homeUrl],
      ['@type' => 'ListItem', 'position' => 2, 'name' => $ui['cross_intl'], 'item' => $vcIntl],
      ['@type' => 'ListItem', 'position' => 3, 'name' => $meta['crumb'], 'item' => $vcSelf],
  ]];
  $vcList = ['name' => $ui['table_t'], 'itemListElement' => []];
  foreach ($rows as $i => $r) {
      $vcList['itemListElement'][] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $r['label'], 'url' => $r['url']];
  }
@endphp

@section('title', $meta['t'])
@section('description', $meta['d'])

@section('content')

<script type="application/ld+json">{!! schema_ld(['mainEntity' => $vcFaqLd], 'FAQPage') !!}</script>
<script type="application/ld+json">{!! schema_ld($vcCrumbs, 'BreadcrumbList') !!}</script>
@if($rows)
<script type="application/ld+json">{!! schema_ld($vcList, 'ItemList') !!}</script>
@endif

{{-- ═══════════ سرتیتر ═══════════ --}}
<section class="section vc-top">
  <div class="container">
    <nav class="vc-crumbs" aria-label="breadcrumb">
      <a href="{{ $homeUrl }}">{{ __('ui.brand') }}</a>
      <span aria-hidden="true">/</span>
      <a href="{{ $vcIntl }}">{{ $ui['cross_intl'] }}</a>
      <span aria-hidden="true">/</span>
      <span>{{ $meta['crumb'] }}</span>
    </nav>
    <div class="vc-head">
      <span class="badge reveal"><span class="pulse"></span><span>{{ $meta['badge'] }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ $meta['h1'] }} <span class="grad">{{ $meta['h1_g'] }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ $meta['lead'] }}</p>
      <div class="vc-pills reveal" style="transition-delay:.22s">
        @if($count)
          <span class="vc-pill vc-pill-p">{{ str_replace(':n', $vcNum($count), $ui['pill_countries']) }}</span>
          <span class="vc-pill">{{ str_replace(':n', $vcNum($planTotal), $ui['pill_plans']) }}</span>
        @endif
        @if($fromPrice)
          <span class="vc-pill">{{ str_replace(':price', $fromPrice, $ui['pill_from']) }}</span>
        @endif
        <span class="vc-pill">{{ $ui['pill_live'] }}</span>
      </div>
      <div class="hero-ctas reveal" style="transition-delay:.28s">
        @if($rows)
          <a class="btn btn-primary" href="#finder"><span>{{ $ui['cta_quiz'] }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        @endif
        <a class="btn btn-glass" href="#compare">{{ $ui['cta_table'] }}</a>
      </div>
    </div>
  </div>
</section>

{{-- ═══════════ پیشنهاددهنده ═══════════ --}}
@if($rows)
<section class="section vc-sec" id="finder">
  <div class="container">
    <div class="vc-sec-h">
      <h2>{{ $ui['quiz_t'] }}</h2>
      <p>{{ $ui['quiz_d'] }}</p>
    </div>
    <form class="vc-quiz" id="vc-quiz" novalidate>
      <fieldset>
        <legend>{{ $ui['q1'] }}</legend>
        <div class="vc-opts">
          @foreach($uses as $u)
            <label class="vc-opt">
              <input type="radio" name="use" value="{{ $u['key'] }}">
              <svg class="icon"><use href="#i-{{ $u['icon'] }}"/></svg>
              <span>{{ $u['t'] }}</span>
            </label>
          @endforeach
        </div>
      </fieldset>
      <fieldset>
        <legend>{{ $ui['q2'] }}</legend>
        <div class="vc-opts vc-opts-3">
          @foreach(['price', 'choice', 'near'] as $p)
            <label class="vc-opt">
              <input type="radio" name="prio" value="{{ $p }}" @if($p === 'price') checked @endif>
              <span>{{ $ui['p_'.$p] }}</span>
            </label>
          @endforeach
        </div>
      </fieldset>
      <div class="vc-quiz-foot">
        <button type="submit" class="btn btn-primary">{{ $ui['quiz_btn'] }}</button>
        <span class="vc-hint" id="vc-hint" role="status" aria-live="polite"></span>
      </div>
    </form>

    <div class="vc-result" id="vc-result" hidden>
      <h3>{{ $ui['res_t'] }}</h3>
      <ol class="vc-picks" id="vc-picks"></ol>
      <a href="#finder" class="vc-again">{{ $ui['res_again'] }}</a>
    </div>
  </div>
</section>
@endif

{{-- ═══════════ جدولِ مقایسه ═══════════ --}}
<section class="section vc-sec" id="compare">
  <div class="container">
    <div class="vc-sec-h">
      <h2>{{ $ui['table_t'] }}</h2>
      <p>{{ $ui['table_d'] }}</p>
    </div>
    @if($rows)
      <div class="vc-table-wrap">
        <table class="vc-table">
          <thead>
            <tr>
              <th>{{ $ui['th_country'] }}</th>
              <th>{{ $ui['th_cities'] }}</th>
              <th>{{ $ui['th_plans'] }}</th>
              <th>{{ $ui['th_from'] }}</th>
              <th>{{ $ui['th_best'] }}</th>
              <th>{{ $ui['th_link'] }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach($rows as $r)
              @php
                $vcBest = [];
                foreach ($uses as $u) {
                    if (($r['uses'][$u['key']] ?? 0) >= 3) { $vcBest[] = $u['t']; }
                }
              @endphp
              <tr>
                <td class="vc-td-c">@include('partials.flag', ['flagSrc' => $r['flag_svg'], 'flagEmoji' => $r['flag'], 'flagSize' => 18]) <span>{{ $r['label'] }}</span></td>
                <td>{{ implode($isFa ? '، ' : ', ', $r['cities']) ?: '—' }}</td>
                <td>{{ $vcNum($r['plans']) }}</td>
                <td class="vc-td-p">@if($r['price'])<b>{{ $r['price'] }}</b> <small>{{ $ui['per_mo'] }}</small>@else — @endif</td>
                <td class="vc-td-best">{{ $vcBest ? implode(' · ', array_slice($vcBest, 0, 2)) : '—' }}</td>
                <td><a class="vc-link" href="{{ $r['url'] }}" aria-label="{{ $ui['see'].' — '.$r['label'] }}">{{ $ui['see'] }}</a></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @else
      <div class="vc-empty">
        <p>{{ $ui['empty'] }}</p>
        <a class="btn btn-primary" href="{{ lroute('contact') }}">{{ __('ui.nav_contact') }}</a>
      </div>
    @endif
  </div>
</section>

{{-- ═══════════ هر کاربرد، کدام کشور ═══════════ --}}
<section class="section vc-sec" id="use-cases">
  <div class="container">
    <div class="vc-sec-h">
      <h2>{{ $ui['uses_t'] }}</h2>
      <p>{{ $ui['uses_d'] }}</p>
    </div>
    <div class="vc-uses">
      @foreach($uses as $i => $u)
        <article class="vc-use reveal" style="transition-delay:{{ $i * 40 }}ms">
          <svg class="icon"><use href="#i-{{ $u['icon'] }}"/></svg>
          <h3>{{ $u['t'] }}</h3>
          <p>{{ $u['d'] }}</p>
          @if($u['top'])
            <p class="vc-use-top"><b>{{ $ui['uses_top'] }}</b>
              @foreach($u['top'] as $j => $t)<a href="{{ $t['url'] }}">{{ $t['label'] }}</a>@if(! $loop->last){{ $isFa ? '، ' : ', ' }}@endif @endforeach
            </p>
          @endif
        </article>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══════════ چک‌لیست ═══════════ --}}
<section class="section vc-sec" id="checklist">
  <div class="container">
    <div class="vc-sec-h"><h2>{{ $ui['check_t'] }}</h2></div>
    <ol class="vc-check">
      @foreach($ui['check'] as $c)
        <li>{{ $c }}</li>
      @endforeach
    </ol>
    <p class="vc-ping"><a class="btn btn-glass" href="{{ lroute('lookup', 'ping') }}">{{ $ui['ping_cta'] }}</a></p>
  </div>
</section>

{{-- ═══════════ پرسش‌ها ═══════════ --}}
<section class="section vc-sec" id="faq">
  <div class="container">
    <div class="vc-sec-h"><h2>{{ $ui['faq_t'] }}</h2></div>
    <div class="vc-faq">
      @foreach($faq as $i => $row)
        <details @if($i === 0) open @endif>
          <summary>{{ $row['q'] }}</summary>
          <div>{{ $row['a'] }}</div>
        </details>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══════════ لینک‌سازی داخلی ═══════════ --}}
<section class="section vc-sec vc-cross-sec">
  <div class="container">
    <h2 class="vc-cross-t">{{ $ui['cross_t'] }}</h2>
    <div class="vc-cross">
      <a href="{{ $vcIntl }}">{{ $ui['cross_intl'] }}</a>
      <a href="{{ lroute('vps.hourly') }}">{{ $ui['cross_hourly'] }}</a>
      <a href="{{ lroute('catalog', ['category' => 'vps', 'slug' => 'trading']) }}">{{ $ui['cross_trading'] }}</a>
      <a href="{{ lroute('catalog', ['category' => 'vps', 'slug' => 'windows']) }}">{{ $ui['cross_windows'] }}</a>
      <a href="{{ lroute('catalog', ['category' => 'vps', 'slug' => 'iran']) }}">{{ $ui['cross_iran'] }}</a>
      <a href="{{ lroute('aup') }}">{{ $ui['cross_aup'] }}</a>
    </div>
  </div>
</section>

@include('partials.product-guides', ['guidesCat' => config('blog.product_guides.cloud')])

@if($rows)
<script>
(function () {
  var D = @json($vcData);
  var form = document.getElementById('vc-quiz');
  var box = document.getElementById('vc-result');
  var list = document.getElementById('vc-picks');
  var hint = document.getElementById('vc-hint');
  if (!form || !box || !list) { return; }

  function num(n) {
    return String(n).replace(/[0-9]/g, function (d) { return D.ui.nums.charAt(+d); });
  }
  function fill(tpl, key, val) { return tpl.split(':' + key).join(val); }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var use = form.querySelector('input[name="use"]:checked');
    var prio = form.querySelector('input[name="prio"]:checked');
    if (!use || !prio) { hint.textContent = D.ui.hint; return; }
    hint.textContent = '';

    var n = D.rows.length;
    var scored = D.rows.map(function (r) {
      var s = (r.uses[use.value] || 0) * 10;
      if (prio.value === 'price' && r.price) { s += (n - 1 - r.priceRank) * 2; }
      if (prio.value === 'choice') { s += (n - 1 - r.plansRank) * 2; }
      if (prio.value === 'near' && (r.region === 'eu' || r.region === 'cis')) { s += 6; }
      return { r: r, s: s };
    });
    scored.sort(function (a, b) { return (b.s - a.s) || (a.r.priceRank - b.r.priceRank); });

    list.textContent = '';
    scored.slice(0, 3).forEach(function (x, i) {
      var r = x.r;
      var li = document.createElement('li');
      li.className = 'vc-pick';

      var tag = document.createElement('span');
      tag.className = 'vc-pick-rank';
      tag.textContent = fill(D.ui.rank, 'n', num(i + 1));
      li.appendChild(tag);

      var h = document.createElement('h4');
      if (r.flag) {
        var img = document.createElement('img');
        img.src = r.flag; img.alt = ''; img.width = 20; img.height = 14;
        h.appendChild(img);
      }
      h.appendChild(document.createTextNode(r.label));
      li.appendChild(h);

      if (r.why) {
        var p = document.createElement('p');
        p.textContent = r.why;
        li.appendChild(p);
      }

      var meta = document.createElement('p');
      meta.className = 'vc-pick-meta';
      var bits = [fill(D.ui.plans, 'n', r.plansTxt)];
      if (r.price) { bits.push(fill(D.ui.from, 'price', r.price)); }
      meta.textContent = bits.join(' · ');
      li.appendChild(meta);

      var a = document.createElement('a');
      a.className = 'btn ' + (i === 0 ? 'btn-primary' : 'btn-glass');
      a.href = r.url;
      a.textContent = fill(D.ui.go, 'country', r.label);
      li.appendChild(a);

      list.appendChild(li);
    });

    box.hidden = false;
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
})();
</script>
@endif

<style>
/* صفحهٔ «کدام کشور برای سرور خارج» — استایلِ درجا با پیشوندِ vc- */
.vc-top{ padding-bottom:30px }
.vc-sec{ padding:44px 0 }
.vc-crumbs{ display:flex; align-items:center; gap:8px; font-size:12.5px; color:var(--dim); margin-bottom:18px; flex-wrap:wrap }
.vc-crumbs a{ color:var(--muted) }
.vc-crumbs a:hover{ color:var(--cyan) }
.vc-head{ max-width:880px }
.vc-head h1{ font-family:var(--font-disp); font-size:clamp(27px,4.4vw,44px); font-weight:700; letter-spacing:-.6px; line-height:1.25; margin:14px 0 16px; text-wrap:balance }
.vc-head .lead{ color:var(--muted); font-size:15px; line-height:2.1; max-width:780px }
.vc-pills{ display:flex; flex-wrap:wrap; gap:9px; margin-top:22px }
.vc-pill{ font-size:12.5px; color:var(--muted); border:1px solid var(--line); border-radius:30px; padding:6px 14px; background:var(--surface) }
.vc-pill-p{ border-color:rgba(34,211,238,.3); color:var(--text) }
.vc-head .hero-ctas{ margin-top:22px }
.vc-sec-h{ margin-bottom:22px; max-width:860px }
.vc-sec-h h2{ font-family:var(--font-disp); font-size:clamp(20px,3vw,27px); font-weight:700; letter-spacing:-.5px; margin-bottom:10px }
.vc-sec-h p{ color:var(--muted); font-size:14.2px; line-height:2 }

.vc-quiz{ border:1px solid var(--line); border-radius:20px; background:var(--surface); padding:22px; display:flex; flex-direction:column; gap:20px }
.vc-quiz fieldset{ border:0; padding:0; margin:0 }
.vc-quiz legend{ font-size:15px; font-weight:700; margin-bottom:12px }
.vc-opts{ display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:10px }
.vc-opts-3{ grid-template-columns:repeat(auto-fill,minmax(190px,1fr)) }
.vc-opt{ position:relative; display:flex; align-items:center; gap:10px; border:1px solid var(--line); border-radius:14px; padding:12px 14px; cursor:pointer; font-size:13.4px; line-height:1.7; transition:border-color .16s, background .16s }
.vc-opt:hover{ border-color:var(--line-2) }
.vc-opt input{ position:absolute; opacity:0; pointer-events:none }
.vc-opt .icon{ width:20px; height:20px; color:var(--cyan); flex-shrink:0 }
.vc-opt:has(input:checked){ border-color:var(--cyan); background:rgba(34,211,238,.08) }
.vc-opt:has(input:focus-visible){ outline:2px solid var(--cyan); outline-offset:2px }
.vc-quiz-foot{ display:flex; align-items:center; gap:14px; flex-wrap:wrap }
.vc-hint{ font-size:13px; color:var(--dim) }

.vc-result{ margin-top:22px; scroll-margin-top:calc(var(--header-h, 112px) + 16px) }
.vc-result h3{ font-size:17px; font-weight:700; margin-bottom:14px }
.vc-picks{ list-style:none; display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:14px }
.vc-pick{ display:flex; flex-direction:column; gap:8px; border:1px solid var(--line); border-radius:18px; background:var(--surface); padding:18px }
.vc-pick:first-child{ border-color:rgba(34,211,238,.45) }
.vc-pick-rank{ font-size:11.5px; color:var(--cyan); font-weight:700 }
.vc-pick h4{ display:flex; align-items:center; gap:8px; font-size:17px; font-weight:700 }
.vc-pick h4 img{ border-radius:3px }
.vc-pick p{ color:var(--muted); font-size:13px; line-height:1.9 }
.vc-pick .vc-pick-meta{ color:var(--text); font-weight:600; font-size:12.8px }
.vc-pick .btn{ margin-top:auto; justify-content:center }
.vc-again{ display:inline-block; margin-top:14px; font-size:13px; color:var(--muted); text-decoration:underline; text-underline-offset:3px }

.vc-table-wrap{ overflow-x:auto; border:1px solid var(--line); border-radius:18px; background:var(--surface) }
.vc-table{ width:100%; border-collapse:collapse; font-size:13.4px; min-width:720px }
.vc-table th, .vc-table td{ padding:13px 16px; text-align:start; border-bottom:1px solid var(--line); vertical-align:middle }
.vc-table thead th{ font-size:12.2px; color:var(--dim); font-weight:600; background:var(--surface-2) }
.vc-table tbody tr:last-child td{ border-bottom:0 }
.vc-table small{ font-size:11.5px; color:var(--dim) }
.vc-td-c{ white-space:nowrap }
.vc-td-c span{ margin-inline-start:6px }
.vc-td-p b{ color:var(--cyan); font-weight:700 }
.vc-td-best{ font-size:12.5px; color:var(--muted) }
.vc-link{ color:var(--muted); text-decoration:underline; text-underline-offset:3px; white-space:nowrap }
.vc-link:hover{ color:var(--cyan) }
.vc-empty{ border:1px dashed var(--line-2); border-radius:20px; background:var(--surface); padding:34px 24px; text-align:center; max-width:600px }
.vc-empty p{ color:var(--muted); font-size:13.6px; line-height:2; margin-bottom:16px }

.vc-uses{ display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:14px }
.vc-use{ border:1px solid var(--line); border-radius:18px; background:var(--surface); padding:20px }
.vc-use .icon{ width:22px; height:22px; color:var(--cyan); margin-bottom:10px }
.vc-use h3{ font-size:14.5px; font-weight:700; margin-bottom:6px }
.vc-use p{ color:var(--muted); font-size:13px; line-height:1.9 }
.vc-use .vc-use-top{ margin-top:10px; color:var(--text) }
.vc-use-top a{ color:var(--cyan) }

.vc-check{ max-width:860px; display:flex; flex-direction:column; gap:10px; padding-inline-start:22px }
.vc-check li{ color:var(--muted); font-size:14px; line-height:2 }
.vc-ping{ margin-top:18px }

.vc-faq{ display:flex; flex-direction:column; gap:10px; max-width:860px }
.vc-faq details{ border:1px solid var(--line); border-radius:14px; background:var(--surface); padding:14px 18px }
.vc-faq summary{ font-size:14px; font-weight:600; list-style:none; cursor:pointer }
.vc-faq summary::-webkit-details-marker{ display:none }
.vc-faq details[open] summary{ color:var(--cyan) }
.vc-faq details div{ margin-top:10px; color:var(--muted); font-size:13.2px; line-height:2 }

.vc-cross-sec{ padding-top:14px }
.vc-cross-t{ font-size:15px; font-weight:700; margin-bottom:14px }
.vc-cross{ display:flex; flex-wrap:wrap; gap:9px }
.vc-cross a{ font-size:12.8px; color:var(--muted); border:1px solid var(--line); border-radius:30px; padding:7px 15px; transition:.16s }
.vc-cross a:hover{ border-color:var(--cyan); color:var(--cyan) }
.vc-result[hidden]{ display:none }
@media(max-width:640px){ .vc-sec{ padding:32px 0 } .vc-quiz{ padding:16px } }
</style>
@endsection
