@php
    $loc = app()->getLocale();
    $mega = config('servernet.mega');
    // ⚠️ از `MenuManager` و نه مستقیم از config: رویهٔ مدیر (خاموشی، متنِ
    //    سه‌زبانه، ترتیب، لینکِ افزوده) این‌جا اعمال می‌شود. عمداً `config()`
    //    بازنویسی نمی‌شود چون `pages/knowledge` به `knowledge_menu[0..3]`
    //    دسترسیِ **موقعیتی** دارد و جابه‌جایی در پنل آن صفحه را می‌شکست.
    $mm = app(\App\Services\MenuManager::class);
    $servicesMenu = $mm->flat('services');
    $toolsMenu = $mm->flat('tools');
    $knowledgeMenu = $mm->flat('knowledge');
@endphp
<div class="site-header-wrap" id="header-wrap">
  {{-- ══════ نوارِ «جای مشتری نشسته‌اید» (impersonation) ══════
       عمداً **داخلِ** .site-header-wrap و اولین فرزندِ آن است. آن wrap خودش
       position:fixed;top:0;z-index:200 دارد (site.css:289)، پس نوار:
         · همیشه روی صفحه است (اسکرول هرچقدر برود),
         · هیچ‌چیز رویش نمی‌افتد ⇒ همیشه کلیک‌پذیر,
         · و چون هدر را از پایین جلو می‌راند، با body.imp-on{padding-top}
           هیچ محتوایی زیرش پنهان نمی‌ماند.

       🔴 باگی که این جایگزینش کرد: نوار قبلاً داخلِ بخشِ content (یعنی
       داخلِ تگِ main) با position:sticky;top:0;z-index:120 بود — دقیقاً همان
       مستطیلی که هدرِ ثابت اشغال می‌کند، و چون ۱۲۰ < ۲۰۰ هدر رویش می‌افتاد و
       کلیک را می‌بلعید. نتیجه: مدیر بعد از ورود به حسابِ مشتری راهِ بازگشت
       نداشت. ⚠️ فقط z-index را زیاد نکن — آن‌وقت نوار روی لوگو و منو می‌افتد.

       dir="rtl" روی خودِ نوار: متنش فارسی است و در صفحاتِ en/tr هم باید درست
       بنشیند. --}}
  @if($impBar ?? false)
  <div class="imp-bar" dir="rtl">
    <div class="container imp-in">
      <span class="imp-tx">
        <svg class="icon" aria-hidden="true"><use href="#i-shield"/></svg>
        <span class="imp-lb">نمایِ مشتری —</span>
        <b class="imp-nm">{{ $impCust->displayName() }}</b>
        <span class="imp-code" dir="ltr">{{ $impCust->code }}</span>
      </span>
      {{-- ⚠️ نشانیِ **مطلق** روی کنسول وقتی این هدر روی دامنهٔ اصلی رندر شده.
           چرایش کاملاً در `ConsoleHost::panelUrl()` نوشته شده؛ خلاصه: این نوار
           در هدرِ سایتِ اصلی است، پس با `action` نسبی یک کنشِ مدیریتی به میزبانِ
           بازاریابی پست می‌شد. --}}
      <form method="post" action="{{ \App\Http\Middleware\ConsoleHost::panelUrl('/admin/impersonate/stop') }}" class="imp-f">@csrf
        <button type="submit" class="imp-out">
          <svg class="icon" aria-hidden="true"><use href="#i-x"/></svg>
          <span class="imp-out-lg">بازگشت به پنل مدیریت</span>
          <span class="imp-out-sm">بازگشت</span>
        </button>
      </form>
    </div>
  </div>
  @endif

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
              <svg class="icon usr-cv" aria-hidden="true"><use href="#i-chev"/></svg>
            </button>
            <div class="usr-drop" role="menu">
              <div class="usr-hd">
                <span class="usr-av lg"><i>{{ $uinit }}</i>@if($uav)<img src="{{ $uav }}" alt="" referrerpolicy="no-referrer" onerror="this.remove()">@endif</span>
                <div class="usr-hd-tx"><b>{{ $cust->displayName() }}</b><small dir="ltr">{{ $cust->code }}</small></div>
              </div>
              <a href="{{ console_lroute('account.home') }}" role="menuitem"><svg class="icon"><use href="#i-gauge"/></svg>{{ __('ui.nav_dash') }}</a>
              <a href="{{ console_lroute('account.services') }}" role="menuitem"><svg class="icon"><use href="#i-server"/></svg>{{ __('ui.nav_services') }}</a>
              <a href="{{ console_lroute('account.invoices') }}" role="menuitem"><svg class="icon"><use href="#i-coins"/></svg>{{ __('ui.nav_invoices') }}</a>
              <form method="post" action="{{ console_lroute('logout') }}" class="usr-out">@csrf<button type="submit" role="menuitem"><svg class="icon"><use href="#i-x"/></svg>{{ __('ui.auth_logout') }}</button></form>
            </div>
          </div>
        @else
          <a class="login-btn" href="{{ console_lroute('login') }}"><svg class="icon" style="width:16px;height:16px"><use href="#i-user"/></svg><span>{{ __('ui.nav_login') }}</span></a>
        @endif
        <button class="hamburger" id="hamburger" aria-label="{{ __('ui.menu') }}" aria-expanded="false"><span class="hb-bar hb-bar-1" aria-hidden="true"></span><span class="hb-bar hb-bar-2" aria-hidden="true"></span><span class="hb-bar hb-bar-3" aria-hidden="true"></span><span class="hb-node" aria-hidden="true"></span></button>
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
            {{-- گروهِ 'wide' تمامِ عرضِ پنل را می‌گیرد و آیتم‌هایش دوردیفه می‌چینند
                 (موقعیت‌های مکانی زیاد شده‌اند و یک ستونِ کشیده بدنما بود). --}}
            <div class="mega-group @if(!empty($group['wide'])) wide @endif">
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

    {{-- ===== منوی ابزارهای تخصصی (مگامنوی گروه‌بندی‌شده) =====
         توازن عمدی: هر سه ستون دقیقاً ۶ آیتم (خواسته‌ی صریح کارفرما، مرداد ۱۴۰۵).
         ToolsMenuBalanceTest این را قفل می‌کند — آیتم اضافه/کم یعنی تست قرمز. --}}
    @php
      $tbOther = collect($toolsMenu)->keyBy(fn ($t) => $t['slug'] ?? '');
    @endphp
    <div class="menu-panel" id="menu-tools" role="region">
      <div class="container">
        <div class="tools-mega">

          {{-- ستون ۱: بررسی و عیب‌یابی (ابزارهای جامع + تشخیصی) --}}
          <div class="tmega-col">
            <div class="tmega-group">
              <span class="tmega-h">{{ __('ui.tb_diag') }}</span>
              <a class="tmega-link" href="{{ lroute('hub.dns') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-db"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_dns') }}</b><small>{{ __('ui.tb_dns_d') }}</small></span>
              </a>
              <a class="tmega-link" href="{{ lroute('hub.network') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-shield"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_net') }}</b><small>{{ __('ui.tb_net_d') }}</small></span>
              </a>
              <a class="tmega-link" href="{{ lroute('lookup', 'email') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-mail"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_email') }} <span class="free-badge new-badge">{{ __('ui.nav_new') }}</span></b><small>{{ __('ui.tb_email_d') }}</small></span>
              </a>
              <a class="tmega-link" href="{{ lroute('lookup', 'blacklist') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-lock"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_bl') }}</b><small>{{ __('ui.tb_bl_d') }}</small></span>
              </a>
              <a class="tmega-link" href="{{ lroute('lookup', 'global-ping') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-globe"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_gping') }} <span class="free-badge new-badge">{{ __('ui.nav_new') }}</span></b><small>{{ __('ui.tb_gping_d') }}</small></span>
              </a>
              <a class="tmega-link" href="{{ lroute('tools', 'speedtest') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-zap"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_spt') }} <span class="free-badge new-badge">{{ __('ui.nav_new') }}</span></b><small>{{ __('ui.tb_spt_d') }}</small></span>
              </a>
            </div>
          </div>

          {{-- ستون ۲: سئو و دامنه --}}
          <div class="tmega-col">
            <div class="tmega-group">
              <span class="tmega-h">{{ __('ui.tb_general') }}</span>
              @foreach(['seo', 'whois', 'ip', 'domain-ideas'] as $slug)
                @if($t = $tbOther[$slug] ?? null)
                <a class="tmega-link" href="{{ lroute('tools', $slug) }}">
                  <span class="tmega-ic tool"><svg class="icon"><use href="#i-{{ $t['icon'] }}"/></svg></span>
                  <span class="tmega-tx"><b>{{ lc($t)['t'] }}@if($slug === 'domain-ideas') <span class="free-badge new-badge">{{ __('ui.nav_new') }}</span>@endif</b><small>{{ lc($t)['d'] }}</small></span>
                </a>
                @endif
              @endforeach
              <a class="tmega-link" href="{{ lroute('lookup', 'global-http') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-monitor"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_ghttp') }} <span class="free-badge new-badge">{{ __('ui.nav_new') }}</span></b><small>{{ __('ui.tb_ghttp_d') }}</small></span>
              </a>
              <a class="tmega-link" href="{{ lroute('lookup', 'pagespeed') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-gauge"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_cwv') }} <span class="free-badge new-badge">{{ __('ui.nav_new') }}</span></b><small>{{ __('ui.tb_cwv_d') }}</small></span>
              </a>
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
              {{--
                «سرورنت ریموت» محصولِ **خودمان** است، نه یکی از ابزارهای عمومی —
                و رایگان، پس بهترین قلابِ ورودِ سایت است. برجستگیِ `is-feature`
                برای همین است: در میانِ نُه آیتمِ این ستون گم نشود.

                🔴 دکمهٔ «دانلود مستقیم» عمداً این‌جا **نیست** و برنگردد.
                منو جای **رفتن** است نه جای **گرفتنِ فایل**: یک آیتمِ منو که
                به‌جای باز کردنِ صفحه یک ZIP دانلود می‌کند، هم از بقیهٔ آیتم‌ها
                ناهمگون درمی‌آید (یک دکمهٔ آبیِ اضافه زیرِ یک ردیف، در ستونی که
                همه‌اش ردیفِ یک‌شکل است) و هم رفتارش برای کاربر غیرمنتظره است.
                ضمناً همان دکمه هر رندرِ **هر صفحهٔ سایت** را به `RemoteRelease`
                گره می‌زد تا فقط بفهمد باید نشان داده شود یا نه.

                جای درستِ دانلود، بخشِ دانلودِ خودِ `/solutions/remote` است که
                هر چهار سکو و شمارهٔ نسخه را با هم نشان می‌دهد.
              --}}
              <a class="tmega-link is-feature" href="{{ lroute('solution', 'remote') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-monitor"/></svg></span>
                <span class="tmega-tx">
                  <b>{{ __('ui.tb_remote') }}<span class="tmega-pill">{{ __('ui.nav_free') }}</span></b>
                  <small>{{ __('ui.tb_remote_d') }}</small>
                </span>
              </a>
              <a class="tmega-link" href="{{ lroute('solution', 'bpmn-designer') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-flow"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.tb_bpmn') }}</b><small>{{ __('ui.tb_bpmn_d') }}</small></span>
              </a>
              {{-- هابِ راهکارها: والدِ موضوعیِ صفحاتِ راهکار. از منو لینک می‌شود
                   تا از هر صفحهٔ سایت یک کلیک فاصله داشته باشد. --}}
              <a class="tmega-link" href="{{ lroute('solutions.index') }}">
                <span class="tmega-ic tool"><svg class="icon"><use href="#i-box"/></svg></span>
                <span class="tmega-tx"><b>{{ __('ui.sol_h1') }}</b><small>{{ __('ui.f_s_all') }}</small></span>
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
    <button class="drawer-close" id="drawer-close" aria-label="{{ __('ui.close') }}"><svg class="icon"><use href="#i-x"/></svg></button>
  </div>
  {{-- کارتِ هویتِ کاربرِ واردشده — بالای کشو، لینک مستقیم به پنل (RTL-first) --}}
  @if($cust ?? null)
    @php $duinit = initials($cust->displayName(), $cust->email); $duav = avatar_url($cust->email); @endphp
    <a class="drawer-id" href="{{ console_lroute('account.home') }}" aria-label="{{ $cust->displayName() }} — {{ __('ui.nav_dash') }}">
      <span class="drawer-id-top">
        <span class="usr-av xl"><i>{{ $duinit }}</i>@if($duav)<img src="{{ $duav }}" alt="" referrerpolicy="no-referrer" onerror="this.remove()">@endif</span>
        <span class="drawer-id-meta">
          <b class="drawer-id-name">{{ \Illuminate\Support\Str::limit($cust->displayName(), 22) }}</b>
          <span class="drawer-id-code" dir="ltr">{{ $cust->code }}</span>
        </span>
      </span>
      <span class="drawer-id-cta">
        <svg class="icon"><use href="#i-gauge"/></svg>
        <span>{{ __('ui.nav_dash') }}</span>
        <svg class="icon drawer-id-chev"><use href="#i-chev"/></svg>
      </span>
    </a>
  @endif
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
        @foreach($group['items'] as $item)<a href="{{ isset($item['route']) ? lroute($item['route'][0], $item['route'][1] ?? []) : (isset($item['slug']) ? ($key === 'hosting' ? lroute('hosting', $item['slug']) : lroute('catalog', ['category' => $key, 'slug' => $item['slug']])) : '#') }}">{{ lc($item) }}</a>@endforeach
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
        <span class="acc-group">{{ __('ui.tb_diag') }}</span>
        <a href="{{ lroute('hub.dns') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-db"/></svg>{{ __('ui.tb_dns') }}</b><small>{{ __('ui.tb_dns_d') }}</small></a>
        <a href="{{ lroute('hub.network') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-shield"/></svg>{{ __('ui.tb_net') }}</b><small>{{ __('ui.tb_net_d') }}</small></a>
        <a href="{{ lroute('lookup', 'email') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-mail"/></svg>{{ __('ui.tb_email') }}</b><small>{{ __('ui.tb_email_d') }}</small></a>
        <a href="{{ lroute('lookup', 'blacklist') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-lock"/></svg>{{ __('ui.tb_bl') }}</b><small>{{ __('ui.tb_bl_d') }}</small></a>
        <a href="{{ lroute('lookup', 'global-ping') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-globe"/></svg>{{ __('ui.tb_gping') }}</b><small>{{ __('ui.tb_gping_d') }}</small></a>
        <a href="{{ lroute('tools', 'speedtest') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-zap"/></svg>{{ __('ui.tb_spt') }}</b><small>{{ __('ui.tb_spt_d') }}</small></a>
        <span class="acc-group">{{ __('ui.tb_general') }}</span>
        @foreach(['seo', 'whois', 'ip', 'domain-ideas'] as $slug)@if($t = $tbOther[$slug] ?? null)<a href="{{ lroute('tools', $slug) }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-{{ $t['icon'] }}"/></svg>{{ lc($t)['t'] }}</b><small>{{ lc($t)['d'] }}</small></a>@endif @endforeach
        <a href="{{ lroute('lookup', 'global-http') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-monitor"/></svg>{{ __('ui.tb_ghttp') }}</b><small>{{ __('ui.tb_ghttp_d') }}</small></a>
        <a href="{{ lroute('lookup', 'pagespeed') }}"><b><svg class="icon" style="width:14px;height:14px"><use href="#i-gauge"/></svg>{{ __('ui.tb_cwv') }}</b><small>{{ __('ui.tb_cwv_d') }}</small></a>
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
      <form method="post" action="{{ console_lroute('logout') }}">@csrf<button type="submit" class="btn btn-glass" style="width:100%;justify-content:center"><svg class="icon" style="width:15px;height:15px"><use href="#i-x"/></svg>{{ __('ui.auth_logout') }}</button></form>
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

{{-- استایلِ منوی هویت/همبرگر/کارتِ کشو در public/assets/css/site.css («Aperture Node»)
     است — تم‌آگاه (روشن/تاریک) و RTL. استایلِ inline قبلی که var(--card) نامعتبر
     داشت و در حالتِ روشن ناخوانا می‌شد، حذف شد. --}}
<script>
(function(){
  var m=document.getElementById('usr-menu'), b=document.getElementById('usr-btn');
  if(!m||!b) return;
  b.addEventListener('click', function(e){ e.stopPropagation(); m.classList.toggle('open'); b.setAttribute('aria-expanded', m.classList.contains('open')); });
  document.addEventListener('click', function(e){ if(!m.contains(e.target)) m.classList.remove('open'); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') m.classList.remove('open'); });
})();
</script>
