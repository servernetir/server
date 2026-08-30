{{-- اپلیکیشن‌ساز — نمایندگی اسکریپت اپ‌ساز، الگو از puzzley --}}
<section class="hero hero-sub" style="padding-bottom:50px">
  <div class="container">
    <div class="hero-sub-inner" style="max-width:840px">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.tl_app_tag') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.tl_app_h1a') }} <span class="grad">{{ __('ui.tl_app_h1b') }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.tl_app_lead') }}</p>
      <div class="hero-ctas reveal" style="transition-delay:.24s">
        <a class="btn btn-primary" href="#pricing"><span>{{ __('ui.tl_app_cta1') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        <a class="btn btn-glass" href="tel:{{ $contact['phone_link'] }}"><svg class="icon" style="width:16px;height:16px"><use href="#i-phone"/></svg>{{ __('ui.hp_consult') }}</a>
      </div>
      <div class="tool-hint reveal" style="transition-delay:.3s">
        @foreach(['tl_app_c1', 'tl_app_c2', 'tl_app_c3', 'tl_app_c4'] as $k)
        <span><svg class="icon"><use href="#i-check"/></svg>{{ __('ui.'.$k) }}</span>
        @endforeach
      </div>
    </div>
  </div>
</section>

{{-- چطور کار می‌کند --}}
<section class="section" style="padding-top:20px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.tl_app_how_badge') }}</span>
      <h2>{{ __('ui.tl_app_how_t') }}</h2>
      <p>{{ __('ui.tl_app_how_d') }}</p>
    </div>
    <div class="app-steps">
      @foreach(['tl_app_s1', 'tl_app_s2', 'tl_app_s3', 'tl_app_s4'] as $i => $k)
      <div class="app-step reveal" style="transition-delay:{{ $i * 70 }}ms">
        <span class="app-num">{{ $isFa ? fa_num($i + 1) : $i + 1 }}</span>
        <b>{{ __('ui.'.$k.'_t') }}</b>
        <small>{{ __('ui.'.$k.'_d') }}</small>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ویژگی‌ها --}}
<section class="section" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.hp_feat_badge') }}</span>
      <h2>{{ __('ui.tl_app_feat_t') }}</h2>
    </div>
    <div class="why-grid">
      @foreach([
        ['smartphone', 'tl_app_f1'], ['layout', 'tl_app_f2'], ['zap', 'tl_app_f3'],
        ['db', 'tl_app_f4'], ['coins', 'tl_app_f5'], ['sparkles', 'tl_app_f6'],
      ] as [$ic, $k])
      <div class="witem reveal">
        <div class="wicon"><svg class="icon"><use href="#i-{{ $ic }}"/></svg></div>
        <div><h4>{{ __('ui.'.$k.'_t') }}</h4><p>{{ __('ui.'.$k.'_d') }}</p></div>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- پلن‌ها --}}
<section class="section" id="pricing" style="padding-top:40px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.hp_plans_badge') }}</span>
      <h2>{{ __('ui.tl_app_plans_t') }}</h2>
    </div>
    <div class="plans plans-3">
      @foreach([
        ['name' => 'tl_app_p1', 'irt' => 1900000, 'eur' => 19, 'pop' => false, 'f' => ['p1a', 'p1b', 'p1c', 'p1d']],
        ['name' => 'tl_app_p2', 'irt' => 3900000, 'eur' => 39, 'pop' => true, 'f' => ['p2a', 'p2b', 'p2c', 'p2d']],
        ['name' => 'tl_app_p3', 'irt' => 8900000, 'eur' => 89, 'pop' => false, 'f' => ['p3a', 'p3b', 'p3c', 'p3d']],
      ] as $i => $p)
      <article class="plan {{ $p['pop'] ? 'popular' : '' }} reveal" style="transition-delay:{{ $i * 80 }}ms">
        @if($p['pop'])<span class="pop-badge">{{ __('ui.popular') }}</span>@endif
        <h3>{{ __('ui.'.$p['name']) }}</h3>
        <div class="p-price"><span class="pr"><b>{{ site_price($p) }}</b><span>{{ __('ui.tl_app_once') }}</span></span></div>
        <ul>
          @foreach($p['f'] as $f)
          <li><svg class="icon"><use href="#i-check"/></svg>{{ __('ui.tl_app_'.$f) }}</li>
          @endforeach
        </ul>
        <a class="btn {{ $p['pop'] ? 'btn-primary' : 'btn-glass' }}" href="tel:{{ $contact['phone_link'] }}">{{ __('ui.tl_app_order') }}</a>
      </article>
      @endforeach
    </div>
  </div>
</section>

{{-- CTA --}}
<section class="cta-wrap reveal" style="margin-top:40px">
  <div class="cta">
    <h2>{{ __('ui.tl_app_cta_t') }}</h2>
    <p>{{ __('ui.tl_app_cta_d') }}</p>
    <a class="btn btn-primary" href="tel:{{ $contact['phone_link'] }}"><span>{{ __('ui.hp_consult') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
  </div>
</section>
