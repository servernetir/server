@extends('layouts.site')
@section('title', __('ai.title'))
@section('description', __('ai.desc'))
@include('pages.ai.style')
@section('content')
<div class="ai-wrap" data-ai-workspace>
@include('pages.ai.nav')
<section class="ai-hero">
  <div><span class="ai-label">{{ __('ai_experience.hero_label') }}</span><h1>{{ __('ai_experience.hero_title') }}</h1><p class="ai-lead">{{ __('ai_experience.hero_lead') }}</p>
    <div class="ai-actions"><a class="ai-btn primary" href="{{ lroute('ai.models') }}">{{ __('ai_experience.hero_browse') }} ↗</a><a class="ai-btn" href="{{ lroute('ai.compare') }}">{{ __('ai_experience.hero_compare') }}</a></div>
    <div class="ai-stats"><div><b>{{ number_format($count) }}</b><span>{{ __('ai_experience.models_label') }}</span></div><div><b>{{ count($categories) }}</b><span>{{ __('ai_experience.categories_label') }}</span></div><div><b>01</b><span>{{ __('ai_experience.one_key') }}</span></div></div>
  </div>
  <div class="ai-network" aria-label="{{ __('ai_experience.api_route') }}"><div class="ai-network-grid" aria-hidden="true"></div>
    <div class="ai-network-top"><span>{{ __('ai_experience.your_app') }}</span><span>SDK / HTTP</span></div>
    <div class="ai-network-core"><b>ServerNet</b><small>{{ __('ai_experience.gateway') }}</small><code>/v1</code></div>
    <div class="ai-network-bottom"><span>{{ __('ai.chat') }}</span><span>{{ __('ai.image') }}</span><span>{{ __('ai.audio') }}</span><span>{{ __('ai.embedding') }}</span></div>
    <div class="ai-network-caption">{{ __('ai_experience.choose_route') }} · {{ __('ai_experience.ready_count') }}</div>
  </div>
</section>
<div class="ai-note">{{ __('ai.status_note') }}</div>
<section class="ai-section" id="choose-task">
 <div class="ai-section-title"><div><span class="ai-label">01 / {{ __('ai_experience.workspace') }}</span><h2>{{ __('ai_experience.selection_title') }}</h2></div><p>{{ __('ai_experience.selection_lead') }}</p></div>
 <div class="ai-grid">@foreach($categories as $type)<a class="ai-card ai-category-card" href="{{ lroute('ai.models', ['type' => $type]) }}"><span class="ai-category-icon" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><span class="ai-category-arrow" aria-hidden="true">↗</span><h3>{{ __('ai_experience.selection.'.$type.'.name') }}</h3><p>{{ __('ai_experience.selection.'.$type.'.text') }}</p></a>@endforeach</div>
</section>
<section class="ai-section" id="workflow">
 <div class="ai-section-title"><div><span class="ai-label">02 / {{ __('ai_experience.how_label') }}</span><h2>{{ __('ai_experience.how_title') }}</h2></div><p>{{ __('ai_experience.how_lead') }}</p></div>
 <div class="ai-walkthrough" data-ai-walkthrough>
   <div class="ai-walkthrough-list">@foreach(__('ai_experience.steps') as $step)<button type="button" class="ai-step" data-ai-step="{{ $loop->index }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" aria-controls="ai-step-panel"><span aria-hidden="true">0{{ $loop->iteration }}</span><b>{{ $step['title'] }}</b><span class="ai-sr" data-ai-step-detail>{{ $step['text'] }}</span></button>@endforeach</div>
   <div class="ai-walkthrough-panel" id="ai-step-panel" aria-live="polite"><div aria-hidden="true"><i class="ai-terminal-dot"></i><i class="ai-terminal-dot"></i><i class="ai-terminal-dot"></i></div><span class="ai-label">SERVERNET / GET STARTED</span><strong data-ai-step-title>{{ __('ai_experience.steps.0.title') }}</strong><p data-ai-step-text>{{ __('ai_experience.steps.0.text') }}</p><a href="{{ lroute('ai.docs') }}">{{ __('ai.docs') }} ↗</a></div>
 </div>
 <noscript><ol class="ai-steps">@foreach(__('ai_experience.steps') as $step)<li><b>{{ $step['title'] }}</b> — {{ $step['text'] }}</li>@endforeach</ol></noscript>
</section>
<section class="ai-section">
 <div class="ai-section-title"><div><span class="ai-label">03 / MODEL CATALOGUE</span><h2>{{ __('ai_experience.featured') }}</h2></div><p>{{ __('ai_experience.featured_note') }}</p></div>
 <div class="ai-grid">@foreach($models as $card)@include('pages.ai.card')@endforeach</div>
 <div class="ai-actions"><a class="ai-btn" href="{{ lroute('ai.models') }}">{{ __('ai.catalog') }} · {{ number_format($count) }}</a></div>
</section>
<section class="ai-section">
 <div class="ai-section-title"><div><span class="ai-label">04 / DEVELOPER FIRST</span><h2>{{ __('ai_experience.quickstart') }}</h2></div><p>{{ __('ai_experience.quickstart_note') }}</p></div>
 @php
 $firstReady = $models->first(fn ($card) => $card['state']['ready']);
 $exampleSlug = ($firstReady['model'] ?? null)?->slug ?? 'SELECT_A_READY_CHAT_MODEL';
 $examplePython = "import os\nfrom openai import OpenAI\nfrom uuid import uuid4\n\nclient = OpenAI(\n    api_key=os.environ[\"SERVERNET_API_KEY\"],\n    base_url=\"https://servernet.cloud/v1\",\n    max_retries=0,\n)\n\nresponse = client.chat.completions.create(\n    model=".json_encode($exampleSlug).",\n    messages=[{\"role\": \"user\", \"content\": \"Hello\"}],\n    max_tokens=128,\n    extra_headers={\"Idempotency-Key\": str(uuid4())},\n)";
 @endphp
 <div class="ai-code-head"><span class="ai-label">PYTHON / OPENAI SDK</span><button type="button" class="ai-btn" data-ai-copy="ai-home-example">{{ __('ai_experience.copy') }}</button></div>
 <pre class="ai-code" id="ai-home-example"><code>{{ $examplePython }}</code></pre><p class="ai-muted">{{ __('ai.retries') }} {{ __('ai.key_safety') }}</p>
</section>
<section class="ai-section ai-faq"><h2>{{ __('ai.faq') }}</h2>@foreach([1,2,3] as $i)<details><summary>{{ __('ai.q'.$i) }}</summary><p class="ai-muted">{{ __('ai.a'.$i) }}</p></details>@endforeach</section>
<section class="ai-endcap"><div><h2>{{ __('ai_experience.start_title') }}</h2><p>{{ __('ai_experience.start_text') }}</p></div><div class="ai-actions"><a class="ai-btn primary" href="{{ lroute('account.ai') }}">{{ __('ai.start') }}</a><a class="ai-btn" href="{{ lroute('ai.compare') }}">{{ __('ai_experience.compare') }}</a></div></section>
@include('pages.ai.compare-tray')
</div>
@endsection
@push('head')
@php $faq = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => collect([1,2,3])->map(fn ($i) => ['@type' => 'Question', 'name' => __('ai.q'.$i), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => __('ai.a'.$i)]])->all()]; @endphp
<script type="application/ld+json">{!! json_encode($faq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endpush
