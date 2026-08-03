@extends('layouts.site')

@section('title', lc($product)['t'].' — '.__('ui.brand'))
@section('description', lc($product)['hero_d'])

@section('content')
@php
    $loc = app()->getLocale();
    $category = $category ?? 'hosting';
    $yearlyOnly = ($product['billing'] ?? null) === 'yearly';
    $priceUnit = $yearlyOnly ? (isset($product['unit']) ? lc($product['unit']) : __('ui.domain_year')) : __('ui.mo');
@endphp

{{-- ============ دادهٔ ساختاریافته ============
  چرا مهم است: این صفحه جدولِ قیمت و پرسش‌های متداول را از قبل دارد، ولی برای
  موتور جست‌وجو و مدل‌های زبانی فقط «متن» بود. با نشانه‌گذاری، قیمت و پاسخ‌ها
  قابلِ نقل‌قول می‌شوند.

  ⚠️ `priceValidUntil` عمداً هست. در بازارِ تورمی، عددها زود عوض می‌شوند و
  بدونِ تاریخ، ChatGPT و Perplexity قیمتِ امروز را ماه‌ها بعد نقل می‌کنند —
  آن‌وقت یا باید عددِ منسوخ را بپذیریم یا مشتری سرِ خرید احساسِ فریب می‌کند.

  ⚠️⚠️ آرایه‌ها اول در بلوکِ php ساخته می‌شوند، نه درون‌خطی: آرایهٔ درون‌خطی
  پارسرِ Blade را می‌شکند. و در همین کامنت هم نامِ دایرکتیوها با علامتِ «at»
  نوشته نمی‌شود، چون Blade هر «at + کلمه» را — حتی داخلِ کامنت — دایرکتیو
  می‌شمارد. --}}
@php
  $sdHome = lroute('home');
  $sdName = lc($product)['t'];

  // نرخِ ارز و قیمت از همان منبعی که کاربر می‌بیند — وگرنه عددِ نشانه‌گذاری
  // با عددِ صفحه فرق می‌کرد و همان تناقض به موتور جست‌وجو گزارش می‌شد.
  $sdIsFa = app()->getLocale() === 'fa';
  $sdCur  = $sdIsFa ? 'IRR' : 'EUR';

  $sdOffers = [];
  foreach ($product['plans'] as $sdP) {
      if (($sdP['contact'] ?? false) || ! isset($sdP['irt'])) {
          continue;                      // «تماس بگیرید» قیمت نیست
      }

      $sdOffers[] = [
          '@type'           => 'Offer',
          'name'            => $sdP['name'] ?? '',
          // IRR چون schema.org واحدِ «تومان» ندارد؛ عدد همان چیزی است که
          // روی صفحه نوشته شده تا تناقضی گزارش نشود.
          'price'           => $sdIsFa ? price_toman((int) $sdP['irt']) : ($sdP['eur'] ?? 0),
          'priceCurrency'   => $sdCur,
          'priceValidUntil' => now()->addDays(30)->toDateString(),
          'availability'    => 'https://schema.org/InStock',
          'url'             => url()->current(),
      ];
  }

  $sdFaq = [];
  foreach ($faqs as $sdF) {
      $sdFaq[] = [
          '@type'          => 'Question',
          'name'           => lc($sdF)['q'],
          'acceptedAnswer' => ['@type' => 'Answer', 'text' => lc($sdF)['a']],
      ];
  }

  $sdCrumbs = ['itemListElement' => [
      ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => $sdHome],
      ['@type' => 'ListItem', 'position' => 2, 'name' => $sdName, 'item' => url()->current()],
  ]];

  $sdProduct = [
      'name'        => $sdName,
      'description' => lc($product)['hero_d'],
      'url'         => url()->current(),
      'brand'       => ['@type' => 'Brand', 'name' => __('ui.brand')],
      'offers'      => $sdOffers,
  ];
@endphp
@if($sdOffers)
<script type="application/ld+json">{!! schema_ld($sdProduct, 'Product') !!}</script>
@endif
@if($sdFaq)
<script type="application/ld+json">{!! schema_ld(['mainEntity' => $sdFaq], 'FAQPage') !!}</script>
@endif
<script type="application/ld+json">{!! schema_ld($sdCrumbs, 'BreadcrumbList') !!}</script>
{{-- ============ HERO ============ --}}
<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ lc($product)['tag'] }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ lc($product)['hero_t'] }} <span class="grad">{{ lc($product)['hero_g'] }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ lc($product)['hero_d'] }}</p>
      <div class="hero-ctas reveal" style="transition-delay:.24s">
        <a class="btn btn-primary" href="#pricing"><span>{{ __('ui.hero_cta1') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        <a class="btn btn-glass" href="tel:{{ $contact['phone_link'] }}"><svg class="icon" style="width:16px;height:16px"><use href="#i-phone"/></svg>{{ __('ui.hp_consult') }}</a>
      </div>
      <div class="chip-row reveal" style="transition-delay:.32s">
        @foreach($product['chips'] as $chip)
        <span class="tech-chip"><svg class="icon"><use href="#i-check"/></svg>{{ $chip }}</span>
        @endforeach
      </div>
    </div>
  </div>
</section>

{{-- ============ AI SITE BUILDER (المان اختصاصی صفحه سایت‌ساز) ============ --}}
@if(($product['signature']['type'] ?? '') === 'ai-builder')
  @include('partials.sig-ai-builder', ['product' => $product])
@endif

{{-- ============ PLANS ============ --}}
<section class="section" id="pricing" style="padding-top:30px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.hp_plans_badge') }}</span>
      <h2>{{ __('ui.hp_plans_title') }}</h2>
      <p>{{ __('ui.hp_plans_sub') }}</p>
    </div>
    @php
      // سرورِ مجازی/اختصاصی با پلنِ زنده = **جدولِ کامل**، نه کارت.
      // کارت برای شش پلن خوب است؛ آلمان ده‌ها پلن دارد و کارت‌کردنشان یعنی یا
      // دیوارِ کارت یا — کاری که قبلاً می‌کردیم — پنهان‌کردنِ بقیه.
      $asTable = ! empty($planHrefs) && in_array($category, ['vps', 'dedicated'], true);
    @endphp

    {{-- در نمای جدولی فقط قیمتِ ماهانه ستون دارد، پس کلیدِ ماهانه/سالانه
         چیزی را عوض نمی‌کند و فقط کاربر را گمراه می‌کند. --}}
    @unless($yearlyOnly || $asTable)
    <div class="bill-toggle reveal" role="group" aria-label="Billing cycle">
      <button type="button" class="active" data-bill="monthly">{{ __('ui.bill_monthly') }}</button>
      <button type="button" data-bill="yearly">{{ __('ui.bill_yearly') }}<span class="save">{{ __('ui.bill_save', ['percent' => $isFa ? fa_num(config('billing.cycles.yearly.discount_pct')) : config('billing.cycles.yearly.discount_pct')]) }}</span></button>
    </div>
    @endunless
    @if($asTable)
    @php
      /*
      | دو گروه: ابریِ عمومی (پردازندهٔ اشتراکی) و پردازندهٔ اختصاصی.
      |
      | نام‌گذاری از مشورتِ مدیر مارکتینگ آمده و عمداً «اختصاصی» را تنها
      | نمی‌گذارد: در بازار ایران «سرور اختصاصی» یعنی سرورِ فیزیکی، و ما جای
      | دیگرِ همین سایت واقعاً سرورِ فیزیکی می‌فروشیم. پس اسمِ محصول در هر دو
      | جدول «سرور ابری» می‌مانَد و تفاوت فقط یک صفت روی **پردازنده** است.
      */
      $groups = [];
      foreach ($product['plans'] as $i => $p) {
          $groups[($p['row']['dedicated'] ?? false) ? 'ded' : 'std'][] = $i;
      }

      // شهرهای موجود برای فیلتر — فقط آن‌هایی که واقعاً ردیف دارند، وگرنه
      // کاربر گزینه‌ای می‌بیند که هیچ نتیجه‌ای نمی‌دهد.
      $cityOpts = [];
      foreach ($product['plans'] as $p) {
          $c = $p['row']['city'] ?? '';
          if ($c !== '') { $cityOpts[$c] = true; }
      }
      $cityOpts = array_keys($cityOpts);
      sort($cityOpts);

      $ramOpts = [];
      foreach ($product['plans'] as $p) {
          $mb = (int) ($p['row']['ram_mb'] ?? 0);
          if ($mb > 0) { $ramOpts[$mb] = $p['row']['ram']; }
      }
      ksort($ramOpts);

      $cpuOpts = [];
      foreach ($product['plans'] as $p) {
          $v = (int) ($p['row']['vcpu'] ?? 0);
          if ($v > 0) { $cpuOpts[$v] = true; }
      }
      $cpuOpts = array_keys($cpuOpts);
      sort($cpuOpts);
    @endphp

    <div class="pt-tools reveal" id="plans">
      <div class="pt-filters">
        <label class="pt-f">
          <span>{{ __('ui.pt_f_city') }}</span>
          <select data-f="city">
            <option value="">{{ __('ui.pt_f_all') }}</option>
            @foreach($cityOpts as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
          </select>
        </label>
        <label class="pt-f">
          <span>{{ __('ui.cvb_cores') }}</span>
          <select data-f="cpu">
            <option value="">{{ __('ui.pt_f_all') }}</option>
            @foreach($cpuOpts as $v)<option value="{{ $v }}">{{ $isFa ? fa_num($v) : $v }}+</option>@endforeach
          </select>
        </label>
        <label class="pt-f">
          <span>{{ __('ui.cvb_ram') }}</span>
          <select data-f="ram">
            <option value="">{{ __('ui.pt_f_all') }}</option>
            @foreach($ramOpts as $mb => $label)<option value="{{ $mb }}">{{ $label }}+</option>@endforeach
          </select>
        </label>
        <label class="pt-f">
          <span>{{ __('ui.pt_f_sort') }}</span>
          <select data-f="sort">
            <option value="price">{{ __('ui.pt_sort_cheap') }}</option>
            <option value="-price">{{ __('ui.pt_sort_dear') }}</option>
            <option value="-cpu">{{ __('ui.pt_sort_cpu') }}</option>
            <option value="-ram">{{ __('ui.pt_sort_ram') }}</option>
          </select>
        </label>
      </div>
      <p class="pt-hint">{{ __('ui.pt_hint') }}</p>
    </div>

    @foreach(['std' => 'ui.pt_g_std', 'ded' => 'ui.pt_g_ded'] as $key => $titleKey)
      @if(! empty($groups[$key]))
      <section class="pt-group reveal" data-group="{{ $key }}">
        <header class="pt-group-head">
          <h3>{{ __($titleKey) }}</h3>
          <p>{{ __($key === 'std' ? 'ui.pt_g_std_d' : 'ui.pt_g_ded_d') }}</p>
        </header>
        <div class="plan-table-wrap">
          <table class="plan-table">
            <thead>
              <tr>
                <th>{{ __('ui.pt_row') }}</th>
                <th>{{ __('ui.pt_plan') }}</th>
                <th>{{ __('ui.cvb_cores') }}</th>
                <th>{{ __('ui.cvb_ram') }}</th>
                <th>{{ __('ui.pt_disk') }}</th>
                <th>{{ __('ui.pt_traffic') }}</th>
                <th>{{ __('ui.pt_location') }}</th>
                <th>{{ __('ui.pt_price') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              @foreach($groups[$key] as $i)
              @php $p = $product['plans'][$i]; $r = $p['row'] ?? []; @endphp
              <tr data-city="{{ $r['city'] ?? '' }}"
                  data-cpu="{{ (int) ($r['vcpu'] ?? 0) }}"
                  data-ram="{{ (int) ($r['ram_mb'] ?? 0) }}"
                  data-price="{{ (int) ($r['price_n'] ?? 0) }}">
                <td class="pt-num"></td>
                <td class="pt-name"><b>{{ $p['name'] }}</b>
                  @if(! empty($r['cpu']))<span class="pt-tag">{{ $r['cpu'] }}</span>@endif
                </td>
                <td dir="ltr">{{ $isFa ? fa_num($r['vcpu'] ?? '') : ($r['vcpu'] ?? '') }}</td>
                <td dir="ltr">{{ $r['ram'] ?? '' }}</td>
                <td dir="ltr">{{ $r['disk'] ?? '' }}</td>
                <td dir="ltr">{{ $r['traffic'] ?? '' }}</td>
                <td>{{ $r['city'] ?? '' }}</td>
                <td class="pt-price"><b>{{ site_price($p) }}</b><span>{{ __('ui.mo') }}</span></td>
                <td class="pt-buy">
                  <a class="btn btn-primary" href="{{ $planHrefs[$i] ?? $cloudStoreHref }}">{{ __('ui.choose') }}</a>
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <p class="pt-empty" hidden>{{ __('ui.pt_nomatch') }}</p>
      </section>
      @endif
    @endforeach

    <p class="plan-table-count reveal" id="pt-count"
       data-tpl="{{ __('ui.pt_count', ['n' => '__N__']) }}">
      {{ __('ui.pt_count', ['n' => $isFa ? fa_num(count($product['plans'])) : count($product['plans'])]) }}
    </p>
    @else
    <div class="plans {{ count($product['plans']) === 3 ? 'plans-3' : '' }} {{ count($product['plans']) >= 5 ? 'plans-many' : '' }}" id="plans">
      @foreach($product['plans'] as $i => $p)
      @php
        $isContact = $p['contact'] ?? false;
        // خرید داخلی: مستقیم به تسویهٔ همان پکیج در پنل (slug = «محصول-شمارهٔ‌پلن»).
        // هاست → سفارشِ همان پکیج؛ سرورِ مجازی/اختصاصی → فروشگاهِ کنسول با مکانِ
        // همین کشورِ ازپیش‌انتخاب‌شده. هیچ‌کدام دیگر به WHMCSِ بیرونی نمی‌روند.
        // پلنِ زندهٔ ابری لینکِ اختصاصیِ خودش را دارد (مکان + پلن ازپیش‌انتخاب‌شده)
        // تا همان چیزی که این‌جا می‌بیند، سرِ پرداخت هم همان باشد.
        $storeHref = ($category === 'hosting')
            ? lroute('account.order', $slug.'-'.($i + 1))
            : (($planHrefs[$i] ?? null) ?: ($cloudStoreHref ?? null));
      @endphp
      <article class="plan {{ ($p['popular'] ?? false) ? 'popular' : '' }} reveal" style="transition-delay:{{ $i * 80 }}ms">
        @if($p['popular'] ?? false)<span class="pop-badge">{{ __('ui.popular') }}</span>@endif
        <h3>{{ $p['name'] }}</h3>
        <div class="p-price">
          @if($isContact)
          <span class="pr"><b style="font-size:23px">{{ __('ui.hp_contact_price') }}</b></span>
          @elseif($yearlyOnly)
          <span class="pr"><b>{{ site_price($p) }}</b><span>{{ $priceUnit }}</span></span>
          @else
          <span class="pr pr-m"><b>{{ site_price($p) }}</b><span>{{ __('ui.mo') }}</span></span>
          <span class="pr pr-y"><s>{{ site_price($p) }}</s><b>{{ site_price_yearly($p) }}</b><span>{{ __('ui.bill_yearly_note') }}</span></span>
          @endif
        </div>
        <ul>
          @foreach($p['specs'] as $j => $spec)
          <li @if($j === 0) class="hl" @endif><svg class="icon"><use href="#i-check"/></svg>@if(is_array($spec)){{ lc($spec) }}@else<span dir="ltr">{{ $spec }}</span>@endif</li>
          @endforeach
        </ul>
        @if($isContact)
        <a class="btn btn-glass" href="tel:{{ $contact['phone_link'] }}"><svg class="icon" style="width:15px;height:15px"><use href="#i-phone"/></svg>{{ __('ui.hp_consult') }}</a>
        @elseif($storeHref)
        <a class="btn {{ ($p['popular'] ?? false) ? 'btn-primary' : 'btn-glass' }}" href="{{ $storeHref }}">{{ __('ui.choose') }}</a>
        @elseif($yearlyOnly)
        <a class="btn {{ ($p['popular'] ?? false) ? 'btn-primary' : 'btn-glass' }}"
           href="{{ isset($p['url']) ? whmcs_url($p['url']) : buy_url($p['pid']) }}"
           target="_blank" rel="noopener">{{ __('ui.choose') }}</a>
        @else
        <a class="btn {{ ($p['popular'] ?? false) ? 'btn-primary' : 'btn-glass' }} plan-buy"
           href="{{ buy_url($p['pid']) }}&billingcycle=monthly"
           data-url-m="{{ buy_url($p['pid']) }}&billingcycle=monthly"
           data-url-y="{{ buy_url($p['pid']) }}&billingcycle=annually"
           target="_blank" rel="noopener">{{ __('ui.choose') }}</a>
        @endif
      </article>
      @endforeach
    </div>
    @endif
    <div class="inc-strip reveal">
      <b>{{ __('ui.hp_inc_title') }}</b>
      <div class="inc-items">
        @foreach(['hp_inc1', 'hp_inc2', 'hp_inc3', 'hp_inc4', 'hp_inc5', 'hp_inc6'] as $inc)
        <span><svg class="icon"><use href="#i-check"/></svg>{{ __('ui.'.$inc) }}</span>
        @endforeach
      </div>
    </div>
  </div>
</section>

{{-- ============ FEATURES ============ --}}
<section class="section" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.hp_feat_badge') }}</span>
      <h2>{{ __('ui.hp_feat_title', ['name' => lc($product)['t']]) }}</h2>
    </div>
    <div class="why-grid">
      @foreach($features as $i => $f)
      <div class="witem reveal" style="transition-delay:{{ $i * 50 }}ms">
        <div class="wicon"><svg class="icon"><use href="#i-{{ $f['icon'] }}"/></svg></div>
        <div><h4>{{ lc($f)['t'] }}</h4><p>{{ lc($f)['d'] }}</p></div>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ============ BAND (پیشنهاد مرتبط، مثل ایمیل تراکنشی) ============ --}}
@isset($product['band'])
@php $band = $product['band']; $bl = lc($band); @endphp
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="hp-band reveal">
      <div class="hp-band-glow"></div>
      <div class="hp-band-main">
        <span class="hp-band-ic"><svg class="icon"><use href="#i-{{ $band['icon'] }}"/></svg></span>
        <div>
          @if(!empty($band['badge_key']))<span class="hp-band-badge">{{ $band['badge_key'] }}</span>@endif
          <h3>{{ $bl['t'] }}</h3>
          <p>{{ $bl['d'] }}</p>
        </div>
      </div>
      <ul class="hp-band-points">
        @foreach($bl['points'] as $pt)<li><svg class="icon"><use href="#i-check"/></svg>{{ $pt }}</li>@endforeach
      </ul>
      <a class="btn btn-primary" href="{{ lroute('contact') }}"><span>{{ $bl['cta'] }}</span><svg class="icon dir" style="width:16px;height:16px"><use href="#i-arrow"/></svg></a>
    </div>
  </div>
</section>
@endisset

{{-- ============ SIGNATURE (المان اختصاصی هر محصول) ============ --}}
@isset($product['signature'])
@if($product['signature']['type'] !== 'ai-builder')
<section class="section" style="padding-top:0">
  <div class="container">
    @includeIf('partials.sig-'.$product['signature']['type'], ['sig' => $product['signature']])
  </div>
</section>
@endif
@endisset

{{-- ============ INFRASTRUCTURE ============ --}}
<section class="section" style="padding-top:30px;padding-bottom:40px">
  <div class="container">
    <div class="infra-panel reveal">
      <div class="infra-head">
        <span class="badge">{{ __('ui.hp_infra_badge') }}</span>
        <h2>{{ __('ui.hp_infra_title') }}</h2>
        <p>{{ __('ui.hp_infra_sub') }}</p>
      </div>
      <div class="infra-stats">
        <div class="istat"><b>Tier III+</b><span>{{ __('ui.hp_infra1') }}</span></div>
        <div class="istat"><b>NVMe RAID-10</b><span>{{ __('ui.hp_infra2') }}</span></div>
        <div class="istat"><b>{{ $isFa ? fa_num('99.9') : '99.9' }}%</b><span>{{ __('ui.hp_infra3') }}</span></div>
        <div class="istat"><b>Anti-DDoS</b><span>{{ __('ui.hp_infra4') }}</span></div>
      </div>
    </div>
  </div>
</section>

{{-- ============ FAQ ============ --}}
<section class="section" id="faq" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.faq_badge') }}</span>
      <h2>{{ __('ui.faq_title') }}</h2>
    </div>
    <div class="faq-list reveal">
      @foreach($faqs as $f)
      <details class="faq">
        <summary>{{ lc($f)['q'] }}<svg class="icon"><use href="#i-plus"/></svg></summary>
        <div class="body">{{ lc($f)['a'] }}</div>
      </details>
      @endforeach
    </div>
  </div>
</section>

{{-- ============ RELATED ============ --}}
<section class="section" style="padding-top:0;padding-bottom:70px">
  <div class="container">
    <div class="section-head reveal" style="margin-bottom:36px">
      <h2 style="font-size:27px">{{ __('ui.hp_related_title') }}</h2>
    </div>
    <div class="loc-strip reveal">
      @foreach($related as $rSlug => $r)
      <a class="loc" href="{{ $category === 'hosting' ? lroute('hosting', $rSlug) : lroute('catalog', ['category' => $category, 'slug' => $rSlug]) }}"><svg class="icon"><use href="#i-{{ $r['icon'] }}"/></svg>{{ lc($r)['t'] }}</a>
      @endforeach
    </div>
  </div>
</section>

{{-- ============ CTA + CONTACT ============ --}}
<section class="cta-wrap reveal" id="contact">
  <div class="cta">
    <h2>{{ __('ui.cta_title') }}</h2>
    <p>{{ __('ui.cta_sub') }}</p>
    <a class="btn btn-primary" href="#pricing"><span>{{ __('ui.hero_cta1') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
    <div class="cta-contacts">
      <a href="tel:{{ $contact['phone_link'] }}"><svg class="icon"><use href="#i-phone"/></svg>{{ $contact['phone'] }}</a>
      <a href="mailto:{{ $contact['email'] }}"><svg class="icon"><use href="#i-mail"/></svg>{{ $contact['email'] }}</a>
      <a href="https://wa.me/{{ $contact['whatsapp'] }}" target="_blank" rel="noopener"><svg class="icon"><use href="#i-message"/></svg>WhatsApp</a>
    </div>
  </div>
</section>
@endsection
