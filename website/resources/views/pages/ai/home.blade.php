@extends('layouts.site')
@section('title',__('ai.title'))
@section('description',__('ai.desc'))
@include('pages.ai.style')
@section('content')
<main class="ai-wrap">@include('pages.ai.nav')<section class="ai-hero"><div><span class="ai-label">{{ __('ai.eyebrow') }}</span><h1>{{ __('ai.hero') }}</h1><p class="ai-lead">{{ __('ai.lead') }}</p><div class="ai-actions"><a class="ai-btn primary" href="{{ lroute('account.ai') }}">{{ __('ai.start') }} ↗</a><a class="ai-btn" href="{{ lroute('ai.models') }}">{{ __('ai.catalog') }}</a></div></div><pre class="ai-code"><span style="color:#80ceff">from</span> openai <span style="color:#80ceff">import</span> OpenAI

import os

client = OpenAI(
    api_key=os.environ["SERVERNET_API_KEY"],
    base_url="https://servernet.cloud/v1",
    max_retries=0,
)

models = client.models.list()
if not models.data:
    raise RuntimeError("No models available")
available_model_id = models.data[0].id
response = client.chat.completions.create(
    model=available_model_id,
    messages=[{"role":"user","content":"Hello"}],
    max_tokens=128,
)</pre></section>
<div class="ai-note">{{ __('ai.status_note') }}</div><section class="ai-grid">@foreach([1,2,3] as $i)<article class="ai-card"><span class="ai-label">0{{ $i }}</span><h2>{{ __('ai.feature'.$i) }}</h2><p>{{ __('ai.feature'.$i.'text') }}</p></article>
@endforeach
</section>
<section class="ai-section"><h2>{{ __('ai.catalog') }}</h2>@include('pages.ai.categories')<div class="ai-grid">@foreach($models as $card)@include('pages.ai.card')
@endforeach
</div><div class="ai-actions"><a class="ai-btn" href="{{ lroute('ai.models') }}">{{ __('ai.catalog') }} · {{ number_format($count) }}</a></div></section>
<section class="ai-section"><h2>{{ __('ai.how') }}</h2><ol class="ai-steps">@foreach([1,2,3,4] as $i)<li>{{ __('ai.step'.$i) }}</li>
@endforeach
</ol><a class="ai-btn" href="{{ lroute('ai.docs') }}">{{ __('ai.docs') }}</a></section>
<section class="ai-section ai-faq"><h2>{{ __('ai.faq') }}</h2>@foreach([1,2,3] as $i)<details><summary>{{ __('ai.q'.$i) }}</summary><p class="ai-muted">{{ __('ai.a'.$i) }}</p></details>
@endforeach
</section></main>

@endsection

@push('head')
@php $faq=['@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>collect([1,2,3])->map(fn($i)=>['@type'=>'Question','name'=>__('ai.q'.$i),'acceptedAnswer'=>['@type'=>'Answer','text'=>__('ai.a'.$i)]])->all()]; @endphp
<script type="application/ld+json">{!! json_encode($faq, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP) !!}</script>

@endpush
