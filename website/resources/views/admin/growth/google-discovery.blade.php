@extends('admin.layout')
@section('title', __('google-discovery.title'))
@section('nav_growth','on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/growth-center.css') }}">@endsection
@section('content')
<div class="gc">
  <a href="/admin/ai-growth?tab=seo">{{ __('google-discovery.back') }}</a>
  <section class="gc-hero"><div><h2>{{ __('google-discovery.title') }}</h2><p>{{ __('google-discovery.explanation') }}</p></div></section>
  <section class="gc-card">
    <h3>{{ __('google-discovery.connection') }}</h3>
    <p>{{ $status['connection']['connected'] ? __('google-discovery.connected') : __('google-discovery.authorization_needed') }}</p>
    @if($status['connection']['email'])<p>{{ $status['connection']['email'] }}</p>@endif
    <p><code>{{ $status['connection']['site'] }}</code> · <a href="{{ $status['connection']['sitemap'] }}">{{ __('google-discovery.sitemap') }}</a></p>
    <form method="post" action="/admin/ai-growth/google-discovery/configure">@csrf
      <label>{{ __('google-discovery.automation') }} <select name="enabled"><option value="1" @selected($status['enabled'])>{{ __('google-discovery.enabled') }}</option><option value="0" @selected(!$status['enabled'])>{{ __('google-discovery.paused') }}</option></select></label>
      <button>{{ __('google-discovery.save') }}</button>
    </form>
    @if($status['connection']['configured'])
      <form method="post" action="/admin/ai-growth/google-discovery/connect">@csrf<button>{{ __('google-discovery.connect') }}</button></form>
    @else<p>{{ __('google-discovery.credentials_missing') }}</p>@endif
    @if($status['connection']['connected'])<form method="post" action="/admin/ai-growth/google-discovery/disconnect">@csrf<button>{{ __('google-discovery.disconnect') }}</button></form>@endif
  </section>
  <section class="gc-card"><h3>{{ __('google-discovery.receipts') }}</h3>
    @forelse($status['receipts'] as $receipt)
      <article style="margin-block:1rem;padding-block:1rem;border-bottom:1px solid #334155">
        <strong>#{{ $receipt['post_id'] }} — {{ __('google-discovery.stage_'.$receipt['stage']) }}</strong>
        @if(!empty($receipt['sitemap_accepted_at']))<p>{{ __('google-discovery.submitted_at') }}: {{ $receipt['sitemap_accepted_at'] }}</p>@endif
        @if(!empty($receipt['code']))<p>{{ __('google-discovery.problem') }}: <code>{{ $receipt['code'] }}</code></p>@endif
        <ul>@foreach($receipt['urls'] as $locale => $url)<li><a href="{{ $url }}" target="_blank" rel="noopener">{{ strtoupper($locale) }}</a>
          @if(isset($receipt['inspection'][$locale]))— {{ $receipt['inspection'][$locale]['coverageState'] ?? $receipt['inspection'][$locale]['verdict'] }} ({{ $receipt['inspection'][$locale]['checked_at'] }})@endif
        </li>@endforeach</ul>
        @if(array_key_exists('all_indexed',$receipt))<p>{{ $receipt['all_indexed'] ? __('google-discovery.indexed') : __('google-discovery.not_all_indexed') }}</p>@endif
      </article>
    @empty<p>{{ __('google-discovery.empty') }}</p>@endforelse
  </section>
</div>
@endsection
