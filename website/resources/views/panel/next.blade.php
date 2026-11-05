@php
    $cnLocale = app()->getLocale();
    $cnCopy = trans('console_next');
    $cnCustomer = auth('customer')->user();
    $cnUser = $pnlUser ?? [];
    $cnNav = $pnlNav ?? [];
    $cnActive = $pnlActive ?? '';
    $cnLegacyUrl = \App\Support\ConsoleNext::legacyUrl(request());
    $cnPageTitle = trim(strip_tags($__env->yieldContent('title'))) ?: $cnCopy['workspace'];
    $cnImpCustomer = session(\App\Http\Controllers\Admin\ImpersonateController::SESSION_KEY) ? $cnCustomer : null;
    $cnText = static function (array $item): string {
        $text = lc($item);
        return is_array($text) ? (string) ($text['t'] ?? '') : (string) $text;
    };
    $cnPublicUrl = static function (string $href): ?string {
        if ($href === '' || $href === '#' || str_starts_with($href, '//')) return null;
        $parts = parse_url($href);
        if ($parts === false || (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true))) return null;
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== '' && !in_array($host, [strtolower(request()->getHost()), 'servernet.cloud', 'www.servernet.cloud', 'console.servernet.cloud'], true)) return $href;
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
        return \App\Http\Middleware\ConsoleHost::siteUrl($path);
    };
    $cnResolve = static function (array $item, string $category = 'services') use ($cnPublicUrl): ?string {
        try {
            if (!empty($item['href']) || !empty($item['url'])) return $cnPublicUrl((string) ($item['href'] ?? $item['url']));
            if (!empty($item['route'])) {
                $routeSpec = is_array($item['route']) ? $item['route'] : [$item['route']];
                return $cnPublicUrl(lroute($routeSpec[0], $routeSpec[1] ?? []));
            }
            if (!empty($item['slug'])) return $cnPublicUrl($category === 'hosting' ? lroute('hosting', $item['slug']) : lroute('catalog', ['category'=>$category, 'slug'=>$item['slug']]));
        } catch (\Throwable) { return null; }
        return null;
    };
    $cnMenus = [];
    $cnMenuManager = app(\App\Services\MenuManager::class);
    try { $cnMega = $cnMenuManager->mega(app(\App\Services\SiteMenu::class)->mega()); }
    catch (\Throwable) { $cnMega = (array) config('servernet.mega', []); }
    $cnProducts = [];
    foreach ($cnMega as $category => $categoryData) {
        foreach (($categoryData['groups'] ?? []) as $categoryGroup) {
            foreach (($categoryGroup['items'] ?? []) as $productItem) {
                $productHref = $cnResolve($productItem, (string) $category);
                $productLabel = $cnText($productItem);
                if ($productHref && $productLabel !== '') $cnProducts[] = ['url'=>$productHref, 'label'=>$productLabel, 'icon'=>$categoryData['icon'] ?? 'server'];
            }
        }
    }
    $cnMenus['products'] = ['label'=>__('ui.nav_products'), 'items'=>$cnProducts];
    foreach (['services'=>'nav_services', 'tools'=>'nav_tools', 'knowledge'=>'nav_knowledge'] as $menuKey => $labelKey) {
        $menuItems = [];
        foreach ($cnMenuManager->flat($menuKey) as $menuItem) {
            $menuHref = $cnResolve($menuItem, (string) ($menuItem['cat'] ?? 'services'));
            $menuLabel = $cnText($menuItem);
            if ($menuHref && $menuLabel !== '') $menuItems[] = ['url'=>$menuHref, 'label'=>$menuLabel, 'icon'=>$menuItem['icon'] ?? 'link'];
        }
        $cnMenus[$menuKey] = ['label'=>__('ui.'.$labelKey), 'items'=>$menuItems];
    }
    $cnContactUrl = $cnPublicUrl(lroute('contact'));
    $cnHomeUrl = $cnPublicUrl(lroute('home'));
@endphp
<!doctype html>
<html lang="{{ $cnLocale }}" dir="{{ $cnLocale === 'fa' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="theme-color" content="#080e1a">
    <title>{{ $cnPageTitle }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="preload" href="{{ asset('assets/font/woff2/IRANSans-web.woff2') }}" as="font" type="font/woff2" crossorigin>
    <script>(function(){try{var m=document.cookie.match(/(?:^|;\s*)snet-theme=(light|dark)/);var t=m?m[1]:localStorage.getItem('snet-theme');if(t==='light')document.documentElement.dataset.theme='light';}catch(e){}})();</script>
    <link rel="stylesheet" href="{{ asset_ver('assets/css/site.css') }}">
    @stack('head')
    <link rel="stylesheet" href="{{ asset_ver('assets/css/console-next.css') }}">
    <script src="{{ asset_ver('assets/js/console-next.js') }}" defer></script>
    <script src="{{ asset_ver('assets/js/console-next-network.js') }}" defer></script>
</head>
<body class="console-next" data-cn-drawer="closed">
<a href="#cn-main" class="cn-skip-link">{{ __('ui.skip') }}</a>
@include('partials.icons')
@if($cnImpCustomer)
<div class="cn-imp-bar imp-bar" dir="{{ $cnLocale === 'fa' ? 'rtl' : 'ltr' }}">
    <div class="cn-imp-content">
        <span>{{ __('activity.customer_view') }} <b>{{ $cnImpCustomer->displayName() }}</b> <bdi>{{ $cnImpCustomer->code }}</bdi></span>
        <form method="post" action="{{ \App\Http\Middleware\ConsoleHost::panelUrl('/admin/impersonate/stop') }}">@csrf<button class="cn-button" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg>{{ __('activity.return_admin') }}</button></form>
    </div>
</div>
@endif
<header class="cn-header" data-cn-background>
    <div class="cn-header-inner">
        <a class="cn-brand" href="{{ $cnHomeUrl }}"><span class="cn-brand-mark"><svg class="icon" aria-hidden="true"><use href="#i-server"/></svg></span><span><b>{{ $cnLocale === 'fa' ? 'سرورنت' : 'ServerNet' }}</b><small>SERVERNET CLOUD</small></span></a>
        <nav class="cn-site-nav" aria-label="{{ $cnCopy['site_navigation'] }}">
            @foreach($cnMenus as $menuKey=>$menu)
            @if(count($menu['items']))
            <details class="cn-site-dropdown" data-cn-menu>
                <summary class="cn-site-link">{{ $menu['label'] }}<svg class="icon" aria-hidden="true"><use href="#i-chev"/></svg></summary>
                <div class="cn-popover" aria-label="{{ $menu['label'] }}"><div class="cn-menu-grid">@foreach($menu['items'] as $item)<a class="cn-site-menu-link" href="{{ $item['url'] }}"><svg class="icon" aria-hidden="true"><use href="#i-{{ $item['icon'] }}"/></svg><span>{{ $item['label'] }}</span></a>@endforeach</div></div>
            </details>
            @endif
            @endforeach
            <a class="cn-site-link" href="{{ $cnContactUrl }}">{{ __('ui.nav_contact') }}</a>
        </nav>
        <div class="cn-header-actions">
            <button class="cn-icon-button" type="button" data-cn-theme aria-label="{{ $cnCopy['theme'] }}"><svg class="icon" aria-hidden="true"><use href="#i-sun"/></svg></button>
            <details class="cn-user-menu" data-cn-menu>
                <summary class="cn-user-trigger" aria-label="{{ $cnCopy['account_menu'] }}"><span class="cn-avatar">@include('panel.avatar', ['user'=>$cnUser])</span><svg class="icon" aria-hidden="true"><use href="#i-chev"/></svg></summary>
                <div class="cn-popover">
                    <b>{{ $cnUser['name'] ?? '' }}</b><small><bdi>{{ $cnUser['code'] ?? '' }}</bdi></small>
                    <a href="{{ lroute('account.profile') }}"><svg class="icon" aria-hidden="true"><use href="#i-user"/></svg>{{ __('ui.nav_profile') }}</a>
                    <a href="{{ lroute('account.security') }}"><svg class="icon" aria-hidden="true"><use href="#i-shield"/></svg>{{ __('ui.nav_security') }}</a>
                    <div class="cn-language-links" aria-label="{{ $cnCopy['language'] }}">@foreach(['fa'=>'فارسی','en'=>'English','tr'=>'Türkçe'] as $language=>$languageName)<a href="{{ $localeUrls[$language] }}" lang="{{ $language }}" @if($cnLocale===$language) aria-current="true" @endif>{{ $languageName }}</a>@endforeach</div>
                    <a class="cn-old-link" href="{{ $cnLegacyUrl }}">{{ $cnCopy['old_console'] }}<svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></a>
                    <form method="post" action="{{ lroute('logout') }}">@csrf<button type="submit" class="cn-button"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg>{{ __('ui.auth_logout') }}</button></form>
                </div>
            </details>
            <button class="cn-icon-button cn-menu-toggle" type="button" data-cn-open-drawer aria-label="{{ __('ui.menu') }}" aria-controls="cn-sidebar" aria-expanded="false"><svg class="icon" aria-hidden="true"><use href="#i-list"/></svg></button>
        </div>
    </div>
</header>
<div class="cn-app">
    <aside class="cn-sidebar" id="cn-sidebar" tabindex="-1" aria-label="{{ $cnCopy['workspace_navigation'] }}">
        <button class="cn-icon-button cn-drawer-close" type="button" data-cn-close-drawer aria-label="{{ __('ui.close') }}"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></button>
        <div class="cn-workspace-identity"><span class="cn-avatar">@include('panel.avatar', ['user'=>$cnUser])</span><div><b>{{ $cnUser['name'] ?? '' }}</b><small><bdi>{{ $cnUser['code'] ?? '' }}</bdi></small></div></div>
        <nav class="cn-sidebar-nav" aria-label="{{ $cnCopy['workspace_navigation'] }}">
            @foreach($cnNav as $group)
            <div class="cn-nav-group">
                @if(!empty($group['label']))<div class="cn-nav-label">{{ $group['label'] }}</div>@endif
                @foreach($group['items'] as $item)<a class="cn-nav-link @if(($item['key']??'')===$cnActive) is-active @endif" href="{{ $item['url'] }}" @if(($item['key']??'')===$cnActive) aria-current="page" @endif data-cn-searchable><svg class="icon" aria-hidden="true"><use href="#i-{{ $item['icon'] }}"/></svg><span>{{ $item['label'] }}</span>@if(!empty($item['badge']))<em>{{ fa_num($item['badge']) }}</em>@endif</a>@endforeach
            </div>
            @endforeach
            <div class="cn-drawer-site-links"><div class="cn-nav-label">{{ $cnCopy['site_navigation'] }}</div>
                @foreach($cnMenus as $menuKey=>$menu)
                @if(count($menu['items']))<details class="cn-drawer-site-group"><summary>{{ $menu['label'] }}<svg class="icon" aria-hidden="true"><use href="#i-chev"/></svg></summary><div>@foreach($menu['items'] as $item)<a href="{{ $item['url'] }}">{{ $item['label'] }}</a>@endforeach</div></details>@endif
                @endforeach
                <a class="cn-nav-link" href="{{ $cnContactUrl }}"><svg class="icon" aria-hidden="true"><use href="#i-phone"/></svg><span>{{ __('ui.nav_contact') }}</span></a>
            </div>
        </nav>
        <div class="cn-side-footer"><a class="cn-old-link" href="{{ $cnLegacyUrl }}">{{ $cnCopy['old_console'] }}<svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></a><form method="post" action="{{ lroute('logout') }}">@csrf<button class="cn-button" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg>{{ __('ui.auth_logout') }}</button></form></div>
    </aside>
    <button class="cn-drawer-backdrop" type="button" data-cn-close-drawer aria-label="{{ __('ui.close') }}" tabindex="-1" hidden></button>
    <div class="cn-workspace" data-cn-background>
        <div class="cn-topbar"><div class="cn-breadcrumb"><span>{{ $cnCopy['workspace'] }}</span><svg class="icon" aria-hidden="true"><use href="#i-chev"/></svg><b>{{ $cnPageTitle }}</b></div><button class="cn-search-trigger" type="button" data-cn-open-search aria-label="{{ $cnCopy['search'] }}"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg><span>{{ $cnCopy['search'] }}</span><kbd>⌘ K</kbd></button></div>
        <main class="cn-main pnl-main" id="cn-main" tabindex="-1">
            <div class="cn-workspace-head @if(!in_array($cnActive,['dash','hosting','services','servers'])) is-compact @endif"><div><span class="cn-eyebrow">SERVERNET / {{ $cnCopy['new_console'] }}</span><p class="cn-title">{{ $cnCopy['workspace_intro'] }}</p><p class="cn-workspace-note">{{ $cnCopy['account_note'] }}</p></div>@if(in_array($cnActive,['dash','hosting','services','servers']))<div class="cn-globe" aria-hidden="true"><canvas data-network-globe data-theme-attribute="data-theme"></canvas><small>SERVERNET GLOBAL EDGE</small></div>@endif</div>
            @include('account.partials.birthday-notice')
            @yield('panel')
            <footer class="cn-footer"><span>ServerNet Cloud</span><a href="{{ $cnLegacyUrl }}">{{ $cnCopy['old_console'] }}</a></footer>
        </main>
    </div>
    <nav class="cn-mobile-nav" aria-label="{{ $cnCopy['workspace_navigation'] }}">
        @foreach(['dash'=>['account.home','gauge',__('ui.nav_dash')], 'hosting'=>['account.hosting','hdd',__('ui.nav_hosting')], 'tickets'=>['account.tickets','lifebuoy',$cnCopy['support_short']]] as $key=>[$routeName,$icon,$label])<a href="{{ lroute($routeName) }}" @if($cnActive===$key) aria-current="page" @endif><svg class="icon" aria-hidden="true"><use href="#i-{{ $icon }}"/></svg><span>{{ $label }}</span></a>@endforeach
        <button type="button" data-cn-open-drawer aria-controls="cn-sidebar" aria-expanded="false"><svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg><span>{{ $cnCopy['more'] }}</span></button>
    </nav>
</div>
<dialog class="cn-search-dialog" id="cn-search-dialog" aria-labelledby="cn-search-title"><header class="cn-search-head"><h2 id="cn-search-title">{{ $cnCopy['search'] }}</h2><button class="cn-icon-button" type="button" data-cn-close-search aria-label="{{ __('ui.close') }}"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg></button></header><label class="cn-search-input"><span class="sr-only">{{ $cnCopy['search'] }}</span><input type="search" autocomplete="off" data-cn-search-input placeholder="{{ $cnCopy['search_placeholder'] }}"></label><div class="cn-search-results" data-cn-search-results></div><p class="cn-search-empty" data-cn-search-empty hidden>{{ $cnCopy['search_empty'] }}</p></dialog>
@include('partials.chat')
@include('partials.ui-dialog')
<script src="{{ asset_ver('assets/js/site-select.js') }}" defer></script>
@stack('scripts')
</body>
</html>
