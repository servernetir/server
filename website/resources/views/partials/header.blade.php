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
          <button class="nav-link" aria-expanded="false">{{ __('ui.nav_tools') }}<span class="free-badge">{{ __('ui.nav_free') }}</span><svg class="icon chev"><use href="#i-chev"/></svg></button>
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
        <a class="login-btn" href="{{ whmcs_url('clientarea.php') }}"><svg class="icon" style="width:16px;height:16px"><use href="#i-user"/></svg><span>{{ __('ui.nav_login') }}</span></a>
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
              <a href="{{ isset($item['slug']) ? ($key === 'hosting' ? lroute('hosting', $item['slug']) : lroute('catalog', ['category' => $key, 'slug' => $item['slug']])) : '#' }}">{{ lc($item) }}</a>
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

    {{-- ===== منوی ابزارهای رایگان ===== --}}
    <div class="menu-panel" id="menu-tools" role="region">
      <div class="container">
        <div class="drop-grid cols-3">
          @foreach($toolsMenu as $t)
          <a class="drop-card" href="{{ isset($t['slug']) ? lroute('tools', $t['slug']) : '#' }}">
            <span class="dc-icon tool"><svg class="icon"><use href="#i-{{ $t['icon'] }}"/></svg></span>
            <span class="dc-txt"><b>{{ lc($t)['t'] }} <span class="free-badge">{{ __('ui.nav_free') }}</span></b><small>{{ lc($t)['d'] }}</small></span>
          </a>
          @endforeach
        </div>
      </div>
    </div>

    {{-- ===== منوی پایگاه دانش ===== --}}
    <div class="menu-panel" id="menu-knowledge" role="region">
      <div class="container">
        <div class="drop-grid cols-3">
          @foreach($knowledgeMenu as $k)
          <a class="drop-card" href="{{ lroute('knowledge').(isset($k['anchor']) ? '#'.$k['anchor'] : '') }}">
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
      <button class="acc-head"><span class="dc-icon sm"><svg class="icon"><use href="#i-zap"/></svg></span>{{ __('ui.nav_tools') }}<span class="free-badge">{{ __('ui.nav_free') }}</span><svg class="icon chev"><use href="#i-chev"/></svg></button>
      <div class="acc-body"><div class="acc-in">
        @foreach($toolsMenu as $t)<a href="{{ isset($t['slug']) ? lroute('tools', $t['slug']) : '#' }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-{{ $t['icon'] }}"/></svg>{{ lc($t)['t'] }}</b><small>{{ lc($t)['d'] }}</small></a>@endforeach
      </div></div>
    </div>
    <div class="acc">
      <button class="acc-head"><span class="dc-icon sm"><svg class="icon"><use href="#i-book"/></svg></span>{{ __('ui.nav_knowledge') }}<svg class="icon chev"><use href="#i-chev"/></svg></button>
      <div class="acc-body"><div class="acc-in">
        @foreach($knowledgeMenu as $k)<a href="{{ lroute('knowledge').(isset($k['anchor']) ? '#'.$k['anchor'] : '') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-{{ $k['icon'] }}"/></svg>{{ lc($k)['t'] }}</b><small>{{ lc($k)['d'] }}</small></a>@endforeach
      </div></div>
    </div>
    <a class="drawer-link" href="{{ lroute('contact') }}"><span class="dc-icon sm"><svg class="icon"><use href="#i-message"/></svg></span>{{ __('ui.nav_contact') }}</a>
  </div>
  <div class="drawer-foot">
    <a class="btn btn-primary" href="{{ whmcs_url('clientarea.php') }}" style="width:100%;justify-content:center"><svg class="icon" style="width:16px;height:16px"><use href="#i-user"/></svg>{{ __('ui.nav_login') }}</a>
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
