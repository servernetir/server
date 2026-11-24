@php $gpuInlineCss = app(\App\Services\PublicPageStyles::class)->auxiliary('assets/css/gpu-alternatives.css'); @endphp
@if($gpuInlineCss !== null)
<style id="gpu-alternatives-css">{!! $gpuInlineCss !!}</style>
@else
<link rel="stylesheet" href="{{ asset_ver('assets/css/gpu-alternatives.css') }}">
@endif
