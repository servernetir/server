@extends('panel.layout')
@section('title', __('dns.title'))

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/dns.css') }}">
@endpush

@section('panel')
@php
  $zoneCount   = $zones->count();
  $activeCount = $zones->where('status', 'active')->count();
  $recordCount = (int) $zones->sum('records_count');
  $pendingCount = $claims->count() + $zones->whereNotIn('status', ['active', 'deleting'])->count();
@endphp
<div class="dns">

  {{-- ══ هیرو: عنوان + افزودنِ دامنه در یک نگاه ══ --}}
  <header class="dns-hero">
    <div>
      <p class="dns-eyebrow"><svg class="icon" aria-hidden="true"><use href="#i-globe"/></svg>{{ __('dns.ux.eyebrow') }}</p>
      <h1>{{ __('dns.title') }}</h1>
      <p class="dns-lead">{{ __('dns.intro') }}</p>

      @if($ready)
        @if($canAdd)
          <form method="POST" action="{{ lroute('account.dns') }}" class="dns-add">
            @csrf
            <div class="dns-add-field">
              <svg class="icon" aria-hidden="true"><use href="#i-globe"/></svg>
              <input class="dns-input dns-ltr" name="domain" dir="ltr" autocomplete="off" spellcheck="false" inputmode="url" autocapitalize="off"
                     placeholder="example.com" value="{{ old('domain') }}" required aria-label="{{ __('dns.add.domain') }}">
            </div>
            <button class="pnl-btn primary" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>{{ __('dns.add.submit') }}</button>
          </form>
          @if($errors->has('domain'))<p class="dns-err" role="alert" style="margin-top:10px">{{ $errors->first('domain') }}</p>@endif
          <p class="dns-hint">{{ __('dns.add.hint') }}</p>
        @else
          <div class="dns-note warn" role="status" style="margin-top:18px">{{ __('dns.add.paused') }}</div>
        @endif
      @endif
    </div>
    <div class="dns-orb" aria-hidden="true">
      <span class="dns-orb-track"></span>
      <span class="dns-orb-core"><svg class="icon"><use href="#i-globe"/></svg></span>
    </div>
  </header>

  @if(session('ok'))<div class="dns-note ok" role="status">{{ session('ok') }}</div>@endif

  @unless($ready)
    <div class="dns-empty">
      <span class="dns-empty-ic"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg></span>
      <b>{{ __('dns.coming_soon_title') }}</b>
      <p>{{ __('dns.coming_soon_body') }}</p>
    </div>
  @else

  {{-- ══ آمار ══ --}}
  <section class="dns-stats" aria-label="{{ __('dns.list.title') }}">
    <div class="dns-stat is-accent">
      <span class="dns-stat-ic" aria-hidden="true"><svg class="icon"><use href="#i-globe"/></svg></span>
      <span>{{ __('dns.ux.stat_zones') }}</span><b data-count="{{ $zoneCount }}">{{ fa_num($zoneCount) }}</b>
    </div>
    <div class="dns-stat">
      <span class="dns-stat-ic" aria-hidden="true"><svg class="icon"><use href="#i-check"/></svg></span>
      <span>{{ __('dns.ux.stat_active') }}</span><b data-count="{{ $activeCount }}">{{ fa_num($activeCount) }}</b>
    </div>
    <div class="dns-stat">
      <span class="dns-stat-ic" aria-hidden="true"><svg class="icon"><use href="#i-list"/></svg></span>
      <span>{{ __('dns.ux.stat_records') }}</span><b data-count="{{ $recordCount }}">{{ fa_num($recordCount) }}</b>
    </div>
    <div class="dns-stat">
      <span class="dns-stat-ic" aria-hidden="true"><svg class="icon"><use href="#i-clock"/></svg></span>
      <span>{{ __('dns.ux.stat_pending') }}</span><b data-count="{{ $pendingCount }}">{{ fa_num($pendingCount) }}</b>
    </div>
  </section>

  {{-- ══ در انتظارِ تأییدِ مالکیت ══ --}}
  @if($claims->count())
  <section class="dns-card">
    <div class="dns-card-h">
      <div class="dns-card-t">
        <span class="dns-card-ic" aria-hidden="true"><svg class="icon"><use href="#i-key"/></svg></span>
        <h2>{{ __('dns.claim.pending_title') }}</h2>
      </div>
      <span class="dns-count">{{ fa_num($claims->count()) }}</span>
    </div>
    <div class="dns-card-b">
      <div class="dns-zones">
        @foreach($claims as $c)
          <a class="dns-zone" href="{{ lroute('account.dns.claim', $c->id) }}">
            <span class="dns-zone-ic"><svg class="icon" aria-hidden="true"><use href="#i-key"/></svg></span>
            <span class="dns-zone-body">
              <span class="dns-zone-d dns-ltr" dir="ltr">{{ \App\Services\Dns\DomainName::toUnicode($c->domain) }}</span>
              <span class="dns-zone-m"><span class="dns-pill wait">{{ __('dns.claim.awaiting') }}</span>{{ __('dns.claim.expires', ['date' => sdate($c->expires_at)]) }}</span>
            </span>
            <span class="dns-zone-go" aria-hidden="true"><svg class="icon"><use href="#i-arrow"/></svg></span>
          </a>
        @endforeach
      </div>
    </div>
  </section>
  @endif

  {{-- ══ دامنه‌ها ══ --}}
  <section class="dns-card">
    <div class="dns-card-h">
      <div class="dns-card-t">
        <span class="dns-card-ic" aria-hidden="true"><svg class="icon"><use href="#i-server"/></svg></span>
        <h2>{{ __('dns.list.title') }}</h2>
      </div>
      <span class="dns-count">{{ fa_num($zoneCount) }}</span>
    </div>
    <div class="dns-card-b">
      @if($zones->isEmpty())
        <div class="dns-empty">
          <span class="dns-empty-ic"><svg class="icon" aria-hidden="true"><use href="#i-globe"/></svg></span>
          <b>{{ __('dns.list.empty_title') }}</b>
          <p>{{ __('dns.list.empty_body') }}</p>
        </div>
      @else
        <div class="dns-zones">
          @foreach($zones as $z)
            @php
              $pill = match(true) {
                $z->status === 'deleting'  => ['off', __('dns.status.deleting')],
                $z->status === 'suspended' => ['off', __('dns.status.suspended')],
                $z->status === 'active' && in_array($z->delegation_state, ['not_delegated', 'partial'], true) => ['wait', __('dns.delegation.state_lost')],
                $z->status === 'active'    => ['on', __('dns.status.active')],
                default                    => ['wait', __('dns.status.pending')],
              };
              $tag = $z->status === 'deleting' ? 'div' : 'a';
            @endphp
            <{{ $tag }} class="dns-zone {{ $tag === 'div' ? 'is-muted' : '' }}" @if($tag === 'a') href="{{ lroute('account.dns.zone', $z->id) }}" @endif>
              <span class="dns-zone-ic"><svg class="icon" aria-hidden="true"><use href="#i-globe"/></svg></span>
              <span class="dns-zone-body">
                <span class="dns-zone-d dns-ltr" dir="ltr">{{ \App\Services\Dns\DomainName::toUnicode($z->domain) }}</span>
                <span class="dns-zone-m"><span class="dns-pill {{ $pill[0] }}">{{ $pill[1] }}</span>{{ trans_choice('dns.list.records', $z->records_count, ['n' => fa_num($z->records_count)]) }}</span>
              </span>
              @if($tag === 'a')<span class="dns-zone-go" aria-hidden="true"><svg class="icon"><use href="#i-arrow"/></svg></span>@endif
            </{{ $tag }}>
          @endforeach
        </div>
      @endif
    </div>
  </section>

  {{-- ══ راهنمای کوتاه + نیم‌سرورها ══ --}}
  <div class="dns-grid">
    <section class="dns-card">
      <div class="dns-card-h">
        <div class="dns-card-t">
          <span class="dns-card-ic" aria-hidden="true"><svg class="icon"><use href="#i-rocket"/></svg></span>
          <div><h2>{{ __('dns.ux.how_title') }}</h2><p>{{ __('dns.ux.how_sub') }}</p></div>
        </div>
      </div>
      <div class="dns-card-b">
        <ol class="dns-how">
          @foreach([1, 2, 3] as $n)
            <li><div><b>{{ __('dns.ux.how_'.$n.'_t') }}</b><span>{{ __('dns.ux.how_'.$n.'_b') }}</span></div></li>
          @endforeach
        </ol>
      </div>
    </section>

    @if(count($nameservers ?? []))
    <section class="dns-card">
      <div class="dns-card-h">
        <div class="dns-card-t">
          <span class="dns-card-ic" aria-hidden="true"><svg class="icon"><use href="#i-server"/></svg></span>
          <div><h2>{{ __('dns.ux.ns_title') }}</h2><p>{{ __('dns.ux.ns_sub') }}</p></div>
        </div>
      </div>
      <div class="dns-card-b">
        <div class="dns-ns">
          @foreach($nameservers as $ns)
            <div class="dns-copy"><code>{{ $ns }}</code>
              <button type="button" data-copy="{{ $ns }}" data-copied="{{ __('dns.ui.copied') }}"><svg class="icon" aria-hidden="true"><use href="#i-copy"/></svg>{{ __('dns.ui.copy') }}</button></div>
          @endforeach
        </div>
      </div>
    </section>
    @endif
  </div>

  @endunless
</div>
<script src="{{ asset_ver('assets/js/dns.js') }}" defer></script>
@endsection
