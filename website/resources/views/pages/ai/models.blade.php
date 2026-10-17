@extends('layouts.site')
@section('title',($categoryPage ? __('ai.category_titles.'.$category) : __('ai.models_title')).($page->currentPage()>1?' · '.$page->currentPage():''))
@section('description',$categoryPage ? __('ai.category_descriptions.'.$category) : __('ai.models_desc'))
@section('canonical',$canonical)
@if($search!=='' || ($category!=='' && !$categoryPage) || $page->isEmpty())@section('noindex','1')
@endif

@include('pages.ai.style')
@section('content')<section class="ai-wrap">@include('pages.ai.nav')<span class="ai-label">AI Gateway · {{ number_format($page->total()) }}</span><h1>{{ $categoryPage ? __('ai.category_headings.'.$category) : __('ai.catalog') }}</h1><p class="ai-lead">{{ $categoryPage ? __('ai.category_descriptions.'.$category) : __('ai.models_desc') }}</p>@include('pages.ai.categories')@if($categoryPage)<section class="ai-section"><h2>{{ __('ai.category_compare') }}</h2><ul class="ai-steps">@foreach(__('ai.category_guides.'.$category) as $guide)<li>{{ $guide }}</li>@endforeach</ul></section>@endif<form class="ai-search" method="get"><input type="search" name="q" value="{{ $search }}" placeholder="{{ __('ai.search') }}" aria-label="{{ __('ai.search') }}"><select name="type" aria-label="{{ __('ai.all') }}"><option value="">{{ __('ai.all') }}</option>@foreach(\App\Models\AiModel::CATEGORIES as $type)<option value="{{ $type }}" @selected($category===$type)>{{ __('ai.'.$type) }}</option>
@endforeach
</select><button class="ai-btn" type="submit">{{ __('ai.filter') }}</button></form><div class="ai-note">{{ __('ai.status_note') }}</div><div class="ai-grid">@forelse($models as $card)@include('pages.ai.card')
@empty
<p>{{ __('ai.empty') }}</p>
@endforelse
</div><div class="ai-pagination">{{ $page->links() }}</div></section>
@endsection
