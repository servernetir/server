@extends('layouts.site')
@section('title',__('ai.model_title',['name'=>$model->name]))
@section('description',__('ai.model_desc',['name'=>$model->name]))
@if(!in_array($model->category,['chat','embedding','rerank'],true) || $model->status==='deprecated')@section('noindex','1')
@endif

@include('pages.ai.style')
@section('content')<main class="ai-wrap">@include('pages.ai.nav')<div class="ai-label" dir="ltr">{{ $model->vendor }} · {{ __('ai.'.$model->category) }}</div><h1>{{ $model->name }} API</h1><p class="ai-lead">{{ __('ai.model_intro',['name'=>$model->name,'vendor'=>$model->vendor,'type'=>__('ai.'.$model->category)]) }}</p><span class="ai-pill {{ $state['ready']?'ready':'' }}">{{ __('ai.'.($model->status==='deprecated'?'deprecated':($state['ready']?'ready':'pending'))) }}</span><div class="ai-actions"><a class="ai-btn primary" href="{{ lroute('account.ai') }}">{{ __('ai.start') }}</a><a class="ai-btn" href="{{ lroute('ai.docs') }}">{{ __('ai.docs') }}</a></div><section class="ai-grid"><div class="ai-card"><h2>{{ __('ai.price') }}</h2>@if($state['quote'])<div class="ai-price"><div>{{ __('ai.input') }}<strong>{{ number_format($state['quote']->pIn) }}</strong></div>@if($model->category==='chat')<div>{{ __('ai.output') }}<strong>{{ number_format($state['quote']->pOut) }}</strong></div>@endif</div>
@else
<p>{{ __('ai.prices_pending') }}</p>
@endif
</div><div class="ai-card"><dl class="ai-spec"><div><dt>{{ __('ai.context') }}</dt><dd>{{ number_format($model->context_tokens) }}</dd></div><div><dt>{{ __('ai.cap') }}</dt><dd>{{ number_format($model->max_output_tokens) }}</dd></div></dl></div><div class="ai-card"><h2>{{ __('ai.supported') }}</h2>@foreach(['reasoning'=>'reasoning','tool_calling'=>'tools','streaming'=>'stream'] as $cap=>$label)@if($model->capability($cap))<p>{{ __('ai.'.$label) }} ✓</p>
@endif

@endforeach
</div></section><div class="ai-note">{{ __('ai.tax') }} {{ __('ai.status_note') }}</div>@if($model->category==='chat')<p class="ai-muted">{{ __('ai.text_only') }}</p>
@endif
</main>
@endsection
