@if($categories !== [])
<nav class="ai-actions" aria-label="{{ __('ai.category_browse') }}">
    <a class="ai-btn" href="{{ lroute('ai.models') }}">{{ __('ai.all') }}</a>
    @foreach($categories as $type)
        <a class="ai-btn" href="{{ lroute('ai.models', ['type' => $type]) }}" @if(($category ?? '') === $type)aria-current="page"@endif>{{ __('ai.'.$type) }}</a>
    @endforeach
</nav>
@endif
