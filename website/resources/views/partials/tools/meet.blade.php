{{-- جلسات آنلاین — پروموت meet.servernet.cloud (Jitsi) --}}
@php $meetUrl = 'https://meet.servernet.cloud'; @endphp

<section class="hero hero-sub" style="padding-bottom:50px">
  <div class="container">
    <div class="hero-sub-inner" style="max-width:820px">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.nav_tools') }} · {{ __('ui.nav_free') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.tl_meet_h1a') }} <span class="grad">{{ __('ui.tl_meet_h1b') }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.tl_meet_lead') }}</p>
      <form class="tool-search reveal" id="meet-form" style="transition-delay:.24s" onsubmit="event.preventDefault();var r=(this.querySelector('input').value||'').trim().replace(/[^A-Za-z0-9آ-ی-]/g,'-');window.open('{{ $meetUrl }}/'+(r||('servernet-'+Date.now().toString(36))),'_blank','noopener');">
        <svg class="icon"><use href="#i-video"/></svg>
        <input type="text" placeholder="{{ __('ui.tl_meet_ph') }}" autocomplete="off" dir="ltr">
        <button class="btn btn-primary" type="submit"><span>{{ __('ui.tl_meet_btn') }}</span></button>
      </form>
      <div class="tool-hint reveal" style="transition-delay:.3s">
        <span><svg class="icon"><use href="#i-lock"/></svg>{{ __('ui.tl_meet_c1') }}</span>
        <span><svg class="icon"><use href="#i-smartphone"/></svg>{{ __('ui.tl_meet_c2') }}</span>
        <span><svg class="icon"><use href="#i-check"/></svg>{{ __('ui.tl_meet_c3') }}</span>
      </div>
    </div>
  </div>
</section>

{{-- ویژگی‌ها --}}
<section class="section" style="padding-top:20px">
  <div class="container">
    <div class="section-head reveal">
      <span class="badge">{{ __('ui.hp_feat_badge') }}</span>
      <h2>{{ __('ui.tl_meet_feat_t') }}</h2>
    </div>
    <div class="why-grid">
      @foreach([
        ['video', 'tl_meet_f1'], ['lock', 'tl_meet_f2'], ['monitor', 'tl_meet_f3'],
        ['smartphone', 'tl_meet_f4'], ['restore', 'tl_meet_f5'], ['sparkles', 'tl_meet_f6'],
      ] as [$ic, $k])
      <div class="witem reveal">
        <div class="wicon"><svg class="icon"><use href="#i-{{ $ic }}"/></svg></div>
        <div><h4>{{ __('ui.'.$k.'_t') }}</h4><p>{{ __('ui.'.$k.'_d') }}</p></div>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- CTA سازمانی --}}
<section class="cta-wrap reveal" style="margin-top:50px">
  <div class="cta">
    <h2>{{ __('ui.tl_meet_cta_t') }}</h2>
    <p>{{ __('ui.tl_meet_cta_d') }}</p>
    <a class="btn btn-primary" href="{{ $meetUrl }}" target="_blank" rel="noopener"><span>{{ __('ui.tl_meet_btn') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
  </div>
</section>
