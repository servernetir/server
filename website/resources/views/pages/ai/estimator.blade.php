@if($seo['tokenOperation'] && $state['quote'])
@php
$contextMax = $model->context_tokens > 0 ? $model->context_tokens : 1000000;
$defaultInput = min(1000, max(1, intdiv($contextMax, 2)));
$defaultOutput = $model->category === 'chat' ? min(500, max(0, $model->max_output_tokens), max(0, $contextMax - $defaultInput)) : 0;
$estimateConfig = ['inputRate' => (string) $state['quote']->pIn, 'outputRate' => (string) $state['quote']->pOut, 'context' => $contextMax, 'outputMax' => $model->category === 'chat' ? $model->max_output_tokens : 0, 'chat' => $model->category === 'chat'];
@endphp
<section class="ai-section" id="usage-planner">
<h2>{{ __('ai_experience.estimator') }}</h2><p class="ai-muted">{{ __('ai_experience.estimator_lead') }}</p>
<form class="ai-estimator" data-ai-estimator>
<script type="application/json" data-ai-estimate-config>@json($estimateConfig)</script>
<div><div class="ai-estimator-fields">
<label>{{ __('ai_experience.estimate_input') }}<input name="input" type="number" inputmode="numeric" min="0" max="{{ $contextMax }}" step="1" value="{{ $defaultInput }}" required></label>
@if($model->category === 'chat')<label>{{ __('ai_experience.estimate_output') }}<input name="output" type="number" inputmode="numeric" min="0" max="{{ $model->max_output_tokens }}" step="1" value="{{ $defaultOutput }}" required></label>@endif
<label>{{ __('ai_experience.estimate_requests') }}<input name="requests" type="number" inputmode="numeric" min="1" max="10000000" step="1" value="10000" required></label>
</div><p class="ai-muted" style="font-size:12px;margin-top:18px">{{ __('ai_experience.estimate_hint') }}</p><small class="ai-muted">{{ __('ai_experience.data_local') }}</small></div>
<div class="ai-estimate-result" aria-live="polite"><small>{{ __('ai_experience.estimate_total') }}</small><output data-ai-estimate-total>—</output><small>{{ __('ai_experience.estimate_request') }}: <span data-ai-estimate-per>—</span></small></div>
<p class="ai-estimator-error" data-ai-estimate-error hidden role="status"></p>
</form><noscript><p class="ai-note">{{ __('ai_model.estimate_note', ['input' => number_format($seo['costExample']['input']), 'output' => number_format($seo['costExample']['output'])]) }} · {{ number_format($seo['costExample']['sell']) }}</p></noscript>
</section>
@endif
