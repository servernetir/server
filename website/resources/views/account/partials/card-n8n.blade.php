@once
@push('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/n8n.css') }}">@endpush
@endonce
@php
  $n8nState = data_get($instance?->meta, 'n8n.state', 'waiting');
  $n8nReady = $n8nState === 'ready' && ! $service->isDead();
  $n8nHost = data_get($instance?->meta, 'n8n.hostname');
  $n8nHostValid = is_string($n8nHost) && preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/iD', $n8nHost) && str_contains($n8nHost, '.');
  $n8nCountry = strtoupper(substr((string) ($instance?->location_code ?? $service->cloudPlan?->location_code), 0, 2));
  $n8nCountry = in_array($n8nCountry, ['IR','DE','TR'], true) ? $n8nCountry : 'XX';
  $n8nInitial = $instance ? session()->pull('revealed_n8n_'.$instance->id) : null;
  $n8nStage = match ($n8nState) { 'waiting_ssh','installing' => 1, 'https' => 2, 'ready' => 4, default => 0 };
  $n8nCycle = in_array($service->cycle, ['monthly','quarterly','semiannual','yearly'], true) ? __('n8n.'.$service->cycle) : '';
  $n8nPaused = $service->status === 'suspended' || in_array($instance?->status, ['off','stopped'], true);
@endphp
<article class="n8n-service-card {{ ($compact ?? false) ? 'is-compact' : '' }}" data-n8n-service="{{ $service->id }}">
  <header><div class="n8n-service-identity"><span class="n8n-app-icon"><img src="{{ asset_ver('assets/apps/n8n.svg') }}" alt="" width="32" height="32"></span><div><span class="n8n-eyebrow">n8n Community</span><h2>{{ \App\Support\PanelText::text($service->name) }}</h2></div></div><span class="n8n-location-badge"><img src="{{ asset_ver('assets/flags/'.strtolower($n8nCountry).'.svg') }}" alt="" width="24" height="18">{{ $n8nCountry === 'XX' ? __('n8n.location') : __('n8n.'.$n8nCountry) }}</span></header>
  <div class="n8n-service-state"><span class="pnl-pill {{ $n8nPaused ? 'warn' : ($n8nReady ? 'ok' : ($n8nState === 'failed' || $service->isDead() ? 'danger' : 'info')) }}">{{ $service->isDead() ? __('n8n.service_closed') : ($n8nPaused ? __('n8n.paused') : __('n8n.'.(in_array($n8nState, ['waiting','waiting_ssh','installing','https','ready','failed'], true) ? $n8nState : 'waiting'))) }}</span><span>{{ $service->isHourly() ? __('n8n.hourly') : $n8nCycle }}</span></div>
  @if($n8nReady && $n8nHostValid)
    <a class="n8n-service-domain" href="{{ 'https://'.$n8nHost }}" target="_blank" rel="noopener noreferrer"><svg class="icon" aria-hidden="true"><use href="#i-link"/></svg><bdi>{{ $n8nHost }}</bdi></a>
    <dl class="n8n-service-access"><div><dt>{{ __('n8n.email') }}</dt><dd><bdi>{{ data_get($instance?->meta, 'n8n.owner_email') }}</bdi></dd></div><div><dt>{{ __('n8n.password') }}</dt><dd><code dir="ltr" data-n8n-password>{{ $n8nInitial ?: '••••••••••••' }}</code></dd></div></dl>
    <div class="n8n-service-actions"><a class="n8n-button n8n-button--small" href="{{ 'https://'.$n8nHost }}" target="_blank" rel="noopener noreferrer">{{ __('n8n.open') }}</a><form method="post" action="{{ lroute('account.cloud.n8n-access', $service) }}" data-n8n-reveal>@csrf<button class="pnl-btn" type="submit">{{ __('n8n.reveal') }}</button></form><button class="pnl-btn" type="button" data-n8n-copy @if(! $n8nInitial) hidden @endif>{{ __('n8n.copy_password') }}</button></div>
    <p class="n8n-small" data-n8n-access-notice role="status" aria-live="polite" data-failure="{{ __('n8n.reveal_error') }}" data-copied="{{ __('n8n.copied') }}">{{ __('n8n.password_note') }}</p>
  @elseif($service->isDead())
    <p class="n8n-small">{{ __('n8n.service_closed') }}</p>
  @else
    <div class="n8n-service-progress" aria-label="{{ __('n8n.steps') }}">@foreach(['step_server','step_install','step_https','step_ready'] as $step)<span class="{{ $n8nStage > $loop->index ? 'is-complete' : '' }}"><i aria-hidden="true"></i>{{ __('n8n.'.$step) }}</span>@endforeach</div>
    <p class="n8n-small">{{ $n8nState === 'failed' ? __('n8n.failed') : __('n8n.delivery_lead') }}</p>
  @endif
  <footer><a href="{{ lroute('account.cloud.show', $service) }}">{{ __('n8n.manage') }}</a>@if($service->next_due_at && $service->isRecurring())<span>{{ __('n8n.renewal') }}: {{ blog_date((string) $service->next_due_at) }}</span>@endif</footer>
</article>
