{{--
  صفحهٔ اصلیِ فروشِ سرورِ مجازی (/cloud · /en/cloud · /tr/cloud).

  سه بخش دارد:
    ۱) نوارِ آمار + مزیت‌ها
    ۲) فهرستِ مکان‌ها گروه‌بندی‌شده بر قاره ← کشور ← شهر (هر شهر لینکِ صفحهٔ خودش)
    ۳) جدولِ همهٔ پلن‌ها با فیلترِ سمتِ کاربر (کشور/هسته/رم) و مرتب‌سازیِ قیمت

  تله‌هایی که این فایل رعایت می‌کند:
    · هیچ آرایهٔ JSON-LD این‌جا ساخته نمی‌شود — کنترلر می‌سازد و schema_ld
      چاپش می‌کند. کلیدِ context در Blade بلعیده می‌شود.
    · هیچ نامِ زیرساختی نه در متن، نه در کلاس، نه در دادهٔ ردیف‌ها.
    · جدولِ پهن داخلِ ظرفِ overflow-x می‌لغزد؛ روی موبایل صفحه افقی نمی‌شود.
    · هیچ منبعِ بیرونی (CDN/فونت/کتابخانه) — CSP بی‌صدا بلاکش می‌کند.
--}}
@extends('layouts.site')

@section('title', $t['cloud_meta_t'])
@section('description', $t['cloud_meta_d'])

@section('content')

<script type="application/ld+json">{!! schema_ld($ld['crumbs'], 'BreadcrumbList') !!}</script>
@if($rows)
<script type="application/ld+json">{!! schema_ld($ld['list'], 'ItemList') !!}</script>
@endif
@if($faq)
<script type="application/ld+json">{!! schema_ld($ld['faq'], 'FAQPage') !!}</script>
@endif

<section class="section cvps-top">
  <div class="container">

    <nav class="cvps-crumbs" aria-label="breadcrumb">
      <a href="{{ $homeUrl }}">{{ __('ui.brand') }}</a>
      <span aria-hidden="true">/</span>
      <span>{{ $t['cloud_badge'] }}</span>
    </nav>

    <div class="cvps-head">
      <span class="badge">{{ $t['cloud_badge'] }}</span>
      <h1>{{ $t['cloud_h1'] }}</h1>
      <p>{{ $t['cloud_lead'] }}</p>
    </div>

    @if($rows)
      <div class="cvps-stats">
        <div class="cvps-stat">
          <b>{{ fa_num(number_format($statLoc)) }}</b>
          <span>{{ $t['cloud_stat_loc'] }}</span>
        </div>
        <div class="cvps-stat">
          <b>{{ fa_num(number_format($statCountry)) }}</b>
          <span>{{ $t['cloud_stat_country'] }}</span>
        </div>
        <div class="cvps-stat">
          <b>{{ fa_num(number_format($statPlan)) }}</b>
          <span>{{ $t['cloud_stat_plan'] }}</span>
        </div>
        @if($fromLabel)
          <div class="cvps-stat cvps-stat-p">
            <b>{{ $fromLabel }}</b>
            <span>{{ __('ui.from') }} {{ __('ui.mo') }}</span>
          </div>
        @endif
      </div>

      <ul class="cvps-feats">
        <li>{{ $t['cloud_feat_1'] }}</li>
        <li>{{ $t['cloud_feat_2'] }}</li>
        <li>{{ $t['cloud_feat_3'] }}</li>
        <li>{{ $t['cloud_feat_4'] }}</li>
      </ul>
    @endif

  </div>
</section>

@if(! $rows)

  {{-- حالتِ «کاتالوگ هنوز سینک نشده»: صفحه باید آبرومند باشد، نه خطا. --}}
  <section class="section cvps-sec">
    <div class="container">
      <div class="cvps-empty">
        <h2>{{ $t['cloud_empty_t'] }}</h2>
        <p>{{ $t['cloud_empty_d'] }}</p>
        <div class="cvps-empty-acts">
          <a class="btn btn-primary" href="{{ lroute('contact') }}">{{ __('ui.nav_contact') }}</a>
          <a class="btn btn-glass" href="{{ lroute('solutions.index') }}">{{ __('ui.sol_h1') }}</a>
        </div>
      </div>
    </div>
  </section>

@else

  {{-- ═══════════ مکان‌ها: قاره ← کشور ← شهر ═══════════ --}}
  <section class="section cvps-sec" id="locations">
    <div class="container">
      <div class="cvps-sec-h">
        <h2>{{ $t['cloud_map_t'] }}</h2>
        <p>{{ $t['cloud_map_d'] }}</p>
      </div>

      @foreach($tree as $cont)
        <div class="cvps-cont">
          <div class="cvps-cont-h">
            <h3>{{ $cont['label'] }}</h3>
            <span class="cvps-cont-n">
              {{ strtr($t['cloud_n_cities'], [':n' => fa_num(number_format($cont['cities']))]) }}
              ·
              {{ strtr($t['cloud_n_plans'], [':n' => fa_num(number_format($cont['plans']))]) }}
              @if($cont['from'])
                · {{ __('ui.from') }} {{ $cont['from'] }}
              @endif
            </span>
          </div>

          {{-- 🔴 کارت = **کشور**، نه شهر.
               مشتری «سرور آلمان» می‌خواهد، نه «سرور فالکن‌اشتاین»؛ شهر را وقتی
               انتخاب می‌کند که پلن‌ها را کنار هم ببیند. پس کلِ کارت یک لینک به
               صفحهٔ کشور است و شهرها فقط برچسبِ اطلاعاتی‌اند.

               ⚠️ این تجمیع یک مشکلِ واقعیِ داده را هم می‌پوشاند: بعضی زیرساخت‌ها
               شهر نمی‌دهند و کدِ مکانشان از ردهٔ محصول ساخته شده
               (`fi-shared`، `fi-dedicated`). با کارتِ کشوری، هر سه زیرِ یک
               «فینلاند» جمع می‌شوند و مشتری سه مکانِ جعلی نمی‌بیند. --}}
          <div class="cvps-countries">
            @foreach($cont['countries'] as $country)
              <a class="cvps-country" href="{{ $country['url'] }}">
                <div class="cvps-country-h">
                  <span class="cvps-flag" aria-hidden="true">@include('partials.flag', ['flagSrc' => $country['flag_svg'] ?? null, 'flagEmoji' => $country['flag'], 'flagSize' => 18])</span>
                  <b>{{ $country['label'] }}</b>
                  <svg class="icon dir cvps-go" aria-hidden="true"><use href="#i-arrow"/></svg>
                </div>

                <div class="cvps-country-m">
                  <span>{{ strtr($t['cloud_n_plans'], [':n' => fa_num(number_format($country['plans']))]) }}</span>
                  @if($country['from'])
                    <span class="cvps-country-p">{{ __('ui.from') }} {{ $country['from'] }}</span>
                  @endif
                </div>

                @if(! empty($country['cities']))
                  <div class="cvps-city-tags">
                    @foreach($country['cities'] as $city)
                      <span class="cvps-city-tag">{{ $city }}</span>
                    @endforeach
                  </div>
                @endif
              </a>
            @endforeach
          </div>
        </div>
      @endforeach
    </div>
  </section>

  {{-- ═══════════ جدولِ پلن‌ها + فیلترِ سمتِ کاربر ═══════════ --}}
  <section class="section cvps-sec" id="plans">
    <div class="container">
      <div class="cvps-sec-h">
        <h2>{{ $t['cloud_table_t'] }}</h2>
        <p>{{ $t['cloud_table_d'] }}</p>
      </div>

      <div class="cvps-plans" id="clp">

        <div class="cvps-filters">
          <label class="cvps-fld">
            <span>{{ $t['cloud_f_country'] }}</span>
            <select id="clp-country">
              <option value="">{{ $t['cloud_f_all'] }}</option>
              @foreach($facets['countries'] as $c)
                <option value="{{ $c['code'] }}">{{ $c['flag'] }} {{ $c['label'] }} ({{ fa_num(number_format($c['n'])) }})</option>
              @endforeach
            </select>
          </label>

          <label class="cvps-fld">
            <span>{{ $t['cloud_f_cpu'] }}</span>
            <select id="clp-cpu">
              <option value="">{{ $t['cloud_f_all'] }}</option>
              @foreach($facets['vcpu'] as $v)
                <option value="{{ $v }}">{{ fa_num($v) }}</option>
              @endforeach
            </select>
          </label>

          <label class="cvps-fld">
            <span>{{ $t['cloud_f_ram'] }}</span>
            <select id="clp-ram">
              <option value="">{{ $t['cloud_f_all'] }}</option>
              @foreach($facets['ram'] as $r)
                <option value="{{ $r['mb'] }}">{{ fa_num($r['label']) }}</option>
              @endforeach
            </select>
          </label>

          <label class="cvps-fld">
            <span>{{ $t['cloud_f_sort'] }}</span>
            <select id="clp-sort">
              <option value="price-asc">{{ $t['cloud_sort_price_asc'] }}</option>
              <option value="price-desc">{{ $t['cloud_sort_price_desc'] }}</option>
              <option value="cpu">{{ $t['cloud_sort_cpu'] }}</option>
              <option value="ram">{{ $t['cloud_sort_ram'] }}</option>
            </select>
          </label>

          <span class="cvps-count" id="clp-count" data-tpl="{{ $t['cloud_shown_n'] }}">
            {{ strtr($t['cloud_shown_n'], [':n' => fa_num(number_format(count($rows)))]) }}
          </span>
        </div>

        {{-- ظرفِ لغزان: جدول ۸ ستون دارد و روی موبایل باید افقی بلغزد، نه اینکه
             کلِ صفحه را افقی کند. --}}
        <div class="cvps-tw">
          <table class="cvps-table">
            <thead>
              <tr>
                <th>{{ $t['cloud_th_plan'] }}</th>
                <th>{{ $t['cloud_th_cpu'] }}</th>
                <th>{{ $t['cloud_th_ram'] }}</th>
                <th>{{ $t['cloud_th_disk'] }}</th>
                <th>{{ __('ui.traffic') }}</th>
                <th>{{ $t['cloud_th_loc'] }}</th>
                <th>{{ $t['cloud_th_price'] }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="clp-body">
              @foreach($rows as $r)
                <tr data-row="1"
                    data-c="{{ $r['country'] }}"
                    data-v="{{ $r['vcpu'] }}"
                    data-r="{{ $r['ram_mb'] }}"
                    data-p="{{ $r['price_n'] }}">
                  <td class="cvps-td-n"><b>{{ $r['name'] }}</b></td>
                  {{-- fa_num فقط در فارسی اثر دارد: عددِ لاتینِ برچسب‌های مدل
                       («4 GB») باید با قیمتِ فارسیِ همان ردیف هم‌شکل باشد. --}}
                  <td>{{ fa_num($r['vcpu']) }} <span class="cvps-dim">{{ $r['cpu_kind'] }}</span></td>
                  <td>{{ fa_num($r['ram']) }}</td>
                  <td>{{ fa_num($r['disk']) }}</td>
                  <td>{{ fa_num($r['traffic']) }}</td>
                  <td class="cvps-td-l">
                    <a href="{{ $r['loc_url'] }}">@include('partials.flag', ['flagSrc' => $r['flag_svg'] ?? null, 'flagEmoji' => $r['flag'], 'flagSize' => 18]) {{ $r['loc'] }}</a>
                  </td>
                  <td class="cvps-td-p"><b>{{ $r['price'] }}</b><span class="cvps-dim">{{ __('ui.mo') }}</span></td>
                  <td class="cvps-td-b">
                    <a class="cvps-buy" href="{{ $r['buy_url'] }}" rel="nofollow">{{ $t['cloud_buy'] }}</a>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        <p class="cvps-none" id="clp-none">{{ $t['cloud_nomatch'] }}</p>
      </div>
    </div>
  </section>

@endif

{{-- ═══════════ پرسش‌های متداول ═══════════ --}}
@if($faq)
  <section class="section cvps-sec" id="faq">
    <div class="container">
      <div class="cvps-sec-h">
        <h2>{{ __('ui.faq_title') }}</h2>
      </div>
      <div class="cvps-faq">
        @foreach($faq as $i => $row)
          <details @if($i === 0) open @endif>
            <summary>{{ $row['q'] }}</summary>
            <div>{{ $row['a'] }}</div>
          </details>
        @endforeach
      </div>
    </div>
  </section>
@endif

{{-- ═══════════ لینک‌سازیِ داخلی ═══════════ --}}
<section class="section cvps-sec cvps-cross-sec">
  <div class="container">
    <h2 class="cvps-cross-t">{{ $t['cloud_cross_t'] }}</h2>
    <div class="cvps-cross">
      <a href="{{ lroute('solutions.index') }}">{{ __('ui.sol_h1') }}</a>
      <a href="{{ lroute('catalog', ['category' => 'cloud', 'slug' => 'iaas']) }}">{{ __('ui.f_p5') }}</a>
      <a href="{{ lroute('catalog', ['category' => 'vps', 'slug' => 'iran']) }}">{{ __('ui.hv_cross_iran') }}</a>
      <a href="{{ lroute('vps.hourly') }}">{{ __('ui.hv_badge') }}</a>
      <a href="{{ lroute('hosting', 'linux') }}">{{ __('ui.f_p1') }}</a>
      <a href="{{ lroute('domain.search') }}">{{ __('ui.nav_domains') }}</a>
      <a href="{{ lroute('knowledge') }}">{{ __('ui.nav_knowledge') }}</a>
      <a href="{{ lroute('contact') }}">{{ __('ui.nav_contact') }}</a>
    </div>
  </div>
</section>

{{-- راهنماهای بلاگ (پل محصول→بلاگ — ممیزی ۳) --}}
@include('partials.product-guides', ['guidesCat' => config('blog.product_guides.cloud')])

<style>
/* صفحهٔ سرورِ مجازی — استایلِ درجا، چون کلاسِ تازه در site.css مرزِ agentِ دیگری
   است و کلاسِ نبود، بی‌هیچ خطایی بی‌استایل رندر می‌شود. */
.cvps-top{ padding:120px 0 40px }
.cvps-sec{ padding:52px 0 }
.cvps-crumbs{ display:flex; align-items:center; gap:8px; font-size:12.5px; color:var(--dim); margin-bottom:18px; flex-wrap:wrap }
.cvps-crumbs a{ color:var(--muted) }
.cvps-crumbs a:hover{ color:var(--cyan) }

.cvps-head{ max-width:760px }
.cvps-head h1{ font-family:var(--font-disp); font-size:clamp(26px,4vw,40px); font-weight:700;
  letter-spacing:-.6px; line-height:1.25; margin:16px 0 12px; text-wrap:balance }
.cvps-head p{ color:var(--muted); font-size:15px; line-height:2 }

.cvps-stats{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-top:32px }
.cvps-stat{ border:1px solid var(--line); border-radius:16px; background:var(--surface); padding:16px 18px }
.cvps-stat b{ display:block; font-size:22px; font-weight:700; letter-spacing:-.4px }
.cvps-stat span{ display:block; font-size:12.5px; color:var(--dim); margin-top:4px }
.cvps-stat-p b{ background:var(--grad-txt); -webkit-background-clip:text; background-clip:text; color:transparent }

.cvps-feats{ display:flex; flex-wrap:wrap; gap:9px; margin-top:20px; list-style:none }
.cvps-feats li{ font-size:12.5px; color:var(--muted); border:1px solid var(--line);
  border-radius:30px; padding:6px 14px; background:var(--surface) }

.cvps-sec-h{ max-width:720px; margin-bottom:28px }
.cvps-sec-h h2{ font-family:var(--font-disp); font-size:clamp(21px,3vw,29px); font-weight:700;
  letter-spacing:-.5px; margin-bottom:10px }
.cvps-sec-h p{ color:var(--muted); font-size:13.6px; line-height:1.95 }

/* ── مکان‌ها ── */
.cvps-cont{ margin-bottom:34px }
.cvps-cont-h{ display:flex; align-items:baseline; justify-content:space-between; gap:14px;
  flex-wrap:wrap; padding-bottom:10px; margin-bottom:16px; border-bottom:1px solid var(--line) }
.cvps-cont-h h3{ font-size:17px; font-weight:700 }
.cvps-cont-n{ font-size:12.2px; color:var(--dim) }
.cvps-countries{ display:grid; grid-template-columns:repeat(auto-fill,minmax(272px,1fr)); gap:14px }
/* کارتِ کشور — کلِ کارت یک لینک است، پس هدفِ کلیک بزرگ و روی موبایل راحت است */
.cvps-country{ display:block; border:1px solid var(--line); border-radius:18px;
  background:var(--surface); padding:16px; color:inherit; text-decoration:none;
  transition:border-color .18s var(--ease), background .18s, transform .18s var(--ease) }
.cvps-country:hover{ border-color:var(--cyan); background:rgba(34,211,238,.06); transform:translateY(-2px) }
.cvps-country:focus-visible{ outline:2px solid var(--cyan); outline-offset:3px }
.cvps-country-h{ display:flex; align-items:center; gap:9px; margin-bottom:9px }
.cvps-country-h b{ font-size:14.5px; font-weight:700; flex:1; min-width:0 }
.cvps-flag{ font-size:19px; line-height:1 }
.cvps-go{ width:15px; height:15px; opacity:.4; flex:none }
.cvps-country:hover .cvps-go{ opacity:1; color:var(--cyan) }
.cvps-dim{ font-size:11.8px; color:var(--dim) }

.cvps-country-m{ display:flex; align-items:baseline; gap:10px; flex-wrap:wrap; font-size:11.8px; color:var(--dim) }
.cvps-country-p{ font-size:12.5px; font-weight:700; color:var(--cyan); margin-inline-start:auto; white-space:nowrap }

/* شهرها فقط برچسبِ اطلاعاتی‌اند — انتخابِ شهر داخلِ صفحهٔ کشور انجام می‌شود */
.cvps-city-tags{ display:flex; flex-wrap:wrap; gap:6px; margin-top:11px }
.cvps-city-tag{ font-size:11.2px; color:var(--dim); padding:3px 9px; border-radius:999px;
  background:var(--surface-2); white-space:nowrap }

/* ── فیلتر و جدول ── */
.cvps-filters{ display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px; margin-bottom:16px }
.cvps-fld{ display:flex; flex-direction:column; gap:5px }
.cvps-fld span{ font-size:11.8px; color:var(--dim) }
.cvps-fld select{ font-family:inherit; font-size:13px; color:var(--text); background:var(--bg2);
  border:1px solid var(--line-2); border-radius:11px; padding:9px 12px; min-width:132px }
.cvps-fld select:focus-visible{ outline:2px solid var(--cyan); outline-offset:2px }
.cvps-count{ font-size:12.5px; color:var(--dim); margin-inline-start:auto; padding-bottom:9px }

.cvps-tw{ overflow-x:auto; border:1px solid var(--line); border-radius:18px; background:var(--surface) }
.cvps-table{ width:100%; border-collapse:collapse; min-width:800px; font-size:13.2px }
.cvps-table th{ text-align:start; font-size:11.8px; font-weight:700; color:var(--dim);
  padding:12px 14px; border-bottom:1px solid var(--line); white-space:nowrap; background:var(--bg2) }
.cvps-table td{ padding:12px 14px; border-bottom:1px solid var(--line); white-space:nowrap; vertical-align:middle }
.cvps-table tbody tr:last-child td{ border-bottom:none }
.cvps-table tbody tr:hover{ background:var(--surface-2) }
.cvps-td-n b{ font-weight:700; letter-spacing:.2px }
.cvps-td-l a{ color:var(--muted) }
.cvps-td-l a:hover{ color:var(--cyan) }
.cvps-td-p b{ font-weight:700; color:var(--text) }
.cvps-td-p .cvps-dim{ margin-inline-start:4px }
.cvps-td-b{ text-align:end }
.cvps-buy{ display:inline-flex; align-items:center; font-size:12.5px; font-weight:700; color:#fff;
  background:var(--grad); border-radius:10px; padding:7px 16px; white-space:nowrap }
.cvps-buy:hover{ box-shadow:0 6px 20px rgba(34,211,238,.32) }
.cvps-none{ display:none; margin-top:14px; padding:16px; border:1px dashed var(--line-2);
  border-radius:14px; color:var(--muted); font-size:13px; text-align:center }

/* ── حالتِ خالی ── */
.cvps-empty{ border:1px solid var(--line); border-radius:22px; background:var(--surface);
  padding:40px 28px; text-align:center; max-width:640px; margin:0 auto }
.cvps-empty h2{ font-size:20px; font-weight:700; margin-bottom:12px }
.cvps-empty p{ color:var(--muted); font-size:13.6px; line-height:2 }
.cvps-empty-acts{ display:flex; flex-wrap:wrap; gap:10px; justify-content:center; margin-top:22px }

/* ── پرسش‌ها ── */
.cvps-faq{ display:flex; flex-direction:column; gap:10px; max-width:860px }
.cvps-faq details{ border:1px solid var(--line); border-radius:14px; background:var(--surface); padding:14px 18px }
.cvps-faq summary{ font-size:14px; font-weight:600; list-style:none }
.cvps-faq summary::-webkit-details-marker{ display:none }
.cvps-faq details[open] summary{ color:var(--cyan) }
.cvps-faq details div{ margin-top:10px; color:var(--muted); font-size:13.2px; line-height:2 }

/* ── لینک‌سازی ── */
.cvps-cross-sec{ padding-top:20px }
.cvps-cross-t{ font-size:15px; font-weight:700; margin-bottom:14px }
.cvps-cross{ display:flex; flex-wrap:wrap; gap:9px }
.cvps-cross a{ font-size:12.8px; color:var(--muted); border:1px solid var(--line);
  border-radius:30px; padding:7px 15px; transition:.16s }
.cvps-cross a:hover{ border-color:var(--cyan); color:var(--cyan) }

@media(max-width:640px){
  .cvps-top{ padding-top:96px }
  .cvps-sec{ padding:36px 0 }
  .cvps-count{ margin-inline-start:0 }
  .cvps-fld{ flex:1 1 140px }
  .cvps-fld select{ width:100% }
}
</style>

<script>
/* فیلتر و مرتب‌سازیِ جدولِ پلن — بی‌هیچ کتابخانه، همه سمتِ کاربر.
   نکته: هیچ درخواستی به سرور نمی‌رود، پس فیلتر روی موبایلِ کندِ ایران هم فوری است. */
(function(){
  var wrap = document.getElementById('clp');
  var body = document.getElementById('clp-body');
  if (!wrap || !body) { return; }

  var rows  = Array.prototype.slice.call(body.querySelectorAll('tr[data-row]'));
  var fc    = document.getElementById('clp-country');
  var fv    = document.getElementById('clp-cpu');
  var fr    = document.getElementById('clp-ram');
  var fs    = document.getElementById('clp-sort');
  var out   = document.getElementById('clp-count');
  var none  = document.getElementById('clp-none');
  var tpl   = out ? (out.getAttribute('data-tpl') || '') : '';
  var isFa  = document.documentElement.lang === 'fa';

  /* رقمِ فارسی: عددی که JS می‌سازد باید با بقیهٔ صفحه هم‌شکل باشد */
  function digits(s){
    if (!isFa) { return s; }
    return s.replace(/[0-9]/g, function(d){ return String.fromCharCode(1776 + Number(d)); });
  }
  function num(el, attr){ return parseInt(el.getAttribute(attr), 10) || 0; }
  function val(el){ return el ? el.value : ''; }

  function apply(){
    var c = val(fc), v = val(fv), r = val(fr), shown = 0;

    rows.forEach(function(row){
      var ok = (c === '' || row.getAttribute('data-c') === c)
            && (v === '' || row.getAttribute('data-v') === v)
            && (r === '' || row.getAttribute('data-r') === r);
      row.style.display = ok ? '' : 'none';
      if (ok) { shown = shown + 1; }
    });

    var mode = val(fs) || 'price-asc';
    var key = 'data-p', dir = 1;
    if (mode === 'price-desc') { dir = -1; }
    else if (mode === 'cpu')   { key = 'data-v'; dir = -1; }
    else if (mode === 'ram')   { key = 'data-r'; dir = -1; }

    rows.slice().sort(function(a, b){
      var d = (num(a, key) - num(b, key)) * dir;
      return d !== 0 ? d : (num(a, 'data-p') - num(b, 'data-p'));
    }).forEach(function(row){ body.appendChild(row); });

    if (out) { out.textContent = digits(tpl.replace(':n', String(shown))); }
    if (none) { none.style.display = shown === 0 ? 'block' : 'none'; }
  }

  [fc, fv, fr, fs].forEach(function(el){ if (el) { el.addEventListener('change', apply); } });
  apply();
})();
</script>
@endsection
