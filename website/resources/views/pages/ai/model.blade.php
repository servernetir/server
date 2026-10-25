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
<article class="ai-wrap" data-ai-model data-ai-workspace>
    @include('pages.ai.nav')
    <nav class="ai-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ lroute('ai.home') }}">AI Gateway</a><span aria-hidden="true"> / </span>
        <a href="{{ lroute('ai.models') }}">{{ __('ai.catalog') }}</a><span aria-hidden="true"> / </span>
        <a href="{{ lroute('ai.models', ['type' => $model->category]) }}">{{ __('ai.'.$model->category) }}</a><span aria-hidden="true"> / </span>
        <span>{{ $model->name }}</span>
    </nav>
    <div class="ai-model-head"><div class="ai-model-heading"><div class="ai-label">{{ $model->vendor }} · {{ $seo['operationLabel'] }}</div>
    <h1 class="ai-model-title"><bdi>{{ $model->name }}</bdi> API</h1>
    <p class="ai-lead">{{ $seo['intro'] }}</p>
    <span class="ai-pill {{ $state['ready'] ? 'ready' : '' }}">{{ __('ai.'.($model->status === 'deprecated' ? 'deprecated' : ($state['ready'] ? 'ready' : 'pending'))) }}</span>
    <div class="ai-actions">
        <a class="ai-btn primary" href="{{ lroute('account.ai') }}">{{ __('ai.start') }}</a>
        <a class="ai-btn" href="{{ lroute('ai.docs') }}">{{ __('ai.docs') }}</a>
    </div>
    </div><aside class="ai-model-side"><span class="ai-label">{{ __('ai_model.model_id') }}</span><p style="margin:14px 0"><code>{{ $model->slug }}</code></p><p class="ai-muted">{{ __('ai_experience.quickstart_note') }}</p><a class="ai-btn" href="{{ lroute('ai.compare', ['models' => $comparisonSlugs]) }}">{{ __('ai_experience.comparison_link') }}</a></aside></div>
    <nav class="ai-jump" aria-label="{{ __('ai_experience.jump') }}"><a href="#model-overview">{{ __('ai_experience.jump_overview') }}</a><a href="#model-pricing">{{ __('ai_experience.jump_pricing') }}</a>@if($seo['example'])<a href="#model-integration">{{ __('ai_experience.jump_connect') }}</a>@endif<a href="#model-alternatives">{{ __('ai_experience.jump_related') }}</a></nav>
    <section class="ai-grid" id="model-pricing" aria-label="{{ __('ai_model.operation') }}">
        <div class="ai-card">
            <h2>{{ $seo['tokenOperation'] ? __('ai.price') : __('ai_model.operation') }}</h2>
            @if($seo['tokenOperation'] && $state['quote'])
                <div class="ai-price">
                    <div>{{ __('ai.input') }}<strong>{{ number_format($state['quote']->pIn) }}</strong></div>
                    @if($model->category === 'chat')<div>{{ __('ai.output') }}<strong>{{ number_format($state['quote']->pOut) }}</strong></div>@endif
                </div>
                <p>{{ __('ai_model.estimate_note_short') }}</p>
            @elseif($seo['nativePrice'])
                <div class="ai-price"><div>{{ __('ai_model.native_unit_ceiling') }}<strong>{{ number_format($seo['nativePrice']['sell']) }}</strong></div></div>
                <p>{{ $seo['nativePrice']['quantity'] }} {{ __('ai_model.units.'.$seo['nativePrice']['unit']) }} · {{ __('ai_model.native_price_note') }}</p>
                @if($seo['nativeNote'])<p>{{ $seo['nativeNote'] }}</p>@endif
                <p>{{ __('ai_model.estimate_note_short') }}</p>
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
    <section class="ai-section" id="model-overview">
        <h2>{{ __('ai_model.overview', ['name' => $model->name]) }}</h2>
        <p class="ai-muted">{{ $seo['summary'] }}</p>
        @if($seo['family'])<p class="ai-muted">{{ $seo['family'] }}</p>@endif
        <h2>{{ __('ai_model.use_cases', ['name' => $model->name]) }}</h2>
        <ul class="ai-steps">@foreach($seo['uses'] as $use)<li>{{ $use }}</li>@endforeach</ul>
        @if($seo['sourceDescription'] !== '')<h2>{{ __('ai_experience.source_description') }}</h2><p class="ai-muted" style="font-size:12px">{{ __('ai_experience.source_note') }}</p><p class="ai-prose" lang="en" dir="ltr">{{ $seo['sourceDescription'] }}</p>@endif
        <div class="ai-editorial" style="margin-top:30px"><span class="ai-label">{{ __('ai_experience.analysis_label') }}</span><h2>{{ __('ai_experience.editorial') }}</h2><p class="ai-muted">{{ $seo['evaluation'] }}</p><div class="ai-editorial-grid"><div><h3>{{ __('ai_experience.criteria_title') }}</h3><ul class="ai-steps">@foreach(__('ai_experience.criteria') as $criterion)<li>{{ $criterion }}</li>@endforeach</ul></div><div><h3>{{ __('ai_experience.selection.'.$model->category.'.name') }}</h3><p class="ai-muted">{{ __('ai_experience.selection.'.$model->category.'.text') }}</p><a class="ai-btn" href="{{ lroute('ai.compare', ['models' => $comparisonSlugs]) }}">{{ __('ai_experience.compare') }} ↗</a></div></div></div>
        <h2>{{ __('ai_model.workflow') }}</h2><p class="ai-muted">{{ $seo['workflow'] }}</p>
    </section>
    <section class="ai-section">
        <h2>{{ __('ai_model.limits') }}</h2><p class="ai-muted">{{ $seo['limit'] }}</p>
        @if($seo['reasoningSupplierUncontrolled'])<p class="ai-note">{{ __('ai_model.reasoning_supplier_uncontrolled') }}</p>
        @elseif($seo['reasoningDisabled'])<p class="ai-note">{{ __('ai_model.reasoning_disabled') }}</p>@endif
        @if(!$seo['tokenOperation'])<p class="ai-note">{{ $seo['nativeBilling'] }}</p>@endif
    </section>
    @if($seo['costExample'])
    <section class="ai-section">
        <h2>{{ __('ai_model.estimate_title') }}</h2>
        <p class="ai-muted">{{ __('ai_model.estimate_note', ['input' => number_format($seo['costExample']['input']), 'output' => number_format($seo['costExample']['output'])]) }}</p>
        <dl class="ai-spec ai-card">
            <div><dt>{{ __('ai_model.subtotal') }}</dt><dd>{{ number_format($seo['costExample']['sell']) }}</dd></div>
        </dl>
    </section>
    @endif
    @include('pages.ai.estimator')
    @php($inputSpec = $seo['gatewaySpec'] ?? $seo['sourceSpec'])
    @if(!empty($inputSpec['fields']))
    <section class="ai-section">
        <h2>{{ __('ai_model.'.($seo['gatewaySpec'] ? 'gateway_specs' : 'source_specs')) }}</h2>
        <p class="ai-muted">{{ __('ai_model.'.($seo['gatewaySpec'] ? 'gateway_date' : 'source_date'), ['date' => $inputSpec['schema_date']]) }}</p>
        <div class="ai-table-scroll" tabindex="0">
            <table class="ai-table" data-ai-input-schema="{{ $seo['gatewaySpec'] ? 'gateway' : 'catalogue' }}">
                <thead><tr><th scope="col">{{ __('ai_model.parameter') }}</th><th scope="col">{{ __('ai_model.data_type') }}</th><th scope="col">{{ __('ai_model.required') }}</th><th scope="col">{{ __('ai_model.constraints') }}</th></tr></thead>
                <tbody>@foreach($inputSpec['fields'] as $field)
                    <tr><th scope="row"><code>{{ $field['name'] }}</code></th><td><code>{{ $field['type'] }}</code></td><td>{{ __('ai_model.'.($field['required'] ? 'yes' : 'no')) }}</td>
                    <td>@foreach(['minLength', 'maxLength', 'maxBytes', 'minimum', 'maximum', 'minItems', 'maxItems', 'multipleOf', 'format', 'default', 'enum'] as $constraint)
                        @if(array_key_exists($constraint, $field))
                        <div>{{ in_array($constraint, ['minItems', 'maxItems', 'multipleOf', 'format']) ? $constraint : __('ai_model.'.$constraint) }}: <bdi>{{ is_array($field[$constraint]) ? ($constraint === 'enum' && !array_filter($field[$constraint], 'is_array') ? implode(', ', $field[$constraint]) : json_encode($field[$constraint], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) : (is_bool($field[$constraint]) ? ($field[$constraint] ? 'true' : 'false') : ($field[$constraint] === null ? 'null' : $field[$constraint])) }}</bdi></div>
                        @endif
                    @endforeach</td></tr>
                @endforeach</tbody>
            </table>
        </div>
    </section>
    @endif
    @if($seo['example'])
    <section class="ai-section" id="model-integration">
        <h2>{{ __('ai_model.integration', ['name' => $model->name]) }}</h2>
        <p class="ai-muted">{{ __('ai_model.example_note', ['scope' => $seo['example']['scope']]) }}</p>
        @if(!$state['ready'])<p class="ai-note">{{ __('ai_model.pending_example') }}</p>@endif
        <div data-ai-code-tabs><div class="ai-actions ai-code-tabs" role="tablist" aria-label="{{ __('ai_experience.quickstart') }}">@foreach(['curl' => 'cURL', 'python' => 'Python', 'javascript' => 'JavaScript / Node.js'] as $language => $label)<button type="button" class="ai-btn" role="tab" data-ai-language="{{ $language }}" id="ai-tab-{{ $language }}" aria-controls="ai-panel-{{ $language }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}">{{ $label }}</button>@endforeach</div>
        @foreach(['curl', 'python', 'javascript'] as $language)<div role="tabpanel" id="ai-panel-{{ $language }}" aria-labelledby="ai-tab-{{ $language }}" data-ai-code-panel="{{ $language }}" @if(!$loop->first) hidden @endif><div class="ai-code-head"><span class="ai-label">{{ $seo['example']['scope'] }}</span><button class="ai-btn" type="button" data-ai-copy="ai-example-{{ $language }}">{{ __('ai_experience.copy') }}</button></div><pre class="ai-code" id="ai-example-{{ $language }}"><code>{{ $seo['example'][$language] }}</code></pre></div>@endforeach</div>
        <p class="ai-muted">{{ __('ai.retries') }} {{ __('ai.key_safety') }}</p>
        <a class="ai-btn" href="{{ lroute('ai.docs') }}">{{ __('ai_model.billing_guide') }}</a>
    </section>
    @endif
    <section class="ai-section ai-faq" id="faq">
        <h2>{{ __('ai.faq') }}</h2>
        @foreach($seo['faq'] as $item)<details><summary>{{ $item['q'] }}</summary><p class="ai-muted">{{ $item['a'] }}</p></details>@endforeach
    </section>
    @if($related->isNotEmpty())
    <section class="ai-section" id="model-alternatives">
        <h2>{{ __('ai_model.related') }}</h2>
        <div class="ai-grid">@foreach($related as $card)@include('pages.ai.card')@endforeach</div>
        <div class="ai-actions"><a class="ai-btn" href="{{ lroute('ai.models', ['type' => $model->category]) }}">{{ __('ai.catalog') }}</a></div>
    </section>
    @endif
    @include('pages.ai.compare-tray')
    <div class="ai-note">{{ __('ai.status_note') }}</div>
</article>
@endsection
