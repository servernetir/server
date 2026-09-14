{{-- Google Tag Manager (noscript) --}}
@php
    $gtmId = config('services.gtm.id', 'GTM-MRSC7BF7');
    $analyticsEnabled = (bool) config('services.gtm.enabled')
        && preg_match('/^GTM-[A-Z0-9]+$/', (string) $gtmId)
        && request()->cookie('snet_analytics_consent') === 'granted';
@endphp
@if($analyticsEnabled)
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $gtmId }}"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
@endif
