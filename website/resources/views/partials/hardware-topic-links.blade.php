@php
    $topicLinks = collect(config('hardware_topics', []))
        ->filter(fn ($t) => (!isset($topicCategory) || $t['category'] === $topicCategory)
            && (!isset($topicGeneration) || ($t['gen'] ?? null) === $topicGeneration))
        ->reject(fn ($t, $slug) => isset($currentTopic) && $slug === $currentTopic);
@endphp
@if($topicLinks->isNotEmpty())
<section class="hw-guide">
    <h2>{{ __('shop_seo.topics') }}</h2>
    <nav class="hw-related-links" aria-label="{{ __('shop_seo.topics') }}">
        @foreach($topicLinks as $topicSlug => $topicData)
            <a href="{{ lroute('parts.topic', $topicSlug) }}">{{ lc($topicData['title']) }}</a>
        @endforeach
    </nav>
</section>
@endif
