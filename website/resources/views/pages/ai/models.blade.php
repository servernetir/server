@extends('layouts.site')
@section('title',__('ai.models_title'))
@section('description',__('ai.models_desc'))
@section('canonical',lroute('ai.models'))
@if($search!=='' || $category!=='' || $page->currentPage()>1)@section('noindex','1')
@endif

@include('pages.ai.style')
@section('content')<main class="ai-wrap">@include('pages.ai.nav')<span class="ai-label">AI Gateway · {{ number_format($page->total()) }}</span><h1>{{ __('ai.catalog') }}</h1><p class="ai-lead">{{ __('ai.models_desc') }}</p><form class="ai-search" method="get"><input type="search" name="q" value="{{ $search }}" placeholder="{{ __('ai.search') }}" aria-label="{{ __('ai.search') }}"><select name="type" aria-label="{{ __('ai.all') }}"><option value="">{{ __('ai.all') }}</option>@foreach(\App\Models\AiModel::CATEGORIES as $type)<option value="{{ $type }}" @selected($category===$type)>{{ __('ai.'.$type) }}</option>
@endforeach
</select><button class="ai-btn" type="submit">{{ __('ai.filter') }}</button></form><div class="ai-note">{{ __('ai.status_note') }}</div><div class="ai-grid">@forelse($models as $card)@include('pages.ai.card')
@empty
<p>{{ __('ai.empty') }}</p>
@endforelse
</div><div class="ai-pagination">{{ $page->links() }}</div><p class="ai-muted">{{ __('ai.tax') }}</p></main>
@endsection
