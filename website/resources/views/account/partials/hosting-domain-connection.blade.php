@php
  $hostingNs = \App\Services\Dns\HostingDomainConnection::nameservers($s);
@endphp
@once
  <link rel="stylesheet" href="{{ asset('assets/css/hosting-connection.css') }}?v=20261102">
  <script src="{{ asset('assets/js/hosting-connection.js') }}?v=20261102" defer></script>
@endonce
<section class="hst-connect" data-hosting-connection data-state="checking"
  data-url="{{ lroute('account.services.domain-connection', $s) }}"
  data-messages="{{ json_encode(__('hosting-connection'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
  aria-label="{{ __('hosting-connection.title') }}">
  <header class="hst-connect-head">
    <span class="hst-connect-orbit" aria-hidden="true"><svg class="icon"><use href="#i-globe"/></svg></span>
    <div><small>{{ __('hosting-connection.lead') }}</small><h3>{{ __('hosting-connection.title') }}</h3></div>
    <span class="hst-connect-location">{{ $s->server?->locationLabel() }}</span>
  </header>
  <div class="hst-connect-status" role="status" aria-live="polite"><i aria-hidden="true"></i><b data-connection-status>{{ __('hosting-connection.checking') }}</b></div>
  <p class="hst-connect-intro">{{ __('hosting-connection.intro') }}</p>
  <div class="hst-connect-ns" role="group" aria-label="{{ __('hosting-connection.nameservers') }}">
    @forelse($hostingNs as $ns)
      <div><span class="hst-connect-index">{{ $loop->iteration }}</span><code dir="ltr">{{ $ns }}</code><button type="button" data-copy-ns="{{ $ns }}" aria-label="{{ __('hosting-connection.copy') }} {{ $ns }}">{{ __('hosting-connection.copy') }}</button></div>
    @empty
      <p>{{ __('hosting-connection.unconfigured') }}</p>
    @endforelse
  </div>
  <div class="hst-connect-check"><button type="button" data-check-connection>{{ __('hosting-connection.refresh') }}</button><small data-connection-time></small></div>
  <p class="hst-connect-observed" hidden><span>{{ __('hosting-connection.observed') }}: </span><b dir="ltr" data-connection-observed></b></p>
  <details class="hst-connect-guide"><summary>{{ __('hosting-connection.guide') }}</summary>
    <ol><li>{{ __('hosting-connection.step1') }}</li><li>{{ __('hosting-connection.step2') }}</li><li>{{ __('hosting-connection.step3') }}</li></ol>
    <p>{{ __('hosting-connection.'.($type === 'whm' ? 'start_whm' : 'start_other')) }}</p>
  </details>
  <p class="hst-connect-note">{{ __('hosting-connection.note') }}</p>
</section>
