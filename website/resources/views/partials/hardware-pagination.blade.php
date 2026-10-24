@if($pagination->lastPage() > 1)
<nav class="hw-pages" aria-label="{{ \App\Services\Shop\HardwarePresentation::text('pagination') }}">
    @if($pagination->onFirstPage() === false)<a rel="prev" href="{{ $pagination->previousPageUrl() }}">{{ \App\Services\Shop\HardwarePresentation::text('previous') }}</a>@endif
    @foreach(array_unique(array_merge([1], range(max(1, $pagination->currentPage() - 2), min($pagination->lastPage(), $pagination->currentPage() + 2)), [$pagination->lastPage()])) as $hardwarePage)
        <a href="{{ $pagination->url($hardwarePage) }}" @if($hardwarePage === $pagination->currentPage()) aria-current="page" @endif>{{ fa_num($hardwarePage) }}</a>
    @endforeach
    @if($pagination->hasMorePages())<a rel="next" href="{{ $pagination->nextPageUrl() }}">{{ \App\Services\Shop\HardwarePresentation::text('next') }}</a>@endif
</nav>
@endif
