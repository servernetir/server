@php
    $loc = app()->getLocale();
    $mega = config('servernet.mega');
    $servicesMenu = config('servernet.services_menu');
    $toolsMenu = config('servernet.tools_menu');
    $knowledgeMenu = config('servernet.knowledge_menu');
@endphp
<div class="site-header-wrap" id="header-wrap">
  {{-- نوار اعتماد: تلفن، ایمیل، وضعیت + زبان --}}
  <div class="topbar">
    <div class="container">
      <div class="topbar-contacts">
        <a href="tel:{{ $contact['phone_link'] }}"><svg class="icon"><use href="#i-phone"/></svg>{{ $contact['phone'] }}</a>
        <a href="mailto:{{ $contact['email'] }}"><svg class="icon"><use href="#i-mail"/></svg>{{ $contact['email'] }}</a>
        <span class="topbar-status hide-sm"><svg class="icon" style="color:var(--green)"><use href="#i-headset"/></svg>{{ __('ui.topbar_support') }}</span>
      </div>
      <div class="topbar-side">
        <span class="topbar-status hide-sm"><span class="pulse"></span>{{ __('ui.topbar_status') }}</span>
        <nav class="lang-links" aria-label="Language">
          <a href="{{ $localeUrls['fa'] }}" @class(['active' => $loc === 'fa'])>فارسی</a>
          <span aria-hidden="true">|</span>
          <a href="{{ $localeUrls['en'] }}" @class(['active' => $loc === 'en'])>English</a>
          <span aria-hidden="true">|</span>
          <a href="{{ $localeUrls['tr'] }}" @class(['active' => $loc === 'tr'])>Türkçe</a>
        </nav>
      </div>
    </div>
  </div>

  <header id="header">
    <div class="container">
      <a href="{{ $homeUrl }}" class="logo"><span class="logo-mark"><svg class="icon"><use href="#i-server"/></svg></span> {{ $isFa ? 'سرورنت' : 'ServerNet' }}</a>

      <nav class="desktop" id="desktop-nav" aria-label="Main">
        <div class="nav-item" data-menu="products">
          <button class="nav-link" aria-expanded="false">{{ __('ui.nav_products') }}<svg class="icon chev"><use href="#i-chev"/></svg></button>
        </div>
        <div class="nav-item" data-menu="services">
          <button class="nav-link" aria-expanded="false">{{ __('ui.nav_services') }}<svg class="icon chev"><use href="#i-chev"/></svg></button>
        </div>
        <div class="nav-item" data-menu="tools">
          <button class="nav-link" aria-expanded="false">{{ __('ui.nav_tools') }}<span class="free-badge new-badge">{{ __('ui.nav_new') }}</span><svg class="icon chev"><use href="#i-chev"/></svg></button>
        </div>
        <div class="nav-item" data-menu="knowledge">
          <button class="nav-link" aria-expanded="false">{{ __('ui.nav_knowledge') }}<svg class="icon chev"><use href="#i-chev"/></svg></button>
        </div>
        <a class="nav-link" href="{{ lroute('contact') }}">{{ __('ui.nav_contact') }}</a>
      </nav>

      <div class="header-actions">
        <button class="theme-toggle" id="theme-toggle" aria-label="Light / Dark">
          <svg class="icon tt-sun"><use href="#i-sun"/></svg>
          <svg class="icon tt-moon"><use href="#i-moon"/></svg>
        </button>
        @php $cust = auth('customer')->user(); @endphp
        @if($cust)
          @php $uinit = initials($cust->displayName(), $cust->email); $uav = avatar_url($cust->email); @endphp
          <div class="usr-menu" id="usr-menu">
            <button type="button" class="usr-btn" id="usr-btn" aria-haspopup="true" aria-expanded="false">
              <span class="usr-av"><i>{{ $uinit }}</i>@if($uav)<img src="{{ $uav }}" alt="" referrerpolicy="no-referrer" onerror="this.remove()">@endif</span>
              <span class="usr-nm">{{ \Illuminate\Support\Str::limit($cust->displayName(), 16) }}</span>
              <svg class="icon usr-cv" style="width:13px;height:13px"><use href="#i-chev"/></svg>
            </button>
            <div class="usr-drop">
              <div class="usr-hd">
                <span class="usr-av lg"><i>{{ $uinit }}</i>@if($uav)<img src="{{ $uav }}" alt="" referrerpolicy="no-referrer" onerror="this.remove()">@endif</span>
                <div><b>{{ $cust->displayName() }}</b><small dir="ltr">{{ $cust->code }}</small></div>
              </div>
              <a href="{{ console_lroute('account.home') }}"><svg class="icon"><use href="#i-gauge"/></svg>{{ __('ui.nav_dash') }}</a>
              <a href="{{ console_lroute('account.services') }}"><svg class="icon"><use href="#i-server"/></svg>{{ __('ui.nav_services') }}</a>
              <a href="{{ console_lroute('account.invoices') }}"><svg class="icon"><use href="#i-coins"/></svg>{{ __('ui.nav_invoices') }}</a>
              <form method="post" action="{{ console_lroute('logout') }}" class="usr-out">@csrf<button type="submit"><svg class="icon"><use href="#i-x"/></svg>{{ __('ui.auth_logout') }}</button></form>
            </div>
          </div>
        @else
          <a class="login-btn" href="{{ console_lroute('login') }}"><svg class="icon" style="width:16px;height:16px"><use href="#i-user"/></svg><span>{{ __('ui.nav_login') }}</span></a>
        @endif
        <button class="hamburger" id="hamburger" aria-label="{{ __('ui.menu') }}" aria-expanded="false"><span></span><span></span><span></span></button>
      </div>
    </div>

    {{-- ===== مگامنوی محصولات ===== --}}
    <div class="menu-panel" id="menu-products" role="region">
      <div class="container mega-inner">
        <div class="mega-tabs" role="tablist">
          @foreach($mega as $key => $cat)
          <button class="mega-tab @if($loop->first) active @endif" data-tab="{{ $key }}" role="tab">
            <span class="mt-icon"><svg class="icon"><use href="#i-{{ $cat['icon'] }}"/></svg></span>
            <span class="mt-txt"><b>{{ lc($cat)['t'] }}</b><small>{{ lc($cat)['d'] }}</small></span>
            <svg class="icon chev dir" style="transform:rotate({{ $isFa ? '90deg' : '-90deg' }})"><use href="#i-chev"/></svg>
          </button>
          @endforeach
        </div>
        <div class="mega-content">
          @foreach($mega as $key => $cat)
          <div class="mega-pane @if($loop->first) active @endif" data-pane="{{ $key }}">
            @foreach($cat['groups'] as $group)
            <div class="mega-group">
              <h6>{{ lc($group) }}</h6>
              @foreach($group['items'] as $item)
              @php
                $mHref = isset($item['route']) ? lroute($item['route'][0], $item['route'][1] ?? [])
                    : (isset($item['slug']) ? ($key === 'hosting' ? lroute('hosting', $item['slug']) : lroute('catalog', ['category' => $key, 'slug' => $item['slug']])) : '#');
              @endphp
              <a href="{{ $mHref }}">{{ lc($item) }}@if(!empty($item['new']))<span class="free-badge new-badge" style="margin-inline-start:6px">{{ __('ui.nav_new') }}</span>@endif</a>
              @endforeach
            </div>
            @endforeach
          </div>
          @endforeach
        </div>
      </div>
      <div class="mega-foot">
        <div class="container">
          <a href="tel:{{ $contact['phone_link'] }}"><svg class="icon"><use href="#i-phone"/></svg>{{ __('ui.mega_cta') }}: <b dir="ltr">{{ $contact['phone'] }}</b></a>
          <span>{{ __('ui.mega_note') }}</span>
        </div>
      </div>
    </div>

    {{-- ===== منوی خدمات ===== --}}
    <div class="menu-panel" id="menu-services" role="region">
      <div class="container">
        <div class="drop-grid cols-3">
          @foreach($servicesMenu as $s)
          <a class="drop-card" href="{{ isset($s['slug']) ? (($s['cat'] ?? null) === 'hosting' ? lroute('hosting', $s['slug']) : lroute('catalog', ['category' => 'services', 'slug' => $s['slug']])) : '#' }}">
            <span class="dc-icon"><svg class="icon"><use href="#i-{{ $s['icon'] }}"/></svg></span>
            <span class="dc-txt"><b>{{ lc($s)['t'] }}</b><small>{{ lc($s)['d'] }}</small></span>
          </a>
          @endforeach
        </div>
      </div>
    </div>

    {{-- ===== منوی ابزارهای تخصصی (مگامنوی گروه‌بندی‌شده) ===== --}}
    @php
      $tbOther = collect($toolsMenu)->keyBy(fn ($t) => $t['slug'] ?? '');
      $lkTypes = config('lookup.types');
      $lkGroups = config('lookup.groups');
    @endphp
    <div class="menu-panel" id="menu-tools" role="region">
      <div class="container">
        <div class="tools-mega">

          {{-- ستون ۱: بررسی و عیب‌یابی (ابزارهای جامع) --}}
          <div class="tmega-col">
            <div class="tmega-group">
              <span class="tmega-h">{{ lc($lkGroups['records']) }} · {{ lc($lkGroups['network']) }}</span>
              <a class="tmega-link" href="{{ lroute('hub.dns') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-db"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_dns') }} <span class="free-badge new-badge">{{ __('ui.nav_new') }}</span></b><small>{{ __('ui.tb_dns_d') }}</small></span>
              </a>
              <a class="tmega-link" href="{{ lroute('hub.network') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-shield"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_net') }} <span class="free-badge new-badge">{{ __('ui.nav_new') }}</span></b><small>{{ __('ui.tb_net_d') }}</small></span>
              </a>
            </div>
          </div>

          {{-- ستون ۲: سئو و دامنه --}}
          <div class="tmega-col">
            <div class="tmega-group">
              <span class="tmega-h">{{ __('ui.tb_general') }}</span>
              @foreach(['seo', 'whois', 'ip'] as $slug)
                @if($t = $tbOther[$slug] ?? null)
                <a class="tmega-link" href="{{ lroute('tools', $slug) }}">
                  <span class="tmega-ic tool"><svg class="icon"><use href="#i-{{ $t['icon'] }}"/></svg></span>
                  <span class="tmega-tx"><b>{{ lc($t)['t'] }}</b><small>{{ lc($t)['d'] }}</small></span>
                </a>
                @endif
              @endforeach
            </div>
          </div>

          {{-- ستون ۳: پلتفرم‌ها --}}
          <div class="tmega-col">
            <div class="tmega-group">
              <span class="tmega-h">{{ __('ui.tb_platforms') }}</span>
              @foreach(['meet', 'app-builder'] as $slug)
                @if($t = $tbOther[$slug] ?? null)
                <a class="tmega-link" href="{{ lroute('tools', $slug) }}">
                  <span class="tmega-ic tool"><svg class="icon"><use href="#i-{{ $t['icon'] }}"/></svg></span>
                  <span class="tmega-tx"><b>{{ lc($t)['t'] }}</b><small>{{ lc($t)['d'] }}</small></span>
                </a>
                @endif
              @endforeach
              <a class="tmega-link" href="{{ lroute('solution', 'remote') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-monitor"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_remote') }}</b><small>{{ __('ui.tb_remote_d') }}</small></span>
              </a>
              <a class="tmega-link" href="{{ lroute('solution', 'bpmn-designer') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-flow"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_bpmn') }}</b><small>{{ __('ui.tb_bpmn_d') }}</small></span>
              </a>
              {{-- یک لینک به هاب ابزارهای وب‌مستر؛ خودِ ابزارها آنجا گروه‌بندی شده‌اند تا منو شلوغ نشود --}}
              <a class="tmega-link" href="{{ lroute('webtools.index') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-wrench"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.wt_title') }}</b><small>{{ __('ui.wt_sub') }}</small></span>
              </a>
            </div>
          </div>

        </div>
      </div>
    </div>

    {{-- ===== منوی پایگاه دانش ===== --}}
    <div class="menu-panel" id="menu-knowledge" role="region">
      <div class="container">
        <div class="drop-grid cols-3">
          @foreach($knowledgeMenu as $k)
          <a class="drop-card" href="{{ isset($k['route']) ? lroute($k['route']) : lroute('knowledge').(isset($k['anchor']) ? '#'.$k['anchor'] : '') }}">
            <span class="dc-icon know"><svg class="icon"><use href="#i-{{ $k['icon'] }}"/></svg></span>
            <span class="dc-txt"><b>{{ lc($k)['t'] }}</b><small>{{ lc($k)['d'] }}</small></span>
          </a>
          @endforeach
        </div>
      </div>
    </div>
  </header>
</div>

{{-- ===== منوی موبایل: کشوی تمام‌صفحه ===== --}}
<div class="drawer-backdrop" id="drawer-backdrop"></div>
<aside class="drawer" id="drawer" aria-label="{{ __('ui.menu') }}">
  <div class="drawer-head">
    <a href="{{ $homeUrl }}" class="logo"><span class="logo-mark"><svg class="icon"><use href="#i-server"/></svg></span> {{ $isFa ? 'سرورنت' : 'ServerNet' }}</a>
    <button class="drawer-close" id="drawer-close" aria-label="✕"><svg class="icon"><use href="#i-x"/></svg></button>
  </div>
  <div class="drawer-body">
    @foreach($mega as $key => $cat)
    <div class="acc">
      <button class="acc-head">
        <span class="dc-icon sm"><svg class="icon"><use href="#i-{{ $cat['icon'] }}"/></svg></span>
        {{ lc($cat)['t'] }}
        <svg class="icon chev"><use href="#i-chev"/></svg>
      </button>
      <div class="acc-body"><div class="acc-in">
        @foreach($cat['groups'] as $group)
        <h6>{{ lc($group) }}</h6>
        @foreach($group['items'] as $item)<a href="{{ isset($item['slug']) ? ($key === 'hosting' ? lroute('hosting', $item['slug']) : lroute('catalog', ['category' => $key, 'slug' => $item['slug']])) : '#' }}">{{ lc($item) }}</a>@endforeach
        @endforeach
      </div></div>
    </div>
    @endforeach
    <div class="acc">
      <button class="acc-head"><span class="dc-icon sm"><svg class="icon"><use href="#i-wrench"/></svg></span>{{ __('ui.nav_services') }}<svg class="icon chev"><use href="#i-chev"/></svg></button>
      <div class="acc-body"><div class="acc-in">
        @foreach($servicesMenu as $s)<a href="{{ isset($s['slug']) ? (($s['cat'] ?? null) === 'hosting' ? lroute('hosting', $s['slug']) : lroute('catalog', ['category' => 'services', 'slug' => $s['slug']])) : '#' }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-{{ $s['icon'] }}"/></svg>{{ lc($s)['t'] }}</b><small>{{ lc($s)['d'] }}</small></a>@endforeach
      </div></div>
    </div>
    <div class="acc">
      <button class="acc-head"><span class="dc-icon sm"><svg class="icon"><use href="#i-zap"/></svg></span>{{ __('ui.nav_tools') }}<span class="free-badge new-badge">{{ __('ui.nav_new') }}</span><svg class="icon chev"><use href="#i-chev"/></svg></button>
      <div class="acc-body"><div class="acc-in">
        <span class="acc-group">{{ lc($lkGroups['records']) }} · {{ lc($lkGroups['network']) }}</span>
        <a href="{{ lroute('hub.dns') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-db"/></svg>{{ __('ui.tb_dns') }}</b><small>{{ __('ui.tb_dns_d') }}</small></a>
        <a href="{{ lroute('hub.network') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-shield"/></svg>{{ __('ui.tb_net') }}</b><small>{{ __('ui.tb_net_d') }}</small></a>
        <span class="acc-group">{{ __('ui.tb_general') }}</span>
        @foreach(['seo', 'whois', 'ip'] as $slug)@if($t = $tbOther[$slug] ?? null)<a href="{{ lroute('tools', $slug) }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-{{ $t['icon'] }}"/></svg>{{ lc($t)['t'] }}</b><small>{{ lc($t)['d'] }}</small></a>@endif @endforeach
        <span class="acc-group">{{ __('ui.tb_platforms') }}</span>
        @foreach(['meet', 'app-builder'] as $slug)@if($t = $tbOther[$slug] ?? null)<a href="{{ lroute('tools', $slug) }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-{{ $t['icon'] }}"/></svg>{{ lc($t)['t'] }}</b><small>{{ lc($t)['d'] }}</small></a>@endif @endforeach
        <a href="{{ lroute('solution', 'remote') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-monitor"/></svg>{{ __('ui.tb_remote') }}</b><small>{{ __('ui.tb_remote_d') }}</small></a>
        <a href="{{ lroute('solution', 'bpmn-designer') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-flow"/></svg>{{ __('ui.tb_bpmn') }}</b><small>{{ __('ui.tb_bpmn_d') }}</small></a>
        <a href="{{ lroute('webtools.index') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-wrench"/></svg>{{ __('ui.wt_title') }}</b><small>{{ __('ui.wt_sub') }}</small></a>
      </div></div>
    </div>
    <div class="acc">
      <button class="acc-head"><span class="dc-icon sm"><svg class="icon"><use href="#i-book"/></svg></span>{{ __('ui.nav_knowledge') }}<svg class="icon chev"><use href="#i-chev"/></svg></button>
      <div class="acc-body"><div class="acc-in">
        @foreach($knowledgeMenu as $k)<a href="{{ isset($k['route']) ? lroute($k['route']) : lroute('knowledge').(isset($k['anchor']) ? '#'.$k['anchor'] : '') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-{{ $k['icon'] }}"/></svg>{{ lc($k)['t'] }}</b><small>{{ lc($k)['d'] }}</small></a>@endforeach
      </div></div>
    </div>
    <a class="drawer-link" href="{{ lroute('contact') }}"><span class="dc-icon sm"><svg class="icon"><use href="#i-message"/></svg></span>{{ __('ui.nav_contact') }}</a>
  </div>
  <div class="drawer-foot">
    @if($cust ?? null)
      <a class="btn btn-primary" href="{{ console_lroute('account.home') }}" style="width:100%;justify-content:center"><svg class="icon" style="width:16px;height:16px"><use href="#i-gauge"/></svg>{{ __('ui.nav_dash') }} — {{ \Illuminate\Support\Str::limit($cust->displayName(), 18) }}</a>
      <form method="post" action="{{ console_lroute('logout') }}" style="margin-top:8px">@csrf<button type="submit" class="btn btn-glass" style="width:100%;justify-content:center"><svg class="icon" style="width:15px;height:15px"><use href="#i-x"/></svg>{{ __('ui.auth_logout') }}</button></form>
    @else
      <a class="btn btn-primary" href="{{ console_lroute('login') }}" style="width:100%;justify-content:center"><svg class="icon" style="width:16px;height:16px"><use href="#i-user"/></svg>{{ __('ui.nav_login') }}</a>
    @endif
    <div class="drawer-meta">
      <a href="tel:{{ $contact['phone_link'] }}" dir="ltr"><svg class="icon"><use href="#i-phone"/></svg>{{ $contact['phone'] }}</a>
      <nav class="lang-links">
        <a href="{{ $localeUrls['fa'] }}" @class(['active' => $loc === 'fa'])>فارسی</a>
        <span aria-hidden="true">|</span>
        <a href="{{ $localeUrls['en'] }}" @class(['active' => $loc === 'en'])>English</a>
        <span aria-hidden="true">|</span>
        <a href="{{ $localeUrls['tr'] }}" @class(['active' => $loc === 'tr'])>Türkçe</a>
      </nav>
    </div>
  </div>
</aside>

{{-- منوی هویتِ کاربرِ واردشده (هدرِ سایت اصلی) --}}
<style>
.usr-menu{ position:relative; }
.usr-btn{ display:flex; align-items:center; gap:8px; background:transparent; border:1px solid var(--line,rgba(148,163,184,.28)); border-radius:30px; padding:5px 12px 5px 6px; cursor:pointer; color:inherit; font:inherit; transition:border-color .16s; }
.usr-btn:hover{ border-color:var(--brand,#22D3EE); }
.usr-nm{ font-size:13px; font-weight:600; max-width:120px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.usr-av{ position:relative; width:30px; height:30px; border-radius:50%; overflow:hidden; display:grid; place-items:center; flex:0 0 auto; background:linear-gradient(135deg,#22D3EE,#3b82f6); color:#04121a; font-size:12px; font-weight:800; }
.usr-av i{ font-style:normal; line-height:1; }
.usr-av img{ position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.usr-av.lg{ width:44px; height:44px; font-size:16px; }
.usr-cv{ opacity:.55; transition:transform .18s; }
.usr-menu.open .usr-cv{ transform:rotate(180deg); }
.usr-drop{ position:absolute; top:calc(100% + 10px); inset-inline-end:0; min-width:230px; background:var(--card,#0f1522); border:1px solid var(--line,rgba(148,163,184,.22)); border-radius:14px; box-shadow:0 18px 44px rgba(2,8,20,.45); padding:8px; opacity:0; visibility:hidden; transform:translateY(-6px); transition:.18s; z-index:130; }
.usr-menu.open .usr-drop{ opacity:1; visibility:visible; transform:none; }
.usr-hd{ display:flex; align-items:center; gap:11px; padding:8px 10px 12px; border-bottom:1px solid var(--line,rgba(148,163,184,.14)); margin-bottom:6px; }
.usr-hd b{ display:block; font-size:13.5px; }
.usr-hd small{ font-size:11.5px; opacity:.6; }
.usr-drop a, .usr-out button{ display:flex; align-items:center; gap:10px; width:100%; padding:9px 10px; border-radius:9px; font:inherit; font-size:13px; color:inherit; text-decoration:none; background:transparent; border:0; cursor:pointer; text-align:start; }
.usr-drop a:hover, .usr-out button:hover{ background:rgba(148,163,184,.12); }
.usr-drop .icon{ width:16px; height:16px; opacity:.7; }
.usr-out{ border-top:1px solid var(--line,rgba(148,163,184,.14)); margin-top:6px; padding-top:6px; }
.usr-out button{ color:#ff6b6b; }
</style>
<script>
(function(){
  var m=document.getElementById('usr-menu'), b=document.getElementById('usr-btn');
  if(!m||!b) return;
  b.addEventListener('click', function(e){ e.stopPropagation(); m.classList.toggle('open'); b.setAttribute('aria-expanded', m.classList.contains('open')); });
  document.addEventListener('click', function(e){ if(!m.contains(e.target)) m.classList.remove('open'); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') m.classList.remove('open'); });
})();
</script>
