<header class="cw-hero cw-hero--profile">
  <div>
    <p class="cw-eyebrow">{{ __('workspace.eyebrow') }}</p>
    <h1>{{ __('workspace.profile_title') }}</h1>
    <p class="cw-lead">{{ __('workspace.profile_lead') }}</p>
  </div>
  <div class="cw-orbit" aria-hidden="true"><svg class="icon"><use href="#i-user"/></svg></div>
</header>
<nav class="cw-tabs" aria-label="{{ __('workspace.profile_title') }}">
  <a href="{{ lroute('account.profile') }}" @if(($profileTab ?? 'profile') === 'profile') aria-current="page" @endif><svg class="icon" aria-hidden="true"><use href="#i-user"/></svg>{{ __('workspace.profile_tab') }}</a>
  <a href="{{ lroute('account.profile', ['tab' => 'security']) }}" @if(($profileTab ?? '') === 'security') aria-current="page" @endif><svg class="icon" aria-hidden="true"><use href="#i-shield"/></svg>{{ __('workspace.security_tab') }}</a>
</nav>
