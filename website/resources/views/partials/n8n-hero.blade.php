<header class="n8n-hero {{ $n8nConsole ? 'n8n-hero--console' : '' }}">
  <div class="n8n-hero-copy">
    <span class="n8n-eyebrow"><img src="{{ asset_ver('assets/apps/n8n.svg') }}" alt="" width="24" height="24">{{ __('n8n.eyebrow') }}</span>
    <h1>{{ __('n8n.hero_title') }}<span>{{ __('n8n.hero_accent') }}</span></h1>
    <p>{{ __('n8n.intro') }}</p>
    <div class="n8n-hero-tags"><span><svg class="icon" aria-hidden="true"><use href="#i-server"/></svg>{{ __('n8n.dedicated') }}</span><span><svg class="icon" aria-hidden="true"><use href="#i-shield"/></svg>HTTPS</span><span><svg class="icon" aria-hidden="true"><use href="#i-flow"/></svg>{{ __('n8n.automatic') }}</span></div>
  </div>
  <div class="n8n-canvas" aria-hidden="true">
    <div class="n8n-orbit n8n-orbit--outer"></div><div class="n8n-orbit n8n-orbit--inner"></div>
    <div class="n8n-center"><img src="{{ asset_ver('assets/apps/n8n.svg') }}" alt="" width="74" height="74"><span>n8n</span></div>
    <span class="n8n-canvas-node n8n-canvas-node--api"><svg class="icon"><use href="#i-code"/></svg>API</span>
    <span class="n8n-canvas-node n8n-canvas-node--ai"><svg class="icon"><use href="#i-sparkles"/></svg>AI</span>
    <span class="n8n-canvas-node n8n-canvas-node--data"><svg class="icon"><use href="#i-db"/></svg>Data</span>
    <span class="n8n-canvas-node n8n-canvas-node--done"><svg class="icon"><use href="#i-check"/></svg>{{ __('n8n.automatic') }}</span>
  </div>
</header>
