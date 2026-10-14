@php
    $hardwareGuide = \App\Services\Shop\HardwarePresentation::guide($hardwareCategory, $hardwareSlug ?? null);
    $hardwareMeta = isset($hardwareSlug) ? \App\Services\Shop\HardwarePresentation::metadata($hardwareSlug) : [];
@endphp
@if($hardwareGuide)
<section class="hw-guide" aria-labelledby="hw-guide-title">
    <h2 id="hw-guide-title">{{ \App\Services\Shop\HardwarePresentation::text('decision_guide') }}</h2>
    <div class="hw-guide-grid">
        @foreach($hardwareGuide as $section)
        <article><h3>{{ lc($section['title']) }}</h3><p>{{ lc($section['body']) }}</p></article>
        @endforeach
    </div>
    @if(!empty($hardwareMeta['schematic']))
    <p class="hw-note">{{ \App\Services\Shop\HardwarePresentation::text('schematic') }}</p>
    @elseif(!empty($hardwareMeta['photo_reference']))
    <p class="hw-note">{{ \App\Services\Shop\HardwarePresentation::text('photo_reference') }}</p>
    @endif
    @if(!empty($hardwareMeta['credit']))
    <p class="hw-note"><a href="{{ $hardwareMeta['credit']['source'] }}" rel="noopener" target="_blank">{{ $hardwareMeta['credit']['title'] }}</a> · {{ $hardwareMeta['credit']['author'] }} · <a href="{{ $hardwareMeta['credit']['license_url'] }}" rel="license noopener" target="_blank">{{ $hardwareMeta['credit']['license'] }}</a> · {{ $hardwareMeta['credit']['changes'] }}</p>
    @endif
    <nav class="hw-related-links" aria-label="{{ \App\Services\Shop\HardwarePresentation::text('next_steps') }}">
        @foreach(['cpu', 'ram', 'disk', 'raid', 'nic', 'psu', 'other'] as $hardwareLinkCategory)
            @if($hardwareLinkCategory !== $hardwareCategory)
            <a href="{{ lroute('parts.category', $hardwareLinkCategory) }}">{{ lc(\App\Models\ServerPart::CATEGORIES[$hardwareLinkCategory]) }}</a>
            @endif
        @endforeach
        <a href="{{ lroute('servers.index') }}">{{ __('ui.srv_title') }}</a>
    </nav>
    @if(!empty($hardwareMeta['manufacturer_url']))
    <p class="hw-note"><a href="{{ $hardwareMeta['manufacturer_url'] }}" rel="noopener" target="_blank">{{ \App\Services\Shop\HardwarePresentation::text('manufacturer_docs') }}</a></p>
    @endif
</section>
@endif
