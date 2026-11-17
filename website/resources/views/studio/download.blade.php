@extends('layouts.site')
@section('title', __('studio_download.title').' — ServerNet')
@section('description', __('studio_download.description'))
@if(!$release)@section('noindex', '1')@endif
@push('head')
<style>
.sn-studio-download{max-width:1120px;margin:48px auto;padding:28px;color:#172339}.sn-studio-download .studio-tag{color:#1674d8;font-size:13px;font-weight:700;letter-spacing:2px}.sn-studio-download h1{font-size:clamp(28px,4vw,48px);line-height:1.7;margin:14px 0}.sn-studio-download .studio-lead{font-size:17px;line-height:2;max-width:760px;color:#63758c}.studio-features{display:flex;gap:12px;flex-wrap:wrap;margin:26px 0}.studio-features span{background:#edf5ff;border-radius:24px;padding:9px 18px;font-size:13px}.studio-download-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin:32px 0}.studio-platform{padding:28px;border:1px solid #dde6f2;background:linear-gradient(145deg,#fff,#f5f9ff);border-radius:22px}.studio-platform h2{font-size:20px}.studio-platform p{min-height:60px;color:#63758c;line-height:1.9}.studio-platform a,.studio-pending{display:block;padding:12px 14px;border-radius:12px;text-align:center;font-size:14px}.studio-platform a{background:#1776df;color:white;text-decoration:none}.studio-pending{background:#e9eef5;color:#63758c}.studio-platform details{font-size:12px;margin-top:18px}.studio-platform code{display:block;overflow-wrap:anywhere;direction:ltr;margin-top:10px}.studio-release-note{padding:22px;border-radius:16px;background:#edf5ff;line-height:2}.studio-download-help{margin-top:40px;line-height:2}.studio-download-help li{margin:10px 0}@media(max-width:760px){.studio-download-grid{grid-template-columns:1fr}.sn-studio-download{margin:20px auto;padding:20px}.studio-platform p{min-height:0}}
</style>
@endpush
@section('content')
<main class="sn-studio-download">
  <span class="studio-tag">SERVERNET STUDIO</span>
  <h1>{{ __('studio_download.title') }}</h1>
  <p class="studio-lead">{{ __('studio_download.description') }}</p>
  <div class="studio-features"><span>{{ __('studio_download.account') }}</span><span>{{ __('studio_download.wallet') }}</span><span>{{ __('studio_download.languages') }}</span></div>
  @if(!$release)<p class="studio-release-note" role="status">{{ __('studio_download.preparing') }}</p>
  @else<p>{{ __('studio_download.version', ['version' => $release['version']]) }}</p>@endif
  <div class="studio-download-grid">
    @foreach(['windows_x64', 'macos_arm64', 'macos_x64'] as $platform)
    <section class="studio-platform">
      <h2>{{ __('studio_download.'.$platform) }}</h2>
      <p>{{ __('studio_download.'.$platform.'_requirements') }}</p>
      @if($release)
        <a href="{{ $release['downloads'][$platform]['url'] }}" download>{{ __('studio_download.download') }}</a>
        <details><summary>SHA-256</summary><code>{{ $release['downloads'][$platform]['sha256'] }}</code></details>
      @else<span class="studio-pending">{{ __('studio_download.soon') }}</span>@endif
    </section>
    @endforeach
  </div>
  <section class="studio-download-help"><h2>{{ __('studio_download.start') }}</h2><ol><li>{{ __('studio_download.step_install') }}</li><li>{{ __('studio_download.step_login') }}</li><li>{{ __('studio_download.step_wallet') }}</li></ol><p>{{ __('studio_download.scope') }}</p><a href="{{ lroute('account.ai') }}">{{ __('studio_download.console') }}</a></section>
</main>
@endsection
