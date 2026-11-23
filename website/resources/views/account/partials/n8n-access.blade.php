@if(\App\Services\Cloud\ManagedN8nCatalog::handles($service))
@php
  $n8nState = data_get($instance?->meta, 'n8n.state', 'waiting_ssh');
  $n8nHost = data_get($instance?->meta, 'n8n.hostname');
  $n8nInitial = $instance ? session()->pull('revealed_n8n_'.$instance->id) : null;
@endphp
<section class="pnl-sec">
  <div class="pnl-sec-head"><h2>{{ __('n8n.access') }}</h2></div>
  @if($n8nState === 'ready' && ! $service->isDead())
    <p><a href="{{ 'https://'.$n8nHost }}" target="_blank" rel="noopener noreferrer" class="pnl-btn">{{ __('n8n.open') }}</a></p>
    <p dir="ltr">{{ $n8nHost }}</p>
    <p>{{ __('n8n.email') }}: <span dir="ltr">{{ data_get($instance?->meta, 'n8n.owner_email') }}</span></p>
    <form method="post" action="{{ lroute('account.cloud.n8n-access', $service) }}">@csrf<button class="pnl-btn" type="submit">{{ __('n8n.reveal') }}</button></form>
    @if($n8nInitial)<p>{{ __('n8n.password') }}: <code dir="ltr">{{ $n8nInitial }}</code></p>@endif
    <p>{{ __('n8n.password_note') }}</p>
  @else
    <p>{{ __('n8n.'.(in_array($n8nState, ['waiting_ssh','installing','https','failed'], true) ? $n8nState : 'waiting')) }}</p>
  @endif
</section>
@endif
