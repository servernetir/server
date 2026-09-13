{{-- گوگل تگ منیجر و متغیرهای دیتالایر اختصاصی سرورنت --}}
@php
    $gtmId = config('services.gtm.id', 'GTM-MRSC7BF7');
@endphp
@if(!empty($gtmId))
<script>
window.dataLayer = window.dataLayer || [];
@if(session()->has(\App\Services\Analytics\DataLayerService::SESSION_PURCHASE_KEY))
window.dataLayer.push({!! json_encode(session(\App\Services\Analytics\DataLayerService::SESSION_PURCHASE_KEY), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!});
@endif
@auth('customer')
window.dataLayer.push({
    'user_id': '{{ (string) auth('customer')->id() }}',
    'customer_status': 'authenticated'
});
@endauth
</script>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{{ $gtmId }}');</script>
<!-- End Google Tag Manager -->
@endif
