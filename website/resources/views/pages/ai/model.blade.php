@extends('layouts.site')
@section('title', e($seo['title']))
@section('description', e($seo['description']))
@section('canonical', $seo['url'])
@if(!$seo['indexable'])
@section('noindex', '1')
@endif
@include('pages.ai.style')
@push('head')
<script type="application/ld+json">{!! json_encode($seo['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endpush

@section('content')
<article class="ai-wrap" data-ai-model>
    @include('pages.ai.nav')
    <nav class="ai-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ lroute('ai.home') }}">AI Gateway</a><span aria-hidden="true"> / </span>
        <a href="{{ lroute('ai.models') }}">{{ __('ai.catalog') }}</a><span aria-hidden="true"> / </span>
        <span>{{ $model->name }}</span>
    </nav>
    <div class="ai-label">{{ $model->vendor }} · {{ __('ai.'.$model->category) }}</div>
    <h1 class="ai-model-title"><bdi>{{ $model->name }}</bdi> API</h1>
    <p class="ai-lead">{{ $seo['intro'] }}</p>
    <span class="ai-pill {{ $state['ready'] ? 'ready' : '' }}">{{ __('ai.'.($model->status === 'deprecated' ? 'deprecated' : ($state['ready'] ? 'ready' : 'pending'))) }}</span>
    <div class="ai-actions">
        <a class="ai-btn primary" href="{{ lroute('account.ai') }}">{{ __('ai.start') }}</a>
        <a class="ai-btn" href="{{ lroute('ai.docs') }}">{{ __('ai.docs') }}</a>
    </div>
    <section class="ai-grid" aria-label="{{ __('ai_model.operation') }}">
        <div class="ai-card">
            <h2>{{ $seo['tokenOperation'] ? __('ai.price') : __('ai_model.operation') }}</h2>
            @if($seo['tokenOperation'] && $state['quote'])
                <div class="ai-price">
                    <div>{{ __('ai.input') }}<strong>{{ number_format($state['quote']->pIn) }}</strong></div>
                    @if($model->category === 'chat')<div>{{ __('ai.output') }}<strong>{{ number_format($state['quote']->pOut) }}</strong></div>@endif
                </div>
                <p>{{ __('ai_model.estimate_note_short', ['percent' => \App\Services\Ai\AiPricing::bpToPercent($seo['vatBp'])]) }}</p>
            @else<p>{{ __('ai.prices_pending') }}</p>@endif
        </div>
        <div class="ai-card ai-facts">
            <dl class="ai-spec">
                @foreach($seo['facts'] as $fact)<div><dt>{{ $fact['name'] }}</dt><dd><bdi>{{ $fact['value'] }}</bdi></dd></div>@endforeach
            </dl>
        </div>
        <div class="ai-card">
            <h2>{{ __('ai.supported') }}</h2>
            @php $shownFeature = false; @endphp
            @foreach(['reasoning' => 'reasoning', 'tool_calling' => 'tools', 'streaming' => 'stream'] as $cap => $label)
                @if($model->category === 'chat' && $model->capability($cap) && !($cap === 'reasoning' && $seo['reasoningDisabled']))
                @php $shownFeature = true; @endphp<p>{{ __('ai.'.$label) }} ✓</p>
                @endif
            @endforeach
            @if(!$shownFeature)<p>{{ __('ai_model.no_features') }}</p>@endif
        </div>
    </section>
    <section class="ai-section">
        <h2>{{ __('ai_model.overview', ['name' => $model->name]) }}</h2>
        <p class="ai-muted">{{ $seo['summary'] }}</p>
        @if($seo['family'])<p class="ai-muted">{{ $seo['family'] }}</p>@endif
        <h2>{{ __('ai_model.use_cases', ['name' => $model->name]) }}</h2>
        <ul class="ai-steps">@foreach($seo['uses'] as $use)<li>{{ $use }}</li>@endforeach</ul>
        <h2>{{ __('ai_model.workflow') }}</h2><p class="ai-muted">{{ $seo['workflow'] }}</p>
    </section>
    <section class="ai-section">
        <h2>{{ __('ai_model.limits') }}</h2><p class="ai-muted">{{ $seo['limit'] }}</p>
        @if($seo['reasoningDisabled'])<p class="ai-note">{{ __('ai_model.reasoning_disabled') }}</p>@endif
        @if(!$seo['tokenOperation'])<p class="ai-note">{{ __('ai_model.native_billing') }}</p>@endif
    </section>
    @if($seo['costExample'])
    <section class="ai-section">
        <h2>{{ __('ai_model.estimate_title') }}</h2>
        <p class="ai-muted">{{ __('ai_model.estimate_note', ['input' => number_format($seo['costExample']['input']), 'output' => number_format($seo['costExample']['output'])]) }}</p>
        <dl class="ai-spec ai-card">
            <div><dt>{{ __('ai_model.subtotal') }}</dt><dd>{{ number_format($seo['costExample']['sell']) }}</dd></div>
            <div><dt>{{ __('ai_model.vat', ['percent' => \App\Services\Ai\AiPricing::bpToPercent($seo['vatBp'])]) }}</dt><dd>{{ number_format($seo['costExample']['tax']) }}</dd></div>
            <div><dt>{{ __('ai_model.total') }}</dt><dd>{{ number_format($seo['costExample']['charged']) }}</dd></div>
        </dl>
    </section>
    @endif
    @if($seo['sourceSpec'])
    <section class="ai-section">
        <h2>{{ __('ai_model.source_specs') }}</h2>
        <p class="ai-muted">{{ __('ai_model.source_date', ['date' => $seo['sourceSpec']['schema_date']]) }}</p>
        <div class="ai-table-scroll" tabindex="0">
            <table class="ai-table">
                <thead><tr><th scope="col">{{ __('ai_model.parameter') }}</th><th scope="col">{{ __('ai_model.data_type') }}</th><th scope="col">{{ __('ai_model.required') }}</th><th scope="col">{{ __('ai_model.constraints') }}</th></tr></thead>
                <tbody>@foreach($seo['sourceSpec']['fields'] as $field)
                    <tr><th scope="row"><code>{{ $field['name'] }}</code></th><td><code>{{ $field['type'] }}</code></td><td>{{ __('ai_model.'.($field['required'] ? 'yes' : 'no')) }}</td>
                    <td>@foreach(['minLength', 'maxLength', 'minimum', 'maximum', 'default', 'enum'] as $constraint)
                        @if(array_key_exists($constraint, $field))
                        <div>{{ __('ai_model.'.$constraint) }}: <bdi>{{ is_array($field[$constraint]) ? implode(', ', $field[$constraint]) : (is_bool($field[$constraint]) ? ($field[$constraint] ? 'true' : 'false') : $field[$constraint]) }}</bdi></div>
                        @endif
                    @endforeach</td></tr>
                @endforeach</tbody>
            </table>
        </div>
    </section>
    @endif
    @if($seo['example'])
    <section class="ai-section">
        <h2>{{ __('ai_model.integration', ['name' => $model->name]) }}</h2>
        <p class="ai-muted">{{ __('ai_model.example_note', ['scope' => $seo['example']['scope']]) }}</p>
        @if(!$state['ready'])<p class="ai-note">{{ __('ai_model.pending_example') }}</p>@endif
        <pre class="ai-code"><code>{{ $seo['example']['curl'] }}</code></pre>
        <p class="ai-muted">{{ __('ai.retries') }} {{ __('ai.key_safety') }}</p>
        <a class="ai-btn" href="{{ lroute('ai.docs') }}">{{ __('ai_model.billing_guide') }}</a>
    </section>
    @endif
    <section class="ai-section ai-faq">
        <h2>{{ __('ai.faq') }}</h2>
        @foreach($seo['faq'] as $item)<details><summary>{{ $item['q'] }}</summary><p class="ai-muted">{{ $item['a'] }}</p></details>@endforeach
    </section>
    @if($related->isNotEmpty())
    <section class="ai-section">
        <h2>{{ __('ai_model.related') }}</h2>
        <div class="ai-grid">@foreach($related as $card)@include('pages.ai.card')@endforeach</div>
        <div class="ai-actions"><a class="ai-btn" href="{{ lroute('ai.models', ['type' => $model->category]) }}">{{ __('ai.catalog') }}</a></div>
    </section>
    @endif
    <div class="ai-note">{{ __('ai.status_note') }}</div>
</article>
@endsection
