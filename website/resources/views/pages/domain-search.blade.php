@extends('layouts.site')
@section('title', __('ui.dsr_title'))
@section('description', __('ui.dsr_meta_desc'))


@section('content')

{{-- ============ رشته‌های موردنیازِ جاوااسکریپت (سه‌زبانه) ============ --}}
@php
$T = [
  'taken_note'         => __('ui.dsr_taken_note'),
  'taken_pill'         => __('ui.dsr_taken_pill'),
  'fx_unavailable'     => __('ui.dsr_fx_unavailable'),
  'no_price'           => __('ui.dsr_no_price'),
  /*
  | ⚠️ عمداً هنوز فرستاده می‌شود ولی دیگر **استفاده نمی‌شود**.
  |
  | «فعلاً قابل سفارش نیست» همان عبارتی بود که این صفحه به سه وضعیتِ کاملاً
  | متفاوت می‌داد (استعلام‌نشده، بی‌قیمت، پسوندی که نمی‌فروشیم) در حالی که پنل
  | فقط برای دومی به کارش می‌بُرد. حالا هر سه واژهٔ خودشان را دارند. کلید در
  | فایل‌های زبان می‌مانَد چون حذفش برابریِ سه فایل را می‌شکند و ارزشِ آن ریسک
  | را ندارد.
  */
  'not_orderable_pill' => __('ui.dsr_not_orderable_pill'),
  'premium_note'       => __('ui.dsr_premium_note'),
  'free_note'          => __('ui.dsr_free_note'),
  'premium_pill'       => __('ui.dsr_premium_pill'),
  'free_pill'          => __('ui.dsr_free_pill'),
  /*
  | واژگانِ سه حالتی که تا امروز روی صفحهٔ عمومی وجود نداشتند و هر سه زیرِ یک
  | قرصِ «فعلاً قابل سفارش نیست» قایم می‌شدند — قرصی که پنل برای حالتِ
  | **دیگری** به کار می‌بَرد. حالا هر حالت واژهٔ خودش را دارد و همان واژه در
  | پنل هم دیده می‌شود.
  */
  'unchecked_pill'     => __('ui.dsr_unchecked_pill'),
  'unchecked_note'     => __('ui.dsr_unchecked_note'),
  'unsupported_pill'   => __('ui.dsr_unsupported_pill'),
  'unsupported_note'   => __('ui.dsr_unsupported_note'),
  'no_price_pill'      => __('ui.dsr_no_price_pill'),
  'lookup_failed'      => __('ui.dsr_lookup_failed'),
  'price_unit'         => __('ui.dsr_price_unit'),
  'register_btn'       => __('ui.dsr_register_btn'),
  'err_empty'          => __('ui.dsr_err_empty'),
  'err_conn'           => __('ui.dsr_err_conn'),
  // ⚠️ جای‌نگهدار `__N__` است نه `:n` — جایگزینی سمتِ جاوااسکریپت انجام
  // می‌شود و آن‌جا رقمِ فارسی هم باید بنشیند.
  'count_tpl'          => __('ui.dsr_count', ['n' => '__N__']),
  'is_fa'              => app()->getLocale() === 'fa',
  'eur_rate'           => cloud_eur_rate(),
  // فروش و تحویلِ دامنه در کنسولِ خودمان است، نه WHMCSِ بیرونی
  'panel'              => lroute('account.domains'),

  /*
  | دسته‌بندیِ پسوندها برای بارگذاریِ تدریجی.
  |
  | ⚠️ اندازهٔ دسته **۱۰** است چون اعتبارسنجیِ روت سقفِ ۱۲ دارد؛ بزرگ‌ترش
  | یعنی هر درخواست با خطای اعتبارسنجی برمی‌گردد و کاربر هیچ نتیجه‌ای
  | نمی‌بیند — خرابی‌ای که فقط در مرورگر دیده می‌شود، نه در تست‌های سرور.
  */
  'tld_first'          => \App\Services\Domain\DomainSearch::firstBatch(),
  'tld_rest'           => \App\Services\Domain\DomainSearch::restBatches(),
  'tld_unsold'         => \App\Services\Domain\DomainSearch::UNSOLD_TLDS,
  'discovery' => __('domain-discovery'),
  'locale' => app()->getLocale(),
  'all_hidden' => __('ui.dsr_f_all_hidden'),
];
@endphp
<script>window.T = @json($T);</script>

{{--
  ═══════════════ صفحهٔ ثبتِ دامنه ═══════════════

  چیدمان عمداً **وسط‌چین** است و نه ستونیِ معمولِ سایت: این صفحه یک کار دارد و
  فقط یک کار — نوشتنِ یک نام. هر چیزی که چشم را از کادرِ جستجو دور کند، همان
  کار را سخت‌تر می‌کند.

  ⚠️ RTL/LTR: کلِ چیدمان با ویژگی‌های منطقی (`inline-start`) نوشته شده، ولی
  خودِ نامِ دامنه و قیمت همیشه `dir="ltr"`اند — «example.com» در متنِ راست‌به‌چپ
  بدونِ این، وارونه دیده می‌شود.
--}}
<section class="dsx">
  <div class="dsx-glow" aria-hidden="true"></div>

  <div class="container dsx-wrap">

    <header class="dsx-head">
      <span class="dsx-badge"><span class="dsx-live-dot" aria-hidden="true"></span>{{ __('domain-discovery.eyebrow') }}</span>
      <h1>{{ __('domain-discovery.headline_start') }} <span>{{ __('domain-discovery.headline_accent') }}</span></h1>
      <p>{{ __('domain-discovery.lead') }}</p>
      <div class="dsx-orbit" aria-hidden="true"><span>.com</span><b>your next idea</b><span>.net</span><span>.dev</span></div>
    </header>

    <div class="dsx-box">
      <form class="dsx-in" id="dm-form" action="{{ lroute('domain.search') }}" method="get">
        <svg class="icon dsx-in-ic" aria-hidden="true"><use href="#i-search"/></svg>
        <label class="dsx-sr-only" for="dm-q">{{ __('domain-discovery.search_label') }}</label>
        <input type="text" id="dm-q" name="q" dir="ltr" autocomplete="off" autocapitalize="off" spellcheck="false"
               aria-describedby="dm-search-hint" maxlength="120" required
               placeholder="{{ __('ui.dsr_input_ph') }}">
        <button type="submit" class="dsx-go" id="dm-go">
          <span class="dm-go-t">{{ __('ui.dsr_search_btn') }}</span>
          <span class="dm-spin" hidden aria-label="{{ __('domain-discovery.searching') }}"></span>
          <svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg>
        </button>
      </form>
      <p class="dsx-hint" id="dm-search-hint">{{ __('domain-discovery.search_hint') }}</p>
      <div class="dsx-seeds"><span>{{ __('domain-discovery.start_hint') }}</span>
        @foreach(['roshana', 'orbitstudio', 'nexora'] as $example)
          <button type="button" data-domain-query="{{ $example }}" title="{{ __('domain-discovery.example_hint') }}" dir="ltr">{{ $example }}<svg class="icon" aria-hidden="true"><use href="#i-search"/></svg></button>
        @endforeach
      </div>
      <div class="dsx-promises"><span><i aria-hidden="true"></i>{{ __('domain-discovery.live_check') }}</span><span><i aria-hidden="true"></i>{{ __('domain-discovery.clear_price') }}</span><span><i aria-hidden="true"></i>{{ __('domain-discovery.more_choices') }}</span></div>
    </div>

    <section class="dsx-ideas" id="dm-ideas" hidden aria-labelledby="dm-ideas-title">
      <div><h2 id="dm-ideas-title">{{ __('domain-discovery.suggestions_title') }}</h2><p>{{ __('domain-discovery.suggestions_hint') }}</p></div>
      <div id="dm-idea-chips" class="dsx-idea-chips"></div>
    </section>

    <div class="dsx-overview" id="dm-overview" hidden>
      <div><span>{{ __('domain-discovery.results_title') }}</span><h2 id="dm-search-term" dir="ltr"></h2></div>
      <div class="dsx-result-stats"><span id="dm-available-count"></span><span id="dm-deals-count"></span></div>
    </div>

    <div id="dm-error" class="dsx-err" hidden role="alert"></div>

    {{--
      🔴 کانالِ خرابیِ صفحهٔ عمومی — تا امروز اصلاً وجود نداشت.

      نقطهٔ پایانی بی‌قیدوشرط `ok: true` می‌داد، پس در یک قطعیِ کاملِ رجیسترار
      همهٔ ردیف‌ها `unknown` می‌شدند، فیلترِ پیش‌فرضِ «بدون قیمت‌ها را نشان نده»
      همه را پنهان می‌کرد، و تنها جمله‌ای که مشتری می‌دید این بود:
      «با این فیلترها چیزی نمانده. یکی از تیک‌ها را بردارید.» — یعنی خرابیِ ما
      به‌عنوانِ اشتباهِ خودِ او گزارش می‌شد.

      ⚠️ این بنر `role="status"` است نه `alert`: خبرِ وضعیت است، و صفحه هنوز
      ردیف‌ها را نشان می‌دهد (که حالا «استعلام نشد»اند، نه «ثبت‌شده»).
    --}}
    <div id="dm-warn" class="dsx-err dsx-warn" hidden role="status"></div>

    {{-- ============ فیلترها ============
         روی نتیجه‌اند نه روی درخواست: همه‌چیز از قبل در صفحه است و فیلتر فقط
         نمایش را عوض می‌کند، پس بی‌درنگ است و هیچ تماسِ تازه‌ای نمی‌سازد.
         شمارندهٔ کنارِ هر گزینه هم می‌گوید فیلتر چه چیزی را پنهان می‌کند. --}}
    <div class="dsx-filters" id="dm-filters" hidden>
      <label class="dsx-chk">
        <input type="checkbox" id="f-taken" checked>
        <span>{{ __('ui.dsr_f_hide_taken') }}</span>
        {{-- fa_num: رقمِ فارسیِ ثابت روی /en و /tr نشت می‌کرد (بررسی زبان) --}}
        <i class="dsx-n" id="n-taken">{{ fa_num(0) }}</i>
      </label>
      <label class="dsx-chk">
        <input type="checkbox" id="f-premium">
        <span>{{ __('ui.dsr_f_hide_premium') }}</span>
        <i class="dsx-n" id="n-premium">{{ fa_num(0) }}</i>
      </label>
      <label class="dsx-chk">
        <input type="checkbox" id="f-unavail" checked>
        <span>{{ __('ui.dsr_f_hide_unorderable') }}</span>
        <i class="dsx-n" id="n-unavail">{{ fa_num(0) }}</i>
      </label>

      <label class="dsx-chk dsx-deal-filter">
        <input type="checkbox" id="f-deals">
        <span>{{ __('domain-discovery.deals_only') }}</span>
        <i class="dsx-n" id="n-deals">{{ fa_num(0) }}</i>
      </label>

      <span class="dsx-sep" aria-hidden="true"></span>

      <label class="dsx-sort">
        <span>{{ __('ui.pt_f_sort') }}</span>
        <select id="f-sort">
          <option value="best">{{ __('ui.dsr_sort_best') }}</option>
          <option value="price">{{ __('ui.pt_sort_cheap') }}</option>
          <option value="-price">{{ __('ui.pt_sort_dear') }}</option>
          <option value="tld">{{ __('ui.dsr_sort_tld') }}</option>
        </select>
      </label>

      <span class="dsx-count" id="dm-count" aria-live="polite"></span>
    </div>

    {{-- ============ نتایج ============ --}}
    <div class="dsx-table" id="dm-table" hidden>
      <div class="dsx-tr dsx-th" aria-hidden="true">
        <span>{{ __('ui.pt_plan') }}</span>
        <span>{{ __('ui.dsr_th_state') }}</span>
        <span>{{ __('ui.pt_price') }}</span>
        <span></span>
      </div>
      <div id="dm-results" role="list"></div>
    </div>

    {{-- «هنوز دارد می‌آید» — بدونِ آن، کاربر فهرستِ نیمه‌کامل را کامل فرض
         می‌کند و فکر می‌کند پسوندِ موردِ نظرش را نداریم. --}}
    <p id="dm-more" class="dsx-more" hidden aria-live="polite">
      <span class="dsx-dots"><i></i><i></i><i></i></span>{{ __('ui.dsr_more') }}
    </p>

    <p id="dm-empty" class="dsx-empty" hidden>{{ __('ui.dsr_f_all_hidden') }}</p>
    <div class="dsx-load" id="dm-load-wrap" hidden>
      <button type="button" class="dsx-load-btn" id="dm-load-more">{{ __('domain-discovery.load_more') }}<span aria-hidden="true">+</span></button>
      <p id="dm-load-note">{{ __('domain-discovery.more_hint') }}</p>
    </div>
    <p class="dsx-pricing-note" id="dm-pricing-note" hidden>{{ __('domain-discovery.pricing_note') }}</p>


    {{--
      حالت اولیه — «هنوز چیزی جستجو نکرده‌اید».

      🔴 تا امروز جاوااسکریپت **اصلاً به این عنصر اشاره نمی‌کرد**: نه در
      `run()` پنهان می‌شد نه جایی دیگر. پس زیرِ ۶۴ نتیجهٔ واقعی هم چاپ
      می‌شد و به کاربری که همین حالا نتیجه گرفته بود می‌گفت «دامنه‌ای
      جستجو نکرده‌اید».

      ⚠️ `pnl-empty` پدینگ و `text-align` دارد ولی `display:` ندارد، پس
      `hidden` روی خودش کار می‌کند و به قاعدهٔ `[hidden]` نیازی نیست —
      برخلافِ `.dsx-more` بالاتر. تفاوتشان همان تلهٔ ثبت‌شدهٔ پروژه است.
    --}}
    <div id="dm-idle" class="dsx-idle">
      <div class="dsx-idle-mark" aria-hidden="true"><svg class="icon"><use href="#i-globe"/></svg><span>↗</span></div>
      <b>{{ __('domain-discovery.ready_title') }}</b>
      <p>{{ __('domain-discovery.ready_text') }}</p>
    </div>

    {{--
      لینک‌های زیرِ کادرِ جستجو — تا امروز این صفحه **هیچ لینکِ خروجی نداشت**.

      کسی که نامش را پیدا نکرد یا اصلاً جستجو نکرد، به بن‌بست می‌خورد: نه
      فهرستِ پسوندها، نه ‎.ir، نه پرمیوم، نه نمایندگی. همان لینک‌هایی که در
      مگامنو هستند این‌جا هم می‌آیند — و از **همان** پیکربندی خوانده می‌شوند
      تا دو فهرستِ موازی نشود که روزی یکی‌شان کهنه می‌شود.

      ⚠️ lroute و نه route: این صفحه در هر سه زبان وجود دارد.
    --}}
    @php
      $domainMenu = [];
      foreach ((array) data_get(config('servernet.mega.domain'), 'groups', []) as $g) {
          foreach ((array) ($g['items'] ?? []) as $it) {
              if (isset($it['slug'])) {
                  $domainMenu[] = [
                      'href'  => lroute('catalog', ['category' => 'domain', 'slug' => $it['slug']]),
                      'label' => lc($it),
                  ];
              }
          }
      }
    @endphp
    @if($domainMenu !== [])
      <nav class="dsx-links" aria-label="{{ __('ui.dsr_explore') }}">
        <h2>{{ __('ui.dsr_explore') }}</h2>
        <div class="dsx-links-in">
          @foreach($domainMenu as $l)
            <a href="{{ $l['href'] }}">{{ $l['label'] }}</a>
          @endforeach
        </div>
      </nav>
    @endif

  </div>
</section>

<style>
.dsx{position:relative;overflow:hidden}
.dsx-wrap{position:relative;z-index:2}
.dsx-glow{position:absolute;inset-inline-start:50%;transform:translateX(-50%);pointer-events:none;z-index:1;background:radial-gradient(closest-side,rgba(34,211,238,.16),transparent 70%)}
.dsx-head{text-align:center}
.dsx-badge{display:inline-flex;align-items:center;gap:10px;font-weight:600}
.dsx-head p{margin:0 auto;color:var(--muted)}
.dsx-box{margin-inline:auto}
.dsx-in{display:flex;align-items:center;gap:10px;border:1px solid var(--line-2);transition:border-color .2s,box-shadow .2s}
.dsx-in:focus-within{border-color:var(--cyan)}
.dsx-in-ic{width:19px;height:19px;color:var(--dim);flex:none;margin-inline-start:10px}
.dsx-in input{flex:1;min-width:0;border:0;background:none;outline:none;color:var(--text);font-family:ui-monospace,Menlo,Consolas,monospace}
.dsx-in input::placeholder{color:var(--dim);font-family:inherit}
.dsx-go{display:inline-flex;align-items:center;gap:8px;border:0;font-family:inherit;font-size:15px;font-weight:700;cursor:pointer;color:#04141c;background:linear-gradient(135deg,#67e8f9,#22d3ee);transition:transform .18s,background .18s}
.dsx-go:hover{transform:translateY(-1px)}
.dsx-go:disabled{opacity:.6;cursor:wait;transform:none}
.dsx-hint{text-align:center;color:var(--dim);line-height:1.9}
.dsx-err{max-width:760px;margin:0 auto 18px;padding:13px 16px;border-radius:12px;text-align:center;font-size:12px;line-height:1.9;color:#fca5a5;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.28)}
.dsx-warn{color:#fcd34d;background:rgba(251,191,36,.08);border-color:rgba(251,191,36,.28);text-align:start}
.dsx-filters{display:flex;align-items:center;flex-wrap:wrap;margin:0 auto 16px;border:1px solid var(--line)}
.dsx-chk{display:inline-flex;align-items:center;cursor:pointer;user-select:none;border:1px solid transparent;transition:border-color .16s,background .16s}
.dsx-chk:hover{background:rgba(255,255,255,.04)}
.dsx-chk.on{border-color:var(--info-line);background:var(--info-bg);color:var(--cyan)}
.dsx-chk input{accent-color:#22d3ee;cursor:pointer;flex:none}
.dsx-n{font-style:normal;padding-block:1px;border-radius:999px;background:rgba(255,255,255,.06);color:var(--dim)}
.dsx-chk.on .dsx-n{background:rgba(34,211,238,.13);color:var(--cyan)}
.dsx-sort{display:inline-flex;align-items:center;gap:8px;color:var(--muted)}
.dsx-sort select{border:1px solid var(--line);border-radius:8px;font-family:inherit;color:var(--text);cursor:pointer}
.dsx-count{color:var(--dim)}
.dsx-table{margin-inline:auto;border:1px solid var(--line)}
.dsx-tr{display:grid;align-items:center;border-bottom:1px solid var(--line)}
.dsx-tr:last-child{border-bottom:0}
.dsx-th{font-weight:700;color:var(--dim)}
.dsx-row{transition:background .16s}
.dsx-row:hover{background:rgba(255,255,255,.035)}
.dsx-row.is-hidden{display:none}
.dsx-name small{display:block;font-weight:400}
.dsx-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;white-space:nowrap}
.dsx-pill::before{content:'';border-radius:50%;background:currentColor}
.dsx-pill.free{color:#34d399;background:rgba(52,211,153,.1)}
.dsx-pill.prem{color:#fbbf24;background:rgba(251,191,36,.1)}
.dsx-pill.taken{color:var(--dim);background:rgba(255,255,255,.05)}
.dsx-pill.no{color:#fca5a5;background:rgba(239,68,68,.08)}
.dsx-pill.warn{color:#67e8f9;background:rgba(34,211,238,.12)}
.dsx-price small{display:block;font-weight:400}
.dsx-buy{display:inline-flex;align-items:center;justify-content:center;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .16s}
.dsx-more{display:flex;align-items:center;justify-content:center;gap:9px;margin:18px 0 0;font-size:12px;color:var(--muted)}
.dsx-more[hidden],.dsx-dots[hidden]{display:none}
.dsx-dots{display:inline-flex;gap:4px}
.dsx-dots i{width:5px;height:5px;border-radius:50%;background:var(--cyan);animation:dsx-pulse 1.1s ease-in-out infinite}
.dsx-dots i:nth-child(2){animation-delay:.16s}.dsx-dots i:nth-child(3){animation-delay:.32s}
@keyframes dsx-pulse{0%,100%{opacity:.22;transform:scale(.8)}50%{opacity:1;transform:scale(1)}}
.dsx-empty{margin:20px auto 0;max-width:65ch;text-align:center;font-size:12px;line-height:1.9;color:var(--muted)}
.dsx-links{text-align:center}
.dsx-links h2{color:var(--muted);margin-bottom:12px}
.dsx-links-in{display:flex;flex-wrap:wrap;justify-content:center;gap:9px}
.dsx-links-in a{display:inline-flex;align-items:center;text-decoration:none;color:var(--text);border:1px solid var(--line);transition:border-color .16s,color .16s}
.dsx-links-in a:hover{border-color:var(--cyan);color:var(--cyan)}
html[data-theme="light"] .dsx-row:hover{background:rgba(0,0,0,.025)}
/* Scoped discovery UI. No fonts, image downloads or animation library. */
.dsx{padding:48px 0 80px;background:radial-gradient(ellipse at 50% 0,rgba(34,211,238,.055),transparent 52%)}
.dsx [hidden]{display:none!important}
.dsx-wrap{max-width:1060px}
.dsx-glow{inset-inline-start:unset;left:50%;right:auto;filter:none;opacity:.45;height:480px;width:900px;top:-180px}
.dsx-head{max-width:800px;margin:0 auto 28px;position:relative}
.dsx-badge{letter-spacing:.06em;font-size:11px;border:0;background:transparent;padding:0;color:var(--muted)}
.dsx-live-dot{width:6px;height:6px;border-radius:50%;background:#34d399;box-shadow:0 0 0 5px rgba(52,211,153,.08)}
.dsx-head h1{font-family:var(--font-disp,inherit);font-size:clamp(32px,5.3vw,58px);font-weight:850;line-height:1.45;letter-spacing:-1.2px;margin:18px auto 10px;max-width:18ch}
.dsx-head h1 span{color:var(--cyan);display:block}
.dsx-head p{font-size:15px;line-height:1.95;max-width:58ch}
.dsx-orbit{display:flex;align-items:center;justify-content:center;gap:10px;direction:ltr;margin:23px auto 0;max-width:490px;position:relative;isolation:isolate}
.dsx-orbit::before{content:'';position:absolute;inset-inline:0;top:50%;height:1px;background:linear-gradient(90deg,transparent,var(--line-2),transparent);z-index:-1}
.dsx-orbit span,.dsx-orbit b{font:600 12px ui-monospace,Menlo,monospace;letter-spacing:.03em;border:1px solid var(--line);border-radius:10px;padding:8px 12px;background:var(--bg2)}
.dsx-orbit b{color:var(--muted);font-weight:400;font-size:11px;padding-inline:16px}
.dsx-orbit span:nth-child(1){transform:rotate(-6deg);color:var(--cyan)}
.dsx-orbit span:nth-child(3){transform:rotate(5deg);color:#a78bfa}
.dsx-orbit span:nth-child(4){transform:rotate(-4deg);color:#34d399}
.dsx-box{max-width:760px;margin-bottom:34px}
.dsx-in{border-radius:18px;padding:8px;background:var(--surface);box-shadow:0 18px 50px rgba(0,0,0,.13);backdrop-filter:none;-webkit-backdrop-filter:none;border-color:var(--line-2)}
.dsx-in:focus-within{box-shadow:0 0 0 4px rgba(34,211,238,.1),0 18px 50px rgba(0,0,0,.13)}
.dsx-in input{font-size:18px;padding:16px 0;letter-spacing:.02em}
.dsx-go{min-height:52px;padding-inline:25px;border-radius:12px;box-shadow:none;white-space:nowrap}
.dsx-go .icon{width:17px;height:17px}
.dsx-hint{font-size:12px;margin-top:12px}
.dsx-seeds{display:flex;gap:8px;align-items:center;justify-content:center;flex-wrap:wrap;margin-top:16px}
.dsx-seeds>span{font-size:11px;color:var(--dim);margin-inline-end:3px}
.dsx-seeds button,.dsx-idea-chips button{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:8px;cursor:pointer;padding:7px 11px;font:500 12px ui-monospace,Menlo,monospace;min-height:34px;transition:border-color .16s,background .16s}
.dsx-seeds button .icon{width:12px;height:12px;color:var(--dim)}
.dsx-seeds button:hover,.dsx-idea-chips button:hover{border-color:var(--cyan);background:var(--info-bg)}
.dsx-promises{display:flex;align-items:center;justify-content:center;gap:24px;flex-wrap:wrap;margin-top:20px;font-size:11px;color:var(--muted)}
.dsx-promises span{display:flex;align-items:center;gap:7px}
.dsx-promises i{width:4px;height:4px;border-radius:50%;background:var(--cyan)}
.dsx-ideas{max-width:960px;margin:0 auto 24px;border:1px solid var(--line);background:var(--surface);border-radius:16px;padding:18px 20px;display:flex;gap:20px;align-items:center;justify-content:space-between}
.dsx-ideas h2{font-size:13px;margin:0 0 5px;font-weight:750}
.dsx-ideas p{font-size:11px;line-height:1.85;color:var(--muted);margin:0;max-width:40ch}
.dsx-idea-chips{display:flex;flex-wrap:wrap;gap:7px;max-width:530px;justify-content:flex-end}
.dsx-idea-chips button{color:var(--cyan);font-size:12px;min-height:36px;border-color:var(--info-line);background:var(--info-bg);max-width:100%;overflow-wrap:anywhere}
.dsx-overview{display:flex;align-items:center;justify-content:space-between;gap:16px;max-width:960px;margin:32px auto 16px}
.dsx-overview>div>span{font-size:11px;color:var(--dim)}
.dsx-overview h2{font:750 clamp(19px,2.5vw,25px) ui-monospace,Menlo,monospace;margin:6px 0 0;overflow-wrap:anywhere;text-align:start}
.dsx-result-stats{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:11px;color:var(--muted)}
.dsx-result-stats span+span{padding-inline-start:12px;border-inline-start:1px solid var(--line)}
.dsx-filters{max-width:960px;justify-content:flex-start;border-radius:12px;padding:10px 12px;gap:7px;background:var(--surface);backdrop-filter:none;-webkit-backdrop-filter:none}
.dsx-chk{font-size:11px;gap:6px;padding:7px 9px;border-radius:8px;background:transparent}
.dsx-chk input{width:14px;height:14px}
.dsx-deal-filter{color:#d9a951}
.dsx-deal-filter.on{color:#fbbf24;background:rgba(251,191,36,.07);border-color:rgba(251,191,36,.25)}
.dsx-n{font-size:10px;padding-inline:5px}
.dsx-sort{font-size:11px;margin-inline-start:auto}
.dsx-sort select{font-size:11px;background:var(--bg2);max-width:150px;min-height:34px}
.dsx-count{width:auto;flex-basis:100%;text-align:start;font-size:11px;border-top:1px solid var(--line);padding:9px 4px 0;margin-top:2px}
.dsx-sep{display:none}
.dsx-table{max-width:960px;background:var(--surface);border-radius:18px;box-shadow:none;backdrop-filter:none;-webkit-backdrop-filter:none;overflow:clip}
.dsx-tr{grid-template-columns:minmax(210px,1.4fr) 105px minmax(180px,1fr) 130px;gap:18px;padding:19px 23px}
.dsx-th{font-size:10px;padding-block:13px;background:var(--bg2);border-bottom-color:var(--line)}
.dsx-row{min-height:104px;animation:dsx-card-in .26s ease both}
.dsx-row.dsx-primary{background:rgba(34,211,238,.045);border-inline-start:3px solid var(--cyan);padding-inline-start:20px;border-bottom-color:var(--line)}
.dsx-row.dsx-offer-row:not(.dsx-primary){background:rgba(251,191,36,.018)}
.dsx-name{font-weight:400;min-width:0}
.dsx-name>b{display:block;font:750 16px ui-monospace,Menlo,monospace;letter-spacing:-.3px;text-align:start;overflow-wrap:anywhere;word-break:break-word}
.dsx-name small{font-family:inherit;font-size:10px;line-height:1.8;word-break:normal;color:var(--dim);margin-top:5px}
.dsx-name-top{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}
.dsx-name-top:empty{display:none}
.dsx-choice,.dsx-offer-badge{font:600 9px/1.4 var(--font-body,system-ui);border-radius:5px;padding:3px 6px;color:var(--cyan);background:rgba(34,211,238,.09)}
.dsx-offer-badge{color:#fbbf24;background:rgba(251,191,36,.1)}
.dsx-pill{font-size:10px;padding:5px 9px;font-weight:600}
.dsx-pill::before{width:4px;height:4px}
.dsx-price{white-space:normal;line-height:1.35;text-align:start;font-weight:400}
.dsx-price strong{display:block;font-size:18px;font-weight:800;letter-spacing:-.3px;white-space:nowrap}
.dsx-price del{display:block;color:var(--dim);font-size:11px;font-weight:500;white-space:nowrap;line-height:1.5;text-decoration-thickness:1px;text-decoration-color:var(--muted)}
.dsx-price small{font-size:10px;margin-top:4px;line-height:1.6;color:var(--muted)}
.dsx-price .dsx-expiry{display:block;font-size:9px;color:#d9a951;margin-top:5px;line-height:1.5}
.dsx-buy{min-height:41px;font-size:11px;gap:16px;padding:10px 14px;border-radius:9px;background:var(--info-bg);border:1px solid var(--info-line);color:var(--cyan)}
.dsx-buy span{font-size:16px}
.dsx-primary .dsx-buy{background:var(--cyan);color:#04141c;border-color:var(--cyan)}
.dsx-buy:hover{box-shadow:none;background:rgba(34,211,238,.18)}
.dsx-primary .dsx-buy:hover{background:#67e8f9}
.dsx-load{max-width:960px;margin:22px auto 0;text-align:center}
.dsx-load-btn{display:inline-flex;align-items:center;gap:18px;min-height:44px;padding:11px 20px;font-family:inherit;font-size:12px;font-weight:600;color:var(--text);border:1px solid var(--line-2);border-radius:10px;background:var(--surface);cursor:pointer;transition:border-color .16s}
.dsx-load-btn span{font-size:18px;line-height:1;color:var(--cyan)}
.dsx-load-btn:hover{border-color:var(--cyan)}
.dsx-load-btn:disabled{opacity:.55;cursor:wait}
.dsx-load p{font-size:11px;color:var(--dim);margin:10px 0 0;line-height:1.8}
.dsx-pricing-note{font-size:10px;line-height:1.9;color:var(--dim);text-align:center;margin:24px auto 0;max-width:68ch}
.dsx-idle{max-width:760px;margin:42px auto 0;text-align:center;border:1px dashed var(--line-2);border-radius:18px;padding:34px 24px;background:var(--surface)}
.dsx-idle-mark{position:relative;width:52px;height:52px;display:grid;place-items:center;margin:0 auto 16px;border:1px solid var(--line-2);border-radius:16px;background:var(--bg2)}
.dsx-idle-mark .icon{width:25px;height:25px;color:var(--cyan)}
.dsx-idle-mark span{position:absolute;bottom:-5px;right:-7px;display:grid;place-items:center;width:23px;height:23px;border:3px solid var(--bg2);border-radius:50%;background:var(--cyan);color:#04141c;font-size:12px;font-weight:800}
.dsx-idle>b{font-size:14px;font-weight:700}
.dsx-idle>p{font-size:12px;line-height:1.95;color:var(--muted);max-width:52ch;margin:8px auto 0}
.dsx-links{margin-top:38px}
.dsx-links h2{font-size:11px;font-weight:600}
.dsx-links-in a{font-size:11px;padding:8px 12px;border-radius:8px;background:transparent}
.dsx-overview,.dsx-ideas{scroll-margin-top:120px}
.dsx-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.dsx button:focus-visible,.dsx a:focus-visible,.dsx input:focus-visible,.dsx select:focus-visible{outline:2px solid var(--cyan);outline-offset:4px}
.dm-spin{width:18px;height:18px;border:2px solid rgba(4,20,28,.2);border-top-color:#04141c;border-radius:50%;animation:dsx-spin .7s linear infinite}
@keyframes dsx-spin{to{transform:rotate(360deg)}}
@keyframes dsx-card-in{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:translateY(0)}}
html[data-theme="light"] .dsx-in,html[data-theme="light"] .dsx-table,html[data-theme="light"] .dsx-filters{background:var(--surface)}
html[data-theme="light"] .dsx-offer-badge,html[data-theme="light"] .dsx-price .dsx-expiry,html[data-theme="light"] .dsx-deal-filter{color:#956112}
@media(min-width:721px) and (max-width:980px){.dsx-tr{grid-template-columns:minmax(160px,1.3fr) 90px minmax(135px,1fr) 112px;gap:12px;padding-inline:17px}.dsx-row.dsx-primary{padding-inline-start:14px}.dsx-ideas{align-items:flex-start}.dsx-idea-chips{max-width:50%}}
@media(max-width:720px){
 .dsx{padding:28px 0 60px}.dsx-head{margin-bottom:24px}.dsx-head h1{font-size:clamp(30px,7.5vw,44px);letter-spacing:-.6px;max-width:19ch}.dsx-head p{font-size:13px;max-width:40ch}.dsx-badge{font-size:9px}
 .dsx-orbit{gap:7px;margin-top:18px}.dsx-orbit span{font-size:10px;padding:6px 9px}.dsx-orbit b{font-size:9px;padding:6px 9px}
 .dsx-in{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:7px;padding:7px;border-radius:14px}.dsx-in input{width:auto;order:0;font-size:16px;padding:12px 7px;min-height:48px}.dsx-go{order:0;width:auto;padding-inline:15px;min-height:48px;font-size:12px;border-radius:9px}.dsx-go>.icon{display:none}
 .dsx-hint{font-size:10px;text-align:start;padding-inline:4px;line-height:1.9}.dsx-seeds{gap:6px;margin-top:13px}.dsx-seeds>span{flex-basis:100%;font-size:10px;text-align:center;margin-bottom:2px}.dsx-seeds button{padding:6px 9px;font-size:11px;min-height:32px}.dsx-promises{gap:15px;font-size:9px;margin-top:15px}.dsx-box{margin-bottom:25px}
 .dsx-ideas{padding:15px;display:block;margin-bottom:21px;border-radius:12px}.dsx-ideas h2{font-size:12px}.dsx-ideas p{max-width:none;font-size:10px}.dsx-idea-chips{justify-content:flex-start;max-width:none;margin-top:12px;gap:6px}.dsx-idea-chips button{font-size:11px;min-height:36px;padding:7px 9px}
 .dsx-overview{margin-top:25px;display:block}.dsx-overview h2{font-size:20px}.dsx-result-stats{gap:9px;margin-top:10px;font-size:10px}.dsx-result-stats span+span{padding-inline-start:9px}
 .dsx-filters{gap:5px;padding:9px;border-radius:12px}.dsx-chk{font-size:10px;padding:7px;gap:5px}.dsx-sort{margin-inline-start:0;flex-grow:1;justify-content:flex-end;font-size:10px}.dsx-sort select{font-size:10px;padding:5px 7px;max-width:128px}.dsx-count{text-align:start;font-size:10px;width:100%}
 .dsx-table{border-radius:14px}.dsx-th{display:none}.dsx-row{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px;padding:17px;min-height:0}.dsx-row.dsx-primary{padding-inline-start:14px}
 .dsx-row .dsx-name{grid-column:1/-1}.dsx-name>b{font-size:17px}.dsx-name small{font-size:10px;max-width:45ch}.dsx-name-top{margin-bottom:7px}.dsx-state{align-self:start}.dsx-price{text-align:end}.dsx-price strong{font-size:18px}.dsx-price small{font-size:9px}.dsx-price del{font-size:10px}.dsx-action{grid-column:1/-1}.dsx-buy{width:100%;min-height:42px;justify-content:space-between;font-size:12px;padding-inline:14px}
 .dsx-idle{margin-top:30px;padding:28px 18px}.dsx-idle>b{font-size:13px}.dsx-idle>p{font-size:11px}.dsx-pricing-note{font-size:10px;text-align:start}.dsx-links-in{gap:6px}.dsx-links-in a{font-size:10px;padding:7px 9px}
}
@media(prefers-reduced-motion:reduce){.dsx *, .dsx *::before,.dsx *::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}.dm-spin{border-top-color:transparent}}

</style>

<script>
(function () {
  'use strict';
  var T = window.T || {}, D = T.discovery || {};
  var q = document.getElementById('dm-q'), go = document.getElementById('dm-go'),
      form = document.getElementById('dm-form'), box = document.getElementById('dm-results'),
      table = document.getElementById('dm-table'), filters = document.getElementById('dm-filters'),
      countEl = document.getElementById('dm-count'), emptyEl = document.getElementById('dm-empty'),
      err = document.getElementById('dm-error'), warn = document.getElementById('dm-warn'),
      more = document.getElementById('dm-more'), idle = document.getElementById('dm-idle'),
      overview = document.getElementById('dm-overview'), ideas = document.getElementById('dm-ideas'),
      ideaChips = document.getElementById('dm-idea-chips'), loadWrap = document.getElementById('dm-load-wrap'),
      loadMore = document.getElementById('dm-load-more'), loadNote = document.getElementById('dm-load-note'),
      priceNote = document.getElementById('dm-pricing-note'),
      spin = go.querySelector('.dm-spin'), label = go.querySelector('.dm-go-t');
  var fTaken = document.getElementById('f-taken'), fPremium = document.getElementById('f-premium'),
      fUnavail = document.getElementById('f-unavail'), fDeals = document.getElementById('f-deals'),
      fSort = document.getElementById('f-sort');
  var rows = [], token = 0, nextBatch = 0, activeTerm = '', seen = {}, controller = null, expiryTimer = null;
  var faDigits = function (s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  var num = function (n) { return T.is_fa ? faDigits(n) : String(n); };
  var esc = function (s) { var el = document.createElement('span'); el.textContent = s == null ? '' : String(s); return el.innerHTML; };
  var money = function (n) {
    n = Number(n);
    if (!Number.isFinite(n) || n <= 0) return null;
    if (T.is_fa) return faDigits(n.toLocaleString('en-US'));
    if (T.eur_rate > 0) return (n / T.eur_rate).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    return null;
  };
  function stateOf(r) {
    if (r.state) return r.state;
    if (r.status === 'unknown') return 'unchecked';
    if (!r.available) return 'taken';
    if (!r.orderable) return 'no_price';
    return r.is_premium ? 'premium' : 'free';
  }
  function bucketOf(st) {
    if (st === 'taken' || st === 'premium' || st === 'free') return st;
    return st === 'unchecked' ? '' : 'unavail';
  }
  function activePromotion(r) {
    var regular = Number(r.regular_price_toman), actual = Number(r.price_toman), end = Date.parse(r.promotion_ends_at || '');
    return r.is_promotion === true && Number.isSafeInteger(regular) && Number.isSafeInteger(actual)
      && actual > 0 && regular > actual && Number.isFinite(end) && end > Date.now();
  }
  function termLabel(r) {
    var value = r.period_years == null ? r.min_years : r.period_years;
    if (typeof value !== 'number' && (typeof value !== 'string' || !/^[1-9][0-9]*$/.test(value))) return '';
    var years = Number(value);
    return Number.isSafeInteger(years) && years > 0 ? D.first_term.replace(':years', num(years)) : '';
  }
  function render(r) {
    var promotionExpired = r.is_promotion === true && Number.isFinite(Date.parse(r.promotion_ends_at || '')) && Date.parse(r.promotion_ends_at) <= Date.now();
    var st = promotionExpired ? 'no_price' : stateOf(r), amount = (st === 'free' || st === 'premium') ? money(r.price_toman) : null, fxMissing = false;
    if (amount === null && (st === 'free' || st === 'premium')) { st = 'no_price'; fxMissing = true; }
    var deal = amount !== null && activePromotion(r), el = document.createElement('div');
    el.className = 'dsx-tr dsx-row' + (r.primary ? ' dsx-primary' : '') + (deal ? ' dsx-offer-row' : '');
    el.setAttribute('role', 'listitem');
    if (r.primary) el.dataset.primary = '1';
    el.dataset.state = st; el.dataset.bucket = bucketOf(st); el.dataset.price = r.price_toman || 0;
    el.dataset.tld = r.tld || ''; el.dataset.deal = deal ? '1' : '';
    var cls, pill, note, right = '';
    if (st === 'taken') { cls = 'taken'; pill = T.taken_pill; note = T.taken_note; }
    else if (st === 'unchecked') { cls = 'warn'; pill = T.unchecked_pill; note = T.unchecked_note; }
    else if (st === 'unsupported') { cls = 'taken'; pill = T.unsupported_pill; note = T.unsupported_note; }
    else if (st === 'no_price') { cls = 'no'; pill = T.no_price_pill; note = promotionExpired ? D.promotion_expired : ((fxMissing || r.reason === 'fx_unavailable') ? T.fx_unavailable : T.no_price); }
    else {
      var prem = st === 'premium'; cls = prem ? 'prem' : 'free'; pill = prem ? T.premium_pill : T.free_pill; note = prem ? T.premium_note : T.free_note;
      // The exact stored quote leads to checkout. Unknown or unpriced rows have no buy action.
      right = '<a class="dsx-buy" href="' + esc(r.quote_id ? T.panel + '/checkout/' + encodeURIComponent(r.quote_id) : T.panel + '?register=' + encodeURIComponent(r.domain)) + '">' + esc(T.register_btn) + '<span aria-hidden="true">↗</span></a>';
    }
    var price = '<div class="dsx-price" aria-hidden="true">—</div>', badge = '', term = termLabel(r);
    if (amount !== null) {
      var previous = deal ? money(r.regular_price_toman) : null;
      if (deal && previous !== null && previous !== amount) {
        var percent = Math.floor((Number(r.regular_price_toman) - Number(r.price_toman)) * 100 / Number(r.regular_price_toman));
        badge = '<span class="dsx-offer-badge">' + esc(percent > 0 ? D.discount_badge.replace(':percent', num(percent)) : D.offer_badge) + '</span>';
        var date = new Date(r.promotion_ends_at);
        var until = date.toLocaleDateString(T.locale === 'fa' ? 'fa-IR' : (T.locale === 'tr' ? 'tr-TR' : 'en-GB'), {month: 'short', day: 'numeric', year: 'numeric'});
        price = '<div class="dsx-price"><del aria-label="' + esc(D.regular_price) + '" dir="ltr">' + previous + '</del><strong aria-label="' + esc(D.current_price) + '" dir="ltr">' + amount + '</strong><small>' + esc(D.currency_unit) + (term ? ' · ' + esc(term) : '') + '</small><span class="dsx-expiry">' + esc(D.promotion_until.replace(':date', until)) + '</span></div>';
      } else {
        el.dataset.deal = ''; el.classList.remove('dsx-offer-row');
        price = '<div class="dsx-price"><strong dir="ltr">' + amount + '</strong><small>' + esc(D.currency_unit) + (term ? ' · ' + esc(term) : '') + '</small></div>';
      }
    }
    el.innerHTML = '<div class="dsx-name"><div class="dsx-name-top">' + (r.primary ? '<span class="dsx-choice">' + esc(D.requested_domain) + '</span>' : '') + badge + '</div><b dir="ltr">' + esc(r.domain) + '</b><small>' + esc(note) + '</small></div>' + '<div class="dsx-state"><span class="dsx-pill ' + cls + '">' + esc(pill) + '</span></div>' + price + '<div class="dsx-action">' + right + '</div>';
    return el;
  }
  function apply() {
    // A legacy API's missing campaign fields do not prove that the market has no offers.
    var promotionData = rows.some(function (r) { return typeof r.is_promotion === 'boolean'; });
    fDeals.closest('.dsx-deal-filter').hidden = !promotionData;
    document.getElementById('dm-deals-count').hidden = !promotionData;
    if (!promotionData) fDeals.checked = false;
    var hide = {taken: fTaken.checked, premium: fPremium.checked, unavail: fUnavail.checked}, n = {taken: 0, premium: 0, unavail: 0}, shown = 0, available = 0, deals = 0;
    var els = [].slice.call(box.children);
    els.forEach(function (el) {
      var b = el.dataset.bucket;
      if (b in n) n[b]++;
      if (el.dataset.state === 'free' || el.dataset.state === 'premium') available++;
      if (el.dataset.deal) deals++;
      // Keep the exact requested name and lookup failures visible under every filter.
      var hidden = !el.dataset.primary && el.dataset.state !== 'unchecked' && ((b === 'taken' && hide.taken) || (b === 'premium' && hide.premium) || (b === 'unavail' && hide.unavail) || (fDeals.checked && !el.dataset.deal));
      el.classList.toggle('is-hidden', hidden);
      if (!hidden) shown++;
    });
    var mode = fSort.value, visible = els.filter(function (el) { return !el.classList.contains('is-hidden'); });
    visible.sort(function (a, b) {
      var primary = (b.dataset.primary ? 1 : 0) - (a.dataset.primary ? 1 : 0);
      if (primary) return primary;
      var pa = +a.dataset.price || 0, pb = +b.dataset.price || 0;
      if (mode === 'price') return (pa || 1e15) - (pb || 1e15);
      if (mode === '-price') return pb - pa;
      if (mode === 'tld') return a.dataset.tld.localeCompare(b.dataset.tld);
      var rank = {free: 0, premium: 1, unchecked: 2, no_price: 3, unsupported: 4, taken: 5};
      var d = (rank[a.dataset.state] || 0) - (rank[b.dataset.state] || 0);
      return d || (pa || 1e15) - (pb || 1e15);
    });
    visible.forEach(function (el) { box.appendChild(el); });
    document.getElementById('n-taken').textContent = num(n.taken);
    document.getElementById('n-premium').textContent = num(n.premium);
    document.getElementById('n-unavail').textContent = num(n.unavail);
    document.getElementById('n-deals').textContent = num(deals);
    [fTaken, fPremium, fUnavail, fDeals].forEach(function (el) { el.closest('.dsx-chk').classList.toggle('on', el.checked); });
    countEl.textContent = T.count_tpl.replace('__N__', num(shown));
    document.getElementById('dm-available-count').textContent = D.available_count.replace(':count', num(available));
    document.getElementById('dm-deals-count').textContent = D.deals_count.replace(':count', num(deals));
    emptyEl.textContent = fDeals.checked && deals === 0 ? D.no_deals : T.all_hidden;
    emptyEl.hidden = !(els.length > 0 && (shown === 0 || (fDeals.checked && deals === 0)));
  }
  function refreshExpiry() {
    clearTimeout(expiryTimer);
    var upcoming = rows.filter(activePromotion).map(function (r) { return Date.parse(r.promotion_ends_at); });
    if (!upcoming.length) return;
    expiryTimer = setTimeout(function () {
      var fragment = document.createDocumentFragment();
      rows.forEach(function (r) { fragment.appendChild(render(r)); });
      box.replaceChildren(fragment); apply(); refreshExpiry();
    }, Math.min(2147483647, Math.max(0, Math.min.apply(null, upcoming) - Date.now() + 30)));
  }
  function busy(on) {
    go.disabled = on; spin.hidden = !on; label.hidden = on;
    box.setAttribute('aria-busy', on ? 'true' : 'false');
  }
  function flagLookup(d) {
    if (d && d.lookup_ok === false) { warn.textContent = T.lookup_failed; warn.hidden = false; }
  }
  // UI-only fallback for legacy APIs: name ideas, never availability or price.
  // Restrict to existing ASCII labels; never alter punycode or transliterate identity.
  function fallbackIdeas(query) {
    var raw = String(query || '').trim().toLowerCase().replace(/^https?:\/\//, '').replace(/^www\./, '').split(/[/?#]/)[0];
    if (/[^\x00-\x7f]/.test(raw)) return [];
    var parts = raw.split('.'), name = parts.shift(), ext = parts.join('.');
    if (!name || name.length < 2 || name.length > 63 || name.indexOf('xn--') === 0 || !/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(name)) return [];
    if (ext.split('.').some(function (label) { return label.indexOf('xn--') === 0; })) return [];
    var blocked = T.tld_unsold || ['ir', 'co.ir', 'ac.ir', 'org.ir', 'net.ir', 'gov.ir', 'id.ir', 'sch.ir'];
    if (!/^[a-z0-9-]+(?:\.[a-z0-9-]+)*$/.test(ext) || ext.split('.').some(function (label) { return label.length > 63; }) || blocked.indexOf(ext) !== -1) ext = 'com';
    return ['get' + name, 'my' + name, name + 'hub', name + 'online', 'try' + name, name + 'site'].filter(function (label) {
      return label.length <= 63 && label.length + ext.length + 1 <= 253;
    }).slice(0, 6).map(function (label) { return {name: label + '.' + ext}; });
  }
  function showIdeas(list) {
    ideaChips.replaceChildren();
    (list || []).slice(0, 6).forEach(function (item) {
      if (!item || typeof item.name !== 'string' || !item.name || item.name.length > 253) return;
      var btn = document.createElement('button'); btn.type = 'button'; btn.dir = 'ltr'; btn.dataset.domainQuery = item.name;
      btn.textContent = item.name; btn.setAttribute('aria-label', D.check_name.replace(':name', item.name)); ideaChips.appendChild(btn);
    });
    ideas.hidden = !ideaChips.children.length;
  }
  function append(list) {
    var fragment = document.createDocumentFragment(), added = 0;
    var primaryGuess = activeTerm.indexOf('.') === -1 ? activeTerm.toLowerCase().replace(/\s+/g, '') + '.' + (T.tld_first[0] || 'com') : null;
    (list || []).forEach(function (r) {
      var name = String(r.domain || '').toLowerCase();
      if (!name || seen[name]) return;
      seen[name] = true;
      if (primaryGuess && name === primaryGuess) r.primary = true;
      rows.push(r); fragment.appendChild(render(r)); added++;
    });
    if (added) { box.appendChild(fragment); table.hidden = false; filters.hidden = false; overview.hidden = false; priceNote.hidden = false; apply(); refreshExpiry(); }
  }
  async function fetchBatch(term, tlds, signal) {
    var res = await fetch(@json(lroute('domain.search.check')), {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify({q: term, tlds: tlds}), signal: signal});
    if (!res.ok) throw new Error('lookup_unavailable');
    return res.json();
  }
  function updateLoad() {
    loadWrap.hidden = !rows.length;
    loadMore.hidden = nextBatch >= T.tld_rest.length;
    loadNote.textContent = loadMore.hidden ? D.all_loaded : D.more_hint;
  }
  async function run() {
    var term = q.value.trim();
    if (!term) { q.focus(); return; }
    if (controller) controller.abort();
    controller = new AbortController();
    var mine = ++token, signal = controller.signal;
    activeTerm = term; nextBatch = 0; seen = {}; rows = []; clearTimeout(expiryTimer);
    var shareUrl = new URL(location.href); shareUrl.searchParams.set('q', term);
    history.replaceState(history.state, '', shareUrl);
    if (window.ServerNetAnalytics) window.ServerNetAnalytics.domainSearch(term);
    busy(true); loadMore.disabled = true;
    if (idle) { idle.hidden = true; }
    err.hidden = true; warn.hidden = true; more.hidden = true; table.hidden = true; filters.hidden = true; overview.hidden = true; ideas.hidden = true; loadWrap.hidden = true; emptyEl.hidden = true; priceNote.hidden = true;
    box.replaceChildren(); document.getElementById('dm-search-term').textContent = term;
    try {
      var first = await fetchBatch(term, T.tld_first, signal);
      if (mine !== token) return;
      if (!first.ok) { err.textContent = T.err_empty; err.hidden = false; return; }
      flagLookup(first); append(first.results); showIdeas(Array.isArray(first.suggestion_names) ? first.suggestion_names : fallbackIdeas(term)); updateLoad();
      if (rows.length) {
        var target = ideas.hidden ? overview : ideas;
        if (typeof target.scrollIntoView === 'function') target.scrollIntoView({block:'start', behavior:window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
      }
      if (!rows.length) { err.textContent = T.err_empty; err.hidden = false; }
    } catch (e) {
      if (mine !== token || e.name === 'AbortError') return;
      err.textContent = T.err_conn; err.hidden = false;
    } finally {
      if (mine === token) { more.hidden = true; busy(false); loadMore.disabled = false; }
    }
  }
  async function loadNext() {
    if (!activeTerm || loadMore.disabled || nextBatch >= T.tld_rest.length) return;
    var mine = token, index = nextBatch, term = activeTerm;
    more.hidden = false; loadMore.disabled = true; loadNote.textContent = D.more_hint;
    try {
      var d = await fetchBatch(term, T.tld_rest[index], controller.signal);
      if (mine !== token) return;
      if (!d.ok) throw new Error('lookup_unavailable');
      flagLookup(d); append(d.results); nextBatch++; updateLoad();
    } catch (e) {
      if (mine === token && e.name !== 'AbortError') loadNote.textContent = D.retry_more;
    } finally {
      if (mine === token) { more.hidden = true; loadMore.disabled = false; }
    }
  }
  [fTaken, fPremium, fUnavail, fDeals, fSort].forEach(function (el) { el.addEventListener('change', apply); });
  form.addEventListener('submit', function (event) { event.preventDefault(); run(); });
  loadMore.addEventListener('click', loadNext);
  document.querySelector('.dsx').addEventListener('click', function (event) {
    var btn = event.target.closest('[data-domain-query]');
    if (!btn) return;
    q.value = btn.dataset.domainQuery; run();
  });
  var pre = new URLSearchParams(location.search).get('q');
  if (pre) { q.value = pre.slice(0, 120); run(); }
})();
</script>
@endsection
