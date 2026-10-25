@extends('layouts.site')
@section('title', __('ai_experience.compare_title'))
@section('description', __('ai_experience.compare_desc'))
@section('canonical', lroute('ai.compare'))
@if($custom)@section('noindex', '1')@endif
@include('pages.ai.style')
@section('content')
<section class="ai-wrap" data-ai-workspace>
@include('pages.ai.nav')
<div class="ai-breadcrumb"><a href="{{ lroute('ai.home') }}">AI Gateway</a><span>/</span><span>{{ __('ai_experience.compare') }}</span></div>
<span class="ai-label">{{ __('ai_experience.workspace') }}</span><h1 class="ai-model-title">{{ __('ai_experience.compare_intro') }}</h1>
<p class="ai-lead">{{ __('ai_experience.compare_lead') }}</p><div class="ai-actions"><a class="ai-btn primary" href="{{ lroute('ai.models') }}">{{ __('ai_experience.hero_browse') }}</a></div>
<div class="ai-note">{{ __('ai_experience.compare_note') }}</div>
<h2 style="font-size:18px;margin:24px 0">{{ __($custom ? 'ai_experience.compare_custom' : 'ai_experience.compare_default') }}</h2>
@if($models->pluck('model.category')->unique()->count() > 1)<p class="ai-note">{{ __('ai_experience.mixed') }}</p>@endif
@if($models->isNotEmpty())
<div class="ai-table-scroll" tabindex="0" role="region" aria-label="{{ __('ai_experience.compare') }}">
<table class="ai-table ai-compare-table">
<thead><tr><th scope="col">{{ __('ai_experience.attribute') }}</th>@foreach($models as $card)<th scope="col"><span class="ai-label"><bdi>{{ $card['model']->vendor }}</bdi></span><a href="{{ lroute('ai.model', $card['model']->slug) }}"><bdi>{{ $card['model']->name }}</bdi></a><br><a class="ai-btn" href="{{ lroute('ai.model', $card['model']->slug) }}">{{ __('ai_experience.see_model') }} ↗</a></th>@endforeach</tr></thead>
<tbody>
<tr><th scope="row">{{ __('ai_experience.status') }}</th>@foreach($models as $card)<td><span class="ai-pill {{ $card['state']['ready'] ? 'ready' : '' }}">{{ __('ai.'.($card['model']->status === 'deprecated' ? 'deprecated' : ($card['state']['ready'] ? 'ready' : 'pending'))) }}</span></td>@endforeach</tr>
<tr><th scope="row">{{ __('ai_experience.operation') }}</th>@foreach($models as $card)<td>{{ $card['seo']['operationLabel'] }}</td>@endforeach</tr>
<tr><th scope="row">{{ __('ai_experience.context') }}</th>@foreach($models as $card)<td>{{ $card['seo']['tokenOperation'] && $card['model']->context_tokens > 0 ? number_format($card['model']->context_tokens) : __('ai_experience.not_listed') }}</td>@endforeach</tr>
<tr><th scope="row">{{ __('ai_experience.output') }}</th>@foreach($models as $card)<td>{{ $card['model']->category === 'chat' && $card['model']->max_output_tokens > 0 ? number_format($card['model']->max_output_tokens) : __('ai_experience.not_listed') }}</td>@endforeach</tr>
<tr><th scope="row">{{ __('ai_experience.input_rate') }}</th>@foreach($models as $card)<td>{{ $card['seo']['tokenOperation'] && $card['state']['quote'] ? number_format($card['state']['quote']->pIn) : __('ai_experience.not_listed') }}</td>@endforeach</tr>
<tr><th scope="row">{{ __('ai_experience.output_rate') }}</th>@foreach($models as $card)<td>{{ $card['model']->category === 'chat' && $card['state']['quote'] ? number_format($card['state']['quote']->pOut) : __('ai_experience.not_listed') }}</td>@endforeach</tr>
<tr><th scope="row">{{ __('ai_experience.native_rate') }}</th>@foreach($models as $card)<td>@if($card['seo']['nativePrice']){{ number_format($card['seo']['nativePrice']['sell']) }} IRT / {{ $card['seo']['nativePrice']['quantity'] }} {{ __('ai_model.units.'.$card['seo']['nativePrice']['unit']) }}@else{{ __('ai_experience.not_listed') }}@endif</td>@endforeach</tr>
@foreach(['tool_calling' => 'tools', 'streaming' => 'stream', 'reasoning' => 'reasoning'] as $key => $label)<tr><th scope="row">{{ __('ai.'.$label) }}</th>@foreach($models as $card)<td>{{ $card['model']->category === 'chat' && $card['model']->capability($key) && !($key === 'reasoning' && $card['seo']['reasoningDisabled']) ? __('ai_experience.yes') : __('ai_experience.no') }}</td>@endforeach</tr>@endforeach
</tbody></table>
</div>
@else<p class="ai-note">{{ __('ai_experience.compare_empty') }}</p>@endif
<section class="ai-section ai-editorial"><span class="ai-label">{{ __('ai_experience.analysis_label') }}</span><h2>{{ __('ai_experience.comparison_method') }}</h2><ol class="ai-steps">@foreach(__('ai_experience.comparison_rules') as $rule)<li>{{ $rule }}</li>@endforeach</ol><div class="ai-actions"><a class="ai-btn" href="{{ lroute('ai.docs') }}">{{ __('ai.docs') }}</a><a class="ai-btn" href="{{ lroute('account.ai') }}">{{ __('ai.start') }}</a></div></section>
@include('pages.ai.compare-tray')
</section>
@endsection
