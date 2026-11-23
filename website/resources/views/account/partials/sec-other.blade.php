{{-- بخشِ خدمات — فهرستِ ساده. ورودی: $items، $room (bool) --}}
@php $room = $room ?? false; @endphp

<section class="pnl-sec" id="sec-other">
  <div class="pnl-sec-h">
    <h2><svg class="icon svc-sec-ic"><use href="#i-wrench"/></svg>{{ __('ui.sec_other') }}</h2>
    @if(! $room && $items->isNotEmpty())
      <a class="pnl-more" href="{{ lroute('account.other') }}">{{ __('ui.sec_view_all') }}</a>
    @endif
  </div>
  <div class="pnl-sec-b">
    @if($items->isEmpty())
      {{-- Custom work still starts through support; managed n8n has its own acquisition card. --}}
      @include('account.partials.sec-empty', [
        'full'  => $room,
        'icon'  => 'wrench',
        'h'     => __('ui.sec_empty_other_h'),
        'p'     => __('workspace.services_empty_note'),
        'short' => __('ui.sec_none_other'),
        'cta'   => __('ui.sec_empty_other_cta'),
        'url'   => lroute('account.tickets'),
      ])
    @else
      @php($n8nServices = $items->filter(fn ($item) => \App\Services\Cloud\ManagedN8nCatalog::handles($item)))
      @if($n8nServices->isNotEmpty())
        <div class="n8n-service-grid">
          @foreach($n8nServices as $n8nService)
            @include('account.partials.card-n8n', ['service' => $n8nService, 'instance' => $n8nService->cloudInstance, 'compact' => true])
          @endforeach
        </div>
      @endif
      <ul class="svc-list">
        @foreach($items->reject(fn ($item) => \App\Services\Cloud\ManagedN8nCatalog::handles($item)) as $s)
          @include('account.partials.row-other', ['s' => $s])
        @endforeach
      </ul>
    @endif
  </div>
</section>
