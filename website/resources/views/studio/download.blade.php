@extends('layouts.site')
@section('title', __('studio_download.title').' — ServerNet')
@section('description', __('studio_download.description'))
@if(!$release)@section('noindex', '1')@endif

@push('head')
<style>
.studio-download {
  --sd-ink: #eef4fb;
  --sd-muted: #b2c0d4;
  --sd-line: #263852;
  --sd-surface: #101b2e;
  --sd-panel: #0c1526;
  --sd-accent: #a7e1fa;
  --sd-accent-ink: #0b2033;
  --sd-radius: 22px;
  max-inline-size: 1216px;
  margin: 0 auto;
  padding: clamp(30px, 5vw, 74px) clamp(16px, 4vw, 38px) clamp(56px, 8vw, 112px);
  color: var(--sd-ink);
}
html[data-theme="light"] .studio-download {
  --sd-ink: #102039;
  --sd-muted: #455775;
  --sd-line: #cdd8e8;
  --sd-surface: #fff;
  --sd-panel: #edf3fa;
  --sd-accent: #1b548b;
  --sd-accent-ink: #fff;
}
.studio-download .sd-hero {
  position: relative;
  isolation: isolate;
  display: grid;
  grid-template-columns: 1.2fr 1fr;
  align-items: center;
  overflow: hidden;
  padding: clamp(28px, 5.5vw, 68px);
  min-block-size: 412px;
  border-radius: clamp(20px, 3.4vw, 34px);
  border: 1px solid #314761;
  background: radial-gradient(ellipse at 87% 100%, #254d6a 0, transparent 56%), linear-gradient(128deg, #172943, #0a1428 68%);
  color: #f0f6ff;
}
[dir="rtl"] .studio-download .sd-hero {
  background: radial-gradient(ellipse at 13% 100%, #254d6a 0, transparent 56%), linear-gradient(232deg, #172943, #0a1428 68%);
}
.studio-download .sd-copy { min-inline-size: 0; z-index: 1; }
.studio-download .sd-hero h1 {
  max-inline-size: 12em;
  font-family: var(--font-disp);
  font-size: clamp(2.3rem, 5vw, 4.35rem);
  letter-spacing: -.055em;
  line-height: 1.1;
  overflow-wrap: anywhere;
  text-wrap: balance;
}
[dir="rtl"] .studio-download .sd-hero h1 {
  font-size: clamp(1.95rem, 4.7vw, 3.55rem);
  letter-spacing: 0;
  line-height: 1.55;
}
.studio-download .sd-lead {
  max-inline-size: 36em;
  margin-block-start: 18px;
  color: #c2cee0;
  font-size: clamp(.95rem, 1.55vw, 1.125rem);
  line-height: 1.9;
}
.studio-download .sd-actions, .studio-download .sd-features {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-block-start: 26px;
}
.studio-download .sd-link {
  display: inline-flex;
  justify-content: center;
  align-items: center;
  gap: 9px;
  min-block-size: 46px;
  padding: 10px 16px;
  border: 1px solid transparent;
  border-radius: 12px;
  font-size: .91rem;
  font-weight: 700;
  text-align: center;
  line-height: 1.5;
  transition: background-color .18s ease, border-color .18s ease, transform .18s ease;
}
.studio-download .sd-link:hover { transform: translateY(-2px); }
.studio-download .sd-link:focus-visible, .studio-download summary:focus-visible { outline-color: var(--sd-accent); }
.studio-download .sd-link-web { background: #b4e7fc; color: #0b2033; }
.studio-download .sd-link-web:hover { background: #d4f3ff; }
.studio-download .sd-hero .sd-link:focus-visible { outline-color: #b4e7fc; }
.studio-download .sd-link-anchor { border-color: #405674; color: #eff6ff; background: #162943; }
.studio-download .sd-link-anchor:hover { border-color: #87b5d4; background: #223b58; }
.studio-download .sd-features { gap: 9px 18px; list-style: none; color: #b9cade; font-size: .82rem; }
.studio-download .sd-features li { display: inline-flex; align-items: center; gap: 7px; }
.studio-download .sd-features .icon { color: #a7e1fa; }
.studio-download .sd-art {
  position: relative;
  display: grid;
  place-items: center;
  inline-size: min(100%, 432px);
  min-block-size: 258px;
  justify-self: center;
  margin-inline-start: 24px;
  pointer-events: none;
}
.studio-download .sd-art::before {
  content: "";
  position: absolute;
  inset: -12% -13% -22% -5%;
  background: repeating-radial-gradient(ellipse at 51% 62%, transparent 0 23px, #8dbde72b 25px 26px, transparent 28px 47px);
  mask-image: radial-gradient(ellipse, #000 20%, transparent 74%);
}
.studio-download .sd-window {
  direction: ltr;
  position: relative;
  inline-size: 94%;
  aspect-ratio: 1.58;
  display: grid;
  grid-template-rows: 34px 1fr;
  transform: rotate(-5deg);
  border: 1px solid #6689ae;
  background: linear-gradient(148deg, #243b58, #102039 62%);
  border-radius: 19px;
  box-shadow: 0 18px 54px #0009, inset 0 1px #d0e8ff2b;
}
[dir="rtl"] .studio-download .sd-window { transform: rotate(5deg); }
.studio-download .sd-titlebar { display: flex; align-items: center; gap: 6px; padding-inline: 13px; border-block-end: 1px solid #869fb342; }
.studio-download .sd-titlebar i { inline-size: 6px; block-size: 6px; border-radius: 50%; background: #9bb6cc; }
.studio-download .sd-titlebar i:first-child { background: #b6e7fa; }
.studio-download .sd-window-body { display: grid; grid-template-columns: 28% 1fr; min-block-size: 0; }
.studio-download .sd-sidebar { display: grid; align-content: start; gap: 12px; padding: 15px 11px; border-inline-end: 1px solid #869fb342; }
.studio-download .sd-sidebar i, .studio-download .sd-preview-lines i { display: block; border-radius: 999px; block-size: 5px; background: #a7c5da55; }
.studio-download .sd-sidebar i:first-child { block-size: 18px; border-radius: 5px; background: #80d2fa36; }
.studio-download .sd-sidebar i:last-child, .studio-download .sd-preview-lines i:last-child { inline-size: 60%; }
.studio-download .sd-preview { display: grid; align-content: center; gap: 13px; padding: clamp(12px, 2.8vw, 30px); }
.studio-download .sd-preview-icon {
  display: grid;
  place-items: center;
  inline-size: 32px;
  block-size: 32px;
  border: 1px solid #8bd9ff6e;
  border-radius: 9px;
  color: #e1f9ff;
  background: linear-gradient(138deg, #467cb0, #27537b);
}
.studio-download .sd-preview-lines { display: grid; gap: 7px; }
.studio-download .sd-input {
  inline-size: 100%;
  block-size: 30px;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  padding-inline: 7px;
  color: #b4e7fc;
  background: #5c82a634;
  border: 1px solid #8bb4d869;
  border-radius: 9px;
}
.studio-download .sd-float {
  position: absolute;
  inset-inline-end: -3%;
  inset-block-end: 10%;
  display: grid;
  place-items: center;
  inline-size: 58px;
  block-size: 58px;
  transform: rotate(8deg);
  border: 1px solid #8cd1ec;
  border-radius: 16px;
  color: #d6f5ff;
  background: linear-gradient(145deg, #527fa6, #294b70);
  box-shadow: 0 9px 22px #0006;
  font-size: 25px;
}
.studio-download .sd-downloads, .studio-download .sd-guide { margin-block-start: clamp(42px, 7vw, 88px); }
.studio-download .sd-downloads { scroll-margin-block-start: 110px; }
.studio-download .sd-section-head, .studio-download .sd-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}
.studio-download h2, .studio-download h3 { font-family: var(--font-disp); line-height: 1.45; }
.studio-download h2 { font-size: clamp(1.55rem, 3vw, 2.25rem); }
.studio-download .sd-note {
  display: flex;
  align-items: baseline;
  gap: 10px;
  padding: 12px 16px;
  margin-block-start: 15px;
  background: var(--sd-panel);
  border-inline-start: 2px solid var(--sd-accent);
  border-radius: 9px;
  color: var(--sd-muted);
  font-size: .9rem;
  line-height: 1.75;
}
.studio-download .sd-note .icon { align-self: center; color: var(--sd-accent); }
.studio-download .sd-grid, .studio-download .sd-steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-block-start: 18px; }
.studio-download .sd-card {
  display: flex;
  flex-direction: column;
  align-items: start;
  min-inline-size: 0;
  padding: clamp(17px, 2.4vw, 27px);
  border: 1px solid var(--sd-line);
  border-radius: var(--sd-radius);
  background: var(--sd-surface);
}
.studio-download .sd-card-head { align-self: stretch; }
.studio-download .sd-platform-icon {
  display: grid;
  place-items: center;
  inline-size: 44px;
  block-size: 44px;
  border: 1px solid var(--sd-line);
  border-radius: 12px;
  color: var(--sd-accent);
  background: var(--sd-panel);
  font-size: 20px;
}
.studio-download .sd-card h3 { margin-block-start: 18px; font-size: 1.14rem; }
.studio-download .sd-card p, .studio-download .sd-steps p { margin-block-start: 6px; color: var(--sd-muted); font-size: .9rem; line-height: 1.8; }
.studio-download .sd-card-action { margin-block-start: auto; padding-block-start: 20px; inline-size: 100%; }
.studio-download .studio-pending, .studio-download .sd-version {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 5px 10px;
  border: 1px solid var(--sd-line);
  border-radius: 99px;
  background: var(--sd-panel);
  color: var(--sd-muted);
  font-size: .78rem;
  white-space: nowrap;
}
.studio-download .sd-link-download { inline-size: 100%; color: var(--sd-accent-ink); background: var(--sd-accent); }
.studio-download .sd-card details { margin-block-start: 12px; color: var(--sd-muted); font-size: .78rem; }
.studio-download .sd-card summary { inline-size: fit-content; min-block-size: 32px; padding: 5px 2px; }
.studio-download .sd-card code { display: block; direction: ltr; text-align: start; overflow-wrap: anywhere; }
.studio-download .sd-preview-status { border-color: var(--sd-line); color: var(--sd-ink); }
.studio-download .sd-preview-description { margin-block-end: 12px; }
.studio-download .sd-preview-meta { display: block; }
.studio-download .sd-source-sha { direction: ltr; overflow-wrap: anywhere; }
.studio-download .sd-steps { list-style: none; counter-reset: studio-step; }
.studio-download .sd-steps li {
  counter-increment: studio-step;
  padding: 16px 0;
  border-block-start: 1px solid var(--sd-line);
}
.studio-download .sd-steps li h3 { display: flex; align-items: baseline; gap: 8px; font-size: 1.04rem; }
.studio-download .sd-steps li h3::before { content: counter(studio-step, decimal-leading-zero); color: var(--sd-accent); font-size: .77rem; font-variant-numeric: tabular-nums; }
.studio-download .sd-console { display: inline-flex; align-items: center; gap: 7px; min-block-size: 44px; color: var(--sd-accent); text-decoration: underline; text-underline-offset: 4px; }
@media (max-width: 950px) {
  .studio-download .sd-window { inline-size: 90%; }
  .studio-download .sd-float { inset-inline-end: 0; inset-block-end: 15%; }
}
@media (max-width: 720px) {
  .studio-download .sd-hero { grid-template-columns: minmax(0, 1fr); gap: 10px; min-block-size: 0; }
  .studio-download .sd-hero h1 { max-inline-size: 17em; }
  .studio-download .sd-art { margin-inline-start: 0; inline-size: min(78%, 325px); min-block-size: 0; padding-block: 14px 9px; }
  .studio-download .sd-float { inline-size: 42px; block-size: 42px; font-size: 20px; border-radius: 12px; inset-inline-end: 1%; inset-block-end: 17%; }
  .studio-download .sd-grid, .studio-download .sd-steps { grid-template-columns: minmax(0, 1fr); }
  .studio-download .sd-card-action { padding-block-start: 12px; }
}
@media (max-width: 360px) {
  .studio-download .sd-hero { padding: 21px; }
  .studio-download .sd-actions .sd-link { inline-size: 100%; }
  .studio-download .sd-art { inline-size: 88%; }
}
@media (prefers-reduced-motion: reduce) {
  .studio-download .sd-link { transition: none; }
}
</style>
@endpush

@section('content')
<div class="studio-download">
  <section class="sd-hero" aria-labelledby="studio-download-title">
    <div class="sd-copy">
      <h1 id="studio-download-title">{{ __('studio_download.title') }}</h1>
      <p class="sd-lead">{{ __('studio_download.description') }}</p>
      <div class="sd-actions">
        @if($studioAvailable)
          <a class="sd-link sd-link-web" href="https://studio.servernet.cloud/">
            <svg class="icon" aria-hidden="true"><use href="#i-globe"/></svg>{{ __('studio_download.open_web') }}
            <svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg>
          </a>
        @endif
        <a class="sd-link sd-link-anchor" href="#studio-installers">
          <svg class="icon" aria-hidden="true"><use href="#i-monitor"/></svg>{{ __('studio_download.view_installers') }}
        </a>
      </div>
      <ul class="sd-features" role="list">
        @foreach(['account' => 'user', 'wallet' => 'coins', 'languages' => 'globe'] as $feature => $icon)
          <li><svg class="icon" aria-hidden="true"><use href="#i-{{ $icon }}"/></svg>{{ __('studio_download.'.$feature) }}</li>
        @endforeach
      </ul>
    </div>
    <div class="sd-art" aria-hidden="true">
      <div class="sd-window">
        <div class="sd-titlebar"><i></i><i></i><i></i></div>
        <div class="sd-window-body">
          <div class="sd-sidebar"><i></i><i></i><i></i></div>
          <div class="sd-preview">
            <span class="sd-preview-icon"><svg class="icon"><use href="#i-sparkles"/></svg></span>
            <div class="sd-preview-lines"><i></i><i></i></div>
            <span class="sd-input"><svg class="icon"><use href="#i-send"/></svg></span>
          </div>
        </div>
      </div>
      <span class="sd-float"><svg class="icon"><use href="#i-message"/></svg></span>
    </div>
  </section>

  <section id="studio-installers" class="sd-downloads" aria-labelledby="studio-installers-title">
    @php
      $previewDownloads = array_diff_key($preview['downloads'] ?? [], $release['downloads'] ?? []);
    @endphp
    <div class="sd-section-head">
      <h2 id="studio-installers-title">{{ __('studio_download.installers') }}</h2>
      @if($release)
        <span class="sd-version">{{ __('studio_download.version', ['version' => $release['version']]) }}</span>
      @endif
    </div>
    @if(!$release)
      <p class="sd-note" role="status"><svg class="icon" aria-hidden="true"><use href="#i-info"/></svg>{{ __($studioAvailable ? 'studio_download.preparing_web' : 'studio_download.preparing') }}</p>
    @endif
    @if($previewDownloads)
      <p class="sd-note" role="status"><svg class="icon" aria-hidden="true"><use href="#i-info"/></svg>{{ __('studio_download.preview_notice') }}</p>
      <details class="sd-note sd-preview-meta"><summary>{{ __('studio_download.preview_source') }}</summary><code class="sd-source-sha" dir="ltr">{{ $preview['source_sha'] }}</code></details>
    @endif
    <div class="sd-grid">
      @foreach(['windows_x64' => 'monitor', 'macos_arm64' => 'cpu', 'macos_x64' => 'cpu'] as $platform => $icon)
        @php
          $isPreview = isset($previewDownloads[$platform]);
          $package = $release['downloads'][$platform] ?? $previewDownloads[$platform] ?? null;
        @endphp
        <section class="sd-card" aria-labelledby="studio-{{ $platform }}">
          <div class="sd-card-head">
            <span class="sd-platform-icon" aria-hidden="true"><svg class="icon"><use href="#i-{{ $icon }}"/></svg></span>
            @if($isPreview)
              <span class="sd-version sd-preview-status">{{ __('studio_download.preview_version', ['version' => $preview['version']]) }}</span>
            @elseif(!$package)
              <span class="studio-pending"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg>{{ __('studio_download.soon') }}</span>
            @endif
          </div>
          <h3 id="studio-{{ $platform }}">{{ __('studio_download.'.$platform) }}</h3>
          <p>{{ __('studio_download.'.$platform.'_requirements') }}</p>
          @if($package)
            <div class="sd-card-action">
              @if($isPreview)
                <p class="sd-preview-description">{{ __('studio_download.preview_verified') }}</p>
              @endif
              <a class="sd-link sd-link-download" href="{{ $package['url'] }}" download aria-describedby="studio-{{ $platform }}">
                <svg class="icon" aria-hidden="true"><use href="#i-box"/></svg>{{ __($isPreview ? ($platform === 'windows_x64' ? 'studio_download.preview_windows' : 'studio_download.preview_mac') : 'studio_download.download') }}
              </a>
              <details><summary dir="ltr">SHA-256</summary><code dir="ltr">{{ $package['sha256'] }}</code></details>
            </div>
          @endif
        </section>
      @endforeach
    </div>
  </section>

  <section class="sd-guide" aria-labelledby="studio-guide-title">
    <h2 id="studio-guide-title">{{ __('studio_download.start') }}</h2>
    <ol class="sd-steps" role="list">
      @foreach(['install', 'login', 'wallet'] as $step)
        <li><h3>{{ __('studio_download.step_'.$step.'_title') }}</h3><p>{{ __('studio_download.step_'.$step) }}</p></li>
      @endforeach
    </ol>
    <a class="sd-console" href="{{ lroute('account.ai') }}">{{ __('studio_download.console') }} <svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a>
  </section>
</div>
@endsection
