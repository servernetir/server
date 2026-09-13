{{-- Google Tag Manager (noscript) --}}
@php
    $gtmId = config('services.gtm.id', 'GTM-MRSC7BF7');
@endphp
@if(!empty($gtmId))
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $gtmId }}"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
@endif
