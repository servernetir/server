@php $m = $card['model']; $s = $card['state']; $tokenOperation = in_array($m->category, ['chat', 'embedding', 'rerank'], true); @endphp
<article class="ai-card">
    <div class="ai-label"><bdi>{{ $m->vendor }}</bdi></div>
    <h3><a href="{{ lroute('ai.model', $m->slug) }}" style="color:inherit"><bdi>{{ $m->name }}</bdi></a></h3>
    <span class="ai-pill {{ $s['ready'] ? 'ready' : '' }}">{{ __('ai.'.($m->status === 'deprecated' ? 'deprecated' : ($s['ready'] ? 'ready' : 'pending'))) }}</span>
    <span class="ai-pill">{{ __('ai.'.$m->category) }}</span>
    @if($tokenOperation && $m->context_tokens > 0)<p>{{ __('ai_model.gateway_window') }}: {{ number_format($m->context_tokens) }} {{ __('ai.tokens') }}</p>@endif
    @if($tokenOperation && $s['quote'])
    <div class="ai-price">
        <div>{{ __('ai.input') }}<strong>{{ number_format($s['quote']->pIn) }}</strong></div>
        @if($m->category === 'chat')<div>{{ __('ai.output') }}<strong>{{ number_format($s['quote']->pOut) }}</strong></div>@endif
    </div><small class="ai-muted">{{ __('ai.price') }}</small>
    @elseif($s['quote']?->native)
        @php $unitPrice=\App\Services\Ai\AiNative::unitPrice($s['quote']); @endphp
        <div class="ai-price"><div>{{ __('ai_model.native_unit_ceiling') }}<strong>{{ number_format($unitPrice['sell']) }}</strong></div></div>
        <small class="ai-muted">{{ $unitPrice['quantity'] }} {{ __('ai_model.units.'.$unitPrice['unit']) }} · {{ __('ai_model.native_price_note') }}</small>
    @else<p>{{ __('ai.prices_pending') }}</p>@endif
<div class="ai-card-foot"><a class="ai-btn" href="{{ lroute('ai.model', $m->slug) }}">{{ __('ai_experience.see_model') }} ↗</a><button type="button" class="ai-btn" data-ai-compare-add="{{ $m->slug }}" aria-pressed="false" hidden>{{ __('ai_experience.pick') }}</button><noscript><a class="ai-btn" href="{{ lroute('ai.compare', ['models' => $m->slug]) }}">{{ __('ai_experience.compare') }}</a></noscript></div>
</article>
